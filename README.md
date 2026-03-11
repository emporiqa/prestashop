# Emporiqa Chat Assistant for PrestaShop

[Emporiqa](https://emporiqa.com) chat assistant module for PrestaShop 8. Syncs your product catalog and CMS pages to the Emporiqa platform, embeds the chat widget on your storefront, and provides endpoints for in-chat cart operations and order tracking.

Customers can discover products, ask questions, add items to their cart, and check order status — all within a natural chat conversation in 65+ languages.

> **[Full Documentation](https://emporiqa.com/docs/prestashop/)** · **[Live Demo](https://test-prestashop.emporiqa.com/)** · **[Emporiqa Platform](https://emporiqa.com)**

## Features

- **Product sync** — Real-time webhook sync of catalog products and combinations (variations). Parent/child relationships, attributes, prices, stock levels, and images are all included.
- **Page sync** — CMS pages synced with per-language content so the assistant can answer support questions from your own content.
- **Chat widget** — Automatically embedded on your storefront in the correct language for the current visitor.
- **In-chat cart** — Customers can add, update, remove items, and proceed to checkout directly from the chat.
- **Order tracking** — HMAC-signed order lookup with optional email verification to protect customer data.
- **Conversion tracking** — Captures chat session IDs at checkout and reports order completion events for revenue attribution.
- **Multi-language** — Automatic language mapping. All translations are consolidated into single webhook payloads per entity.
- **Multi-shop compatible** — Global configuration with proper shop context handling across multi-shop setups.
- **Async delivery** — Deferred execution via `register_shutdown_function` ensures webhooks never block your storefront.
- **Extensibility hooks** — 7 action hooks for developers to customize sync payloads, cancel syncs, or modify widget behavior.

## Requirements

- PrestaShop 8.0 – 8.x
- PHP 7.4+
- An [Emporiqa account](https://emporiqa.com/platform/create-store/) (free sandbox available — 100 products, 20 pages, no credit card required)

## Installation

1. Download the latest release (`emporiqa.zip`) or clone this repository.
2. In your PrestaShop back office, go to **Modules > Module Manager > Upload a module** and upload `emporiqa.zip`.
3. Click **Configure** on the Emporiqa module.
4. Enter your **Store ID** and **Webhook Secret** from your [Emporiqa dashboard](https://emporiqa.com/platform/).
5. Go to the **Sync** tab and run the initial product and page sync.
6. The chat widget appears automatically on your storefront.

## Configuration

All settings are managed from the module configuration page (**Modules > Emporiqa > Configure**):

| Setting | Description | Default |
|---------|-------------|---------|
| Store ID | Your Emporiqa store identifier | — |
| Webhook Secret | HMAC-SHA256 signing secret | — |
| Webhook URL | Emporiqa webhook endpoint | `https://emporiqa.com/webhooks/sync/` |
| Sync Products | Enable real-time product sync | On |
| Sync Pages | Enable real-time CMS page sync | On |
| Enabled Languages | Languages included in sync payloads | All active shop languages |
| Order Tracking | Allow order status lookups from chat | On |
| Order Tracking Email | Require email verification for lookups | On |
| Cart Operations | Enable in-chat cart functionality | On |
| Batch Size | Products/pages per webhook request during bulk sync | 25 |

## Module Structure

```
emporiqa/
├── emporiqa.php                 # Main module class (hooks, install, config)
├── config.xml                   # Module metadata
├── logo.png                     # Module icon
├── classes/
│   ├── EmporiqaCartHandler.php       # In-chat cart operations
│   ├── EmporiqaLanguageHelper.php    # Language mapping utilities
│   ├── EmporiqaOrderFormatter.php    # Order payload formatting
│   ├── EmporiqaPageFormatter.php     # CMS page payload formatting
│   ├── EmporiqaProductFormatter.php  # Product/combination payload formatting
│   ├── EmporiqaSignatureHelper.php   # HMAC-SHA256 signing & verification
│   ├── EmporiqaSyncService.php       # Bulk sync orchestration
│   └── EmporiqaWebhookClient.php     # HTTP client for webhook delivery
├── controllers/
│   ├── admin/                        # Admin controller (menu tab redirect)
│   └── front/
│       ├── cartapi.php               # Cart API endpoint (/module/emporiqa/cartapi)
│       └── ordertracking.php         # Order tracking endpoint (/module/emporiqa/ordertracking)
├── views/
│   ├── css/admin.css                 # Admin configuration styles
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

## How It Works

### Webhook Sync

When a product or CMS page is created, updated, or deleted in PrestaShop, the module queues a webhook event. Events are flushed in batches at the end of the PHP request using `register_shutdown_function` (with `fastcgi_finish_request` when available), so storefront response times are not affected.

All webhooks are signed with HMAC-SHA256 via the `X-Webhook-Signature` header for payload integrity verification.

### Product Combinations

PrestaShop products with combinations are synced with their full variation structure. The parent product carries the shared name, description, and images, while each combination carries its specific attributes (size, color, etc.), price, and stock. The assistant understands "this jacket comes in blue and red, sizes S through XL."

### Multi-Language

Each active shop language is mapped to a standard language code. A single product with translations in 3 languages is sent as one webhook payload with all translations nested — fewer HTTP requests, consistent data.

### Registered PrestaShop Hooks

| Hook | Purpose |
|------|---------|
| `displayHeader` | Embeds the chat widget on the storefront |
| `actionProductSave` | Syncs product on create/update |
| `actionProductDelete` | Sends delete event for product and its variations |
| `actionObjectCombination{Add,Update,Delete}After` | Syncs parent product when combinations change |
| `actionObjectCms{Add,Update,Delete}After` | Syncs CMS pages on create/update/delete |
| `actionValidateOrder` | Captures chat session ID and sends order.completed event |
| `actionOrderStatusPostUpdate` | Sends order.completed for late payment captures |
| `actionUpdateQuantity` | Re-syncs product when stock changes |

## Extensibility Hooks

Developers can hook into the sync pipeline to customize payloads or cancel syncs:

| Hook | Purpose | Key Parameters |
|------|---------|----------------|
| `actionEmporiqaFormatProduct` | Modify product/variation payload before sending | `&$data`, `$product`, `$event_type` |
| `actionEmporiqaFormatPage` | Modify page payload before sending | `&$data`, `$page`, `$event_type` |
| `actionEmporiqaFormatOrder` | Modify order tracking payload | `&$data`, `$order` |
| `actionEmporiqaShouldSyncProduct` | Conditionally cancel a product sync | `$product`, `&$event_type`, `&$should_sync` |
| `actionEmporiqaShouldSyncPage` | Conditionally cancel a page sync | `$page`, `&$event_type`, `&$should_sync` |
| `actionEmporiqaWidgetParams` | Modify chat widget embed parameters | `&$params` |
| `actionEmporiqaOrderTracking` | Modify order tracking response | `&$data`, `$order` |

## Documentation

For configuration details, webhook format reference, hook examples, and troubleshooting:

**[https://emporiqa.com/docs/prestashop/](https://emporiqa.com/docs/prestashop/)**

## License

[Academic Free License 3.0 (AFL-3.0)](https://opensource.org/licenses/AFL-3.0)
