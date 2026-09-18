# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

A single PrestaShop module that connects a PrestaShop shop to **icomm AI Marketing Cloud**.
Its technical name is `icommktconnector` — that is the folder name, the controller class prefix and the
`Configuration` key prefix, and it must **not** be renamed; only `displayName` (in the constructor and in
`config.xml`) carries the product name.
The repo root **is** the module folder: it is deployed by copying it to `<prestashop>/modules/icommktconnector/`
and installing it from the Back Office (Modules → install), which runs `install()`.

There is no build system, no dependency manager, no test suite and no linter config in this repo.
The only local check available is a syntax check, e.g. `php -l icommktconnector.php`.
Anything beyond that (behaviour, SQL, routes) can only be verified on a real PrestaShop install.

## Architecture

### What the module does today

On a **fresh install** there is exactly one feature set: an **inbound read API** (pull). icomm reads orders,
order statuses, customers and the product catalogue from the shop. Auth is the `X-VTEX-API-AppKey` /
`X-VTEX-API-AppToken` header pair, checked in `Icommktconnector::authorizeRequest()`. Orders and customers are
deliberately formatted to mimic VTEX's OMS/Master Data payloads (`formatListOrder`,
`formatCustomerDataToVTEX`); the catalogue endpoint, added in 1.3.0, is **not** VTEX-shaped — it returns a plain
`{products, paging}` payload.

The **outbound cron push** to `https://api.icommarketing.com/Contacts/...` (abandoned carts, newsletter
subscribers, both guarded by `ICOMMKT_SECURE_TOKEN` in the URL) is entirely legacy — see below.

### The legacy pattern

Two features have been withdrawn but **not deleted**, because shops already using them must keep working:
abandoned carts (1.3.0) and the newsletter subscriber push (1.4.0). Both follow the identical pattern, and any
future withdrawal should copy it:

| Feature | Flag method | Cached key | Detected by |
| --- | --- | --- | --- |
| Abandoned carts | `isAbandonLegacy()` | `ICOMMKT_ABANDON_LEGACY` | `ICOMMKT_PROFILEKEY_ABANDON` filled, or rows in `commktconnector_abandomentcarts` |
| Newsletter push | `isNewsletterLegacy()` | `ICOMMKT_NEWSLETTER_LEGACY` | `ICOMMKT_PROFILEKEY` filled, or the `is_send_icommkt` column already added to the subscribers table |

Detection is lazy and cached in `Configuration`, so the probe query runs once per shop. `install()` calls the
`detect*` methods rather than writing `0`, so reinstalling on a shop that used a feature does not lose it.
Everything feature-specific hangs off the flag: config fields, routes, Back Office blocks and the controller's
own `init()` guard (which redirects to 404, closing the non-friendly `index.php?fc=module&...` URL too).
`ICOMMKT_APIKEY` and `ICOMMKT_SECURE_TOKEN` are shown only when at least one legacy feature is active, since
nothing else reads them. **When touching legacy code, keep it behind its flag rather than removing it.**

### The fat-module / thin-controller split

Practically all logic lives in `icommktconnector.php` (the `Icommktconnector` Module class, ~1300 lines).
The front controllers in `controllers/front/` are dispatchers: they override `init()` (not `initContent()`),
grab the module with `Module::getInstanceByName('icommktconnector')` and call a public method on it.
Consequence: **when adding API behaviour, add a public method to the module class**, not to the controller.

The two token-guarded controllers (`abandomentcart.php`, `sendtoicommkt.php`) are the exception — they hold
their own logic and their own cURL calls to the ICOMMKT API.

Controllers and module methods terminate the request with `exit`/`die`/`json_encode` and never return control
to PrestaShop's rendering pipeline. Response headers are set by `controllerSetRespondeHeaders()`, which flushes
any output buffer first.

| Controller | Route | Calls |
| --- | --- | --- |
| `oms.php` | `icommkt/oms/pvt/status_list`, `icommkt/oms/pvt/orders{/:id_order}` | `getStatusList()`, `getSingleOrder()`, `getOrders()` |
| `masterdata.php` | `icommkt/dataentities/{entity_code}/search` (only `cl` is supported) | `getClients()` |
| `catalog.php` | `icommkt/catalog/pvt/products{/:id_product}` | `getProducts()` |
| `abandomentcart.php` | `abandomentcart/{action}/{secure_token}{/:id_cart}` (legacy only) | self-contained |
| `sendtoicommkt.php` | `sendtoicommkt/{action}/{secure_token}` (legacy only) | self-contained |

### Routing

