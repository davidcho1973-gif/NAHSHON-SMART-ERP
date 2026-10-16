const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup({ errors = false, token = '' } = {}) {
    const source = fs.readFileSync('resources/views/gate/index.blade.php', 'utf8');
    const script = source.match(/<script>\s*([\s\S]*?)<\/script>/)[1]
        .replace(/@json\(route\([^)]*\)\)/g, '"/test-route"')
        .replaceAll('@json($lang)', '"ko"')
        .replaceAll('@json($errors->any())', String(errors));
    const nodes = Object.fromEntries([...source.matchAll(/id="([^"]+)"/g)].map(match => [match[1], {
        hidden: false, disabled: false, value: '', textContent: '', listeners: {},
        addEventListener(type, callback) { this.listeners[type] = callback; },
        focus() { this.focused = true; },
    }]));
    const state = { marked: 0, requests: 0 };
    const context = {
        document: { documentElement: {}, getElementById: id => nodes[id], querySelectorAll: () => [] },
        localStorage: { getItem: () => token }, navigator: {},
        window: { markRegisteringPhone(form) { assert.equal(form, nodes['register-form']); state.marked++; } },
        fetch() { state.requests++; throw new Error('Registration review must not call attendance APIs'); },
    };
    vm.runInNewContext(script, context);
    function submit() {
        const event = { prevented: false, preventDefault() { this.prevented = true; } };
        nodes['register-form'].listeners.submit(event);
        return event;
    }
    return { nodes, state, submit };
}

test('registration reviews details before posting, supports correction, and submits only once', () => {
    const { nodes, state, submit } = setup();
    nodes['new-worker'].onclick();
    nodes['full-name'].value = ' Wrong Name ';
    nodes['new-phone'].value = '480-555-0142';

    assert.equal(submit().prevented, true);
    assert.equal(nodes['registration-fields'].hidden, true);
    assert.equal(nodes['registration-review'].hidden, false);
    assert.equal(nodes['review-name'].textContent, 'Wrong Name');
    assert.equal(state.marked, 0);

    nodes['edit-registration'].onclick();
    assert.equal(nodes['registration-fields'].hidden, false);
    nodes['full-name'].value = '<img onerror=bad()> Worker';
    nodes['new-phone'].value = '480-555-0155';
    assert.equal(submit().prevented, true);
    assert.equal(nodes['review-name'].textContent, '<img onerror=bad()> Worker');
    assert.equal(nodes['review-phone'].textContent, '480-555-0155');
    assert.equal(submit().prevented, false);
    assert.equal(state.marked, 1);
    assert.equal(nodes['confirm-registration'].disabled, true);
    assert.equal(submit().prevented, true);
    assert.equal(state.marked, 1);
    assert.equal(state.requests, 0);
});

test('registration validation errors stay on the form even if this phone remembers another worker', () => {
    const { nodes, state } = setup({ errors: true, token: 'previous-worker-device' });
    assert.equal(nodes.register.hidden, false);
    assert.equal(nodes.entry.hidden, true);
    assert.equal(nodes.attendance.hidden, true);
    assert.equal(state.requests, 0);
});
