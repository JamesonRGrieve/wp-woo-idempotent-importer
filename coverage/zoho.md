<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Zoho CRM (API v8) → FluentCRM + WooCommerce

The single source of truth for what the Zoho CRM adapter mirrors. CRM data goes into **FluentCRM** (free, GPL),
the product catalogue and sales orders into WooCommerce. Zoho publishes an OpenAPI document per API in
`zoho/crm-oas`; the ones the adapter reads (records, field metadata, tags, notes, related records, organization)
are pinned to commit `a6687fc961acdaa52592d39c5c7e4385e5b4c289` and every fixture is validated against them.
**Planned** is work the importer doesn't do yet; each is an operator decision on the Deck card.

## Access

A server-based client registered in the Zoho API console (api-console.zoho.com), connected with "Connect to Zoho
CRM": authorization at `<accounts URL>/oauth/v2/auth` with `access_type=offline` (so Zoho issues a refresh token),
scopes `ZohoCRM.modules.READ`, `ZohoCRM.modules.notes.READ`, `ZohoCRM.settings.fields.READ`,
`ZohoCRM.settings.tags.READ`, `ZohoCRM.org.READ`. The redirect names the data centre's accounts server
(`accounts-server`), where the code is traded and the token refreshed; the token response names the org's
`api_domain`, which every request goes to (`<api domain>/crm/v8/…`). Access tokens last an hour and are refreshed
before they expire; requests carry `Authorization: Zoho-oauthtoken <token>`. The accounts URL is
`https://accounts.zoho.com` unless the operator enters their data centre's (`.eu`, `.in`, `.com.au`, …).

Zoho counts API credits per org per day (by edition and licences) and limits concurrent calls, not calls per
minute (zoho.com/crm/developer/docs/api/v8/api-limits.html). The importer makes one call at a time; a 429 is
retried with capped backoff, then pauses the run. Rejected credentials (401, or a refresh Zoho refuses) stop the
run and hold scheduled runs.

Nothing is incremental (unchanged records are skipped by hash). Records are read 200 a page; `page` reaches the
first 2,000 and `next_page_token` goes beyond. Each module's field metadata is read once per run and every field
it lists is asked for; a request names at most 50 fields, so a module with more is read in chunks, the later ones
by `ids`.

## Mirrored

| Zoho CRM | Home | Notes |
|---|---|---|
| Accounts | FluentCRM companies | Name, phone, website, industry, account type, description, employees, billing address (else shipping), created time. The org's own account fields are FluentCRM company custom fields (group "Zoho CRM"). Rating, revenue, ownership, parent account, owner and the other settable fields are meta. |
| Campaigns | FluentCRM lists | Name, description; members are the contacts and leads in the campaign's Contacts and Leads related lists. Type, status, dates, budget and results are meta. |
| Tags (of Contacts and Leads) | FluentCRM tags | Name; colour is meta. |
| Contacts and unconverted leads | FluentCRM contacts | Email (lower-cased), salutation, first and last name, phone (else mobile), birthdate, mailing address (a lead's address), created time, source "Zoho CRM", contact type lead. Status: Email Opt Out → unsubscribed, else transactional (a record holds no opt-in). Account → company, tags → tags, campaigns → lists. The org's own fields → FluentCRM custom fields: text, textarea, number (integer, decimal, currency, percent), date, date-time, picklist and boolean as select (Yes/No), multi-select picklist; lookups by name, other structured values as JSON text. Title, department, lead source and status, a lead's company name and owner are meta. A FluentCRM contact with the same email is adopted. |
| Notes, calls, events | FluentCRM notes (note, call, meeting) on the contact's, lead's or account's profile | A note's title and content (dated when created); a call's subject and description, dated when it started, with its type, duration and result listed after it; an event's title and description, dated when it starts, with its end and venue listed after it. Owner is meta. |
| Product categories (the products' category picklist) | WooCommerce product categories | The picklist value's display name. |
| Products | Simple WooCommerce products | Name, description, unit price, product code as the SKU (optional generated `zoho-<id>`), quantity in stock as managed stock (none: unmanaged), taxable, category; an inactive product is private. Vendor, manufacturer, taxes, unit, reorder level, commission rate and the org's own product fields are meta. |
| Contacts named on sales orders | WooCommerce customers | Email, first and last name, phone, created time, mailing address as the billing address (also FluentCRM contacts above). |
| Sales orders | WooCommerce orders with Zoho's totals | Number (else subject), created time, currency (the order's in a multi-currency org, else the org's home currency), customer, billing and shipping addresses, sub-total, discount, tax, adjustment (a fee), grand total. Status: Delivered → completed, Cancelled → cancelled, others (Created, Approved, custom) → processing; Zoho's status is meta. Each ordered item: product, quantity, list price, discount, total; its line taxes listed by name. Subject, deal, quote, carrier, purchase order, terms, owner and every other settable field are meta. Mirroring an order does not reduce stock. |

## Planned (operator decisions)

| Zoho CRM | Why, and the candidate home |
|---|---|
| Deals, pipelines and stages; tasks | FluentCRM has no deals. Candidate: **FluentBoards** (free, GPLv2+, FluentCRM's team): a board per pipeline (Zoho layouts), its stages, a task per deal linked to its contact; tasks as tasks with due date and assignee. Same decision as HubSpot, Salesforce and Pipedrive. |
| Quotes, invoices, purchase orders, vendors, price books, cases, solutions | No WooCommerce or FluentCRM home with the same meaning (invoices could become orders, but sales orders already are; doubling them is the decision). |
| Converted leads | A converted lead is its contact (and account and deal); it is left out, as Zoho's own lists do. |
| Records without an email address | FluentCRM keys contacts by email; decision: leave them out (as now) or a placeholder. |
| Attachments, emails, signals, custom modules | Not read. |
| Users (owners) | Kept as ids; mapping them to WordPress users is a decision. |
| Prices in other currencies (multi-currency orgs) | WooCommerce has one currency; an order keeps its own currency, products the home currency's price. |

## Unverified against a live org

There are no live credentials: everything above is built from the published documents, and no live org was read.
Unverified: that `field_read_only` and `read_only` together exclude exactly the fields a user cannot set; that a
call's and event's owner is in `Who_Id` or `What_Id` with `$se_module`; that the category picklist is
`Product_Category`; that Ordered_Items' line taxes are `{name, percentage, value}`; and that the Sales_Orders
statuses Delivered and Cancelled are the org's (statuses are customisable).
