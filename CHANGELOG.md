# Changelog

All notable changes to the Emporiqa PrestaShop module are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Entries were reconstructed on 2026-09-02 from the module's own release commits.
Until then this module was the only Emporiqa integration shipping without a
changelog, which left 1.2.5 through 1.2.8 with no recorded rationale even though
the work was real. Upgrade scripts exist for 1.2.0, 1.2.3, 1.2.4 and 1.3.0
only; the other releases need no data migration.

## [1.3.0] - 2026-10-05

Tested on PrestaShop 8.1 to 9.2. Needs PHP 8.0 or newer.

### In short
- Order tracking works as in 1.2.8 and stays on. It also works with
  Emporiqa's ready-made rules: the new Order status rule replaces it, and the
  settings page shows each rule as On or Not added.
- On a multistore install you choose which shops show the chat.
- A full-page cache can no longer hand one shopper's identity to another in
  the chat.
- The configuration page is clearer and available in French and German.

### Added
- Tracking numbers from PrestaShop 9.2's improved shipments: order lookups
  read `{prefix}shipment` too (by order, skipping deleted and cancelled
  shipments), alongside the OrderCarrier number, each with its carrier and
  tracking link. Before 9.2 the table does not exist and is not queried.
- Ready-made rules: an `order_status` endpoint for Emporiqa
  (`controllers/front/action.php`, `?key=order_status`), read-only, signed
  both ways with per-purpose HKDF keys, deduped on `request_id` for 10
  minutes. One answer for an unknown order and a wrong email; missing fields
  are rejected before the lookup. A new `actionEmporiqaOrderStatus` hook can
  adjust the answer. The same controller (`?key=verify`) answers the connect
  origin proof and the endpoint challenge.
- Ready-made rules show once Emporiqa says the store has them. `/connect/exchange`
  and the Test connection dry run answer `rules_available` and `live_rules`;
  the module keeps them (`EMPORIQA_RULES_AVAILABLE`, `EMPORIQA_LIVE_RULES`),
  so a connected shop learns it on its next Test connection without
  reconnecting. Then the settings page lists the supported rules, each On or
  Not added with an Open in Emporiqa link, and suggests switching the old order
  tracking off once Order status is on. No reconnect is needed after the
  upgrade: the section shows the module's Order status address with a Copy
  button; Emporiqa usually finds it by itself when the rule is added, and
  otherwise the owner pastes it into the rule, where Emporiqa confirms it
  through the existing `?key=verify` action. Until Emporiqa has said so (a
  shop not connected yet, or not tested since the upgrade), none of it shows
  and order tracking keeps its own visible section.
- The Sync tab shows when products and pages were last fully synced, whether
  that sync completed, and whether automatic updates are getting through.
- Sync webhooks carry the scheme-2 `X-Emporiqa-Webhook-Signature` beside the
  old `X-Webhook-Signature`, plus `X-Emporiqa-Plugin-Version`.
- Connect sends the actions base URL, keeps the PKCE verifier until the
  exchange returns, and shows the text of a 409 (an owner must confirm a move).
- Multistore: a Shops setting (under Shops and languages) chooses which shops
  are synced and show the chat, and shows each shop's Emporiqa channel key.
  Settings stay global (one Emporiqa store, one channel per shop), and the
  page now says so when the back office is in a single-shop or group context.

### Changed
- Requires PHP 8.0 or newer, as the PrestaShop coding standard's trailing
  commas in argument and parameter lists do. 1.2.6 to 1.2.8 already failed to
  load on PHP 7.x for that reason. On PHP 7 the module now loads without a
  fatal error: install refuses with "Emporiqa needs PHP 8.0 or newer", the
  configuration page shows the same message, and an installed copy does
  nothing on the storefront.
- The customer token is no longer written into the page. A logged-in shopper's
  token comes from an uncached endpoint (`/module/emporiqa/token`) when the chat
  opens, and now carries `aud`, so a full-page cache can never hand one
  customer's identity to another. A guest is answered without any request, and
  a page view that never opens the chat makes none either. A signed-in
  shopper's token is reused for at most five minutes, so a tab left open
  never hands the chat an expired one.
- Order tracking stays on by default on new installs and keeps its value on
  upgrade. It moves under Advanced, as deprecated, only where ready-made rules
  are offered. A missing email is still refused before the lookup, with the
  same 400 "Email verification required." as 1.2.8.
