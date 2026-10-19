const test=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync(require('node:path').join(__dirname,'../../public/js/claim-line-workspace.js'),'utf8');
function setup(){
  const el={innerHTML:'',isConnected:true};
  const window={AdminUI:{esc:s=>String(s).replaceAll('<','&lt;'),rowButton:(s,a)=>`<button data-action="${a}">${s}</button>`,notice:s=>s,badge:s=>s,table:o=>o.rows.map(r=>o.columns.map(c=>c.render?c.render(r):'').join('')).join('')},AdminDrawingEvidence:{_safeUrl:s=>s&&s.startsWith('/')?s:''},apiCache:{}};
  vm.runInNewContext(source,{window,document:{getElementById:()=>el}});
  return {w:window,el,api:window.AdminClaimLineWorkspace};
}
test('missing evidence uses actual records, never purchases or forecasts as completion',()=>{
  const {api}=setup();
  let c={line:{id:1},records:[{lineId:1,recordKind:'forecast',evidence:[{id:9}]}]};
  assert.equal(api._gaps(c,{drawings:[],evidence:{summary:{linked:0}}}).length,3);
  c.records=[{lineId:1,recordKind:'actual',status:'pending',evidence:[]}];
  const gaps=api._gaps(c,{drawings:[{}],evidence:{summary:{linked:1}}});
  assert.equal(gaps.length,1);assert.match(gaps[0],/작업 기록의 근거/);
});
test('line navigation ignores stale responses and keeps scoped pagination',async()=>{
  const {w,el,api}=setup();let requests=[];
  w.gsRun=(m,a)=>new Promise(resolve=>requests.push({m,a,resolve}));
  const first=api.mount({contractId:2,line:{id:3},records:[],canManage:true});
  const second=api.mount({contractId:2,line:{id:4},records:[],canManage:true});
  const payload=title=>({success:true,drawings:[],evidence:{rows:[{id:8,title,sourceType:'document',status:'review'}],summary:{linked:0},pagination:{total:26,page:1,lastPage:2}}});
  requests[1].resolve(payload('<new>'));await second;
  requests[0].resolve(payload('old'));await first;
  assert.ok(el.innerHTML.includes('&lt;new>'));assert.ok(!el.innerHTML.includes('old'));
  assert.ok(el.innerHTML.includes('문서 정보 수정 / 검토'));assert.ok(el.innerHTML.includes('page(2)'));
  const next=api.page(2);assert.deepEqual(Array.from(requests[2].a),[2,4,2]);requests[2].resolve(payload('next'));await next;
});
