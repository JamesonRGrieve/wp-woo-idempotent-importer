<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\FreshBooks\Mapping;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\CreditContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * A client's credit notes → their store credit in Square Gift Cards (CreditContract): the balance is what is left
 * of their credit notes once the parts applied to invoices are taken off, in the credit notes' currency; the ledger
 * is each credit note issued and each application. The card belongs to the client's WooCommerce customer.
 */
final class StoreCreditMapper implements Mapper
{
    private const VERSION = 1;
    private const LOOSE_DATE = '/^(\d{4})-(\d{1,2})-(\d{1,2})$/';

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
        $client = $node->requiredChild('client');
        $credits = [];
        $currencies = [];
        foreach ($node->list('credit_notes') as $note) {
            $currencies[] = Fields::text($note, 'currency_code') ?? $note->child('amount')?->optionalString('code');
            $applications = array_map(
                static fn(Node $payment): array => CreditContract::application(
                    Fields::id('CreditApplication', $payment->int('id')),
                    Fields::date($payment, 'date'),
                    Fields::moneyOrZero($payment, 'amount'),
                    'Invoice ' . $payment->int('invoiceid'),
                ),
                $note->list('applications'),
            );
            $credits[] = CreditContract::credit($note->id(), Fields::text($note, 'credit_number'), self::date($note), Fields::moneyOrZero($note, 'amount'), $applications);
        }
        $currency = array_values(array_unique(array_filter($currencies)));
        if (count($currency) !== 1) {
            throw new SourceError("FreshBooks client {$client->int('id')}'s credit notes are in " . (count($currency) === 0 ? 'no currency' : 'several currencies') . '.');
        }

        return CreditContract::storeCredit(
            $node->id(),
            $this->settings,
            $currency[0],
            Fields::id('Customer', $client->int('id')),
            Fields::text($client, 'email'),
            Fields::text($client, 'fname'),
            Fields::text($client, 'lname'),
            $credits,
        );
    }

    /** A credit note's date (FreshBooks shows `2020-9-22` as well as `2020-09-22`) as the ISO time of its UTC midnight. */
    private static function date(Node $note): ?string
    {
        $value = Fields::text($note, 'create_date');
        if ($value === null || preg_match(self::LOOSE_DATE, $value, $parts) !== 1) {
            return null;
        }

        return sprintf('%04d-%02d-%02dT00:00:00Z', (int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }
}
