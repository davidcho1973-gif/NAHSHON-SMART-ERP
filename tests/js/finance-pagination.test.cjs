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
  context.document.getElementById('fin-search').value='bolt';context.document.getElementById('fin-from').value='2026-10-01';context.document.getElementById('fin-to').value='2026-10-31';context.document.getElementById('fin-status').value='approved';context.document.getElementById('fin-payment').value='personal';nodes['fin-query'].onsubmit({preventDefault(){}});await new Promise(resolve=>setImmediate(resolve));
  assert.equal(calls[2].page,1);assert.equal(calls[2].search,'bolt');assert.equal(calls[2].dateFrom,'2026-10-01');assert.equal(calls[2].status,'approved');assert.equal(context.window.financeExportOptions().search,'bolt');assert.equal(context.window.financeExportOptions().payment,'personal');nodes['fin-query'].input();assert.equal(nodes['btn-fin-export'].disabled,true);assert.deepEqual(errors,[]);
});
