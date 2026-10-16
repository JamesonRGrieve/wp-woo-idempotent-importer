<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Etsy\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A transaction review → a verified WooCommerce product review on the listing's product. Etsy shows
 * reviewers' names only on etsy.com, so the review is attributed to "Etsy buyer"; its photo and language
 * are mirrored as meta.
 */
final class ReviewMapper implements Mapper
{
    public const AUTHOR = 'Etsy buyer';

    private const VERSION = 1;
    private const MIN_RATING = 1;
    private const MAX_RATING = 5;

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

        return new Desired(Kind::Review, $node->id(), [
            'product_gid' => Fields::id('Listing', $node->int('listing_id')),
            'rating' => $rating,
            'content' => Fields::html($node->optionalString('review')),
            'author' => self::AUTHOR,
            'email' => '',
            'created_at' => Fields::requiredTime($node, 'created_timestamp'),
            'approved' => true,
            'verified' => true,
            'reply' => null,
            'image' => $node->optionalString('image_url_fullxfull'),
            'language' => $node->optionalString('language'),
        ]);
    }
}
