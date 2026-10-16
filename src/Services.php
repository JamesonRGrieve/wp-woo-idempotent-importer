<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter;

use IdempotentShopifyImporter\Config\Credentials;
use IdempotentShopifyImporter\Config\Settings;
use IdempotentShopifyImporter\Mapping\ArticleMapper;
use IdempotentShopifyImporter\Mapping\BlogMapper;
use IdempotentShopifyImporter\Mapping\CollectionMapper;
use IdempotentShopifyImporter\Mapping\CommentMapper;
use IdempotentShopifyImporter\Mapping\CustomerMapper;
use IdempotentShopifyImporter\Mapping\DeliveryProfileMapper;
use IdempotentShopifyImporter\Mapping\DiscountMapper;
use IdempotentShopifyImporter\Mapping\DraftOrderMapper;
use IdempotentShopifyImporter\Mapping\FileMapper;
use IdempotentShopifyImporter\Mapping\MenuMapper;
use IdempotentShopifyImporter\Mapping\OrderMapper;
use IdempotentShopifyImporter\Mapping\PageMapper;
use IdempotentShopifyImporter\Mapping\ProductMapper;
use IdempotentShopifyImporter\Mapping\ReviewMapper;
use IdempotentShopifyImporter\Mapping\ShopMapper;
use IdempotentShopifyImporter\Mapping\UrlRedirectMapper;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Shopify\Connection;
use IdempotentShopifyImporter\Shopify\GraphQlClient;
use IdempotentShopifyImporter\Shopify\QueryLibrary;
use IdempotentShopifyImporter\Shopify\Reader;
use IdempotentShopifyImporter\Shopify\SystemSleeper;
use IdempotentShopifyImporter\Store\LinkStore;
use IdempotentShopifyImporter\Store\RedirectStore;
use IdempotentShopifyImporter\Store\RunLog;
use IdempotentShopifyImporter\Store\SeenStore;
use IdempotentShopifyImporter\Store\Tables;
use IdempotentShopifyImporter\Sync\Cursors;
use IdempotentShopifyImporter\Sync\Handler;
use IdempotentShopifyImporter\Sync\ObjectSync;
use IdempotentShopifyImporter\Sync\RunLock;
use IdempotentShopifyImporter\Sync\Runner;
use IdempotentShopifyImporter\Wp\ContentHtml;
use IdempotentShopifyImporter\Wp\Log;
use IdempotentShopifyImporter\Wp\MediaLibrary;
use IdempotentShopifyImporter\Wp\WpHttpTransport;
use IdempotentShopifyImporter\Wp\Writer\ArticleWriter;
use IdempotentShopifyImporter\Wp\Writer\CommentWriter;
use IdempotentShopifyImporter\Wp\Writer\CustomerWriter;
use IdempotentShopifyImporter\Wp\Writer\DeliveryProfileWriter;
use IdempotentShopifyImporter\Wp\Writer\DiscountWriter;
use IdempotentShopifyImporter\Wp\Writer\FileWriter;
use IdempotentShopifyImporter\Wp\Writer\MenuWriter;
use IdempotentShopifyImporter\Wp\Writer\OrderWriter;
use IdempotentShopifyImporter\Wp\Writer\PageWriter;
use IdempotentShopifyImporter\Wp\Writer\ProductWriter;
use IdempotentShopifyImporter\Wp\Writer\ReviewWriter;
use IdempotentShopifyImporter\Wp\Writer\ShopWriter;
use IdempotentShopifyImporter\Wp\Writer\Targets;
use IdempotentShopifyImporter\Wp\Writer\TermWriter;
use IdempotentShopifyImporter\Wp\Writer\UrlRedirectWriter;
use wpdb;

/**
 * The composition root: builds every service once, from the current settings, and nothing else
 * constructs dependencies. The Shopify connection is built only when something reads from Shopify, so
 * the site works (and reports a useful error) before credentials are configured.
 */
