<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A Wix Stores product review → a WooCommerce product review on the product: rating, title and body, the
 * author's display name, the date it was written, Wix's verified flag and the store's reply. Approved
 * reviews (and those published without moderation) are approved; reviews in moderation or rejected are
 * kept unapproved. Wix gives the author's contact, not an email, so the review carries none (the contact
 * id is meta). Helpfulness votes aren't mirrored, so a vote doesn't rewrite the review.
 */
final class ReviewMapper implements Mapper
{
    private const VERSION = 1;
    private const MIN_RATING = 1;
    private const MAX_RATING = 5;
    private const PUBLISHED = ['APPROVED', 'SKIPPED'];

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
        $content = $node->requiredChild('content');
        $rating = $content->int('rating');
        if ($rating < self::MIN_RATING || $rating > self::MAX_RATING) {
            throw new Unsupported("Review rating {$rating} is outside 1–5.");
        }
        $title = $content->optionalString('title');
        $body = htmlspecialchars($content->optionalString('body') ?? '', ENT_QUOTES);
        $author = $node->child('author');
        $status = $node->child('moderation')?->optionalString('moderationStatus');

        return new Desired(Kind::Review, $node->id(), [
            'product_gid' => Fields::productId($node->string('entityId')),
            'rating' => $rating,
            'content' => $title === null ? $body : '<strong>' . htmlspecialchars($title, ENT_QUOTES) . "</strong>\n\n{$body}",
            'author' => $author?->optionalString('authorName') ?? '',
            'email' => '',
            'created_at' => Fields::time($node, 'reviewDate') ?? Fields::requiredTime($node, 'createdDate'),
            'approved' => in_array($status, self::PUBLISHED, true),
            'verified' => ($node->raw()['verified'] ?? false) === true,
            'reply' => $node->child('reply')?->optionalString('message'),
            'status' => $status,
            'contact_id' => $author?->optionalString('contactId'),
        ]);
    }
}
