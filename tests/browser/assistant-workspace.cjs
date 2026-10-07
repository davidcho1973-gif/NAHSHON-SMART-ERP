'use strict';

// Real Chromium and actual password/preview/confirmation endpoints. The explicitly labeled
// suggestion UI fixture alone fakes its AI response; backend suggestions have HTTP-fake feature tests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium, expect } = require('@playwright/test');
const { withThrottleRetry } = require('./throttle-retry.cjs');
assert.equal(require('@playwright/test/package.json').version, '1.57.0', 'Use the pinned test tool version.');

const base = process.env.ERP_BROWSER_BASE_URL;
assert.equal(process.env.ERP_BROWSER_SYNTHETIC, '1');
assert.equal(base, 'http://127.0.0.1:8765', 'Never point this test at a deployed ERP.');
const fixture = JSON.parse(fs.readFileSync('storage/app/erp-browser-synthetic.json', 'utf8'));
assert.equal(fixture.synthetic, true);
const artifacts = path.resolve('test-results/assistant-browser');
fs.mkdirSync(artifacts, { recursive: true });
const php = (...args) => execFileSync(process.env.PHP_BINARY || 'php', ['tests/browser/fixture.php', ...args], { encoding: 'utf8' });
const state = () => JSON.parse(php('inspect'));
const results = [];
const errors = [];
const forbiddenTraffic = [];
let browser;
let activePage;

async function step(name, fn) {
    await fn();
    results.push(name);
    console.log(`PASS ${name}`);
}

async function context(viewport) {
    const ctx = await browser.newContext({ viewport, acceptDownloads: true, serviceWorkers: 'block', locale: 'ko-KR' });
    await ctx.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.origin === base) return route.continue();
        // Fonts/CDN decoration is deliberately unavailable; all application/provider traffic must be local.
        if (!['image', 'font', 'stylesheet', 'script'].includes(request.resourceType())) forbiddenTraffic.push(url.origin);
        return route.abort('blockedbyclient');
    });
    ctx.on('page', page => page.on('pageerror', error => {
        if (new URL(page.url()).pathname === '/attendance-app/ask') errors.push(error.message);
    }));
    return ctx;
}

async function api(page, suffix, payload, method) {
    // The dense CI flow shares the real 30/minute limiter. Only a confirmed
    // rejection is safe to replay; transport failures and other statuses are not.
    return withThrottleRetry(() => page.evaluate(async ({ suffix, payload, method }) => {
        const response = await fetch('/ask-api/workspace' + suffix, {
            method: method || (payload === undefined ? 'GET' : 'POST'), credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: payload === undefined ? undefined : JSON.stringify(payload),
        });
        return { status: response.status, cache: response.headers.get('cache-control'),
            retryAfter: response.headers.get('retry-after'), body: await response.json() };
    }, { suffix, payload, method }));
}

async function openAsk(page) {
    const status = page.waitForResponse(r => new URL(r.url()).pathname === '/ask-api/workspace/status');
    await page.goto(base + '/attendance-app/ask');
    assert.equal((await status).status(), 200, 'A password-established strong session is required.');
    await expect(page.locator('#assistant-workspace')).toBeVisible();
    await expect(page.locator('#assistant-write-fields')).toBeEnabled();
}

async function login(page, user) {
    await page.goto(base + '/login?erp=1');
    await page.locator('#login-email').fill(user.email);
    await page.locator('#login-password').fill(fixture.password);
    const response = page.waitForResponse(r => new URL(r.url()).pathname === '/auth/password/login' && r.request().method() === 'POST');
    await page.locator('#email-login button[type=submit]').click();
    assert.equal((await response).status(), 302);
    await page.waitForURL(url => url.pathname === '/');
    await openAsk(page);
}

async function panel(page, index) {
    const details = page.locator('#assistant-workspace > details').nth(index);
    if (await details.getAttribute('open') === null) await details.locator('summary').click();
}

