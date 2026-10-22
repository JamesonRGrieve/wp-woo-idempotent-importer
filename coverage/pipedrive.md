<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Pipedrive (API v1 + v2) → FluentCRM + FluentBoards + WooCommerce

The single source of truth for what the Pipedrive adapter mirrors. CRM data goes into **FluentCRM** (free, GPL),
pipelines, deals and activities into **FluentBoards** (free, GPLv2+, by the FluentCRM team; operator decision of
2026-10-21), the product catalogue into WooCommerce. Pipedrive publishes OpenAPI 3.0.1 documents for API v1 and v2 (linked from
developers.pipedrive.com/docs/api/v1) at unversioned URLs; both are pinned to the bytes fetched on 2026-10-21 and
every fixture is validated against them. **Planned** is work the importer doesn't do yet; each is an operator
decision on the Deck card.

## Access

A user's personal API token (Settings → Personal preferences → API), sent as `x-api-token`, or a Pipedrive app
connected with "Connect to Pipedrive": authorization at `https://oauth.pipedrive.com/oauth/authorize`, tokens at
`https://oauth.pipedrive.com/oauth/token` (HTTP Basic with the app's client id and secret), refreshed before they
expire; the token response's `api_domain` is the company's API host. App scopes are declared in the app (the
documents list `base`, `contacts:read`, `activities:read`, `products:read`, `deals:read` (pipelines, stages and deals)
and `users:read`); the authorize URL names none.

Pipedrive limits each token to a burst of requests per rolling 2-second window by plan (20 for an API token on
Lite, the lowest; 80 for an OAuth app), reports what is left in `x-ratelimit-remaining` and when it resets in
`x-ratelimit-reset`, and spends a daily token budget per company (30,000 × plan multiplier × seats); a spent budget
answers 429 until the daily reset (pipedrive.readme.io/docs/core-api-concepts-rate-limiting). Requests are paced at
ten a second, the window is waited out when it is down to its last request, and a persistent 429 pauses the run
(about a day at most, as long as the daily reset). Rejected credentials stop the run and hold scheduled runs.

Persons, organizations, products, deals and activities (as tasks) are incremental (`updated_since`); filters,
labels, categories, pipelines, stages, users, notes and done activities (as notes) are read in full (unchanged objects are skipped by hash). API v2 lists are paged 100 at a time by
`cursor`; API v1 lists 500 at a time by `start`.

## Mirrored

| Pipedrive | Home | Notes |
|---|---|---|
| Organizations | FluentCRM companies | Name, website, LinkedIn, employee count, address (street and number, subpremise, locality, area, postal code, country), created date, industry by its option's name. The account's own organization fields are FluentCRM company custom fields (group "Pipedrive"). Annual revenue, labels, owner and visibility are meta. |
| Person filters | FluentCRM lists | Each run mirrors the persons the filter matches (read through `filter_id`), so the list follows Pipedrive's segment. Owner, visibility and code are meta. |
| Person labels (the label field's options) | FluentCRM tags | Label; colour is meta. |
| Persons | FluentCRM contacts | Primary email (lower-cased), first and last name, primary phone, birthday, postal address, created date, source "Pipedrive", lead. Status from the marketing status (asked for with `include_fields=marketing_status`): subscribed → subscribed, unsubscribed or archived → unsubscribed, no consent or none → transactional. Organization → company, labels → tags, filters → lists. The account's own person fields → FluentCRM custom fields: text, textarea, number, date, select (single option, status, boolean as Yes/No), multiple select (multiple options), money as amount and currency, other structured values (address, time ranges) as JSON text. Other emails and phones, job title, contact sync notes, IM accounts, owner and visibility are meta. A FluentCRM contact with the same email is adopted. |
| Notes | FluentCRM notes on each person's and organization's profile | HTML content, added date. |
| Done activities | FluentCRM notes (call, email, meeting for `call`, `email`, `meeting` and `lunch`, else note) on each participant's and organization's profile | Subject, note, dated when marked done; type, due date and time, duration, location, conference link and public description are listed after the note. Owner, deal and lead ids are meta. |
| Pipelines and their stages | FluentBoards boards | Name; the stages in order (a deleted stage is archived on the board), each with its deal probability, kept with the board's stage definitions. Pipedrive keeps won and lost as a deal's status, not as stages, so no stage closes its deals. A deleted pipeline archives its board. |
| Deals | FluentBoards tasks on their pipeline's board | Title, stage, value as the task's value (two decimal places; a value FluentBoards can't hold exactly is meta only, with a warning), currency, expected close date as the due date (a date), won or lost (closed, dated when won or lost), the person as the task's FluentCRM contact, the organization (meta), added date. Probability, lost reason, labels, origin, channel, archived flag, recurring revenue and custom fields are meta. A deleted deal archives its task. |
| Activities (to do and done) | FluentBoards tasks on the "Pipedrive activities" board, To do or Done | Subject, note with the type, duration, location and conference link listed after it, due date and time (UTC), done (closed, dated when marked done), the person and participants (the first is the task's FluentCRM contact), the organization (meta), added date. Deal, lead, project, priority, busy flag and attendees are meta. A done activity is also a FluentCRM note (above): the task is what was to do, the note the log of it. |
| Users (owners, from every user) | The FluentBoards task's assignee | Only on a clean match: exactly one WordPress user has the user's email (operator decision of 2026-10-21); that user is also a member of the board, with notifications off. Otherwise no assignee: the owner (id, email, name) is meta and the log says so. Persons, organizations and notes keep their owner id as meta. |
| Product categories (the category field's options) | WooCommerce product categories | Label. |
| Products and product variations | Simple product, or variable product with a "Variation" attribute and a variation per Pipedrive variation | Name, description, code as the SKU (optional generated `pipedrive-<id>`), price and cost in the company's default currency (the connected user's), the variation's own price else the product's, category. Stock is unmanaged (Pipedrive keeps none); a deleted product is private. Prices in every currency, unit, tax percentage, billing frequency, owner and the account's own product fields are meta; recurring billing is warned about. |

## Planned (operator decisions)

| Pipedrive | Why, and the candidate home |
|---|---|
| Deal products and deal participants | Not read: a deal's value is its own; its person is its contact. |
| Leads (the Leads Inbox) | A lead is a person or organization with a deal-to-be; mirroring its person is done. The lead itself (value, labels, source) is planned as a FluentBoards task like a deal, on a leads board. |
| Organization labels | FluentCRM companies have no tags; they are meta. |
| Persons without an email address | FluentCRM keys contacts by email; decision: leave them out (as now) or a placeholder. |
| Prices in other currencies | WooCommerce has one currency; the default currency's price is used, the rest are meta (a multi-currency plugin is the planned home). |
| Files, mail threads, projects, goals | Not read. |
| Marketing status | Pipedrive returns it only for companies with its Campaigns add-on; the response schema doesn't declare it (only the request parameter does), so the fixtures leave it out and it is covered by a unit test. |

## Unverified against a live company

There are no live credentials: everything above is built from the published documents, and no live company was
read. Unverified: that `edit_flag` is true exactly for the account's own fields; that the label field is
`label_ids` (older companies may call it `label`; both are looked for); that a person filter's persons are those
`/api/v2/persons?filter_id=` returns; that the connected user's `default_currency` is the company's; that a
done activity's `marked_as_done_time` and an activity's `due_time` are UTC; and that a deal's `status` is `open`,
`won` or `lost` (the documents name the values only as a filter).
