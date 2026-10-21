<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\StoredValue;

use IdempotentImporter\Config\Credentials;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Http\SystemSleeper;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\Rest\RestClient;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SquareOnline\SquareOnlineAuthorizer;
use IdempotentImporter\Support\Currency;

/**
 * The Square seller account the store's gift cards (and store credit) live in, whatever the source: Square
 * Gift Cards, Square Loyalty and Square customers, through the Square API at the version the Square Online
 * adapter speaks.
 * Every write carries an idempotency key derived from what it writes, so a retried run never applies it
 * twice. Configured in wp-config.php or the environment: the access token (GIFTCARDS_READ, GIFTCARDS_WRITE,
 * LOYALTY_READ, LOYALTY_WRITE, CUSTOMERS_READ, CUSTOMERS_WRITE), the location activities are recorded at, and
 * the environment.
 */
final class SquareStoredValue
{
    public const ACCESS_TOKEN = 'IIM_SQUARE_STORED_VALUE_ACCESS_TOKEN';
    public const LOCATION_ID = 'IIM_SQUARE_STORED_VALUE_LOCATION_ID';
    /** `production` (default) or `sandbox`. */
    public const ENVIRONMENT = 'IIM_SQUARE_STORED_VALUE_ENVIRONMENT';
    /** Test harness only: points the client at a local fake Square. */
    public const ENDPOINT_OVERRIDE = 'IIM_SQUARE_STORED_VALUE_ENDPOINT';
    public const PRODUCTION = 'https://connect.squareup.com';
    public const SANDBOX = 'https://connect.squareupsandbox.com';
    private const LABEL = 'Square';
    private const IDEMPOTENCY_KEY_LENGTH = 45;
    /** Why the importer adjusts a balance: Square's reasons closest to "the card's other store changed it". */
    private const ADJUSTMENT_REASON = 'SUPPORT_ISSUE';
    private const DETAILS = [
        GiftCardPlan::ACTIVATE => 'activate_activity_details',
        GiftCardPlan::INCREMENT => 'adjust_increment_activity_details',
        GiftCardPlan::DECREMENT => 'adjust_decrement_activity_details',
    ];

    public function __construct(private readonly RestClient $client, public readonly string $locationId) {}

    public static function configured(): bool
    {
        return Credentials::get(self::ACCESS_TOKEN) !== '' && Credentials::get(self::LOCATION_ID) !== '';
    }

    /** @throws SourceError when the token or location isn't configured */
    public static function connect(HttpTransport $transport): self
    {
        $location = Credentials::get(self::LOCATION_ID);
        if (!self::configured()) {
            throw new SourceError('Square gift cards are not configured: set ' . self::ACCESS_TOKEN . ' and ' . self::LOCATION_ID . '.');
        }
        $base = Credentials::constant(self::ENDPOINT_OVERRIDE)
            ?? (Credentials::get(self::ENVIRONMENT) === 'sandbox' ? self::SANDBOX : self::PRODUCTION);

        return new self(new RestClient(self::LABEL, $base, new SquareOnlineAuthorizer(Credentials::get(self::ACCESS_TOKEN)), $transport, new SystemSleeper()), $location);
    }

    /** An idempotency key for one write: a hash of what it is (Square takes up to 45 characters for most writes). */
    public static function key(string ...$parts): string
    {
        return substr(hash('sha256', implode("\x1f", $parts)), 0, self::IDEMPOTENCY_KEY_LENGTH);
    }

    public function card(string $id): ?Node
    {
        return $this->client->get('/v2/gift-cards/' . rawurlencode($id))?->child('gift_card');
    }

    public function cardByGan(string $gan): ?Node
    {
        return $this->client->query('/v2/gift-cards/from-gan', ['gan' => $gan])?->child('gift_card');
    }

    /** A new digital card with a custom GAN (PENDING, no balance). */
    public function createCard(string $gan, string $key): Node
    {
        return $this->required('/v2/gift-cards', [
            'idempotency_key' => $key,
            'location_id' => $this->locationId,
            'gift_card' => ['type' => 'DIGITAL', 'gan_source' => 'OTHER', 'gan' => $gan],
        ])->requiredChild('gift_card');
    }

