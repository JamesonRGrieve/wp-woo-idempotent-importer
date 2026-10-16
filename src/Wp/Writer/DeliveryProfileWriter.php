<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Options;
use WC_Shipping_Zone;
use wpdb;

/**
 * The default delivery profile → WooCommerce shipping zones. Rest-of-world rates go to WooCommerce's
 * "locations not covered" zone. Only methods this writer created are ever replaced or removed; methods
 * an operator added by hand stay.
 */
final class DeliveryProfileWriter implements Writer
{
    public const OWNED_METHODS_OPTION = 'iim_owned_shipping_methods';
    private const REST_OF_WORLD_ZONE = 0;
    private const ZONE_SEPARATOR = '#';
    private const NO_OBJECT = 0;

    public function __construct(private readonly Targets $targets, private readonly wpdb $db) {}

    public function kind(): Kind
    {
        return Kind::DeliveryProfile;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $kept = [];
        foreach ($f->records('zones') as $zoneValues) {
            $key = $desired->gid . self::ZONE_SEPARATOR . $zoneValues->string('gid');
            if ($zoneValues->bool('rest_of_world') && $zoneValues->strings('locations') === []) {
                $zone = new WC_Shipping_Zone(self::REST_OF_WORLD_ZONE);
                $this->targets->links->save(Kind::ShippingZone, $key, self::REST_OF_WORLD_ZONE);
            } else {
                $zone = $this->zone($key, $zoneValues);
            }
            $this->replaceMethods($key, $zone, $zoneValues->records('methods'));
            $kept[] = $key;
        }
        foreach ($this->targets->links->withPrefix(Kind::ShippingZone, $desired->gid . self::ZONE_SEPARATOR) as $zoneLink) {
            if (!in_array($zoneLink->gid, $kept, true)) {
                $this->replaceMethods($zoneLink->gid, new WC_Shipping_Zone($zoneLink->targetId), []);
            }
        }

        return self::NO_OBJECT;
    }

    public function exists(int $targetId): bool
    {
        return true;
    }

    public function retire(Link $link): void
    {
        foreach ($this->targets->links->withPrefix(Kind::ShippingZone, $link->gid . self::ZONE_SEPARATOR) as $zoneLink) {
            $this->replaceMethods($zoneLink->gid, new WC_Shipping_Zone($zoneLink->targetId), []);
        }
    }

    private function zone(string $key, Values $zoneValues): WC_Shipping_Zone
    {
        $zone = new WC_Shipping_Zone($this->targets->links->targetId(Kind::ShippingZone, $key) ?? null);
        $zone->set_zone_name($zoneValues->string('name'));
        $zone->clear_locations(['country', 'state']);
        foreach ($zoneValues->strings('locations') as $location) {
            $zone->add_location($location, str_contains($location, ':') ? 'state' : 'country');
        }
        $id = $zone->save();
        $this->targets->links->save(Kind::ShippingZone, $key, $id);

        return $zone;
    }

    /** @param list<Values> $methods */
    private function replaceMethods(string $key, WC_Shipping_Zone $zone, array $methods): void
    {
        $owned = self::ownedMethods();
        foreach ($owned[$key] ?? [] as $instanceId) {
            $zone->delete_shipping_method($instanceId);
        }
        $owned[$key] = [];
        foreach ($methods as $method) {
            $type = $method->string('type');
            $instanceId = $zone->add_shipping_method($type);
            update_option("woocommerce_{$type}_{$instanceId}_settings", array_filter([
                'title' => $method->string('name'),
                'cost' => $type === 'flat_rate' ? $method->string('cost') : null,
                'tax_status' => $type === 'flat_rate' ? 'taxable' : null,
                'requires' => $type === 'free_shipping' && $method->nullableString('minimum_amount') !== null ? 'min_amount' : null,
                'min_amount' => $type === 'free_shipping' ? $method->nullableString('minimum_amount') : null,
            ], static fn(?string $value): bool => $value !== null));
            $this->db->update(
                $this->db->prefix . 'woocommerce_shipping_zone_methods',
                ['is_enabled' => $method->bool('enabled') ? 1 : 0],
                ['instance_id' => $instanceId],
                ['%d'],
                ['%d'],
            );
            $owned[$key][] = $instanceId;
        }
        update_option(self::OWNED_METHODS_OPTION, $owned, false);
    }

    /** @return array<string, list<int>> zone key => shipping method instance ids this writer created */
    private static function ownedMethods(): array
    {
        $owned = [];
        foreach (Options::array(self::OWNED_METHODS_OPTION) as $key => $ids) {
            if (is_string($key) && is_array($ids)) {
                $owned[$key] = array_values(array_filter($ids, is_int(...)));
            }
        }

        return $owned;
    }
}
