const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/js/drawing-marker.js'), 'utf8');

function load() {
  const context = { window: {}, document: {} };
  context.window.window = context.window;
  vm.createContext(context);
  vm.runInContext(source.replace('})(window);', '})(this.window);'), context);
  return context.window.DrawingMarker;
}

// 평면도에서 벽·배관·전선은 선으로 보인다 — 단위가 면적(LF2)이어도.
test('walls and runs default to a line even when the unit is an area', () => {
  const m = load();
  assert.equal(m._shapeForUnit('LF2', 'Dry Wall(Water resistant) one side'), 'line');
  assert.equal(m._shapeForUnit('LF', 'ELECTRICAL METALLIC TUBING (EMT)'), 'line');
  assert.equal(m._shapeForUnit('LF2', 'Epoxy Coating'), 'area');
  assert.equal(m._shapeForUnit('EA', 'SQUARE DIFFUSER'), 'point');
});

// 위치 글자로 도면에서 찾을 말 — 전체 문장 먼저, 그다음 긴 단어. 흔한 말은 뺀다.
test('location text becomes drawing search terms, longest first', () => {
  const m = load();
  assert.deepEqual(Array.from(m._searchTerms('Pantry north wall')), ['PANTRY NORTH WALL', 'PANTRY', 'NORTH']);
  assert.deepEqual(Array.from(m._searchTerms('')), []);
});

test('every status has its own colour', () => {
  const colors = Object.values(load()._statusColors).map((s) => s.color);
  assert.equal(new Set(colors).size, colors.length);
});
