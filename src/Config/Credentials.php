<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Config;

use IdempotentImporter\Wp\Options;

/**
 * Secrets, by name (each adapter owns its names), resolved in order: a wp-config constant, then the
 * process environment (both injected by the deploy pipeline from the secret store), then the value saved
 * on the settings page or by an OAuth flow. Values are never rendered back to the page, logged or
 * included in errors.
 */
final class Credentials
{
    public const OPTION = 'iim_credentials';

    public static function get(string $name): string
    {
        $stored = Options::array(self::OPTION)[$name] ?? '';

        return self::external($name) ?? (is_string($stored) ? $stored : '');
    }

    /** A wp-config constant only; for test-harness switches that must never be settable from the admin. */
    public static function constant(string $name): ?string
    {
        $value = defined($name) ? constant($name) : null;

        return is_string($value) && $value !== '' ? $value : null;
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

    private static function external(string $name): ?string
    {
        $env = getenv($name);

        return self::constant($name) ?? (is_string($env) && $env !== '' ? $env : null);
    }
}
