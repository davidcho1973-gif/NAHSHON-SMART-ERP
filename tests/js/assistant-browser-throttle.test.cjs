const test = require('node:test');
const assert = require('node:assert/strict');
const { retryAfterMs, withThrottleRetry } = require('../browser/throttle-retry.cjs');

test('browser harness respects Retry-After seconds and HTTP dates', () => {
    assert.equal(retryAfterMs('60'), 60_250);
    assert.equal(retryAfterMs('Tue, 06 Oct 2026 03:33:00 GMT', Date.parse('2026-10-06T03:32:00Z')), 60_250);
    for (const value of [null, '', 'invalid', '1000', '-1']) assert.throws(() => retryAfterMs(value));
});

test('browser harness waits and retries exactly once only after confirmed 429', async () => {
    const waits = [];
    let calls = 0;
    const result = await withThrottleRetry(async () => ++calls === 1 ? { status: 429, retryAfter: '60' } : { status: 200 },
        async delay => waits.push(delay), () => {});
    assert.equal(result.status, 200);
    assert.equal(calls, 2);
    assert.deepEqual(waits, [60_250]);
});

test('browser harness preserves non-429 failures and never retries uncertain requests', async () => {
    for (const status of [200, 401, 403, 404, 409, 500]) {
        let calls = 0;
        const response = await withThrottleRetry(async () => { calls++; return { status }; }, async () => assert.fail('Unexpected wait'));
        assert.equal(response.status, status);
        assert.equal(calls, 1);
    }
    let calls = 0;
    await assert.rejects(withThrottleRetry(async () => { calls++; throw new Error('Uncertain response'); }), /Uncertain response/);
    assert.equal(calls, 1);
});

test('browser harness leaves a repeated 429 visible to the original assertion', async () => {
    let calls = 0;
    const result = await withThrottleRetry(async () => { calls++; return { status: 429, retryAfter: '0' }; }, async () => {}, () => {});
    assert.equal(result.status, 429);
    assert.equal(calls, 2);
});
