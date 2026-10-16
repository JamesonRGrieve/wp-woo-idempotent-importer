<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Source\SourceError;

/**
 * The page sizes the client asks for, per operation, learned over one run. Shopify refuses before running it
 * any query whose requested cost (fixed by the selection and its page sizes, not by the store's data) is over
 * its single-query limit (shopify.dev/docs/apps/build/apis/graphql-admin/rate-limits: 1,000 points). Each
 * operation starts at QueryLibrary's defaults; a refusal scales all of its sizes down together, in proportion
 * to how far over the limit it was (never below 1), and the next request uses the smaller sizes. Every
 * successful response reports its requested cost, so sizes grow back toward the defaults while there is room,
 * but never back to sizes Shopify refused. Nested connections are paged to the end, so smaller pages only
 * mean more requests, never less data. Deterministic: the same store gives the same sequence of sizes.
 *
 * Sizes are scaled from requested cost, not actual cost: the limit is enforced on requested cost, and a
 * store's small objects only lower the actual cost after Shopify has accepted the query.
 */
final class PageSizes
{
    /** Shopify's documented single-query cost limit, used until a refusal reports the store's own. */
    public const MAX_QUERY_COST = 1000;

    /** The share of the limit a resized query aims for, so it fits with a margin. */
    public const TARGET_SHARE = 0.9;

    private const FULL_SCALE = 1.0;
    /** The step by which growth backs off from a refused share until the sizes differ from the refused ones. */
    private const BELOW_REFUSED = 0.99;
    private const SMALLEST_SIZE = 1;

    /** @var array<string, float> operation => share of the default sizes it currently asks for */
    private array $scales = [];

    /** @var array<string, float> operation => the smallest share Shopify refused */
    private array $refusedScales = [];

    /** @var array<string, int> operation => the cost limit its last refusal reported */
    private array $limits = [];

    public function __construct(private readonly QueryLibrary $library) {}

    /**
     * The size variables to send with the operation's next request.
     *
     * @return array<string, int>
     */
    public function for(string $operation): array
    {
        return self::at($this->library->pageSizes($operation), $this->scales[$operation] ?? self::FULL_SCALE);
    }

    /**
     * Shopify refused the operation at for($operation) because its requested cost was over the limit: every
     * size shrinks, by at least one step. When every size is already 1 the operation can't fit at all.
     */
    public function refused(string $operation, int $cost, int $limit): void
    {
        $defaults = $this->library->pageSizes($operation);
        $scale = $this->scales[$operation] ?? self::FULL_SCALE;
        $current = self::at($defaults, $scale);
        if (array_filter($current, static fn(int $size): bool => $size > self::SMALLEST_SIZE) === []) {
            throw new SourceError("{$operation}: one object costs {$cost} points even one at a time, over Shopify's single-query limit of {$limit}.");
        }
        $this->refusedScales[$operation] = min($scale, $this->refusedScales[$operation] ?? self::FULL_SCALE);
        $this->limits[$operation] = $limit;
        $factor = min(self::TARGET_SHARE, self::target($limit) / max($cost, 1));
        $next = $scale * $factor;
        while (self::at($defaults, $next) === $current) {
            $next *= $factor;
        }
        $this->scales[$operation] = $next;
    }

    /** Shopify ran the operation at for($operation), reporting this requested cost: grow back where there is room. */
    public function succeeded(string $operation, int $requestedCost): void
    {
        $scale = $this->scales[$operation] ?? self::FULL_SCALE;
        $target = self::target($this->limits[$operation] ?? self::MAX_QUERY_COST);
        if ($scale >= self::FULL_SCALE || $requestedCost <= 0 || $requestedCost >= $target) {
            return;
        }
        $defaults = $this->library->pageSizes($operation);
        $next = min(self::FULL_SCALE, $scale * $target / $requestedCost);
        $refused = $this->refusedScales[$operation] ?? null;
        if ($refused !== null && $next >= $refused) {
            $refusedSizes = self::at($defaults, $refused);
            $next = $refused;
            while (self::at($defaults, $next) === $refusedSizes) {
                $next *= self::BELOW_REFUSED;
            }
        }
        if ($next > $scale) {
            $this->scales[$operation] = $next;
        }
    }

    /**
     * @param array<string, int> $defaults
     *
     * @return array<string, int>
     */
    private static function at(array $defaults, float $scale): array
    {
        return array_map(static fn(int $size): int => max(self::SMALLEST_SIZE, (int) floor($size * $scale)), $defaults);
    }

    private static function target(int $limit): float
    {
        return $limit * self::TARGET_SHARE;
    }
}
