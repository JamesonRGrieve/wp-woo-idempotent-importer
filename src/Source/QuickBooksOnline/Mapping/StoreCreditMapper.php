<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\QuickBooksOnline\Mapping;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\CreditContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * A customer's credit memos → their store credit in Square Gift Cards (CreditContract): the balance is what is left
 * of their credit memos once every application by a payment is taken off (which QuickBooks also reports as each
 * memo's RemainingCredit), in the memos' currency (the company's home currency when they name none); the ledger is
 * each credit memo issued and each application. The card belongs to the customer's WooCommerce customer.
 */
final class StoreCreditMapper implements Mapper
{
    private const VERSION = 1;

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
        $customer = $node->requiredChild('customer');
        $credits = [];
        $currencies = [];
        foreach ($node->list('credit_memos') as $memo) {
            $currencies[] = Fields::ref($memo, 'CurrencyRef') ?? $node->optionalString('home_currency');
            $applications = array_map(
                static fn(Node $application): array => CreditContract::application(
                    Fields::id('CreditApplication', "{$memo->string('Id')}-{$application->string('payment_id')}"),
                    Fields::date($application, 'date'),
                    $application->string('amount'),
                    'Payment ' . $application->string('payment_id'),
                ),
                $memo->list('applications'),
            );
            $credits[] = CreditContract::credit($memo->id(), Fields::text($memo, 'DocNumber'), Fields::date($memo, 'TxnDate'), Fields::amount($memo, 'TotalAmt') ?? '0', $applications);
        }
        $currency = array_values(array_unique(array_filter($currencies)));
        if (count($currency) !== 1) {
            throw new SourceError("QuickBooks Online customer {$customer->string('Id')}'s credit memos are in " . (count($currency) === 0 ? 'no currency' : 'several currencies') . '.');
        }

        return CreditContract::storeCredit(
            $node->id(),
            $this->settings,
            $currency[0],
            Fields::id('Customer', $customer->string('Id')),
            Fields::email($customer, 'PrimaryEmailAddr'),
            Fields::text($customer, 'GivenName') ?? Fields::text($customer, 'DisplayName'),
            Fields::text($customer, 'FamilyName'),
            $credits,
        );
    }
}
