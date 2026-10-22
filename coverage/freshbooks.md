<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: FreshBooks → WooCommerce + FluentCRM

The single source of truth for what the FreshBooks adapter mirrors. FreshBooks is accounting software: what has a
faithful home in a store (what it sells, who it sells to, what it invoiced and was paid) goes into WooCommerce, its
clients also into **FluentCRM** (free, GPL); the books themselves go to ERPNext only (operator decision of 2026-10-21; below). FreshBooks
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

Credit notes are read at `/credit_notes/credit_notes` with `include[]=lines`; the reference lists `user:clients:read` as their scope.

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
| Clients | WooCommerce customers | Email (lower-cased), first and last name, phone (business, else mobile, else home), billing address with the organization as its company. Fax, VAT name and number, currency, language, note, sign-up time and accounting id are meta. |
| Clients | FluentCRM contacts | Email, name, phone, billing address, contact type customer, source "FreshBooks", status transactional (FreshBooks records no marketing consent), organization as company. A FluentCRM contact with the same email is adopted. Other phones, shipping address and the rest are meta. |
| Client organizations | FluentCRM companies | FreshBooks keeps an organization as a client's business name: the clients naming the same one (case and spacing aside) share one company, with the first such client's phone and address. |
| Credit notes applied to invoices | WooCommerce refunds on the invoices' orders | Operator decision of 2026-10-21. A payment naming a credit note (`creditid`) applied that much of it to its invoice: a refund of that amount on the invoice's order, dated when applied, with the credit note's number as the reason and its own lines kept on the refund (`_mirror_credit_lines`; they name no invoice line, so none is matched). Nothing is restocked or paid back; the order keeps its status. A credit note applied to nothing has no refund. |
| What is left of a client's credit notes | Their store credit in **Square Gift Cards** | Through the gift card writer (switch the `gift_card` kind on): one card per client, linked to their WooCommerce customer, with a new number shown in My Account; its balance is each credit note's amount less what was applied, in the credit notes' currency; its ledger each credit note issued and each application. Increases are held unless allowed (Square's load fee), as for every card; a placeholder email is never given to Square. |
| Invoices with their payments | WooCommerce orders with FreshBooks' amounts | Number, issue date, currency, client, the address on the invoice, else the client's. Lines: name, quantity, quantity × unit cost (a fractional quantity and the unit cost are listed on the line; FreshBooks lines name no item, so they link to no product). Discount: `discount_total`. Taxes: each tax (a line's `taxName`/`taxAmount`, a percentage) on the discounted lines it is on, rounded to the cent; if those don't make the amount exactly, one tax of the difference, with a warning. Total: the amount. Status: nothing outstanding → completed (paid on the last payment's date); disputed → on hold; otherwise (draft, sent, viewed, partial, overdue) → pending payment. Each payment is a private order note (amount, method, date, note, from a client credit), the last one's method is the payment method. Due date, PO number, notes, terms, FreshBooks' statuses, paid and outstanding amounts and discount percentage are meta. Mirroring an invoice reduces no stock. |
| Clients without an email address | A WooCommerce customer and a FluentCRM contact with a placeholder address | WooCommerce and FluentCRM keep people by email, so one without gets `client-<id>@freshbooks.invalid` (operator decision of 2026-10-21): the same every run, under `.invalid`, which RFC 2606 reserves so it can never be delivered, and marked `_mirror_email_placeholder`. No mail is ever sent to it: every `.invalid` recipient is dropped before WordPress's mailer, whatever sends it, and password resets skip it. Status follows the source's consent as for anyone else. |

## Planned, ERPNext only and out of scope (operator decisions)

| FreshBooks | Why, and the candidate home |
|---|---|
| Chart of accounts, general ledger, manual journal entries | ERPNext only (operator decision of 2026-10-21): the books are never written to WordPress; the sibling frappe-idempotent-importer mirrors them into ERPNext. |
| Expenses, bills, bill vendors, bill payments, other income | ERPNext only (operator decision of 2026-10-21): the books are never written to WordPress; the sibling frappe-idempotent-importer mirrors them into ERPNext. |
| Estimates | Planned: a `wp-woo-quotes` companion. No reputable GPL WooCommerce quote plugin holds an estimate faithfully (researched 2026-10-21): YITH Request a Quote (free, GPLv2+, 10,000+ installs) keeps no quote record in its free version (requests are emailed; stored quotes, statuses and order conversion are Premium); Quotes for WooCommerce (GPLv3, 4,000+ installs, updated 2026-06) makes a quote a WooCommerce order with a `_quote_status` of pending, sent or complete, so it has no accepted, declined or expired state and the quote and its invoice can't both exist; CatalogX (MultiVendorX, GPLv2+, 5,000+ installs) registers accepted, rejected and expired quote order statuses but its free version has no expiry or conversion to an order, and no PHP API; NP Quote Request (GPLv2, 8,000+ installs) has only requested and sent. None has an API for creating a quote idempotently. An estimate needs its lines, totals, expiry, accepted/declined state and its conversion to the invoice that is mirrored as an order. |
| Projects, time entries, team members | No WooCommerce or FluentCRM home; FluentBoards (projects as boards) is a candidate, as for CRM deals. |
| Retainers, recurring invoice profiles, attachments, gateways and online payment settings | Not read. |

## Unverified against a live business

There are no live credentials: everything above is built from the published reference, and no live business was
read. Unverified: the timezone of FreshBooks' timestamps (undocumented; only dates are read as times, the
timestamps are meta); that `include[]=lines` returns every line; that the authorize URL takes the scopes as `scope`
(the reference shows scopes chosen on the app); that `vis_state` 1 is deleted for clients, invoices and payments
as it is for items; that a service's billable rate is `service_rate.rate`; that the credit notes list takes
`include[]=lines` and answers with the fields of the reference's single-record example (which shows no lines); and
that applying a credit note to an invoice is a payment naming it (`creditid`, `from_credit`), as the payments
reference has those fields (the credits page doesn't describe applying credit).
