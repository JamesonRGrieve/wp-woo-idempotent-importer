<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Shopify;

use IdempotentImporter\Source\SourceError;

/**
 * Every GraphQL document the importer sends. Hand-written operations live in graphql/operations; the
 * list, by-id and nested-page operations of each EntityQuery are generated from one template each. Every
 * fragment an operation spreads (transitively) is appended from graphql/fragments/<Name>.graphql.
 *
 * Every page size a generated operation requests is an `Int!` variable: `$first` for the operation's own
 * connection and NestedConnection::sizeVariable() for each nested connection its node fragment pages
 * through. pageSizes() gives their defaults; the client shrinks them when Shopify refuses a query as too
 * costly (PageSizes). Connections nothing pages through keep a literal `first: 250` (NESTED_PAGE_SIZE):
 * shrinking those would truncate them.
 */
final class QueryLibrary
{
    /** The default number of objects a list operation asks for. */
    public const PAGE_SIZE = 50;

    /** Shopify's largest page: the default for nested connections, and the literal size of the unpaged ones. */
    public const NESTED_PAGE_SIZE = 250;

    public const FIRST_VARIABLE = 'first';

    private const SPREAD_PATTERN = '/\.\.\.\s*([A-Z][A-Za-z0-9_]*)/';

    /** @var array<string, string> operation name => operation body without fragments */
    private readonly array $bodies;

    /** @var array<string, array<string, int>> operation name => size variable => default page size */
    private readonly array $sizes;

    /** @param array<string, EntityQuery> $sources */
    public function __construct(private readonly string $directory, array $sources)
    {
        $bodies = [];
        $sizes = [];
        foreach (glob($directory . '/operations/*.graphql') ?: [] as $file) {
            $bodies[basename($file, '.graphql')] = $this->read('operations/' . basename($file));
        }
        foreach ($sources as $source) {
            $nestedSizes = [];
            foreach ($source->nested as $nested) {
                $nestedSizes[$nested->sizeVariable()] = self::NESTED_PAGE_SIZE;
                $bodies[$source->pageOperation($nested)] = self::pageBody($source, $nested);
                $sizes[$source->pageOperation($nested)] = [self::FIRST_VARIABLE => self::NESTED_PAGE_SIZE];
            }
            $bodies[$source->listOperation()] = self::listBody($source);
            $sizes[$source->listOperation()] = [self::FIRST_VARIABLE => self::PAGE_SIZE, ...$nestedSizes];
            $bodies[$source->nodeOperation()] = self::nodeBody($source);
            $sizes[$source->nodeOperation()] = $nestedSizes;
        }
        ksort($bodies);
        $this->bodies = $bodies;
        $this->sizes = $sizes;
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

    /**
     * The default page size of every size variable the operation declares (none for hand-written ones).
     *
     * @return array<string, int>
     */
    public function pageSizes(string $operation): array
    {
        if (!isset($this->bodies[$operation])) {
            throw new SourceError("Unknown GraphQL operation {$operation}");
        }

        return $this->sizes[$operation] ?? [];
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
        $variables = '$first: Int!, $after: String' . ($source->searchable ? ', $query: String' : '') . self::nestedSizeVariables($source);
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
        $variables = '$id: ID!' . self::nestedSizeVariables($source);

        return <<<GRAPHQL
            query {$source->nodeOperation()}({$variables}) {
              {$source->nodeField}(id: \$id) {
                ...{$source->fragment}
              }
            }

            GRAPHQL;
    }

    private static function pageBody(EntityQuery $source, NestedConnection $nested): string
    {
        $extra = $nested->extraArguments !== '' ? ", {$nested->extraArguments}" : '';

        return <<<GRAPHQL
            query {$source->pageOperation($nested)}(\$id: ID!, \$after: String, \$first: Int!) {
              node(id: \$id) {
                ... on {$source->graphqlType} {
                  {$nested->field}(first: \$first, after: \$after{$extra}) {
                    ...{$nested->fragment}
                  }
                }
              }
            }

            GRAPHQL;
    }

    /** The `, $<field>First: Int!` declarations of the source's nested connections, used by its node fragment. */
    private static function nestedSizeVariables(EntityQuery $source): string
    {
        $declarations = '';
        foreach ($source->nested as $nested) {
            $declarations .= ", \${$nested->sizeVariable()}: Int!";
        }

        return $declarations;
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
