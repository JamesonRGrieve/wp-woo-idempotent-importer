<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Wix\Mapping\LoyaltyAccountMapper;
use IdempotentImporter\Source\Wix\WixReader;
use IdempotentImporter\StoredValue\E164;
use IdempotentImporter\StoredValue\LoyaltyPlan;
use IdempotentImporter\Tests\Support\FixtureResponder;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class LoyaltyTest extends TestCase
{
    public function testPhonesAreE164OrNotGuessedAt(): void
    {
        self::assertSame('+13065550177', E164::of('+1 306 555 0177', 'FR'), 'a country code is kept whatever the store');
        self::assertSame('+13065550177', E164::of('(306) 555-0177', 'CA'), 'a NANP store adds +1');
        self::assertSame('+13065550177', E164::of('1-306-555-0177', 'US'));
        self::assertNull(E164::of('06 12 34 56 78', 'FR'), 'no country code outside NANP: not guessed');
        self::assertNull(E164::of('555-0177', 'CA'), 'too short');
        self::assertNull(E164::of('+1234', 'CA'));
    }

    public function testOnlyWhatTheSourceChangedIsApplied(): void
    {
        $first = LoyaltyPlan::next(120, 0, 0);
        self::assertSame([120, 120, null], [$first->points, $first->loaded, $first->held]);
        $spent = LoyaltyPlan::next(70, 120, 200);
        self::assertSame([-50, 70], [$spent->points, $spent->loaded], 'points earned in Square stay');
        self::assertSame(0, LoyaltyPlan::next(70, 70, 5)->points);
    }

    public function testPointsSpentInBothProgramsAreNeverTakenBelowZero(): void
    {
        $plan = LoyaltyPlan::next(0, 120, 30);
        self::assertSame([-30, 0], [$plan->points, $plan->loaded]);
        self::assertStringContainsString('90 were spent in both programs', (string) $plan->held);
    }

    public function testWixLoyaltyAccounts(): void
    {
        [$account] = $this->accounts('v1');

        self::assertSame(120, $account->int('points'));
        self::assertSame('gid://wix/Contact/2c4e6a80-0001-4b3d-9e1f-5a6b7c8d0001', $account->nullableString('customer_gid'));
        self::assertSame(['maya.thunderchild@example.com', 'Maya', 'Thunderchild'], [$account->nullableString('email'), $account->nullableString('given_name'), $account->nullableString('family_name')]);
        self::assertSame([['earn', 100], ['adjust', 20]], array_map(static fn(Values $entry): array => [$entry->string('kind'), $entry->int('points')], $account->records('ledger')));

        [$redeemed] = $this->accounts('v2');
        self::assertSame(70, $redeemed->int('points'));
        self::assertSame(['redeem', -50], [$redeemed->records('ledger')[2]->string('kind'), $redeemed->records('ledger')[2]->int('points')], 'a redemption takes points away');
    }

    /** @return list<Values> */
    private function accounts(string $state): array
    {
        $reader = WixReader::connect('https://www.wixapis.test', 'fixture-api-key', 'fixture-site', new ScriptedTransport(new FixtureResponder('wix', $state)), new RecordingSleeper());

        return array_map(static fn(Node $node): Values => Values::of((new LoyaltyAccountMapper())->map($node)), $reader->page(Kind::LoyaltyAccount, null, null, '')->nodes);
    }
}
