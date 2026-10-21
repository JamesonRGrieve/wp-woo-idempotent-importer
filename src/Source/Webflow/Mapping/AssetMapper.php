<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * An asset → a media library attachment: the hosted file (the original, not a responsive variant) with its alt
 * text. Its display and original file names, type, size and folder are kept with it.
 */
final class AssetMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['displayName', 'originalFileName', 'contentType', 'size', 'folderId', 'createdOn'];

    public function kind(): Kind
    {
        return Kind::File;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        return new Desired(Kind::File, $node->id(), [
            'download_url' => $node->string('hostedUrl'),
            'alt' => $node->optionalString('altText'),
            'asset' => $node->only(self::DETAILS),
        ]);
    }
}
