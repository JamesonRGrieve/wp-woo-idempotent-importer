<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: QuickBooks Online (Accounting API) → WooCommerce + FluentCRM

The single source of truth for what the QuickBooks Online adapter mirrors. QuickBooks is accounting software: what
has a faithful home in a store (what it sells, who it sells to, what it invoiced or sold and was paid) goes into
WooCommerce, its customers also into **FluentCRM** (free, GPL); the books go to ERPNext only (operator decision of 2026-10-21; below). Intuit
publishes its data model as XML Schemas in its official Java SDK (intuit/QuickBooks-V3-Java-SDK,
`ipp-v3-java-data/src/main/xsd`, Apache-2.0), and the JSON API returns the same types; the answers the adapter reads
are built from those types at commit `c4d5dfad23ffebf0302356876d0dc389708513a9` (`tests/schemas/quickbooks_online.json`:
each type an object, nothing required, no undeclared key), and every fixture is validated against them. **Planned**
is work the importer doesn't do yet; each is an operator decision on the Deck card.

## Access

Create an app on developer.intuit.com with the settings page's callback URL as a redirect URI, enter its client ID
and secret (and the sandbox API host for a sandbox company), save, and click **Connect to QuickBooks Online**. OAuth 2:
authorization at `https://appcenter.intuit.com/connect/oauth2` with the `com.intuit.quickbooks.accounting` scope
(Intuit has no read-only scope; the importer only reads), tokens at
`https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer` with HTTP Basic client authentication; the redirect names
the company's realm id, kept with the tokens. Access tokens last an hour; refresh tokens about 100 days, and a refresh
may replace the refresh token (the newest is always kept). Rejected credentials stop the run and hold scheduled runs.

Reading: the query endpoint (`/v3/company/<realm>/query`, minor version 75, the lowest Intuit has answered since
August 2025), `SELECT * FROM <Entity>` in creation order, 1,000 a page (the most a query returns); items, customers
and tax rates with `Active IN (true, false)` (a query returns only active ones otherwise); the home currency from
`/preferences`. Nothing is incremental (unchanged records are skipped by hash). Intuit allows 500 requests a minute and
10 a second per company and app, and answers 429 (asking for 60 seconds) when throttling (developer.intuit.com, API
call limits and throttles): requests are paced at 450 a minute, and a persistent 429 pauses the run.

## Mirrored

