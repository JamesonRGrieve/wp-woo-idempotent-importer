<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Support\Decimal;
use IdempotentImporter\Wp\Comments;
use IdempotentImporter\Wp\Dates;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\WcDataMeta;
use IdempotentImporter\Wp\Retirement;
use WC_Order;
use WC_Order_Item_Coupon;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Tax;

/**
 * Orders and draft orders → WooCommerce orders, through the CRUD API (HPOS-safe). Totals are the source's,
 * never recalculated. When an order changes its items, refunds and tax lines are rebuilt; notes are
 * added once each. Writes run inside Quiet, so no emails and no stock or coupon-usage side effects.
 */
final class OrderWriter implements Writer
{
    public const ORDER_NUMBER_META = '_mirror_name';
    public const CREATED_VIA = 'idempotent-import';
    private const NOTE_IDS_META = '_mirror_note_ids';
    private const LINE_GID_META = '_mirror_line_item_gid';
    private const SHIPPING_METHOD_FALLBACK = self::CREATED_VIA;
    private const PAID_STATUSES = ['processing', 'completed', 'refunded'];
    private const PERCENT_PLACES = 2;
    private const CONSUMED = [
        'name', 'created_at', 'currency', 'prices_include_tax', 'customer_gid', 'email', 'phone', 'billing',
        'shipping', 'coupons', 'line_items', 'shipping_lines', 'tax_lines', 'discount_total', 'shipping_total',
        'tax_total', 'total', 'status', 'processed_at', 'closed_at', 'customer_note', 'payment_method_title',
        'transaction_id', 'fees', 'refunds', 'notes',
    ];

