/**
 * Emporiqa Cart Handler
 *
 * Provides window.EmporiqaCartHandler for the Emporiqa chat widget
 * to perform cart operations in the PrestaShop store.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 */

(function () {
    'use strict';

    var config = window.emporiqa_cart_config || {};

    if (!config.ajax_url) {
        window.EmporiqaCartHandler = function () {
            return Promise.resolve(buildResponse(false, { error: 'Cart configuration not loaded. Please refresh the page.' }));
        };
        return;
    }

    function buildResponse(success, extras) {
        return Object.assign({
            success: success,
            error: null,
            checkoutUrl: null,
            cart: null
        }, extras || {});
    }

    function normalizeResponse(data) {
        if (!data || typeof data !== 'object') {
            return buildResponse(false, { error: 'Invalid response' });
        }

        var cart = null;
        if (data.cart) {
            cart = {
                items: (data.cart.items || []).map(function (item) {
                    return {
                        product_id: item.product_id ? String(item.product_id) : null,
                        variation_id: item.variation_id ? String(item.variation_id) : null,
                        name: item.name || '',
                        quantity: item.quantity || 0,
                        unit_price: item.unit_price || 0,
                        image_url: item.image_url || null,
                        product_url: item.product_url || null
                    };
                }),
                item_count: data.cart.item_count || 0,
                total: data.cart.total || 0,
                currency: data.cart.currency || ''
            };
        }

        return buildResponse(data.success === true, {
            error: data.success ? null : (data.error || null),
            checkoutUrl: data.checkoutUrl || null,
            cart: cart
        });
    }

    function emporiqaAjax(action, data) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('token', config.token);

        if (data) {
            Object.keys(data).forEach(function (key) {
                formData.append(key, data[key]);
            });
        }

        return fetch(config.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (response) {
            if (!response.ok) {
                return buildResponse(false, { error: 'Request failed (' + response.status + ')' });
            }
            return response.json().then(normalizeResponse).catch(function () {
                return buildResponse(false, { error: 'Invalid response from server' });
            });
        });
    }

    function refreshCart() {
        setTimeout(function () {
            try {
                if (typeof prestashop !== 'undefined' && prestashop.emit) {
                    prestashop.emit('updateCart', { reason: 'emporiqa' });
                }
            } catch (e) {}
        }, 0);
    }

    function handleAdd(items) {
        if (!items || !items.length) {
            return Promise.resolve(buildResponse(false, { error: 'No items provided' }));
        }

        var item = items[0];
        var data = {
            product_id: item.product_id || '',
            variation_id: item.variation_id || '',
            quantity: item.quantity || 1
        };

        return emporiqaAjax('add', data).then(function (result) {
            if (result.success) {
                refreshCart();
            }
            return result;
        });
    }

    function handleUpdate(items) {
        if (!items || !items.length) {
            return Promise.resolve(buildResponse(false, { error: 'No items provided' }));
        }

        var item = items[0];
        var data = {
            product_id: item.product_id || '',
            variation_id: item.variation_id || '',
            quantity: item.quantity || 1
        };

        return emporiqaAjax('update', data).then(function (result) {
            if (result.success) {
                refreshCart();
            }
            return result;
        });
    }

    function handleRemove(items) {
        if (!items || !items.length) {
            return Promise.resolve(buildResponse(false, { error: 'No items provided' }));
        }

        var item = items[0];
        var data = {
            product_id: item.product_id || '',
            variation_id: item.variation_id || ''
        };

        return emporiqaAjax('remove', data).then(function (result) {
            if (result.success) {
                refreshCart();
            }
            return result;
        });
    }

    function handleClear() {
        return emporiqaAjax('clear').then(function (result) {
            if (result.success) {
                refreshCart();
            }
            return result;
        });
    }

    function handleView() {
        return emporiqaAjax('get');
    }

    function handleCheckout() {
        return buildResponse(true, { checkoutUrl: config.checkout_url });
    }

    window.EmporiqaCartHandler = function (params) {
        var promise;

        try {
            var action = params.action;
            var items = params.items;

            switch (action) {
                case 'add':
                    promise = handleAdd(items);
                    break;
                case 'update':
                    promise = handleUpdate(items);
                    break;
                case 'remove':
                    promise = handleRemove(items);
                    break;
                case 'clear':
                    promise = handleClear();
                    break;
                case 'view':
                    promise = handleView();
                    break;
                case 'checkout':
                    promise = Promise.resolve(handleCheckout());
                    break;
                default:
                    promise = Promise.resolve(buildResponse(false, { error: 'Unknown action: ' + action }));
                    break;
            }
        } catch (err) {
            return Promise.resolve(buildResponse(false, { error: err.message || 'An unexpected error occurred' }));
        }

        return promise.catch(function (err) {
            return buildResponse(false, { error: err.message || 'An unexpected error occurred' });
        });
    };
})();
