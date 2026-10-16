<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Lightspeed\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * A product review → a WooCommerce product review on the product: score, text, the reviewer's name and the
 * review date. A review visible in the shop is approved; a hidden one is kept unapproved. Lightspeed sends no
 * reviewer email (only a link to the customer, kept as meta) and doesn't say whether the reviewer bought the
 * product, so reviews aren't marked verified. The review's language is kept as meta.
 */
final class ReviewMapper implements Mapper
{
    private const VERSION = 1;
    private const MIN_SCORE = 1;
    private const MAX_SCORE = 5;

    public function kind(): Kind
    {
        return Kind::Review;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $score = $node->int('score');
        if ($score < self::MIN_SCORE || $score > self::MAX_SCORE) {
            throw new Unsupported("Review score {$score} is outside 1–5.");
        }
        $productId = Fields::linkedId($node, 'product') ?? throw new SourceError("Lightspeed review {$node->id()} names no product.");
        $customerId = Fields::linkedId($node, 'customer');

        return new Desired(Kind::Review, $node->id(), [
            'product_gid' => Fields::productId($productId),
            'rating' => $score,
            'content' => Fields::text($node, 'content') ?? '',
            'author' => Fields::text($node, 'name') ?? '',
            'email' => '',
            'created_at' => Fields::requiredTime($node, 'createdAt'),
            'approved' => ($node->raw()['isVisible'] ?? false) === true,
            'verified' => false,
            'reply' => null,
            'customer_gid' => $customerId === null ? null : Fields::customerId($customerId),
            'language' => Fields::object($node, 'language')?->optionalString('code'),
        ]);
    }
}