    public function __construct(private readonly Kind $kind, private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return $this->kind;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $order = $existing !== null ? wc_get_order($existing->targetId) : null;
        $order = $order instanceof WC_Order ? $order : new WC_Order();
        $taxKeys = self::taxKeys($f);
        $this->clear($order);
        foreach ($f->records('line_items') as $line) {
            $order->add_item($this->lineItem($line, $taxKeys));
        }
        foreach ($f->records('shipping_lines') as $line) {
            $order->add_item($this->shippingLine($line, $taxKeys));
        }
        foreach ($f->records('fees') as $fee) {
            $order->add_item($this->fee($fee, $taxKeys));
        }
        foreach ($f->strings('coupons') as $code) {
            $coupon = new WC_Order_Item_Coupon();
            $coupon->set_code($code);
            $order->add_item($coupon);
        }
        foreach ($this->taxItems($f, $taxKeys) as $tax) {
            $order->add_item($tax);
        }
        $this->properties($order, $f);
        $status = $f->string('status');
        $order->set_status($status);
        MetaSync::mirror(new WcDataMeta($order), $desired->gid, $f, self::CONSUMED);
        $order->update_meta_data(self::ORDER_NUMBER_META, $f->string('name'));
        $id = $order->save();
        $this->refunds($order, $f->records('refunds'), self::lineItemIds($order), $taxKeys);
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order) {
            throw new SourceError("Order {$id} vanished while it was being written.");
        }
        if ($order->get_status() !== $status) {
            $order->set_status($status);
            $order->save();
        }
        $this->notes($order, $f);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return wc_get_order($targetId) instanceof WC_Order;
    }

    public function retire(Link $link): void
    {
        $order = wc_get_order($link->targetId);
        Retirement::wcObject($order instanceof WC_Order ? $order : null);
    }

    /**
     * Each distinct tax title gets a stable key, used as the "rate id" that links line taxes to the
     * order's tax lines (mirrored taxes have no WooCommerce rate).
     *
     * @return array<string, int> tax title => key
     */
    private static function taxKeys(Values $f): array
    {
        $titles = [];
        foreach ($f->records('tax_lines') as $tax) {
            $titles[] = $tax->string('title');
        }
        foreach ([...$f->records('line_items'), ...$f->records('shipping_lines'), ...$f->records('fees')] as $line) {
            foreach ($line->records('taxes') as $tax) {
                $titles[] = $tax->string('title');
            }
        }
        $keys = [];
        foreach (array_values(array_unique($titles)) as $index => $title) {
            $keys[$title] = $index + 1;
        }

        return $keys;
    }

    /** Removes every item and our refunds, so a changed order is rebuilt exactly. */
    private function clear(WC_Order $order): void
    {
        if ($order->get_id() === 0) {
            return;
        }
        foreach ($order->get_refunds() as $refund) {
            $refund->delete(true);
        }
        $order->remove_order_items();
    }

    /** @param array<string, int> $taxKeys */
    private function lineItem(Values $line, array $taxKeys): WC_Order_Item_Product
    {
        $item = new WC_Order_Item_Product();
        $item->set_name($line->string('name'));
        $item->set_quantity($line->int('quantity'));
        [$productId, $variationId] = $this->product($line);
        $item->set_product_id($productId);
        $item->set_variation_id($variationId);
        $item->set_subtotal($line->string('subtotal'));
        $item->set_total($line->string('total'));
        $taxes = self::taxArray($line->records('taxes'), $taxKeys);
        $item->set_taxes(['total' => $taxes, 'subtotal' => $taxes]);
        $item->add_meta_data(self::LINE_GID_META, $line->string('gid'), true);
        $sku = $line->nullableString('sku');
        if ($sku !== null) {
            $item->add_meta_data('_mirror_sku', $sku, true);
        }
        foreach ($line->stringMap('attributes') as $name => $value) {
            $item->add_meta_data($name, $value);
        }

        return $item;
    }

    /**
     * The product and variation a line points at. A line whose product was deleted at the source stays a
     * named line with no product; one whose product exists but isn't mirrored yet waits for it.
     *
     * @return array{0: int, 1: int}
     */
    private function product(Values $line): array
    {
        $variantGid = $line->nullableString('variant_gid');
        $productGid = $line->nullableString('product_gid');
        if ($productGid === null || !$this->targets->settings->syncs(Kind::Product)) {
            return [0, 0];
        }
        $productId = $this->targets->require(Kind::Product, $productGid);
        $variationId = $variantGid === null ? null : $this->targets->links->targetId(Kind::Variant, $variantGid);

        return [$productId, $variationId !== null && $variationId !== $productId ? $variationId : 0];
    }

    /** @param array<string, int> $taxKeys */
    private function shippingLine(Values $line, array $taxKeys): WC_Order_Item_Shipping
    {
        $item = new WC_Order_Item_Shipping();
        $item->set_method_title($line->string('title'));
        $item->set_method_id($line->nullableString('code') ?? self::SHIPPING_METHOD_FALLBACK);
        $item->set_total($line->string('total'));
        $item->set_taxes(['total' => self::taxArray($line->records('taxes'), $taxKeys)]);

        return $item;
    }

    /** @param array<string, int> $taxKeys */
    private function fee(Values $fee, array $taxKeys): WC_Order_Item_Fee
    {
        $item = new WC_Order_Item_Fee();
        $item->set_name($fee->string('name'));
        $item->set_total($fee->string('total'));
        $item->set_taxes(['total' => self::taxArray($fee->records('taxes'), $taxKeys)]);

        return $item;
    }

    /**
     * One tax item per tax title, totalled from the line taxes; a tax line with an `order_amount` (a source
     * that reports a tax only for the whole order) uses that amount instead, with no shipping share.
     *
     * @param array<string, int> $taxKeys
     *
     * @return list<WC_Order_Item_Tax>
     */
    private function taxItems(Values $f, array $taxKeys): array
    {
        $rates = [];
        $orderAmounts = [];
        foreach ($f->records('tax_lines') as $tax) {
            $rates[$tax->string('title')] = $tax->nullableString('rate');
            $orderAmounts[$tax->string('title')] = $tax->nullableString('order_amount');
        }
        $items = [];
        foreach ($taxKeys as $title => $key) {
            $item = new WC_Order_Item_Tax();
            $item->set_rate_id($key);
            $item->set_label($title);
            $item->set_rate_code(sanitize_title($title));
            $orderAmount = $orderAmounts[$title] ?? null;
            $item->set_tax_total($orderAmount ?? self::taxTotal([...$f->records('line_items'), ...$f->records('fees')], $title));
            $item->set_shipping_tax_total($orderAmount === null ? self::taxTotal($f->records('shipping_lines'), $title) : '0');
            $rate = $rates[$title] ?? null;
            if ($rate !== null) {
                $item->set_rate_percent((float) Decimal::shift($rate, self::PERCENT_PLACES));
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @param list<Values> $lines */
    private static function taxTotal(array $lines, string $title): string
    {
        $amounts = [];
        foreach ($lines as $line) {
            foreach ($line->records('taxes') as $tax) {
                if ($tax->string('title') === $title) {
                    $amounts[] = $tax->string('amount');
                }
            }
        }

        return Decimal::sum($amounts);
    }

    /**
     * @param list<Values> $taxes
     * @param array<string, int> $taxKeys
     *
     * @return array<int, string>
     */
    private static function taxArray(array $taxes, array $taxKeys): array
    {
        $amounts = [];
        foreach ($taxes as $tax) {
            $amounts[$taxKeys[$tax->string('title')]][] = $tax->string('amount');
        }

        return array_map(Decimal::sum(...), $amounts);
    }

    private function properties(WC_Order $order, Values $f): void
    {
        $order->set_created_via(self::CREATED_VIA);
        $order->set_currency($f->string('currency'));
        $order->set_prices_include_tax($f->bool('prices_include_tax'));
        $order->set_date_created(Dates::gmt($f->string('created_at')) . '+00:00');
        $processed = $f->nullableString('processed_at');
        $order->set_date_paid($processed !== null && in_array($f->string('status'), self::PAID_STATUSES, true) ? $processed : null);
        $order->set_date_completed($f->string('status') === 'completed' ? $f->nullableString('closed_at') : null);
        $customerGid = $f->nullableString('customer_gid');
        $order->set_customer_id($customerGid !== null && $this->targets->settings->syncs(Kind::Customer)
            ? $this->targets->require(Kind::Customer, $customerGid) : 0);
        $order->set_address($f->record('billing')?->all() ?? [], 'billing');
        $order->set_address($f->record('shipping')?->all() ?? [], 'shipping');
        $order->set_billing_email($f->nullableString('email') ?? '');
        $order->set_billing_phone($f->nullableString('phone') ?? $order->get_billing_phone());
        $order->set_customer_note($f->nullableString('customer_note') ?? '');
        $order->set_payment_method_title($f->nullableString('payment_method_title') ?? '');
        $order->set_transaction_id($f->nullableString('transaction_id') ?? '');
        $order->set_discount_total($f->string('discount_total'));
        $order->set_shipping_total($f->string('shipping_total'));
        $order->set_cart_tax($f->string('tax_total'));
        $order->set_total($f->string('total'));
    }

    /**
     * Which WooCommerce order item each source line became, for refunds to point at.
     *
     * @return array<string, int> line item GID → order item id
     */
    private static function lineItemIds(WC_Order $order): array
    {
        $ids = [];
        foreach ($order->get_items() as $item) {
            $gid = $item->get_meta(self::LINE_GID_META);
            if (is_string($gid) && $gid !== '') {
                $ids[$gid] = $item->get_id();
            }
        }

        return $ids;
    }

    /**
     * Mirrors refunds as WooCommerce refunds: no money moves and nothing is restocked.
     *
     * @param list<Values> $refunds
     * @param array<string, int> $lineItemIds
     * @param array<string, int> $taxKeys
     */
    private function refunds(WC_Order $order, array $refunds, array $lineItemIds, array $taxKeys): void
    {
        foreach ($refunds as $refund) {
            $lines = [];
            foreach ($refund->records('lines') as $line) {
                $itemId = $lineItemIds[$line->string('line_item_gid')] ?? null;
                if ($itemId === null) {
                    continue;
                }
                $lines[$itemId] = [
                    'qty' => $line->int('quantity'),
                    'refund_total' => $line->string('subtotal'),
                    'refund_tax' => array_fill_keys(array_values($taxKeys), '0'),
                ];
            }
            $created = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => $refund->string('amount'),
                'reason' => $refund->nullableString('note') ?? '',
                'line_items' => $lines,
                'refund_payment' => false,
                'restock_items' => false,
                'date_created' => $refund->nullableString('created_at'),
            ]);
            if (is_wp_error($created)) {
                throw new SourceError("Refund {$refund->string('gid')}: " . $created->get_error_message());
            }
            $this->targets->links->save(Kind::Refund, $refund->string('gid'), $created->get_id());
        }
    }

    /** Adds each note (timeline comments, shipments) once, as a private order note. */
    private function notes(WC_Order $order, Values $f): void
    {
        $added = $order->get_meta(self::NOTE_IDS_META);
        $added = is_array($added) ? $added : [];
        $notes = [];
        foreach ($f->records('notes') as $note) {
            $author = $note->nullableString('author');
            $notes[$note->string('gid')] = [($author !== null ? "{$author}: " : '') . $note->string('message'), $note->string('created_at')];
        }
        $changed = false;
        foreach ($notes as $gid => [$text, $date]) {
            if (isset($added[$gid])) {
                continue;
            }
            $commentId = $order->add_order_note($text);
            wp_update_comment(['comment_ID' => $commentId, ...Comments::dates($date)]);
            $added[$gid] = $commentId;
            $changed = true;
        }
        if ($changed) {
            $order->update_meta_data(self::NOTE_IDS_META, $added);
            $order->save();
        }
    }
}
