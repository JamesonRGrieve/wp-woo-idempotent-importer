<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * An eCommerce order → a WooCommerce order with Wix's totals. Lines point at the Wix Stores product and its
 * variant (see Fields::variantId) with their discounts, taxes and description lines (chosen options); the
 * selected shipping option becomes the shipping line, additional fees fees, coupon codes coupon lines,
 * refunds WooCommerce refunds and each fulfillment (carrier, tracking) an order note. The order, payment and
 * fulfillment statuses map to one WooCommerce status (see status()); the first approved payment dates the
 * payment and names its method and transaction. Archived orders are mirrored (archiving only hides an order
 * in Wix). Currency conversion, channel, attribution, custom fields, balance, platform fees, the buyer and
 * recipient details and the payments are mirrored as meta.
 */
final class OrderMapper implements Mapper
{
    public const SOURCE = 'wix';

    private const VERSION = 1;
    private const APPROVED = 'APPROVED';
    private const CANCELED = 'CANCELED';
    private const REJECTED = 'REJECTED';
    private const PAID = ['PAID', 'PARTIALLY_REFUNDED'];
    private const FULLY_REFUNDED = 'FULLY_REFUNDED';
    private const DECLINED = 'DECLINED';
    private const FULFILLED = 'FULFILLED';
    private const GIFT_CARD = 'GIFT_CARD';
    private const REFUND_SUCCEEDED = 'SUCCEEDED';
    /** Payment statuses that mean the money was taken (refunds come after it was). */
    private const CHARGED = ['APPROVED', 'REFUNDED', 'PARTIALLY_REFUNDED'];
    private const GIFT_CARD_METHOD = 'Gift card';
    private const MEMBERSHIP_METHOD = 'Membership';
    private const DETAILS = [
        'status', 'paymentStatus', 'fulfillmentStatus', 'buyerLanguage', 'weightUnit', 'currencyConversionDetails', 'siteLanguage',
        'archived', 'channelInfo', 'attributionSource', 'createdBy', 'checkoutId', 'customFields', 'balanceSummary', 'imported',
        'buyerInfo', 'recipientInfo', 'businessLocation', 'purchasedDate', 'tags', 'platformFeeSummary', 'appliedDiscounts',
    ];
    private const PAYMENT_DETAILS = ['id', 'status', 'amount', 'createdDate', 'regularPaymentDetails', 'giftcardPaymentDetails', 'membershipPaymentDetails'];

