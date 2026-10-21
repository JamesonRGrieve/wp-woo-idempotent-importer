<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Config;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Adapters;
use IdempotentImporter\Wp\Options;

/**
 * Every operator setting, read once from the `iim_settings` option and validated. DEFAULTS is the single
 * definition of each setting and its default; the admin page and sanitiser are driven by it.
 */
final readonly class Settings
{
    public const OPTION = 'iim_settings';

    public const DEFAULTS = [
        'source' => Adapters::DEFAULT,
        'store_domain' => '',
        'kinds' => [
            'shop', 'file', 'collection', 'product', 'customer', 'discount', 'order', 'draft_order',
            'page', 'blog', 'article', 'comment', 'menu', 'url_redirect', 'review',
        ],
        'filters' => [],
        'interval_minutes' => 60,
        'full_reconcile_hours' => 24,
        'store_settings' => false,
        'shipping_zones' => false,
        'redirects' => true,
        'localise_images' => true,
        'clean_html' => false,
        'preserve_order_numbers' => true,
        'generate_missing_skus' => false,
        'default_author' => 0,
        'notify_email' => '',
        'gift_card_load_increases' => false,
        'gift_card_new_numbers' => true,
    ];

    public const MIN_INTERVAL_MINUTES = 5;

    /**
     * @param list<Kind> $kinds
     * @param array<string, string> $filters the source's own filter syntax, per kind value
     */
    public function __construct(
        public string $source,
        public string $storeDomain,
        public array $kinds,
        public array $filters,
        public int $intervalMinutes,
        public int $fullReconcileHours,
        public bool $storeSettings,
        public bool $shippingZones,
        public bool $redirects,
        public bool $localiseImages,
        public bool $cleanHtml,
        public bool $preserveOrderNumbers,
        public bool $generateMissingSkus,
        public int $defaultAuthor,
        public string $notifyEmail,
        public bool $giftCardLoadIncreases = false,
        public bool $giftCardNewNumbers = true,
    ) {}

    public static function load(): self
    {
        return self::fromArray(Options::array(self::OPTION));
    }

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $value = static fn(string $key): mixed => $raw[$key] ?? self::DEFAULTS[$key];
        $kinds = [];
        foreach ((array) $value('kinds') as $kind) {
            $parsed = is_string($kind) ? Kind::tryFrom($kind) : null;
            if ($parsed !== null) {
                $kinds[] = $parsed;
            }
        }
        // Returns are refunds on mirrored orders: they follow the orders setting.
        if (in_array(Kind::Order, $kinds, true)) {
            $kinds[] = Kind::Return;
        }
        if ((bool) $value('shipping_zones')) {
            $kinds[] = Kind::DeliveryProfile;
        }
        $filters = [];
        foreach ((array) $value('filters') as $kind => $filter) {
            if (is_string($kind) && is_string($filter) && trim($filter) !== '' && Kind::tryFrom($kind) !== null) {
                $filters[$kind] = trim($filter);
            }
        }

        $source = self::string($value('source'));

        return new self(
            source: Adapters::exists($source) ? $source : Adapters::DEFAULT,
            storeDomain: strtolower(trim(self::string($value('store_domain')))),
            kinds: array_values(array_filter(Kind::SYNC_ORDER, static fn(Kind $kind): bool => in_array($kind, $kinds, true))),
            filters: $filters,
            intervalMinutes: max(self::MIN_INTERVAL_MINUTES, self::int($value('interval_minutes'))),
            fullReconcileHours: max(1, self::int($value('full_reconcile_hours'))),
            storeSettings: (bool) $value('store_settings'),
            shippingZones: (bool) $value('shipping_zones'),
            redirects: (bool) $value('redirects'),
            localiseImages: (bool) $value('localise_images'),
            cleanHtml: (bool) $value('clean_html'),
            preserveOrderNumbers: (bool) $value('preserve_order_numbers'),
            generateMissingSkus: (bool) $value('generate_missing_skus'),
            defaultAuthor: self::int($value('default_author')),
            notifyEmail: sanitize_email(self::string($value('notify_email'))),
            giftCardLoadIncreases: (bool) $value('gift_card_load_increases'),
            giftCardNewNumbers: (bool) $value('gift_card_new_numbers'),
        );
    }

    public function filter(Kind $kind): string
    {
        return $this->filters[$kind->value] ?? '';
    }

    public function syncs(Kind $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
