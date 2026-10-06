const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('invitation form sends the selected grant and sharing copies the complete private link', async () => {
  let form, modal, copied;
  const calls = [];
  const row = { id: 7, name: 'Worker', siteId: 4, canInvite: true };
  const ui = {
    esc: s => String(s || ''), formModal: o => { form = o; }, modal: o => { modal = o; },
    pageHeader: () => '', primaryButton: () => '', table: () => '', bindSearch() {}, toast() {},
  };
  const options = { roles: [{ value: 'worker' }, { value: 'site_manager' }, { value: 'admin' }, { value: 'super_admin' }],
    scopes: [{ value: 'site' }, { value: 'self' }, { value: 'company' }, { value: 'all_sites' }], sites: [], companies: [], canIssueInvitations: true };
  const context = { window: { AdminUI: ui, gsRun: async (method, args) => {
    calls.push({ method, args });
    if (method === 'api_getUserAccessOptions') return options;
    if (method === 'api_getUserAccessList') return { rows: [row] };
    return { success: true, name: 'Worker', url: 'https://erp.example/manager-invitation/secret', qr: 'data:image/svg+xml;base64,test', expiresAt: '2030-01-01' };
  } }, document: { getElementById: () => ({ innerHTML: '' }) },
  navigator: { clipboard: { writeText: async text => { copied = text; } } }, setTimeout: fn => fn() };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('public/js/admin-access.js', 'utf8'), context);
  context.window.AdminAccess._state.rows = [row];
  context.window.AdminAccess.invite(7);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(form.fields[0].value, 'site_manager');
  assert.deepEqual(Array.from(form.fields[0].options, o => o.value), ['site_manager', 'admin']);
  await form.onSave({ role: 'site_manager', scope: 'site', siteId: '4' });
  const issued = calls.find(c => c.method === 'api_createManagerInvitation');
  assert.equal(issued.args[0].id, 7);
  assert.equal(issued.args[0].siteId, '4');
  assert.match(modal.body, /관리자 등록 초대 QR/);
  assert.match(modal.body, /manager-invitation\/secret/);
  modal.onAction({ action: 'copy' }, {});
  await new Promise(resolve => setImmediate(resolve));
  assert.match(copied, /Worker/);
  assert.match(copied, /https:\/\/erp.example\/manager-invitation\/secret/);
});
