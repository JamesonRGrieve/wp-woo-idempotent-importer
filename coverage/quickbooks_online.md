<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: QuickBooks Online (Accounting API) → WooCommerce + FluentCRM

The single source of truth for what the QuickBooks Online adapter mirrors. QuickBooks is accounting software: what
has a faithful home in a store (what it sells, who it sells to, what it invoiced or sold and was paid) goes into
WooCommerce, its customers also into **FluentCRM** (free, GPL); the books are operator decisions (below). Intuit
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
| Customers | WooCommerce customers | Email (lower-cased), given and family name (else display name), phone (else mobile), notes, created time, billing address with the company name, shipping address. WooCommerce customers need an email: a customer without one is not a customer, and its sales are guest orders in its name and address. Display name, other phones, website, parent customer (a sub-customer or job), balance, currency, terms and tax details are meta. |
| Customers | FluentCRM contacts | Email, title, names, phone, billing address, created time, contact type customer, source "QuickBooks Online", status transactional (QuickBooks records no marketing consent), its company. A FluentCRM contact with the same email is adopted; a customer without an email is not a contact. |
| Customers with a company name | FluentCRM companies | Company name, phone, website, billing address, created time; business number and tax identifiers are meta. |
| Invoices with the payments applied to them | WooCommerce orders with QuickBooks' amounts | Number, date, currency (the transaction's, else the home currency), customer, billing email, billing and shipping addresses. Item lines (a bundle's own lines in its place): item, description, quantity (a fractional one listed on the line), unit price, amount; the shipping item's line is the shipping. Discount lines make the discount; QuickBooks' tax lines (by tax rate name, at its percentage) the taxes; its total the total; tax-inclusive amounts are kept so. Nothing left to pay → completed, paid on the last payment's date; otherwise pending payment. Each payment applied is a private order note (amount, method, reference, date, memo); the last one's method and reference are the order's. Due date, terms, PO number, memos, balance, class, location and statuses are meta. Mirroring a sale reduces no stock. |
| Sales receipts | Completed WooCommerce orders | As invoices, paid when made: their payment method and reference. |

## Planned (operator decisions)

| QuickBooks Online | Why, and the candidate home |
|---|---|
| Accounts, journal entries, deposits, transfers, purchases, bills, bill payments, vendors, vendor credits, purchase orders, employees, classes, departments | WordPress and WooCommerce have no ledger or purchasing. Decision: a WordPress accounting plugin (not yet chosen), or keep the books in an ERP (the sibling frappe-idempotent-importer is the ERPNext route). |
| Bundles (group items) | WooCommerce has no bundles in core; a bundles plugin is the candidate. A bundle sold on an invoice is mirrored as its own lines. |
| Credit memos and refund receipts | A credit memo is to a customer, not always to one invoice; a refund receipt refunds a sale. Decision: refunds on the order they are applied to, or customer meta. |
| Estimates | WooCommerce has no quotes; a quote plugin or draft orders are the candidates. |
| Tax rates and codes | WooCommerce tax rates are the store's own (by location): each order keeps the taxes QuickBooks charged. |
| Customers without an email address | WooCommerce and FluentCRM both key people by email: their sales are guest orders in their name and address. Decision: leave it so, or a placeholder email. |
| Attachments, custom fields, recurring transactions | Not read. |
| QuickBooks Desktop | No cloud API: its only supported route is the QuickBooks Web Connector, which runs beside QuickBooks Desktop on the Windows machine and polls a SOAP service the integration hosts, exchanging qbXML (developer.intuit.com, QuickBooks Desktop: Get started with QuickBooks Web Connector). Mirroring it means the plugin hosting that SOAP service and the operator running the Web Connector; it can't be proven here without QuickBooks Desktop. Decision: build that route, or move the company to QuickBooks Online first (Intuit's own migration) and mirror it from there. |

## Unverified against a live company

There are no live credentials: everything above is built from Intuit's data model, and no live company was read.
Intuit's developer documentation renders in the browser and could not be read directly; its limits, token lifetimes
and minor version were confirmed through search excerpts of its pages and Intuit's developer blog. Unverified: that
`Country` is free text (it is mirrored as sent; `CountryCode`, when present, is preferred); that a sale's `CurrencyRef`
is always present (the home currency is the fallback); and that a tax line's rate name is the tax rate's `Name`.
