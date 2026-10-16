<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Mapping;

use IdempotentImporter\Model\Kind;
use IdempotentImporter\Shopify\ShopifyNode;

/** Collection → product category. Smart-collection rules have no home; membership comes from products. */
final class CollectionMapper implements Mapper
{
    private const VERSION = 2;
    private const METAFIELD_CONDITION = 'CollectionRuleMetafieldCondition';

    public function kind(): Kind
    {
        return Kind::Collection;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(ShopifyNode $node): Desired
    {
        $metafields = Fields::metafields($node);
        $image = $node->child('image');
        $rules = $node->child('ruleSet');

        return new Desired(Kind::Collection, $node->id(), [
            'name' => $node->string('title'),
            'slug' => $node->string('handle'),
            'description_html' => $node->optionalString('descriptionHtml') ?? '',
            'image' => $image === null ? null : ['url' => $image->string('url'), 'alt' => $image->optionalString('altText')],
            'seo' => Fields::seo($node->child('seo'), $metafields),
            'sort_order' => $node->optionalString('sortOrder'),
            'template_suffix' => $node->optionalString('templateSuffix'),
            'smart_rules' => $rules === null ? null : [
                'disjunctive' => $rules->bool('appliedDisjunctively'),
                'rules' => array_map(self::rule(...), $rules->list('rules')),
            ],
            'meta' => $metafields,
        ], [LegacyPaths::collection($node->string('handle'))]);
    }

    /**
     * A smart-collection rule in the shape wp-woo-dynamic-collections stores (Shopify's own, plus the
     * metafield a metafield rule tests as "namespace.key").
     *
     * @return array{column: string, relation: string, condition: string, metafield?: string}
     */
    private static function rule(ShopifyNode $rule): array
    {
        $mapped = ['column' => $rule->string('column'), 'relation' => $rule->string('relation'), 'condition' => $rule->string('condition')];
        $object = $rule->child('conditionObject');
        if ($object !== null && $object->typename() === self::METAFIELD_CONDITION) {
            $definition = $object->requiredChild('metafieldDefinition');
            $mapped['metafield'] = $definition->string('namespace') . '.' . $definition->string('key');
        }

        return $mapped;
    }
}