async function layout(page, label) {
    await panel(page, 0);
    await panel(page, 1);
    await expect(page.locator('#assistant-title')).toBeVisible();
    const measurement = await page.evaluate(() => {
        const root = document.documentElement;
        const selectors = ['#assistant-workspace', '#assistant-company', '#assistant-site', '#assistant-title', '[data-action=propose]'];
        return { width: innerWidth, scroll: root.scrollWidth, boxes: selectors.map(selector => {
            const r = document.querySelector(selector).getBoundingClientRect();
            return { selector, left: r.left, right: r.right, width: r.width, height: r.height };
        }) };
    });
    assert.ok(measurement.scroll <= measurement.width + 1, `${label}: page has horizontal overflow`);
    for (const box of measurement.boxes) {
        assert.ok(box.left >= -1 && box.right <= measurement.width + 1 && box.width > 0 && box.height > 0,
            `${label}: ${box.selector} is clipped`);
    }
    await page.screenshot({ path: path.join(artifacts, `SYNTHETIC-${label}.png`), fullPage: true });
}

async function propose(page, title) {
    await panel(page, 1);
    await page.locator('#assistant-operation').selectOption('ops.todo.create');
    await page.locator('#assistant-title').fill(title);
    const response = page.waitForResponse(r => new URL(r.url()).pathname === '/ask-api/workspace/proposals');
    await page.locator('[data-action=propose]').click();
    const result = await response;
    assert.equal(result.status(), 200);
    const proposal = (await result.json()).proposal;
    await expect(page.locator('#assistant-preview')).toBeVisible();
    await expect(page.locator('#assistant-preview-body')).toContainText(title);
    await expect(page.locator('[data-action=confirm]')).toBeDisabled();
    await expect(page.locator('#assistant-approve')).not.toBeChecked();
    return proposal;
}

async function restore(page, id) {
    // Reload recovery also consumes the real request limiter. Replay only a confirmed
    // 429 read after its Retry-After delay; no uncertain request or mutation is retried.
    const recovered = await withThrottleRetry(async () => {
        await page.evaluate(({ user, id }) => sessionStorage.setItem('erp-assistant-proposal-' + user, id), { user: fixture.users.owner.id, id });
        const response = page.waitForResponse(r => new URL(r.url()).pathname === '/ask-api/workspace/proposals/' + id);
        await page.reload();
        const result = await response;
        return { status: result.status(), retryAfter: result.headers()['retry-after'], result };
    });
    assert.equal(recovered.status, 200);
    const result = recovered.result;
    await panel(page, 1);
    await expect(page.locator('#assistant-preview')).toBeVisible();
    await expect(page.locator('#assistant-approve')).not.toBeChecked();
    await expect(page.locator('[data-action=confirm]')).toBeDisabled();
    return (await result.json()).proposal;
}

