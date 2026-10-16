<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\Adapters;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Store\LinkStore;
use IdempotentImporter\Store\RedirectStore;
use IdempotentImporter\Store\RunLog;
use IdempotentImporter\Store\SeenStore;
use IdempotentImporter\Store\Tables;
use IdempotentImporter\Sync\CredentialHold;
use IdempotentImporter\Sync\Cursors;
use IdempotentImporter\Sync\Handler;
use IdempotentImporter\Sync\ObjectSync;
use IdempotentImporter\Sync\RunLock;
use IdempotentImporter\Sync\Runner;
use IdempotentImporter\Wp\ContentHtml;
use IdempotentImporter\Wp\Log;
use IdempotentImporter\Wp\MediaLibrary;
use IdempotentImporter\Wp\WpHttpTransport;
use IdempotentImporter\Wp\Writer\ArticleWriter;
use IdempotentImporter\Wp\Writer\CommentWriter;
use IdempotentImporter\Wp\Writer\CustomerWriter;
use IdempotentImporter\Wp\Writer\DeliveryProfileWriter;
use IdempotentImporter\Wp\Writer\DiscountWriter;
use IdempotentImporter\Wp\Writer\FileWriter;
use IdempotentImporter\Wp\Writer\MenuWriter;
use IdempotentImporter\Wp\Writer\OrderWriter;
use IdempotentImporter\Wp\Writer\PageWriter;
use IdempotentImporter\Wp\Writer\ProductWriter;
use IdempotentImporter\Wp\Writer\ReviewWriter;
use IdempotentImporter\Wp\Writer\ShopWriter;
use IdempotentImporter\Wp\Writer\Targets;
use IdempotentImporter\Wp\Writer\TermWriter;
use IdempotentImporter\Wp\Writer\UrlRedirectWriter;
use IdempotentImporter\Wp\Writer\Writer;
use wpdb;

/**
 * The composition root: builds every service once, from the current settings, and nothing else
 * constructs dependencies. The active source's reader is built only when something reads from it, so
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

    /** The source platform the operator selected. */
    public function adapter(): Adapter
    {
        return Adapters::for($this->settings->source);
    }

    public function transport(): HttpTransport
    {
        return $this->transport;
    }

    public function cursors(): Cursors
    {
        return new Cursors($this->settings->source);
    }

    public function lock(): RunLock
    {
        return new RunLock();
    }

    public function credentialHold(): CredentialHold
    {
        return new CredentialHold();
    }

    /** Throws SourceError when the source is deferred or its credentials aren't configured. */
    public function reader(): SourceReader
    {
        return $this->adapter()->reader($this->settings, $this->transport);
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
            $this->adapter(),
            $this->reader(),
            $this->objectSync(),
            $this->settings,
            $this->links,
            new SeenStore($this->db, $this->tables),
            $this->cursors(),
            $this->lock(),
            $this->credentialHold(),
            $this->runLog(),
            $this->log(),
        );
    }

    /** @return array<string, Handler> the active source's mappers, each with its kind's writer, keyed by Kind value */
    private function handlers(): array
    {
        $writers = $this->writers();
        $byKind = [];
        foreach ($this->adapter()->mappers($this->settings) as $mapper) {
            $kind = $mapper->kind()->value;
            $byKind[$kind] = new Handler($mapper, $writers[$kind]);
        }

        return $byKind;
    }

    /** @return array<string, Writer> one writer per kind, keyed by Kind value; the same for every source */
    private function writers(): array
    {
        $media = new MediaLibrary($this->links, $this->transport);
        $targets = new Targets(
            $this->links,
            $media,
            new ContentHtml($media, $this->settings->cleanHtml, $this->settings->localiseImages),
            $this->settings,
            $this->log(),
        );
        $writers = [
            new ShopWriter($targets, $this->redirects),
            new FileWriter($targets),
            new TermWriter(Kind::Collection, 'product_cat', $targets),
            new ProductWriter($targets),
            new CustomerWriter(),
            new DiscountWriter($targets),
            new OrderWriter(Kind::Order, $targets),
            new OrderWriter(Kind::DraftOrder, $targets),
            new PageWriter($targets),
            new TermWriter(Kind::Blog, 'category', $targets),
            new ArticleWriter($targets),
            new CommentWriter($targets),
            new MenuWriter($targets),
            new UrlRedirectWriter(),
            new DeliveryProfileWriter($targets, $this->db),
            new ReviewWriter($targets),
        ];
        $byKind = [];
        foreach ($writers as $writer) {
            $byKind[$writer->kind()->value] = $writer;
        }

        return $byKind;
    }
}
