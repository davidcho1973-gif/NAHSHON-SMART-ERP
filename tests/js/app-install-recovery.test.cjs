const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

// Execute the real install controller; only server-rendered translations are substituted.
const blade = fs.readFileSync('resources/views/partials/install-app.blade.php', 'utf8').replace(/\{\{--[\s\S]*?--\}\}/g, '');
const source = blade.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/var DICT = .*;/, 'var DICT = {ko:{manual:"Browser menu",help:"Help"}};')
    .replace(/var lang = .*;/, 'var lang = "ko";');

function setup({ ios = false, standalone = false, storageBlocked = false } = {}) {
    const nodes = new Map(); const events = {}; const stored = new Map();
    const el = key => {
        if (!nodes.has(key)) nodes.set(key, { hidden: true, href: 'https://erp.example/app/install',
            addEventListener(name, fn) { this[name] = fn; }, querySelector: el,
            querySelectorAll: selector => [el(selector + ':1')] });
        return nodes.get(key);
    };
    const navigator = { userAgent: ios ? 'iPhone' : 'Android Chrome', standalone };
    const window = { navigator, matchMedia: () => ({ matches: standalone }), addEventListener: (name, fn) => { events[name] = fn; } };
    vm.runInNewContext(source, { window, navigator, document: { getElementById: el }, Promise,
        localStorage: { getItem: key => { if (storageBlocked) throw Error('disabled'); return stored.get(key); },
            setItem: (key, value) => { if (storageBlocked) throw Error('disabled'); stored.set(key, value); } } });
    return { app: window.AppInstall, el, events, stored };
}

test('explicit install tap opens manual guidance when Android does not offer an install event', () => {
    const { app, el } = setup();
    assert.equal(app.offer(), false);
    assert.equal(app.show(), true);
    assert.equal(el('app-install').hidden, false);
    assert.equal(el('.di-manual').hidden, false);
    assert.equal(el('.di-ios').hidden, true);
});

test('browser install event upgrades an open manual sheet; dismiss is not reported as installed', async () => {
    const { app, el, events, stored } = setup();
    app.show(); let prompted = 0;
    events.beforeinstallprompt({ preventDefault() {}, prompt: async () => { prompted++; return { outcome: 'dismissed' }; } });
    assert.equal(el('.di-auto').hidden, false);
    assert.equal(el('.di-manual').hidden, true);
    el('di-go').click(); await Promise.resolve();
    assert.equal(prompted, 1); assert.equal(stored.size, 0); assert.equal(app.installed(), false);
    assert.equal(app.show(), true); assert.equal(el('.di-manual').hidden, false);
});

test('failed native install prompt returns to usable help without an unhandled rejection', async () => {
    const { app, el, events } = setup();
    events.beforeinstallprompt({ preventDefault() {}, prompt: () => Promise.reject(Error('blocked')) });
    app.show(); el('di-go').click(); await Promise.resolve(); await Promise.resolve();
    assert.equal(el('app-install').hidden, false); assert.equal(el('.di-manual').hidden, false);
});

test('standalone launch suppresses installation; iPhone and blocked storage still work', () => {
    assert.equal(setup({ standalone: true }).app.show(), false);
    const ios = setup({ ios: true, storageBlocked: true });
    assert.equal(ios.app.show(), true); assert.equal(ios.el('.di-ios').hidden, false);
    ios.el('[data-di-dismiss]:1').click(); assert.equal(ios.el('app-install').hidden, true);
    assert.equal(ios.app.show(), true);
});
