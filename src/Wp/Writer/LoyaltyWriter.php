<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\GiftCardRecord;
use IdempotentImporter\Store\GiftCardStore;
use IdempotentImporter\Store\LedgerEntry;
use IdempotentImporter\Store\Link;
use IdempotentImporter\StoredValue\E164;
use IdempotentImporter\StoredValue\LoyaltyPlan;
use IdempotentImporter\StoredValue\SquareStoredValue;

/**
 * Loyalty accounts → Square Loyalty, the store's one points balance (earned and spent at the Square counter
 * and online). Square enrols by phone number, so the account is the one Square has for the customer's phone
 * (E.164, from their mirrored billing phone), created when there is none and linked to their Square
 * customer. The first sync adds the source balance; afterwards only what the source changed is applied
 * (LoyaltyPlan), so points earned or spent in Square stay. A customer with no phone Square can enrol is
 * held, kept here with the reason, and retried. The source history and every adjustment made in Square are
 * the account's ledger (GiftCardStore, kind `loyalty`), shown in the customer's account.
 */
final class LoyaltyWriter implements Writer
{
    public const POINTS = 'PTS';
    private const ADJUST_REASON = 'Moved from the previous store';

    private ?string $programId = null;

    public function __construct(
        private readonly Targets $targets,
        private readonly GiftCardStore $accounts,
        private readonly HttpTransport $transport,
        private readonly string $storeCountry,
    ) {}

    public function kind(): Kind
    {
        return Kind::LoyaltyAccount;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $known = $this->accounts->find($desired->gid);
        $customerGid = $f->nullableString('customer_gid');
        $userId = $customerGid === null ? 0 : ($this->targets->links->targetId(Kind::Customer, $customerGid) ?? 0);
        $record = new GiftCardRecord(
            $known->id ?? 0,
            $desired->gid,
            GiftCardStore::LOYALTY,
            '',
            $known->squareId ?? '',
            self::POINTS,
            (string) $f->int('points'),
            $known->loadedBalance ?? '0',
            'active',
            $userId,
            '',
            '',
            (string) json_encode($f->raw('details'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        $billingPhone = $userId === 0 ? null : get_user_meta($userId, 'billing_phone', true);
        $phone = is_string($billingPhone) ? E164::of($billingPhone, $this->storeCountry) : null;
        if ($phone === null) {
            $id = $this->accounts->save($record->with($record->squareId, $record->loadedBalance, 'No phone number Square Loyalty can enrol (it enrols by phone).'));
            $this->accounts->replaceSourceHistory($id, array_map(self::sourceEntry(...), $f->records('ledger')));

            throw new UnresolvedDependency("{$desired->gid}: its customer has no phone number Square Loyalty can enrol.");
        }
        $id = $this->accounts->save($record);
        $this->accounts->replaceSourceHistory($id, array_map(self::sourceEntry(...), $f->records('ledger')));

        $square = SquareStoredValue::connect($this->transport);
        $account = ($record->squareId === '' ? null : $square->loyaltyAccount($record->squareId))
            ?? $square->loyaltyAccountByPhone($phone)
            ?? $square->createLoyaltyAccount(
                $this->programId ??= $square->loyaltyProgramId(),
                $phone,
                $this->customerId($square, $f, $record),
                SquareStoredValue::key('loyalty', $desired->gid),
            );
        $record = $record->with($account->string('id'), $record->loadedBalance, '');
        $this->accounts->save($record);
        $plan = LoyaltyPlan::next($f->int('points'), (int) $record->loadedBalance, $account->optionalInt('balance') ?? 0);
        if ($plan->points !== 0) {
            $event = $square->adjustPoints($record->squareId, $plan->points, self::ADJUST_REASON, SquareStoredValue::key($desired->gid, 'adjust', $record->loadedBalance, (string) $plan->loaded));
            $this->accounts->addSquareEntry($id, new LedgerEntry('square:' . $event->string('id'), $event->optionalString('created_at'), 'adjust_points', (string) $plan->points, null, $event->string('id'), 'Written in Square by the importer', GiftCardStore::SQUARE_ENTRY));
        }
        $this->accounts->save($record->with($record->squareId, (string) $plan->loaded, $plan->held ?? ''));
        if ($plan->held !== null) {
            $this->targets->log->warning("{$desired->gid}: {$plan->held}");
        }

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return $this->accounts->byId($targetId) !== null;
    }

    /** Gone at the source: the Square account and its points stay; it is kept here, marked retired. */
    public function retire(Link $link): void
    {
        $account = $this->accounts->byId($link->targetId);
        if ($account !== null) {
            $this->accounts->retire($account);
        }
    }

    private function customerId(SquareStoredValue $square, Values $f, GiftCardRecord $record): ?string
    {
        $email = $f->nullableString('email');

        return $email === null || $email === '' ? null : $square->customer($email, $f->nullableString('given_name'), $f->nullableString('family_name'), "wp-user-{$record->userId}");
    }

    private static function sourceEntry(Values $entry): LedgerEntry
    {
        return new LedgerEntry($entry->string('gid'), $entry->nullableString('at'), $entry->string('kind'), (string) $entry->int('points'), null, null, $entry->nullableString('note'));
    }
}