// CI deliberately has no provider key. Keep the AI fixture entirely browser-local:
// status/suggestion payloads are simulated, while preview/confirmation remain real HTTP/DB.
async function suggestedPreview(page) {
    const statusUrl = base + '/ask-api/workspace/status*';
    const suggestionUrl = base + '/ask-api/workspace/suggestions';
    const baseline = state();
    let requests = 0, release, entered;
    const waiting = new Promise(resolve => { entered = resolve; });
    const delayed = new Promise(resolve => { release = resolve; });
    const statusHandler = async route => {
        const actual = await route.fetch();
        assert.equal(actual.status(), 200);
        const body = await actual.json();
        assert.equal(body.suggestions_enabled, false, 'Real synthetic server has no provider key.');
        await route.fulfill({ response: actual, json: { ...body, suggestions_enabled: true } });
    };
    const suggestionHandler = async route => {
        const request = route.request().postDataJSON();
        assert.equal(request.operation, 'ops.todo.create');
        assert.equal(request.company_id, fixture.companies.A);
        assert.equal(request.site_id, fixture.sites.A);
        assert.equal(request.record_id, null);
        assert.equal(request.source_document_id, null);
        requests++;
        if (requests === 1) { entered(); await delayed; }
        const { request_text, ...envelope } = request;
        assert.equal(typeof request_text, 'string');
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true,
            suggestion: { ...envelope, fields: { title: 'SYNTHETIC suggested duct inspection', detail: 'Must not overwrite my words' },
                questions: ['Check the proposed fields.'], missing_fields: [] } }) });
    };
    await page.route(statusUrl, statusHandler);
    await page.route(suggestionUrl, suggestionHandler);
    try {
        await openAsk(page);
        await panel(page, 1);
        await page.locator('#assistant-operation').selectOption('ops.todo.create');
        await page.locator('#assistant-title').fill('');
        await page.locator('#assistant-detail').fill('SYNTHETIC existing manual detail');
        await page.locator('#assistant-request-text').fill('Inspect the duct layout.');
        await expect(page.locator('[data-action=suggest]')).toBeEnabled();
        await page.locator('[data-action=suggest]').evaluate(button => { button.click(); button.click(); });
        await waiting;
        assert.equal(requests, 1);
        await expect(page.locator('[data-action=propose]')).toBeDisabled();
        await page.locator('#assistant-detail').fill('SYNTHETIC newer manual detail');
        const first = page.waitForResponse(r => new URL(r.url()).pathname === '/ask-api/workspace/suggestions');
        release();
        await first;
        await expect(page.locator('[data-action=suggest]')).toBeEnabled();
        await expect(page.locator('#assistant-title')).toHaveValue('');
        await expect(page.locator('#assistant-detail')).toHaveValue('SYNTHETIC newer manual detail');
        const second = page.waitForResponse(r => new URL(r.url()).pathname === '/ask-api/workspace/suggestions');
        await page.locator('[data-action=suggest]').click();
        assert.equal((await second).status(), 200);
        await expect(page.locator('#assistant-title')).toHaveValue('SYNTHETIC suggested duct inspection');
        await expect(page.locator('#assistant-detail')).toHaveValue('SYNTHETIC newer manual detail');
        await expect(page.locator('#assistant-preview')).toBeHidden();
        await expect(page.locator('#assistant-approve')).not.toBeChecked();
        assert.equal(state().proposals.length, baseline.proposals.length, 'AI suggestions cannot create proposals.');
        assert.equal(state().todos.length, baseline.todos.length, 'AI suggestions cannot create business records.');
        await page.locator('#assistant-title').fill('SYNTHETIC confirm once');
        const response = page.waitForResponse(r => new URL(r.url()).pathname === '/ask-api/workspace/proposals');
        await page.locator('[data-action=propose]').click();
        const actual = await response;
        assert.equal(actual.status(), 200);
        const proposal = (await actual.json()).proposal;
        assert.equal(proposal.after.title, 'SYNTHETIC confirm once');
        assert.equal(proposal.after.detail, 'SYNTHETIC newer manual detail');
        await expect(page.locator('[data-action=confirm]')).toBeDisabled();
        await expect(page.locator('#assistant-approve')).not.toBeChecked();
        return proposal;
    } finally {
        release();
        await page.unroute(suggestionUrl, suggestionHandler);
        await page.unroute(statusUrl, statusHandler);
    }
}