Routes are declared in `hookModuleRoutes()` (registered on install). The `abandomentcart` friendly route is only
registered when the shop is in legacy mode **and** `ICOMMKT_FRIENDLY_URL == 1`; the API routes are always
registered. Every route has an always-available fallback:
`index.php?fc=module&controller=<name>&module=icommktconnector&...`.
`getFormattedLink()` / `getFormattedLinkUser()` build the example URLs shown in the Back Office and must stay in
sync with the routes — `getFormattedLink()` is hardcoded to `abandomentcart` in both of its branches and is only
used by that flow. **Adding or changing a route requires clearing the PrestaShop route cache**, otherwise the
friendly URL 404s.

### The catalogue endpoint

`getProducts()` returns one row per real SKU: `LEFT JOIN product_attribute` makes a product with N combinations
produce N rows and a product without combinations produce one row with `id_product_attribute = 0`.

Two invariants worth preserving when editing it:
- The `FROM` and `WHERE` are built once (`getProductsSqlFrom()`, `getProductsSqlFilters()`) and reused by both the
  data query and the `COUNT`, so the total can never disagree with the listing. Anything that does not affect
  cardinality (stock, cover image, combination name) is a **scalar subquery in the SELECT**, not a join.
- Multishop is scoped with an explicit `ps.id_shop = X` using the shop `authorizeRequest()` resolved into
  `$context_id_shop`, *not* `Shop::addSqlRestriction()` — the controller skips `parent::init()`, so
  `Shop::getContext()` is unreliable. For the same reason `getProducts()` has to load `context->country`,
  `context->currency` and `context->shop` by hand before calling `Product::getPriceStatic()`.

Prices go through `Product::getPriceStatic()` (N+1, but bounded by `per_page`, max 200) because taxes, specific
prices and combination impacts have to be right; the raw SQL price ships alongside as `basePriceTaxExcl`.

**PrestaShop 8/9 refuse to price a product with no cart and no employee in the context** — `getPriceStatic()`
throws *"If no employee is assigned in the context, cart ID must be provided to this method"*, which is exactly
the situation of a read API. `getProducts()` therefore seeds an unsaved `Cart` (and an `Employee`) into the
context before pricing anything. Do not remove that block: the endpoint 500s on every row without it. Pricing is
additionally wrapped by `getCatalogPrice()`, which falls back to the SQL base price and logs, so one bad product
cannot take down a whole page of the catalogue.

`&debug=1` turns the generic 500 into a JSON diagnosis. It deliberately does **not** wrap anything in
`try/catch` — `enableCatalogDebug()` installs a `set_exception_handler` plus a `register_shutdown_function`, so
the normal code path is byte-for-byte unchanged when debug is off, and the shutdown hook catches what a
`try/catch` cannot (OOM, execution timeout). It also surfaces SQL errors, which in production return `false`
silently and would otherwise show up as an empty listing. Keep that property if you extend it.

### The configuration screen

`getContent()` renders `views/templates/admin/settings.tpl` — just the logo and the product name — followed by
`renderForm()`. Deliberately nothing else: the help text, the example URLs and the cron samples were removed in
1.4.1 because most of them described withdrawn features. The template takes a single variable, `module_dir`.

### Configuration keys

All stored via `Configuration::get/updateValue`, all defined in `getConfigForm()` / `getConfigFormValues()`
(adding a field means editing both — `postProcess()` iterates over the values array, so a key missing there is
never saved).

Always shown: `ICOMMKT_APPKEY`, `ICOMMKT_APPTOKEN` (API auth).
Shown when either legacy feature is active: `ICOMMKT_APIKEY` (icomm `Authorization` header),
`ICOMMKT_SECURE_TOKEN` (cron URL guard).
Newsletter-legacy only: `ICOMMKT_PROFILEKEY`.
Abandoned-cart-legacy only: `ICOMMKT_PROFILEKEY_ABANDON`, `ICOMMKT_DAYS_TO_ABANDON` (default `1`),
`ICOMMKT_FRIENDLY_URL`.
Not form fields: `ICOMMKT_ABANDON_LEGACY` and `ICOMMKT_NEWSLETTER_LEGACY` cache the legacy detection.

### Database

Since 1.4.0 a fresh install **touches no tables at all** — it creates none of its own and no longer ALTERs any
core table. Both of those behaviours survive only in legacy shops:

- `commktconnector_abandomentcarts` and `..._abandomentcarts_error` still exist where abandoned carts were used;
  `uninstallDb()` drops them with `IF EXISTS`.
