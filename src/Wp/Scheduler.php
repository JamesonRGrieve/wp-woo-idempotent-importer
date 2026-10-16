<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentShopifyImporter\Wp;

use IdempotentShopifyImporter\Model\Kind;
use IdempotentShopifyImporter\Services;
use IdempotentShopifyImporter\Shopify\ShopifyError;
use IdempotentShopifyImporter\Shopify\SourceRegistry;
use IdempotentShopifyImporter\Sync\Mode;

/**
 * Background work on Action Scheduler (bundled with WooCommerce): the recurring run, the steps that
 * carry a run forward, and one-object syncs from webhooks. A webhook job that finds a run in progress
 * waits for it rather than writing alongside it.
 */
final class Scheduler
{
    public const GROUP = 'shopify-import';
    public const RECURRING_HOOK = 'isi_scheduled_run';
    public const STEP_HOOK = 'isi_run_step';
    public const OBJECT_HOOK = 'isi_sync_object';
    public const LAST_FULL_OPTION = 'isi_last_full_run';
    public const INTERVAL_OPTION = 'isi_scheduled_interval';
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
        $last = Options::int(self::LAST_FULL_OPTION);
        $mode = time() - $last >= $this->services->settings()->fullReconcileHours * self::SECONDS_PER_HOUR ? Mode::Full : Mode::Incremental;
        $this->startInBackground($mode, false);
    }

    /** @return bool false when a run is already in progress */
    public function startInBackground(Mode $mode, bool $dryRun): bool
    {
        try {
            $state = $this->services->runner()->start($mode, $dryRun);
        } catch (ShopifyError $e) {
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

    public function step(): void
    {
        if (!$this->services->runner()->step(self::STEP_BUDGET_SECONDS)) {
            as_enqueue_async_action(self::STEP_HOOK, [], self::GROUP);
        }
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
        if ($kind === null || !$this->services->settings()->syncs($kind)) {
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
        $reader = $this->services->reader();
        if ($kind === Kind::Shop) {
            $sync->sync($kind, $reader->shop(), false);

            return;
        }
        if (str_contains($gid, '/InventoryItem/')) {
            $gid = $reader->inventoryItemProduct($gid) ?? throw new ShopifyError("No product for {$gid}.");
        }
        $node = $reader->node(SourceRegistry::for($kind), $gid);
        if ($node === null) {
            $sync->retire($kind, $gid, false);
        } else {
            $sync->sync($kind, $node, false);
        }
    }
}
