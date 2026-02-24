<?php
/**
 * Emporiqa Cart Handler
 *
 * Cart operations logic for the AJAX front controller.
 * Handles add, get, update, remove, clear, and checkout-url actions.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaCartHandler
{
    /** @var Context */
    private $context;

    public function __construct()
    {
        $this->context = Context::getContext();
    }

    /**
     * Add a product to the cart.
     *
     * @param string $rawProductId e.g. 'product-123' or '123'
     * @param string $rawVariationId e.g. 'variation-456' or '456' or ''
     * @param int $quantity
     *
     * @return array Standardized response
     */
    public function add($rawProductId, $rawVariationId = '', $quantity = 1)
    {
        $productId = self::resolveId($rawProductId);
        $variationId = self::resolveId($rawVariationId);

        if (!$productId) {
            return $this->errorResponse('No product ID provided.');
        }

        if ($quantity < 1) {
            $quantity = 1;
        }

        $product = new Product($productId);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return $this->errorResponse('Product not found.');
        }

        // Ensure cart exists
        $this->ensureCart();

        // Check if product has combinations and variation is required
        $hasCombinations = (bool) Product::getProductAttributesIds($productId);
        if ($hasCombinations && !$variationId) {
            return $this->errorResponse('Please select a product variation.');
        }

        $result = $this->context->cart->updateQty(
            $quantity,
            $productId,
            $variationId ?: null,
            false,
            'up'
        );

        if ($result < 0) {
            return $this->errorResponse('Minimum quantity not reached.');
        }

        if ($result === false) {
            return $this->errorResponse('Failed to add item to cart.');
        }

        return $this->successResponse();
    }

    /**
     * Get current cart contents.
     *
     * @return array Standardized response
     */
    public function get()
    {
        return $this->successResponse();
    }

    /**
     * Update quantity of a cart item.
     *
     * @param string $rawProductId
     * @param string $rawVariationId
     * @param int $quantity
     *
     * @return array Standardized response
     */
    public function update($rawProductId, $rawVariationId = '', $quantity = 1)
    {
        $productId = self::resolveId($rawProductId);
        $variationId = self::resolveId($rawVariationId);

        if (!$productId) {
            return $this->errorResponse('No product ID provided.');
        }

        if ($quantity < 1) {
            return $this->errorResponse('Quantity must be greater than zero.');
        }

        $this->ensureCart();

        // Find current quantity in cart
        $cartProducts = $this->context->cart->getProducts();
        $currentQty = 0;
        $found = false;

        foreach ($cartProducts as $cartProduct) {
            if ((int) $cartProduct['id_product'] === $productId
                && (int) $cartProduct['id_product_attribute'] === $variationId) {
                $currentQty = (int) $cartProduct['cart_quantity'];
                $found = true;
                break;
            }
        }

        if (!$found) {
            return $this->errorResponse('Item not found in cart.');
        }

        $diff = $quantity - $currentQty;
        if ($diff === 0) {
            return $this->successResponse();
        }

        $operator = $diff > 0 ? 'up' : 'down';
        $result = $this->context->cart->updateQty(
            abs($diff),
            $productId,
            $variationId ?: null,
            false,
            $operator
        );

        if ($result === false) {
            return $this->errorResponse('Failed to update cart.');
        }

        return $this->successResponse();
    }

    /**
     * Remove an item from the cart.
     *
     * @param string $rawProductId
     * @param string $rawVariationId
     *
     * @return array Standardized response
     */
    public function remove($rawProductId, $rawVariationId = '')
    {
        $productId = self::resolveId($rawProductId);
        $variationId = self::resolveId($rawVariationId);

        if (!$productId) {
            return $this->errorResponse('No product ID provided.');
        }

        $this->ensureCart();

        $result = $this->context->cart->deleteProduct($productId, $variationId ?: 0);

        if (!$result) {
            return $this->errorResponse('Failed to remove item.');
        }

        return $this->successResponse();
    }

    /**
     * Clear the entire cart.
     *
     * @return array Standardized response
     */
    public function clear()
    {
        if ($this->context->cart && Validate::isLoadedObject($this->context->cart)) {
            $products = $this->context->cart->getProducts();
            foreach ($products as $product) {
                $this->context->cart->deleteProduct(
                    (int) $product['id_product'],
                    (int) $product['id_product_attribute']
                );
            }
        }

        return $this->successResponse();
    }

    /**
     * Get checkout URL.
     *
     * @return array Standardized response
     */
    public function getCheckoutUrl()
    {
        return [
            'success' => true,
            'error' => null,
            'checkoutUrl' => $this->context->link->getPageLink('order'),
            'cart' => null,
        ];
    }

    /**
     * Build standardized cart data.
     *
     * @return array Cart data
     */
    public function buildCartData()
    {
        if (!$this->context->cart || !Validate::isLoadedObject($this->context->cart)) {
            return [
                'items' => [],
                'item_count' => 0,
                'total' => 0.0,
                'currency' => $this->getDefaultCurrencyIso(),
            ];
        }

        $cartProducts = $this->context->cart->getProducts(true);
        $items = [];

        foreach ($cartProducts as $product) {
            $productId = (int) $product['id_product'];
            $paId = (int) $product['id_product_attribute'];

            $imageUrl = null;
            if (!empty($product['id_image'])) {
                $imageId = $product['id_image'];
                if (strpos($imageId, '-') !== false) {
                    $parts = explode('-', $imageId);
                    $imageId = end($parts);
                }
                $imageTypeName = ImageType::getFormattedName('small');
                $imageUrl = $this->context->link->getImageLink(
                    $product['link_rewrite'] ?? '',
                    $productId . '-' . $imageId,
                    $imageTypeName
                );
                if (strpos($imageUrl, 'http') !== 0) {
                    $imageUrl = 'https://' . $imageUrl;
                }
            }

            $items[] = [
                'product_id' => 'product-' . $productId,
                'variation_id' => $paId ? 'variation-' . $paId : null,
                'name' => $product['name'] ?? '',
                'quantity' => (int) $product['cart_quantity'],
                'unit_price' => (float) ($product['price_wt'] ?? 0),
                'image_url' => $imageUrl,
                'product_url' => $this->context->link->getProductLink($productId),
            ];
        }

        $currency = new Currency((int) $this->context->cart->id_currency);
        $currencyIso = Validate::isLoadedObject($currency) ? $currency->iso_code : $this->getDefaultCurrencyIso();

        return [
            'items' => $items,
            'item_count' => (int) array_sum(array_column($cartProducts, 'cart_quantity')),
            'total' => (float) $this->context->cart->getOrderTotal(true),
            'currency' => $currencyIso,
        ];
    }

    /**
     * Resolve an identification_number or raw integer ID.
     * Accepts: "product-123", "variation-456", or "123"
     */
    public static function resolveId($value)
    {
        if (empty($value)) {
            return 0;
        }

        if (preg_match('/^(?:product|variation)-(\d+)$/', $value, $matches)) {
            return (int) $matches[1];
        }

        return (int) $value;
    }

    /**
     * Ensure a cart exists for the current context.
     */
    private function ensureCart()
    {
        if (!$this->context->cart || !$this->context->cart->id) {
            $cart = new Cart();
            $cart->id_lang = $this->context->language ? (int) $this->context->language->id : (int) Configuration::get('PS_LANG_DEFAULT');
            $cart->id_currency = $this->context->currency ? (int) $this->context->currency->id : (int) Configuration::get('PS_CURRENCY_DEFAULT');
            $cart->id_shop = $this->context->shop ? (int) $this->context->shop->id : 1;
            $cart->id_guest = (int) $this->context->cookie->__get('id_guest');

            if ($this->context->customer && $this->context->customer->id) {
                $cart->id_customer = $this->context->customer->id;
                $cart->id_address_delivery = (int) Address::getFirstCustomerAddressId($this->context->customer->id);
                $cart->id_address_invoice = $cart->id_address_delivery;
            }

            $cart->add();
            $this->context->cart = $cart;
            $this->context->cookie->__set('id_cart', (int) $cart->id);
            $this->context->cookie->write();
        }
    }

    private function successResponse()
    {
        return [
            'success' => true,
            'error' => null,
            'checkoutUrl' => $this->context->link->getPageLink('order'),
            'cart' => $this->buildCartData(),
        ];
    }

    private function errorResponse($message)
    {
        return [
            'success' => false,
            'error' => $message,
            'checkoutUrl' => null,
            'cart' => null,
        ];
    }

    private function getDefaultCurrencyIso()
    {
        $currency = Currency::getDefaultCurrency();

        return $currency ? $currency->iso_code : '';
    }
}
