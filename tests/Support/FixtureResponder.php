<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Tests\Support;

/**
 * Answers a GraphQL request from one store state's recorded fixtures, as Shopify would: by operation
 * name, and for paged/by-id operations by the `after` cursor and `id`. Page size and search filters are
 * ignored (a fixture is the store's whole answer). Shared by the unit tests' transport and the
 * end-to-end fake Shopify server.
 */
final class FixtureResponder
{
    private const MATCHED_VARIABLES = ['after', 'id'];

    /** @var list<Fixture> */
    private readonly array $fixtures;

    public function __construct(string $state)
    {
        $this->fixtures = Fixtures::state($state);
    }

    /**
     * @param array<array-key, mixed> $request decoded request body
     *
     * @return array<string, mixed> response body
     */
    public function respond(array $request): array
    {
        $operation = is_string($request['operationName'] ?? null) ? $request['operationName'] : '';
        $variables = is_array($request['variables'] ?? null) ? $request['variables'] : [];
        foreach ($this->fixtures as $fixture) {
            if ($fixture->operation === $operation && self::matches($fixture->variables, $variables)) {
                return ['data' => $fixture->data];
            }
        }

        return ['errors' => [['message' => "No fixture for {$operation} " . json_encode($variables)]]];
    }

    /**
     * @param array<string, mixed> $recorded
     * @param array<array-key, mixed> $sent
     */
    private static function matches(array $recorded, array $sent): bool
    {
        foreach (self::MATCHED_VARIABLES as $name) {
            if (($recorded[$name] ?? null) !== ($sent[$name] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
