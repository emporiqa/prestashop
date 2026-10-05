# Emporiqa: AI Chatbot for PrestaShop

The [Emporiqa](https://emporiqa.com) AI chatbot for PrestaShop 8.1+ and 9 is an online salesperson that closes sales in your store: shoppers describe what they need or upload a photo of something they like, it finds matching products from your catalog, handles objections like "too expensive" with alternatives instead of a discount, answers questions from your CMS pages, and walks shoppers to cart and checkout in 65+ languages. This module syncs your product catalog and CMS pages to Emporiqa, embeds the chat widget on your storefront, and answers the chat's cart and order status requests.

[![Emporiqa chat widget open on a storefront, answering which noise cancelling headphones under 400 euros suit long flights: it names the Sennheiser Momentum 4 for up to 60 hours with ANC and the Sony WH-1000XM5 at 250 g, and shows both as product cards with photo, price and a Cart button, above a message box with a photo button and a voice button](docs/images/lead-answer.webp)](https://demo.emporiqa.com)

- **Integration overview**: [emporiqa.com/integrations/prestashop/](https://emporiqa.com/integrations/prestashop/)
- **Full documentation**: [emporiqa.com/docs/prestashop/](https://emporiqa.com/docs/prestashop/) (webhook format reference, hook examples, troubleshooting)
- **Features**: [emporiqa.com/features/](https://emporiqa.com/features/) · **FAQ**: [emporiqa.com/faq/](https://emporiqa.com/faq/) · **Pricing**: [emporiqa.com/pricing/](https://emporiqa.com/pricing/)
- **Live demo**: [demo.emporiqa.com](https://demo.emporiqa.com) and a [30-second video](https://www.youtube.com/watch?v=y7ARSIUxuXI). That demo sells electronics, and the behavior is the same on any catalog.

## Requirements

- PrestaShop 8.1+ or 9.x (tested on 8.1 to 9.2)
- PHP 8.0+ on PrestaShop 8.1, PHP 8.1+ on PrestaShop 9. PrestaShop 8.1 also runs on PHP 7.x; this module does not
- An [Emporiqa account](https://emporiqa.com/platform/create-store/). Sign up with no card; $25 of signup credit (~100 free conversations) auto-applied

## Installation

1. Get the module from the [PrestaShop Addons Marketplace](https://addons.prestashop.com/en/front-office-features-prestashop-modules/97345-emporiqa-chat-assistant.html) (paid) or from [GitHub](https://github.com/emporiqa/prestashop) (free).
2. In your PrestaShop back office, go to **Modules > Module Manager > Upload a Module** and upload `emporiqa.zip`.
3. Click **Configure** on the Emporiqa module.
4. Click **Connect to Emporiqa**. A new tab opens on emporiqa.com. Create a free account (no card required, $25 of signup credit) or sign in if you already have one, then pick the store you want to connect (or create a new one). The module is connected when you return.
5. On the **Sync** tab, click **Send my catalog** and keep the tab open until the progress bar is full. Products, pages, and combinations flow through; the widget appears on your storefront when the first product arrives.

**On HTTP, or prefer to paste credentials yourself?** Expand **Edit credentials manually** on the Configure page. Paste a **Store ID** and **Connection Secret** from your Emporiqa dashboard under **Settings → Integration**. Both flows reach the same place.

For order status in the chat, copy the **Order tracking** address from the Configure page into your Emporiqa dashboard under **Settings → Integration → Order tracking**. It is on by default. Once Emporiqa has told the module that your store has ready-made rules, the Configure page shows a **Ready-made rules** section instead, with each rule marked **On** or **Not added** and an **Open in Emporiqa** link; there the **Order status** rule replaces the order tracking address, which moves under **Advanced**. The module learns whether your store has ready-made rules when you connect and each time you click **Test Connection**.

## Configuration

All settings are managed from the module configuration page (**Modules > Emporiqa > Configure**):

**Connection Settings**

The recommended path is **Connect to Emporiqa** (one-click handshake, no credentials to paste). For HTTP sites or manual setup, expand **Edit credentials manually**:

| Setting | Description | Default |
|---------|-------------|---------|
| Store ID | Your Emporiqa store identifier (filled automatically by one-click connect) | (none) |
| Connection Secret | HMAC-SHA256 signing secret (filled automatically by one-click connect) | (none) |

**Shops and languages**

| Setting | Description | Default |
|---------|-------------|---------|
| Shops (multistore only) | Shops whose catalog is synced and where the chat is shown. All shops share one Emporiqa store; each shop is a channel | All active shops |
| Languages | Languages included in sync payloads; their pages show the chat. Pages in an unticked language show no chat | All active shop languages |

**Order tracking**

| Setting | Description | Default |
|---------|-------------|---------|
| Order tracking | Lets the chat answer "Where is my order?" after the shopper gives the order reference and the order's email. Shown in its own section, or under **Advanced** as *Old order tracking* once ready-made rules are offered to your store, where the Order status rule replaces it | On |

**Advanced**

| Setting | Description | Default |
|---------|-------------|---------|
| Auto-sync products | Send each product change as it is saved | On |
| Auto-sync pages | Send each CMS page change as it is saved | On |
| Webhook URL | Emporiqa webhook endpoint | `https://emporiqa.com/webhooks/sync/` |
| Batch Size | Products/pages per webhook request during bulk sync | 25 |

In-chat cart operations are always enabled. No configuration needed.

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
│   ├── EmporiqaActionEndpoint.php    # Ready-made rules endpoint (order_status, verify)
│   ├── EmporiqaCartApiEndpoint.php   # Cart API body (POST only, never cached)
│   ├── EmporiqaCartHandler.php       # In-chat cart operations
│   ├── EmporiqaChannelResolver.php   # Multi-shop → channel mapping
│   ├── EmporiqaConnectHandshake.php  # One-click connect handshake body
│   ├── EmporiqaConnectNonce.php      # One-click connect PKCE verifier store
│   ├── EmporiqaJsonResponse.php      # The one JSON response helper (PHP 7 parseable)
│   ├── EmporiqaLanguageHelper.php    # Language mapping utilities
│   ├── EmporiqaOrderFormatter.php    # Order payload formatting
│   ├── EmporiqaOrderStatus.php       # Order status lookup, dedupe and rate limit
│   ├── EmporiqaOrderTrackingEndpoint.php # Order tracking body (rate limited like order_status)
│   ├── EmporiqaPageFormatter.php     # CMS page payload formatting
│   ├── EmporiqaProductFormatter.php  # Product/combination payload formatting
│   ├── EmporiqaSchema.php            # Module tables, created and repaired on install/upgrade
│   ├── EmporiqaSignatureHelper.php   # HMAC-SHA256 signing & verification
│   ├── EmporiqaSyncService.php       # Bulk sync orchestration
│   ├── EmporiqaTokenEndpoint.php     # Signed customer token for the widget
│   └── EmporiqaWebhookClient.php     # HTTP client for webhook delivery
├── controllers/
│   ├── admin/
│   │   ├── AdminEmporiqaController.php        # Admin menu tab redirect
│   │   └── AdminEmporiqaConnectController.php # One-click connect handshake
│   └── front/
│       ├── action.php                # Ready-made rules endpoint (/module/emporiqa/action)
│       ├── cartapi.php               # Cart API endpoint (/module/emporiqa/cartapi)
│       ├── ordertracking.php         # Order tracking endpoint (/module/emporiqa/ordertracking)
│       └── token.php                 # Customer token endpoint (/module/emporiqa/token)
├── views/
│   ├── css/admin.css                 # Admin configuration styles
│   ├── img/                          # Module images (rectangular logo)
│   ├── js/
│   │   ├── admin-sync.js            # Bulk sync UI with progress tracking
│   │   ├── front-cart-handler.js    # Chat widget cart integration
│   │   └── front-customer-token.js  # Hands the customer token to the widget
│   └── templates/
│       ├── admin/configure.tpl       # Configuration page template
│       ├── admin/sync_tab.tpl        # Sync tab template
│       ├── admin/sync_health_line.tpl # Last full sync line on the Sync tab
│       └── hook/header.tpl           # Widget embed (displayHeader hook)
├── translations/fr.php               # French back-office translation
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
| `actionEmporiqaFormatOrder` | Modify the `order.completed` event payload | `&$data`, `$order` |
| `actionEmporiqaShouldSyncProduct` | Conditionally cancel a product sync | `$product`, `$event_type`, `&$should_sync` |
| `actionEmporiqaShouldSyncPage` | Conditionally cancel a page sync | `$page`, `$event_type`, `&$should_sync` |
| `actionEmporiqaWidgetParams` | Modify chat widget embed parameters | `&$params` |
| `actionEmporiqaOrderStatus` | Modify the Order status ready-made rule's answer | `&$data`, `$order` |
| `actionEmporiqaOrderTracking` | Modify the order tracking response | `&$data`, `$order` |

## Pricing

The module is paid on PrestaShop Addons and free on [GitHub](https://github.com/emporiqa/prestashop). The Emporiqa service itself is pay-as-you-go: $0/month base + $0.25/conversation, with $25 of signup credit (about 100 conversations) and no card required at signup. After the credit, the monthly cap defaults to $59 and you can change it from the billing dashboard. Voice mode (the shopper speaks and hears the answer read aloud) is optional and off by default: a conversation where the shopper speaks costs $0.15 more, charged once, and counts toward that cap. Prices exclude VAT. Enterprise option for catalogs over 100,000 products. Full pricing at [emporiqa.com/pricing/](https://emporiqa.com/pricing/).

## Known issues

- The messages written by the **Sync** and **Test Connection** buttons, and the one-click connect error messages, are in English only. The rest of the configuration page is available in English and French.
- The controllers under `controllers/` and `emporiqa.php` keep PHP 7 syntax (no trailing commas in calls or parameters), so a shop left on PHP 7 gets a clean error instead of a fatal one; the PHP 8 code lives in `classes/`.
- The full list, with technical detail, is in [CHANGELOG.md](CHANGELOG.md).

## Support

Email support@emporiqa.com.

## License

[Academic Free License 3.0 (AFL-3.0)](https://opensource.org/licenses/AFL-3.0)
