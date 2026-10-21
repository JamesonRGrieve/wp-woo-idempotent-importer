<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * A static page → a WordPress page: its title, slug, SEO title and description, and its content as the page's
 * DOM gives it, in order: text nodes as their HTML, images (with their asset's URL and alt text), and the text
 * a component instance overrides. Form fields and buttons have no content of their own and are kept as meta,
 * as are Open Graph fields. Drafts are drafts and archived pages private. The page's published path redirects
 * to it. Webflow's layout and styles are the Designer's and have no API; the page keeps its content, not its
 * design.
 */
final class PageMapper implements Mapper
{
    private const VERSION = 1;
    private const CONTENT_OVERRIDES = ['Plain Text', 'Rich Text'];

    public function kind(): Kind
    {
        return Kind::Page;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        $html = [];
        $controls = [];
        $assets = $node->child('assets');
        foreach ($node->list('dom_nodes') as $element) {
            match ($element->string('type')) {
                'text' => $html[] = self::text($element->requiredChild('text')),
                'image' => $html[] = self::image($element->requiredChild('image'), $assets),
                'component-instance' => array_push($html, ...self::overrides($element)),
                default => $controls[] = $element->raw(),
            };
        }
        $path = $node->optionalString('publishedPath');

        return new Desired(Kind::Page, $node->id(), [
            'title' => $node->optionalString('title') ?? $node->string('slug'),
            'slug' => $node->optionalString('slug') ?? '',
            'content_html' => implode("\n", array_filter($html, static fn(string $part): bool => $part !== '')),
            'status' => match (true) {
                ($node->raw()['archived'] ?? false) === true => 'private',
                ($node->raw()['draft'] ?? false) === true => 'draft',
                default => 'publish',
            },
            'published_at' => null,
            'seo' => ['title' => $node->child('seo')?->optionalString('title'), 'description' => $node->child('seo')?->optionalString('description')],
            'open_graph' => $node->child('openGraph')?->raw(),
            'form_controls' => $controls,
        ], $path === null || $path === '/' ? [] : [$path]);
    }

    private static function text(Node $text): string
    {
        return $text->optionalString('html') ?? htmlspecialchars($text->optionalString('text') ?? '', ENT_QUOTES);
    }

    private static function image(Node $image, ?Node $assets): string
    {
        $asset = $assets?->child($image->optionalString('assetId') ?? '');
        $url = $asset?->optionalString('url');
        if ($url === null) {
            return '';
        }
        $alt = $image->optionalString('alt') ?? $asset->optionalString('alt') ?? '';

        return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '">';
    }

    /** @return list<string> the text a component instance's overrides give it */
    private static function overrides(Node $instance): array
    {
        $parts = [];
        foreach ($instance->list('propertyOverrides') as $override) {
            if (in_array($override->string('type'), self::CONTENT_OVERRIDES, true)) {
                $parts[] = self::text($override->requiredChild('text'));
            }
        }

        return $parts;
    }
}
