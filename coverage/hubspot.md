<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: HubSpot (CRM API v3) → FluentCRM + FluentBoards + WooCommerce

The single source of truth for what the HubSpot adapter mirrors. CRM data goes into **FluentCRM** (free, GPL,
fluentcrm.com), the WordPress CRM home; deal pipelines, deals and tasks into **FluentBoards** (free, GPLv2+, by the
FluentCRM team, wordpress.org/plugins/fluent-boards; operator decision of 2026-10-21); store data (the product library
and commerce orders) into WooCommerce.
HubSpot publishes an OpenAPI 3 document per API (HubSpot/HubSpot-public-api-spec-collection); the fixtures are
validated against the contacts, companies, notes, calls, emails, meetings, products, line items, orders, lists,
properties, deals, tasks, pipelines and owners documents at the commit of 2026-10-21. Property names used natively are those HubSpot's guides
document; every other property the account can set is kept as meta. **Planned** is work the importer doesn't
do yet; each is an operator decision on the Deck card.

## Access

A private app's access token (Settings → Integrations → Private apps), or an OAuth app connected with "Connect to
HubSpot" (authorization at `https://app.hubspot.com/oauth/authorize`, tokens at
`https://api.hubapi.com/oauth/v1/token`, refreshed before they expire). Scopes (each endpoint's `security` in the
published documents): `crm.objects.contacts.read`, `crm.objects.companies.read`, `crm.lists.read`,
`crm.objects.notes.read`, `crm.objects.calls.read`, `crm.objects.emails.read`, `crm.objects.meetings.read`,
`e-commerce` (products), `crm.objects.line_items.read`, `crm.objects.orders.read`, `crm.objects.deals.read` (deals and
their pipelines), `crm.objects.tasks.read`, `crm.objects.owners.read`, and `crm.schemas.<object>.read` for contacts,
companies, line items, orders, notes, calls, emails, meetings, deals and tasks (the Properties API).

A privately distributed app may make 100 requests every 10 seconds on Free and Starter accounts (190 on
Professional and Enterprise), an OAuth app 110 per installing account; each response reports what is left in
`X-HubSpot-RateLimit-Remaining`, and the daily limit (250,000 on Free and Starter) resets at midnight in the
account's time zone (developers.hubspot.com/docs/developer-tooling/platform/usage-guidelines). Requests are paced
at 10 a second; when the 10-second window is down to its last request the next waits it out; a 429 is retried
with backoff and, if it persists (a spent daily limit), pauses the run. Rejected credentials stop the run and
hold scheduled runs. Objects are listed 100 at a time (`after` cursor); list memberships 250 at a time.

Nothing is incremental: the list endpoints have no changed-since filter (only the Search API does, with lower
limits and a 10,000-result cap), so every run reads in full and unchanged objects are skipped by hash. Every
object is asked for every property the account defines (from `/crm/v3/properties/<object>`).

Mirroring writes FluentCRM and FluentBoards through their models, never their API helpers, so no event fires: no
FluentCRM automation, email sequence or double opt-in email starts, and no FluentBoards notification, activity entry or
email (the e2e suite counts FluentCRM's contact, list and tag events and FluentBoards' board and task events).

## Mirrored

