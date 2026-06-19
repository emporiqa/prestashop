<?php
/**
 * Upgrade script: → 1.2.3.
 *
 * 1.2.3 is code-only — no database, config, or hook changes. This script just
 * registers the new version. It bundles three changes:
 *
 * 1. Pricing fix: currency-scoped specific prices (a promo or price override
 *    set for one currency only, i.e. a ps_specific_price row with
 *    id_currency > 0) were silently dropped from the catalog payload.
 *    EmporiqaProductFormatter::buildPriceEntries() computed each product's
 *    price once in the default currency and then mathematically converted it
 *    for the other currencies via Tools::convertPriceFull(). That conversion
 *    never consults the per-currency specific price, so the chat quoted the
 *    undiscounted converted price for any currency-targeted promotion. The
 *    formatter now computes each currency's price in that currency's own
 *    context so getPriceStatic() resolves the correct currency-scoped
 *    specific price natively.
 * 2. Lightweight stock path: pure quantity/availability ticks
 *    (actionUpdateQuantity, actionProductOutOfStock) now emit a small
 *    `product.availability` event instead of rebuilding and re-shipping the
 *    full product payload.
 * 3. Extra payload flags: products and combinations now carry PrestaShop's
 *    native `condition`, `is_virtual`, `available_for_order`, and a
 *    contract-parity `max_order_quantities` field.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');

/**
 * @param Emporiqa $module
 *
 * @return bool
 */
function upgrade_module_1_2_3($module)
{
    // Pure code fix — nothing to migrate.
    return true;
}
