<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Mapping\PlaceholderEmail;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Services;
use IdempotentImporter\Source\Shopify\Mapping\ReviewCsv;
use IdempotentImporter\Source\Shopify\ShopifyAdapter;
use IdempotentImporter\Source\Shopify\WebhookTopics;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Store\RunRecord;
use IdempotentImporter\Sync\Mode;
use IdempotentImporter\Sync\RunCounts;
use InvalidArgumentException;
use WP_CLI;
use WP_User;

/**
 * Mirrors a store (Shopify, Etsy, …) into WooCommerce, idempotently.
 *
 * ## EXAMPLES
 *
 *     wp idempotent-import run
 *     wp idempotent-import run --full --dry-run
 *     wp idempotent-import run --kinds=product,order
 */
final class Cli
{
    private const STEP_BUDGET_SECONDS = 60.0;

    public function __construct(private readonly Services $services, private readonly Scheduler $scheduler) {}

    /**
     * Runs an import in the foreground and prints what it did.
     *
     * ## OPTIONS
     *
     * [--full]
     * : Read everything and retire copies of objects the source no longer has.
     *
     * [--dry-run]
     * : Report what would be created, updated or retired without writing anything.
     *
     * [--kinds=<kinds>]
     * : Comma-separated kinds to run (default: every enabled kind).
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        self::reporting(fn() => $this->runImport($assoc));
    }

    /** @param array<string, string|bool> $assoc */
    private function runImport(array $assoc): void
    {
        $mode = isset($assoc['full']) ? Mode::Full : Mode::Incremental;
        $kinds = isset($assoc['kinds']) && is_string($assoc['kinds']) ? self::kinds($assoc['kinds']) : null;
        $this->services->credentialHold()->release();
        $runner = $this->services->runner();
        $state = $runner->start($mode, isset($assoc['dry-run']), $kinds);
        if ($state === null) {
            WP_CLI::error('Another import is running.');

            return;
        }
        while (!$runner->step(self::STEP_BUDGET_SECONDS)) {
            $resumeAt = $runner->resumeAt();
            if ($resumeAt === null) {
                WP_CLI::log('…');
                continue;
            }
            WP_CLI::log('The source is pushing back; pausing until ' . gmdate('H:i:s', $resumeAt) . ' UTC (see the log).');
            sleep(max(0, $resumeAt - time()));
        }
        foreach ($this->services->runLog()->recent(1) as $latest) {
            WP_CLI::log($latest->message);
            WP_CLI::success("Run {$state->id} {$latest->status}.");
        }
    }

    /**
     * Shows the running import (if any), the recent runs and the incremental cursors.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $holder = $this->services->lock()->holder();
        WP_CLI::log($holder === null ? 'No import is running.' : "Import {$holder} is running.");
        $hold = $this->services->credentialHold()->reason($this->services->settings()->source);
        if ($hold !== null) {
            WP_CLI::warning("Scheduled runs are held: the source rejected the credentials — {$hold}.");
        }
        $rows = array_map(static fn(RunRecord $run): array => [
            'started' => $run->startedAt,
            'mode' => $run->label(),
            'status' => $run->status,
            'writes' => $run->counts->writes(),
        ], $this->services->runLog()->recent());
        WP_CLI\Utils\format_items('table', $rows, ['started', 'mode', 'status', 'writes']);
    }

    /**
     * Re-reads one object from the source and mirrors it now.
     *
     * ## OPTIONS
     *
     * <kind>
     * : The kind, e.g. product.
     *
     * <gid>
     * : Its identity, e.g. gid://shopify/Product/123 or gid://etsy/Listing/123.
     *
     * @param list<string> $args
     */
    public function sync(array $args): void
    {
        $kind = Kind::tryFrom($args[0] ?? '');
        $gid = $args[1] ?? '';
        if ($kind === null || $gid === '') {
            WP_CLI::error('Give a known kind and a GID.');

            return;
        }
        $this->services->credentialHold()->release();
        self::reporting(fn() => $this->scheduler->syncObject($kind->value, $gid, false));
        WP_CLI::success("Synced {$gid}.");
    }

    /**
     * Forgets the incremental cursors, so the next run reads everything.
     */
    public function reset_cursors(): void
    {
        $this->services->cursors()->reset();
        WP_CLI::success('Cursors reset.');
    }

