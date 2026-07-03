# MageAustralia_B2bAccess

A B2B access-control gate for Maho 26.5+. Require login to view the store, hide
prices, hide products entirely, and block purchasing, scoped by customer group,
category, destination country or individual product. Everything is enforced on
the server (observers and a checkout guard), reflected in search results, and
configurable as either a single simple gate or a full rules engine. No core
rewrites.

- **Maho / OpenMage** module (community codepool), PHP 8.3+.
- Modernised conventions: `declare(strict_types=1)`, PHP attribute observers and
  routes, OSL-3.0, no Zend, no Prototype.
- Soft integrations only: works standalone; gets richer when a search engine
  (`maho-module-meilisearch` or `maho-search`) and `maho-module-geoip` are present.

---

## Feature scope at a glance

| Capability | What it does | Scope options | Server-enforced |
|---|---|---|---|
| **Login wall** | Redirect guests to a CMS/login page until they sign in | store view | yes |
| **Hide price** | Replace the price with a message | group, category, country, product | yes |
| **Hide product** | Remove from category listings and search entirely | group, category, country, product | yes (incl. search index) |
| **Block purchase** | Reject add-to-cart and abort order submission | group, category, country, product | yes (add-to-cart + checkout) |
| **Country restriction** | Block ordering to specific destinations | country (+ any other scope) | yes (checkout guard) |
| **Redirect gated PDP** | Send a direct hit on a hidden product to a CMS page | per rule | yes |

Two ways to configure the above:

- **Basic mode** (default): one gate, driven entirely by System Configuration.
  Enough for "log in to see prices" wholesale shops.
- **Rules mode**: many named rules, each with independent scope, actions,
  enforcement level and priority. For multi-market, multi-brand or
  professional-only catalogues.

---

## Why you would use it

- **Wholesale / trade store** - require login to see any pricing; show retail
  customers a "call for trade pricing" message; block them from buying.
- **Professional-only products** - a range of products (veterinary, chemical,
  licensed) visible and buyable only to an approved "Professional" customer
  group, hidden from everyone else including in search.
- **Brand distribution contracts** - a brand you may not sell into certain
  countries: the products stay browsable but cannot be ordered to a blocked
  shipping destination, enforced at checkout.
- **Region / store gating** - different visibility per store view or website in
  a multi-store setup.

---

## Concepts

### The gate

A single engine (`b2baccess/gate`) answers every enforcement question. It reads
a set of **rules** from one of two sources and applies them uniformly, so the
storefront behaviour, the search index and the checkout guard always agree.

### A rule

A rule has:

- **Scope** (all AND-ed; an empty list means "any" for that dimension):
  - customer groups (empty = any group, including guests)
  - stores (empty or "All Store Views" = any store)
  - destination countries (matched against the shipping country at checkout)
  - categories (subcategories are included automatically)
  - products (by SKU or ID)
- **Actions**: hide from listings/search, hide price, block purchase, and an
  optional CMS redirect for direct hits on a hidden product page.
- **Enforcement**: where the rule bites.
  - `visibility` - hide only; browsing-time actions (price/listing), still
    allow checkout.
  - `checkout` - do not change what is shown, but block ordering.
  - `both` - apply both.
- **Priority** (lower first) and an optional per-rule message override.

A rule with no category and no product scope is **catalog-wide** (covers every
product), which is how "hide all prices from group X" is expressed.

Because a guest's country is unknown while browsing, country rules default to
`checkout` (or `both`): the authoritative block happens at order submission,
where the shipping address is finally known.

---

## Modes

Set **System > Configuration > Customers > B2B Access > General > Mode**.

### Basic mode (default)

Everything is configured in the config section:

- **Login Wall** - require login, redirect target (a CMS page or the login
  page), and an optional notice.
- **Restrictions** - hide prices, hide products entirely, block purchasing, and
  the hidden-price message.
- **Activation Matrix** - who/what the restrictions apply to: by customer group
  (OR) by category (subcategories included).

Basic mode reproduces the module's original single-gate behaviour exactly. Note
that "Gate by category" applies to all customer groups; for per-group hiding,
use Rules mode.

### Rules mode

A grid under **Customers > B2B Access Rules** (Add New Rule) with a three-part
form:

- **General** - name, active, priority, enforcement, message override.
- **Scope** - groups, stores, countries, categories, products (SKUs or IDs).
- **Actions** - hide listing, hide price, block purchase, redirect.

Rules evaluate by priority. Switching between modes never loses your Basic
configuration; the grid governs Rules mode only.

---

## Search integration

This module keeps search results consistent with the gate: a product hidden by a
`visibility`/`both` hide-listing rule never appears in search for a restricted
group. It subscribes once to the engine-neutral
`catalog_search_product_restrictions` event, which every search backend
dispatches per product during reindex, and contributes the customer-group IDs the
product is hidden from. Supported engines:

