<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Source\Shopify\Mapping\ReviewCsv;
use IdempotentImporter\Source\Shopify\Mapping\ReviewMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReviewCsvTest extends TestCase
{
    private const JUDGE_ME = "title,body,rating,review_date,source,curated,reviewer_name,reviewer_email,product_id,product_handle,reply\n"
        . "Lovely,\"Soft, fits well\",5,2026-09-01 10:00:00 UTC,web,ok,Rae,RAE@example.com,301,silk-scarf,Thanks!\n"
        . "<script>,Meh,2,2026-09-02 10:00:00 UTC,web,spam,Bot,bot@example.com,301,silk-scarf,\n";

    public function testParsesJudgeMeExportsWithStableIds(): void
    {
        $rows = ReviewCsv::parse(self::JUDGE_ME);

        self::assertCount(2, $rows);
        self::assertSame($rows[0]->id(), ReviewCsv::parse(self::JUDGE_ME)[0]->id());
        self::assertStringStartsWith(ReviewCsv::ID_PREFIX, $rows[0]->id());

        $first = Values::of((new ReviewMapper())->map($rows[0]));
        self::assertSame('silk-scarf', $first->string('product_handle'));
        self::assertSame(5, $first->int('rating'));
        self::assertSame("<strong>Lovely</strong>\n\nSoft, fits well", $first->string('content'));
        self::assertSame('rae@example.com', $first->string('email'));
        self::assertTrue($first->bool('approved'));

        $spam = Values::of((new ReviewMapper())->map($rows[1]));
        self::assertFalse($spam->bool('approved'));
        self::assertStringContainsString('&lt;script&gt;', $spam->string('content'));
    }

    public function testShopifyProductReviewsColumns(): void
    {
        $csv = "product_handle,state,rating,title,author,email,location,body,reply,created_at,replied_at\n"
            . "silk-scarf,published,4,,Kim,kim@example.com,,Nice,,2026-09-03T10:00:00Z,\n";

        self::assertSame('Kim', Values::of((new ReviewMapper())->map(ReviewCsv::parse($csv)[0]))->string('author'));
    }

    public function testMissingColumnsAreReported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('product_handle');
        ReviewCsv::parse("rating,body\n5,x\n");
    }

    public function testOutOfRangeRatingIsUnsupported(): void
    {
        $csv = "product_handle,rating,body,created_at\nsilk-scarf,9,x,2026-09-01\n";

        $this->expectException(Unsupported::class);
        (new ReviewMapper())->map(ReviewCsv::parse($csv)[0]);
    }
}
