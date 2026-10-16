<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Source\SourceError;

/**
 * Where one Shopify store's Admin API lives: its GraphQL endpoint, derived from the myshopify domain, and its
 * OAuth token endpoint on the same host. An explicit endpoint exists only for the test harness (set by
 * constant, never UI); the token endpoint follows it, so a fake store serves both. How requests are
 * authenticated is the authorizer's job (ShopifyTokenAuthorizer, ClientCredentialsAuthorizer).
 */
final readonly class Connection
{
    private const DOMAIN_PATTERN = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';
    private const TOKEN_PATH = '/admin/oauth/access_token';

    public function __construct(public string $endpoint) {}

    public static function forStore(string $myshopifyDomain): self
    {
        $domain = strtolower(trim($myshopifyDomain));
        if (preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
            throw new SourceError('The store must be its <name>.myshopify.com domain.');
        }

        return new self('https://' . $domain . '/admin/api/' . ApiVersion::PINNED . '/graphql.json');
    }

    /** The store's OAuth token endpoint (the client credentials grant). */
    public function tokenUrl(): string
    {
        $parts = parse_url($this->endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new SourceError('The Shopify endpoint is not a URL.');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}" . self::TOKEN_PATH;
    }
}
