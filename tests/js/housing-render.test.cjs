const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../resources/views/smart-company/index.blade.php'), 'utf8');
const start = source.indexOf('      async function renderHousing()');
const housing = source.slice(start, source.indexOf('      // ── 초기화', start));
async function render(rows, stats) {
  const host = {innerHTML:''};
  const context = vm.createContext({pageContainer:host, skeleton:()=>'', console, renderError:message=>{throw Error(message)}, window:{API:{getHousingList:async()=>rows,getHousingStats:async()=>stats}}});
  await vm.runInContext(housing+'\nrenderHousing()',context);
  return host.innerHTML;
}
test('housing renders actual backend fields and escapes stored names', async()=>{
  const html = await render([{id:'H-A',name:'<img src=x>',address:'A & B',site:'TEST',beds:4,occupied:2,monthlyRent:2500,status:'정상'}], {total:1,currentOcc:2,totalCapacity:4,occupancyRate:50,monthlyRentTotal:2500});
  assert.ok(html.includes('$2,500.00'));
  assert.ok(html.includes('입주 2명 / 정원 4명'));
  assert.ok(html.includes('&lt;img src=x&gt;'));
  assert.ok(!html.includes('<img src=x>'));
  assert.ok(!html.includes('undefined'));
  assert.ok(!html.includes('openNfcAssignModal'));
});
test('empty housing database renders an explicit empty state',async()=>{
  const html = await render([], {total:0,currentOcc:0,totalCapacity:0,occupancyRate:0,monthlyRentTotal:0});
  assert.ok(html.includes('등록된 숙소가 없습니다.'));
  assert.ok(!html.includes('NaN'));
});
