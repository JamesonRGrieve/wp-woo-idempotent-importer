<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Sync;

/**
 * After a source rejects the credentials, scheduled runs and webhook syncs hold off: every attempt with the
 * same credentials risks the source locking the key or account out. The hold is released when the operator
 * saves the settings, reconnects, or starts a run by hand (one deliberate attempt).
 */
final class CredentialHold
{
    public const OPTION = 'iim_credential_hold';

    public function hold(string $source, string $reason): void
    {
        update_option(self::OPTION, ['source' => $source, 'reason' => $reason, 'since' => gmdate('c')], false);
    }

    /**
     * Why $source's scheduled work is held, or null when it isn't.
     *
     * @phpstan-impure
     */
    public function reason(string $source): ?string
    {
        $hold = get_option(self::OPTION);
        if (!is_array($hold) || ($hold['source'] ?? null) !== $source) {
            return null;
        }
        $reason = is_string($hold['reason'] ?? null) ? $hold['reason'] : '';
        $since = is_string($hold['since'] ?? null) ? $hold['since'] : '';

        return "{$reason} (since {$since} UTC)";
    }

    public function release(): void
    {
        delete_option(self::OPTION);
    }
}
