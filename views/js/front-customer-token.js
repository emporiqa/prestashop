/**
 * Emporiqa Customer Token
 *
 * Hands the signed customer token to the Emporiqa widget without ever
 * putting it in the page HTML (page caches would serve it to someone else),
 * and without broadcasting it to the other scripts on the page.
 *
 * When the chat opens, embed.js creates a MessageChannel and asks with
 *   window.postMessage({type: 'EMPORIQA_TOKEN_REQUEST', reply: 'port'}, location.origin, [port])
 * and this script answers ONLY on that port: first
 *   {type: 'EMPORIQA_TOKEN_PENDING'}
 * so the widget holds the shopper's first message for the token, then
 *   {type: 'EMPORIQA_CUSTOMER_TOKEN', token: '<token>' | ''}
 * ('' for a guest). A window.postMessage reaches every "message" listener on
 * the page; a port reaches only embed.js. A request without a port is not
 * answered.
 *
 * A guest (prestashop.customer.is_logged false) is answered '' at once, with
 * no request. A signed-in shopper costs one uncached POST to the token
 * controller; the answer is reused for at most five minutes, so a tab left
 * open never hands the widget a token that has expired or belongs to a
 * session that has since signed out. A failed answer is never reused.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 */

(function () {
    'use strict';

    var config = window.emporiqa_token_config || {};
    if (!config.url || !window.fetch) {
        return;
    }

    var origin = window.location.origin;
    var MAX_AGE_MS = 5 * 60 * 1000;
    var answer = null;
    var answeredAt = 0;

    function isGuest() {
        var ps = window.prestashop;
        return !!(ps && ps.customer && !ps.customer.is_logged);
    }

    function fetchToken() {
        if (isGuest()) {
            return Promise.resolve('');
        }
        if (answer && Date.now() - answeredAt < MAX_AGE_MS) {
            return answer;
        }
        answeredAt = Date.now();
        answer = fetch(config.url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('token request failed: ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                return data && typeof data.token === 'string' ? data.token : '';
            })
            .catch(function () {
                // Not reused, so the next request tries again.
                answer = null;
                return '';
            });
        return answer;
    }

    window.addEventListener('message', function (event) {
        if (event.source !== window || event.origin !== origin) {
            return;
        }
        var data = event.data;
        if (!data || data.type !== 'EMPORIQA_TOKEN_REQUEST') {
            return;
        }
        // A request without a port gets no answer: on the window the token
        // would reach every other script listening for messages.
        var port = event.ports && event.ports[0];
        if (!port) {
            return;
        }
        port.postMessage({ type: 'EMPORIQA_TOKEN_PENDING' });
        fetchToken().then(function (token) {
            port.postMessage({ type: 'EMPORIQA_CUSTOMER_TOKEN', token: token });
        });
    });
})();
