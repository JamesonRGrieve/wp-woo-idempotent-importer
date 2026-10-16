<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Shopify\Decimal;
use IdempotentImporter\Shopify\ShopifyNode;

/**
 * Delivery profile → WooCommerce shipping zones. A flat price becomes flat rate; a zero price becomes
 * free shipping, with a minimum order amount when Shopify sets one. Every other rate (weight or price
 * bands, carrier-calculated) is reported, not approximated.
 */
final class DeliveryProfileMapper implements Mapper
{
    private const VERSION = 1;
    private const RATE_DEFINITION = 'DeliveryRateDefinition';
    private const PRICE_FIELD = 'TOTAL_PRICE';
    private const AT_LEAST = 'GREATER_THAN_OR_EQUAL_TO';

    public function kind(): Kind
    {
        return Kind::DeliveryProfile;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(ShopifyNode $node): Desired
    {
        if (!$node->bool('default')) {
            throw new Unsupported("Delivery profile \"{$node->string('name')}\" applies to specific products; WooCommerce zones are store-wide (needs shipping classes or a table-rate plugin).");
        }
        $zones = [];
        $warnings = [];
        foreach ($node->list('profileLocationGroups') as $group) {
            foreach ($group->list('locationGroupZones') as $groupZone) {
                $zone = $groupZone->requiredChild('zone');
                $methods = [];
                foreach ($groupZone->list('methodDefinitions') as $method) {
                    $mapped = self::method($method);
                    if (is_string($mapped)) {
                        $warnings[] = "Shipping zone \"{$zone->string('name')}\": {$mapped}";
                    } else {
                        $methods[] = $mapped;
                    }
                }
                $zones[] = ['gid' => $zone->id(), 'name' => $zone->string('name'), ...self::locations($zone), 'methods' => $methods];
            }
        }

        return new Desired(Kind::DeliveryProfile, $node->id(), ['name' => $node->string('name'), 'zones' => $zones], [], $warnings);
    }

    /** @return array{rest_of_world: bool, locations: list<string>} WooCommerce zone locations: "CA" or "CA:AB" */
    private static function locations(ShopifyNode $zone): array
    {
        $restOfWorld = false;
        $locations = [];
        foreach ($zone->list('countries') as $country) {
            $code = $country->requiredChild('code');
            if ($code->bool('restOfWorld')) {
                $restOfWorld = true;
                continue;
            }
            $countryCode = $code->string('countryCode');
            $provinces = $country->list('provinces');
            if ($provinces === []) {
                $locations[] = $countryCode;
            }
            foreach ($provinces as $province) {
                $locations[] = $countryCode . ':' . $province->string('code');
            }
        }

        return ['rest_of_world' => $restOfWorld, 'locations' => $locations];
    }

    /** @return array<string, mixed>|string the method, or why it can't be mirrored */
    private static function method(ShopifyNode $method): array|string
    {
        $name = $method->string('name');
        $rate = $method->requiredChild('rateProvider');
        if ($rate->typename() !== self::RATE_DEFINITION) {
            return "\"{$name}\" uses carrier-calculated rates.";
        }
        $price = $rate->requiredChild('price')->decimal('amount');
        $conditions = $method->list('methodConditions');
        $minimum = null;
        foreach ($conditions as $condition) {
            $criteria = $condition->requiredChild('conditionCriteria');
            if ($condition->string('field') !== self::PRICE_FIELD || $condition->string('operator') !== self::AT_LEAST || !Decimal::isZero($price)) {
                return "\"{$name}\" has a weight or price band, which needs a table-rate plugin.";
            }
            $minimum = $criteria->decimal('amount');
        }

        return [
            'gid' => $method->id(),
            'name' => $name,
            'description' => $method->optionalString('description'),
            'enabled' => $method->bool('active'),
            'type' => Decimal::isZero($price) ? 'free_shipping' : 'flat_rate',
            'cost' => $price,
            'minimum_amount' => $minimum,
        ];
    }
}