- [maho-module-meilisearch](https://github.com/mageaustralia/maho-module-meilisearch)
  - the index carries a `restricted_customer_group_ids` field and the storefront
  filters each query with `restricted_customer_group_ids != <currentGroupId>`.
- [maho-search](https://github.com/mageaustralia/maho-search) (pure-PHP Lucene) -
  the restricted groups are stored on the document and matching results are
  dropped for the current group at query time.

Notes:

- Run a full reindex after adding or changing hide-listing rules.
- Zero coupling: with no search module installed the events never fire and
  nothing breaks; with one present it works with no extra configuration.
- Country scope is intentionally **not** pushed into the search index (it is
  geo-agnostic and, for Meilisearch, CDN-cacheable); country is enforced
  server-side at checkout.

---

## Migrating from Amasty Customer Group Catalog (Magento 1)

A CLI importer maps `am_groupcat_rules` / `am_groupcat_product` into access
rules:

```bash
./maho b2baccess:import-groupcat --dry-run   # preview what would be imported
./maho b2baccess:import-groupcat             # import
```

Mapping: customer groups, stores, categories and product links carry across;
`remove_product_links` becomes hide-listing, `hide_price` becomes hide-price,
purchasing is blocked, and enforcement is set to `both`. It is idempotent
(imported rules are named `Groupcat: <name>` and skipped on re-run). Afterward,
set Mode = Rules and reindex your search engine.

---

## Install

```bash
composer require mageaustralia/maho-module-b2b-access
./maho cache:flush
composer dump-autoload   # compile the observer + route attributes
```

Rules mode adds two tables (`b2baccess_rule`, `b2baccess_rule_product`); the
setup script runs automatically on first load, including on installs upgrading
from 1.0.0.

---

## Enforcement points

Every behaviour is server-side; hiding UI alone is never relied upon.

| Concern | Hook |
|---|---|
| Login wall | `controller_action_predispatch` (redirect guests) |
| Hide price | `core_block_abstract_to_html_after` (rewrite price block) |
| Drop price sort option | `core_block_abstract_to_html_before` (listing toolbar) |
| Hide from listings | `catalog_block_product_list_collection` (filter collection) |
| Block add-to-cart | `controller_action_predispatch_checkout_cart_add` / `_addgroup` |
| Country / purchase guard | `sales_model_service_quote_submit_before` (abort submit) |
| Search restrictions (any engine) | `catalog_search_product_restrictions` (contribute group IDs) |

The add-to-cart guard also throws server-side, so a crafted
`?product=...&qty=` URL cannot bypass a hidden button. The checkout guard fires
before every order path (onepage, multishipping, admin, PayPal, ...) and names
the offending item(s), including child simples of configurable/bundle products.

---

## Configuration reference

| Path | Meaning |
|---|---|
| `b2baccess/general/enabled` | Master switch |
| `b2baccess/general/mode` | `basic` or `rules` |
| `b2baccess/login/required` | Require login to view the store |
| `b2baccess/login/redirect_cms` | CMS page for walled-out guests (blank = login page) |
| `b2baccess/login/message` | Notice shown on redirect |
| `b2baccess/price/hide` | Hide prices when the matrix matches |
| `b2baccess/price/hide_listing` | Also remove matched products from listings/search |
| `b2baccess/price/message` | Hidden-price message |
| `b2baccess/price/block_purchase` | Block purchasing of matched products |
| `b2baccess/matrix/by_customer` + `customer_groups` | Gate by customer group |
| `b2baccess/matrix/by_category` + `categories` | Gate by category (subcategories included) |

All paths are store-scoped.

---

## For developers

### Contributing search restrictions from another module

Any module can hide products from groups in search by subscribing to the same
event this module uses. Push integer group IDs onto the transport's
`restricted_customer_group_ids` array:

```php
#[\Maho\Config\Observer('catalog_search_product_restrictions')]
public function addRestrictions(\Maho\Event\Observer $observer): void
{
    $transport = $observer->getEvent()->getTransport();
    $existing  = (array) $transport->getData('restricted_customer_group_ids');
    $transport->setData(
        'restricted_customer_group_ids',
        array_values(array_unique(array_merge($existing, [1, 4]))),
    );
}
```

### Asking the gate directly

```php
/** @var MageAustralia_B2bAccess_Helper_Data $h */
$h = Mage::helper('b2baccess');
$h->shouldHidePrice($product);
$h->shouldHideListing($product);
$h->shouldBlockPurchase($product);
$h->getRestrictedGroupIdsForProduct($product, $storeId); // list<int>
```

---

## Tests

```bash
# Pure logic, no Maho needed (rule scope matching, enforcement flags):
vendor/bin/phpunit --testsuite Unit

# Full suite against a Maho install (rule persistence, basic-mode gate,
# Amasty import); skips gracefully without MAHO_ROOT:
MAHO_ROOT=/path/to/maho vendor/bin/phpunit
```

---

## Compatibility

- Maho 26.5+ / PHP 8.3+.
- Optional: `maho-module-meilisearch` (search-aware hiding),
  `maho-module-geoip` (country hints for the visibility layer).

## License

Open Software License 3.0 (OSL-3.0).
