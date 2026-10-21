<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Source\Node;

/**
 * What a CMS item (an article, or an item of any other collection) holds, read through its collection's field
 * definitions (`collection_fields`, which the reader adds): its name and slug, its first rich text field as the
 * body, its first image field as the featured image, and every other field by slug, kept as meta. Reference
 * fields keep the referenced items' identities.
 */
final readonly class ItemContent
{
    private const NAME = 'name';
    private const SLUG = 'slug';
    private const REFERENCE_TYPES = ['Reference', 'MultiReference'];

    /**
     * @param array{url: string, alt: ?string}|null $image
     * @param array<string, mixed> $fields
     */
    private function __construct(
        public string $title,
        public string $slug,
        public ?string $bodyHtml,
        public ?array $image,
        public array $fields,
    ) {}

    public static function of(Node $item): self
    {
        $data = $item->requiredChild('fieldData');
        $body = null;
        $image = null;
        $fields = [];
        foreach ($item->list('collection_fields') as $field) {
            $slug = $field->string('slug');
            if ($slug === self::NAME || $slug === self::SLUG || !array_key_exists($slug, $data->raw())) {
                continue;
            }
            $type = $field->string('type');
            if ($type === Fields::RICH_TEXT && $body === null) {
                $body = $data->optionalString($slug) ?? '';
                continue;
            }
            if ($type === Fields::IMAGE && $image === null && $data->child($slug) !== null) {
                $picture = $data->requiredChild($slug);
                $image = ['url' => $picture->string('url'), 'alt' => $picture->optionalString('alt')];
                continue;
            }
            $value = $data->raw()[$slug];
            $fields[$slug] = in_array($type, self::REFERENCE_TYPES, true) ? self::references($value) : $value;
        }

        return new self($data->string(self::NAME), $data->string(self::SLUG), $body, $image, $fields);
    }

    /** A reference field's item ids as identities. */
    private static function references(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => Fields::id('Item', $value),
            is_array($value) => array_map(static fn(mixed $id): mixed => is_string($id) ? Fields::id('Item', $id) : $id, $value),
            default => $value,
        };
    }
}
