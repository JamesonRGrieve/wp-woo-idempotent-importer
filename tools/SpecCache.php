<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Every source's published API documents (the catalog the REST fixtures are validated against), cached
 * locally by `composer specs`. URLs are pinned: a versioned document, a repository commit, or (for an
 * unversioned URL) the document's SHA-256.
 */
final class SpecCache
{
    public const FETCH_TIMEOUT_SECONDS = 120;

    private const BIGCOMMERCE_BASE = 'https://docs.bigcommerce.com/openapi/';
    /**
     * The BigCommerce documents the adapter reads, by name → SHA-256. BigCommerce publishes one OpenAPI 3.1
     * document per API area (indexed at https://docs.bigcommerce.com/openapi.json; every one has info.version
     * 1.0.0) at an unversioned URL, so each is pinned to the bytes fetched on 2026-10-16. Their paths carry
     * the whole `/stores/{store_hash}/v2|v3` prefix and their server is the bare API host, so no prefix is
     * stripped.
     */
    private const BIGCOMMERCE_DOCUMENTS = [
        'admin-management-store-information-v2' => '840c3c9d072a27c5f7e228641fefdb4c4378833856b9a1de36b0490b961e7990',
        'admin-catalog-category-trees' => '243c23b1150565f600f319e97c548a262f4cc10cfd24dc8f9f5f485ad36d6ca4',
        'admin-catalog-products' => 'ba4baf2999f51fe30119af42729a36ca425b417be76e1eb4fc503fbb109096a5',
        'admin-catalog-product-variants' => '8597a077b2fd0aac9b98dfa121c8af3f00d77a86fa8460fdef9fdee3d0b9fb8a',
        'admin-catalog-brands' => '66d4560b56f7ad6f09b58a159af44d708715014c15bcd658c072602f8a6b067c',
        'admin-management-customers-v3' => '5576697361ae159a2e6e18c10feca7ec8420912652c97456414cdbf40da1518f',
        'admin-content-marketing' => 'c2469589c1752db9ca39e7eab7191312fa9c36889b1447f6b33cb4c5a49ddf34',
        'admin-management-promotions' => '8c4c0e7e2ca7f4bb226a21563c77ac71e8313d6f6a57c422b804d76ce0999d1b',
        'admin-management-orders' => 'b1022156b66d9a0dd89f3f418f0e51dec626686c87906a8f42c417cdcc56c5c2',
        'admin-management-order-operations' => '85058cb818f3da2d56022ded545b6c8ea7730747bf62bfeeabce602164c4bb34',
        'admin-content-pages' => '97008b6fc2af709f8b49477e6a49eb6e33f6e1d0108b22ffcaf229cc1d57ddfd',
        'admin-content-store-content' => '29859d12a29def2e2881faee7dd5d312af3dd9936d8842da40453dc68e756dbc',
        'admin-management-redirects' => 'c06587f9727a44d2a8e4192fc304f90dfd8f42abf8a71d27eaaad08c9f6e5df0',
    ];

    /** @return list<SpecDocument> */
    public static function catalog(): array
    {
        return [
            new SpecDocument('etsy', 'open-api-v3', 'https://www.etsy.com/openapi/generated/oas/3.0.0.json', '', true),
            // Square API 2026-09-16 (square/connect-api-specification, "Release 2026-09-16").
            new SpecDocument(
                'square_online',
                'connect-api-2026-09-16',
                'https://raw.githubusercontent.com/square/connect-api-specification/79ccc3f89e1527f7b943cc2f6628b6098572583e/api.json',
                '',
                false,
            ),
            ...self::bigCommerce(),
        ];
    }

    /** @return list<SpecDocument> */
    private static function bigCommerce(): array
    {
        $documents = [];
        foreach (self::BIGCOMMERCE_DOCUMENTS as $name => $sha256) {
            $documents[] = new SpecDocument('bigcommerce', $name, self::BIGCOMMERCE_BASE . "{$name}.json", '', false, SpecDocument::FORMAT_JSON, $sha256);
        }

        return $documents;
    }

    /** @return list<SpecDocument> the documents of one source */
    public static function forSource(string $source): array
    {
        return array_values(array_filter(self::catalog(), static fn(SpecDocument $document): bool => $document->source === $source));
    }

    public static function write(SpecDocument $document, string $body): void
    {
        $document->verify($body);
        self::decode($document, $body);
        $path = $document->cachePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw new RuntimeException("Could not create {$dir}");
        }
        if (file_put_contents($path, $body) === false) {
            throw new RuntimeException("Could not write {$path}");
        }
    }

    public static function load(SpecDocument $document): ApiSpec
    {
        $path = $document->cachePath();
        if (!is_file($path)) {
            throw new RuntimeException("No cached spec at {$path}; run `composer specs` first.");
        }

        $body = (string) file_get_contents($path);
        $document->verify($body);

        return ApiSpec::decode(self::decode($document, $body), $document->prefixPattern);
    }

    private static function decode(SpecDocument $document, string $body): mixed
    {
        return $document->format === SpecDocument::FORMAT_YAML
            ? Yaml::parse($body)
            : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
