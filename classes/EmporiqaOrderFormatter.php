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

        return [
            'order_number' => $order->reference,
            'status' => $orderState && is_array($orderState->name) ? ($orderState->name[$langId] ?? '') : '',
            'date_created' => date('c', strtotime($order->date_add)),
            'total' => (float) $order->total_paid_tax_incl,
            'currency' => $currencyIso,
            'payment_method' => $order->payment,
            'billing_address' => $billingData,
            'shipping_address' => $shippingData,
            'items' => $items,
        ];
    }
}
