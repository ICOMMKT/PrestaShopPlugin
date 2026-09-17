# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

A single PrestaShop module (`icommktconnector`) that connects a PrestaShop 1.6/1.7 shop to ICOMMKT.
The repo root **is** the module folder: it is deployed by copying it to `<prestashop>/modules/icommktconnector/`
and installing it from the Back Office (Modules → install), which runs `install()`.

There is no build system, no dependency manager, no test suite and no linter config in this repo.
The only local check available is a syntax check, e.g. `php -l icommktconnector.php`.
Anything beyond that (behaviour, SQL, routes) can only be verified on a real PrestaShop install.

## Architecture

### Two integrations in one module

The module bundles two unrelated feature sets that share only the configuration form:

1. **Push to ICOMMKT** (cron-driven, outbound) — abandoned carts and newsletter subscribers are POSTed to
   `https://api.icommarketing.com/Contacts/...`. Auth is a shared `ICOMMKT_SECURE_TOKEN` passed in the URL,
   so these endpoints are meant to be hit by a cron job.
2. **VTEX-shaped REST API** (inbound, pull) — ICOMMKT reads orders, order statuses and customers from the shop.
   Responses are deliberately formatted to mimic VTEX's OMS/Master Data payloads
   (`formatListOrder`, `formatCustomerDataToVTEX`). Auth is the `X-VTEX-API-AppKey` /
   `X-VTEX-API-AppToken` header pair, checked in `Icommktconnector::authorizeRequest()`.

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
| `abandomentcart.php` | `abandomentcart/{action}/{secure_token}{/:id_cart}` | self-contained |
| `sendtoicommkt.php` | `sendtoicommkt/{action}/{secure_token}` | self-contained |

### Routing

Routes are declared in `hookModuleRoutes()` (registered on install). The `abandomentcart` friendly route is only
registered when `ICOMMKT_FRIENDLY_URL == 1`; otherwise the feature is reached via
`index.php?fc=module&controller=abandomentcart&module=icommktconnector&action=...`.
`getFormattedLink()` / `getFormattedLinkUser()` build the example URLs shown in the Back Office and must stay in
sync with the routes — `getFormattedLink()` branches on the same `ICOMMKT_FRIENDLY_URL` flag, and clearing the
PrestaShop route cache is required after toggling it.

### Configuration keys

All stored via `Configuration::get/updateValue`, all defined in `getConfigForm()` / `getConfigFormValues()`
(adding a field means editing both):
`ICOMMKT_APPKEY`, `ICOMMKT_APPTOKEN` (VTEX-style API auth), `ICOMMKT_APIKEY` (ICOMMKT `Authorization` header),
`ICOMMKT_PROFILEKEY` (newsletter profile), `ICOMMKT_PROFILEKEY_ABANDON` (abandoned-cart profile),
`ICOMMKT_SECURE_TOKEN`, `ICOMMKT_DAYS_TO_ABANDON` (default `1`), `ICOMMKT_FRIENDLY_URL`.

### Database

- `install.sql` creates `PREFIX_commktconnector_abandomentcarts` (successfully sent carts) and
  `..._abandomentcarts_error`. `installDb()` splits the file on `;` and runs each statement.
- `addNewColumn()` **ALTERs a core PrestaShop table** — `emailsubscription` on 1.7+, `newsletter` on <1.7 —
  adding `is_send_icommkt` / `date_send_icommkt`; `uninstallColumns()` drops them. Both return `true`
  unconditionally, so a failing ALTER does not block install/uninstall.
- Membership of "already sent" is what prevents re-sending: carts by presence in
  `commktconnector_abandomentcarts`, subscribers by `is_send_icommkt = 1`.

### PrestaShop 1.6 vs 1.7

Version branching is done with `Tools::version_compare(_PS_VERSION_, '1.7.0.0', '>=')` and appears in
`addNewColumn()`, `uninstallColumns()` and `sendtoicommkt.php::sendUsersIcommkt()`. Any new query touching the
newsletter table needs the same branch.

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

The version is stored in **two** places that are currently out of sync: `config.xml` (`1.2.0`) and
`$this->version` in `icommktconnector.php::__construct()` (`1.2.2`). Update both when releasing.

## Documentation

`README.md` is the functional spec (in Spanish): configurable fields, the exact cron URLs for each action, the
tables created, and the known caveat that a customer with several carts matching `Days to abandon` may have a
different cart sent on a later run. Keep it updated when endpoints, config fields or tables change.
