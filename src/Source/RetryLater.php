<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

/**
 * The source can't be read right now — it keeps rate limiting, asks for a wait too long to sit out inside a
 * request, keeps failing or can't be reached — so the run pauses where it is and resumes after $seconds,
 * rather than failing the kind and carrying on against a source that is pushing back.
 */
final class RetryLater extends SourceError
{
    public function __construct(public readonly float $seconds, string $message)
    {
        parent::__construct($message);
    }
}