- The customer token travels to embed.js on a `MessageChannel` port instead
  of `window.postMessage`, so other scripts on the page no longer see it. A
  request without a port (an older embed.js) is still answered on the window.
  A non-2xx token answer is never reused, and the script loads deferred.
- The cart and token scripts and their config go through PrestaShop's asset
  manager (`registerJavascript`, `Media::addJsDef`) instead of inline tags;
  the header template keeps only the widget script. The `frame-ancestors`
  CSP `<meta>` is gone: browsers ignore that directive in a `<meta>`.
- Settings page wording: shops are listed as "shown in Emporiqa as ..."
  instead of "channel ...", and languages as "English (en-US)" instead of
  PrestaShop's "English (English)". Module buttons keep their case on
  PrestaShop 8, the logo no longer sits under the page subtitle on
  PrestaShop 8, and the header wraps on narrow screens.
- The manual sync pages by product id instead of OFFSET, so a product disabled
  mid-sync can no longer shift the pages, skip a live product, and get that
  product deleted when the session completes.
- The configuration page is reorganised: Shops and Languages have their own
  section next to the connection, the sync toggles are named Auto-sync
  products and Auto-sync pages, Sync All is the main sync button, and every
  help text says where to click next. The welcome card no longer says the tab
  can be closed during the first sync: the sync runs from the open tab.
- The configuration page ships with French and German translations
  (`translations/fr.php`, `translations/de.php`). They also cover the sync
  log, the Test connection results and the one-click connect errors, which
  were English only.
- Test connection shows its sample product and page data under a collapsed
  Technical details toggle instead of in the result.
- The back-office CSS and JS URLs carry the module version and file time, so
  browsers load the new files after an update.

### Fixed
- Install brings every module table up to the current schema
  (`EmporiqaSchema::ensure()`, shared with the 1.3.0 upgrade): a table kept
  from an older version gets its missing columns and indexes, so a leftover
  `emporiqa_connect_nonce` no longer breaks one-click connect with "Unknown
  column `exchanging_at`".
- Uninstall from a single-shop or group context removes the module's
  settings for every shop and group, not only the current one.
- A language unticked under Shops and languages is neither synced nor
  offered: its storefront pages show no chat, as with an unticked shop.
- Saving settings drops language codes that are not active PrestaShop
  languages.
- The chat language is chosen among the languages of the shop being viewed,
  so another shop's language, or a deactivated one, is never offered.
- `order_status` only finds orders placed in the shops chosen under Shops and
  languages, and for a reference shared by a split checkout it reports the newest
  order with every order's tracking.
- A signed-in shopper can look up a guest order they placed by giving its
  email, as they could when signed out.
- `order_status` is rate limited per order number and per email (10 per 10
  minutes) and per shop (300), answered with a signed 429 `rate_limited`
  whose `data.scope` says which limit was hit (`value` or `store`), with a
  `Retry-After` header giving the seconds until the window ends.
- `order_status`, `verify` and legacy order tracking answer while the shop is
  in maintenance mode or geo-blocks the caller's country.
- One-click connect always sends an https actions URL, or says why it can't.
- A shop selection that no longer matches any active shop syncs nothing and
  shows a warning, instead of silently widening to every shop.
- Product, page and order webhooks are sent after the response (PHP-FPM and
  LiteSpeed), and product and page webhooks stop for the rest of the request
  after Emporiqa fails to answer once. `order.completed` is sent after the
  response both at checkout and on a later paid status, goes out before the
  product and page webhooks, is never skipped by that stop, and one Emporiqa
  does not accept is sent again on the order's next paid status.
- PrestaShop's cookie is written before the response is finished early, so a
  login, a new cart or a back-office change made in that request is kept.
- The cart API answers JSON on an error as well as an exception.
- Test connection warns when this server's clock is more than 2 minutes off
  Emporiqa's (Emporiqa refuses signatures more than 5 minutes off), and a sync
  refused for a clock more than 5 minutes off logs that once a day.
- On PHP 7, a 1.3.0 upgrade stops with "Emporiqa 1.3.0 needs PHP 8.0 or
  newer" instead of a bare failure, uninstall still removes the settings and
  tables, and the order status and customer token endpoints answer instead of
  failing to parse.
