<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use GraphQL\Utils\BuildSchema;
use IdempotentImporter\Tools\CostCalibration;
use IdempotentImporter\Tools\QueryCost;
use PHPUnit\Framework\TestCase;

final class QueryCostTest extends TestCase
{
    private const SCHEMA = <<<'GRAPHQL'
        type Query {
          products(first: Int, after: String): ProductConnection!
          node(id: ID!): Node
          shop: Shop!
        }
        interface Node { id: ID! }
        type Shop { name: String! shopAddress: Address! billingAddress: Address }
        type Address { city: String }
        type PageInfo { hasNextPage: Boolean! }
        type MoneyV2 { amount: String! }
        type ProductConnection { pageInfo: PageInfo! nodes: [Product!]! }
        type Product implements Node {
          id: ID!
          title: String!
          price: MoneyV2!
          options: [Option!]!
          variants(first: Int): VariantConnection!
        }
        type Option { name: String! }
        type VariantConnection { pageInfo: PageInfo! nodes: [Variant!]! }
        type Variant implements Node { id: ID! product: Product! }
        GRAPHQL;

    public function testTheSizeMultiplierMatchesEveryOneMeasuredOnALiveStore(): void
    {
        $measured = CostCalibration::multipliers();

        self::assertNotEmpty($measured);
        foreach ($measured as $size => $multiplier) {
            self::assertSame($multiplier, QueryCost::sizeMultiplier($size), "first: {$size}");
        }
    }

    public function testObjectsCostOneScalarsAndZeroCostTypesNothing(): void
    {
        $cost = new QueryCost(BuildSchema::build(self::SCHEMA));

        self::assertSame(1, $cost->of('{ shop { name shopAddress { city } } }', []), 'Shop.shopAddress is free');
        self::assertSame(2, $cost->of('{ shop { name billingAddress { city } } }', []));
    }

    public function testConnectionsAreSizedByTheirPageSizeAndNestedConnectionsMultiply(): void
    {
        $cost = new QueryCost(BuildSchema::build(self::SCHEMA));
        $query = 'query P($first: Int!, $variantsFirst: Int!) { products(first: $first) { pageInfo { hasNextPage } nodes { title price { amount } options { name } variants(first: $variantsFirst) { nodes { id } } } } }';

        // products: 2 + m(50)=7 × (nodes 1 + options 1 + variants (2 + m(250)=11 × nodes 1)) = 2 + 7 × 15
        self::assertSame(107, $cost->of($query, ['first' => 50, 'variantsFirst' => 250]));
        // 2 + m(1)=1 × (1 + 1 + (2 + 1 × 1))
        self::assertSame(7, $cost->of($query, ['first' => 1, 'variantsFirst' => 1]));
    }

    public function testInterfacesCostTheirMostCostlyPossibleSelection(): void
    {
        $cost = new QueryCost(BuildSchema::build(self::SCHEMA));
        $query = '{ node(id: "x") { __typename ... on Product { options { name } } ... on Variant { product { options { name } } } } }';

        self::assertSame(3, $cost->of($query, []), 'node 1 + Variant.product 1 + options 1');
    }

    public function testTheCalibrationCoversTheDefaultsShopifyRefused(): void
    {
        $operations = CostCalibration::operations();

        self::assertSame(1941, $operations['ProductList']['cost'] ?? null);
        self::assertSame(1080, $operations['OrderList']['cost'] ?? null);
        self::assertSame(3957, $operations['DeliveryProfileList']['cost'] ?? null);
    }
}
