<?php
/**
 * Emporiqa Order Formatter
 *
 * Formats PrestaShop order data for webhook payloads
 * and order tracking API responses.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaOrderFormatter
{
    /**
     * Format an order for the order.completed webhook event.
     *
     * @param Order $order The order
     * @param string $sessionId Emporiqa session ID
     *
     * @return array Event data payload
     */
    public function formatOrderCompleted(Order $order, $sessionId = '')
    {
        $items = [];
        $products = $order->getProducts();

        foreach ($products as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            $paId = (int) ($product['product_attribute_id'] ?? 0);

            $items[] = [
                'product_id' => 'product-' . $productId,
                'variation_id' => $paId > 0 ? 'variation-' . $paId : null,
                'quantity' => (int) ($product['product_quantity'] ?? 0),
                'price' => round((float) ($product['unit_price_tax_incl'] ?? 0), 2),
            ];
        }

        $currency = new Currency((int) $order->id_currency);
        $currencyIso = Validate::isLoadedObject($currency) ? $currency->iso_code : '';

        return [
            'order_id' => (string) $order->id,
            'total' => (float) $order->total_paid_tax_incl,
            'currency' => $currencyIso,
            'emporiqa_session_id' => $sessionId,
            'items' => $items,
        ];
    }

    /**
     * Format an order for the order tracking API response.
     *
     * @param Order $order The order
     *
     * @return array Tracking response data
     */
    public function formatOrderTracking(Order $order)
    {
        $items = [];
        $products = $order->getProducts();

        foreach ($products as $product) {
            $items[] = [
                'name' => $product['product_name'] ?? '',
                'sku' => $product['product_reference'] ?? '',
                'quantity' => (int) ($product['product_quantity'] ?? 0),
                'total' => (float) ($product['total_price_tax_incl'] ?? 0),
            ];
        }

        $langId = (int) $order->id_lang;
        $currency = new Currency((int) $order->id_currency);
        $currencyIso = Validate::isLoadedObject($currency) ? $currency->iso_code : '';
        $billingAddress = new Address((int) $order->id_address_invoice);
        $shippingAddress = new Address((int) $order->id_address_delivery);
        $orderState = $order->getCurrentOrderState();

        $billingData = ['first_name' => '', 'last_name' => '', 'city' => '', 'country' => ''];
        if (Validate::isLoadedObject($billingAddress)) {
            $billingData = [
                'first_name' => $billingAddress->firstname,
                'last_name' => $billingAddress->lastname,
                'city' => $billingAddress->city,
                'country' => Country::getNameById($langId, (int) $billingAddress->id_country),
            ];
        }

        $shippingData = ['first_name' => '', 'last_name' => '', 'city' => '', 'country' => ''];
        if (Validate::isLoadedObject($shippingAddress)) {
            $shippingData = [
                'first_name' => $shippingAddress->firstname,
                'last_name' => $shippingAddress->lastname,
                'city' => $shippingAddress->city,
                'country' => Country::getNameById($langId, (int) $shippingAddress->id_country),
            ];
        }

        // Shipping tracking. PrestaShop has no single "tracking URL" field: the
        // number lives on OrderCarrier, and the URL is composed from the
        // carrier's URL template ('@' is replaced by the number). Both stay
        // empty until the order ships and the carrier URL is configured; the
        // actionEmporiqaOrderTracking hook lets a merchant add/override their
        // own (e.g. a third-party tracking link).
        $trackingNumber = '';
        $trackingUrl = '';
        $carrierName = '';
        $idOrderCarrier = (int) $order->getIdOrderCarrier();
        if ($idOrderCarrier) {
            $orderCarrier = new OrderCarrier($idOrderCarrier);
            if (Validate::isLoadedObject($orderCarrier)) {
                $trackingNumber = (string) $orderCarrier->tracking_number;
            }
        }
        // Loaded WITH the order's language: `delay` is a multilang field, so
        // without $langId it comes back as an array (or empty) and the
        // shopper is told nothing.
        //
        // The fallback is NOT belt-and-braces. `Carrier` is `multilang_shop`,
        // so EntityMapper::load adds `WHERE b.id_shop = <context shop>` to the
        // carrier_lang join and degrades it to an INNER JOIN. `orders` is not
        // a shop-associated table and Order::getByReference() returns
        // cross-shop rows, so a shop-1 order looked up in shop-2 context finds
        // no lang row and the WHOLE object fails to load -- taking the carrier
        // NAME and the tracking URL with it, which used to work. Falling back
        // to the shop-agnostic constructor keeps those two and costs only the
        // delay text, which is the right thing to lose.
        $carrierDelay = '';
        $carrier = new Carrier((int) $order->id_carrier, $langId);
        $carrierHasLanguage = Validate::isLoadedObject($carrier);
        if (!$carrierHasLanguage) {
            $carrier = new Carrier((int) $order->id_carrier);
        }
        if (Validate::isLoadedObject($carrier)) {
            // PrestaShop stores the carrier name as the literal '0' to mean
            // "use the shop name" (see core OrderLazyArray / delivery slip).
            $carrierName = $carrier->name == '0'
                ? (string) Configuration::get('PS_SHOP_NAME')
                : (string) $carrier->name;
            if ($trackingNumber !== '' && !empty($carrier->url)) {
                $trackingUrl = str_replace('@', $trackingNumber, $carrier->url);
            }
            // The carrier's own delivery-time text, e.g. "Delivery next day!".
            // This is PrestaShop's NATIVE estimated-delivery surface: it is
            // what the shopper was shown at checkout when they picked this
            // carrier, and it exists on every order in every shop. Free text,
            // not a date, and merchant-written, so it is passed through as-is
            // rather than parsed.
            //
            // Read ONLY from the language-scoped object. `delay` is the sole
            // `lang => true` field in Carrier::$definition, and EntityMapper
            // hydrates a multilang field as a SCALAR when an id_lang is given
            // and as an ARRAY keyed by language id when it is not. That
            // behaviour is identical on 8.1 and 9.0.2, which is why the rule
            // is stated against the mapper rather than against the docblock:
            // CarrierCore::$delay is documented `@var string` on 8.1 but
            // `@var string[]|string` on 9.0.2, so a claim about the docblock
            // would be wrong on half the supported range.
            //
            // Hence the narrowing annotation instead of a bare cast: casting a
            // `string[]|string` to string is a level-0 InvalidCastRule error
            // for the Addons static analysis on 9.0.2.
            if ($carrierHasLanguage) {
                /** @var string $delay */
                $delay = $carrier->delay;
                $carrierDelay = $delay;
            }
        }

        // `$order->delivery_date` is NOT sent, and that is deliberate.
        //
        // Its name promises a delivery date. It is not one. Core stamps it
        // with the CURRENT time on every transition into a state whose
        // `delivery` flag is set (OrderHistory::changeIdOrderState ->
        // Order::setDelivery), and on a stock shop that includes "Processing
        // in progress" -- so for most in-flight orders it holds the moment
        // the merchant started picking, days before anything is delivered.
        // OrderHistory even re-stamps it "even if it was already set by
        // another state change", and Order::setDelivery calls it "keep it for
        // backward compatibility, to remove on 1.6 version".
        //
        // The consumer is an AI salesperson that reads fields to shoppers by
        // name, so a key called `delivery_date` carrying a picking timestamp
        // would be recited as a delivery promise. A merchant with a REAL
        // estimated delivery date should add it through the
        // actionEmporiqaOrderTracking hook fired by
        // controllers/front/ordertracking.php, where it means what it says.

        return [
            'order_number' => $order->reference,
            'status' => $orderState ? (is_array($orderState->name) ? ($orderState->name[$langId] ?? reset($orderState->name) ?: '') : (string) $orderState->name) : '',
            'date_created' => date('c', strtotime($order->date_add)),
            'total' => (float) $order->total_paid_tax_incl,
            'currency' => $currencyIso,
            'payment_method' => $order->payment,
            'billing_address' => $billingData,
            'shipping_address' => $shippingData,
            'carrier' => $carrierName,
            'carrier_delay' => $carrierDelay,
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl,
            'items' => $items,
        ];
    }
}
