/**
 * Locks in views/js/front-customer-token.js, the shop half of the customer
 * token handshake with Emporiqa's embed.js.
 *
 * What must never regress: a request that carries a MessageChannel port is
 * answered ONLY on that port, so no other script on the page sees the token;
 * a request without a port is not answered at all, never on the window, and
 * costs no token request. Only requests from this window and origin are answered. A guest
 * never causes a request; a non-2xx answer is never reused.
 *
 * Self-contained (no jsdom, no PrestaShop): the window is a fake.
 * Run: node tests/FrontCustomerTokenTest.js
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { MessageChannel } = require('worker_threads');

const SOURCE = fs.readFileSync(path.join(__dirname, '..', 'views', 'js', 'front-customer-token.js'), 'utf8');
const ORIGIN = 'https://shop.test';
const TOKEN = 'eyJ1aWQiOiI3NyJ9.' + 'a'.repeat(64);

let failures = 0;
function check(label, ok) {
    console.log((ok ? '  ok   ' : '  FAIL ') + label);
    if (!ok) {
        failures += 1;
    }
}

function wait(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function makeWindow({ loggedIn = true, status = 200 } = {}) {
    const listeners = [];
    const windowPosts = [];
    const fetches = [];
    const win = {
        location: { origin: ORIGIN },
        emporiqa_token_config: { url: ORIGIN + '/module/emporiqa/token' },
        prestashop: { customer: { is_logged: loggedIn } },
        addEventListener(type, fn) {
            if (type === 'message') {
                listeners.push(fn);
            }
        },
        postMessage(data, target) {
            windowPosts.push({ data, target });
        },
        fetch(url, options) {
            fetches.push({ url, options });
            return Promise.resolve({
                ok: status >= 200 && status < 300,
                status,
                json: () => Promise.resolve({ token: status === 200 ? TOKEN : 'stale' }),
            });
        },
    };
    win.window = win;
    const context = vm.createContext(win);
    vm.runInContext(SOURCE, context);
    // The script's `window`, as the realm sees it (not the outer object).
    const self = vm.runInContext('window', context);
    const dispatch = (data, { origin = ORIGIN, source = self, ports = [] } = {}) =>
        listeners.forEach((fn) => fn({ data, origin, source, ports }));
    return { win, windowPosts, fetches, dispatch };
}

function onPort() {
    const channel = new MessageChannel();
    const got = [];
    channel.port1.on('message', (data) => got.push(data));
    return { channel, got };
}

(async () => {
    console.log('Scenario 1: signed in, request with a port');
    {
        const { windowPosts, fetches, dispatch } = makeWindow();
        const { channel, got } = onPort();
        dispatch({ type: 'EMPORIQA_TOKEN_REQUEST', reply: 'port' }, { ports: [channel.port2] });
        await wait(50);
        check('pending, then the token, on the port', JSON.stringify(got.map((m) => m.type))
            === JSON.stringify(['EMPORIQA_TOKEN_PENDING', 'EMPORIQA_CUSTOMER_TOKEN']) && got[1].token === TOKEN);
        check('nothing on the window', windowPosts.length === 0);
        check('one uncached POST', fetches.length === 1 && fetches[0].options.method === 'POST'
            && fetches[0].options.cache === 'no-store');
        channel.port1.close();
    }

    console.log('Scenario 2: a request without a port');
    {
        const { windowPosts, fetches, dispatch } = makeWindow();
        dispatch({ type: 'EMPORIQA_TOKEN_REQUEST' });
        await wait(50);
        check('nothing on the window', windowPosts.length === 0);
        check('no token request', fetches.length === 0);
    }

    console.log('Scenario 3: a guest');
    {
        const { fetches, dispatch } = makeWindow({ loggedIn: false });
        const { channel, got } = onPort();
        dispatch({ type: 'EMPORIQA_TOKEN_REQUEST', reply: 'port' }, { ports: [channel.port2] });
        await wait(50);
        check('no token request', fetches.length === 0);
        check('answered with an empty token', got.length === 2 && got[1].token === '');
        channel.port1.close();
    }

    console.log('Scenario 4: requests from elsewhere');
    {
        const { windowPosts, fetches, dispatch } = makeWindow();
        dispatch({ type: 'EMPORIQA_TOKEN_REQUEST' }, { origin: 'https://evil.test' });
        dispatch({ type: 'EMPORIQA_TOKEN_REQUEST' }, { source: {} });
        await wait(50);
        check('ignored', windowPosts.length === 0 && fetches.length === 0);
    }

    console.log('Scenario 5: a non-2xx answer');
    {
        const { fetches, dispatch } = makeWindow({ status: 503 });
        const { channel, got } = onPort();
        dispatch({ type: 'EMPORIQA_TOKEN_REQUEST', reply: 'port' }, { ports: [channel.port2] });
        await wait(50);
        check('answered with an empty token', got[1] && got[1].token === '');
        dispatch({ type: 'EMPORIQA_TOKEN_REQUEST', reply: 'port' }, { ports: [channel.port2] });
        await wait(50);
        check('not reused: the next request asks again', fetches.length === 2);
        channel.port1.close();
    }

    console.log('Scenario 6: a good answer is reused');
    {
        const { fetches, dispatch } = makeWindow();
        const { channel } = onPort();
        dispatch({ type: 'EMPORIQA_TOKEN_REQUEST', reply: 'port' }, { ports: [channel.port2] });
        await wait(20);
        dispatch({ type: 'EMPORIQA_TOKEN_REQUEST', reply: 'port' }, { ports: [channel.port2] });
        await wait(20);
        check('one request for two opens', fetches.length === 1);
        channel.port1.close();
    }

    if (failures > 0) {
        console.log('\n' + failures + ' assertion(s) FAILED');
        process.exit(1);
    }
    console.log('\nAll assertions passed');
})();