    public function kind(): Kind
    {
        return Kind::Order;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $billing = $node->child('billingInfo');
        $shippingInfo = $node->child('shippingInfo');
        $buyer = $node->child('buyerInfo');
        $email = $buyer?->optionalString('email');
        $contactId = $buyer?->optionalString('contactId');
        $summary = $node->requiredChild('priceSummary');
        $payment = self::chargedPayment($node->list('payments'));
        $status = self::status($node->string('status'), $node->optionalString('paymentStatus'), $node->optionalString('fulfillmentStatus'));
        $lineItems = $node->list('lineItems');

        return new Desired(Kind::Order, $node->id(), [
            'name' => '#' . ($node->optionalDecimal('number') ?? Fields::sourceId($node)),
            'created_at' => Fields::requiredTime($node, 'createdDate'),
            'currency' => $node->string('currency'),
            'prices_include_tax' => ($node->raw()['taxIncludedInPrices'] ?? false) === true,
            'customer_gid' => $contactId === null ? null : Fields::contactId($contactId),
            'email' => $email === null ? null : strtolower($email),
            'phone' => $billing?->child('contactDetails')?->optionalString('phone'),
            'billing' => Fields::orderAddress($billing),
            'shipping' => Fields::orderAddress($shippingInfo?->child('logistics')?->child('shippingDestination')),
            'tags' => [],
            'coupons' => array_values(array_filter(array_map(
                static fn(Node $discount): ?string => $discount->child('coupon')?->optionalString('code'),
                $node->list('appliedDiscounts'),
            ))),
            'line_items' => array_map(self::lineItem(...), $lineItems),
            'shipping_lines' => $shippingInfo === null ? [] : [self::shippingLine($shippingInfo)],
            'tax_lines' => self::taxLines($node),
            'discount_total' => Fields::moneyOrZero($summary, 'discount'),
            'shipping_total' => Fields::moneyOrZero($summary, 'shipping'),
            'tax_total' => Fields::moneyOrZero($summary, 'tax'),
            'total' => Fields::moneyOrZero($summary, 'total'),
            'status' => $status,
            'processed_at' => $payment === null ? null : Fields::time($payment, 'createdDate'),
            'closed_at' => $node->optionalString('fulfillmentStatus') === self::FULFILLED ? self::lastFulfilled($node->list('fulfillments')) : null,
            'customer_note' => $node->optionalString('buyerNote'),
            'source_name' => self::SOURCE,
            'payment_method_title' => $payment === null ? null : self::paymentMethod($payment),
            'transaction_id' => $payment?->child('regularPaymentDetails')?->optionalString('providerTransactionId')
                ?? $payment?->child('regularPaymentDetails')?->optionalString('gatewayTransactionId'),
            'fees' => array_map(static fn(Node $fee): array => [
                'name' => $fee->optionalString('name') ?? 'Fee',
                'total' => Fields::money($fee->child('priceBeforeTax')) ?? Fields::moneyOrZero($fee, 'price'),
                'taxes' => self::taxes($fee->child('taxInfo')),
            ], $node->list('additionalFees')),
            'refunds' => self::refunds($node->list('refunds')),
            'notes' => self::notes($node->list('fulfillments'), Fields::requiredTime($node, 'createdDate')),
            'payments' => array_map(static fn(Node $payment): array => $payment->only(self::PAYMENT_DETAILS), $node->list('payments')),
            'order' => $node->only(self::DETAILS),
        ]);
    }

    /**
     * A Wix order's status, payment status and fulfillment status → a WooCommerce order status: canceled →
     * cancelled, rejected → failed, still in checkout → pending; an approved order is refunded when fully
     * refunded, failed when its payment was declined, completed when paid and fulfilled, processing when
     * paid, and on hold while payment is outstanding (offline, pending or partial payments).
     */
    public static function status(string $orderStatus, ?string $paymentStatus, ?string $fulfillmentStatus): string
    {
        return match (true) {
            $orderStatus === self::CANCELED => 'cancelled',
            $orderStatus === self::REJECTED => 'failed',
            $orderStatus !== self::APPROVED => 'pending',
            $paymentStatus === self::FULLY_REFUNDED => 'refunded',
            $paymentStatus === self::DECLINED => 'failed',
            in_array($paymentStatus, self::PAID, true) => $fulfillmentStatus === self::FULFILLED ? 'completed' : 'processing',
            default => 'on-hold',
        };
    }

    /** @return array<string, mixed> */
    private static function lineItem(Node $line): array
    {
        $catalog = $line->child('catalogReference');
        $productId = $catalog?->optionalString('appId') === Fields::STORES_APP_ID ? $catalog->optionalString('catalogItemId') : null;
        $variantId = $catalog?->child('options')?->optionalString('variantId');
        $total = Fields::moneyOrZero($line, 'totalPriceBeforeTax');
        $taxes = self::taxes($line->child('taxInfo'));
        $attributes = [];
        foreach ($line->list('descriptionLines') as $description) {
            $name = $description->child('name')?->optionalString('original');
            $value = $description->child('plainText')?->optionalString('original') ?? $description->child('colorInfo')?->optionalString('original');
            if ($name !== null && $value !== null) {
                $attributes[$name] = $value;
            }
        }

        return [
            'gid' => Fields::id('OrderLineItem', $line->string('id')),
            'name' => $line->child('productName')?->optionalString('original') ?? '',
            'gift_card' => $line->child('itemType')?->optionalString('preset') === self::GIFT_CARD,
            'product_gid' => $productId === null ? null : Fields::productId($productId),
            'variant_gid' => $productId === null || $variantId === null ? null : Fields::variantId($productId, $variantId),
            'sku' => $line->child('physicalProperties')?->optionalString('sku'),
            'quantity' => $line->int('quantity'),
            'taxable' => $taxes !== [],
            'subtotal' => Decimal::sum([$total, Fields::moneyOrZero($line, 'totalDiscount')]),
            'total' => $total,
            'taxes' => $taxes,
            'attributes' => $attributes,
        ];
    }