| HubSpot | Home | Notes |
|---|---|---|
| Companies | FluentCRM companies | Name (else domain), domain as the website, phone, industry, city and state, created date. The account's own company properties are FluentCRM company custom fields (group "HubSpot"). Every other property the account can set (address, employees, revenue, lifecycle stage, owner) is meta. FluentCRM shows companies once its Companies module is on (Settings → Experimental Features). |
| Contact lists (static) and segments (active) | FluentCRM lists | Each run mirrors who is in the list now, so a segment's membership follows HubSpot's. Processing type and version are meta. |
| Contacts | FluentCRM contacts | Email (lower-cased), first and last name, phone (else mobile), address, created date, lifecycle stage `customer` → FluentCRM customer (else lead), source "HubSpot", status transactional (see Planned). Companies (the first associated is the primary company) and lists among those mirrored from HubSpot; memberships of lists made in WordPress are left alone. The account's own contact properties are FluentCRM custom fields: text, textarea, number, date, date and time, select (radio, select, single checkbox) and multiple select (checkboxes, by option label). Every other property the account can set (job title, lead status, timezone, owner) is meta; calculated and read-only properties (analytics, counters) are not mirrored, because they change on every visit. A contact FluentCRM already has (same email) is adopted. |
| Notes, calls, emails, meetings | FluentCRM notes of type note, call, email, meeting on each associated contact's and company's profile | Title (call or meeting title, email subject, else the type), body (an email's HTML, else its text), timestamp. Call direction, status, numbers, duration and recording; email direction and status; meeting times, location, outcome, internal notes and link are listed after the body. Owner and attachment ids are meta. |
| Product library | Simple WooCommerce products | Name, description, unit price, SKU (optional generated `hubspot-<id>`), cost of goods. Stock is unmanaged (HubSpot keeps none). Recurring billing period and tiered pricing are meta and warned about. |
| Contacts who placed orders | WooCommerce customers (`hubspot-<id>`) | Email, name, phone, created date, address as the billing address. |
| Deal pipelines | FluentBoards boards | The pipeline's label; its stages in display order (archived stages are archived on the board). A stage HubSpot marks closed (`metadata.isClosed`) closes its deals; each stage's probability is kept with the board's stage definitions. An archived pipeline archives its board. |
| Deals | FluentBoards tasks on their pipeline's board | Name, description, stage, amount as the task's value (FluentBoards' `lead_value`, two decimal places; an amount it can't hold exactly is meta only, with a warning), currency, close date as the due date, priority, closed won or lost (`hs_is_closed`, `hs_is_closed_won`; a closed deal is a closed task, dated at its close date), the first associated contact as the task's FluentCRM contact (the others, and the companies, are meta), created date. Every other property the account can set, and the account's own deal properties, are meta. |
| Tasks | FluentBoards tasks on the "HubSpot tasks" board | A stage per option of the task status property (`COMPLETED` closes a task). Subject, body, status, priority (low, medium, high; none is none), due date (`hs_timestamp`), completion date, the first contact, created date; type and the rest are meta. |
| Owners (HubSpot users, from the Owners API, active and archived) | The FluentBoards task's assignee | Only on a clean match: exactly one WordPress user has the owner's email (operator decision of 2026-10-21); that user is also made a member of the board, with FluentBoards' notifications off. Otherwise the task has no assignee, keeps the owner (id, email, name) as meta, and the log says so. Contacts, companies and notes keep their owner id as meta (FluentCRM has no owner field). |
| Commerce orders | WooCommerce orders | Name as the order number, currency, created date, shipping address, customer (the first associated contact), and line items (name, quantity, unit price, product) totalled as quantity × price; the order total is the sum of its lines. The tracking number is a note. Every other order and line item property the account can set (fulfillment status, pipeline stage, source store, the portal's totals) is meta. |

## Planned (operator decisions)

| HubSpot | Why, and the candidate home |
|---|---|
| Order amounts and statuses | HubSpot's order guide documents no property for an order's totals, tax, shipping or discount, nor the values of its fulfillment status and pipeline stages; they are kept as meta and the order is processing, totalled from its lines. Verifying the property names against a live portal turns them into WooCommerce totals, tax and shipping lines and statuses. |
| Email consent and subscription types | Contacts are transactional (no marketing). HubSpot keeps consent per subscription type (the Communication Preferences API, scope `communication_preferences.read`); mapping those onto FluentCRM's subscribed / unsubscribed is the planned next step. |
| Contacts without an email address | FluentCRM keys contacts by email. Decision: leave them out (as now, reported in the log), or a placeholder address. |
| Notes and activities with no contact or company | No FluentCRM profile to hang them on. |
| Segment filters | FluentCRM's free lists are static; its dynamic segments are Pro. Membership is mirrored each run instead. |
| Tickets, quotes, invoices, payments, subscriptions, carts, custom objects | Not read. Invoices and payments would become WooCommerce orders or FluentCRM activities; tickets a helpdesk (Fluent Support, GPL). |
| Product images, folders, recurring billing, tiered prices | Images and folders aren't documented product properties; recurring billing → the selling-plans companion; tiered prices → a tiered-pricing home. |

## Unverified against a live account

There are no live credentials: everything above is built from the published documents and guides, and no live
account was read. Unverified: that the list endpoints leave out properties an object has no value for (the
documents type every value as a string); that a list of 100 objects with every property name in the query stays
within HubSpot's URL limit for an account with many properties; that `lifecyclestage` is `customer` for
customers; that the Lists search returns every contact list when `objectTypeId` is `0-1`; that a call's
`hs_call_duration` is in milliseconds (the calls guide says so); that a deal stage's metadata names `isClosed` (the
Pipelines document names only `probability` for deals); and that a deal's `hs_is_closed` and `hs_is_closed_won` say
how it ended.
