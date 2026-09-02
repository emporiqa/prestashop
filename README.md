# Emporiqa: AI Chatbot for PrestaShop

The [Emporiqa](https://emporiqa.com) AI chatbot for PrestaShop 8.1+ and 9 is an online salesperson that closes sales in your store: shoppers describe what they need or upload a photo of something they like, it finds matching products from your catalog, handles objections like "too expensive" with alternatives instead of a discount, answers questions from your CMS pages, and walks shoppers to cart and checkout in 65+ languages. This module syncs your product catalog and CMS pages to Emporiqa, embeds the chat widget on your storefront, and exposes endpoints for in-chat cart operations and order tracking.

[![Emporiqa chat widget open on a storefront, answering which laptop under 1200 euros suits a student editing video: it names the model and price, then flags the storage trade-off before offering to add it to the cart](docs/images/07-storefront.webp)](https://demo.emporiqa.com)

- **Integration overview**: [emporiqa.com/integrations/prestashop/](https://emporiqa.com/integrations/prestashop/)
- **Full documentation**: [emporiqa.com/docs/prestashop/](https://emporiqa.com/docs/prestashop/) (webhook format reference, hook examples, troubleshooting)
- **Features**: [emporiqa.com/features/](https://emporiqa.com/features/) · **FAQ**: [emporiqa.com/faq/](https://emporiqa.com/faq/) · **Pricing**: [emporiqa.com/pricing/](https://emporiqa.com/pricing/)
- **Live demo**: [demo.emporiqa.com](https://demo.emporiqa.com) and a [30-second video](https://www.youtube.com/watch?v=as54_uvk038). That demo sells electronics, and the behavior is the same on any catalog.

## Requirements

- PrestaShop 8.1+ or 9.x
- PHP 7.4+ on PrestaShop 8.1, PHP 8.1+ on PrestaShop 9
- An [Emporiqa account](https://emporiqa.com/platform/create-store/). Sign up with no card; $25 of signup credit (~100 free conversations) auto-applied

## Installation

1. Get the module from the [PrestaShop Addons Marketplace](https://addons.prestashop.com/) (paid) or from [GitHub](https://github.com/emporiqa/prestashop) (free).
2. In your PrestaShop back office, go to **Modules > Module Manager > Upload a Module** and upload `emporiqa.zip`.
3. Click **Configure** on the Emporiqa module.
4. Click **Connect to Emporiqa**. A new tab opens on emporiqa.com. Create a free account (no card required, $25 of signup credit) or sign in if you already have one, then pick the store you want to connect (or create a new one). The module is connected when you return.
5. On the **Sync** tab, click **Send my catalog**. Products, pages, and combinations flow through; the widget appears on your storefront when the first product arrives.

**On HTTP, or prefer to paste credentials yourself?** Expand **Edit credentials manually** on the Configure page. Paste a **Store ID** and **Connection Secret** from your Emporiqa dashboard under **Settings → Integration**. Both flows reach the same place.

For order tracking, copy the **Order Tracking URL** shown on the Configure page and paste it into your Emporiqa dashboard under **Integration → Order tracking** (the URL is also auto-derived by one-click connect on most setups).

## Configuration

All settings are managed from the module configuration page (**Modules > Emporiqa > Configure**):

**Connection Settings**

The recommended path is **Connect to Emporiqa** (one-click handshake, no credentials to paste). For HTTP sites or manual setup, expand **Edit credentials manually**:

| Setting | Description | Default |
|---------|-------------|---------|
| Store ID | Your Emporiqa store identifier (filled automatically by one-click connect) | (none) |
| Connection Secret | HMAC-SHA256 signing secret (filled automatically by one-click connect) | (none) |
| Order Tracking URL | Read-only endpoint to paste into your Emporiqa dashboard | auto-generated |

**Advanced**

| Setting | Description | Default |
|---------|-------------|---------|
| Sync Products | Enable real-time product sync | On |
| Sync Pages | Enable real-time CMS page sync | On |
| Enabled Languages | Languages included in sync payloads | All active shop languages |
| Webhook URL | Emporiqa webhook endpoint | `https://emporiqa.com/webhooks/sync/` |
| Batch Size | Products/pages per webhook request during bulk sync | 25 |

Order tracking (with customer email verification) and in-chat cart operations are always enabled. No configuration needed.

## AI disclosure

The chat's default greeting tells the shopper it is the store's AI assistant, in every language the chat speaks. A custom greeting must keep that disclosure; one that drops it is refused when you save it. Section 8.6 of the [Emporiqa Terms](https://emporiqa.com/terms-of-service/) treats removing the disclosure, including through custom CSS or custom code, as a breach.

## Keeping your catalog in sync

The module pushes product, page, and order changes to Emporiqa automatically as they happen via PrestaShop hooks. Per-product changes such as scheduled promos (SpecificPrice), image edits, and combination edits re-emit the affected product on their own; pure stock/out-of-stock changes emit a compact availability-only update instead of rebuilding the whole product.

Some changes affect the whole catalog (category or brand renames, currency rate refreshes, tax-rate or tax-rules-group edits, cart-rule changes, new languages enabled). Running a synchronous per-product re-sync from those hooks would block the admin request, so the module logs an actionable warning in **Advanced Parameters → Logs** instead and leaves the catalog refresh to a manual run.

Re-run a full sync from the **Sync** tab when:

- You see one of the "catalog-wide change" warnings in the PrestaShop log
- You add a new shop in multi-shop mode (existing products won't carry the new shop's data until something else touches them)
- You import products in bulk from a CSV file (PrestaShop sometimes bypasses standard save hooks during bulk imports)
- A custom script, migration, or another module writes catalog data directly to the database
- Emporiqa was unreachable for an extended period (network outage, planned maintenance, expired credentials)

As a safety net, run a full sync once a week to catch any drift that may have built up from background failures.

## Product payload fields

Beyond the fields shown in the [webhook payload reference](https://emporiqa.com/docs/prestashop/), the full product and combination payload carries these PrestaShop-native merchandising and pricing fields:

- `condition`: string or null; PrestaShop's product `condition` (`"new"`, `"used"`, or `"refurbished"`).
- `is_virtual`: boolean; true for digital products with no shipping.
- `available_for_order`: boolean; false for display-only / catalog-mode products. The assistant still describes these but won't add them to the cart.
- `max_order_quantities`: per-channel dict (`{channel: int|null}`) of the maximum allowed per-order quantity. PrestaShop has no native per-order maximum, so this currently always ships `null` (no limit). The field is included for cross-platform contract parity, so a future custom source can populate it.
- `tier_prices`: per-currency list of quantity-based volume discounts (`[{min_quantity, price}]`), present on a price entry only when the product or combination has PrestaShop quantity discounts configured. Each tier reflects the public (guest) shopper's unit price at that break. Group-, customer-, or country-restricted (B2B) tiers are intentionally excluded.

These flags are part of the full product and combination payload, not the lightweight `product.availability` event, which carries only the identification number, SKU, per-channel availability statuses, and stock quantities.

## Module structure

```
emporiqa/
├── emporiqa.php                 # Main module class (hooks, install, config)
├── config.xml                   # Module metadata
├── logo.png                     # Module icon
├── classes/
│   ├── EmporiqaCartHandler.php       # In-chat cart operations
│   ├── EmporiqaChannelResolver.php   # Multi-shop → channel mapping
│   ├── EmporiqaLanguageHelper.php    # Language mapping utilities
│   ├── EmporiqaOrderFormatter.php    # Order payload formatting
│   ├── EmporiqaPageFormatter.php     # CMS page payload formatting
│   ├── EmporiqaProductFormatter.php  # Product/combination payload formatting
│   ├── EmporiqaSignatureHelper.php   # HMAC-SHA256 signing & verification
│   ├── EmporiqaSyncService.php       # Bulk sync orchestration
│   └── EmporiqaWebhookClient.php     # HTTP client for webhook delivery
├── controllers/
│   ├── admin/
│   │   ├── AdminEmporiqaController.php        # Admin menu tab redirect
│   │   └── AdminEmporiqaConnectController.php # One-click connect handshake
│   └── front/
│       ├── cartapi.php               # Cart API endpoint (/module/emporiqa/cartapi)
│       └── ordertracking.php         # Order tracking endpoint (/module/emporiqa/ordertracking)
├── views/
│   ├── css/admin.css                 # Admin configuration styles
│   ├── img/                          # Module images (rectangular logo)
│   ├── js/
│   │   ├── admin-sync.js            # Bulk sync UI with progress tracking
│   │   └── front-cart-handler.js    # Chat widget cart integration
│   └── templates/
│       ├── admin/configure.tpl       # Configuration page template
│       ├── admin/sync_tab.tpl        # Sync tab template
│       └── hook/header.tpl           # Widget embed (displayHeader hook)
├── translations/                     # Translation catalogues
└── upgrade/                          # Version upgrade scripts
```

## Registered PrestaShop hooks

| Hook | Purpose |
|------|---------|
| `displayHeader` | Embeds the chat widget on the storefront |
| `actionProductSave` | Syncs product on create/update |
| `actionProductDelete` | Sends delete event for product and its variations |
| `actionObjectCombination{Add,Update,Delete}After` | Syncs parent product when combinations change |
| `actionObjectCms{Add,Update,Delete}After` | Syncs CMS pages on create/update/delete |
| `actionValidateOrder` | Captures chat session ID and sends order.completed event |
| `actionOrderStatusPostUpdate` | Sends order.completed for late payment captures |
| `actionUpdateQuantity` | Emits a lightweight `product.availability` event when stock changes (no full product rebuild) |
| `actionProductOutOfStock` | Emits a `product.availability` event on stock-boundary transitions |
| `actionObjectSpecificPrice{Add,Update,Delete}After` | Re-syncs the affected product on scheduled promos, per-group reductions, and quantity-based volume discounts (tier pricing) |
| `actionObjectImage{Add,Update,Delete}After` | Re-syncs the affected product when product images change |
| `actionObjectCategory{Update,Delete}After` | Logs an actionable warning so the merchant can run a full sync (catalog-wide impact) |
| `actionObjectManufacturer{Update,Delete}After` | Logs an actionable warning so the merchant can run a full sync (catalog-wide impact) |
| `actionObjectCartRule{Add,Update,Delete}After` | Logs an actionable warning so the merchant can run a full sync (catalog-wide impact) |
| `actionObjectCurrencyUpdateAfter` | Logs an actionable warning so the merchant can run a full sync (catalog-wide price impact) |
| `actionObjectTaxUpdateAfter` / `actionObjectTaxRulesGroupUpdateAfter` | Logs an actionable warning so the merchant can run a full sync (catalog-wide price impact) |
| `actionObjectLanguageAddAfter` | Logs an actionable warning so the merchant can run a full sync (new locale needs back-fill) |

## Extensibility hooks

Developers can hook into the sync pipeline to customize payloads or cancel syncs:

| Hook | Purpose | Key Parameters |
|------|---------|----------------|
| `actionEmporiqaFormatProduct` | Modify product/variation payload before sending | `&$data`, `$product`, `$event_type` |
| `actionEmporiqaFormatPage` | Modify page payload before sending | `&$data`, `$page`, `$event_type` |
| `actionEmporiqaFormatOrder` | Modify order tracking payload | `&$data`, `$order` |
| `actionEmporiqaShouldSyncProduct` | Conditionally cancel a product sync | `$product`, `$event_type`, `&$should_sync` |
| `actionEmporiqaShouldSyncPage` | Conditionally cancel a page sync | `$page`, `$event_type`, `&$should_sync` |
| `actionEmporiqaWidgetParams` | Modify chat widget embed parameters | `&$params` |
| `actionEmporiqaOrderTracking` | Modify order tracking response | `&$data`, `$order` |

## Pricing

The module is paid on PrestaShop Addons and free on [GitHub](https://github.com/emporiqa/prestashop). The Emporiqa service itself is pay-as-you-go: $0/month base + $0.25/conversation, with $25 of signup credit and no card required at signup. Full pricing at [emporiqa.com/pricing/](https://emporiqa.com/pricing/).

## Support

Email support@emporiqa.com.

## License

[Academic Free License 3.0 (AFL-3.0)](https://opensource.org/licenses/AFL-3.0)
