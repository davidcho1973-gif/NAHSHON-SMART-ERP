'use strict';

const { setTimeout: sleep } = require('node:timers/promises');

function retryAfterMs(value, now = Date.now()) {
    if (typeof value !== 'string' || value.trim() === '') throw new Error('429 response is missing Retry-After.');
    const delay = /^\d+$/.test(value) ? Number(value) * 1000 : Date.parse(value) - now;
    // One normal 60-second throttle window plus a small clock/timer margin.
    if (!Number.isFinite(delay) || delay < 0 || delay > 65_000) throw new Error('Retry-After exceeds the bounded browser-test wait.');
    return delay + 250;
}

async function withThrottleRetry(request, wait = sleep, log = console.log) {
    for (let attempt = 0; attempt < 2; attempt++) {
        const response = await request();
        if (response.status !== 429 || attempt === 1) return response;
        const delay = retryAfterMs(response.retryAfter);
        log(`Confirmed HTTP 429; respecting Retry-After before one retry (${delay} ms).`);
        await wait(delay);
    }
}

module.exports = { retryAfterMs, withThrottleRetry };
