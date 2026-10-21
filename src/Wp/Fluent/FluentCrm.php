<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Fluent;

use FluentCrm\App\Models\Company;
use FluentCrm\App\Models\CompanyNote;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Model;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\SubscriberNote;
use FluentCrm\App\Models\Tag;
use FluentCrm\Framework\Database\Orm\Relations\BelongsToMany;
use FluentCrmMigrations\CompaniesMigrator;
use IdempotentImporter\Wp\Dates;
use wpdb;

/**
 * The importer's typed gateway to FluentCRM (free, GPL; fluentcrm.com), the WordPress home of CRM data: its
 * models (contacts, companies, lists, tags, notes and their meta), written directly so that mirroring history
 * fires none of FluentCRM's contact, list or tag events. Those events start automations and email sequences,
 * which must never run for an import (the same rule as Quiet: no email). Only FluentCRM's documented models
 * are used; nothing is written to its tables by hand.
 */
final class FluentCrm
{
    /** How FluentCRM's pivot names each kind of membership (the related model's class). */
    public const LIST_TYPE = Lists::class;
    public const TAG_TYPE = Tag::class;
    public const COMPANY_TYPE = Company::class;
    private const COMPANIES_TABLE = 'fc_companies';
    private const COMPANIES_MIGRATION = 'database/migrations/CompaniesMigrator.php';

    /** FluentCRM's timestamps are site-local `Y-m-d H:i:s` (fluentCrmTimestamp). */
    public static function localTime(string $iso): string
    {
        return Dates::local($iso);
    }

    /** @throws FluentCrmMissing when FluentCRM isn't active, so nothing can be written */
    public static function require(): void
    {
        if (!defined('FLUENTCRM') || !class_exists(Subscriber::class)) {
            throw new FluentCrmMissing('FluentCRM is not active: install and activate FluentCRM (free, wordpress.org/plugins/fluent-crm) to mirror CRM data.');
        }
    }

    /**
     * Makes sure FluentCRM's companies table exists. FluentCRM creates it only when its Companies module is
     * switched on (Settings → Experimental Features), with its own migration; the same migration is run here, so
     * companies are kept whether or not the module shows them yet.
     */
    public static function requireCompanies(): void
    {
        self::require();
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            throw new FluentCrmMissing('WordPress has no database connection.');
        }
        $table = $wpdb->prefix . self::COMPANIES_TABLE;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
            return;
        }
        $path = defined('FLUENTCRM_PLUGIN_PATH') ? constant('FLUENTCRM_PLUGIN_PATH') : null;
        if (!is_string($path)) {
            throw new FluentCrmMissing('FluentCRM does not say where it is installed.');
        }
        // FluentCRM's migrations aren't autoloaded; FluentCRM loads this one the same way when the module is switched on.
        require_once $path . self::COMPANIES_MIGRATION;
        CompaniesMigrator::migrate();
    }

    public static function contact(int $id): ?Subscriber
    {
        $found = (new Subscriber())->newQuery()->find($id);

        return $found instanceof Subscriber ? $found : null;
    }

    public static function contactByEmail(string $email): ?Subscriber
    {
        $found = (new Subscriber())->newQuery()->where('email', $email)->first();

        return $found instanceof Subscriber ? $found : null;
    }

    public static function company(int $id): ?Company
    {
        $found = (new Company())->newQuery()->find($id);

        return $found instanceof Company ? $found : null;
    }

    public static function list(int $id): ?Lists
    {
        $found = (new Lists())->newQuery()->find($id);

        return $found instanceof Lists ? $found : null;
    }

    public static function tag(int $id): ?Tag
    {
        $found = (new Tag())->newQuery()->find($id);

        return $found instanceof Tag ? $found : null;
    }

    /** A contact's note, or a company's (FluentCRM keeps both in one table; a company note's status says so). */
    public static function note(int $id, bool $ofCompany): SubscriberNote|CompanyNote|null
    {
        $found = $ofCompany ? (new CompanyNote())->newQuery()->find($id) : (new SubscriberNote())->newQuery()->find($id);

        return $found instanceof SubscriberNote || $found instanceof CompanyNote ? $found : null;
    }

    public static function id(Model $model): int
    {
        $id = $model->getKey();

        return is_numeric($id) ? (int) $id : 0;
    }

    public static function string(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Sets the attributes that differ and saves when anything did, so an unchanged object is never re-saved
     * (FluentCRM would stamp a new updated_at).
     *
     * @param array<string, scalar|null> $attributes
     */
    public static function fill(Model $model, array $attributes): void
    {
        $changes = [];
        foreach ($attributes as $name => $value) {
            $current = $model->getAttribute($name);
            if ((is_scalar($current) ? (string) $current : null) !== ($value === null ? null : (string) $value)) {
                $changes[$name] = $value;
            }
        }
        if ($changes !== [] || self::id($model) === 0) {
            $model->fill($changes);
            $model->save();
        }
    }

    /**
     * A slug for a list or tag no other one of its kind has (FluentCRM finds lists and tags by slug): the title's,
     * else the title's with the mirrored object's own suffix.
     *
     * @param class-string<Lists|Tag> $modelClass
     */
    public static function uniqueSlug(string $modelClass, string $title, string $suffix, int $ownId): string
    {
        $base = sanitize_title($title) !== '' ? sanitize_title($title) : $suffix;
        foreach ([$base, "{$base}-{$suffix}"] as $slug) {
            $holder = (new $modelClass())->newQuery()->where('slug', $slug)->first();
            if (!$holder instanceof Model || self::id($holder) === $ownId) {
                return $slug;
            }
        }

        return "{$base}-{$suffix}";
    }

    /**
     * Makes a contact's memberships of one kind (lists, tags or companies) those the source gives, among the ones
     * this importer manages: memberships of anything else (a list made in WordPress) are left alone.
     *
     * @param class-string<Lists|Tag|Company> $objectType
     * @param list<int> $desired
     * @param list<int> $managed every id of that kind the importer mirrors
     */
    public static function syncMemberships(Subscriber $contact, string $objectType, array $desired, array $managed): void
    {
        $relation = self::memberships($contact, $objectType);
        $current = self::memberIds($contact, $objectType);
        $add = array_values(array_diff($desired, $current));
        $remove = array_values(array_intersect(array_diff($current, $desired), $managed));
        foreach ($add as $id) {
            $relation->attach($id, ['object_type' => $objectType]);
        }
        if ($remove !== []) {
            $relation->detach($remove);
        }
    }

    /**
     * The ids of what a contact belongs to of one kind (its lists, tags or companies).
     *
     * @param class-string<Lists|Tag|Company> $objectType
     *
     * @return list<int>
     */
    public static function memberIds(Subscriber $contact, string $objectType): array
    {
        return array_values(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, self::memberships($contact, $objectType)->allRelatedIds()->all()));
    }

    /**
     * @param class-string<Lists|Tag|Company> $objectType
     *
     * @return BelongsToMany<Lists|Tag|Company, Subscriber>
     */
    private static function memberships(Subscriber $contact, string $objectType): BelongsToMany
    {
        $relation = match ($objectType) {
            self::LIST_TYPE => $contact->lists(),
            self::TAG_TYPE => $contact->tags(),
            default => $contact->companies(),
        };

        return $relation instanceof BelongsToMany ? $relation : throw new FluentCrmMissing("FluentCRM contacts have no {$objectType} relation.");
    }
}
