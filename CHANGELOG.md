# Changelog

All notable changes to the Emporiqa PrestaShop module are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Entries were reconstructed on 2026-09-02 from the module's own release commits.
Until then this module was the only Emporiqa integration shipping without a
changelog, which left 1.2.5 through 1.2.8 with no recorded rationale even though
the work was real. Upgrade scripts exist for 1.2.0, 1.2.3 and 1.2.4 only; the
other releases need no data migration.

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