- `addNewColumn()` **ALTERs a core PrestaShop table** (`getNewsletterTable()`: `emailsubscription` on 1.7+,
  `newsletter` below) adding `is_send_icommkt` / `date_send_icommkt`. It now runs from `install()` only when the
  shop is newsletter-legacy, and `uninstallColumns()` returns early otherwise. Both return `true`
  unconditionally, so a failing ALTER does not block install/uninstall.
- Subscribers are prevented from being re-sent by `is_send_icommkt = 1`.

### Which PrestaShop versions this actually runs on

The module was written for **1.6/1.7** and all its version branching is expressed as
"1.7 or newer vs. older". It has since been seen running on **PrestaShop 9.1.1 with PHP 8.4**, so the `>= 1.7.0.0`
branches are in practice "modern PrestaShop" branches. Treat 8.x/9.x as supported-but-unverified: the catalogue
endpoint has been adapted to them (see the pricing note above), but the newsletter push and the VTEX-shaped
orders/customers endpoints have **not** been reviewed against 8.x/9.x — `emailsubscription`, `OrderDetail` and
`Link` have all changed across those releases.

### PrestaShop 1.6 vs 1.7

Version branching is done with `Tools::version_compare(_PS_VERSION_, '1.7.0.0', '>=')` and appears in
`getNewsletterTable()` — the single source of truth for the subscribers table name, used by `addNewColumn()`,
`uninstallColumns()` and the legacy detection — plus `sendtoicommkt.php::sendUsersIcommkt()` (which still
carries its own copy of the branch) and `getProductsSqlFilters()` (`product.state` only exists in 1.7+).

One catalogue difference is handled with `method_exists` instead: `ImageType::getFormatedName()` (1.6) vs
`getFormattedName()` (1.7) — 1.7.0-1.7.5 kept both, so the version check would be wrong.
`Link::getProductLink()` and `Product::getPriceStatic()` only use their first 7 positional arguments, which are
identical across both branches.

## Conventions to preserve

- **Use PrestaShop's `Tools::` wrappers, not raw PHP**: `Tools::substr`, `Tools::strtolower`, `Tools::strtoupper`,
  `Tools::getValue`, `Tools::file_get_contents`, `Tools::version_compare`. The PrestaShop module validator rejects
  the bare PHP equivalents, which is why e.g. `getallheaders()` is reimplemented locally.
- **Every directory needs an `index.php`** (the PrestaShop anti-listing stub). Add one to any new folder.
- Queries are hand-built strings; multishop scoping goes through `Shop::addSqlRestriction(...)` and user input
  through `(int)` casts or `pSQL()`. Keep both when editing SQL.
- Code style is PSR-2-ish with a ~120 char line limit, `array()` syntax (not `[]`), and the licence docblock
  header at the top of every PHP/TPL file.
- User-facing strings go through `$this->l('...')` in PHP and `{l s='...' mod='icommktconnector'}` in Smarty.

## Version bumping

The version is stored in **two** places — `config.xml` and `$this->version` in
`icommktconnector.php::__construct()` — which were out of sync until 1.3.0 realigned them. PrestaShop reads
`$this->version`. Update both when releasing. Current: **1.4.4**.

`config.xml` is a cache PrestaShop regenerates from the constructor, so `tab`, `author`, `version` and the
descriptions must be changed in `__construct()` — editing only the XML has no lasting effect. `tab` must be one
of PrestaShop's predefined identifiers (`advertising_marketing` here); an unrecognised value silently files the
module under "Other".

The product name lives in `displayName` (constructor) and in `config.xml`'s `<displayName>`/`<description>`,
plus the `<h2>` of `views/templates/admin/settings.tpl`. `$this->name` is never part of it.

### Caching gotcha when shipping an update

PrestaShop keeps Smarty's compiled templates keyed by file path, and browsers cache module images by URL, so a
shop can keep rendering the **old** `.tpl` and the **old** logo even after the new files are on disk — that is
what happened in 1.4.0 (new PHP + stale template). Clearing `var/cache/` fixes it, but the reliable trick is to
**rename the file**: `configure.tpl` became `settings.tpl` and `views/img/logo.jpg` became
`views/img/logo-icomm.png` for exactly this reason. `logo.png` and `logo.gif` at the module root cannot be
renamed (PrestaShop requires those names), so a hard refresh is still needed for the module-list icon.

## Documentation

`README.md` is the functional spec (in Spanish): the catalogue endpoint with its parameters and payload, the
configurable fields, the exact cron URLs for each action, and the legacy notice on abandoned carts (kept because
it is the only documentation the shops still using it have). Keep it updated when endpoints, config fields or
tables change.
