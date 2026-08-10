# Magenx_ProductFeed

Product feed generation and delivery for Magento 2.4.8 — define feeds in the
admin, filter the catalog with the standard condition widget, render XML / CSV /
TSV / JSONL from a safe template language, publish on a schedule, and deliver by
public URL, FTP/SFTP, the **Google Merchant API**, or a **direct catalog-API
push**.

## Why this exists

The stack had no feed surface at all: nothing wrote an export file, talked to a
marketplace API, or scheduled a catalog dump. A merchant could not list on Google
Shopping, Meta, TikTok, Pinterest, Bing or any European comparison engine without
buying a third-party extension.

Timing forced the API choice. **Google's Content API for Shopping v2.1 sunsets on
18 August 2026**, after which its endpoints return `410 Gone`, and the Merchant
API's own `v1beta` was shut down on 28 February 2026. This module targets
Merchant API **v1**, which is the only supported path.

## Backend-only — there is no GraphQL sibling

Unlike most `Magenx_*` modules here, this one ships **no `*GraphQl` companion and
touches no storefront file**. Feeds are an operator feature with no storefront
consumer — the storefront never reads a feed, marketplaces do. `packages/engine`
and the `/api/graphql` persisted-query allowlist are both unchanged.

If a storefront need ever appears it belongs in a sibling `Magenx_ProductFeedGraphQl`,
per the house split. `etc/di.xml` carries a note to that effect so a later session
does not "fix" the missing module.

## Admin

| | |
|---|---|
| Feeds | **Catalog → Product Feeds → Feeds** |
| Settings | **Stores → Configuration → Magenx → Product Feeds** |
| ACL | `Magenx_ProductFeed::feeds` (+ `::feed`, `::generate`, `::deliver`, `::config`) |
| Log | `var/log/magenx_product_feed.log` |

## Configuration

| Path | Default | Notes |
|---|---|---|
| `magenx_product_feed/general/enabled` | `0` | Off by default. While off, both cron jobs return immediately. |
| `magenx_product_feed/generation/batch_size` | `200` | Products per batch. Query count per batch is fixed, so this trades memory for round trips. |
| `magenx_product_feed/generation/max_execution_seconds` | `50` | Time budget per cron tick; the run resumes on the next one. |
| `magenx_product_feed/generation/validate_after_generation` | `1` | Record validation findings against the feed's own rules. |
| `magenx_product_feed/generation/history_retention_days` | `30` | Rolling prune of `magenx_feed_history`. |
| `magenx_product_feed/google/merchant_id` | — | Numeric Merchant Center account ID. Per website / store view. |
| `magenx_product_feed/google/service_account_key` | — | **File name** inside `var/magenx_feed/gmc/`, not a path. |
| `magenx_product_feed/notifications/*` | off | Failure email, sent through Magento's own Mail Sending Settings. |
| `magenx_product_feed/cron/dispatch_schedule` | `*/5 * * * *` | Dispatcher tick, **not** a feed's schedule. |
| `magenx_product_feed/cron/deliver_schedule` | `*/15 * * * *` | Delivery retry. |

## Template language

A feed template is **data, never code**. Filters resolve through a hard-coded
`match()`, the render context holds nested arrays rather than Magento models, and
there is no `eval`, no `call_user_func` on a template-supplied name, and **no
facility for executing an admin-authored PHP file**. Feed extensions commonly
offer that last feature; it is remote code execution reachable by anyone with
feed permissions, and its absence here is deliberate. Do not add it, and do not
add a generic "call this PHP function" filter, which is the same hole with a
smaller door.

```
{{ product.sku }}
{{ product.name | stripHtml | truncate: 150 }}
{{ product.final_price | price }}
{{ product.gallery[0] }}
{{ product.parent.url }}                  falls back to the product's own
{{ product.inventory:warehouse_b }}
{% if product.qty > 0 %}in stock{% else %}out of stock{% endif %}
{% for tp in product.tier_prices %}{{ tp.quantity }}:{{ tp.price }}{% endfor %}
```

