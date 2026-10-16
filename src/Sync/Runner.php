<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Sync;

use IdempotentShopifyImporter\Config\Settings;
use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Shopify\Reader;
use IdempotentShopifyImporter\Shopify\SearchQuery;
use IdempotentShopifyImporter\Shopify\ShopifyError;
use IdempotentShopifyImporter\Shopify\SourceRegistry;
use IdempotentShopifyImporter\Store\LinkStore;
use IdempotentShopifyImporter\Store\RunLog;
use IdempotentShopifyImporter\Store\SeenStore;
use IdempotentShopifyImporter\Wp\Log;

/**
 * A run, one step at a time: each step reads and mirrors pages until its time budget is spent, then
 * persists where it got to. Kinds run in dependency order. A full run retires copies of objects Shopify
 * no longer has, but only for a kind that completed cleanly and wasn't filtered.
 */
final class Runner
{
    public function __construct(
        private readonly Reader $reader,
        private readonly ObjectSync $sync,
        private readonly Settings $settings,
        private readonly LinkStore $links,
        private readonly SeenStore $seen,
        private readonly Cursors $cursors,
        private readonly RunLock $lock,
        private readonly RunLog $runLog,
        private readonly Log $log,
    ) {}

    /**
     * Starts a run unless one is in progress; returns it, or null when another run holds the lock.
     *
     * @param list<Kind>|null $onlyKinds
     */
    public function start(Mode $mode, bool $dryRun, ?array $onlyKinds = null): ?RunState
    {
        $id = wp_generate_uuid4();
        if (!$this->lock->acquire($id)) {
            return null;
        }
        $kinds = array_values(array_filter(
            $this->settings->kinds,
            fn(Kind $kind): bool => $this->sync->handles($kind) && ($onlyKinds === null || in_array($kind, $onlyKinds, true)),
        ));
        $state = new RunState($id, $mode, $dryRun, $kinds, gmdate('c'));
        $state->save();
        $this->runLog->start($id, $mode->value, $dryRun);
        $this->log->info("Run {$id} started ({$mode->value}" . ($dryRun ? ', dry run' : '') . ').');

        return $state;
    }

    /** Works on the current run for up to $budgetSeconds; returns true when the run is finished. */
    public function step(float $budgetSeconds): bool
    {
        $state = RunState::load();
        if ($state === null) {
            return true;
        }
        $deadline = microtime(true) + $budgetSeconds;
        while (($kind = $state->current()) !== null && microtime(true) < $deadline) {
            $this->lock->refresh($state->id);
            try {
                $done = $kind === Kind::Shop ? $this->shop($state) : $this->page($state, $kind);
            } catch (ShopifyError $e) {
                $this->log->error("Reading {$kind->value} failed: {$e->getMessage()}");
                $state->counts->add($kind, Outcome::Failed);
                $done = true;
            }
            if ($done) {
                $this->finishKind($state, $kind);
                $state->advance();
            }
            $state->save();
        }
        if ($state->current() !== null) {
            return false;
        }
        $this->finish($state);

        return true;
    }

    /** Whether a run is in progress (its state exists). */
    public function isRunning(): bool
    {
        return RunState::load() !== null;
    }

    private function shop(RunState $state): bool
    {
        $state->counts->add(Kind::Shop, $this->sync->sync(Kind::Shop, $this->reader->shop(), $state->dryRun));

        return true;
    }

    /** Mirrors one page of a kind; returns true when the kind has no more pages. */
    private function page(RunState $state, Kind $kind): bool
    {
        $source = SourceRegistry::for($kind);
        $since = $state->mode === Mode::Incremental && $source->isIncremental() ? $this->cursors->since($kind) : null;
        $page = $this->reader->page($source, $state->cursor, SearchQuery::build($since, $this->settings->filter($kind)));
        foreach ($page->nodes as $node) {
            $state->counts->add($kind, $this->sync->sync($kind, $node, $state->dryRun));
            if ($state->mode === Mode::Full) {
                $this->seen->mark($state->id, $kind, $node->id());
            }
        }
        $state->cursor = $page->endCursor;

        return !$page->hasNextPage;
    }

    private function finishKind(RunState $state, Kind $kind): void
    {
        if ($state->counts->isIncomplete($kind)) {
            $this->log->warning("{$kind->value} did not complete cleanly; it is retried next run.");

            return;
        }
        if ($state->mode === Mode::Full && $kind !== Kind::Shop) {
            if ($this->settings->filter($kind) !== '') {
                $this->log->info("{$kind->value} is filtered, so deletions aren't inferred from this full run.");
            } else {
                foreach ($this->links->unseen($kind, $state->id) as $link) {
                    $state->counts->add($kind, $this->sync->retire($kind, $link->gid, $state->dryRun));
                }
            }
        }
        if (!$state->dryRun) {
            $this->cursors->advance($kind, $state->startedAt);
        }
    }

    private function finish(RunState $state): void
    {
        $summary = $state->counts->summary();
        $status = $this->hasFailures($state) ? 'incomplete' : 'complete';
        $this->runLog->finish($state->id, $status, $state->counts->toArray(), $summary);
        $this->seen->clear($state->id);
        RunState::clear();
        $this->lock->release($state->id);
        $this->log->info("Run {$state->id} {$status}:\n{$summary}");
        if ($this->settings->notifyEmail !== '') {
            wp_mail($this->settings->notifyEmail, "Shopify import {$status}", $summary);
        }
    }

    private function hasFailures(RunState $state): bool
    {
        foreach ($state->kinds as $kind) {
            if ($state->counts->isIncomplete($kind)) {
                return true;
            }
        }

        return false;
    }
}
