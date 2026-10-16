<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\VariableNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\LeafType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use RuntimeException;

/**
 * Shopify's requested cost of a query, computed statically. Shopify documents the rules only in outline
 * (shopify.dev/docs/apps/build/apis/graphql-admin/rate-limits: scalars and enums 0, objects 1, interfaces and
 * unions the maximum of their possible selections, connections sized by `first`/`last`, plus manual costs on
 * some fields); the exact figures here were calibrated against a live store with Shopify-GraphQL-Cost-Debug
 * (tools/calibrate-query-cost.php, recorded in tests/fixtures/shopify-query-cost.json):
 *  - a connection costs 2 + m(first) × the cost of its selection, where m(n) = max(1, ⌊2·ln n⌋);
 *  - any other object-returning field (lists included) costs 1 plus its selection, except the zero-cost
 *    types and fields below.
 */
final class QueryCost
{
    /** Object types Shopify charges nothing for. */
    public const ZERO_COST_TYPES = ['PageInfo', 'MoneyBag', 'MoneyV2', 'Weight'];

    /** Object-returning fields Shopify charges nothing for (`Type.field`). */
    public const ZERO_COST_FIELDS = [
        'Collection.ruleSet',
        'CollectionRule.conditionObject',
        'CollectionRuleSet.rules',
        'Customer.defaultAddress',
        'Customer.emailMarketingConsent',
        'Customer.smsMarketingConsent',
        'InventoryItem.measurement',
        'Shop.shopAddress',
    ];

    private const CONNECTION_SUFFIX = 'Connection';
    private const CONNECTION_COST = 2;
    private const OBJECT_COST = 1;
    private const SIZE_ARGUMENTS = ['first', 'last'];
    private const TYPENAME = '__typename';

    /** @var array<string, FragmentDefinitionNode> */
    private array $fragments = [];

    /** @var array<string, mixed> */
    private array $variables = [];

    public function __construct(private readonly Schema $schema) {}

    /** Shopify's multiplier for a connection page of $first objects. */
    public static function sizeMultiplier(int $first): int
    {
        return max(1, (int) floor(2 * log(max(1, $first))));
    }

    /**
     * The requested cost of the document's (first) operation with these variables.
     *
     * @param array<string, mixed> $variables
     */
    public function of(string $document, array $variables): int
    {
        $operation = null;
        $this->fragments = [];
        $this->variables = $variables;
        foreach (Parser::parse($document)->definitions as $definition) {
            if ($definition instanceof FragmentDefinitionNode) {
                $this->fragments[$definition->name->value] = $definition;
            } elseif ($definition instanceof OperationDefinitionNode) {
                $operation ??= $definition;
            }
        }
        if ($operation === null) {
            throw new RuntimeException('The document has no operation.');
        }
        $root = $operation->operation === 'query' ? $this->schema->getQueryType() : $this->schema->getMutationType();

        return $this->selections($operation->selectionSet, $root ?? throw new RuntimeException('The schema has no root type.'));
    }

    private function selections(SelectionSetNode $set, Type $parent): int
    {
        if ($parent instanceof AbstractType) {
            $costs = array_map(fn(ObjectType $type): int => $this->selectionsOn($set, $type), $this->schema->getPossibleTypes($parent));

            return $costs === [] ? 0 : max($costs);
        }
        if (!$parent instanceof ObjectType) {
            return 0;
        }

        return $this->selectionsOn($set, $parent);
    }

    private function selectionsOn(SelectionSetNode $set, ObjectType $type): int
    {
        $cost = 0;
        foreach ($set->selections as $selection) {
            if ($selection instanceof FieldNode) {
                if ($selection->name->value !== self::TYPENAME) {
                    $cost += $this->field($type->getField($selection->name->value), $selection, $type);
                }
            } elseif ($selection instanceof InlineFragmentNode) {
                if ($this->applies($selection->typeCondition?->name->value ?? $type->name, $type)) {
                    $cost += $this->selectionsOn($selection->selectionSet, $type);
                }
            } elseif ($selection instanceof FragmentSpreadNode) {
                $fragment = $this->fragments[$selection->name->value] ?? throw new RuntimeException("Unknown fragment {$selection->name->value}");
                if ($this->applies($fragment->typeCondition->name->value, $type)) {
                    $cost += $this->selectionsOn($fragment->selectionSet, $type);
                }
            }
        }

        return $cost;
    }

    private function field(FieldDefinition $definition, FieldNode $node, ObjectType $parent): int
    {
        $named = Type::getNamedType($definition->getType());
        if ($named instanceof LeafType) {
            return 0;
        }
        $children = $node->selectionSet === null ? 0 : $this->selections($node->selectionSet, $named);
        $size = $this->size($node);
        if ($size !== null && str_ends_with($named->name(), self::CONNECTION_SUFFIX)) {
            return self::CONNECTION_COST + self::sizeMultiplier($size) * $children;
        }
        $free = in_array($named->name(), self::ZERO_COST_TYPES, true) || in_array("{$parent->name}.{$definition->name}", self::ZERO_COST_FIELDS, true);

        return ($free ? 0 : self::OBJECT_COST) + $children;
    }

    /** The field's `first`/`last` page size (a literal or a variable), or null when it takes none. */
    private function size(FieldNode $node): ?int
    {
        foreach ($node->arguments as $argument) {
            if (!in_array($argument->name->value, self::SIZE_ARGUMENTS, true)) {
                continue;
            }
            $value = $argument->value;
            if ($value instanceof IntValueNode) {
                return (int) $value->value;
            }
            if ($value instanceof VariableNode) {
                $variable = $this->variables[$value->name->value] ?? null;

                return is_int($variable) ? $variable : throw new RuntimeException("No page size for \${$value->name->value}");
            }
        }

        return null;
    }

    private function applies(string $condition, ObjectType $type): bool
    {
        if ($condition === $type->name) {
            return true;
        }
        $conditionType = $this->schema->getType($condition);

        return $conditionType instanceof AbstractType && $this->schema->isSubType($conditionType, $type);
    }
}
