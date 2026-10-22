<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: FreshBooks → WooCommerce + FluentCRM

The single source of truth for what the FreshBooks adapter mirrors. FreshBooks is accounting software: what has a
faithful home in a store (what it sells, who it sells to, what it invoiced and was paid) goes into WooCommerce, its
clients also into **FluentCRM** (free, GPL); the books themselves are operator decisions (below). FreshBooks
publishes no OpenAPI document, so the answers the adapter reads are transcribed from its API reference
(`tests/schemas/freshbooks.json`, each path citing its page under www.freshbooks.com/api/) and every fixture is
validated against them. **Planned** is work the importer doesn't do yet; each is an operator decision on the Deck
card.

## Access

Create an app in FreshBooks' developer portal (my.freshbooks.com/#/developer) with the settings page's callback
URL as its redirect URI and the scopes `user:profile:read`, `user:clients:read`, `user:invoices:read`,
`user:payments:read` and `user:billable_items:read`; enter its client ID and secret, save, and click **Connect to
FreshBooks**. Authorization is at `https://auth.freshbooks.com/oauth/authorize`; tokens are traded at
`https://api.freshbooks.com/auth/oauth/token` with JSON requests that name the redirect URI, refreshes too
(www.freshbooks.com/api/authentication). Access tokens are short-lived; refresh tokens are used once each, and the
new one is saved before the access token is used. Rejected credentials stop the run and hold scheduled runs.

The business mirrored is one the login is a member of (`/auth/api/v1/users/me`): the one whose account ID is
entered, else the only one (a login with several and none entered is an error naming them).

FreshBooks puts no limit on requests a day but rate limits "too many calls within a short period"
(www.freshbooks.com/api/limits), without a number. Requests are paced at two a second; a 429 is retried with
capped backoff, then pauses the run.

Lists are read 100 a page (FreshBooks' cap). Deleted records (`vis_state` 1) are left out, so a full pass retires
them. Nothing is incremental (unchanged records are skipped by hash). Amounts are exact decimals.

## Mirrored

| FreshBooks | Home | Notes |
|---|---|---|
| Items | Simple WooCommerce products | Name, description, unit cost as the price, SKU (optional generated `freshbooks-<id>`), inventory as managed stock (none: unmanaged); archived is private. Default taxes, quantity multiplier and accounting id are meta. |
| Services | Simple virtual WooCommerce products | Name, rate as the price (read per service); archived is private. Billable is meta. |
| Clients | WooCommerce customers | Email (lower-cased), first and last name, phone (business, else mobile, else home), billing address with the organization as its company. WooCommerce customers need an email, so a client without one is not a customer: its invoices are guest orders in its name and address. Fax, VAT name and number, currency, language, note, sign-up time and accounting id are meta. |
| Clients | FluentCRM contacts | Email, name, phone, billing address, contact type customer, source "FreshBooks", status transactional (FreshBooks records no marketing consent), organization as company. A FluentCRM contact with the same email is adopted; a client without an email is not a contact. Other phones, shipping address and the rest are meta. |
| Client organizations | FluentCRM companies | FreshBooks keeps an organization as a client's business name: the clients naming the same one (case and spacing aside) share one company, with the first such client's phone and address. |
| Invoices with their payments | WooCommerce orders with FreshBooks' amounts | Number, issue date, currency, client (none for a client without an email), the address on the invoice, else the client's. Lines: name, quantity, quantity × unit cost (a fractional quantity and the unit cost are listed on the line; FreshBooks lines name no item, so they link to no product). Discount: `discount_total`. Taxes: each tax (a line's `taxName`/`taxAmount`, a percentage) on the discounted lines it is on, rounded to the cent; if those don't make the amount exactly, one tax of the difference, with a warning. Total: the amount. Status: nothing outstanding → completed (paid on the last payment's date); disputed → on hold; otherwise (draft, sent, viewed, partial, overdue) → pending payment. Each payment is a private order note (amount, method, date, note, from a client credit), the last one's method is the payment method. Due date, PO number, notes, terms, FreshBooks' statuses, paid and outstanding amounts and discount percentage are meta. Mirroring an invoice reduces no stock. |

## Planned (operator decisions)

| FreshBooks | Why, and the candidate home |
|---|---|
| Chart of accounts, general ledger, manual journal entries | WordPress and WooCommerce have no ledger. Decision: a WordPress accounting plugin (not yet chosen), or keep the books in an ERP (the sibling frappe-idempotent-importer mirrors FreshBooks' books into ERPNext). |
| Expenses, bills, bill vendors, bill payments, other income | Purchases and income outside sales have no WooCommerce home; same decision as the ledger. |
| Credit notes | A credit note is to a client, not an invoice; WooCommerce refunds belong to an order. Decision: refunds on the invoice a credit is applied to, or keep them as client meta. |
| Estimates | WooCommerce has no quotes; a quote plugin or draft orders are the candidates. |
| Projects, time entries, team members | No WooCommerce or FluentCRM home; FluentBoards (projects as boards) is a candidate, as for CRM deals. |
| Retainers, recurring invoice profiles, attachments, gateways and online payment settings | Not read. |
| Clients without an email address | WooCommerce and FluentCRM both key people by email: their invoices are guest orders in their name and address. Decision: leave it so, or a placeholder email. |

## Unverified against a live business

There are no live credentials: everything above is built from the published reference, and no live business was
read. Unverified: the timezone of FreshBooks' timestamps (undocumented; only dates are read as times, the
timestamps are meta); that `include[]=lines` returns every line; that the authorize URL takes the scopes as `scope`
(the reference shows scopes chosen on the app); that `vis_state` 1 is deleted for clients, invoices and payments
as it is for items; and that a service's billable rate is `service_rate.rate`.
