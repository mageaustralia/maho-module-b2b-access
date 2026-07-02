# MageAustralia_B2bAccess

B2B access gate for Maho 26.5+. Require login to view the store, hide prices,
hide products entirely, and block purchasing for selected customer groups,
categories, countries or products. Server-side enforced, observer-driven, no
core rewrites.

## What it does

Three independent capabilities:

1. **Login wall** - redirect guests to a CMS page (or the login page) on every
   storefront action except the pages they need to log in / register.
2. **Restrictions** - for a matched customer/product, hide the price, hide the
   product from listings and search, and/or block purchasing.
3. **Country restrictions** - block ordering of a product to specific
   destination countries (brand distribution contracts), enforced at checkout
   against the shipping address.

Everything is enforced on the server: hiding a price also blocks the crafted
add-to-cart URL; hiding a listing also removes the product from the Meilisearch
index for the restricted groups; a country block aborts order submission.

## Two modes

Set **System > Configuration > Customers > B2B Access > General > Mode**.

### Basic (default)

One gate, configured entirely in the config section:

- **Login Wall** - require login, redirect target, notice.
- **Restrictions** - hide prices, hide products entirely, block purchasing,
  hidden-price message.
- **Activation Matrix** - who/what the restrictions apply to: by customer group
  (OR) by category (subcategories included).

This reproduces the original single-gate behaviour and is enough for most
"log in to see prices" wholesale shops.

### Rules

Multiple named rules under **Customers > B2B Access Rules**, each with its own:

- **Scope** (all AND-ed; empty = any): customer groups, stores, destination
  countries, categories, products (SKUs or IDs).
- **Actions**: hide from listings and search, hide price, block purchase,
  redirect a gated product page to a CMS page.
- **Enforcement**: `visibility` (hide only), `checkout` (block ordering only),
  or `both`.
- **Priority** and an optional per-rule message.

Rules are evaluated by priority (lowest first). A rule with no category and no
product scope is catalog-wide.

## Meilisearch integration

When [maho-module-meilisearch](https://github.com/mageaustralia/maho-module-meilisearch)
is installed, this module subscribes to its `meilisearch_product_restrictions`
event and contributes, per product, the customer-group IDs the product is hidden
from (from every `visibility`/`both` hide-listing rule that covers it). The
search index carries a `restricted_customer_group_ids` field and the storefront
filters with `restricted_customer_group_ids != <currentGroupId>`, so restricted
products never appear in search for the wrong group.

Run a full Meilisearch reindex after changing hide-listing rules.

No coupling: if the search module is absent the event never fires and nothing
breaks; if present it works with zero extra configuration.

## Country restrictions

Country scope is matched at checkout against the quote's shipping country
(billing for virtual carts). Because a guest's country is usually unknown while
browsing, country rules default to `checkout` (or `both`) enforcement: the
authoritative block happens in `sales_model_service_quote_submit_before`, before
any order is created, and names the offending item(s). Country is intentionally
not pushed into the (geo-agnostic, CDN-cacheable) search index.

## Migrating from Amasty Customer Group Catalog (Magento 1)

A CLI importer maps `am_groupcat_rules` / `am_groupcat_product` into rules:

```bash
./maho b2baccess:import-groupcat --dry-run   # preview
./maho b2baccess:import-groupcat             # import
```

It is idempotent (imported rules are named `Groupcat: <name>` and skipped on
re-run). After importing, set Mode = Rules and reindex Meilisearch.

## Install

```bash
composer require mageaustralia/maho-module-b2b-access
./maho cache:flush
composer dump-autoload   # compile the observer/route attributes
```

Rules mode adds two tables (`b2baccess_rule`, `b2baccess_rule_product`); the
setup script runs automatically on first load, including for installs upgrading
from 1.0.0.

## Enforcement points

| Concern | Hook |
|---|---|
| Login wall | `controller_action_predispatch` (redirect guests) |
| Hide price | `core_block_abstract_to_html_after` (rewrite price block) |
| Drop price sort | `core_block_abstract_to_html_before` (toolbar) |
| Hide listing | `catalog_block_product_list_collection` (filter collection) |
| Block add-to-cart | `controller_action_predispatch_checkout_cart_add[group]` |
| Country checkout guard | `sales_model_service_quote_submit_before` |
| Search restrictions | `meilisearch_product_restrictions` (contribute group IDs) |

## Tests

```bash
# Pure logic, no Maho needed:
vendor/bin/phpunit --testsuite Unit

# Full suite against a Maho install:
MAHO_ROOT=/path/to/maho vendor/bin/phpunit
```

## License

OSL-3.0.
