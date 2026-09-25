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

// 도면 글자에서 축척 — 건축·토목·미터. 1인치 = 72포인트.
test('scales are read from the drawing text', () => {
  const m = load();
  const s = m._parseScales('FLOOR PLAN SCALE: 1/4" = 1\'-0" ENLARGED 1 1/2" = 1\'-0" SITE 1" = 20\' SCALE 1:100');
  const byLabel = Object.fromEntries(Array.from(s).map((x) => [x.label, x.feetPerPoint]));
  assert.ok(Math.abs(byLabel['1/4" = 1\'-0"'] - 4 / 72) < 1e-9);
  assert.ok(Math.abs(byLabel['1 1/2" = 1\'-0"'] - 1 / (1.5 * 72)) < 1e-9);
  assert.ok(Math.abs(byLabel['1" = 20\''] - 20 / 72) < 1e-9);
  assert.ok(byLabel['1:100'] > 0);
  assert.equal(m._parseScales('1:30 PM meeting').length, 0, '시각은 축척이 아니다');
});

// 그은 선·영역 → 줄의 단위. 벽(면적 단위를 선으로)은 높이를 곱한다.
test('measured shapes become quantities in the line unit', () => {
  const m = load();
  const fpp = 4 / 72;   // 1/4" = 1'-0"
  const line = m._measure([[0, 0], [0.5, 0]], 'line', 720, 720, fpp);   // 360pt = 5in = 20ft
  assert.ok(Math.abs(line.lengthFt - 20) < 1e-9);
  assert.ok(Math.abs(m._qtyFor('LF', 'line', line) - 20) < 1e-9);
  assert.ok(Math.abs(m._qtyFor('M', 'line', line) - 6.096) < 1e-9);
  assert.ok(Math.abs(m._qtyFor('LF2', 'line', line, 10) - 200) < 1e-9, '벽 20ft × 높이 10ft');
  assert.equal(m._qtyFor('LF2', 'line', line, null), null, '높이를 모르면 면적을 만들지 않는다');
  const box = m._measure([[0, 0], [0.5, 0], [0.5, 0.25], [0, 0.25]], 'area', 720, 720, fpp);   // 20ft × 10ft
  assert.ok(Math.abs(box.areaSqft - 200) < 1e-9);
  assert.equal(m._qtyFor('EA', 'area', box), null, '개수 단위는 재지 않는다');
  assert.equal(m._fmtFeet(12.5), "12'-6\"");
  assert.equal(m._parseLength("12'-6\""), 12.5);
  assert.ok(Math.abs(m._parseLength('3.048m') - 10) < 1e-9);
});

test('nearly straight segments snap to horizontal or vertical', () => {
  const m = load();
  assert.deepEqual(Array.from(m._snapPoint([0.1, 0.1], [0.5, 0.11], 1000, 1000)), [0.5, 0.1]);
  assert.deepEqual(Array.from(m._snapPoint([0.1, 0.1], [0.105, 0.5], 1000, 1000)), [0.1, 0.5]);
  assert.deepEqual(Array.from(m._snapPoint([0.1, 0.1], [0.4, 0.4], 1000, 1000)), [0.4, 0.4]);
});

// 1차·2차 기성은 서로 다른 색, 청구 전 확인분은 «다음 기성 대상».
test('billing rounds get their own colours', () => {
  const m = load();
  const r1 = m._keyOf({ status: 'done', rounds: [{ no: 1 }] }, 'round');
  const r2 = m._keyOf({ status: 'done', rounds: [{ no: 2 }] }, 'round');
  assert.equal(r1, 'r1');
  assert.notEqual(m._styleOf(r1).color, m._styleOf(r2).color);
  assert.equal(m._styleOf(r2).label, '2차 기성');
  assert.equal(m._keyOf({ status: 'done', rounds: [] }, 'round'), 'next');
  assert.equal(m._keyOf({ status: 'pending', rounds: [] }, 'round'), 'pending');
  assert.equal(m._keyOf({ status: 'done', rounds: [{ no: 1 }] }, 'status'), 'done');
});
