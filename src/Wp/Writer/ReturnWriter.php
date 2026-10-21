<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Meta\WcDataMeta;
use IdempotentImporter\Wp\OrdersByMeta;
use WC_Order;
use WC_Order_Item_Tax;
use WC_Order_Refund;

/**
 * Return orders → WooCommerce refunds on the mirrored sales they return from: the returned lines (pointing at
 * the sale's line items, with their tax) and the return's total. No money moves and nothing is restocked.
 * Each refund carries the return's identity (OrderWriter::RETURN_META), so rebuilding the sale order keeps
 * it, and is rebuilt here whenever the return changes. Money a refund on the sale order itself already
 * mirrors (the same Square refund) isn't counted again: the return then refunds its lines at no amount. The
 * sale order keeps the status its own source gives it. The link's target is the first sale order.
 */
final class ReturnWriter implements Writer
{
    private const CHARGES_META = '_mirror_returned_charges';
    private const SOURCE_META = '_mirror_return_source';

    public function __construct(private readonly Targets $targets) {}

    public function kind(): Kind
    {
        return Kind::Return;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $orderIds = $this->targets->requireAll(Kind::Order, $f->strings('order_gids'));
        if ($orderIds === []) {
            throw new SourceError("{$desired->gid} returns from no order.");
        }
        $this->removeRefunds($desired->gid);
        foreach ($f->records('refunds') as $entry) {
            $this->refund($desired->gid, $f, $entry);
        }

        return $orderIds[0];
    }

    public function exists(int $targetId): bool
    {
        return wc_get_order($targetId) instanceof WC_Order;
    }

    /** The return is gone at the source: its refunds are kept, marked deleted (nothing mirrored is deleted). */
    public function retire(Link $link): void
    {
        foreach ($this->refundIds($link->gid) as $refundId) {
            $refund = wc_get_order($refundId);
            if ($refund instanceof WC_Order_Refund) {
                $refund->update_meta_data('_mirror_deleted', '1');
                $refund->save();
            }
        }
    }

    private function refund(string $gid, Values $f, Values $entry): void
    {
        $order = wc_get_order($this->targets->require(Kind::Order, $entry->string('order_gid')));
        if (!$order instanceof WC_Order) {
            throw new UnresolvedDependency("{$entry->string('order_gid')} is no longer a WooCommerce order.");
        }
        $status = $order->get_status();
        $moneyMirrored = $this->moneyAlreadyMirrored($order, $entry->strings('refund_gids'));
        $lineItemIds = OrderWriter::lineItemIds($order);
        $taxRates = self::taxRates($order);
        $lines = [];
        foreach ($entry->records('lines') as $line) {
            $lineGid = $line->nullableString('line_item_gid');
            $itemId = $lineGid === null ? null : ($lineItemIds[$lineGid] ?? null);
            if ($itemId === null) {
                continue;
            }
            $taxes = [];
            foreach ($line->records('taxes') as $tax) {
                $rate = $taxRates[$tax->string('title')] ?? null;
                if ($rate !== null) {
                    $taxes[$rate] = $moneyMirrored ? '0' : $tax->string('amount');
                }
            }
            $lines[$itemId] = [
                'qty' => $line->int('quantity'),
                'refund_total' => $moneyMirrored ? '0' : $line->string('subtotal'),
                'refund_tax' => $taxes,
            ];
        }
        $created = wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => $moneyMirrored ? '0' : $entry->string('amount'),
            'reason' => $entry->nullableString('note') ?? '',
            'line_items' => $lines,
            'refund_payment' => false,
            'restock_items' => false,
            'date_created' => $entry->nullableString('created_at'),
        ]);
        if (is_wp_error($created)) {
            throw new SourceError("Return {$entry->string('gid')}: " . $created->get_error_message());
        }
        $this->mark($created, $gid, $f, $entry, array_flip($lineItemIds));
        $this->targets->links->save(Kind::Refund, $entry->string('gid'), $created->get_id());
        $order = wc_get_order($order->get_id());
        if ($order instanceof WC_Order && $order->get_status() !== $status) {
            $order->set_status($status);
            $order->save();
        }
    }

    /**
     * @param array<int, string> $lineGids order item id → its source line's identity
     */
    private function mark(WC_Order_Refund $refund, string $gid, Values $f, Values $entry, array $lineGids): void
    {
        $refund->update_meta_data(OrderWriter::RETURN_META, $gid);
        $meta = new WcDataMeta($refund);
        $meta->set(self::SOURCE_META, array_filter([
            'name' => $f->nullableString('name'),
            'source_name' => $f->nullableString('source_name'),
            'location_id' => $f->nullableString('location_id'),
            'refunds' => $entry->strings('refund_gids'),
        ], static fn(mixed $value): bool => $value !== null && $value !== []));
        $charges = $entry->raw('charges');
        if (is_array($charges) && $charges !== []) {
            $meta->set(self::CHARGES_META, $charges);
        }
        $refund->save();
        foreach ($refund->get_items() as $item) {
            $refunded = $item->get_meta(OrderWriter::REFUNDED_ITEM_META);
            if (is_numeric($refunded) && isset($lineGids[(int) $refunded])) {
                $item->update_meta_data(OrderWriter::LINE_GID_META, $lineGids[(int) $refunded]);
                $item->save();
            }
        }
    }

    /**
     * Whether a refund already mirrored on the sale order (from its own refunds) is the money this return
     * refunded.
     *
     * @param list<string> $refundGids
     */
    private function moneyAlreadyMirrored(WC_Order $order, array $refundGids): bool
    {
        foreach ($refundGids as $refundGid) {
            $refundId = $this->targets->links->targetId(Kind::Refund, $refundGid);
            $refund = $refundId === null ? null : wc_get_order($refundId);
            if ($refund instanceof WC_Order_Refund && $refund->get_parent_id() === $order->get_id() && $refund->get_meta(OrderWriter::RETURN_META) === '') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> each of the order's tax lines' title → its rate id */
    private static function taxRates(WC_Order $order): array
    {
        $rates = [];
        foreach ($order->get_items('tax') as $tax) {
            if ($tax instanceof WC_Order_Item_Tax) {
                $rates[$tax->get_label()] = $tax->get_rate_id();
            }
        }

        return $rates;
    }

    private function removeRefunds(string $gid): void
    {
        foreach ($this->refundIds($gid) as $refundId) {
            $refund = wc_get_order($refundId);
            if ($refund instanceof WC_Order_Refund) {
                $refund->delete(true);
            }
        }
    }

    /** @return list<int> the refunds written for return $gid */
    private function refundIds(string $gid): array
    {
        return array_map(static fn(\WC_Abstract_Order $refund): int => $refund->get_id(), OrdersByMeta::find(OrdersByMeta::REFUND, OrderWriter::RETURN_META, $gid));
    }
}
