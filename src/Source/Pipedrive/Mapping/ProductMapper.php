<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Pipedrive\Mapping;

use IdempotentImporter\Mapping\CrmContract;
use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Slugs;
use IdempotentImporter\Mapping\SquareLimits;
use IdempotentImporter\Mapping\VariantAttributes;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Support\Decimal;

/**
 * A product → a WooCommerce product: name, description, code as the SKU, its price and cost in the account's
 * default currency (`currency`, which the reader adds), and its category (an option of the category field) as its
 * product category. A product with variations is a variable
 * product with a variation per Pipedrive variation (its name as the value of a "Variation" attribute, its own price
 * in that currency, else the product's); without them, a simple product. Pipedrive keeps no stock, so stock is
 * unmanaged. Its prices in other currencies, unit, tax percentage, billing frequency, owner and the account's own
 * product fields are meta; recurring billing is warned about (the selling-plans companion is planned).
 */
final class ProductMapper implements Mapper
{
    public const GENERATED_SKU_PREFIX = 'pipedrive-';
    public const VARIATION_ATTRIBUTE = 'Variation';

    private const VERSION = 1;
    private const ONE_TIME = 'one-time';
    private const DETAILS = ['unit', 'tax', 'prices', 'billing_frequency', 'billing_frequency_cycles', 'owner_id', 'visible_to', 'is_linkable', 'custom_fields'];

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
        $id = Fields::sourceId($node);
        $title = $node->string('name');
        $currency = $node->string('currency');
        [$price, $cost] = self::price($node, $currency);
        $variations = $node->list('variations');
        $simple = $variations === [];
        $code = $node->optionalString('code');
        $variants = $simple
            ? [$this->variant(Fields::id('Variant', $id), $title, 1, $code ?? ($this->generateMissingSkus ? self::GENERATED_SKU_PREFIX . $id : null), $price ?? '0', $cost, [])]
            : array_map(fn(Node $variation, int $index): array => $this->variant(
                Fields::id('Variation', $variation->int('id')),
                $variation->string('name'),
                $index + 1,
                $this->generateMissingSkus ? self::GENERATED_SKU_PREFIX . $id . '-' . $variation->int('id') : null,
                self::price($variation, $currency)[0] ?? $price ?? '0',
                null,
                [self::VARIATION_ATTRIBUTE => $variation->string('name')],
            ), $variations, array_keys($variations));
        $frequency = $node->optionalString('billing_frequency');
        $category = $node->optionalString('category');

        return new Desired(Kind::Product, $node->id(), [
            'title' => $title,
            'slug' => Slugs::fromTitle($title),
            'description_html' => CrmContract::textHtml($node->optionalString('description')),
            'status' => ($node->raw()['is_deleted'] ?? false) === true ? 'private' : 'publish',
            'catalog_visibility' => 'visible',
            'published_at' => null,
            'created_at' => Fields::time($node, 'add_time'),
            'tags' => [],
            'collections' => $category === null ? [] : [Fields::id('Category', $category)],
            'images' => [],
            'attributes' => $simple ? [] : VariantAttributes::fromVariants($variants),
            'variants' => $variants,
            'simple' => $simple,
            'seo' => ['title' => null, 'description' => null],
            'brand' => null,
            'product' => array_filter($node->only(self::DETAILS), static fn(mixed $value): bool => $value !== null && $value !== []),
        ], [], [
            ...SquareLimits::warnings($title, $variants),
            ...($frequency !== null && $frequency !== self::ONE_TIME ? ["\"{$title}\" is billed {$frequency}; it is meta until the selling-plans companion (wp-woo-selling-plans) holds it."] : []),
        ]);
    }

    /**
     * A product's or variation's price and cost in a currency (null when it has none in it).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function price(Node $item, string $currency): array
    {
        foreach (is_array($item->raw()['prices'] ?? null) ? $item->list('prices') : [] as $price) {
            if ($price->optionalString('currency') === $currency) {
                return [$price->optionalDecimal('price'), $price->optionalDecimal('cost')];
            }
        }

        return [null, null];
    }

    /**
     * @param array<string, string> $options
     *
     * @return array<string, mixed>
     */
    private function variant(string $gid, string $title, int $position, ?string $sku, string $price, ?string $cost, array $options): array
    {
        return [
            'gid' => $gid,
            'title' => $title,
            'position' => $position,
            'sku' => $sku,
            'gtin' => null,
            'regular_price' => Decimal::normalise($price),
            'sale_price' => null,
            'cost' => $cost,
            'weight' => null,
            'virtual' => false,
            'taxable' => true,
            'manage_stock' => false,
            'stock_quantity' => null,
            'backorders' => false,
            'image' => null,
            'options' => $options,
            'origin_country' => null,
            'origin_province' => null,
            'hs_code' => null,
            'meta' => [],
        ];
    }
}
