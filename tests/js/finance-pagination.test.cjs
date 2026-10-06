const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
test('finance renders metadata, pages and searches through the scoped API without fetching images', async () => {
  const source=fs.readFileSync('resources/views/smart-company/index.blade.php','utf8');
  const start=source.indexOf('      var financePage =');
  const end=source.indexOf('      // ── INVENTORY',start);
  const nodes={},calls=[],errors=[];
  const context={window:{API:{getFinanceStats:async()=>({totalSpend:100}),getExpenses:async o=>{calls.push({...o});return {items:[{id:'EXP-1',detail:'test',amount:100,receiptUrl:'/mobile-expense/1/receipt'}],page:o.page,lastPage:2,total:26};}}},pageContainer:{innerHTML:''},document:{getElementById:id=>nodes[id]??=( {addEventListener(name,fn){this[name]=fn;}}),querySelectorAll:()=>[]},_siteId:()=> 'ALL',skeleton:()=>'',fmtUSD:n=>'$'+Number(n||0),safeHtml:s=>String(s??''),renderError:e=>errors.push(e),console};
  vm.createContext(context);vm.runInContext(source.slice(start,end),context);
  await vm.runInContext('renderFinance()',context);
  assert.match(context.pageContainer.innerHTML,/영수증 보기/);assert.doesNotMatch(context.pageContainer.innerHTML,/<img/);
  assert.equal(calls[0].page,1);
  await nodes['fin-next'].onclick();await new Promise(resolve=>setImmediate(resolve));
  assert.equal(calls[1].page,2);
  nodes['fin-search'].value='bolt';nodes['fin-search'].keydown({key:'Enter'});await new Promise(resolve=>setImmediate(resolve));
  assert.equal(calls[2].page,1);assert.equal(calls[2].search,'bolt');assert.deepEqual(errors,[]);
});
