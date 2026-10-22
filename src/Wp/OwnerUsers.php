<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use WP_User_Query;

/**
 * A CRM owner (the source's user a record belongs to) as a WordPress user, only on a clean match: exactly one
 * WordPress user has the owner's email address. Anything else (no email, no such user, or several) is no match,
 * and the record keeps the owner's source id.
 */
final class OwnerUsers
{
    /** Two are enough to know the match isn't clean. */
    private const AMBIGUITY_LIMIT = 2;

    /** @var array<string, ?int> email → the user, per request */
    private array $matches = [];

    public function userId(?string $email): ?int
    {
        if ($email === null || $email === '') {
            return null;
        }
        $email = strtolower($email);
        if (!array_key_exists($email, $this->matches)) {
            $query = new WP_User_Query(['search' => $email, 'search_columns' => ['user_email'], 'number' => self::AMBIGUITY_LIMIT, 'fields' => 'ID', 'count_total' => false]);
            $ids = array_values(array_filter(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, (array) $query->get_results())));
            $this->matches[$email] = count($ids) === 1 ? $ids[0] : null;
        }

        return $this->matches[$email];
    }
}
