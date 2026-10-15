const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../public/js/admin-sites.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));

function harness() {
  const forms = [];
  const calls = [];
  const options = { success: true, timezones: [
    { value: 'America/New_York', label: '동부 · 사바나' },
    { value: 'America/Phoenix', label: '애리조나 · 피닉스' },
    { value: 'America/Los_Angeles', label: '태평양 · 로스앤젤레스' },
  ] };
  const window = {
    AdminUI: {
      formModal: form => forms.push(form),
      toast: () => {},
    },
    gsRun: async (method, args) => {
      calls.push({ method, args });
      if (method === 'api_getSiteAdminOptions') return options;
      // Stop after the save response: list rendering is a separate concern.
      if (method === 'api_saveSiteAdmin') return { success: false, error: 'Synthetic stop after save' };
      throw new Error('Unexpected API call: ' + method);
    },
  };
  vm.runInNewContext(source, { window }, { filename: 'admin-sites.js' });
  return { app: window.AdminSites, forms, calls, options };
}

test('new site requires a timezone selection and submits the chosen IANA zone', async () => {
  const h = harness();
  h.app.openSite();
  await settle();
  const form = h.forms[0];
  const field = form.fields.find(f => f.name === 'timezone');
  assert.equal(field.type, 'select');
  assert.equal(field.required, true);
  assert.equal(field.value, '', 'do not silently default a new site to Arizona');
  assert.equal(field.options.find(o => o.value === 'America/Los_Angeles').label, '태평양 · 로스앤젤레스');
  await form.onSave({ code: 'LA', name: 'LA Site', timezone: 'America/Los_Angeles' });
  const save = h.calls.find(c => c.method === 'api_saveSiteAdmin');
  assert.equal(save.args[0].timezone, 'America/Los_Angeles');
  assert.equal(save.args[0].id, 0);
});

test('editing preserves both catalog and existing non-catalog timezones without leaking choices', async () => {
  const h = harness();
  for (const timezone of ['America/Phoenix', 'Asia/Seoul', 'America/Detroit', 'UTC']) {
    h.app._state.sites = [{ id: 42, code: 'EXISTING', name: 'Existing Site', timezone }];
    h.app.openSite(42);
    await settle();
    const form = h.forms.at(-1);
    const field = form.fields.find(f => f.name === 'timezone');
    assert.equal(field.value, timezone);
    assert.equal(field.options.filter(o => o.value === timezone).length, 1);
    await form.onSave({ code: 'EXISTING', name: 'Updated Site', timezone: field.value });
    assert.equal(h.calls.at(-1).args[0].timezone, timezone);
    assert.equal(h.calls.at(-1).args[0].id, 42);
  }
  assert.equal(h.options.timezones.length, 3, 'never mutate cached server options');
  h.app.openSite();
  await settle();
  const field = h.forms.at(-1).fields.find(f => f.name === 'timezone');
  assert.equal(field.value, '');
  assert.equal(field.options.length, 3);
});
