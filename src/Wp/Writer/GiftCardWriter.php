<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\GiftCardContract;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Store\GiftCardRecord;
use IdempotentImporter\Store\GiftCardStore;
use IdempotentImporter\Store\LedgerEntry;
use IdempotentImporter\Store\Link;
use IdempotentImporter\StoredValue\GiftCardNumber;
use IdempotentImporter\StoredValue\GiftCardPlan;
use IdempotentImporter\StoredValue\SquareStoredValue;
use IdempotentImporter\Support\Currency;

/**
 * Gift cards and store credit → Square Gift Cards, the store's one spendable balance (online through
 * WooCommerce Square, at the Square counter). Each source card becomes one digital Square card with a
 * custom number: the source's own code where Square can take it, else a new number (when the operator
 * allows it) that the customer sees in their account. Its balance is loaded on activation, and afterwards
 * only what the source changed is applied (GiftCardPlan), so money spent in Square is never put back. The
 * card is linked to the customer's Square profile (found by email, created when missing). The source history
 * and every write made in Square are kept as the card's ledger (GiftCardStore), since Square can't backdate
 * activity. A card gone from the source is kept, marked retired; nothing is deactivated in Square.
 */
final class GiftCardWriter implements Writer
{
    /** The key new gift card numbers are derived with (wp-config); WordPress's secure-auth salt when unset. */
    public const NUMBER_KEY = 'IIM_GIFT_CARD_NUMBER_KEY';

    public function __construct(
        private readonly Targets $targets,
        private readonly GiftCardStore $cards,
        private readonly HttpTransport $transport,
        #[\SensitiveParameter]
        private readonly string $siteKey,
    ) {}

    public function kind(): Kind
    {
        return Kind::GiftCard;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $square = SquareStoredValue::connect($this->transport);
        $currency = $f->string('currency');
        $known = $this->cards->find($desired->gid);
        $record = new GiftCardRecord(
            $known->id ?? 0,
            $desired->gid,
            $f->string('kind'),
            $known === null || $known->gan === '' ? $this->number($desired->gid, $f) : $known->gan,
            $known->squareId ?? '',
            $currency,
            $f->string('balance'),
            $known->loadedBalance ?? '0',
            $f->string('state'),
            $this->userId($f->nullableString('customer_gid')),
            (string) $f->nullableString('code_hint'),
            $known->held ?? '',
            (string) json_encode($f->raw('details'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        $id = $this->cards->save($record);
        $this->cards->replaceSourceHistory($id, array_map(self::sourceEntry(...), $f->records('ledger')));

        $card = $record->squareId !== ''
            ? $square->card($record->squareId) ?? throw new SourceError("Square has no gift card {$record->squareId} for {$desired->gid}.")
            : $square->cardByGan($record->gan) ?? $square->createCard($record->gan, SquareStoredValue::key('card', $desired->gid));
        $record = $record->with($card->string('id'), $record->loadedBalance, $record->held);
        $this->cards->save($record);

        $target = $f->string('state') === GiftCardContract::ACTIVE ? $f->string('balance') : '0';
        $plan = GiftCardPlan::next($target, $record->loadedBalance, $card->string('state'), self::balance($card, $currency), $f->bool('load_increases'));
        if ($plan->step !== null) {
            $activity = $square->activity($plan->step, $record->squareId, $plan->amount, $currency, $desired->gid, SquareStoredValue::key($desired->gid, $plan->step, $record->loadedBalance, $plan->loaded));
            $this->cards->addSquareEntry($id, new LedgerEntry(
                'square:' . $activity->string('id'),
                $activity->optionalString('created_at'),
                strtolower($plan->step),
                $plan->step === GiftCardPlan::DECREMENT ? '-' . $plan->amount : $plan->amount,
                self::balance($activity, $currency, 'gift_card_balance_money'),
                $activity->string('id'),
                'Written in Square by the importer',
                GiftCardStore::SQUARE_ENTRY,
            ));
        }
        $this->cards->save($record->with($record->squareId, $plan->loaded, $plan->held ?? ''));
        if ($plan->held !== null) {
            $this->targets->log->warning("{$desired->gid}: {$plan->held}");
        }
        $this->linkCustomer($square, $card, $f, $record);

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return $this->cards->byId($targetId) !== null;
    }

    /** Gone at the source: the card stays in Square as it is and is kept here, marked retired. */
    public function retire(Link $link): void
    {
        $card = $this->cards->byId($link->targetId);
        if ($card !== null) {
            $this->cards->retire($card);
        }
    }

    /** The card's Square number: its own code where Square can take it, else a new one (if allowed). */
    private function number(string $gid, Values $f): string
    {
        $code = $f->nullableString('code');
        $gan = $code === null ? null : GiftCardNumber::fromSourceCode($code);
        if ($gan !== null) {
            return $gan;
        }
        if (!$f->bool('new_numbers')) {
            throw new SourceError($code === null
                ? "{$gid}: the source doesn't reveal the card's code, and new gift card numbers are switched off."
                : "{$gid}: Square can't use the card's code as its number, and new gift card numbers are switched off.");
        }

        return GiftCardNumber::generated($gid, $this->siteKey);
    }

    private function userId(?string $customerGid): int
    {
        return $customerGid === null ? 0 : ($this->targets->links->targetId(Kind::Customer, $customerGid) ?? 0);
    }

    private function linkCustomer(SquareStoredValue $square, Node $card, Values $f, GiftCardRecord $record): void
    {
        $email = $f->nullableString('email');
        if ($email === null || $email === '') {
            return;
        }
        $customerId = $square->customer($email, $f->nullableString('given_name'), $f->nullableString('family_name'), $record->userId > 0 ? "wp-user-{$record->userId}" : $record->gid);
        if (!in_array($customerId, $card->strings('customer_ids'), true)) {
            $square->linkCustomer($record->squareId, $customerId);
        }
    }

    private static function sourceEntry(Values $entry): LedgerEntry
    {
        return new LedgerEntry(
            $entry->string('gid'),
            $entry->nullableString('at'),
            $entry->string('kind'),
            $entry->string('amount'),
            $entry->nullableString('balance_after'),
            $entry->nullableString('reference'),
            $entry->nullableString('note'),
        );
    }

    private static function balance(Node $object, string $currency, string $field = 'balance_money'): string
    {
        $amount = $object->child($field)?->optionalInt('amount');

        return $amount === null ? '0' : Currency::fromMinorUnits($amount, $currency);
    }
}
