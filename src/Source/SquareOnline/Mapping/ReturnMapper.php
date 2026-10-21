<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A Square return order (an itemized return, or the return half of an exchange) → one WooCommerce refund on
 * each sale it returns from: the returned lines (pointing at the sale's own line items, with their tax) and
 * the return's total. No money moves and nothing is restocked (WooCommerce Square keeps stock). The money
 * Square refunded is named by its refund ids, so a refund Square also lists on the sale order itself is
 * never counted twice. A return whose refunds all failed or were rejected returned nothing (no refunds). A return
 * with no sale to point at (a no-receipt return) has no WooCommerce home.
 */
final class ReturnMapper implements Mapper
{
    private const VERSION = 1;
    /** Refund statuses that mean the return happened (an exchange has no refund at all). */
    private const RETURNED_REFUND_STATUSES = ['APPROVED', 'PENDING', 'COMPLETED'];

    public function kind(): Kind
    {
        return Kind::Return;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $orderId = Fields::squareId($node);
        $refunds = $node->list('refunds');
        $returned = array_filter($refunds, static fn(Node $refund): bool => in_array($refund->optionalString('status'), self::RETURNED_REFUND_STATUSES, true));
        $happened = $refunds === [] || $returned !== [];
        $refundIds = array_values(array_map(static fn(Node $refund): string => Fields::id('Refund', $refund->string('id')), $returned));
        $note = implode('; ', array_values(array_unique(array_filter(array_map(static fn(Node $refund): ?string => $refund->optionalString('reason'), $returned)))));
        $entries = [];
        $orderGids = [];
        foreach ($node->list('returns') as $index => $return) {
            $sourceOrderId = $return->optionalString('source_order_id')
                ?? throw new Unsupported("Return order {$orderId} returns goods sold on no Square order (a no-receipt return); WooCommerce refunds need the sale.");
            $orderGids[] = Fields::id('Order', $sourceOrderId);
            if (!$happened) {
                continue;
            }
            $entries[] = [
                'gid' => Fields::id('Refund', $orderId . ':' . ($return->optionalString('uid') ?? (string) $index)),
                'order_gid' => Fields::id('Order', $sourceOrderId),
                'created_at' => Fields::requiredTime($node, 'created_at'),
                'amount' => self::total($return),
                'note' => $note === '' ? "Square return {$orderId}" : $note,
                'refund_gids' => $refundIds,
                'lines' => array_map(static fn(Node $line): array => self::line($sourceOrderId, $line, self::taxTitles($return)), $return->list('return_line_items')),
                'charges' => array_map(static fn(Node $charge): array => [
                    'name' => $charge->optionalString('name') ?? 'Service charge',
                    'total' => Decimal::subtract(Fields::moneyOrZero($charge, 'total_money'), Fields::moneyOrZero($charge, 'total_tax_money')),
                    'tax' => Fields::moneyOrZero($charge, 'total_tax_money'),
                ], $return->list('return_service_charges')),
            ];
        }

        return new Desired(Kind::Return, $node->id(), [
            'name' => "#{$orderId}",
            'source_name' => $node->child('source')?->optionalString('name'),
            'location_id' => $node->optionalString('location_id'),
            'order_gids' => array_values(array_unique($orderGids)),
            'refunds' => $entries,
        ]);
    }

    /** What one return entry gives back: Square's own total for it, else its lines' and charges' totals. */
    private static function total(Node $return): string
    {
        $total = $return->child('return_amounts') === null ? null : Fields::money($return->requiredChild('return_amounts'), 'total_money');
        if ($total !== null) {
            return $total;
        }

        return Decimal::sum([
            ...array_map(static fn(Node $line): string => Fields::moneyOrZero($line, 'total_money'), $return->list('return_line_items')),
            ...array_map(static fn(Node $charge): string => Fields::moneyOrZero($charge, 'total_money'), $return->list('return_service_charges')),
        ]);
    }

    /** @return array<string, string> each returned tax's uid → its title (the sale's tax line it reverses) */
    private static function taxTitles(Node $return): array
    {
        $titles = [];
        foreach ($return->list('return_taxes') as $tax) {
            $titles[(string) $tax->optionalString('uid')] = $tax->optionalString('name') ?? 'Tax';
        }

        return $titles;
    }

    /**
     * @param array<string, string> $taxTitles
     *
     * @return array<string, mixed>
     */
    private static function line(string $sourceOrderId, Node $line, array $taxTitles): array
    {
        $sourceUid = $line->optionalString('source_line_item_uid');
        $tax = Fields::moneyOrZero($line, 'total_tax_money');
        $taxes = [];
        foreach ($line->list('applied_taxes') as $applied) {
            $taxes[] = ['title' => $taxTitles[(string) $applied->optionalString('tax_uid')] ?? 'Tax', 'amount' => Fields::moneyOrZero($applied, 'applied_money')];
        }

        return [
            // The sale's line, as OrderMapper identifies it; a line returned without one stays on the refund's total.
            'line_item_gid' => $sourceUid === null ? null : Fields::id('OrderLineItem', "{$sourceOrderId}-{$sourceUid}"),
            'name' => $line->optionalString('name') ?? $line->optionalString('variation_name') ?? '',
            'quantity' => Fields::wholeQuantity($line->string('quantity'), "Returned line \"{$line->optionalString('name')}\""),
            'subtotal' => Decimal::subtract(Fields::moneyOrZero($line, 'total_money'), $tax),
            'tax' => $tax,
            'taxes' => $taxes,
        ];
    }
}
