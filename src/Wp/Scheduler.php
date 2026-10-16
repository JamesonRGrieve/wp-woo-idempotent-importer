<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Services;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Sync\Mode;

/**
 * Background work on Action Scheduler (bundled with WooCommerce): the recurring run, the steps that
 * carry a run forward, and one-object syncs from webhooks. A webhook job that finds a run in progress
 * waits for it rather than writing alongside it; one whose source is pushing back is postponed. While the
 * source's credentials are on hold (Sync\CredentialHold), scheduled runs and webhook syncs don't call it.
 */
final class Scheduler
{
    public const GROUP = 'idempotent-import';
    public const RECURRING_HOOK = 'iim_scheduled_run';
    public const STEP_HOOK = 'iim_run_step';
    public const OBJECT_HOOK = 'iim_sync_object';
    public const LAST_FULL_OPTION = 'iim_last_full_run';
    public const INTERVAL_OPTION = 'iim_scheduled_interval';
    public const STEP_BUDGET_SECONDS = 20.0;
    public const LOCKED_RETRY_SECONDS = 60;
    private const SECONDS_PER_MINUTE = 60;
    private const SECONDS_PER_HOUR = 3600;

    public function __construct(private readonly Services $services) {}

    public function register(): void
    {
        add_action(self::RECURRING_HOOK, $this->scheduledRun(...));
        add_action(self::STEP_HOOK, $this->step(...));
        add_action(self::OBJECT_HOOK, $this->syncObject(...), 10, 3);
        add_action('action_scheduler_init', $this->ensureSchedule(...));
    }

    /** Keeps exactly one recurring run at the configured interval. */
    public function ensureSchedule(): void
    {
        $interval = $this->services->settings()->intervalMinutes * self::SECONDS_PER_MINUTE;
        if (as_has_scheduled_action(self::RECURRING_HOOK, [], self::GROUP) && Options::int(self::INTERVAL_OPTION) === $interval) {
            return;
        }
        as_unschedule_all_actions(self::RECURRING_HOOK, [], self::GROUP);
        as_schedule_recurring_action(time() + $interval, $interval, self::RECURRING_HOOK, [], self::GROUP, true);
        update_option(self::INTERVAL_OPTION, $interval, false);
    }

    public static function unscheduleAll(): void
    {
        foreach ([self::RECURRING_HOOK, self::STEP_HOOK, self::OBJECT_HOOK] as $hook) {
            as_unschedule_all_actions($hook, [], self::GROUP);
        }
    }

    /** Starts a run in the background: full when the last full run is older than the reconcile interval. */
    public function scheduledRun(): void
    {
        if ($this->held('Scheduled run')) {
            return;
        }
        $last = Options::int(self::LAST_FULL_OPTION);
        $mode = time() - $last >= $this->services->settings()->fullReconcileHours * self::SECONDS_PER_HOUR ? Mode::Full : Mode::Incremental;
        $this->startInBackground($mode, false);
    }

    /** @return bool false when a run is already in progress */
    public function startInBackground(Mode $mode, bool $dryRun): bool
    {
        try {
            $state = $this->services->runner()->start($mode, $dryRun);
        } catch (SourceError $e) {
            $this->services->log()->error("Run not started: {$e->getMessage()}");

            return false;
        }
        if ($state === null) {
            return false;
        }
        if ($mode === Mode::Full && !$dryRun) {
            update_option(self::LAST_FULL_OPTION, time(), false);
        }
        as_enqueue_async_action(self::STEP_HOOK, [], self::GROUP);

        return true;
    }

    /** Carries the run forward; a run pausing for its source is picked up again when its pause ends. */
    public function step(): void
    {
        $runner = $this->services->runner();
        if ($runner->step(self::STEP_BUDGET_SECONDS)) {
            return;
        }
        $resumeAt = $runner->resumeAt();
        if ($resumeAt !== null) {
            as_schedule_single_action($resumeAt, self::STEP_HOOK, [], self::GROUP);
        } else {
            as_enqueue_async_action(self::STEP_HOOK, [], self::GROUP);
        }
    }

    /** Whether the source's credentials were rejected, so scheduled work must not retry them (logged once per attempt). */
    private function held(string $what): bool
    {
        $reason = $this->services->credentialHold()->reason($this->services->settings()->source);
        if ($reason !== null) {
            $this->services->log()->warning("{$what} skipped: the source rejected the credentials — {$reason}. Save the settings or start a run by hand to try again.");
        }

        return $reason !== null;
    }

    public function enqueueObject(Kind $kind, string $gid, bool $deletion): void
    {
        $args = [$kind->value, $gid, $deletion];
        if (!as_has_scheduled_action(self::OBJECT_HOOK, $args, self::GROUP)) {
            as_enqueue_async_action(self::OBJECT_HOOK, $args, self::GROUP);
        }
    }

    public function syncObject(string $kindValue, string $gid, bool $deletion): void
    {
        $kind = Kind::tryFrom($kindValue);
        if ($kind === null || !$this->services->settings()->syncs($kind) || $this->held("Syncing {$gid}")) {
            return;
        }
        if ($this->services->lock()->holder() !== null) {
            as_schedule_single_action(time() + self::LOCKED_RETRY_SECONDS, self::OBJECT_HOOK, [$kindValue, $gid, $deletion], self::GROUP);

            return;
        }
        $sync = $this->services->objectSync();
        if ($deletion) {
            $sync->retire($kind, $gid, false);

            return;
        }
        try {
            $this->syncFromSource($kind, $gid);
        } catch (RetryLater $e) {
            $this->services->log()->warning("Syncing {$gid} postponed: {$e->getMessage()}");
            as_schedule_single_action(time() + (int) ceil(max($e->seconds, self::LOCKED_RETRY_SECONDS)), self::OBJECT_HOOK, [$kindValue, $gid, $deletion], self::GROUP);
        } catch (CredentialsRejected $e) {
            $this->services->log()->error("Syncing {$gid} stopped: {$e->getMessage()} Scheduled work is held until the settings are saved or a run is started by hand.");
            $this->services->credentialHold()->hold($this->services->settings()->source, $e->getMessage());
        }
    }

    private function syncFromSource(Kind $kind, string $gid): void
    {
        $sync = $this->services->objectSync();
        $reader = $this->services->reader();
        if ($kind === Kind::Shop) {
            $sync->sync($kind, $reader->shop(), false);

            return;
        }
        $node = $reader->node($kind, $gid);
        if ($node === null) {
            $sync->retire($kind, $gid, false);
        } else {
            $sync->sync($kind, $node, false);
        }
    }
}