(async () => {
    php('guard');
    browser = await chromium.launch({ headless: true });
    const desktop = await context({ width: 1440, height: 1000 });
    const page = activePage = await desktop.newPage();

    await step('login interruption, Back and reload cannot create authentication', async () => {
        await page.goto(base + '/up');
        await page.goto(base + '/login?erp=1');
        await page.locator('#login-email').fill(fixture.users.owner.email);
        await page.locator('#login-password').fill(fixture.password);
        await page.goBack(); // Leave the unfinished login without submitting it.
        assert.equal(new URL(page.url()).pathname, '/up');
        const unauthenticated = await desktop.request.get(base + '/ask-api/workspace/status', { headers: { Accept: 'application/json' } });
        assert.equal(unauthenticated.status(), 401);
        await page.goForward();
        await page.reload();
        await expect(page.locator('#email-login')).toBeVisible();
        const again = await desktop.request.get(base + '/ask-api/workspace/status', { headers: { Accept: 'application/json' } });
        assert.equal(again.status(), 401);
        await login(page, fixture.users.owner);
    });

    await step('desktop and mobile controls stay inside the viewport', async () => {
        await layout(page, 'desktop-1440');
        await page.setViewportSize({ width: 390, height: 844 });
        await layout(page, 'mobile-390');
        await page.setViewportSize({ width: 1440, height: 1000 });
    });

    await step('grounded scoped report and real downloaded XLSX', async () => {
        await page.locator('#assistant-dataset').selectOption('wbs_items');
        const response = page.waitForResponse(r => new URL(r.url()).pathname === '/ask-api/workspace/report');
        await page.locator('[data-action=report]').click();
        const result = await response;
        assert.equal(result.status(), 200);
        assert.match(result.headers()['cache-control'], /private/);
        const report = (await result.json()).report;
        assert.deepEqual(report.records.map(r => r.id), [fixture.wbs.A]);
        assert.deepEqual(report.sources, [{ dataset: 'wbs_items', record_id: fixture.wbs.A }]);
        await expect(page.locator('#assistant-report')).toContainText('SYNTHETIC formula');
        await expect(page.locator('#assistant-report')).not.toContainText('OTHER COMPANY');
        const downloaded = page.waitForEvent('download');
        await page.locator('#assistant-export').click();
        const download = await downloaded;
        assert.match(download.suggestedFilename(), /^erp-wbs_items-.*\.xlsx$/);
        assert.equal(await download.failure(), null);
        const file = path.join(artifacts, 'SYNTHETIC-report.xlsx');
        await download.saveAs(file);
        console.log(php('xlsx', file).trim());
        await layout(page, 'desktop-report');
        await page.setViewportSize({ width: 390, height: 844 });
        await layout(page, 'mobile-report');
        await page.setViewportSize({ width: 1440, height: 1000 });
    });

    await step('company/site changes discard stale report results and approvals', async () => {
        let release, entered;
        const waiting = new Promise(resolve => { entered = resolve; });
        const delayed = new Promise(resolve => { release = resolve; });
        const routeHandler = async route => {
            const response = await route.fetch(); // The data still comes from Laravel/PostgreSQL.
            entered();
            await delayed;
            await route.fulfill({ response });
        };
        await page.route(base + '/ask-api/workspace/report', routeHandler);
        await page.locator('[data-action=report]').click();
        await waiting;
        await page.locator('#assistant-company').selectOption(String(fixture.companies.B));
        await expect(page.locator('#assistant-site')).toHaveValue(String(fixture.sites.B));
        const delivered = page.waitForResponse(r => new URL(r.url()).pathname === '/ask-api/workspace/report');
        release();
        await delivered;
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        await expect(page.locator('#assistant-report')).toBeEmpty();
        await page.unroute(base + '/ask-api/workspace/report', routeHandler);
        // Wait for the delayed response to be consumed before issuing the next report.
        await page.waitForFunction(() => document.querySelector('#assistant-write-fields').disabled === false);
        const proposal = await propose(page, 'SYNTHETIC context discarded');
        assert.equal(proposal.site_id, fixture.sites.B);
        await page.locator('#assistant-approve').check();
        await page.locator('#assistant-company').selectOption(String(fixture.companies.A));
        await expect(page.locator('#assistant-site')).toHaveValue(String(fixture.sites.A));
        await expect(page.locator('#assistant-preview')).toBeHidden();
        await expect(page.locator('[data-action=confirm]')).toBeDisabled();
        assert.equal((await api(page, '/proposals/' + proposal.id + '/cancel', {})).status, 200);
    });

    await step('browser-only suggestion fixture requires real preview, explicit checkbox and exactly-once confirmation', async () => {
        const proposal = await suggestedPreview(page);
        assert.equal(state().todos.length, 1, 'Preview must not create a business record.');
        await expect(page.locator('#assistant-preview-body')).toContainText('SYNTHETIC Company A');
        await expect(page.locator('#assistant-preview-body')).toContainText('SYNTHETIC Site A');
        await expect(page.locator('#assistant-preview-body')).toContainText('site_operations_board');
        await page.locator('#assistant-approve').check();
        const response = page.waitForResponse(r => new URL(r.url()).pathname.endsWith('/' + proposal.id + '/confirm'));
        let requests = 0;
        const listener = request => { if (new URL(request.url()).pathname.endsWith('/' + proposal.id + '/confirm')) requests++; };
        page.on('request', listener);
        // Same-tick repeated DOM clicks exercise the actual browser event handler's in-flight guard.
        await page.locator('[data-action=confirm]').evaluate(button => { button.click(); button.click(); });
        assert.equal((await response).status(), 200);
        await expect(page.locator('#assistant-preview')).toBeHidden();
        assert.equal(requests, 1);
        page.off('request', listener);
        const retry = await api(page, '/proposals/' + proposal.id + '/confirm',
            { confirmed: true, version: proposal.version, preview_token: proposal.preview_token });
        assert.equal(retry.status, 200);
        assert.equal(retry.body.proposal.status, 'applied');
        assert.equal(state().todos.filter(t => t.title === 'SYNTHETIC confirm once').length, 1);
    });

    await step('cancel and reload recovery require fresh review without writes', async () => {
        const proposal = await propose(page, 'SYNTHETIC cancel after reload');
        await page.locator('#assistant-approve').check();
        await restore(page, proposal.id);
        await expect(page.locator('#assistant-preview-body')).toContainText(proposal.id);
        const cancelled = page.waitForResponse(r => new URL(r.url()).pathname.endsWith('/' + proposal.id + '/cancel'));
        await page.locator('[data-action=cancel]').click();
        assert.equal((await cancelled).status(), 200);
        await expect(page.locator('#assistant-preview')).toBeHidden();
        await page.reload();
        await panel(page, 1);
        await expect(page.locator('#assistant-preview')).toBeHidden();
        assert.equal(state().todos.length, 2);
    });

    await step('lost confirmation response recovers saved applied status without reapplying', async () => {
        const proposal = await propose(page, 'SYNTHETIC lost response');
        const endpoint = base + '/ask-api/workspace/proposals/' + proposal.id + '/confirm';
        await page.route(endpoint, async route => {
            const actual = await route.fetch();
            assert.equal(actual.status(), 200);
            await route.abort('failed'); // Commit happened; only delivery to the UI is interrupted.
        });
        await page.locator('#assistant-approve').check();
        await page.locator('[data-action=confirm]').click();
        await expect(page.locator('#assistant-status')).toHaveClass(/bad/);
        await page.unroute(endpoint);
        const recovered = await restore(page, proposal.id);
        assert.equal(recovered.status, 'applied');
        await expect(page.locator('#assistant-preview-body')).toContainText('applied');
        assert.equal(state().todos.filter(t => t.title === 'SYNTHETIC lost response').length, 1);
    });

    await step('expired and concurrently changed proposals cannot apply', async () => {
        const expired = await restore(page, fixture.expired);
        assert.equal(expired.status, 'expired');
        const rejected = await api(page, '/proposals/' + expired.id + '/confirm',
            { confirmed: true, version: expired.version, preview_token: expired.preview_token });
        assert.equal(rejected.status, 409);
        const stale = await restore(page, fixture.stale);
        assert.equal(stale.status, 'pending');
        await page.locator('#assistant-approve').check();
        const response = page.waitForResponse(r => new URL(r.url()).pathname.endsWith('/' + stale.id + '/confirm'));
        await page.locator('[data-action=confirm]').click();
        assert.equal((await response).status(), 409);
        await expect(page.locator('#assistant-status')).toHaveClass(/bad/);
        assert.equal((await api(page, '/proposals/' + stale.id)).body.proposal.status, 'stale');
        assert.equal(state().todos.find(t => t.id === fixture.stale_record).title, 'SYNTHETIC concurrent edit retained');
    });

    await step('private source metadata is absent and cannot be used for a category proposal', async () => {
        const report = await api(page, '/report', { company_id: fixture.companies.A, site_id: fixture.sites.A, dataset: 'documents' });
        assert.equal(report.status, 200);
        assert.deepEqual(report.body.report.records.map(r => r.id), [fixture.documents.shared]);
        assert.ok(!JSON.stringify(report.body).includes('PRIVATE CONTENT'));
        const denied = await api(page, '/proposals', { operation: 'document.category.update', site_id: fixture.sites.A,
            record_id: fixture.documents.private, payload: { category: 'drawing_spec' } });
        assert.equal(denied.status, 404);
    });

    await step('recurring check needs explicit checkbox and can be stopped', async () => {
        await panel(page, 2);
        const saved = page.waitForResponse(r => new URL(r.url()).pathname === '/ask-api/workspace/checks');
        await page.locator('[data-action=save-check]').click();
        const check = (await (await saved).json()).check;
        assert.equal(check.enabled, false);
        const activate = page.locator('[data-action=activate-check][data-id="' + check.id + '"]');
        await expect(activate).toBeVisible();
        await activate.click();
        await expect(page.locator('#assistant-status')).toHaveClass(/bad/);
        await page.locator('[data-check-consent="' + check.id + '"]').check();
        const started = page.waitForResponse(r => new URL(r.url()).pathname.endsWith('/checks/' + check.id + '/activate'));
        await activate.click();
        assert.equal((await started).status(), 200);
        const stop = page.locator('[data-action=disable-check][data-id="' + check.id + '"]');
        await expect(stop).toBeVisible();
        const stopped = page.waitForResponse(r => new URL(r.url()).pathname.endsWith('/checks/' + check.id + '/disable'));
        await stop.click();
        assert.equal((await stopped).status(), 200);
    });

    await step('daily-report checks are hourly and remain off after saving', async () => {
        await page.locator('#assistant-check-kind').selectOption('missing_trade_reports');
        await expect(page.locator('#assistant-check-interval')).toHaveValue('1');
        await expect(page.locator('#assistant-check-interval option')).toHaveCount(1);
        const rejected = await api(page, '/checks', {
            site_id: fixture.sites.A, kind: 'missing_trade_reports', interval_hours: 24
        });
        assert.equal(rejected.status, 422, 'The server must reject a non-hourly daily-report check.');
        const response = page.waitForResponse(r => new URL(r.url()).pathname === '/ask-api/workspace/checks');
        await page.locator('[data-action=save-check]').click();
        const check = (await (await response).json()).check;
        assert.equal(check.enabled, false);
        assert.equal(check.interval_hours, 1);
        await expect(page.locator('[data-check-consent="' + check.id + '"]')).not.toBeChecked();
        await page.locator('#assistant-check-kind').selectOption('pending_expense_approvals');
        await expect(page.locator('#assistant-check-interval option')).toHaveCount(3);
    });

    await step('site-limited password session rejects client-supplied role and foreign scope', async () => {
        const mobile = await context({ width: 390, height: 844 });
        const limited = activePage = await mobile.newPage();
        await login(limited, fixture.users.limited);
        await expect(limited.locator('#assistant-company option')).toHaveCount(1);
        await expect(limited.locator('#assistant-site option')).toHaveCount(1);
        const denied = await api(limited, '/report', { dataset: 'wbs_items', company_id: fixture.companies.B,
            site_id: fixture.sites.B, role: 'super_admin', user_id: fixture.users.owner.id });
        assert.equal(denied.status, 403);
        const crossSite = await api(limited, '/report', { dataset: 'wbs_items', company_id: fixture.companies.A, site_id: fixture.sites.B });
        assert.equal(crossSite.status, 403);
        const privateProposal = await api(limited, '/proposals/' + fixture.stale);
        assert.equal(privateProposal.status, 404);
        assert.deepEqual((await api(limited, '/status')).body.checks, [], 'Checks must remain actor-private.');
        const money = await api(limited, '/report', { dataset: 'pay_applications', company_id: fixture.companies.A, site_id: fixture.sites.A });
        assert.equal(money.status, 403);
        await layout(limited, 'mobile-limited-scope');
        await mobile.close();
        activePage = page;
    });

    await step('source records remain unchanged and only approved writes exist', async () => {
        console.log(php('assert-final').trim());
        assert.deepEqual(errors, [], 'Ask page has uncaught JavaScript errors.');
        assert.deepEqual(forbiddenTraffic, [], 'Application attempted nonlocal traffic.');
    });
})().catch(async error => {
    console.error(error);
    if (activePage && !activePage.isClosed()) await activePage.screenshot({ path: path.join(artifacts, 'SYNTHETIC-failure.png'), fullPage: true }).catch(() => {});
    process.exitCode = 1;
}).finally(async () => {
    fs.writeFileSync(path.join(artifacts, 'SYNTHETIC-results.json'), JSON.stringify({ synthetic: true, passed: results, errors, forbiddenTraffic, commit: process.env.GITHUB_SHA || null }, null, 2));
    if (browser) await browser.close();
});