- Legacy order tracking only finds orders placed in the shops chosen under
  Shops and languages.
- No `curl_close()` calls, deprecated in PHP 8.5 and a no-op since 8.0.
- On PHP 7 the cart API and legacy order tracking answer a JSON 503, and
  one-click connect returns to the settings page (which says PHP 8 is
  needed), instead of a fatal error: their controllers stay PHP 7 parseable
  and load the PHP 8 bodies from `classes/` only on PHP 8.
- One-click connect waits up to 20 seconds for `/connect/exchange` (was 10),
  well past the 4 seconds Emporiqa now gives its origin proof in total, so a
  slow shop no longer keeps its old secret after Emporiqa issued a new one.
- The cart API is POST only and answers `Cache-Control: no-store, private`.
- Legacy order tracking is rate limited like `order_status` (per order
  number, per email and per shop), answering 429 with `Retry-After`.
- The sync AJAX compares its token in constant time and also requires the
  employee's configure permission on the module.
- The storefront no longer loads a `Shop` object per shop on every page view
  to build the channel map; a single-shop install reads no shop list at all.
- The `{prefix}shipment` existence check is one `SHOW TABLES LIKE` per
  request instead of an `information_schema` query per order lookup.
- One JSON response helper (`EmporiqaJsonResponse`) replaces eight copies of
  the "clear buffers, send JSON, exit" block.
- The Test connection button is re-enabled after every answer.
- The manual sync retries a failed batch once before moving on.
- An error (not only an exception) in a deferred sync is logged instead of
  breaking the request's shutdown.

### Upgrade notes
- The upgrade clears PrestaShop's Smarty and CCC caches. **If you use a
  full-page cache module** (or a CDN that caches HTML), purge it after
  upgrading: cached pages still carry the 1.2.x header markup.
- Order tracking keeps working as it did: it stays offered, and a flag still
  carrying the 1.0.x `0` is set to 1, since 1.1 to 1.2.8 answered regardless
  of it and 1.3.0 is the first version to read it. Ready-made rules stay
  hidden until the next connect or Test connection says Emporiqa offers them.
- **Downgrading to 1.2.8 needs a reconnect.** Once 1.3.0 has synced, Emporiqa
  refuses the old signature for this store, so a 1.2.8 rollback cannot sync
  until you click Connect again.

### Known issues (deferred)
- Legacy order tracking (`controllers/front/ordertracking.php`) has no replay
  dedupe.
- The cart API token is still in the page HTML (now through
  `Media::addJsDef`), so a full-page cache must not cache pages per visitor.
- `On` / `Not added` on the ready-made rules panel is as fresh as the last
  connect or Test connection.
- `emporiqa.php`, the upgrade scripts and the controller shells keep no
  trailing commas in calls and parameters, which the coding standard asks
  for: they must parse on PHP 7.2.
- The multistore guard in `uninstallDb()` is dead code: it runs after
  PrestaShop has already removed the module's shop rows.

## [1.2.8] - 2026-08-22

### Added
- Order tracking now returns `carrier_delay`, PrestaShop's own per-carrier
  delivery-time text, so the chat can tell a shopper how long a carrier usually
  takes instead of only where the parcel is.

### Fixed
- The carrier is loaded in the order's language, with a shop-agnostic fallback,
  so the carrier name and tracking link survive cross-shop lookups in multistore.

## [1.2.7] - 2026-07-09

### Security
- **A full sync can no longer finalize after a failed batch.** `sync.complete`
  tells Emporiqa to drop anything it did not see in the session, so one failed
  batch could remove products that still exist in the shop from the chat's
  index. The sync now stops and asks the merchant to re-run it. Nothing in the
  PrestaShop catalog was ever modified; this affected only the chat's index.
- **Guest carts are CSRF-protected** with a per-visitor token.

### Changed
- Brought the codebase to the PrestaShop coding standard required for
  marketplace validation.

## [1.2.6] - 2026-06-21

### Added
- **Quantity-based volume pricing** syncs for products and combinations. Each
  price entry gains an optional `tier_prices` list of `{min_quantity, price}`,
  computed by PrestaShop at each break, scoped to the public customer group,
  default country, and the entry's shop and currency. Group-, customer- and
  country-restricted B2B tiers are deliberately excluded, and a payload without
  quantity discounts stays byte-identical.

