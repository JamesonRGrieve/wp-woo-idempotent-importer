<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Coverage: Salesforce (REST API v62.0) → FluentCRM + FluentBoards + WooCommerce

The single source of truth for what the Salesforce adapter mirrors. CRM data goes into **FluentCRM** (free, GPL),
opportunities and tasks into **FluentBoards** (free, GPLv2+, by the FluentCRM team; operator decision of 2026-10-21),
store data (products and orders) into WooCommerce. Salesforce publishes no OpenAPI document for its REST
resources and sObjects, so the responses the adapter reads (the Query resource, sObject describes, and the
Account, Contact, Lead, Campaign, CampaignMember, Topic, TopicAssignment, Task, Event, Note, Product2, Order,
OrderItem, Organization, Opportunity, OpportunityContactRole, OpportunityStage, TaskStatus and User records) are transcribed from the REST API Developer Guide and the Object Reference
for API version 62.0 into `tests/schemas/salesforce.json` (each schema cites its page), and every fixture is
validated against it. A record may also carry the org's custom fields (`__c`), which no document can list.
**Planned** is work the importer doesn't do yet; each is an operator decision on the Deck card.

Salesforce's developer documentation refuses this environment's requests (HTTP 403 to automated fetches), so
the transcription relies on the published guides as cited and on Salesforce's long-stable REST shapes; it is
marked unverified below until it is checked against a live org.

## Access

