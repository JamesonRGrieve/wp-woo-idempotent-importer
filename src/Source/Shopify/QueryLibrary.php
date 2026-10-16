<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Source\SourceError;

/**
 * Every GraphQL document the importer sends. Hand-written operations live in graphql/operations; the
 * list, by-id and nested-page operations of each EntityQuery are generated from one template each. Every
 * fragment an operation spreads (transitively) is appended from graphql/fragments/<Name>.graphql.
 */
final class QueryLibrary
{
    /** Must equal the `first:` every fragment gives its nested connections. */
    public const NESTED_PAGE_SIZE = 250;

    private const SPREAD_PATTERN = '/\.\.\.\s*([A-Z][A-Za-z0-9_]*)/';

    /** @var array<string, string> operation name => operation body without fragments */
    private readonly array $bodies;

    /** @param array<string, EntityQuery> $sources */
    public function __construct(private readonly string $directory, array $sources)
    {
        $bodies = [];
        foreach (glob($directory . '/operations/*.graphql') ?: [] as $file) {
            $bodies[basename($file, '.graphql')] = $this->read('operations/' . basename($file));
        }
        foreach ($sources as $source) {
            $bodies[$source->listOperation()] = self::listBody($source);
            $bodies[$source->nodeOperation()] = self::nodeBody($source);
            foreach ($source->nested as $nested) {
                $bodies[$source->pageOperation($nested)] = self::pageBody($source, $nested);
            }
        }
        ksort($bodies);
        $this->bodies = $bodies;
    }

    public static function bundled(): self
    {
        return new self(dirname(__DIR__, 3) . '/graphql', EntityQueries::all());
    }

    /** @return list<string> */
    public function operations(): array
    {
        return array_keys($this->bodies);
    }

    public function document(string $operation): string
    {
        $body = $this->bodies[$operation] ?? throw new SourceError("Unknown GraphQL operation {$operation}");
        $included = [];
        $pending = $this->spreads($body);
        $fragments = '';
        while ($pending !== []) {
            $name = array_shift($pending);
            if (isset($included[$name])) {
                continue;
            }
            $included[$name] = true;
            $fragment = $this->read("fragments/{$name}.graphql");
            $fragments .= "\n" . $fragment;
            array_push($pending, ...$this->spreads($fragment));
        }

        return $body . $fragments;
    }

    private static function listBody(EntityQuery $source): string
    {
        $variables = '$first: Int!, $after: String' . ($source->searchable ? ', $query: String' : '');
        $arguments = 'first: $first, after: $after'
            . ($source->searchable ? ', query: $query' : '')
            . ($source->sortKey !== null ? ", sortKey: {$source->sortKey}" : '');

        return <<<GRAPHQL
            query {$source->listOperation()}({$variables}) {
              {$source->connection}({$arguments}) {
                pageInfo {
                  ...PageInfoFields
                }
                nodes {
                  ...{$source->fragment}
                }
              }
            }

            GRAPHQL;
    }

    private static function nodeBody(EntityQuery $source): string
    {
        return <<<GRAPHQL
            query {$source->nodeOperation()}(\$id: ID!) {
              {$source->nodeField}(id: \$id) {
                ...{$source->fragment}
              }
            }

            GRAPHQL;
    }

    private static function pageBody(EntityQuery $source, NestedConnection $nested): string
    {
        $size = self::NESTED_PAGE_SIZE;
        $extra = $nested->extraArguments !== '' ? ", {$nested->extraArguments}" : '';

        return <<<GRAPHQL
            query {$source->pageOperation($nested)}(\$id: ID!, \$after: String) {
              node(id: \$id) {
                ... on {$source->graphqlType} {
                  {$nested->field}(first: {$size}, after: \$after{$extra}) {
                    ...{$nested->fragment}
                  }
                }
              }
            }

            GRAPHQL;
    }

    /** @return list<string> */
    private function spreads(string $document): array
    {
        preg_match_all(self::SPREAD_PATTERN, $document, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function read(string $relative): string
    {
        $path = $this->directory . '/' . $relative;
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new SourceError("Missing GraphQL document {$relative}");
        }

        return trim($content) . "\n";
    }
}