### Changed
- Documented tier pricing and the 1.2.5 order-tracking shipping fields in
  `README.md` and `README-FR.md`.

## [1.2.5] - 2026-06-19

### Added
- Order tracking returns `carrier`, `tracking_number` and `tracking_url`. The
  tracking URL is composed from the carrier's URL template using core's `@`
  placeholder, and a carrier name stored as the literal `0` sentinel resolves to
  the shop name, matching PrestaShop core behaviour.

### Changed
- Coding-standard alignment for marketplace validation: const visibility,
  unqualified `Exception`, trailing commas in multiline calls.

## [1.2.4] - 2026-06-05

### Security
- Hardened the chat session id path, which cleared a static-analysis false
  positive that misread the `Hook::exec` dispatcher as an OS command sink.
  `getEmporiqaSessionId()` now rebuilds the `emporiqa_sid` cookie value by
  construction from a whitelist rather than passing the raw string, and
  `hookActionValidateOrder` attaches the session id to the order payload after
  the `actionEmporiqaFormatOrder` hook, so the cookie value never flows through
  hook dispatch. Behaviour is unchanged and the webhook payload still carries
  `emporiqa_session_id`.

## [1.2.3] - 2026-06-05

### Added
- A lightweight `product.availability` event for stock-only changes
  (`actionUpdateQuantity`, `actionProductOutOfStock`) instead of a full product
  re-sync.
- `condition`, `is_virtual`, `available_for_order` and `max_order_quantities`
  on product and combination payloads.
- `README-FR.md`, the French module README.

### Fixed
- Currency-scoped specific prices are resolved per currency, so a
  currency-targeted promotion is no longer dropped.

## [1.2.1] - 2026-05-29

### Security
- `frame-ancestors` Content-Security-Policy header on storefront pages.
- `pSQL()` applied to the `emporiqa_sid` cookie read.
- `display_errors` suppressed during module upgrade.

### Fixed
- Structured error handling around `initSync()`, and a generic JS message on a
  failed sync request rather than a raw error.
- `uniqid()` fallback in `generateUuid()` when `random_int()` fails.
- Corrected the minimum-PrestaShop comments from 8.0+ to 8.1+.

## [1.2.0] - 2026-05-26

### Added
- **One-click Connect.** A signed handshake links the shop to Emporiqa in one
  click, through the new `AdminEmporiqaConnect` controller. Manual credential
  paste stays available for HTTP-only sites.
- Wider catalog-change coverage: SpecificPrice, Currency, Tax, TaxRulesGroup,
  CartRule, ProductOutOfStock, Category, Manufacturer, Image and Language hooks
  now re-sync the affected entities automatically.
- A "Send my catalog" welcome card on the Sync tab after a successful connect.
- `upgrade-1.2.0.php` for the in-place 1.1.1 to 1.2.0 upgrade (tab, nonce table,
  new hooks).

### Changed
- The synchronous webhook send is capped at 1.5s total and 500ms connect, down
  from 4s, so saving a product in the admin stays fast.
- Settings page rewritten in plain language with grouped sections.
- Minimum PrestaShop raised to 8.1.0, matching the tested floor and the
  marketplace claim.

## [1.1.1] - 2026-05-18

### Fixed
- **Multistore sync.** Page and product formatters reload each entity scoped to
  the channel's shop, so a sync produces correct data regardless of which shop
  the admin has selected. Previously, running the admin AJAX in a shop where a
  page or product had no translations produced payloads with empty titles, links
  and categories, and the webhook API rejected page batches with a `dict_type`
  validation error. Category paths are resolved with a nested-set query scoped
  by shop, independent of the request's shop context.

## [1.1.0] - 2026-04-15

### Changed
- "Webhook Secret" renamed to "Connection Secret" throughout the UI.
- Sync toggles, languages, webhook URL and batch size merged into the Advanced
  section; Test Connection moved to the Sync tab.
- Order tracking and in-chat cart operations are always enabled.
- Order tracking always enforces customer email verification.

### Added
- Order Tracking URL with a Copy button in Connection Settings.

### Fixed
- Save button visibility on the configuration page.
- Browser autocomplete disabled on the Store ID and Connection Secret fields.
