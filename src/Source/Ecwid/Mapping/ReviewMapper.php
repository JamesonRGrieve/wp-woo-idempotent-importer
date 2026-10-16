<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Ecwid\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;
use IdempotentImporter\Source\SourceError;

/**
 * A product review → a WooCommerce product review on the product: rating, text, the reviewer's name and
 * email, and the review date. A published review is approved; one waiting for moderation is kept
 * unapproved. A review tied to an order is a verified purchase. The customer link, the order id and the
 * reviewer's city and country are mirrored as meta.
 */
final class ReviewMapper implements Mapper
{
    private const VERSION = 1;
    private const MIN_RATING = 1;
    private const MAX_RATING = 5;
    private const PUBLISHED = 'published';

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
        $rating = $node->int('rating');
        if ($rating < self::MIN_RATING || $rating > self::MAX_RATING) {
            throw new Unsupported("Review rating {$rating} is outside 1–5.");
        }
        $productId = Fields::linkedId($node, 'productId') ?? throw new SourceError("Ecwid review {$node->id()} names no product.");
        $customerId = Fields::linkedId($node, 'customerId');
        $reviewer = $node->child('reviewerInfo');
        $orderId = $node->optionalString('orderId');

        return new Desired(Kind::Review, $node->id(), [
            'product_gid' => Fields::productId($productId),
            'rating' => $rating,
            'content' => $node->optionalString('review') ?? '',
            'author' => $reviewer?->optionalString('name') ?? '',
            'email' => $reviewer?->optionalString('email') ?? '',
            'created_at' => Fields::requiredTime($node, 'createDate'),
            'approved' => $node->optionalString('status') === self::PUBLISHED,
            'verified' => $orderId !== null,
            'reply' => null,
            'customer_gid' => $customerId === null ? null : Fields::customerId($customerId),
            'order_id' => $orderId,
            'reviewer_location' => $reviewer?->only(['city', 'country']),
        ]);
    }
}
