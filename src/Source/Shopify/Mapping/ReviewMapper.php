<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/** A review CSV row → a WooCommerce product review. */
final class ReviewMapper implements Mapper
{
    public const MIN_RATING = 1;
    public const MAX_RATING = 5;

    private const VERSION = 1;
    private const HIDDEN_STATES = ['unpublished', 'hidden', 'false', 'spam', 'archived'];
    private const TRUE_VALUES = ['true', 'yes', '1'];

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
        $body = $node->string('body');
        $state = strtolower($node->optionalString('state') ?? '');

        return new Desired(Kind::Review, $node->id(), [
            'product_handle' => $node->string('product_handle'),
            'rating' => $rating,
            'content' => $title === null ? $body : '<strong>' . htmlspecialchars($title, ENT_QUOTES) . "</strong>\n\n{$body}",
            'author' => $node->optionalString('author') ?? '',
            'email' => strtolower($node->optionalString('email') ?? ''),
            'created_at' => $node->string('created_at'),
            'approved' => !in_array($state, self::HIDDEN_STATES, true),
            'verified' => in_array(strtolower($node->optionalString('verified') ?? ''), self::TRUE_VALUES, true),
            'reply' => $node->optionalString('reply'),
        ]);
    }
}
