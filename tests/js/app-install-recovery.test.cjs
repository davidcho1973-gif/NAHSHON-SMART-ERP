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

async function employeeAdmin(rows) {
    const actions = new Map(), calls = [], forms = [];
    const window = { location: {}, AdminUI: {
        esc: value => String(value || ''), badge: label => label,
        rowButton: (label, action) => label + ' ' + action,
        pageHeader: () => '', primaryButton: () => '', bindSearch() {},
        formModal: value => forms.push(value),
        table: table => {
            const render = table.columns.find(column => column.key === 'act').render;
            table.rows.forEach(row => actions.set(row.id, render(row)));
            return '';
        },
    }, gsRun: async method => {
        calls.push(method);
        if (method === 'api_getEmployeeAdminOptions') return { canGrantAccount: true };
        if (method === 'api_getEmployeeAdminList') return { rows, canManage: true };
        if (method === 'api_grantEmployeeAccount') return { success: false, error: 'Synthetic stop after save' };
        throw Error('Unexpected API: ' + method);
    } };
    vm.runInNewContext(fs.readFileSync('public/js/admin-employees.js', 'utf8'), {
        window, document: { getElementById: () => ({ innerHTML: '' }) },
    });
    window.AdminEmployees.render();
    await new Promise(resolve => setImmediate(resolve));
    return { window, actions, calls, forms };
}

test('employee actions offer installation only for active eligible accounts, never job titles or purchase grants', async () => {
    const base = { hasAccount: true, status: 'active', accountStatus: 'active', siteQrUrl: '/gate/4/qr' };
    const rows = [
        { ...base, id: 1, accountRole: 'worker', position: 'foreman', purchaseRequestEnabled: true },
        { ...base, id: 2, accountRole: 'foreman', position: 'worker' },
        { ...base, id: 3, accountRole: 'admin' },
        { ...base, id: 4, accountRole: 'admin', accountStatus: 'suspended' },
        { ...base, id: 5, accountRole: 'admin', status: 'terminated' },
        { ...base, id: 6, accountRole: 'company_admin' },
        { ...base, id: 7 },
    ];
    const { actions } = await employeeAdmin(rows);
    assert.match(actions.get(1), /현장 QR 출퇴근/);
    for (const id of [1, 4, 5, 6, 7]) {
        assert.doesNotMatch(actions.get(id), /앱 설치 카드|앱 링크 공유|개인앱 보기|install-card|attendance-app\?as=/);
    }
    for (const id of [2, 3]) {
        assert.match(actions.get(id), new RegExp('attendance-app/employee/' + id + '/install-card'));
        assert.match(actions.get(id), new RegExp('attendance-app/employee/' + id + '/share'));
    }
    assert.match(actions.get(7), /접속 안내/);
    assert.match(actions.get(7), /attendance-app\/employee\/7\/share/);
});

test('employee account form keeps its save flow and distinguishes worker QR from management login', async () => {
    const h = await employeeAdmin([{ id: 8, name: 'Foreman', status: 'active', hasAccount: false }]);
    h.window.AdminEmployees.grantAccount(8);
    await new Promise(resolve => setImmediate(resolve));
    const form = h.forms[0];
    const email = form.fields.find(field => field.name === 'email');
    const role = form.fields.find(field => field.name === 'role');
    assert.match(role.hint, /앱 설치 없이 현장 QR/);
    assert.match(role.hint, /역할·이메일을 바꾸기 전에 초대/);
    assert.match(email.hint, /이 이메일과 같은 Google 계정으로 처음 로그인/);
    assert.match(email.hint, /비밀번호는 이 화면에서 설정되지 않습니다/);
    assert.doesNotMatch(email.hint, /뒷 4자리/);
    assert.match(form.subtitle, /계정 · 권한 관리 → 관리자로 초대를 먼저/);
    await form.onSave({ email: '', role: 'worker', scope: 'self' });
    assert.equal(h.calls.includes('api_grantEmployeeAccount'), true);
});
