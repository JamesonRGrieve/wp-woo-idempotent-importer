<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Wave → WooCommerce + FluentCRM

The single source of truth for what the Wave adapter mirrors. Wave is accounting software: what has a faithful
home in a store (what it sells, who it sells to, what it invoiced and was paid) goes into WooCommerce, its customers
also into **FluentCRM** (free, GPL); the books go to ERPNext only (operator decision of 2026-10-21; below). Wave's API is GraphQL
(gql.waveapps.com/graphql/public). Its schema is introspected from that endpoint (which answers introspection
without a token) by `composer schema` and pinned by its SHA-256 (tools/WaveSchema.php, 2026-10-21);
`tools/validate-wave.php` validates every operation (src/Source/Wave/Operations.php) against it and executes every
fixture back through its operation, so each fixture has exactly the shape Wave's schema gives it. **Planned** is
work the importer doesn't do yet; each is an operator decision on the Deck card.

## Access

Create an application in Wave's developer portal (developer.waveapps.com, Manage Applications) with the settings
page's callback URL as its redirect URI, enter its client ID and secret, save, and click **Connect to Wave**. OAuth 2:
authorization at `https://api.waveapps.com/oauth2/authorize/` with the scopes `business:read`, `product:read`,
`customer:read`, `invoice:read` and `sales_tax:read` (Wave's `<resource>:read` scopes); tokens at
`https://api.waveapps.com/oauth2/token/`, form-encoded with the client ID and secret; the token response carries a
long-lived refresh token, and a refresh invalidates the previous access token. Wave opens its API only to businesses
on a paid plan (Pro or Advisor). Rejected credentials, or an answer Wave marks unauthenticated or forbidden, stop
the run and hold scheduled runs.

The business mirrored is one the connection can open (`businesses`): the one whose id is entered, else the only
one (several and none entered is an error naming them).

Lists are read 45 a page (Wave's default), oldest first. Incremental runs ask for what changed since the last run
(`modifiedAtAfter`) of products, customers and invoices; deletions come only from a full pass. Requests are paced
at one a second; a 429 is retried with capped backoff, then pauses the run.

## Mirrored

| Wave | Home | Notes |
|---|---|---|
| Products and services | Simple WooCommerce products | Name, description, unit price, created time. Wave counts no stock: unmanaged. No SKU (optional generated `wave-<id>`). A product Wave doesn't sell (bought only) or an archived one (read with a second, archived list) is private. Sold and bought flags, default sales taxes and income and expense accounts are meta. |
| Customers | WooCommerce customers | Email (lower-cased), first and last name (else the name), phone (else mobile), created time, address as billing (with a business's name as company), shipping details as shipping. Account number, fax, toll-free, website, internal notes, currency and archived flag are meta. |
| Customers | FluentCRM contacts | Email, name, phone, address, created time, contact type customer, source "Wave", status transactional (Wave records no marketing consent), its business as company. A FluentCRM contact with the same email is adopted. |
| Business customers | FluentCRM companies | Wave keeps one name per customer beside its contact person's first and last names; a name that is not the person's is the business's: a company with that name, phone, website, address and created time. |
| Invoices | WooCommerce orders with Wave's amounts | Number, invoice date, currency, customer, the customer's name and address. Items: product, description, quantity (a fractional one is listed on the line), subtotal, and the taxes Wave computed on each, by sales tax abbreviation at its rate. Wave's discount total, tax total and total. Status: paid or overpaid → completed (on the last payment's date); anything else → pending payment. Each active payment is a private order note (amount, method, date, memo); the last one's method and transaction id are the order's; a removed payment is left out. Title, subhead, PO number, due date, memo, footer, discounts, exchange rate and amounts paid and due are meta. Mirroring an invoice reduces no stock. |
| Customers without an email address | A WooCommerce customer and a FluentCRM contact with a placeholder address | WooCommerce and FluentCRM keep people by email, so one without gets `customer-<id>@wave.invalid` (operator decision of 2026-10-21): the same every run, under `.invalid`, which RFC 2606 reserves so it can never be delivered, and marked `_mirror_email_placeholder`. No mail is ever sent to it: every `.invalid` recipient is dropped before WordPress's mailer, whatever sends it, and password resets skip it. Status follows the source's consent as for anyone else. |

## Planned, ERPNext only and out of scope (operator decisions)

| Wave | Why, and the candidate home |
|---|---|
| Chart of accounts | ERPNext only (operator decision of 2026-10-21): the books are never written to WordPress; the sibling frappe-idempotent-importer mirrors them into ERPNext. |
| Bills, expenses, receipts, bank and journal transactions | ERPNext only (operator decision of 2026-10-21): the books are never written to WordPress; the sibling frappe-idempotent-importer mirrors them into ERPNext. Wave's public API reads none of them anyway (the schema has only `moneyTransactionCreate` and its kin, which write). |
| Vendors | ERPNext only (operator decision of 2026-10-21): the books are never written to WordPress; the sibling frappe-idempotent-importer mirrors them into ERPNext. |
| Sales taxes | WooCommerce tax rates are the store's own (by location), not a document's: each order keeps the taxes Wave charged; mirroring Wave's rate table into WooCommerce's is a decision. |
| Estimates | Planned: a `wp-woo-quotes` companion. No reputable GPL WooCommerce quote plugin holds an estimate faithfully (researched 2026-10-21): YITH Request a Quote (free, GPLv2+, 10,000+ installs) keeps no quote record in its free version (requests are emailed; stored quotes, statuses and order conversion are Premium); Quotes for WooCommerce (GPLv3, 4,000+ installs, updated 2026-06) makes a quote a WooCommerce order with a `_quote_status` of pending, sent or complete, so it has no accepted, declined or expired state and the quote and its invoice can't both exist; CatalogX (MultiVendorX, GPLv2+, 5,000+ installs) registers accepted, rejected and expired quote order statuses but its free version has no expiry or conversion to an order, and no PHP API; NP Quote Request (GPLv2, 8,000+ installs) has only requested and sent. None has an API for creating a quote idempotently. An estimate needs its lines, totals, expiry, accepted/declined state and its conversion to the invoice that is mirrored as an order. |
| Payment refunds, estimate deposits, recurring invoices, reminders, attachments | Not read. |

## Unverified against a live business

There are no live credentials: everything above is built from Wave's schema, and no live business was read. Wave's
help centre (developer.waveapps.com) refuses automated reads, so its OAuth guide and any request limits were read
only through search excerpts and a third-party guide (which reports 60 requests a minute per token and access tokens
lasting two hours): requests are paced well under that. Unverified: that a refresh takes the redirect URI (it is
sent); that province codes are ISO 3166-2 (`CA-BC`; the subdivision part is kept, a bare code as is); that an
invoice item's `subtotal` is quantity × unit price before the invoice's discount; and that `payments` lists every
payment on an invoice, removed ones marked inactive.