A connected app with OAuth enabled (web server flow with PKCE, scopes `api refresh_token`), connected with
"Connect to Salesforce": authorization at `<login URL>/services/oauth2/authorize`, tokens at
`<login URL>/services/oauth2/token` (`https://login.salesforce.com`, `https://test.salesforce.com` for a sandbox,
or the org's My Domain). The token response names the org's `instance_url`, which every request goes to; a
rejected access token is renewed once with the refresh token, and a refused refresh stops the run and holds
scheduled runs. An access token and instance URL issued elsewhere (the Salesforce CLI) can be set in
`wp-config.php` instead (`IIM_SALESFORCE_ACCESS_TOKEN`, `IIM_SALESFORCE_INSTANCE_URL`).

Salesforce limits API requests per org per rolling 24 hours (by edition and licences), not per second, and
reports usage in each response's `Sforce-Limit-Info: api-usage=<used>/<limit>`. A spent allowance is answered
with a 403 `REQUEST_LIMIT_EXCEEDED`; the adapter treats that as rate limiting (retried with backoff, then the run
pauses and holds on for about a day, the window over which usage ages out), never as rejected credentials.
Requests aren't paced. A first import costs about one request per 2,000 records of each object (the Query
resource's batch size), plus one describe per object.

Nothing is incremental: every run reads in full and unchanged records are skipped by hash. Each object is read
with every field its describe lists (compound address and location fields through their parts); fields a user
can set that no mapper places natively are meta; formula, roll-up and system fields are not mirrored.

## Mirrored

| Salesforce | Home | Notes |
|---|---|---|
| Accounts | FluentCRM companies | Name, phone, website, industry, type, description, employees, billing address (else shipping address; a street's second line is address line 2), created date. Custom account fields are FluentCRM company custom fields (group "Salesforce"); other settable fields (rating, revenue, ownership, parent, owner, the shipping address) are meta. |
| Campaigns | FluentCRM lists | Name and description; members from campaign members (contacts and leads). Type, status, dates, budget and results are meta. |
| Topics | FluentCRM tags | Name and description; assigned from topic assignments on contacts and leads. |
| Contacts and unconverted leads | FluentCRM contacts | Email (lower-cased), salutation, first and last name, phone (else mobile), birthdate (contacts), mailing address (contacts) or address (leads), created date, source "Salesforce", contact type lead (Salesforce doesn't say who has bought; Contact or Lead is meta), status unsubscribed when opted out of email, else transactional. A contact's account is its company; campaigns are lists, topics tags. Custom fields are FluentCRM custom fields: text, textarea, number (number, currency, percent), date, date and time, select (picklist, checkbox as Yes/No), multiple select (multi-select picklist). Other settable fields (title, department, lead source and status, a lead's company name, owner) are meta. A FluentCRM contact with the same email (from another source too) is adopted. A converted lead is retired (its contact carries on). |
| Logged calls and emails (tasks with subtype Call, Email or List Email), events, notes | FluentCRM notes (call, email, meeting, note) on the contact's or lead's profile, else the account's | Subject or title, description or body, the event's start (else when created). Status, priority, due date, call type, duration and result; event times and location are listed after the body. Owner and related record are meta. |
| Opportunity stages (OpportunityStage, the active ones) | The stages of the "Salesforce opportunities" FluentBoards board | In sort order, keyed by API name (what an opportunity's StageName holds), titled by label; a closed stage closes its opportunities, won or lost (IsWon). Default probability and forecast category are kept with the board, in the org's default currency. An inactive stage is archived on the board. |
| Opportunities | FluentBoards tasks on that board | Name, description, stage, amount as the task's value (two decimal places; an amount FluentBoards can't hold exactly is meta only, with a warning), currency (the org's default in a single-currency org), close date as the due date (a date, that day wherever the site is), closed won or lost (IsClosed, IsWon), the contacts by role (the primary first: the task's FluentCRM contact; the rest are meta), the account (meta), created date. Every other settable field, and the org's custom fields by API name, are meta. |
| Task statuses (TaskStatus) | The stages of the "Salesforce tasks" FluentBoards board | In sort order; a closed status closes its tasks. |
| Tasks that aren't logged calls or emails (subtype Task, or none) | FluentBoards tasks on that board | Subject, description, status, priority (High, Normal, Low → high, medium, low), due date (ActivityDate), closed (IsClosed, with CompletedDateTime), the contact or lead it is about (WhoId) as the task's FluentCRM contact, the account (meta), created date. The related record (WhatId), every other settable field and the org's custom fields are meta. |
| Owners (Users, every one, active or not) | The FluentBoards task's assignee | Only on a clean match: exactly one WordPress user has the user's email (operator decision of 2026-10-21); that user is also made a member of the board, with notifications off. Otherwise no assignee: the owner (id, email, name) is meta and the log says so. Accounts, contacts and notes keep their owner id as meta. |
| Products (Product2) | Simple WooCommerce products | Name, description, standard price book entry's unit price, stock keeping unit (else product code) as the SKU; inactive → private. Family, code, unit and other settable fields are meta. |
| Contacts orders are billed to | WooCommerce customers (`salesforce-<id>`) | Email, name, phone, created date, mailing address as billing address. |
| Orders and order products | WooCommerce orders with Salesforce's total | Order number, effective date, currency (the order's in a multi-currency org, else the org's default), bill-to contact, billing and shipping addresses, description. Draft → pending, activated → processing. Each product with its quantity, unit price and total. Other settable fields (account, contract, PO number, type, status) are meta. |
| Contacts and leads without an email address | a FluentCRM contact (and a WooCommerce customer, where it buys) with a placeholder address | WooCommerce and FluentCRM keep people by email, so one without gets `record-<id>@salesforce.invalid` (operator decision of 2026-10-21): the same every run, under `.invalid`, which RFC 2606 reserves so it can never be delivered, and marked `_mirror_email_placeholder`. No mail is ever sent to it: every `.invalid` recipient is dropped before WordPress's mailer, whatever sends it, and password resets skip it. Status follows the source's consent as for anyone else. |

## Planned (operator decisions)

| Salesforce | Why, and the candidate home |
|---|---|
| Sales processes (which stages each record type's opportunities use) | One board holds every active stage; a sales process is a subset of them, and the opportunity's record type is meta. |
| Opportunity products (OpportunityLineItem) | Not read; the amount is the opportunity's value. |
| Email consent beyond opt-out (consent records, data privacy objects) | Without an opt-in record contacts are transactional; mapping Individual / ContactPointConsent onto subscribed is planned. |
| Enhanced notes (ContentNote), files and attachments | ContentDocumentLink reads; files would go to the media library. |
| Prices in other price books, multi-currency prices | The standard price is the product's price; other books need a pricing home (wp-woo-discount-rules or a B2B price list in wp-woo-b2b-companies). |
| Cases, quotes, contracts, assets, custom objects | Not read. Cases → a helpdesk (Fluent Support, GPL); quotes and contracts → documents. |
| Activities with no contact, lead or account | No FluentCRM profile to hang them on. |
| Shared activities (one task on several contacts, TaskRelation) | Mirrored on the primary contact only. |

## Unverified against a live org

There are no live credentials, and Salesforce's documentation could not be fetched from here: the record and
response shapes are transcribed, not validated against a published document. Unverified: every field name and
type in `tests/schemas/salesforce.json`; that the describe's `createable`/`updateable`/`calculated` flags are
present for every field; that a subquery's relationship names are `PricebookEntries`, `OrderItems` and
`OpportunityContactRoles`; the fields of OpportunityStage and TaskStatus (their names match Salesforce's Data Cloud
mapping guide for OpportunityStage; an opportunity's StageName and a task's Status hold the stage's or status's API
name); that `TaskSubtype = null OR TaskSubtype NOT IN (…)` selects the tasks that aren't calls or emails; that the
daily limit is answered with HTTP 403 and `REQUEST_LIMIT_EXCEEDED` (third-party integration guides agree); that
`TaskSubtype` is `Call`, `Email` or `ListEmail` for logged calls and emails; and that a query selecting every
described field stays within SOQL's 100,000-character limit for an org with many custom fields.