final class Services
{
    private readonly Settings $settings;
    private readonly Tables $tables;
    private readonly LinkStore $links;
    private readonly RedirectStore $redirects;
    private readonly WpHttpTransport $transport;
    private ?Log $log = null;
    private ?ObjectSync $objectSync = null;

    public function __construct(private readonly wpdb $db)
    {
        $this->settings = Settings::load();
        $this->tables = new Tables($db);
        $this->links = new LinkStore($db, $this->tables);
        $this->redirects = new RedirectStore($db, $this->tables);
        $this->transport = new WpHttpTransport();
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function tables(): Tables
    {
        return $this->tables;
    }

    public function links(): LinkStore
    {
        return $this->links;
    }

    public function redirects(): RedirectStore
    {
        return $this->redirects;
    }

    public function runLog(): RunLog
    {
        return new RunLog($this->db, $this->tables);
    }

    public function log(): Log
    {
        return $this->log ??= Log::woocommerce();
    }

    public function cursors(): Cursors
    {
        return new Cursors();
    }

    public function lock(): RunLock
    {
        return new RunLock();
    }

    /** Throws ShopifyError when the store or token isn't configured. */
    public function client(): GraphQlClient
    {
        $override = Credentials::endpointOverride();
        $connection = $override !== null
            ? new Connection($override, Credentials::accessToken())
            : Connection::forStore($this->settings->storeDomain, Credentials::accessToken());

        return new GraphQlClient($connection, QueryLibrary::bundled(), $this->transport, new SystemSleeper());
    }

    /** Throws ShopifyError when the store or token isn't configured. */
    public function reader(): Reader
    {
        return new Reader($this->client());
    }

    public function objectSync(): ObjectSync
    {
        return $this->objectSync ??= new ObjectSync(
            $this->handlers(),
            $this->links,
            $this->redirects,
            $this->log(),
            !$this->settings->cleanHtml,
            $this->settings->redirects,
        );
    }

    public function runner(): Runner
    {
        return new Runner(
            $this->reader(),
            $this->objectSync(),
            $this->settings,
            $this->links,
            new SeenStore($this->db, $this->tables),
            $this->cursors(),
            $this->lock(),
            $this->runLog(),
            $this->log(),
        );
    }

    /** @return array<string, Handler> keyed by Kind value */
    private function handlers(): array
    {
        $media = new MediaLibrary($this->links, $this->transport);
        $targets = new Targets(
            $this->links,
            $media,
            new ContentHtml($media, $this->settings->cleanHtml, $this->settings->localiseImages),
            $this->settings,
            $this->log(),
        );
        $handlers = [
            new Handler(new ShopMapper(), new ShopWriter($targets, $this->redirects)),
            new Handler(new FileMapper(), new FileWriter($targets)),
            new Handler(new CollectionMapper(), new TermWriter(Kind::Collection, 'product_cat', $targets)),
            new Handler(new ProductMapper($this->settings->generateMissingSkus), new ProductWriter($targets)),
            new Handler(new CustomerMapper(), new CustomerWriter()),
            new Handler(new DiscountMapper(), new DiscountWriter($targets)),
            new Handler(new OrderMapper(), new OrderWriter(Kind::Order, $targets)),
            new Handler(new DraftOrderMapper(), new OrderWriter(Kind::DraftOrder, $targets)),
            new Handler(new PageMapper(), new PageWriter($targets)),
            new Handler(new BlogMapper(), new TermWriter(Kind::Blog, 'category', $targets)),
            new Handler(new ArticleMapper(), new ArticleWriter($targets)),
            new Handler(new CommentMapper(), new CommentWriter($targets)),
            new Handler(new MenuMapper(), new MenuWriter($targets)),
            new Handler(new UrlRedirectMapper(), new UrlRedirectWriter()),
            new Handler(new DeliveryProfileMapper(), new DeliveryProfileWriter($targets, $this->db)),
            new Handler(new ReviewMapper(), new ReviewWriter()),
        ];
        $byKind = [];
        foreach ($handlers as $handler) {
            $byKind[$handler->mapper->kind()->value] = $handler;
        }

        return $byKind;
    }
}
