<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

use IdempotentImporter\Config\Settings;
use IdempotentImporter\Http\HttpTransport;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;

/**
 * One source platform: how to read it and how its objects map to desired WordPress state. Everything
 * after mapping (identity, hashing, writers, retirement, redirects) is shared by every adapter.
 */
interface Adapter
{
    /** Stable, persisted id; also the host part of every identity this adapter mints. Never rename one. */
    public function id(): string;

    public function label(): string;

    /**
     * The kinds this source provides, in Kind::SYNC_ORDER.
     *
     * @return list<Kind>
     */
    public function kinds(): array;

    /** @return list<Mapper> one per kind in kinds() */
    public function mappers(Settings $settings): array;

    /** @throws SourceError when the source's credentials aren't configured */
    public function reader(Settings $settings, HttpTransport $transport): SourceReader;

    /** Whether the source pushes change notifications (then the webhook endpoint is registered). */
    public function supportsWebhooks(): bool;

    /** An example of the per-kind filter syntax runs pass to the reader, or null when filters aren't supported. */
    public function filterHint(): ?string;

    /**
     * The secrets the operator provides for this source, as Credentials names → admin field labels.
     *
     * @return array<string, string>
     */
    public function credentialFields(): array;
}
