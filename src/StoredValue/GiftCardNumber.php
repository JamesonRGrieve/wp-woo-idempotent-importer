<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\StoredValue;

/**
 * The Square gift card account number (GAN) a source card gets. Square takes a custom GAN of 8 to 20
 * alphanumeric characters, unique per seller, that doesn't start with a card BIN or Square's own gift card
 * BINs (https://developer.squareup.com/docs/gift-cards/using-gift-cards-api, "Custom GAN gift cards"). A
 * source code that fits (once its separators are dropped) is kept, so the customer's card works as it is.
 * A source that hides its codes, or a code Square can't take, gets a new number: 16 digits from a keyed
 * hash of the card's identity (stable for this site, so re-runs find it again, and not guessable without
 * the site's key), starting with 9, which no major card network or Square uses.
 */
final class GiftCardNumber
{
    public const GENERATED_LENGTH = 16;
    private const MIN_LENGTH = 8;
    private const MAX_LENGTH = 20;
    private const GENERATED_PREFIX = '9';
    /** Square's own gift card BINs (physical, digital), which a custom GAN may not start with. */
    private const SQUARE_BINS = ['778273', '778332'];
    /** First digits of the major card networks' BINs (Mastercard 2-series, Amex/Diners/JCB, Visa, Mastercard, Discover). */
    private const CARD_BIN_FIRST_DIGITS = ['2', '3', '4', '5', '6'];

    /** The source code as a GAN, or null when Square can't take it. */
    public static function fromSourceCode(string $code): ?string
    {
        $gan = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        $length = strlen($gan);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || self::startsLikeACard($gan)) {
            return null;
        }

        return $gan;
    }

    /** A new number for card $gid, keyed by $siteKey. */
    public static function generated(string $gid, #[\SensitiveParameter] string $siteKey): string
    {
        $digits = '';
        foreach (str_split(hash_hmac('sha256', $gid, $siteKey), 2) as $byte) {
            $digits .= (string) (hexdec($byte) % 10);
        }

        return self::GENERATED_PREFIX . substr($digits, 0, self::GENERATED_LENGTH - 1);
    }

    private static function startsLikeACard(string $gan): bool
    {
        if (ctype_digit($gan[0]) && in_array($gan[0], self::CARD_BIN_FIRST_DIGITS, true) && ctype_digit(substr($gan, 0, 6))) {
            return true;
        }
        foreach (self::SQUARE_BINS as $bin) {
            if (str_starts_with($gan, $bin)) {
                return true;
            }
        }

        return false;
    }
}
