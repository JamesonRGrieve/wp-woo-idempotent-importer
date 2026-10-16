<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wix\Mapping;

use IdempotentImporter\Mapping\Unsupported;
use IdempotentImporter\Source\Node;

/**
 * A Wix rich content (Ricos) document as HTML: paragraphs, headings, lists, block quotes, code blocks,
 * dividers and images, with bold, italic, underline, strikethrough, superscript, subscript and links.
 * Text colour, size and font are presentation and aren't carried. Any other block (galleries, videos,
 * embeds, buttons, tables, polls, app embeds…) or text decoration has no HTML the importer writes exactly,
 * so the document is Unsupported rather than mirrored with something missing.
 */
final class RichContent
{
    /** Decorations that only style text; the text is kept without them. */
    private const PRESENTATION = ['COLOR', 'FONT_SIZE', 'FONT_FAMILY', 'FONT_WEIGHT'];
    /** Text decorations and the element each one is. */
    private const ELEMENTS = ['BOLD' => 'strong', 'ITALIC' => 'em', 'UNDERLINE' => 'u', 'STRIKETHROUGH' => 's', 'SUPERSCRIPT' => 'sup', 'SUBSCRIPT' => 'sub'];
    /** Blocks and the element each one is. */
    private const BLOCKS = ['PARAGRAPH' => 'p', 'BULLETED_LIST' => 'ul', 'ORDERED_LIST' => 'ol', 'LIST_ITEM' => 'li', 'BLOCKQUOTE' => 'blockquote'];
    private const MIN_HEADING = 1;
    private const MAX_HEADING = 6;
    private const DEFAULT_HEADING = 2;
    /** Where Wix serves a media file named only by its Wix Media id. */
    public const MEDIA_BASE = 'https://static.wixstatic.com/media/';

    /** @throws Unsupported for a block or decoration with no exact HTML */
    public static function html(?Node $document): string
    {
        return $document === null ? '' : self::nodes($document->list('nodes'));
    }

    /** @param list<Node> $nodes */
    private static function nodes(array $nodes): string
    {
        return implode('', array_map(self::node(...), $nodes));
    }

    private static function node(Node $node): string
    {
        $type = $node->string('type');
        if (isset(self::BLOCKS[$type])) {
            $element = self::BLOCKS[$type];

            return "<{$element}>" . self::nodes($node->list('nodes')) . "</{$element}>";
        }

        return match ($type) {
            'TEXT' => self::text($node->requiredChild('textData')),
            'HEADING' => self::heading($node),
            'CODE_BLOCK' => '<pre><code>' . self::nodes($node->list('nodes')) . '</code></pre>',
            'DIVIDER' => '<hr>',
            'IMAGE' => self::image($node->requiredChild('imageData')),
            default => throw new Unsupported("The post has a Wix {$type} block, which has no HTML the importer writes."),
        };
    }

    private static function heading(Node $node): string
    {
        $level = $node->child('headingData')?->optionalInt('level') ?? self::DEFAULT_HEADING;
        $level = min(self::MAX_HEADING, max(self::MIN_HEADING, $level));

        return "<h{$level}>" . self::nodes($node->list('nodes')) . "</h{$level}>";
    }

    private static function text(Node $text): string
    {
        $html = htmlspecialchars($text->optionalString('text') ?? '', ENT_QUOTES);
        foreach ($text->list('decorations') as $decoration) {
            $type = $decoration->string('type');
            if (in_array($type, self::PRESENTATION, true)) {
                continue;
            }
            if ($type === 'LINK') {
                $link = $decoration->requiredChild('linkData')->requiredChild('link');
                $url = $link->optionalString('url') ?? throw new Unsupported('The post links to an anchor within itself, which has no HTML the importer writes.');
                $html = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $html . '</a>';
                continue;
            }
            $element = self::ELEMENTS[$type] ?? throw new Unsupported("The post has Wix {$type} text, which has no HTML the importer writes.");
            $html = "<{$element}>{$html}</{$element}>";
        }

        return $html;
    }

    private static function image(Node $data): string
    {
        $source = $data->requiredChild('image')->requiredChild('src');
        $id = $source->optionalString('id');
        $url = $source->optionalString('url') ?? ($id === null ? null : self::MEDIA_BASE . $id)
            ?? throw new Unsupported('The post has an image with no source.');
        $img = '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . htmlspecialchars($data->optionalString('altText') ?? '', ENT_QUOTES) . '">';
        $link = $data->child('link')?->optionalString('url');

        return '<figure>' . ($link === null ? $img : '<a href="' . htmlspecialchars($link, ENT_QUOTES) . '">' . $img . '</a>') . '</figure>';
    }
}
