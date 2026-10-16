<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * A listing → a WooCommerce product. Each inventory product becomes a variant identified by its property
 * values (see Fields::variantId); a listing without variations is a simple product. Fields WooCommerce
 * has no home for (materials, styles, who/when made, personalization, translations, shipping profile,
 * videos, taxonomy) are mirrored as meta by the writer.
 */
final class ListingMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'etsy-';

    private const VERSION = 1;
    /** Etsy listing state → WooCommerce status; inactive and expired listings stay, hidden. */
    private const STATUS = ['active' => 'publish', 'sold_out' => 'publish', 'draft' => 'draft', 'inactive' => 'private', 'expired' => 'private'];
    private const DOWNLOAD_ONLY = 'download';

    public function __construct(private readonly bool $generateMissingSkus) {}

    public function kind(): Kind
    {
        return Kind::Product;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $listingId = $node->int('listing_id');
        $title = (string) Fields::text($node->string('title'));
        $products = array_values(array_filter(
            $node->child('inventory')?->list('products') ?? throw new SourceError("Listing {$listingId} came without its inventory."),
            static fn(Node $product): bool => !$product->bool('is_deleted'),
        ));
        $simple = !$node->bool('has_variations') || count($products) === 1 && $products[0]->list('property_values') === [];
        $variants = [];
        foreach ($products as $position => $product) {
            $variants[] = $this->variant($node, $product, $position + 1, $simple);
        }
        $sectionId = $node->optionalInt('shop_section_id');

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Fields::slug($title),
            'description_html' => Fields::html($node->string('description')),
            'status' => self::STATUS[$node->string('state')] ?? 'draft',
            'catalog_visibility' => 'visible',
            'published_at' => Fields::time($node, 'original_creation_timestamp'),
            'created_at' => Fields::requiredTime($node, 'created_timestamp'),
            'tags' => array_map(static fn(string $tag): string => (string) Fields::text($tag), $node->strings('tags')),
            'collections' => $sectionId === null ? [] : [Fields::id('Section', $sectionId)],
            'images' => self::images($node),
            'other_media' => array_map(
                static fn(Node $video): array => ['type' => 'Video', 'url' => $video->string('video_url')],
                $node->list('videos'),
            ),
            'attributes' => $simple ? [] : self::attributes($products),
            'variants' => $variants,
            'simple' => $simple,
            'url' => $node->optionalString('url'),
            'materials' => $node->strings('materials'),
            'styles' => $node->strings('style'),
            'who_made' => $node->optionalString('who_made'),
            'when_made' => $node->optionalString('when_made'),
            'is_supply' => $node->raw()['is_supply'] ?? null,
            'listing_type' => $node->string('listing_type'),
            'taxonomy_id' => $node->optionalInt('taxonomy_id'),
            'processing_days' => [$node->optionalInt('processing_min'), $node->optionalInt('processing_max')],
            'dimensions' => [
                'length' => $node->optionalDecimal('item_length'),
                'width' => $node->optionalDecimal('item_width'),
                'height' => $node->optionalDecimal('item_height'),
                'unit' => $node->optionalString('item_dimensions_unit'),
            ],
            'shipping_profile_id' => $node->optionalInt('shipping_profile_id'),
            'return_policy_id' => $node->optionalInt('return_policy_id'),
            'personalization' => $node->raw()['personalization'] ?? null,
            'translations' => $node->raw()['translations'] ?? null,
        ], [], SquareLimits::warnings($title, $variants));
    }

    /** @return array<string, mixed> */
    private function variant(Node $listing, Node $product, int $position, bool $simple): array
    {
        $listingId = $listing->int('listing_id');
        $offering = array_values(array_filter($product->list('offerings'), static fn(Node $offer): bool => !$offer->bool('is_deleted')))[0]
            ?? throw new SourceError("Listing {$listingId} has an inventory product with no offering.");
        $options = [];
        $valueIds = [];
        foreach ($product->list('property_values') as $property) {
            $options[(string) Fields::text($property->optionalString('property_name') ?? (string) $property->int('property_id'))]
                = (string) Fields::text(implode(', ', $property->strings('values')));
            $valueIds[$property->int('property_id')] = implode('.', $property->ints('value_ids'));
        }
        $key = Fields::variantKey($listingId, $simple ? [] : $valueIds);
        $sku = $product->optionalString('sku');
        if ($sku === null && $this->generateMissingSkus) {
            $sku = self::GENERATED_SKU_PREFIX . $key;
        }
        $weight = $listing->optionalDecimal('item_weight');
        $weightUnit = $listing->optionalString('item_weight_unit');

        return [
            'gid' => Fields::id('ListingVariant', $key),
            'title' => $options === [] ? (string) Fields::text($listing->string('title')) : implode(' / ', $options),
            'position' => $position,
            'sku' => $sku,
            'gtin' => null,
            'regular_price' => Fields::money($offering, 'price') ?? throw new SourceError("Listing {$listingId} has an offering with no price."),
            'sale_price' => null,
            'cost' => null,
            'weight' => $weight === null || $weightUnit === null ? null : ['value' => $weight, 'unit' => Fields::WEIGHT_UNITS[$weightUnit]],
            'virtual' => $listing->string('listing_type') === self::DOWNLOAD_ONLY,
            'taxable' => $listing->bool('is_taxable'),
            'manage_stock' => true,
            'stock_quantity' => $offering->bool('is_enabled') ? $offering->int('quantity') : 0,
            'backorders' => false,
            'image' => null,
            'options' => $options,
            'origin_country' => null,
            'origin_province' => null,
            'hs_code' => null,
            'meta' => [],
        ];
    }

    /**
     * Each property (in the order the first product lists them) with every value it takes.
     *
     * @param list<Node> $products
     *
     * @return list<array{name: string, position: int, values: list<string>}>
     */
    private static function attributes(array $products): array
    {
        $attributes = [];
        foreach ($products as $product) {
            foreach ($product->list('property_values') as $property) {
                $name = (string) Fields::text($property->optionalString('property_name') ?? (string) $property->int('property_id'));
                $attributes[$name] ??= ['name' => $name, 'position' => count($attributes) + 1, 'values' => []];
                $value = (string) Fields::text(implode(', ', $property->strings('values')));
                if (!in_array($value, $attributes[$name]['values'], true)) {
                    $attributes[$name]['values'][] = $value;
                }
            }
        }

        return array_values($attributes);
    }

    /** @return list<array{url: string, alt: ?string}> by rank (the first is the featured image) */
    private static function images(Node $listing): array
    {
        $images = $listing->list('images');
        usort($images, static fn(Node $a, Node $b): int => $a->int('rank') <=> $b->int('rank'));

        return array_map(
            static fn(Node $image): array => ['url' => $image->string('url_fullxfull'), 'alt' => Fields::text($image->optionalString('alt_text'))],
            $images,
        );
    }
}
