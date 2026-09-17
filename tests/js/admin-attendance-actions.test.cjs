const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const shellSource = fs.readFileSync(path.join(root, 'public/js/admin-shell.js'), 'utf8');
const attendanceSource = fs.readFileSync(path.join(root, 'public/js/admin-attendance.js'), 'utf8');
const clone = value => JSON.parse(JSON.stringify(value));
const settle = () => new Promise(resolve => setImmediate(resolve));

// Decode once, after finding the closing quote, just as an HTML attribute is read.
// Decoding before extracting onclick would hide the original nested-quote bug.
function decodeAttribute(value) {
  const entities = { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'" };
  return value.replace(/&(?:amp|lt|gt|quot|#39);/g, entity => entities[entity]);
}

function controls(html) {
  return Array.from(html.matchAll(/<(button|a)\b([^>]*)>([\s\S]*?)<\/\1>/g), match => ({
    attributes: match[2],
    label: decodeAttribute(match[3].replace(/<[^>]*>/g, '')),
  }));
}

function executeControl(context, control) {
  assert.ok(control, 'the requested control must be rendered');
  const match = control.attributes.match(/\bonclick="([^"]*)"/);
  assert.ok(match, 'the control must have an onclick attribute');
  return vm.runInContext('(function () {' + decodeAttribute(match[1]) + '\n}).call(window)', context);
}

function row(overrides = {}) {
  return {
    id: 41, employeeId: 7, employee: 'Test Worker', employeeNumber: 'TEST-7',
    date: '2026-09-15', eventAt: '2026-09-15 07:30:00', eventTime: '07:30',
    eventType: 'clock_in', eventTypeLabel: '출근', status: 'pending', statusLabel: '대기중',
    source: 'manual', sourceLabel: '수기 입력', siteId: 3, site: 'TEST-SITE',
    notes: 'Original note', editCount: 1, deleted: false, ...overrides,
  };
}

async function harness({ rows = [row()], canManage = true, canDelete = true, responses = {} } = {}) {
  const host = { innerHTML: '' };
  const calls = [];
  const forms = [];
  const confirmations = [];
  const toasts = [];
  const appended = [];
  const serverRows = clone(rows);
  const document = {
    getElementById: id => id === 'page-container' ? host : null,
    createElement: () => ({
      style: {}, innerHTML: '', listeners: {}, removed: false,
      addEventListener(type, listener) { this.listeners[type] = listener; },
      remove() { this.removed = true; },
    }),
    body: { appendChild: element => appended.push(element) },
  };
  const context = vm.createContext({ window: {}, document });
  vm.runInContext(shellSource, context, { filename: 'admin-shell.js' });
  const ui = context.window.AdminUI;
  ui.bindSearch = () => {};
  ui.formModal = options => { forms.push(options); return Promise.resolve(); };
  ui.confirmDanger = options => new Promise(resolve => confirmations.push({ options, resolve }));
  ui.toast = (message, kind) => toasts.push({ message, kind });
  context.window.gsRun = async (method, args) => {
    calls.push({ method, args: clone(args) });
    if (Object.hasOwn(responses, method)) return clone(responses[method]);
    switch (method) {
      case 'api_getAttendanceLogOptions':
        return { success: true, employees: [{ value: '7', label: 'Test Worker' }],
          sites: [{ value: '3', label: 'TEST-SITE' }], eventTypes: [], statuses: [], sources: [] };
      case 'api_getAttendanceLogs':
        return { success: true, rows: clone(serverRows), canManage, canDelete };
      case 'api_setAttendanceLogStatus': {
        const current = serverRows.find(item => item.id === args[0]);
        assert.ok(current, 'status request must identify the selected row');
        current.status = args[1];
        return { success: true, status: args[1] };
      }
      case 'api_saveAttendanceLog': {
        const current = serverRows.find(item => item.id === args[0].id);
        assert.ok(current, 'save request must edit the selected row');
        Object.assign(current, args[0]);
        return { success: true, id: current.id };
      }
      case 'api_deleteAttendanceLog':
        serverRows.splice(serverRows.findIndex(item => item.id === args[0]), 1);
        return { success: true };
      case 'api_restoreAttendanceLog':
        serverRows.find(item => item.id === args[0]).deleted = false;
        return { success: true };
      case 'api_getAttendanceLogHistory':
        return { success: true, edits: [{ at: '2026-09-15 08:00:00', by: 'Test Admin',
          changes: { notes: { from: '<old>', to: 'Checked & corrected' } } }] };
      default:
        throw new Error('Unexpected synthetic API call: ' + method);
    }
  };
  vm.runInContext(attendanceSource, context, { filename: 'admin-attendance.js' });
  context.window.AdminAttendance.render();
  await settle();
  assert.match(host.innerHTML, /id="at-tbl"/, 'attendance table must load');
  return {
    context, host, calls, forms, confirmations, toasts, appended,
    actions: () => controls(host.innerHTML),
    requests: method => calls.filter(call => call.method === method),
    async click(label) {
      const result = executeControl(context, controls(host.innerHTML).find(control => control.label === label));
      await settle();
      return result;
    },
  };
}

for (const helper of ['primaryButton', 'rowButton']) {
  test(helper + ' preserves quoted arguments and keeps labels and handlers inside their HTML boundaries', () => {
    const received = [];
    const context = vm.createContext({ window: { capture: (...args) => received.push(args) } });
    vm.runInContext(shellSource, context);
    const args = ['He said "ready" & O\'Brien <crew> &quot;', 'x" data-injected="yes" onclick="window.compromised()', 'First line\nSecond line\tend'];
    const handler = 'window.capture(\n' + args.map(value => JSON.stringify(value)).join(',\n') + '\n)';
    const html = context.window.AdminUI[helper]('<img src=x onerror="bad()"> & Save', handler);
    const [control] = controls(html);
    assert.equal(controls(html).length, 1);
    assert.equal(control.label, '<img src=x onerror="bad()"> & Save');
    assert.ok(!html.includes('<img'), 'label is text, not an injected element');
    const attributeNames = Array.from(control.attributes.matchAll(/([^\s=]+)="[^"]*"/g), match => match[1]);
    assert.deepEqual(attributeNames, ['type', 'onclick', 'style'], 'handler cannot add attributes');
    executeControl(context, control);
    assert.deepEqual(received, [args]);
  });
}

test('crew navigation passes raw handlers to the shared button helper without double escaping', async () => {
  const host = { innerHTML: '' };
  const navigated = [];
  const document = {
    getElementById: id => id === 'page-container' ? host : { addEventListener() {} },
    querySelector: selector => ({ click: () => navigated.push(selector) }),
  };
  const context = vm.createContext({ window: {}, document });
  vm.runInContext(shellSource, context);
  context.window.gsRun = async method => {
    assert.equal(method, 'api_getCrewSetup');
    return { success: true, companies: [], sites: [], teams: [], employees: [], canManage: false, canManageCompanies: false };
  };
  vm.runInContext(fs.readFileSync(path.join(root, 'public/js/admin-crew.js'), 'utf8'), context);
  context.AdminCrew = context.window.AdminCrew;
  context.AdminCrew.render();
  await settle();
  for (const label of ['현장 등록·수정', '직원 등록·관리', '계정·권한 관리']) {
    executeControl(context, controls(host.innerHTML).find(control => control.label === label));
  }
  assert.deepEqual(navigated, ['[data-view="site-admin"]', '[data-view="employee-admin"]', '[data-view="access-control"]']);
});

test('approval button sends the selected ID and status and reloads the table', async () => {
  const h = await harness();
  await h.click('승인');
  assert.deepEqual(h.requests('api_setAttendanceLogStatus'), [{ method: 'api_setAttendanceLogStatus', args: [41, 'approved'] }]);
  assert.equal(h.confirmations.length, 0);
  assert.equal(h.requests('api_getAttendanceLogs').length, 2);
  assert.ok(!h.actions().some(control => control.label === '승인'));
  assert.equal(h.toasts[0].message, '승인했습니다.');
});

test('rejection requires confirmation and cancel sends no mutation', async () => {
  const h = await harness();
  await h.click('반려');
  assert.match(h.confirmations[0].options.body, /Test Worker/);
  assert.equal(h.requests('api_setAttendanceLogStatus').length, 0);
  h.confirmations[0].resolve(false);
  await settle();
  assert.equal(h.requests('api_setAttendanceLogStatus').length, 0);
  await h.click('반려');
  h.confirmations[1].resolve(true);
  await settle();
  assert.deepEqual(h.requests('api_setAttendanceLogStatus')[0].args, [41, 'rejected']);
  assert.equal(h.requests('api_getAttendanceLogs').length, 2);
  assert.ok(!h.actions().some(control => control.label === '반려'));
});

test('edit opens the selected record and saves its ID and changed field', async () => {
  const h = await harness();
  await h.click('수정');
  const form = h.forms[0];
  assert.equal(form.title, '출퇴근 기록 수정');
  const values = Object.fromEntries(form.fields.map(field => [field.name, field.value]));
  assert.equal(values.employeeId, 7);
  assert.equal(values.eventAt, '2026-09-15T07:30');
  assert.equal(values.notes, 'Original note');
  values.notes = 'Corrected by test';
  const result = await form.onSave(values);
  assert.equal(result.success, true);
  assert.equal(h.requests('api_saveAttendanceLog').length, 1);
  assert.deepEqual(h.requests('api_saveAttendanceLog')[0].args, [{ ...values, id: 41 }]);
  assert.equal(h.requests('api_getAttendanceLogs').length, 2);
  assert.equal(h.context.window.AdminAttendance._state.rows[0].notes, 'Corrected by test');
});

test('delete cancels safely and confirmed deletion reloads without the removed row', async () => {
  const h = await harness();
  await h.click('삭제');
  assert.match(h.confirmations[0].options.body, /Test Worker/);
  h.confirmations[0].resolve(false);
  await settle();
  assert.equal(h.requests('api_deleteAttendanceLog').length, 0);
  assert.equal(h.requests('api_getAttendanceLogs').length, 1);
  await h.click('삭제');
  h.confirmations[1].resolve(true);
  await settle();
  assert.deepEqual(h.requests('api_deleteAttendanceLog')[0].args, [41]);
  assert.equal(h.requests('api_getAttendanceLogs').length, 2);
  assert.equal(h.context.window.AdminAttendance._state.rows.length, 0);
  assert.match(h.host.innerHTML, /이 기간에 기록이 없습니다/);
});

test('deleted rows expose restore and reload after restoration', async () => {
  const h = await harness({ rows: [row({ deleted: true, deletedAt: '2026-09-15 09:00:00' })] });
  for (const label of ['승인', '반려', '수정', '삭제']) {
    assert.ok(!h.actions().some(control => control.label === label));
  }
  await h.click('되살리기');
  assert.deepEqual(h.requests('api_restoreAttendanceLog')[0].args, [41]);
  assert.equal(h.requests('api_getAttendanceLogs').length, 2);
  assert.ok(h.actions().some(control => control.label === '수정'));
});

test('history link loads the selected record and escapes the displayed changes', async () => {
  const h = await harness();
  assert.equal(await h.click('수정 1회'), false);
  assert.deepEqual(h.requests('api_getAttendanceLogHistory')[0].args, [41]);
  assert.equal(h.appended.length, 1);
  assert.match(h.appended[0].innerHTML, /Test Admin/);
  assert.match(h.appended[0].innerHTML, /&lt;old&gt;/);
  assert.match(h.appended[0].innerHTML, /Checked &amp; corrected/);
});

test('read-only users retain history but see no mutation controls', async () => {
  const h = await harness({ canManage: false, canDelete: false });
  assert.deepEqual(h.actions().map(control => control.label), ['조회', '수정 1회']);
  assert.ok(h.calls.every(call => call.method.startsWith('api_get')));
});

test('managers without delete permission can edit but cannot delete or restore', async () => {
  const h = await harness({ canDelete: false, rows: [row(), row({ id: 42, deleted: true })] });
  for (const label of ['승인', '반려', '수정']) {
    assert.ok(h.actions().some(control => control.label === label));
  }
  for (const label of ['삭제', '되살리기']) {
    assert.ok(!h.actions().some(control => control.label === label));
  }
});

test('failed status response displays an error and does not reload or claim success', async () => {
  const h = await harness({ responses: { api_setAttendanceLogStatus: { success: false, error: 'Test permission denied' } } });
  await h.click('승인');
  assert.deepEqual(h.toasts, [{ message: 'Test permission denied', kind: 'error' }]);
  assert.equal(h.requests('api_getAttendanceLogs').length, 1);
  assert.equal(h.context.window.AdminAttendance._state.rows[0].status, 'pending');
});

test('save validation errors return to the form without a success toast or reload', async () => {
  const failure = { success: false, errors: { eventAt: 'Test invalid timestamp' } };
  const h = await harness({ responses: { api_saveAttendanceLog: failure } });
  await h.click('수정');
  const form = h.forms[0];
  const values = Object.fromEntries(form.fields.map(field => [field.name, field.value]));
  const result = await form.onSave(values);
  assert.deepEqual(result, failure);
  assert.equal(h.toasts.length, 0);
  assert.equal(h.requests('api_getAttendanceLogs').length, 1);
});

test('failed deletion keeps the row and displays the API error', async () => {
  const h = await harness({ responses: { api_deleteAttendanceLog: { success: false, error: 'Test delete denied' } } });
  await h.click('삭제');
  h.confirmations[0].resolve(true);
  await settle();
  assert.deepEqual(h.toasts, [{ message: 'Test delete denied', kind: 'error' }]);
  assert.equal(h.requests('api_getAttendanceLogs').length, 1);
  assert.equal(h.context.window.AdminAttendance._state.rows[0].id, 41);
});
