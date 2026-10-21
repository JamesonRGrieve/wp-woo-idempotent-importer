<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Mapping\GiftCardContract;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapters;
use IdempotentImporter\Source\BigCommerce\BigCommerceReader;
use IdempotentImporter\Source\BigCommerce\Mapping\GiftCertificateMapper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\StoredValue\GiftCardNumber;
use IdempotentImporter\StoredValue\GiftCardPlan;
use IdempotentImporter\Support\Currency;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GiftCardsTest extends TestCase
{
    public function testASourceCodeKeepsItsCharactersWhenSquareCanTakeIt(): void
    {
        self::assertSame('4KZX0OUHIJHC', GiftCardNumber::fromSourceCode('4kz-x0o-uhi-jhc'), 'separators dropped, upper case');
        self::assertNull(GiftCardNumber::fromSourceCode('SHORT1'), 'fewer than 8 characters');
        self::assertNull(GiftCardNumber::fromSourceCode(str_repeat('A', 21)), 'more than 20');
        self::assertNull(GiftCardNumber::fromSourceCode('4111111111111111'), 'a card BIN');
        self::assertNull(GiftCardNumber::fromSourceCode('7783320000001234'), "Square's digital gift card BIN");
        self::assertSame('4KZ12345AB', GiftCardNumber::fromSourceCode('4KZ12345AB'), 'a digit first is fine when it is no BIN');
    }

    public function testNewNumbersAreStableKeyedAndNeverLookLikeACard(): void
    {
        $number = GiftCardNumber::generated('gid://bigcommerce/GiftCertificate/902', 'e2e-gift-card-key');

        self::assertSame('9415999166351453', $number);
        self::assertSame($number, GiftCardNumber::generated('gid://bigcommerce/GiftCertificate/902', 'e2e-gift-card-key'));
        self::assertNotSame($number, GiftCardNumber::generated('gid://bigcommerce/GiftCertificate/902', 'another-site'), 'not guessable without the site key');
        self::assertSame(GiftCardNumber::GENERATED_LENGTH, strlen($number));
        self::assertSame($number, GiftCardNumber::fromSourceCode($number), 'a valid Square number');
    }

    public function testAPendingCardIsActivatedWithTheWholeBalance(): void
    {
        $plan = GiftCardPlan::next('50', '0', 'PENDING', '0', false);
        self::assertSame([GiftCardPlan::ACTIVATE, '50', '50', null], [$plan->step, $plan->amount, $plan->loaded, $plan->held]);
        self::assertNull(GiftCardPlan::next('0', '0', 'PENDING', '0', false)->step, 'nothing to load');
    }

    public function testOnlyWhatTheSourceChangedIsApplied(): void
    {
        $drop = GiftCardPlan::next('30', '50', 'ACTIVE', '45', false);
        self::assertSame([GiftCardPlan::DECREMENT, '20', '30'], [$drop->step, $drop->amount, $drop->loaded], 'the 5 spent in Square stays spent');
        self::assertNull(GiftCardPlan::next('30', '30', 'ACTIVE', '12', false)->step, 'unchanged at the source');
    }

    public function testARiseIsHeldUnlessTheOperatorAllowsTheLoadFee(): void
    {
        $held = GiftCardPlan::next('60', '50', 'ACTIVE', '50', false);
        self::assertSame([null, '50'], [$held->step, $held->loaded], 'not loaded, so the next run sees it again');
        self::assertStringContainsString('load fee', (string) $held->held);
        $loaded = GiftCardPlan::next('60', '50', 'ACTIVE', '50', true);
        self::assertSame([GiftCardPlan::INCREMENT, '10', '60'], [$loaded->step, $loaded->amount, $loaded->loaded]);
    }

    public function testMoneySpentInBothStoresIsNeverTakenBelowZero(): void
    {
        $plan = GiftCardPlan::next('0', '50', 'ACTIVE', '15', false);
        self::assertSame([GiftCardPlan::DECREMENT, '15', '0'], [$plan->step, $plan->amount, $plan->loaded]);
        self::assertStringContainsString('35 was spent in both stores', (string) $plan->held);
        $empty = GiftCardPlan::next('0', '50', 'ACTIVE', '0', false);
        self::assertNull($empty->step);
        self::assertNotNull($empty->held);
    }

    public function testACardSquareBlockedIsLeftAlone(): void
    {
        $plan = GiftCardPlan::next('30', '50', 'BLOCKED', '50', true);
        self::assertSame([null, '50'], [$plan->step, $plan->loaded]);
        self::assertStringContainsString('BLOCKED', (string) $plan->held);
    }

    public function testMinorUnitsAreExact(): void
    {
        self::assertSame(1999, Currency::toMinorUnits('19.99', 'CAD'));
        self::assertSame(500, Currency::toMinorUnits('500', 'JPY'));
        $this->expectException(InvalidArgumentException::class);
        Currency::toMinorUnits('0.005', 'CAD');
    }

    public function testBigCommerceGiftCertificates(): void
    {
        [$active, $pending] = $this->certificates('v1');

        self::assertSame(['4KZ-X0O-UHI-JHC', '50', GiftCardContract::ACTIVE, 'CAD'], [$active->nullableString('code'), $active->string('balance'), $active->string('state'), $active->string('currency')]);
        self::assertSame('gid://bigcommerce/Customer/41', $active->nullableString('customer_gid'));
        self::assertSame(['ingrid@example.com', 'Ingrid', 'Larsen'], [$active->nullableString('email'), $active->nullableString('given_name'), $active->nullableString('family_name')]);
        self::assertSame([['gid' => 'gid://bigcommerce/GiftCertificateIssue/901', 'at' => '2026-09-01T16:00:00Z', 'kind' => 'issue', 'amount' => '50', 'balance_after' => '50', 'reference' => 'gid://bigcommerce/Order/501', 'note' => null]], $active->raw('ledger'));
        self::assertSame(GiftCardContract::DISABLED, $pending->string('state'), 'an unpaid certificate');
        self::assertNull($pending->nullableString('customer_gid'));
        self::assertSame([false, true], [$active->bool('load_increases'), $active->bool('new_numbers')], 'the Square policies are part of the card');

        [$spent] = $this->certificates('v2');
        self::assertSame('30', $spent->string('balance'));
        self::assertSame(['observed', '-20', '30'], [$spent->records('ledger')[1]->string('kind'), $spent->records('ledger')[1]->string('amount'), $spent->records('ledger')[1]->nullableString('balance_after')]);
    }

    /** @return list<Values> */
    private function certificates(string $state): array
    {
        $reader = BigCommerceReader::connect('https://api.bigcommerce.test', 'bcfix123', 'fixture-access-token', new ScriptedTransport(new FixtureResponder('bigcommerce', $state)), new RecordingSleeper());
        $mapper = new GiftCertificateMapper(self::settings());

        return array_map(static fn(Node $node): Values => Values::of($mapper->map($node)), $reader->page(Kind::GiftCard, null, null, '')->nodes);
    }

    private static function settings(): Settings
    {
        return new Settings(
            source: Adapters::DEFAULT,
            storeDomain: '',
            kinds: Kind::SYNC_ORDER,
            filters: [],
            intervalMinutes: 60,
            fullReconcileHours: 24,
            storeSettings: false,
            shippingZones: false,
            redirects: true,
            localiseImages: true,
            cleanHtml: false,
            preserveOrderNumbers: true,
            generateMissingSkus: false,
            defaultAuthor: 0,
            notifyEmail: '',
        );
    }
}