    /** @return array<string, mixed> the selected shipping option at its price before discounts (they are in the discount total) */
    private static function shippingLine(Node $shipping): array
    {
        $cost = $shipping->child('cost');

        return [
            'title' => $shipping->optionalString('title') ?? 'Shipping',
            'code' => $shipping->optionalString('code'),
            'source' => self::SOURCE,
            'total' => $cost === null ? '0' : Fields::moneyOrZero($cost, 'price'),
            'taxes' => self::taxes($cost?->child('taxInfo')),
        ];
    }

    /**
     * A line's (or the shipping's, or a fee's) taxes by jurisdiction; a line taxed without a breakdown has one
     * "Tax" line.
     *
     * @return list<array{title: string, rate: ?string, amount: string}>
     */
    private static function taxes(?Node $taxInfo): array
    {
        if ($taxInfo === null) {
            return [];
        }
        $taxes = [];
        foreach ($taxInfo->list('taxBreakdown') as $tax) {
            $taxes[] = ['title' => self::taxTitle($tax), 'rate' => $tax->optionalDecimal('rate'), 'amount' => Fields::moneyOrZero($tax, 'taxAmount')];
        }
        $amount = Fields::money($taxInfo->child('taxAmount'));
        if ($taxes === [] && !Decimal::isZero($amount) && $amount !== null) {
            $taxes[] = ['title' => 'Tax', 'rate' => null, 'amount' => $amount];
        }

        return $taxes;
    }

    /**
     * One tax line per tax the order's breakdown names; an order taxed without any breakdown gets one
     * order-level line.
     *
     * @return list<array{title: string, rate: ?string, order_amount: ?string}>
     */
    private static function taxLines(Node $order): array
    {
        $lines = [];
        foreach ($order->child('taxInfo')?->list('taxBreakdown') ?? [] as $tax) {
            $lines[self::taxTitle($tax)] ??= ['title' => self::taxTitle($tax), 'rate' => $tax->optionalDecimal('rate'), 'order_amount' => null];
        }
        $total = Fields::moneyOrZero($order->requiredChild('priceSummary'), 'tax');
        $lineTaxes = array_filter($order->list('lineItems'), static fn(Node $line): bool => self::taxes($line->child('taxInfo')) !== []);
        if ($lines === [] && $lineTaxes === [] && !Decimal::isZero($total)) {
            return [['title' => 'Tax', 'rate' => null, 'order_amount' => $total]];
        }
        if ($lines === []) {
            foreach ($lineTaxes as $line) {
                foreach (self::taxes($line->child('taxInfo')) as $tax) {
                    $lines[$tax['title']] ??= ['title' => $tax['title'], 'rate' => $tax['rate'], 'order_amount' => null];
                }
            }
        }

        return array_values($lines);
    }

    private static function taxTitle(Node $tax): string
    {
        return $tax->optionalString('taxName') ?? $tax->optionalString('jurisdiction') ?? 'Tax';
    }

    /** @param list<Node> $payments the first payment that took the money */
    private static function chargedPayment(array $payments): ?Node
    {
        foreach ($payments as $payment) {
            if (in_array($payment->optionalString('status'), self::CHARGED, true)) {
                return $payment;
            }
        }

        return null;
    }

