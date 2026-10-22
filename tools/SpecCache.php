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
        // Re-pinned 2026-10-21: only two descriptions of write operations changed.
        'admin-management-orders' => 'd0506f86d6414d9a08691ff313e9a6f8c9747af67e9ea9748b5096df6bf9c9ba',
        'admin-management-order-operations' => '85058cb818f3da2d56022ded545b6c8ea7730747bf62bfeeabce602164c4bb34',
        'admin-content-pages' => '97008b6fc2af709f8b49477e6a49eb6e33f6e1d0108b22ffcaf229cc1d57ddfd',
        'admin-content-store-content' => '29859d12a29def2e2881faee7dd5d312af3dd9936d8842da40453dc68e756dbc',
        'admin-management-redirects' => 'c06587f9727a44d2a8e4192fc304f90dfd8f42abf8a71d27eaaad08c9f6e5df0',
    ];

    /**
     * The HubSpot CRM API documents the adapter reads (HubSpot/HubSpot-public-api-spec-collection, one OpenAPI 3
     * document per API, at the commit of 2026-10-21): name → path in the repository. Their paths carry the whole
     * `/crm/v3/…` path and their server is the bare API host, so no prefix is stripped.
     */
    private const HUBSPOT_COMMIT = 'dd4246cd4b82fefd93b374547fec352f443fc4ae';
    private const HUBSPOT_DOCUMENTS = [
        'contacts' => 'PublicApiSpecs/CRM/Contacts/Rollouts/424/v3/contacts.json',
        'companies' => 'PublicApiSpecs/CRM/Companies/Rollouts/424/v3/companies.json',
        'notes' => 'PublicApiSpecs/CRM/Notes/Rollouts/424/v3/notes.json',
        'calls' => 'PublicApiSpecs/CRM/Calls/Rollouts/424/v3/calls.json',
        'emails' => 'PublicApiSpecs/CRM/Emails/Rollouts/424/v3/emails.json',
        'meetings' => 'PublicApiSpecs/CRM/Meetings/Rollouts/424/v3/meetings.json',
        'products' => 'PublicApiSpecs/CRM/Products/Rollouts/424/v3/products.json',
        'line-items' => 'PublicApiSpecs/CRM/Line Items/Rollouts/424/v3/lineItems.json',
        'orders' => 'PublicApiSpecs/CRM/Orders/Rollouts/424/v3/orders.json',
        'lists' => 'PublicApiSpecs/CRM/Lists/Rollouts/144891/v3/lists.json',
        'properties' => 'PublicApiSpecs/CRM/Properties/Rollouts/145899/v3/properties.json',
        'deals' => 'PublicApiSpecs/CRM/Deals/Rollouts/424/v3/deals.json',
        'tasks' => 'PublicApiSpecs/CRM/Tasks/Rollouts/424/v3/tasks.json',
        'pipelines' => 'PublicApiSpecs/CRM/Pipelines/Rollouts/145896/v3/pipelines.json',
        'owners' => 'PublicApiSpecs/CRM/Crm Owners/Rollouts/146888/v3/crmOwners.json',
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
            // The same document for the Square account gift cards and store credit are written to (any source).
            new SpecDocument(
                'square_stored_value',
                'connect-api-2026-09-16',
                'https://raw.githubusercontent.com/square/connect-api-specification/79ccc3f89e1527f7b943cc2f6628b6098572583e/api.json',
                '',
                false,
            ),
            ...self::bigCommerce(),
            // Squarespace's Commerce APIs (OpenAPI 3.1.1, linked from the docs' schemas page), published at an
            // unversioned URL, so pinned to the bytes fetched on 2026-10-16 (last modified 2026-10-12). Its
            // paths carry their /1.0, /v1 or /v2 prefix and its server is the bare API host.
            new SpecDocument(
                'squarespace',
                'commerce-apis',
                'https://developers.squarespace.com/commerce-apis/latest/schema-processor-version-version-latest.json',
                '',
                false,
                SpecDocument::FORMAT_JSON,
                '5dd4b1b7cd387d7db65f2f25418dac62c822ded5d90f4d41bba152fa305db368',
            ),
            // Wix publishes no OpenAPI document: the response shapes the adapter reads, transcribed from Wix's
            // official REST reference (each schema cites its page). Its paths are full (the server is the bare
            // www.wixapis.com host).
            SpecDocument::committed('wix', 'rest-reference', 'tests/schemas/wix.json', '', false),
            // Adobe Commerce / Magento Open Source 2.4.9 admin REST schema, "All inclusive" (Swagger 2.0,
            // AdobeDocs/commerce-webapi at the commit of 2026-06-02). Its basePath is /rest/default; a store
            // answers under /rest/<store view code>, so any store code is stripped.
            new SpecDocument(
                'magento',
                'admin-schema-2.4.9',
                'https://raw.githubusercontent.com/AdobeDocs/commerce-webapi/ed5aedf78cb2dd1a28a39f3d03f7e5aabab65fb4/src/openapi/admin-schema-2.4.9.yaml',
                '/rest/[^/]+',
                false,
                SpecDocument::FORMAT_YAML,
            ),
            // Lightspeed eCom publishes no OpenAPI document: the responses the adapter reads, transcribed from the
            // official endpoint reference (developers.lightspeedhq.com/ecom/endpoints/, each schema cites its page).
            // Requests go to <cluster>/<language>/…, so the two-letter shop language is stripped.
            SpecDocument::committed('lightspeed', 'endpoint-reference', 'tests/schemas/lightspeed.json', '/[a-z]{2}', false),
            // Ecwid publishes no OpenAPI document: the responses the adapter reads, transcribed from the official
            // REST API reference (docs.ecwid.com/api-reference/rest-api/, each schema cites its page). Requests go
            // to /api/v3/<store id>/…, so that prefix is stripped.
            SpecDocument::committed('ecwid', 'rest-api-reference', 'tests/schemas/ecwid.json', '/api/v3/\d+', false),
            // Webflow Data API v2 (OpenAPI 3.1, webflow/openapi-spec at the commit of 2026-09-02). Its server is
            // https://api.webflow.com/v2, so the /v2 prefix is stripped.
            new SpecDocument(
                'webflow',
                'data-api-v2',
                'https://raw.githubusercontent.com/webflow/openapi-spec/f6db607359a412dd6aa6cd304674731d7f2dbe10/openapi/v2.yml',
                '/v2',
                false,
                SpecDocument::FORMAT_YAML,
            ),
            ...self::hubSpot(),
            // Salesforce publishes no OpenAPI document for its REST resources: the responses the adapter reads,
            // transcribed from the REST API Developer Guide and the Object Reference (API version 62.0; each schema
            // cites its page). Requests go to <instance>/services/data/v62.0/…, so that prefix is stripped.
            SpecDocument::committed('salesforce', 'rest-api-reference', 'tests/schemas/salesforce.json', '/services/data/v[0-9.]+', false),
            // Pipedrive's API v1 and v2 (OpenAPI 3.0.1, linked from developers.pipedrive.com/docs/api/v1), published at
            // unversioned URLs, so pinned to the bytes fetched on 2026-10-21. Requests go to <company domain>/api/v1/…
            // and /api/v2/…, so those prefixes are stripped.
            new SpecDocument(
                'pipedrive',
                'api-v1',
                'https://developers.pipedrive.com/docs/api/v1/openapi.yaml',
                '/api/v1',
                false,
                SpecDocument::FORMAT_YAML,
                '16a59954e145348795dd9c7acb37612a05497c6bd108d86a90736504c79b9a83',
            ),
            new SpecDocument(
                'pipedrive',
                'api-v2',
                'https://developers.pipedrive.com/docs/api/v1/openapi-v2.yaml',
                '/api/v2',
                false,
                SpecDocument::FORMAT_YAML,
                'b14dad2526cf6dc8696270a2e42a9b141fecc0f47198f45a837ada7330d457d5',
            ),
            ...self::zoho(),
            // FreshBooks publishes no OpenAPI document: the answers the adapter reads, transcribed from its API
            // reference (www.freshbooks.com/api/, each path cites its page). Its paths are full (the server is the
            // bare api.freshbooks.com host).
            SpecDocument::committed('freshbooks', 'api-reference', 'tests/schemas/freshbooks.json', '', false),
            // QuickBooks Online publishes its data model as XML Schemas, in its official Java SDK
            // (intuit/QuickBooks-V3-Java-SDK, ipp-v3-java-data/src/main/xsd, Apache-2.0, the commit of 2026-07-17,
            // c4d5dfad23ffebf0302356876d0dc389708513a9); the JSON API returns the same types. The answers the adapter
            // reads, built from those types (each an object, nothing required, no undeclared key). Its paths are full.
            SpecDocument::committed('quickbooks_online', 'data-model', 'tests/schemas/quickbooks_online.json', '', false),
        ];
    }

    /**
     * The Zoho CRM API v8 documents the adapter reads (zoho/crm-oas, one OpenAPI document per API, at the commit of
     * 2026-09-02), in the order a request is matched against them: the specific paths (`/settings/fields`, `/Notes`,
     * related lists) before the records API's `/{module}`. Requests go to <api domain>/crm/v8/…, so that prefix is
     * stripped.
     */
    private const ZOHO_COMMIT = 'a6687fc961acdaa52592d39c5c7e4385e5b4c289';
    private const ZOHO_DOCUMENTS = ['fields', 'tags', 'org', 'layouts', 'pipeline', 'notes', 'related_records', 'record'];

    /** @return list<SpecDocument> */
    private static function zoho(): array
    {
        return array_map(
            static fn(string $name): SpecDocument => new SpecDocument('zoho', $name, 'https://raw.githubusercontent.com/zoho/crm-oas/' . self::ZOHO_COMMIT . "/v8.0/{$name}/{$name}.yaml", '/crm/v8', false, SpecDocument::FORMAT_YAML),
            self::ZOHO_DOCUMENTS,
        );
    }

    /** @return list<SpecDocument> */
    private static function hubSpot(): array
    {
        $documents = [];
        foreach (self::HUBSPOT_DOCUMENTS as $name => $path) {
            $url = 'https://raw.githubusercontent.com/HubSpot/HubSpot-public-api-spec-collection/' . self::HUBSPOT_COMMIT . '/' . str_replace(' ', '%20', $path);
            $documents[] = new SpecDocument('hubspot', $name, $url, '', false);
        }

        return $documents;
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
        if ($document->isCommitted()) {
            throw new RuntimeException("{$document->source}/{$document->name} is committed at {$document->cachePath()}; it is edited there, never fetched.");
        }
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
            throw new RuntimeException($document->isCommitted() ? "The committed spec {$path} is missing." : "No cached spec at {$path}; run `composer specs` first.");
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
