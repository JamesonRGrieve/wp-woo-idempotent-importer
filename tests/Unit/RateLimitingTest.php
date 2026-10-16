<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Http\RateLimit;
use IdempotentImporter\Http\RequestPacer;
use IdempotentImporter\Http\ResetFormat;
use IdempotentImporter\Http\RetryPolicy;
use IdempotentImporter\Source\RetryLater;
use IdempotentImporter\Sync\PausePolicy;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;

/** The lockout-safety rules shared by every source: pacing, backoff, inline waits and run pauses. */
final class RateLimitingTest extends TestCase
{
    public function testBackoffDoublesUpToItsCap(): void
    {
        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 16.0, 16.0], array_map(RetryPolicy::backoff(...), range(1, 7)));
    }

    public function testOnlyShortWaitsWithinTheAttemptsAreSatOutInline(): void
    {
        self::assertTrue(RetryPolicy::waitsInline(RetryPolicy::MAX_INLINE_WAIT_SECONDS, 1));
        self::assertFalse(RetryPolicy::waitsInline(RetryPolicy::MAX_INLINE_WAIT_SECONDS + 1, 1), 'too long for a request');
        self::assertFalse(RetryPolicy::waitsInline(1.0, RetryPolicy::MAX_ATTEMPTS), 'attempts used up');
    }

    public function testRunPausesGrowFromTheSourcesHintToAnHourAndThenGiveUp(): void
    {
        self::assertSame([60, 120, 240, 480, 960, 1920, 3600, 3600], array_map(static fn(int $pause): int => PausePolicy::seconds($pause, 16.0), range(1, 8)));
        self::assertSame(900, PausePolicy::seconds(1, 899.2), "the source's own longer wait wins");
        self::assertSame(PausePolicy::MAX_SECONDS, PausePolicy::seconds(1, 86400.0), 'never more than an hour at a time');
        self::assertFalse(PausePolicy::exhausted(PausePolicy::MAX_CONSECUTIVE_PAUSES));
        self::assertTrue(PausePolicy::exhausted(PausePolicy::MAX_CONSECUTIVE_PAUSES + 1));
        $heldFor = array_sum(array_map(static fn(int $pause): int => PausePolicy::seconds($pause, 0.0), range(1, PausePolicy::MAX_CONSECUTIVE_PAUSES)));
        self::assertGreaterThan(24 * 3600, $heldFor, 'the pauses outlast a spent daily quota');
        self::assertLessThan(26 * 3600, $heldFor, 'and give up a dead source within about a day');
    }

    public function testResetFormats(): void
    {
        self::assertSame(3.0, ResetFormat::SecondsFromNow->secondsUntil('3', 100.0));
        self::assertSame(1.5, ResetFormat::MillisecondsFromNow->secondsUntil('1500', 100.0));
        self::assertSame(20.0, ResetFormat::EpochSeconds->secondsUntil('120', 100.0));
        self::assertSame(0.0, ResetFormat::EpochSeconds->secondsUntil('90', 100.0), 'a reset in the past means now');
        self::assertNull(ResetFormat::SecondsFromNow->secondsUntil('soon', 100.0));
    }

    public function testAPacerWithoutLimitsNeverWaits(): void
    {
        $sleeper = new RecordingSleeper();
        $pacer = new RequestPacer(new RateLimit(), $sleeper);

        for ($request = 0; $request < 3; ++$request) {
            $pacer->beforeRequest('Test');
            $pacer->afterResponse(new HttpResponse(200, '{}', null, null, ['x-left' => '0']));
        }

        self::assertSame([], $sleeper->waits);
    }

    public function testAWindowThatReportsOnlyWhatRemainsWaitsItsFixedLength(): void
    {
        $sleeper = new RecordingSleeper();
        $pacer = new RequestPacer(new RateLimit(remainingHeader: 'x-remaining-this-second', windowSeconds: 1.0), $sleeper);

        $pacer->beforeRequest('Etsy');
        $pacer->afterResponse(new HttpResponse(200, '{}', null, null, ['x-remaining-this-second' => '0']));
        $pacer->beforeRequest('Etsy');

        self::assertCount(1, $sleeper->waits);
        self::assertEqualsWithDelta(1.0, $sleeper->waits[0], 1e-6);
    }

    public function testASpentWindowLongerThanARequestMayWaitPausesTheRun(): void
    {
        $sleeper = new RecordingSleeper();
        $pacer = new RequestPacer(new RateLimit(remainingHeader: 'x-left', resetHeader: 'x-reset'), $sleeper);

        $pacer->beforeRequest('Test');
        $pacer->afterResponse(new HttpResponse(200, '{}', null, null, ['x-left' => '1', 'x-reset' => '600']));

        $this->expectException(RetryLater::class);
        $pacer->beforeRequest('Test');
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $response = new HttpResponse(200, '', null, null, ['x-rate-limit-requests-left' => '3', 'empty' => '']);

        self::assertSame('3', $response->header('X-Rate-Limit-Requests-Left'));
        self::assertNull($response->header('Empty'));
        self::assertNull($response->header('missing'));
    }

    public function testADocumentedRateIsSpacedEvenly(): void
    {
        self::assertSame(5.0, RateLimit::perPeriod(300, 60.0));
        self::assertEqualsWithDelta(0.2, (new RateLimit(requestsPerSecond: 5.0))->minInterval(), 1e-12);
        self::assertSame(0.0, (new RateLimit())->minInterval());
    }
}
