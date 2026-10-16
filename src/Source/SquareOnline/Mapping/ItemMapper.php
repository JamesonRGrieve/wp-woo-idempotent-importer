<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\SquareOnline\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\LegacyPaths;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\VariantAttributes;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Source\SquareOnline\SquareOnlineReader;

/**
 * A catalog item → a WooCommerce product. Each variation becomes a variant identified by its Square id (see
 * Fields::variationId); an item with one variation and no item options is a simple product. Variations are
 * told apart by their item option values or, without item options, by their names (a "Variation"
 * attribute). Price, stock tracking and sold-out state are read at the main location (its override wins);
 * stock is the main location's in-stock count. Archived items, and items not sold at the main location,
 * are kept private. A deleted item maps to nothing, so its copy is retired. Square's other item fields
 * (modifier lists, taxes, channels, location presence, per-variation settings) are mirrored as meta.
 */
final class ItemMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'square-';
    /** The attribute that tells variations apart when an item has no item options. */
    public const VARIATION_ATTRIBUTE = 'Variation';

    private const VERSION = 1;
    /** Product types WooCommerce sells as ordinary products; gift cards, appointments, events, donations and legacy Square Online services and memberships have no WooCommerce equivalent. */
    private const PRODUCT_TYPES = ['REGULAR', 'FOOD_AND_BEV', 'DIGITAL'];
    private const DEFAULT_PRODUCT_TYPE = 'REGULAR';
    private const DIGITAL = 'DIGITAL';
    private const VARIABLE_PRICING = 'VARIABLE_PRICING';
    /** A normalised path with nothing to redirect (no Square Online product URL). */
    private const NO_PATH = '/';
    /** Variation settings with no WooCommerce field, mirrored per variation. */
    private const VARIATION_DETAILS = [
        'name', 'ordinal', 'pricing_type', 'location_overrides', 'inventory_alert_type', 'inventory_alert_threshold',
        'user_data', 'sellable', 'stockable', 'stockable_conversion', 'vendor_information', 'kitchen_name',
    ];
    /** Item fields with no WooCommerce field, mirrored as they are. */
    private const ITEM_DETAILS = [
        'abbreviation', 'label_color', 'buyer_facing_name', 'is_archived', 'channels', 'ecom_uri', 'tax_ids',
        'modifier_list_info', 'reporting_category', 'is_alcoholic', 'food_and_beverage_details', 'sort_name',
        'kitchen_name', 'skip_modifier_screen',
    ];

    public function __construct(private readonly bool $generateMissingSkus) {}

    public function kind(): Kind
    {
        return Kind::Product;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): ?Desired
    {
        if (Fields::isDeleted($node)) {
            return null;
        }
        $itemId = Fields::squareId($node);
        $item = $node->requiredChild('item_data');
        $title = $item->optionalString('name') ?? throw new Unsupported('A Square item without a name has no WooCommerce product.');
        $type = $item->optionalString('product_type') ?? self::DEFAULT_PRODUCT_TYPE;
        if (!in_array($type, self::PRODUCT_TYPES, true)) {
            throw new Unsupported("\"{$title}\" is a Square {$type} item, which has no WooCommerce product equivalent.");
        }
        $variations = array_values(array_filter($item->list('variations'), static fn(Node $variation): bool => !Fields::isDeleted($variation)));
        if ($variations === []) {
            throw new SourceError("Square item {$itemId} has no variations.");
        }
        $options = self::options($node, $item);
        $simple = count($variations) === 1 && self::variationData($variations[0])->list('item_option_values') === [];
        $variants = [];
        foreach ($variations as $index => $variation) {
            $variants[] = $this->variant($node, $item, $variation, $index + 1, $simple, $options);
        }
        $seo = $item->child('ecom_seo_data');
        $ecomUri = $item->optionalString('ecom_uri');
        $legacyPath = $ecomUri === null ? self::NO_PATH : LegacyPaths::normalise($ecomUri);
        $legacyPaths = $legacyPath === self::NO_PATH ? [] : [$legacyPath];
        $warnings = SquareLimits::warnings($title, $variants);
        $variablePriced = count(array_filter($variants, static fn(array $variant): bool => $variant['regular_price'] === ''));
        if ($variablePriced > 0) {
            $warnings[] = "\"{$title}\" has {$variablePriced} variable-priced variation(s); WooCommerce has no price for them, so they can't be bought.";
        }

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => $seo?->optionalString('permalink') ?? Fields::slug($title),
            'description_html' => $item->optionalString('description_html') ?? Fields::html($item->optionalString('description')),
            'status' => self::status($node, $item),
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => null,
            'tags' => [],
            'collections' => self::collections($node, $item),
            'images' => Fields::images($node, $item->strings('image_ids')),
            'attributes' => $simple ? [] : VariantAttributes::fromVariants($variants),
            'variants' => $variants,
            'simple' => $simple,
            'seo' => ['title' => $seo?->optionalString('page_title'), 'description' => $seo?->optionalString('page_description')],
            'square_product_type' => $type,
            'taxable' => self::taxable($item),
            'present_at_all_locations' => $node->raw()['present_at_all_locations'] ?? null,
            'present_at_location_ids' => $node->strings('present_at_location_ids'),
            'absent_at_location_ids' => $node->strings('absent_at_location_ids'),
            'custom_attribute_values' => $node->raw()['custom_attribute_values'] ?? null,
            'item' => array_intersect_key($item->raw(), array_flip(self::ITEM_DETAILS)),
            'variation_details' => array_combine(
                array_map(static fn(Node $variation): string => $variation->string('id'), $variations),
                array_map(static fn(Node $variation): array => array_intersect_key(self::variationData($variation)->raw(), array_flip(self::VARIATION_DETAILS)), $variations),
            ),
        ], $legacyPaths, $warnings);
    }

    /**
     * @param array<string, array{name: string, values: array<string, string>}> $options
     *
     * @return array<string, mixed>
     */
    private function variant(Node $itemNode, Node $item, Node $variation, int $position, bool $simple, array $options): array
    {
        $variationId = $variation->string('id');
        $data = self::variationData($variation);
        if ($data->optionalString('measurement_unit_id') !== null) {
            throw new Unsupported("Variation {$variationId} is sold by a measurement unit; WooCommerce sells whole units.");
        }
        $mainLocation = $itemNode->string('main_location_id');
        $override = self::override($data, $mainLocation);
        $soldOut = ($override?->raw()['sold_out'] ?? false) === true;
        $tracked = $soldOut || (self::flag($override, 'track_inventory') ?? self::flag($data, 'track_inventory') ?? false);
        $sku = $data->optionalString('sku');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . $variationId;
        }
        $variationOptions = $simple ? [] : self::variationOptions($variation, $options);

        return [
            'gid' => Fields::variationId($variationId),
            'title' => $variationOptions === [] ? ($data->optionalString('name') ?? (string) $item->optionalString('name')) : implode(' / ', $variationOptions),
            'position' => $position,
            'sku' => $sku,
            'gtin' => $data->optionalString('upc'),
            'regular_price' => self::price($variationId, $data, $override),
            'sale_price' => null,
            'cost' => null,
            'weight' => null,
            'virtual' => $item->optionalString('product_type') === self::DIGITAL,
            'taxable' => self::taxable($item),
            'manage_stock' => $tracked,
            'stock_quantity' => $tracked ? ($soldOut ? 0 : self::inStock($itemNode, $variationId, $mainLocation)) : null,
            'backorders' => false,
            'image' => Fields::images($itemNode, $data->strings('image_ids'))[0]['url'] ?? null,
            'options' => $variationOptions,
            'origin_country' => null,
            'origin_province' => null,
            'hs_code' => null,
            'meta' => [],
        ];
    }

    /**
     * The item's options, in the item's order: option id → its display name and its values (value id → name).
     *
     * @return array<string, array{name: string, values: array<string, string>}>
     */
    private static function options(Node $itemNode, Node $item): array
    {
        $objects = [];
        foreach ($itemNode->list('option_objects') as $object) {
            $objects[$object->string('id')] = $object->requiredChild('item_option_data');
        }
        $options = [];
        foreach ($item->list('item_options') as $reference) {
            $id = $reference->string('item_option_id');
            $option = $objects[$id] ?? throw new Unsupported("The item uses item option {$id}, which Square no longer has.");
            $values = [];
            foreach ($option->list('values') as $value) {
                $values[$value->string('id')] = $value->child('item_option_value_data')?->optionalString('name') ?? $value->string('id');
            }
            $options[$id] = ['name' => $option->optionalString('display_name') ?? $option->optionalString('name') ?? $id, 'values' => $values];
        }

        return $options;
    }

    /**
     * What tells a variation apart: its item option values (option name → value name, in the item's option
     * order), or its name when the item has no item options.
     *
     * @param array<string, array{name: string, values: array<string, string>}> $options
     *
     * @return array<string, string>
     */
    private static function variationOptions(Node $variation, array $options): array
    {
        $data = self::variationData($variation);
        if ($options === []) {
            return [self::VARIATION_ATTRIBUTE => $data->optionalString('name') ?? $variation->string('id')];
        }
        $chosen = [];
        foreach ($data->list('item_option_values') as $value) {
            $chosen[(string) $value->optionalString('item_option_id')] = (string) $value->optionalString('item_option_value_id');
        }
        $selected = [];
        foreach ($options as $optionId => $option) {
            $valueId = $chosen[$optionId] ?? throw new Unsupported("Variation {$variation->string('id')} has no value for option \"{$option['name']}\".");
            $selected[$option['name']] = $option['values'][$valueId] ?? throw new Unsupported("Variation {$variation->string('id')} uses an option value Square no longer has.");
        }

        return $selected;
    }

    /** @return list<string> the item's categories that are mirrored (live and named), in the item's order */
    private static function collections(Node $itemNode, Node $item): array
    {
        $named = [];
        foreach ($itemNode->list('category_objects') as $category) {
            if ($category->child('category_data')?->optionalString('name') !== null) {
                $named[$category->string('id')] = true;
            }
        }
        $ids = [];
        foreach ($item->list('categories') as $category) {
            $id = $category->string('id');
            if (isset($named[$id])) {
                $ids[] = Fields::id('Category', $id);
            }
        }

        return array_values(array_unique($ids));
    }

    /** Archived items, and items not sold at the main location, are kept private. */
    private static function status(Node $itemNode, Node $item): string
    {
        if (($item->raw()['is_archived'] ?? false) === true) {
            return 'private';
        }

        return self::presentAt($itemNode, $itemNode->string('main_location_id')) ? 'publish' : 'private';
    }

    /** Square's location presence: everywhere except the absent list (the default), or only the present list. */
    private static function presentAt(Node $object, string $locationId): bool
    {
        return ($object->raw()['present_at_all_locations'] ?? true) !== false
            ? !in_array($locationId, $object->strings('absent_at_location_ids'), true)
            : in_array($locationId, $object->strings('present_at_location_ids'), true);
    }

    /**
     * The variation's in-stock count at the main location (none recorded is zero).
     *
     * @throws Unsupported for a fractional count
     */
    private static function inStock(Node $itemNode, string $variationId, string $locationId): int
    {
        foreach ($itemNode->list('inventory_counts') as $count) {
            if ($count->optionalString('catalog_object_id') === $variationId
                && $count->optionalString('location_id') === $locationId
                && $count->optionalString('state') === SquareOnlineReader::STOCK_STATE) {
                return Fields::wholeQuantity($count->optionalString('quantity') ?? '0', "Variation {$variationId}'s stock");
            }
        }

        return 0;
    }

    /** The price at the main location (its override wins); empty when the price is set at the time of sale. */
    private static function price(string $variationId, Node $data, ?Node $override): string
    {
        if (($override?->optionalString('pricing_type') ?? $data->optionalString('pricing_type')) === self::VARIABLE_PRICING) {
            return '';
        }
        $overridden = $override === null ? null : Fields::money($override, 'price_money');

        return $overridden ?? Fields::money($data, 'price_money') ?? throw new SourceError("Variation {$variationId} has a fixed price but no price.");
    }

    private static function override(Node $data, string $locationId): ?Node
    {
        foreach ($data->list('location_overrides') as $override) {
            if ($override->optionalString('location_id') === $locationId) {
                return $override;
            }
        }

        return null;
    }

    private static function flag(?Node $node, string $field): ?bool
    {
        $value = $node?->raw()[$field] ?? null;

        return is_bool($value) ? $value : null;
    }

    /** Square items are taxable unless marked otherwise. */
    private static function taxable(Node $item): bool
    {
        return self::flag($item, 'is_taxable') ?? true;
    }

    private static function variationData(Node $variation): Node
    {
        return $variation->requiredChild('item_variation_data');
    }
}
