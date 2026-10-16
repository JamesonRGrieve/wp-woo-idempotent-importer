<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * Open draft orders → pending WooCommerce orders. A completed draft became a real order (imported as
 * such), so the draft's copy is retired.
 */
final class DraftOrderMapper implements Mapper
{
    private const VERSION = 1;
    private const COMPLETED = 'COMPLETED';

    public function kind(): Kind
    {
        return Kind::DraftOrder;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): ?Desired
    {
        if ($node->string('status') === self::COMPLETED) {
            return null;
        }
        $shippingLine = $node->child('shippingLine');

        return new Desired(Kind::DraftOrder, $node->id(), [
            ...OrderParts::common($node, $shippingLine === null ? [] : [$shippingLine]),
            'status' => 'pending',
            'draft_status' => $node->string('status'),
            'customer_note' => $node->optionalString('note2'),
            'fees' => [],
            'refunds' => [],
            'fulfillments' => [],
            'transactions' => [],
            'notes' => [],
        ]);
    }
}
