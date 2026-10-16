<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\BigCommerce\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A product review → a WooCommerce product review on the product: rating, title and text, reviewer name and
 * email, review date. Approved reviews are approved; pending and disapproved ones are kept unapproved.
 * BigCommerce doesn't say whether the reviewer bought the product, so reviews aren't marked verified.
 */
final class ReviewMapper implements Mapper
{
    private const VERSION = 1;
    private const MIN_RATING = 1;
    private const MAX_RATING = 5;
    private const APPROVED = 'approved';

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
        $title = $node->optionalString('title');
        $text = $node->optionalString('text') ?? '';

        return new Desired(Kind::Review, $node->id(), [
            'product_gid' => Fields::productId($node->int('product_id')),
            'rating' => $rating,
            'content' => $title === null ? $text : '<strong>' . htmlspecialchars($title, ENT_QUOTES) . "</strong>\n\n{$text}",
            'author' => $node->optionalString('name') ?? '',
            'email' => strtolower($node->optionalString('email') ?? ''),
            'created_at' => Fields::requiredTime($node, 'date_reviewed'),
            'approved' => $node->optionalString('status') === self::APPROVED,
            'verified' => false,
            'reply' => null,
            'status' => $node->optionalString('status'),
        ]);
    }
}
