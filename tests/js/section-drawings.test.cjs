const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'public/js/admin-section-drawings.js'), 'utf8');

function load() {
  const context = { window: {}, document: { getElementById: () => null, querySelector: () => null } };
  context.window.window = context.window;
  vm.createContext(context);
  vm.runInContext(source.replace('})(window);', '})(this.window);'), context);
  return context.window.AdminSectionDrawings;
}

// 글자가 든 쪽은 그 글자를 쓰고, 사진 쪽(또는 CAD 서브셋 글꼴이 뱉은 기호)은 AI 판독으로 보낸다.
test('a CAD page with real words keeps its own text', () => {
  const screen = load();
  const text = 'PLUMBING - KITCHEN FIRST FLOOR PLAN 3" GV THROUGH SIDEWALL. PROVIDE TURN-DOWN AND BEEHIVE SCREEN '.repeat(3);
  assert.equal(screen._meaningful(text), true);
});

test('a picture page with no text layer goes to AI reading', () => {
  const screen = load();
  assert.equal(screen._meaningful(''), false);
  assert.equal(screen._meaningful('   \n  '), false);
});

test('garbled subset-font output goes to AI reading', () => {
  const screen = load();
  const garbled = '░▒'.repeat(80) + ' A1 ';
  assert.equal(screen._meaningful(garbled), false);
});

test('a page with only a stamp and a sheet number is not treated as readable text', () => {
  const screen = load();
  assert.equal(screen._meaningful('703K-A01-01 08/21/2026'), false);
});