Filters: `lowercase uppercase capitalize replace remove append prepend escape
html_entity_decode nl2br strip_newlines stripHtml stripStyleTag clean trim ltrim
rtrim truncate truncatewords ifEmpty dateFormat json ceil floor round
numberFormat price convert plus minus times divided_by modulo first last count
join secure unsecure`. An unknown filter passes the value through and logs once,
rather than failing the export.

### The product loop is lifted out

Write the whole document; wrap the per-product part in
`{% for product in context.products %} … {% endfor %}`. The header is written
once at the start, the loop body once per product across as many cron ticks as
needed, and the footer when the run completes. That is what makes a catalog
larger than one cron window exportable at all.

## Formats and what they can do

| Format | Driven by | Can push to a catalog API |
|---|---|---|
| `xml` | template | no — arbitrary nesting has no record equivalent |
| `csv` / `tsv` / `jsonl` | field map | **yes** |

## Delivery

| Type | What it does |
|---|---|
| `file` | Publishes at the feed's public URL for a consumer to fetch. |
| `sftp` | Uploads through Magento's `Filesystem\Io\Sftp`. No extra dependency. |
| `ftp` | Uploads through `Filesystem\Io\Ftp`. Needs `ext-ftp`. Unencrypted — prefer SFTP. |
| `google_datasource` | Registers the feed as a FETCH data source in Merchant Center and triggers a fetch. |
| `meta_batch` | Pushes records straight into a Meta catalog via `items_batch`, live during the export. |

A new destination is one class implementing `Api\DelivererInterface` plus one
`<item>` in `di.xml`. Adding **Pinterest**, **Microsoft Advertising**, **TikTok**
or Google's direct `productInputs.insert` needs no pipeline change.

**Test-connection writes nothing, anywhere.** A probe file left in a destination
folder gets ingested as a bogus feed by whatever consumer watches it, and one
copied from the module's own directory publishes source code to a third party.

### Google setup

1. Enable the **Merchant API** on a Google Cloud project.
2. Create a service account, download its JSON key, and upload it to
   `var/magenx_feed/gmc/` — **never `pub/media`**, which is web-served; that key
   grants programmatic access to the Merchant Center account.
3. Add the service account's `client_email` as a user on the Merchant Center
   account (**Settings → Users**), Standard access or higher.
4. Register the Cloud project with the Merchant Center account
   (**Settings → Developer registration**). The Merchant API rejects every call
   until this is done, and it takes a few minutes to take effect. A project can
   be registered with only one Merchant Center account at a time.
5. Make the feed URL publicly fetchable — see `deploy/nginx/feed.conf.example`.

### Meta setup

Needs a **system user** token (a user token expires) with `catalog_management`,
and a role on the catalog. Requires a field-mapped feed; the deliverer refuses a
template-driven one rather than sending nothing and reporting success.

## Serving the feed file

`pub/media/magenx-feed/<store_code>/<url_secret>/<filename>`

The random segment is not decoration: a feed is a complete machine-readable dump
of the catalog and may carry cost price or supplier SKU if the merchant maps
those columns.

**In this stack `/media/*` is owned by imgproxy and will not serve an XML or CSV
file.** Without an nginx location of its own the request falls through to the
Next.js storefront, which answers a `307` to `/en/media/…` — the consumer reports
a broken feed and Magento's log shows nothing. See
`deploy/nginx/feed.conf.example`; wiring it is a documented ops step, because
this repo ships no complete storefront nginx config to merge it into.

## CLI

```
bin/magento magenx:feed:list
bin/magento magenx:feed:generate --code=google_shopping     # runs to completion
bin/magento magenx:feed:generate --all [--slice]            # --slice = one cron-sized tick
bin/magento magenx:feed:deliver  --code=google_shopping     # re-send without regenerating
```

## Install

```bash
bin/magento module:enable Magenx_ProductFeed
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode only
bin/magento cache:flush
```

## Tables

| Table | Holds |
|---|---|
| `magenx_feed` | Feed definitions, filter tree, schedule, run state |
| `magenx_feed_delivery` | Destinations and their credentials (encrypted) and last state |
| `magenx_feed_history` | Generation / delivery / validation runs, findings in `details` |

## Design notes worth keeping

