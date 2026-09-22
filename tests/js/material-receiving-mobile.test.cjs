const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/js/material-receiving-mobile.js'), 'utf8');
const clone = value => JSON.parse(JSON.stringify(value));
const settle = () => new Promise(resolve => setImmediate(resolve));
const decode = value => value.replace(/&(amp|lt|gt|quot|#39);/g,
  (_, entity) => ({amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'"}[entity]));

// Only the DOM surface this form uses; the actual event handlers and request code run unchanged.
class Element {
  constructor(tag = 'div') {
    Object.assign(this, {tag, children: [], listeners: {}, dataset: {}, value: '', hidden: false, disabled: false, textContent: ''});
  }
  addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
  async fire(type, target = this) {
    for (const listener of this.listeners[type] || []) await listener({target, preventDefault() {}});
  }
  click() { return this.fire('click'); }
  appendChild(child) { child.parent = this; this.children.push(child); return child; }
  append(...children) { children.filter(child => typeof child !== 'string').forEach(child => this.appendChild(child)); }
  replaceChildren(...children) { this.children = []; children.forEach(child => this.appendChild(child)); }
  remove() { this.parent.children = this.parent.children.filter(child => child !== this); }
  removeAttribute(name) { delete this[name]; }
  scrollIntoView() {}
  reportValidity() { return true; }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  querySelectorAll(selector) {
    const selectors = selector.split(',').map(value => value.trim());
    const matches = node => selectors.some(value => {
      if (value === 'input') return node.tag === 'input';
      const data = value.match(/^\[data-([a-z]+)(?:="([^"]*)")?\]$/);
      return data && Object.hasOwn(node.dataset, data[1]) && (data[2] === undefined || node.dataset[data[1]] === data[2]);
    });
    return this.children.flatMap(child => [child, ...child.querySelectorAll('*')])
      .filter(node => selector === '*' || matches(node));
  }
  set innerHTML(html) {
    this.html = html;
    this.children = [];
    for (const match of html.matchAll(/<input\b([^>]*)>/g)) {
      const input = new Element('input');
      input.dataset.field = match[1].match(/data-field="([^"]*)"/)?.[1];
      input.value = decode(match[1].match(/value="([^"]*)"/)?.[1] || '');
      this.appendChild(input);
    }
    if (html.includes('data-remove')) {
      const button = new Element('button'); button.dataset.remove = '';
      this.appendChild(button);
    }
  }
  get innerHTML() { return this.html || ''; }
}

function record(overrides = {}) {
  return {
    id: 7, siteId: 3, siteCode: '703K', receivedOn: '2026-09-22', vendor: 'Graybar', status: 'draft',
    poNo: 'PO-3', deliveryNo: 'DN-7', note: 'Partial delivery', photoUrl: null, photoName: null,
    lines: [{name: 'EMT', quantity: 10, unit: 'EA', unitPrice: 2.5, itemId: 21, note: 'Two boxes damaged'}],
    ...overrides,
  };
}

async function harness({records = [], upload, save} = {}) {
  const nodes = new Map();
  const get = id => {
    if (!nodes.has(id)) { const node = new Element(); node.id = id; nodes.set(id, node); }
    return nodes.get(id);
  };
  get('receipt-site').value = '3';
  get('received-on').value = '2026-09-22';
  get('use-ai').checked = true;
  const calls = [];
  let stored = clone(records);
  const document = {
    getElementById: get,
    createElement: tag => new Element(tag),
    querySelector: () => ({content: 'csrf'}),
    querySelectorAll: selector => [...nodes.values()].flatMap(node => node.querySelectorAll(selector)),
  };
  const window = {
    materialReceivingConfig: {baseUrl: '/receiving', today: '2026-09-22', hasSites: true, translations: {}},
    crypto: {randomUUID: () => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'},
    addEventListener() {}, confirm: () => true,
  };
  const fetch = async (url, options = {}) => {
    const payload = typeof options.body === 'string' ? JSON.parse(options.body) : options.body;
    calls.push({url, payload, method: options.method});
    let data;
    if (url.includes('/items?')) data = {success: true, items: clone(stored)};
    else if (url.endsWith('/upload')) data = upload ? await upload(payload) : {success: true, file: {token: 'proof', name: 'delivery.jpg'}};
    else if (url.endsWith('/confirm')) data = {success: true};
    else if (save) data = await save(payload, value => { stored = clone(value); });
    else {
      stored = [record({lines: payload.lines})];
      data = {success: true, id: 7, status: 'draft'};
    }
    return {ok: data.success !== false, json: async () => data};
  };
  class FormData { append(key, value) { this[key] = value; } }
  vm.runInNewContext(source, {window, document, fetch, FormData, URL: {createObjectURL: () => 'blob:test', revokeObjectURL() {}}});
  await settle();
  const line = () => get('receipt-lines').children[0];
  const field = name => line().querySelectorAll('[data-field]').find(input => input.dataset.field === name);
  const input = async (id, value) => { get(id).value = value; await get('receipt-form').fire('input', get(id)); };
  const chooseFile = async () => {
    get('proof-file').files = [{name: 'delivery.jpg', size: 400, type: 'image/jpeg'}];
    await get('proof-file').fire('change');
  };
  return {get, calls, field, input, chooseFile};
}

test('response-loss retry reviews the stored quantities before allowing confirmation', async () => {
  let attempts = 0;
  const ui = await harness({save: (payload, store) => {
    if (++attempts === 1) { store([record()]); throw Error('response lost'); }
    return {success: true, id: 7, status: 'draft', replayed: true};
  }});
  ui.field('name').value = 'EMT'; ui.field('quantity').value = '10';
  await ui.get('receipt-form').fire('submit');
  assert.equal(ui.get('saved-actions').children.length, 0);
  ui.field('quantity').value = '99';
  await ui.get('receipt-form').fire('submit');
  const requests = ui.calls.filter(call => call.url === '/receiving');
  assert.equal(requests.length, 2);
  assert.equal(requests[0].payload.request_key, requests[1].payload.request_key);
  assert.equal(ui.field('quantity').value, '10', 'retry must show what the server actually saved');
  assert.equal(ui.get('saved-actions').children.length, 1);
  await ui.get('saved-actions').children[0].click();
  assert.equal(ui.calls.at(-2).url, '/receiving/7/confirm');
});

test('editing quantities preserves item links and per-line delivery notes', async () => {
  const ui = await harness({records: [record()]});
  const edit = ui.get('receipt-history').querySelector('[data-edit]');
  assert.ok(edit);
  await edit.click();
  ui.field('quantity').value = '8';
  await ui.get('receipt-form').fire('submit');
  const payload = ui.calls.find(call => call.url === '/receiving').payload;
  assert.equal(payload.id, 7);
  assert.equal(payload.lines[0].quantity, 8);
  assert.equal(payload.lines[0].item_id, 21);
  assert.equal(payload.lines[0].note, 'Two boxes damaged');
  assert.equal(payload.note, 'Partial delivery');
});

test('AI attachments preserve manually entered metadata and quantities', async () => {
  const ui = await harness({upload: () => ({success: true, file: {token: 'proof', name: 'delivery.jpg'}, data: {
    vendor: 'AI vendor', received_on: '2026-09-01', po_no: 'AI-PO', delivery_no: 'AI-DN',
    lines: [{name: 'AI item', quantity: 999, unit: 'BOX'}],
  }})});
  await ui.input('vendor', 'Checked vendor');
  await ui.input('received-on', '2026-09-20');
  await ui.input('po-no', 'Checked PO');
  await ui.input('delivery-no', 'Checked DN');
  ui.field('name').value = 'Counted EMT'; ui.field('quantity').value = '8';
  await ui.chooseFile();
  assert.equal(ui.get('vendor').value, 'Checked vendor');
  assert.equal(ui.get('received-on').value, '2026-09-20');
  assert.equal(ui.get('po-no').value, 'Checked PO');
  assert.equal(ui.get('delivery-no').value, 'Checked DN');
  assert.equal(ui.field('name').value, 'Counted EMT');
  assert.equal(ui.field('quantity').value, '8');
});

test('failed attachment can be removed to save the retained manual entry', async () => {
  const ui = await harness({upload: () => ({success: false, error: 'unsupported file'})});
  ui.field('name').value = 'Counted EMT'; ui.field('quantity').value = '8';
  await ui.chooseFile();
  assert.equal(ui.get('save-receipt').disabled, true);
  assert.equal(ui.get('retry-upload').hidden, false);
  await ui.get('remove-upload').click();
  assert.equal(ui.get('save-receipt').disabled, false);
  assert.equal(ui.get('retry-upload').hidden, true);
  await ui.get('receipt-form').fire('submit');
  const payload = ui.calls.find(call => call.url === '/receiving').payload;
  assert.equal(Object.hasOwn(payload, 'photo'), false);
  assert.equal(payload.lines[0].name, 'Counted EMT');
  assert.equal(payload.lines[0].quantity, 8);
});
