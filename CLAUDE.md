# wp-woo-idempotent-importer — Agent Operating Guide

AGPL-3.0-or-later WordPress plugin: a one-way, idempotent Shopify → WooCommerce mirror kept current
until cutover. README.md is the user-facing contract; COVERAGE.md is the single source of truth for what
is mirrored and what is a gap. PHP rules: `/home/jameson/Source/ai-prompts/php.md`.

## Layout

- `graphql/` — fragments and hand-written operations. List, by-id and nested-page operations are
  generated per `Shopify\Source` by `QueryLibrary`; never hand-write one of those.
- `src/Shopify/` — the Admin API boundary (pure): client (throttle/retry), `Reader` (paging, nested
  completion, truncation guard), `ShopifyNode` (validated reads), sources, webhooks.
- `src/Mapping/` — pure Shopify → desired-state mappers, one per kind, plus shared readers (`Fields`,
  `OrderParts`, `Values`). No WordPress.
- `src/Sync/` — `ObjectSync` (the one map → hash → write path), `Runner` (stepped runs), cursors, lock.
- `src/Store/` — the plugin's own tables (links, redirects, seen, runs) through `Sql` (`%i` identifiers).
- `src/Wp/` — WordPress side: one `Writer` per kind, shared helpers (`MetaSync`, `MediaLibrary`,
  `Posts`, `Comments`, `Terms`, `Quiet`, `Retirement`), scheduler, webhooks, storefront, admin, CLI.
- `src/Services.php` — the composition root; nothing else constructs dependencies.

## Rules

- Idempotent by construction: identity is (kind, Shopify GID) in `iim_links`; the change signal is the
  hash of the mapped fields and the mapper's `version()`. Bump a mapper's version whenever its output
  changes. Never key on titles, slugs or handles (reviews, which have no Shopify id, are the exception).
- Writers write only mapped fields. Fields with no native home go through `MetaSync::mirror` as
  `_mirror_<field>`; never drop a mapped field.
- Nothing mirrored is ever deleted permanently: retire = trash where WordPress has one, else mark
  `_mirror_deleted`.
- Something WooCommerce can't represent exactly is `Unsupported` (and a COVERAGE.md gap), never
  approximated. A missing dependency is `UnresolvedDependency` (retried), never skipped.
- Every write runs inside `Quiet` (no emails, no stock or coupon side effects).
- Credentials come from constants/env first; they are never rendered, logged or put in errors.
- New GraphQL: add the fragment/operation, run `composer validate-graphql`, add or extend fixtures in
  `tests/fixtures/shopify/<state>/` (they must execute against the real schema).

## Testing

Pure layers are unit-tested over the recorded fixtures (`composer test`); the WordPress side is proven
end to end against real WordPress + WooCommerce + MariaDB (`composer e2e`), including that an unchanged
re-run leaves every content table byte-identical. `composer check` is the pre-commit gate.
