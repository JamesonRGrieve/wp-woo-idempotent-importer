<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Shopify;

/**
 * Where and how to reach one Shopify store's Admin GraphQL API. The endpoint is derived from the
 * myshopify domain; an explicit endpoint exists only for the test harness (set by constant, never UI).
 */
final readonly class Connection
{
    private const DOMAIN_PATTERN = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';

    public function __construct(
        public string $endpoint,
        #[\SensitiveParameter]
        public string $accessToken,
    ) {}

    public static function forStore(string $myshopifyDomain, #[\SensitiveParameter] string $accessToken): self
    {
        $domain = strtolower(trim($myshopifyDomain));
        if (preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
            throw new ShopifyError('The store must be its <name>.myshopify.com domain.');
        }
        if ($accessToken === '') {
            throw new ShopifyError('No Shopify access token is configured.');
        }

        return new self('https://' . $domain . '/admin/api/' . ApiVersion::PINNED . '/graphql.json', $accessToken);
    }

    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint, 'accessToken' => '[redacted]'];
    }
}
