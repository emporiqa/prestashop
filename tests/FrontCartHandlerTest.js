/**
 * Locks in views/js/front-cart-handler.js on a page that holds a cart token
 * that is not the visitor's (a page served from a full-page cache).
 *
 * What must never regress: an `invalid_token` refusal that carries a token
 * is retried once with that token, and the token is kept for the next
 * request; a refusal without one, or a second refusal, is answered as a
 * failure, never retried in a loop. Requests run one at a time, so the token
 * a first request receives is the one the next request sends.
 *
 * Self-contained (no jsdom, no PrestaShop): the window is a fake.
 * Run: node tests/FrontCartHandlerTest.js
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '..', 'views', 'js', 'front-cart-handler.js'), 'utf8');

let failures = 0;
function check(label, ok) {
    console.log((ok ? '  ok   ' : '  FAIL ') + label);
    if (!ok) {
        failures += 1;
    }
}

class FakeFormData {
    constructor() {
        this.fields = {};
    }

    append(key, value) {
        this.fields[key] = String(value);
    }
}

const OK = { success: true, cart: { items: [], item_count: 0, total: 0, currency: 'EUR' } };
const REFUSED = { success: false, error: 'Security check failed.', code: 'invalid_token', token: 'fresh-token' };

// answer(fields) returns the JSON body for one request.
function load(answer) {
    const sent = [];
    let inFlight = 0;
    let maxInFlight = 0;
    const win = {
        emporiqa_cart_config: { ajax_url: 'https://shop.test/module/emporiqa/cartapi', token: 'cached-token', checkout_url: '/order' },
        fetch(url, options) {
            const fields = options.body.fields;
            sent.push(fields);
            inFlight += 1;
            maxInFlight = Math.max(maxInFlight, inFlight);
            return new Promise((resolve) => setTimeout(resolve, 5)).then(() => {
                inFlight -= 1;
                const body = answer(fields, sent.length);
                return { ok: true, status: 200, json: () => Promise.resolve(body) };
            });
        },
    };
    win.window = win;
    const context = vm.createContext(Object.assign(win, { FormData: FakeFormData, Promise, setTimeout, Object }));
    vm.runInContext(SOURCE, context);
    return { win, sent, maxInFlight: () => maxInFlight };
}

(async () => {
    console.log('A cached page: the first request is refused with a fresh token');
    let page = load((fields) => (fields.token === 'fresh-token' ? OK : REFUSED));
    let res = await page.win.EmporiqaCartHandler({ action: 'add', items: [{ product_id: 'product-7', quantity: 1 }] });
    check('the add succeeds', res.success === true);
    check('it was sent twice, the second time with the fresh token', page.sent.length === 2 && page.sent[0].token === 'cached-token' && page.sent[1].token === 'fresh-token');
    check('the retry is the same request', page.sent[1].action === 'add' && page.sent[1].product_id === 'product-7');
    res = await page.win.EmporiqaCartHandler({ action: 'view', items: null });
    check('the next request sends the fresh token at once', res.success === true && page.sent.length === 3 && page.sent[2].token === 'fresh-token');

    console.log('Two requests at once on a cached page');
    page = load((fields) => (fields.token === 'fresh-token' ? OK : REFUSED));
    const both = await Promise.all([
        page.win.EmporiqaCartHandler({ action: 'view', items: null }),
        page.win.EmporiqaCartHandler({ action: 'view', items: null }),
    ]);
    check('both succeed', both[0].success && both[1].success);
    check('one at a time', page.maxInFlight() === 1);
    check('only the first is refused', page.sent.length === 3 && page.sent[2].token === 'fresh-token');

    console.log('A refusal without a token');
    page = load(() => ({ success: false, error: 'Security check failed.', code: 'invalid_token' }));
    res = await page.win.EmporiqaCartHandler({ action: 'view', items: null });
    check('fails without a retry', res.success === false && page.sent.length === 1);

    console.log('Refused again on the retry');
    page = load(() => REFUSED);
    res = await page.win.EmporiqaCartHandler({ action: 'add', items: [{ product_id: 'product-7', quantity: 1 }] });
    check('fails after exactly one retry', res.success === false && page.sent.length === 2);
    check('says why', /Security check failed/.test(res.error));

    console.log('Any other failure');
    page = load(() => ({ success: false, error: 'Out of stock' }));
    res = await page.win.EmporiqaCartHandler({ action: 'add', items: [{ product_id: 'product-7', quantity: 1 }] });
    check('is not retried', res.success === false && res.error === 'Out of stock' && page.sent.length === 1);

    console.log('A failed request does not block the next one');
    page = load((fields, n) => {
        if (n === 1) {
            throw new Error('boom');
        }
        return OK;
    });
    res = await page.win.EmporiqaCartHandler({ action: 'view', items: null });
    const next = await page.win.EmporiqaCartHandler({ action: 'view', items: null });
    check('the first fails, the next succeeds', res.success === false && next.success === true);

    if (failures) {
        console.log('\n' + failures + ' check(s) FAILED');
        process.exit(1);
    }
    console.log('\nAll checks passed');
})();