    /** A payment's method as the store owner named it, else in the site's (or the buyer's) language. */
    private static function paymentMethod(Node $payment): ?string
    {
        if ($payment->child('giftcardPaymentDetails') !== null) {
            return self::GIFT_CARD_METHOD;
        }
        if ($payment->child('membershipPaymentDetails') !== null) {
            return self::MEMBERSHIP_METHOD;
        }
        $name = $payment->child('regularPaymentDetails')?->child('paymentMethodName');

        return $name?->child('userDefinedName')?->optionalString('custom')
            ?? $name?->child('userDefinedName')?->optionalString('predefined')
            ?? $name?->optionalString('siteLanguageName')
            ?? $name?->optionalString('buyerLanguageName');
    }

    /**
     * Refunds that returned money (a failed or still-pending refund returned none yet), each with its
     * refunded lines and what each line was refunded.
     *
     * @param list<Node> $refunds
     *
     * @return list<array<string, mixed>>
     */
    private static function refunds(array $refunds): array
    {
        $mapped = [];
        foreach ($refunds as $refund) {
            $refunded = Fields::money($refund->child('summary')?->child('refunded'))
                ?? Decimal::sum(array_map(
                    static fn(Node $transaction): string => Fields::moneyOrZero($transaction, 'amount'),
                    array_values(array_filter($refund->list('transactions'), static fn(Node $transaction): bool => $transaction->optionalString('refundStatus') === self::REFUND_SUCCEEDED)),
                ));
            if (Decimal::isZero($refunded)) {
                continue;
            }
            $amounts = [];
            foreach ($refund->child('summary')?->child('breakdown')?->list('lineItems') ?? [] as $line) {
                $amounts[$line->string('lineItemId')] = Fields::moneyOrZero($line, 'totalRefundedAmount');
            }
            $details = $refund->child('details');
            $items = $details?->list('lineItems') ?? [];
            $mapped[] = [
                'gid' => Fields::id('Refund', $refund->string('id')),
                'created_at' => Fields::time($refund, 'createdDate'),
                'note' => $details?->optionalString('reason'),
                'amount' => $refunded,
                'lines' => array_map(static fn(Node $item): array => [
                    'line_item_gid' => Fields::id('OrderLineItem', $item->string('lineItemId')),
                    'quantity' => $item->int('quantity'),
                    'subtotal' => $amounts[$item->string('lineItemId')] ?? '0',
                ], $items === [] ? $details?->list('items') ?? [] : $items),
            ];
        }

        return $mapped;
    }

    /** @param list<Node> $fulfillments the time the last of them was created */
    private static function lastFulfilled(array $fulfillments): ?string
    {
        $times = array_values(array_filter(array_map(static fn(Node $fulfillment): ?string => Fields::time($fulfillment, 'createdDate'), $fulfillments)));
        sort($times);

        return $times === [] ? null : $times[count($times) - 1];
    }

    /**
     * Each fulfillment (carrier, tracking number and link) once.
     *
     * @param list<Node> $fulfillments
     *
     * @return list<array{gid: string, created_at: string, author: ?string, message: string}>
     */
    private static function notes(array $fulfillments, string $orderCreated): array
    {
        return array_map(static function (Node $fulfillment) use ($orderCreated): array {
            $tracking = $fulfillment->child('trackingInfo');
            $parts = array_filter([
                $tracking?->optionalString('shippingProvider'),
                $tracking?->optionalString('trackingNumber'),
                $tracking?->optionalString('trackingLink'),
            ]);

            return [
                'gid' => Fields::id('Fulfillment', $fulfillment->string('id')),
                'created_at' => Fields::time($fulfillment, 'createdDate') ?? $orderCreated,
                'author' => null,
                'message' => 'Wix fulfillment' . ($parts === [] ? '' : ': ' . implode(' ', $parts)),
            ];
        }, $fulfillments);
    }
}
