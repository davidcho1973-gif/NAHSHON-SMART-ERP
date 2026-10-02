const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.style = {}; this.textContent = ''; this.disabled = false; }
    append(...items) { this.children.push(...items); }
    replaceChildren(...items) { this.children = items; }
    find(text) { return this.children.flatMap(c => [c, ...c.all()]).find(c => c.textContent === text); }
    all() { return this.children.flatMap(c => [c, ...c.all()]); }
}
function setup(post) {
    const context = { window: {}, document: { createElement: tag => new Element(tag) } };
    vm.runInNewContext(fs.readFileSync('public/js/gate-completion.js', 'utf8'), context);
    const container = new Element('div');
    const text = Object.fromEntries(['closed','closeHint','loading','failed','retry','close','notices','required','details','ack','confirmed'].map(v => [v,v]));
    const flow = context.window.GateCompletion.create({ container, text, post, download: async () => {} });
    return { container, flow };
}
test('required notices need explicit confirmation; loading does not acknowledge or punch', async () => {
    const calls = [];
    const { container, flow } = setup(async (kind, data) => {
        calls.push([kind, data]);
        return { notices: [{ id: 9, title: '<img onerror=bad()>', body: 'Safety', required: true }] };
    });
    await flow.load();
    assert.deepEqual(calls.map(c => c[0]), ['notices']);
    assert.equal(container.find('close').disabled, true);
    await container.find('ack').onclick();
    assert.equal(container.find('close').disabled, false);
    await container.find('close').onclick();
    assert.ok(container.find('closed'));
    assert.deepEqual(calls.map(c => c[0]), ['notices','noticeAck']);
});
test('normal notice is remembered on close; empty notices go directly to terminal view', async () => {
    const calls = [];
    const { container, flow } = setup(async kind => { calls.push(kind); return { notices: [{ id: 1, title: 'Normal', body: 'Text' }] }; });
    await flow.load(); await container.find('close').onclick();
    assert.deepEqual(calls, ['notices','noticeAck']); assert.ok(container.find('closed'));
    const empty = setup(async () => ({ notices: [] })); await empty.flow.load(); assert.ok(empty.container.find('closed'));
});
test('notice outage can retry or close without another attendance request', async () => {
    const calls = [];
    const { container, flow } = setup(async kind => { calls.push(kind); throw Error('offline'); });
    await flow.load(); assert.ok(container.find('failed')); await container.find('retry').onclick();
    container.find('close').onclick(); assert.ok(container.find('closed'));
    assert.deepEqual(calls, ['notices','notices']);
});
test('acknowledgement failure leaves required notice unconfirmed', async () => {
    const { container, flow } = setup(async kind => {
        if (kind === 'noticeAck') throw Error('offline');
        return { notices: [{ id: 1, title: 'Required', required: true }] };
    });
    await flow.load(); await container.find('ack').onclick();
    assert.equal(container.find('close').disabled, true); assert.ok(container.find('retry'));
});
test('saved gate punch enters terminal state instead of calling recognize for the opposite button', () => {
    const source = fs.readFileSync('resources/views/gate/index.blade.php','utf8');
    const handler = source.slice(source.indexOf("el('punch').onclick="), source.indexOf('paint();if(token)'));
    assert.ok(handler.includes('busy||completed'));
    assert.ok(handler.includes('finishPunch(d)'));
    assert.ok(!handler.includes('await recognize()'));
});
