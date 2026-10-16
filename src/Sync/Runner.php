<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Sync;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapter;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\Identity;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SourceReader;
use IdempotentImporter\Store\LinkStore;
use IdempotentImporter\Store\RunLog;
use IdempotentImporter\Store\SeenStore;
use IdempotentImporter\Wp\Log;

/**
 * A run of the active source, one step at a time: each step reads and mirrors pages until its time
 * budget is spent, then persists where it got to. Kinds run in dependency order. A full run retires
 * copies of this source's objects that it no longer has, but only for a kind that completed cleanly and
 * wasn't filtered.
 *
 * Lockout safety, which matters most in the long first catch-up: when the source keeps pushing back
 * (RetryLater) the run pauses exactly where it is — same kind, same page — and resumes after PausePolicy's
 * growing pause, instead of failing the kind and carrying on against a source that is refusing requests.
 * When the source rejects the credentials (CredentialsRejected) the run stops at once and CredentialHold
 * keeps scheduled work from retrying them.
 */
final class Runner
{
    public function __construct(
        private readonly Adapter $adapter,
        private readonly SourceReader $reader,
        private readonly ObjectSync $sync,
        private readonly Settings $settings,
        private readonly LinkStore $links,
        private readonly SeenStore $seen,
        private readonly Cursors $cursors,
        private readonly RunLock $lock,
        private readonly CredentialHold $credentialHold,
        private readonly RunLog $runLog,
        private readonly Log $log,
    ) {}

    /**
     * Starts a run unless one is in progress; returns it, or null when another run holds the lock.
     *
     * @param list<Kind>|null $onlyKinds
     *
     * @phpstan-impure
     */
    public function start(Mode $mode, bool $dryRun, ?array $onlyKinds = null): ?RunState
    {
        $id = wp_generate_uuid4();
        if (!$this->lock->acquire($id)) {
            return null;
        }
        $kinds = array_values(array_filter(
            $this->settings->kinds,
            fn(Kind $kind): bool => in_array($kind, $this->adapter->kinds(), true)
                && $this->sync->handles($kind)
                && ($onlyKinds === null || in_array($kind, $onlyKinds, true)),
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
        if ($state->isPaused(time())) {
            return false;
        }
        $state->resumeAt = null;
        $deadline = microtime(true) + $budgetSeconds;
        while (($kind = $state->current()) !== null && microtime(true) < $deadline) {
            $this->lock->refresh($state->id);
            try {
                $done = $kind === Kind::Shop ? $this->shop($state) : $this->page($state, $kind);
                $state->pauses = 0;
            } catch (RetryLater $e) {
                if ($this->pause($state, $kind, $e)) {
                    return false;
                }
                $done = true;
            } catch (CredentialsRejected $e) {
                $this->stop($state, $kind, $e);

                return true;
            } catch (SourceError $e) {
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

    /**
     * Whether a run is in progress (its state exists).
     *
     * @phpstan-impure
     */
    public function isRunning(): bool
    {
        return RunState::load() !== null;
    }

    /**
     * When the run in progress resumes from a pause (Unix time), or null when it isn't paused.
     *
     * @phpstan-impure
     */
    public function resumeAt(): ?int
    {
        $state = RunState::load();

        return $state !== null && $state->isPaused(time()) ? $state->resumeAt : null;
    }

    /**
     * Pauses the run where it is (the kind and its cursor are kept) for PausePolicy's next pause; returns
     * false when the pauses are exhausted, so the kind is given up as failed instead.
     */
    private function pause(RunState $state, Kind $kind, RetryLater $e): bool
    {
        ++$state->pauses;
        if (PausePolicy::exhausted($state->pauses)) {
            $this->log->error("Reading {$kind->value} failed after {$state->pauses} pauses: {$e->getMessage()}");
            $state->counts->add($kind, Outcome::Failed);
            $state->pauses = 0;

            return false;
        }
        $seconds = PausePolicy::seconds($state->pauses, $e->seconds);
        $resumeAt = time() + $seconds;
        $state->resumeAt = $resumeAt;
        $state->save();
        $this->lock->holdUntil($state->id, $resumeAt);
        $this->log->warning("Pausing {$seconds}s before reading {$kind->value} again (pause {$state->pauses}): {$e->getMessage()}");

        return true;
    }

    /** Ends the run now: the credentials were rejected, and every further request would repeat that. */
    private function stop(RunState $state, Kind $kind, CredentialsRejected $e): void
    {
        $this->log->error("Stopping the run: {$e->getMessage()} Scheduled runs are held until the settings are saved or a run is started by hand.");
        $state->counts->add($kind, Outcome::Failed);
        $this->credentialHold->hold($this->adapter->id(), $e->getMessage());
        $this->finish($state);
    }

    private function shop(RunState $state): bool
    {
        $state->counts->add(Kind::Shop, $this->sync->sync(Kind::Shop, $this->reader->shop(), $state->dryRun));

        return true;
    }

    /** Mirrors one page of a kind; returns true when the kind has no more pages. */
    private function page(RunState $state, Kind $kind): bool
    {
        $since = $state->mode === Mode::Incremental && $this->reader->isIncremental($kind) ? $this->cursors->since($kind) : null;
        $page = $this->reader->page($kind, $state->cursor, $since, $this->settings->filter($kind));
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
                foreach ($this->links->unseen($kind, $state->id, Identity::prefix($this->adapter->id())) as $link) {
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
            wp_mail($this->settings->notifyEmail, "Store import {$status}", $summary);
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
