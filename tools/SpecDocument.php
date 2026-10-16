<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tools;

/**
 * One published API document a source's fixtures are validated against, pinned to an exact version
 * (a versioned URL or a commit), with the server path prefix its paths sit under and how strictly a
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
    ) {}

    public function cachePath(): string
    {
        return dirname(__DIR__) . "/.cache/specs/{$this->source}/{$this->name}.{$this->format}";
    }
}