- **Never put a double quote in a `db_schema.xml` default or comment.** Magento
  emits `COMMENT "%s"` and `DEFAULT "%s"` unescaped, so one unbalances the whole
  `CREATE TABLE` and `setup:upgrade` fails with *"Multiple queries can't be
  executed"* — pointing at neither the table nor the column. That is why the CSV
  delimiter and enclosure are stored as codes (`comma`, `double`) and resolved to
  characters in PHP.
- **The template decides what gets loaded.** `Template\Parser` walks the compiled
  tree once per run and reports which attributes and joins are used, so a feed of
  sku/name/price costs the collection query plus one price query instead of nine.
- **The filter is pushed into SQL** via `Rule\Model\Condition\Sql\Builder` with
  the **CatalogWidget** condition classes — only those implement
  `getMappedSqlField()`. Known cost: an attribute the builder cannot map is
  skipped, which over-selects harmlessly inside an AND group and silently
  under-selects inside an OR group.
- **Paging seeks on `entity_id`, not OFFSET** — constant cost per page, and
  stable when a product is deleted mid-run.
- **Publication is an atomic same-directory rename.** A consumer polling the URL
  sees the previous complete file or the new one, never a half-written one.
- **Locking is Magento's DB `LockManager`, not a lock file** — a file lock fails
  on NFS/EFS and a crash leaves it wedged.
- **Store emulation wraps the run**, or product and image URLs resolve against
  the admin store and come out wrong on cron while looking fine from the admin.
- **Delivery only follows a completed generation.** A partial run has no
  published file, and pushing half a catalog reads to a marketplace as every
  absent product being delisted.

## Not implemented (and why)

- **A "create from template" picker.** Starter templates for the major channels
  are worth shipping but the picker UI is not built; write the template into the
  form directly for now.
- **Category mapping to a marketplace taxonomy.** Real work, and useless without
  the taxonomy files.
- **Click tracking and revenue reporting.** Belongs with analytics, not here.
- **Amazon.** Not a feed destination but a marketplace integration: SP-API needs
  a Selling Partner account, an approved LWA developer app, role authorisation
  and per-marketplace product-type schemas, and it owns listings, orders and
  inventory rather than an advertising catalog. A separate module.
- **The OpenAI / ChatGPT shopping feed as a *destination*.** The format is
  public (UTF-8 delimited, `item_id`/`title`/`price`/`availability`/
  `is_eligible_checkout`…) and a TSV feed produces it today, but the submission
  endpoint and its auth are not published — so it is delivered by URL or SFTP
  until they are.

## Verification status

Everything below was run in a sandbox with **PHP 8.4 but no Magento install**:

- `php -l` clean on all PHP files; `xmllint` clean on all XML; both JSON files parse.
- Every core class and method this module references was checked to exist at tag
  2.4.8.
- ACL re-declarations were cross-checked against core's own `acl.xml` files:
  all five shared ids sit at core's exact path. Menu parents and resources
  resolve. (A mismatch in either 500s every admin page, and XSD validation
  cannot see it.)
- Every `system.xml` field using the email-template source model has a matching
  `<template id>` of `section_group_field`. (A mismatch renders the whole config
  section blank, with only a line in `var/log`.)
- The template engine, scheduler, validator and all four writers were exercised
  against the real classes in a standalone harness: **72 cases, all passing** —
  filters, parent fallback, gallery indexing, numeric vs string comparison,
  loops, syntax-error reporting, requirements analysis, store-timezone and DST
  scheduling, catch-up after cron downtime, validation severities, trailing empty
  CSV columns, quote escaping, TSV tab forcing, CDATA terminator splitting and
  illegal control-character stripping.

**Nothing has been exercised against a live Magento.** Required before trusting
it: `setup:upgrade` completing; the admin loading at all; the feed grid and the
edit form rendering with a **working "+" button on the condition tree** (the
likeliest failure); a feed generating end-to-end with the row count matching the
filter; the published file being reachable at its public URL **through nginx**;
store emulation producing store-scoped product and image URLs on a **cron** run
rather than only from the admin; an SFTP test leaving the remote folder
untouched; and a Google data source being created and fetched against a real
Merchant Center account with a registered Cloud project.
