<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\GiftCardContract;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A customer's store credit → a Square gift card linked to the customer (the store credit ruling): its
 * balance (the sum of its amounts, in the store's currency), no code (a new number, shown in the customer's
 * account), and as its ledger the balance BigCommerce reported (it keeps no store credit history).
 *
 * A gift certificate → a Square gift card (GiftCardContract) with its code, remaining balance and state
 * (active; pending, which isn't paid for yet, and disabled carry no balance; expired). It belongs to its
 * recipient (`to_email`), and to the customer who bought it when that customer is mirrored. BigCommerce
 * keeps no redemption history, only the amount issued and what remains, so the ledger is the issue and
 * what was spent from it before the move. The rest (sender, message, template, dates, order) is kept with
 * the card.
 */
final class GiftCertificateMapper implements Mapper
{
    private const VERSION = 1;
    private const STATES = ['active' => GiftCardContract::ACTIVE, 'pending' => GiftCardContract::DISABLED, 'disabled' => GiftCardContract::DISABLED, 'expired' => GiftCardContract::EXPIRED];
    private const DETAILS = ['to_name', 'from_name', 'from_email', 'amount', 'template', 'message', 'status', 'order_id', 'purchase_date', 'expiry_date'];
    private const UNIX_TIME = '/^\d+$/';

    public function __construct(private readonly Settings $settings) {}

    public function kind(): Kind
    {
        return Kind::GiftCard;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        if (str_contains($node->id(), '/StoreCredit/')) {
            return $this->storeCredit($node);
        }
        $id = Fields::sourceId($node);
        $currency = $node->optionalString('currency_code') ?? throw new Unsupported("Gift certificate {$id} names no currency.");
        $amount = Fields::decimalOrZero($node, 'amount');
        $balance = $node->optionalDecimal('balance') ?? $amount;
        $orderId = $node->optionalInt('order_id');
        $order = $orderId === null || $orderId === 0 ? null : Fields::id('Order', $orderId);
        $issued = self::time($node->optionalString('purchase_date'));
        $ledger = [GiftCardContract::entry(Fields::id('GiftCertificateIssue', $id), $issued, 'issue', $amount, $amount, $order)];
        $spent = Decimal::subtract($balance, $amount);
        if (!Decimal::isZero($spent)) {
            $ledger[] = GiftCardContract::entry(Fields::id('GiftCertificateBalance', "{$id}-{$balance}"), null, 'observed', $spent, $balance, null, 'Spent at BigCommerce before the move');
        }
        $customerId = $node->optionalInt('customer_id');
        [$given, $family] = array_pad(explode(' ', trim((string) $node->optionalString('to_name')), 2), 2, null);

        return GiftCardContract::desired(
            $node->id(),
            $this->settings,
            GiftCardContract::GIFT_CARD,
            $node->optionalString('code'),
            null,
            $currency,
            $balance,
            self::STATES[(string) $node->optionalString('status')] ?? GiftCardContract::DISABLED,
            $customerId === null || $customerId === 0 ? null : Fields::id('Customer', $customerId),
            $node->optionalString('to_email'),
            $given === '' ? null : $given,
            $family,
            $ledger,
            Fields::details($node, self::DETAILS),
        );
    }

    private function storeCredit(Node $node): Desired
    {
        $id = Fields::sourceId($node);
        $balance = Decimal::sum(array_map(static fn(Node $credit): string => Fields::decimalOrZero($credit, 'amount'), $node->list('store_credit_amounts')));
        $ledger = Decimal::isZero($balance) ? [] : [GiftCardContract::entry(Fields::id('StoreCreditBalance', "{$id}-{$balance}"), null, 'observed', $balance, $balance, null, 'Store credit at BigCommerce')];

        return GiftCardContract::desired(
            $node->id(),
            $this->settings,
            GiftCardContract::STORE_CREDIT,
            null,
            null,
            $node->string('currency'),
            $balance,
            GiftCardContract::ACTIVE,
            Fields::id('Customer', $id),
            $node->optionalString('email'),
            $node->optionalString('first_name'),
            $node->optionalString('last_name'),
            $ledger,
            [],
        );
    }

    /** A gift certificate date (a Unix timestamp, or RFC 2822 as v2 dates usually are) as ISO 8601 UTC. */
    private static function time(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = preg_match(self::UNIX_TIME, $value) === 1 ? (int) $value : strtotime($value);

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
