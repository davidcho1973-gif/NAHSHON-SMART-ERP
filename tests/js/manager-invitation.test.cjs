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

test('new manager invite has no employee prerequisite, fixes admin scope and can reissue or cancel', async () => {
  let form, modal, copied;
  const calls = [];
  const pending = { id: 12, label: '703K manager', grant: { access_role: 'site_manager', access_scope: 'site' },
    enrollment: { site_id: 4, company_id: 2 } };
  const ui = {
    esc: s => String(s || ''), formModal: o => { form = o; }, modal: o => { modal = o; },
    pageHeader: () => '', primaryButton: () => '', table: () => '', bindSearch() {}, toast() {},
    confirmDanger: async () => true,
  };
  const options = { roles: [{ value: 'site_manager' }, { value: 'admin' }], scopes: [{ value: 'site' }, { value: 'all_sites' }],
    sites: [], companies: [], canIssueInvitations: true };
  const context = { window: { AdminUI: ui, gsRun: async (method, args) => {
    calls.push({ method, args });
    if (method === 'api_getUserAccessOptions') return options;
    if (method === 'api_getUserAccessList') return { rows: [], newInvitations: [pending] };
    return { success: true, kind: 'new_employee', name: 'New hire', url: 'https://erp.example/manager-invitation/new-secret', qr: 'data:image/svg+xml;base64,test' };
  } }, document: { getElementById: () => ({ innerHTML: '' }) },
  navigator: { clipboard: { writeText: async text => { copied = text; } } }, setTimeout: fn => fn() };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('public/js/admin-access.js', 'utf8'), context);
  context.window.AdminAccess.inviteNew();
  await new Promise(resolve => setImmediate(resolve));
  assert.match(form.subtitle, /직원 사전 등록 없이 링크 하나/);
  assert.ok(form.fields.some(f => f.name === 'recipientLabel'));
  await form.onSave({ role: 'admin', scope: 'site', siteId: '4' });
  const issued = calls.find(c => c.method === 'api_createManagerInvitation');
  assert.equal(issued.args[0].kind, 'new_employee');
  assert.equal(issued.args[0].scope, 'all_sites');
  assert.equal(issued.args[0].id, undefined);
  assert.match(modal.subtitle, /직원·계정이 생성되지 않습니다/);
  modal.onAction({ action: 'copy' }, {});
  await new Promise(resolve => setImmediate(resolve));
  assert.match(copied, /이름·전화번호/);
  assert.match(copied, /new-secret/);
  context.window.AdminAccess.inviteNew(12);
  await new Promise(resolve => setImmediate(resolve));
  await form.onSave({ role: 'site_manager', scope: 'site', siteId: '4' });
  assert.equal(calls.filter(c => c.method === 'api_createManagerInvitation').at(-1).args[0].replaceInvitationId, 12);
  context.window.AdminAccess.revokeNewInvite(12);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls.find(c => c.method === 'api_revokeNewManagerInvitation').args[0], 12);
});
