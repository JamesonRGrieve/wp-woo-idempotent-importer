<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

use RuntimeException;

/**
 * One published API document a source's fixtures are validated against, pinned to an exact version
 * (a versioned URL or a commit) or, where the vendor only publishes an unversioned URL, to the SHA-256 of
 * the document as fetched (`sha256`: a changed document fails loudly instead of silently changing what
 * fixtures are validated against). It names the server path prefix its paths sit under and how strictly a
 * response object is checked: `completeObjects` for APIs that return every declared property (Etsy),
 * otherwise only the spec's own required properties must be present. Undeclared properties are always
 * rejected.
 */
final readonly class SpecDocument
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_YAML = 'yaml';

    public function __construct(
        public string $source,
        public string $name,
        public string $url,
        public string $prefixPattern,
        public bool $completeObjects,
        public string $format = self::FORMAT_JSON,
        public ?string $sha256 = null,
    ) {}

    public function cachePath(): string
    {
        return dirname(__DIR__) . "/.cache/specs/{$this->source}/{$this->name}.{$this->format}";
    }

    /** @throws RuntimeException when the document is pinned by hash and $body isn't that document */
    public function verify(string $body): void
    {
        $actual = hash('sha256', $body);
        if ($this->sha256 !== null && !hash_equals($this->sha256, $actual)) {
            throw new RuntimeException(
                "{$this->source}/{$this->name} ({$this->url}) has changed: sha256 {$actual}, pinned {$this->sha256}. "
                . 'Review the new document, re-validate the fixtures and update the pin in tools/SpecCache.php.',
            );
        }
    }
}
