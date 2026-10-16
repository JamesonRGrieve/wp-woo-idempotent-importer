# wp-woo-idempotent-importer — Agent Operating Guide

AGPL-3.0-or-later WordPress plugin: a one-way, idempotent store → WooCommerce mirror kept current until
cutover, with one adapter per source platform (Shopify, Etsy, Square Online and BigCommerce built;
Squarespace, Wix, Ecwid and Magento deferred). README.md is the user-facing contract; `coverage/<source>.md`
is the single source of truth for what each source mirrors and what is a gap. PHP rules:
`/home/jameson/Source/ai-prompts/php.md`.

## Layout

- `src/Source/` — the source boundary (pure): `Adapter` (one per platform, listed in `Adapters`),
  `SourceReader` (complete objects, paged), `Node` (validated reads), `Identity` (`gid://<source>/…`),
  `DeferredAdapter` (placeholder for a planned platform).
- `src/Source/<Platform>/` — one adapter: its API client, reader and `Mapping/` (pure source → desired
  state, one mapper per kind). Shopify's GraphQL lives in `graphql/`; list, by-id and nested-page
  operations are generated per `EntityQuery` by `QueryLibrary`, never hand-written.
- `src/Mapping/` — the source-neutral contract: `Desired`, `Mapper`, `Values`, `Hasher`, `Unsupported`.
- `src/Sync/` — `ObjectSync` (the one map → hash → write path), `Runner` (stepped runs), cursors, lock.
- `src/Store/` — the plugin's own tables (links, redirects, seen, runs) through `Sql` (`%i` identifiers).
- `src/Wp/` — WordPress side, shared by every source: one `Writer` per kind, shared helpers
  (`MetaSync`, `MediaLibrary`, `Posts`, `Comments`, `Terms`, `Quiet`, `Retirement`), scheduler,
  webhooks, storefront, admin, CLI.
- `src/Services.php` — the composition root; nothing else constructs dependencies.

## Rules

- Idempotent by construction: identity is (kind, `gid://<source>/<Type>/<id>`) in `iim_links`; the change
  signal is the hash of the mapped fields and the mapper's `version()`. Bump a mapper's version whenever
  its output changes. Never key on titles, slugs or handles; an object with no id at its source gets a
  stable id from its content.
- Writers speak WooCommerce, never a source's vocabulary: each mapper translates its platform's enums
  and units (weight units, statuses, policy roles, transaction ids) into the shared field contract.
- A source's runs only ever retire that source's links (`Identity::prefix`), never another source's.
- Writers write only mapped fields. Fields with no native home go through `MetaSync::mirror` as
  `_mirror_<field>`; never drop a mapped field.
- Nothing mirrored is ever deleted permanently: retire = trash where WordPress has one, else mark
  `_mirror_deleted`.
- Something WooCommerce can't represent exactly is `Unsupported` (and a coverage gap), never
  approximated. A missing dependency is `UnresolvedDependency` (retried), never skipped.
- Every write runs inside `Quiet` (no emails, no stock or coupon side effects).
- Credentials come from constants/env first; they are never rendered, logged or put in errors.
- New Shopify GraphQL: add the fragment/operation, run `composer validate-graphql`, add or extend
  fixtures in `tests/fixtures/shopify/<state>/` (they must execute against the real schema). Etsy
  fixtures in `tests/fixtures/etsy/<state>/` must validate against Etsy's OpenAPI spec.

## Testing

Pure layers are unit-tested over the recorded fixtures (`composer test`); the WordPress side is proven
end to end against real WordPress + WooCommerce + MariaDB (`composer e2e`), including that an unchanged
re-run leaves every content table byte-identical. `composer check` is the pre-commit gate.
