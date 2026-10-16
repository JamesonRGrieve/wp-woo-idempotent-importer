<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Sync;

use IdempotentShopifyImporter\Mapping\Hasher;
use IdempotentShopifyImporter\Mapping\Unsupported;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Shopify\ShopifyNode;
use IdempotentShopifyImporter\Store\LinkStore;
use IdempotentShopifyImporter\Store\RedirectStore;
use IdempotentShopifyImporter\Wp\Log;
use IdempotentShopifyImporter\Wp\Quiet;
use IdempotentShopifyImporter\Wp\Writer\UnresolvedDependency;
use Throwable;

/**
 * The one path from a Shopify object to WordPress: map, hash, compare with the link, and write only when
 * the hash differs (or the WordPress copy was deleted). Used by scheduled runs, webhooks and the CLI.
 */
final class ObjectSync
{
    /** @param array<string, Handler> $handlers keyed by Kind value */
    public function __construct(
        private readonly array $handlers,
        private readonly LinkStore $links,
        private readonly RedirectStore $redirects,
        private readonly Log $log,
        private readonly bool $keepRawHtml,
        private readonly bool $redirectsEnabled,
    ) {}

    public function handles(Kind $kind): bool
    {
        return isset($this->handlers[$kind->value]);
    }

    public function sync(Kind $kind, ShopifyNode $node, bool $dryRun): Outcome
    {
        $handler = $this->handler($kind);
        $gid = $node->id();
        try {
            $desired = $handler->mapper->map($node);
        } catch (Unsupported $e) {
            $this->log->info("{$gid} not mirrored: {$e->getMessage()}");

            return Outcome::Unsupported;
        }
        if ($desired === null) {
            return $this->retire($kind, $gid, $dryRun);
        }
        $hash = Hasher::hash($desired, $handler->mapper->version());
        $link = $this->links->find($kind, $gid);
        if ($link !== null && $link->hash === $hash && $handler->writer->exists($link->targetId)) {
            return Outcome::Skipped;
        }
        $outcome = $link === null ? Outcome::Created : Outcome::Updated;
        if ($dryRun) {
            return $outcome;
        }
        try {
            $targetId = Quiet::run($this->keepRawHtml, static fn(): int => $handler->writer->write($desired, $link));
        } catch (UnresolvedDependency $e) {
            $this->log->info("{$gid} deferred: {$e->getMessage()}");

            return Outcome::Deferred;
        } catch (Throwable $e) {
            // Deliberate catch-all: one object's failure is recorded and the run carries on.
            $this->log->error("{$gid} failed: {$e->getMessage()}", ['exception' => $e::class]);

            return Outcome::Failed;
        }
        $this->links->save($kind, $gid, $targetId, $hash);
        $this->redirects->syncObject($kind, $gid, $this->redirectsEnabled ? $desired->legacyPaths : [], $desired->redirectTarget);
        foreach ($desired->warnings as $warning) {
            $this->log->warning("{$gid}: {$warning}");
        }

        return $outcome;
    }

    /** The Shopify object is gone (deleted, or no longer belongs on the target). */
    public function retire(Kind $kind, string $gid, bool $dryRun): Outcome
    {
        $link = $this->links->find($kind, $gid);
        if ($link === null) {
            return Outcome::Skipped;
        }
        if ($dryRun) {
            return Outcome::Retired;
        }
        try {
            Quiet::run($this->keepRawHtml, fn() => $this->handler($kind)->writer->retire($link));
        } catch (Throwable $e) {
            // Deliberate catch-all: recorded, and retried by the next reconcile.
            $this->log->error("{$gid} could not be retired: {$e->getMessage()}", ['exception' => $e::class]);

            return Outcome::Failed;
        }
        $this->links->delete($kind, $gid);
        $this->redirects->syncObject($kind, $gid, []);

        return Outcome::Retired;
    }

    private function handler(Kind $kind): Handler
    {
        return $this->handlers[$kind->value] ?? throw new \LogicException("No handler for {$kind->value}");
    }
}
