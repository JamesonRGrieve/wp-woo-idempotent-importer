<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Shopify\ShopifyNode;

/** Order → WooCommerce order, with refunds, fulfillments, transactions and timeline comments. */
final class OrderMapper implements Mapper
{
    private const VERSION = 1;
    private const COMMENT_EVENT = 'CommentEvent';
    private const FINANCIAL_STATUS = [
        'PENDING' => 'pending',
        'AUTHORIZED' => 'on-hold',
        'PARTIALLY_PAID' => 'on-hold',
        'PAID' => 'processing',
        'PARTIALLY_REFUNDED' => 'processing',
        'REFUNDED' => 'refunded',
        'VOIDED' => 'cancelled',
        'EXPIRED' => 'cancelled',
    ];
    private const DONE_FULFILLMENT = ['FULFILLED', 'FULFILLMENT_NOT_REQUIRED'];

    public function kind(): Kind
    {
        return Kind::Order;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(ShopifyNode $node): Desired
    {
        $financial = $node->optionalString('displayFinancialStatus');
        $fulfillment = $node->string('displayFulfillmentStatus');
        $fees = [];
        foreach ($node->list('additionalFees') as $fee) {
            $fees[] = ['name' => $fee->string('name'), 'total' => Fields::moneyOrZero($fee, 'price'), 'taxes' => Fields::taxLines($fee->list('taxLines'))];
        }
        foreach (['Duties' => 'originalTotalDutiesSet', 'Tip' => 'totalTipReceivedSet'] as $name => $field) {
            $amount = Fields::money($node, $field);
            if ($amount !== null && $amount !== '0') {
                $fees[] = ['name' => $name, 'total' => $amount, 'taxes' => []];
            }
        }

        return new Desired(Kind::Order, $node->id(), [
            ...OrderParts::common($node, $node->list('shippingLines')),
            'number' => $node->int('number'),
            'status' => self::status($node->optionalString('cancelledAt'), $financial, $fulfillment),
            'financial_status' => $financial,
            'fulfillment_status' => $fulfillment,
            'processed_at' => $node->string('processedAt'),
            'cancelled_at' => $node->optionalString('cancelledAt'),
            'cancel_reason' => $node->optionalString('cancelReason'),
            'closed_at' => $node->optionalString('closedAt'),
            'customer_note' => $node->optionalString('note'),
            'test' => $node->bool('test'),
            'source_name' => $node->optionalString('sourceName'),
            'locale' => $node->optionalString('customerLocale'),
            'risk_level' => $node->optionalString('riskLevel'),
            'payment_method_title' => implode(', ', $node->strings('paymentGatewayNames')),
            'fees' => $fees,
            'refunds' => array_map(self::refund(...), $node->list('refunds')),
            'fulfillments' => array_map(self::fulfillment(...), $node->list('fulfillments')),
            'transactions' => array_map(self::transaction(...), $node->list('transactions')),
            'notes' => self::notes($node),
        ]);
    }

    /** Shopify's financial + fulfillment state → the WooCommerce order status. */
    public static function status(?string $cancelledAt, ?string $financial, string $fulfillment): string
    {
        $status = self::FINANCIAL_STATUS[$financial ?? 'PENDING'] ?? 'pending';
        if ($cancelledAt !== null && $status !== 'refunded') {
            return 'cancelled';
        }
        if ($status === 'processing' && in_array($fulfillment, self::DONE_FULFILLMENT, true)) {
            return 'completed';
        }

        return $status;
    }

    /** @return array<string, mixed> */
    private static function refund(ShopifyNode $refund): array
    {
        return [
            'gid' => $refund->id(),
            'created_at' => $refund->optionalString('createdAt'),
            'note' => $refund->optionalString('note'),
            'amount' => Fields::moneyOrZero($refund, 'totalRefundedSet'),
            'lines' => array_map(static fn(ShopifyNode $line): array => [
                'line_item_gid' => $line->requiredChild('lineItem')->id(),
                'quantity' => $line->int('quantity'),
                'restock_type' => $line->string('restockType'),
                'subtotal' => Fields::moneyOrZero($line, 'subtotalSet'),
                'tax' => Fields::moneyOrZero($line, 'totalTaxSet'),
            ], $refund->list('refundLineItems')),
        ];
    }

    /** @return array<string, mixed> */
    private static function fulfillment(ShopifyNode $fulfillment): array
    {
        return [
            'gid' => $fulfillment->id(),
            'name' => $fulfillment->string('name'),
            'status' => $fulfillment->string('status'),
            'created_at' => $fulfillment->string('createdAt'),
            'delivered_at' => $fulfillment->optionalString('deliveredAt'),
            'tracking' => array_map(static fn(ShopifyNode $tracking): array => [
                'company' => $tracking->optionalString('company'),
                'number' => $tracking->optionalString('number'),
                'url' => $tracking->optionalString('url'),
            ], $fulfillment->list('trackingInfo')),
        ];
    }

    /** @return array<string, mixed> */
    private static function transaction(ShopifyNode $transaction): array
    {
        return [
            'gid' => $transaction->id(),
            'kind' => $transaction->string('kind'),
            'status' => $transaction->string('status'),
            'gateway' => $transaction->optionalString('gateway'),
            'processed_at' => $transaction->optionalString('processedAt'),
            'authorization' => $transaction->optionalString('authorizationCode'),
            'amount' => Fields::moneyOrZero($transaction, 'amountSet'),
        ];
    }

    /** @return list<array{gid: string, created_at: string, author: ?string, message: string}> */
    private static function notes(ShopifyNode $order): array
    {
        $notes = [];
        foreach ($order->list('events') as $event) {
            if ($event->typename() === self::COMMENT_EVENT) {
                $notes[] = [
                    'gid' => $event->id(),
                    'created_at' => $event->string('createdAt'),
                    'author' => $event->child('author')?->optionalString('name'),
                    'message' => $event->string('rawMessage'),
                ];
            }
        }

        return $notes;
    }
}