| QuickBooks Online | Home | Notes |
|---|---|---|
| Item categories (items of type Category) | WooCommerce product categories | Name; the parent category and full name are meta. |
| Inventory, non-inventory and service items | Simple WooCommerce products | Name, description, sales price, SKU (optional generated `qbo-<id>`), purchase cost as the cost, taxable, category, created time. An inventory item that tracks quantity has managed stock at its quantity on hand; others are unmanaged. A service is virtual; an inactive item private. Purchase description, accounts, tax codes, reorder point and preferred vendor are meta. |
| Customers | WooCommerce customers | Email (lower-cased), given and family name (else display name), phone (else mobile), notes, created time, billing address with the company name, shipping address. Display name, other phones, website, parent customer (a sub-customer or job), balance, currency, terms and tax details are meta. |
| Customers | FluentCRM contacts | Email, title, names, phone, billing address, created time, contact type customer, source "QuickBooks Online", status transactional (QuickBooks records no marketing consent), its company. A FluentCRM contact with the same email is adopted. |
| Customers with a company name | FluentCRM companies | Company name, phone, website, billing address, created time; business number and tax identifiers are meta. |
| Invoices with the payments applied to them | WooCommerce orders with QuickBooks' amounts | Number, date, currency (the transaction's, else the home currency), customer, billing email, billing and shipping addresses. Item lines (a bundle's own lines in its place): item, description, quantity (a fractional one listed on the line), unit price, amount; the shipping item's line is the shipping. Discount lines make the discount; QuickBooks' tax lines (by tax rate name, at its percentage) the taxes; its total the total; tax-inclusive amounts are kept so. Nothing left to pay → completed, paid on the last payment's date; otherwise pending payment. Each payment applied is a private order note (amount, method, reference, date, memo); the last one's method and reference are the order's. Due date, terms, PO number, memos, balance, class, location and statuses are meta. Mirroring a sale reduces no stock. |
| Sales receipts | Completed WooCommerce orders | As invoices, paid when made: their payment method and reference. |
| Credit memos applied to invoices | WooCommerce refunds on the invoices' orders | Operator decision of 2026-10-21. A payment line linking a credit memo applied that much of it to the invoice the payment pays: a refund of that amount on the invoice's order, dated with the payment, with the memo's number as the reason and its own lines kept on the refund (`_mirror_credit_lines`). A payment that pays several invoices doesn't say which got the credit: that application is not guessed, it is logged as a warning (and still spent from the store credit). Nothing is restocked; the order keeps its status. |
| Refund receipts | WooCommerce refunds on their sale's order | The sale the receipt links (`LinkedTxn`, a sales receipt or invoice): a refund of its total, with its payment method in the reason and its lines kept on the refund. One that links no sale has no order to refund: it is not mirrored, and the log says so. |
| What is left of a customer's credit memos | Their store credit in **Square Gift Cards** | Through the gift card writer (switch the `gift_card` kind on): one card per customer, linked to their WooCommerce customer, with a new number shown in My Account; its balance is each memo's total less every application (QuickBooks' RemainingCredit), in the memos' currency (else the home currency); its ledger each memo issued and each application. Increases are held unless allowed, as for every card; a placeholder email is never given to Square. |
| Customers without an email address | A WooCommerce customer and a FluentCRM contact with a placeholder address | WooCommerce and FluentCRM keep people by email, so one without gets `customer-<id>@quickbooks-online.invalid` (operator decision of 2026-10-21): the same every run, under `.invalid`, which RFC 2606 reserves so it can never be delivered, and marked `_mirror_email_placeholder`. No mail is ever sent to it: every `.invalid` recipient is dropped before WordPress's mailer, whatever sends it, and password resets skip it. Status follows the source's consent as for anyone else. |

## Planned, ERPNext only and out of scope (operator decisions)

| QuickBooks Online | Why, and the candidate home |
|---|---|
| Accounts, journal entries, deposits, transfers, purchases, expenses, bills, bill payments, vendors, vendor credits, purchase orders, employees, classes, departments | ERPNext only (operator decision of 2026-10-21): the books are never written to WordPress; the sibling frappe-idempotent-importer mirrors them into ERPNext. |
| Bundles (group items) | Planned on Deck card 394: the `wp-woo-bundles` companion (operator decision of 2026-10-21), which is still a scaffold with no data model or PHP API to write into. Until it has one, a bundle sold on an invoice is mirrored as its own lines and the group item itself is not a product. |
| Estimates | Planned: a `wp-woo-quotes` companion. No reputable GPL WooCommerce quote plugin holds an estimate faithfully (researched 2026-10-21): YITH Request a Quote (free, GPLv2+, 10,000+ installs) keeps no quote record in its free version (requests are emailed; stored quotes, statuses and order conversion are Premium); Quotes for WooCommerce (GPLv3, 4,000+ installs, updated 2026-06) makes a quote a WooCommerce order with a `_quote_status` of pending, sent or complete, so it has no accepted, declined or expired state and the quote and its invoice can't both exist; CatalogX (MultiVendorX, GPLv2+, 5,000+ installs) registers accepted, rejected and expired quote order statuses but its free version has no expiry or conversion to an order, and no PHP API; NP Quote Request (GPLv2, 8,000+ installs) has only requested and sent. None has an API for creating a quote idempotently. An estimate needs its lines, totals, expiry, accepted/declined state and its conversion to the invoice that is mirrored as an order. |
| Tax rates and codes | WooCommerce tax rates are the store's own (by location): each order keeps the taxes QuickBooks charged. |
| Attachments, custom fields, recurring transactions | Not read. |
| QuickBooks Desktop | Out of scope (operator decision of 2026-10-21): QuickBooks is mirrored from QuickBooks Online only. |

## Unverified against a live company

There are no live credentials: everything above is built from Intuit's data model, and no live company was read.
Intuit's developer documentation renders in the browser and could not be read directly; its limits, token lifetimes
and minor version were confirmed through search excerpts of its pages and Intuit's developer blog. Unverified: that
`Country` is free text (it is mirrored as sent; `CountryCode`, when present, is preferred); that a sale's `CurrencyRef`
is always present (the home currency is the fallback); that a tax line's rate name is the tax rate's `Name`; that a
credit memo applied to an invoice is a payment with a line linking the credit memo beside the invoice's line (Intuit's
guides show it so); and that a refund receipt links the sale it refunds in `LinkedTxn` (the data model allows it; a
refund receipt made from scratch links none).
