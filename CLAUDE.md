# wp-woo-idempotent-importer — Agent Operating Guide

AGPL-3.0-or-later WordPress plugin: a one-way, idempotent store → WooCommerce mirror kept current until
cutover, with one adapter per source platform (Shopify, Etsy, Square Online, BigCommerce, Squarespace, Wix,
Magento, Lightspeed eCom, Ecwid and Webflow), and of CRMs (HubSpot, Salesforce, Pipedrive, Zoho CRM) into FluentCRM and FluentBoards, and of accounting software (FreshBooks, Wave, QuickBooks Online) into both. README.md is the user-facing contract; `coverage/<source>.md`
is the single source of truth for what each source mirrors and what is a gap. PHP rules:
SOP *Stack Standards → PHP*.

## Layout

- `src/Source/` — the source boundary (pure): `Adapter` (one per platform, listed in `Adapters`),
  `SourceReader` (complete objects, paged), `Node` (validated reads), `Identity` (`gid://<source>/…`),
  `RetryLater` / `CredentialsRejected` (lockout safety, below), `Rest/` (the shared REST client),
  `OAuth2/` (the shared authorization-code grant: `OAuthApp` per vendor, `OAuth2Client`, `BearerAuthorizer`,
  tokens kept by `CredentialTokens`; an adapter that connects by OAuth delegates to an `OAuthConnection`).
- `src/Http/` — the outbound boundary: transport, `RateLimit` + `RequestPacer`, `RetryPolicy`.
- `src/Source/<Platform>/` — one adapter: its API client, reader and `Mapping/` (pure source → desired
  state, one mapper per kind). Shopify's GraphQL lives in `graphql/`; list, by-id and nested-page
  operations are generated per `EntityQuery` by `QueryLibrary`, never hand-written.
  Wave's GraphQL is its `Operations` (hand-written: a few lists), sent through the REST client's JSON POST.
- `src/Mapping/` — the source-neutral contract: `Desired`, `Mapper`, `Values`, `Hasher`, `Unsupported`.
- `src/Sync/` — `ObjectSync` (the one map → hash → write path), `Runner` (stepped runs), cursors, lock.
- `src/Store/` — the plugin's own tables (links, redirects, seen, runs) through `Sql` (`%i` identifiers).
- `src/Wp/` — WordPress side, shared by every source: one `Writer` per kind, shared helpers
  (`MetaSync`, `MediaLibrary`, `Posts`, `Comments`, `Terms`, `Quiet`, `Retirement`), scheduler,
  webhooks, storefront, admin, CLI. CRM data is written into FluentCRM through `Wp/Fluent/` (`FluentCrm`, the
  typed gateway to its models; `CustomFields`; `FluentMeta`): only its models, never its API helpers, so no
  FluentCRM event (automations, emails) fires; mappers speak `Mapping/CrmContract`. CRM pipelines, deals and tasks
  go into FluentBoards the same way (`FluentBoards`, `FluentBoardsMeta`; models saved quietly, so no FluentBoards
  notification or activity fires); mappers speak `Mapping/BoardContract`, and an owner is an assignee only when
  `OwnerUsers` finds exactly one WordPress user with the owner's email. `ContentTypes` registers the custom post type of each mirrored CMS
  collection (kept as an `iim_content_type` post).
- `src/StoredValue/` — the Square account gift cards and store credit are written to (`SquareStoredValue`),
  their numbers (`GiftCardNumber`) and how a balance change is applied (`GiftCardPlan`, pure); the
  ledger lives in `Store/GiftCardStore`, the writer in `Wp/Writer/GiftCardWriter`, the customer's page in
  `Wp/GiftCardAccount`. Fixtures for systems the importer writes to record whole request bodies, which
  `validate-fixtures` checks against the request schema too.
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
- A person with no email where WooCommerce or FluentCRM needs one gets `Mapping/PlaceholderEmail` (deterministic,
  `@<source>.invalid`, marked); `Wp/PlaceholderMail` drops every `.invalid` recipient from all mail, always.
- Lockout safety: every source client declares its documented limits as an `Http\RateLimit` (cited in a
  docblock; no guessed numbers) and goes through `RequestPacer` + `RetryPolicy`. A source that keeps
  pushing back is `RetryLater` (the run pauses in place, `PausePolicy`); rejected credentials are
  `CredentialsRejected` (the run stops, `CredentialHold` blocks scheduled work). Never retry either in a
  loop of your own.
- Credentials come from constants/env first; they are never rendered, logged or put in errors.
- Webhooks: a source that pushes them returns a `Source\WebhookReceiver` from `Adapter::webhooks()`
  (its signature check, in constant time, and the objects each event names). The endpoint only queues
  those objects; they are re-read through `ObjectSync` like a run.
- An object the source copied from WooCommerce itself (an order WooCommerce Square pushed to Square) names
  its original (`original_meta`); the writer refuses it (`OriginalInWooCommerce`), so it is never mirrored back.
- New Shopify GraphQL: add the fragment/operation, run `composer validate-graphql`, add or extend
  fixtures in `tests/fixtures/shopify/<state>/` (they must execute against the real schema). Etsy
  fixtures in `tests/fixtures/etsy/<state>/` must validate against Etsy's OpenAPI spec.
- New Wave GraphQL: change `Source/Wave/Operations`, run `composer validate-graphql` (which runs
  `tools/validate-wave.php` against Wave's schema, pinned by SHA-256 in `tools/WaveSchema.php` and fetched by
  `composer schema`), and add or extend `tests/fixtures/wave/<state>/` (each executes back through its operation).

## Testing

Pure layers are unit-tested over the recorded fixtures (`composer test`); the WordPress side is proven
end to end against real WordPress + WooCommerce + MariaDB (`composer e2e`), including that an unchanged
re-run leaves every content table byte-identical. `composer check` is the pre-commit gate.