    /**
     * An ACTIVATE, ADJUST_INCREMENT or ADJUST_DECREMENT activity. Activating outside Square's Orders API
     * takes the buyer's payment instruments for Square's compliance checks; a migrated card has none, so
     * the source card's identity stands in for them.
     */
    public function activity(string $type, string $cardId, string $amount, string $currency, string $sourceGid, string $key): Node
    {
        $money = ['amount' => Currency::toMinorUnits($amount, $currency), 'currency' => $currency];
        $details = $type === GiftCardPlan::ACTIVATE
            ? ['amount_money' => $money, 'buyer_payment_instrument_ids' => [$sourceGid], 'reference_id' => $sourceGid]
            : ['amount_money' => $money, 'reason' => self::ADJUSTMENT_REASON];

        return $this->required('/v2/gift-cards/activities', [
            'idempotency_key' => $key,
            'gift_card_activity' => [
                'type' => $type,
                'location_id' => $this->locationId,
                'gift_card_id' => $cardId,
                self::DETAILS[$type] => $details,
            ],
        ])->requiredChild('gift_card_activity');
    }

    /** The Square customer with this email, created when there is none; returns its id. */
    public function customer(string $email, ?string $givenName, ?string $familyName, string $referenceId): string
    {
        $found = $this->client->query('/v2/customers/search', [
            'limit' => 1,
            'query' => ['filter' => ['email_address' => ['exact' => $email]]],
        ])?->list('customers')[0] ?? null;
        if ($found !== null) {
            return $found->string('id');
        }

        return $this->required('/v2/customers', array_filter([
            'idempotency_key' => self::key('customer', $referenceId),
            'given_name' => $givenName,
            'family_name' => $familyName,
            'email_address' => $email,
            'reference_id' => $referenceId,
        ], static fn(?string $value): bool => $value !== null && $value !== ''))->requiredChild('customer')->string('id');
    }

    public function linkCustomer(string $cardId, string $customerId): Node
    {
        return $this->required('/v2/gift-cards/' . rawurlencode($cardId) . '/link-customer', ['customer_id' => $customerId])->requiredChild('gift_card');
    }

    /**
     * The seller's loyalty program id (its one program, `main`).
     *
     * @throws SourceError when the seller has no Square Loyalty program (no subscription)
     */
    public function loyaltyProgramId(): string
    {
        return $this->client->get('/v2/loyalty/programs/main')?->child('program')?->string('id')
            ?? throw new SourceError('The Square seller has no loyalty program (Square Loyalty needs its own subscription).');
    }

    public function loyaltyAccount(string $id): ?Node
    {
        return $this->client->get('/v2/loyalty/accounts/' . rawurlencode($id))?->child('loyalty_account');
    }

    /** The loyalty account enrolled with this phone number (E.164), or null. */
    public function loyaltyAccountByPhone(string $phone): ?Node
    {
        return $this->client->query('/v2/loyalty/accounts/search', [
            'query' => ['mappings' => [['phone_number' => $phone]]],
            'limit' => 1,
        ])?->list('loyalty_accounts')[0] ?? null;
    }

    public function createLoyaltyAccount(string $programId, string $phone, ?string $customerId, string $key): Node
    {
        return $this->required('/v2/loyalty/accounts', [
            'loyalty_account' => array_filter([
                'program_id' => $programId,
                'mapping' => ['phone_number' => $phone],
                'customer_id' => $customerId,
            ], static fn(mixed $value): bool => $value !== null),
            'idempotency_key' => $key,
        ])->requiredChild('loyalty_account');
    }

    /** Adds (or, negative, removes) points outside a purchase; returns the ADJUST_POINTS event. */
    public function adjustPoints(string $accountId, int $points, string $reason, string $key): Node
    {
        return $this->required('/v2/loyalty/accounts/' . rawurlencode($accountId) . '/adjust', [
            'idempotency_key' => $key,
            'adjust_points' => ['points' => $points, 'reason' => $reason],
        ])->requiredChild('event');
    }

    /** @param array<string, mixed> $body */
    private function required(string $path, array $body): Node
    {
        return $this->client->write($path, $body) ?? throw new SourceError("Square has no {$path}.");
    }
}
