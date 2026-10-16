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
 * rejected. A source that publishes no machine-readable spec has one hand-authored from its official
 * reference docs and committed to this repository: its URL is `file://<path from the repository root>`,
 * it is read where it is (nothing is fetched or cached), and it is pinned by the commit that holds it.
 */
final readonly class SpecDocument
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_YAML = 'yaml';
    public const COMMITTED_SCHEME = 'file://';

    public function __construct(
        public string $source,
        public string $name,
        public string $url,
        public string $prefixPattern,
        public bool $completeObjects,
        public string $format = self::FORMAT_JSON,
        public ?string $sha256 = null,
    ) {}

    /** A hand-authored document committed at $path (relative to the repository root). */
    public static function committed(string $source, string $name, string $path, string $prefixPattern, bool $completeObjects): self
    {
        $format = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === self::FORMAT_YAML ? self::FORMAT_YAML : self::FORMAT_JSON;

        return new self($source, $name, self::COMMITTED_SCHEME . ltrim($path, '/'), $prefixPattern, $completeObjects, $format);
    }

    /** Whether the document is committed to this repository rather than fetched from its publisher. */
    public function isCommitted(): bool
    {
        return str_starts_with($this->url, self::COMMITTED_SCHEME);
    }

    /** Where the document is read from: the committed file itself, or its fetched copy in .cache/specs. */
    public function cachePath(): string
    {
        $root = dirname(__DIR__);

        return $this->isCommitted()
            ? $root . '/' . substr($this->url, strlen(self::COMMITTED_SCHEME))
            : $root . "/.cache/specs/{$this->source}/{$this->name}.{$this->format}";
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
