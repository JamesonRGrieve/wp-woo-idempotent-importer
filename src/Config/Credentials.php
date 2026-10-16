<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Config;

use IdempotentImporter\Wp\Options;

/**
 * Secrets, resolved in order: a wp-config constant, then the process environment (both injected by the
 * deploy pipeline from the secret store), then the value saved on the settings page. Values are never
 * rendered back to the page, logged or included in errors.
 */
final class Credentials
{
    public const ACCESS_TOKEN = 'IIM_SHOPIFY_ACCESS_TOKEN';
    public const WEBHOOK_SECRET = 'IIM_SHOPIFY_WEBHOOK_SECRET';
    /** Test harness only: points the client at a local fake Shopify. Never settable from the admin. */
    public const ENDPOINT_OVERRIDE = 'IIM_SHOPIFY_ENDPOINT';

    public const OPTION = 'iim_credentials';

    public static function accessToken(): string
    {
        return self::resolve(self::ACCESS_TOKEN);
    }

    public static function webhookSecret(): string
    {
        return self::resolve(self::WEBHOOK_SECRET);
    }

    public static function endpointOverride(): ?string
    {
        return self::constant(self::ENDPOINT_OVERRIDE);
    }

    /** Whether a secret comes from outside the database (then the settings page can't change it). */
    public static function isInjected(string $name): bool
    {
        return self::external($name) !== null;
    }

    public static function save(string $name, #[\SensitiveParameter] string $value): void
    {
        $stored = Options::array(self::OPTION);
        $stored[$name] = $value;
        update_option(self::OPTION, $stored, false);
    }

    private static function resolve(string $name): string
    {
        $stored = Options::array(self::OPTION)[$name] ?? '';

        return self::external($name) ?? (is_string($stored) ? $stored : '');
    }

    private static function external(string $name): ?string
    {
        $env = getenv($name);

        return self::constant($name) ?? (is_string($env) && $env !== '' ? $env : null);
    }

    private static function constant(string $name): ?string
    {
        $value = defined($name) ? constant($name) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