    /**
     * Imports product reviews from a review app's CSV export (Judge.me or Shopify Product Reviews).
     *
     * ## OPTIONS
     *
     * <file>
     * : The CSV file.
     *
     * [--dry-run]
     * : Report without writing.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function reviews(array $args, array $assoc): void
    {
        self::reporting(fn() => $this->importReviews($args, $assoc));
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    private function importReviews(array $args, array $assoc): void
    {
        $this->shopify();
        $file = $args[0] ?? '';
        $csv = is_readable($file) ? file_get_contents($file) : false;
        if ($csv === false) {
            WP_CLI::error('Cannot read the CSV file.');

            return;
        }
        $counts = new RunCounts();
        foreach (ReviewCsv::parse($csv) as $row) {
            $counts->add(Kind::Review, $this->services->objectSync()->sync(Kind::Review, $row, isset($assoc['dry-run'])));
        }
        WP_CLI::log($counts->summary());
        WP_CLI::success('Reviews imported.');
    }

    /**
     * Subscribes this site to the Shopify webhooks the importer uses (one subscription per topic).
     */
    public function register_webhooks(): void
    {
        self::reporting($this->registerWebhooks(...));
    }

    private function registerWebhooks(): void
    {
        $client = $this->shopify()->client($this->services->settings(), $this->services->transport());
        $uri = WebhookController::url();
        foreach (WebhookTopics::topics() as $topic) {
            $result = $client->query('WebhookSubscriptionCreate', ['topic' => $topic, 'uri' => $uri])->requiredChild('webhookSubscriptionCreate');
            $errors = $result->list('userErrors');
            if ($errors === []) {
                WP_CLI::log("Subscribed {$topic}.");
            } else {
                WP_CLI::warning("{$topic}: " . $errors[0]->string('message'));
            }
        }
        WP_CLI::success("Webhooks point at {$uri}.");
    }

    /**
     * Emails every mirrored customer a password-reset link (Shopify passwords can't be migrated), except those
     * mirrored with a placeholder address.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Send without asking.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function send_password_resets(array $args, array $assoc): void
    {
        $links = $this->services->links()->all(Kind::Customer);
        WP_CLI::confirm('Email a password-reset link to ' . count($links) . ' customers?', $assoc);
        $sent = 0;
        foreach ($links as $link) {
            $user = get_user_by('id', $link->targetId);
            // A placeholder address (a source person with no email) can't receive the link; its mail would be dropped.
            if ($user instanceof WP_User && $user->user_email !== '' && !PlaceholderEmail::isPlaceholder($user->user_email) && retrieve_password($user->user_login) === true) {
                ++$sent;
            }
        }
        WP_CLI::success("Sent {$sent} reset emails.");
    }

    /**
     * Retires every mirrored object of the given kinds (trash where WordPress has one) and forgets them.
     *
     * ## OPTIONS
     *
     * --kinds=<kinds>
     * : Comma-separated kinds.
     *
     * [--yes]
     * : Clear without asking.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function clear(array $args, array $assoc): void
    {
        self::reporting(fn() => $this->clearKinds($assoc));
    }

    /** @param array<string, string|bool> $assoc */
    private function clearKinds(array $assoc): void
    {
        $kinds = self::kinds(is_string($assoc['kinds'] ?? null) ? $assoc['kinds'] : '');
        WP_CLI::confirm('Retire every mirrored ' . implode(', ', array_map(static fn(Kind $kind): string => $kind->value, $kinds)) . '?', $assoc);
        $counts = new RunCounts();
        foreach ($kinds as $kind) {
            foreach ($this->services->links()->all($kind) as $link) {
                $counts->add($kind, $this->services->objectSync()->retire($kind, $link->gid, false));
            }
        }
        WP_CLI::log($counts->summary());
        WP_CLI::success('Cleared.');
    }

    /** The active adapter, for commands that only exist for Shopify. */
    private function shopify(): ShopifyAdapter
    {
        $adapter = $this->services->adapter();
        if (!$adapter instanceof ShopifyAdapter) {
            throw new SourceError("This command is for Shopify; the active source is {$adapter->label()}.");
        }

        return $adapter;
    }

    /** Configuration, source and argument problems end the command with their message, not a fatal. */
    private static function reporting(callable $command): void
    {
        try {
            $command();
        } catch (SourceError|InvalidArgumentException $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /** @return list<Kind> */
    private static function kinds(string $list): array
    {
        $kinds = [];
        foreach (array_filter(array_map(trim(...), explode(',', $list))) as $name) {
            $kind = Kind::tryFrom($name);
            if ($kind === null) {
                throw new InvalidArgumentException("Unknown kind {$name}.");
            }
            $kinds[] = $kind;
        }

        return $kinds;
    }
}
