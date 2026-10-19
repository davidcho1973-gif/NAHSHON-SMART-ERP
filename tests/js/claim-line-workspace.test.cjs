const test=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync(require('node:path').join(__dirname,'../../public/js/claim-line-workspace.js'),'utf8');
function setup(){
  const el={innerHTML:'',isConnected:true};
  const window={AdminUI:{esc:s=>String(s).replaceAll('<','&lt;'),rowButton:(s,a)=>`<button data-action="${a}">${s}</button>`,notice:s=>s,badge:s=>s,table:o=>o.rows.map(r=>o.columns.map(c=>c.render?c.render(r):'').join('')).join('')},AdminDrawingEvidence:{_safeUrl:s=>s&&s.startsWith('/')?s:''},apiCache:{}};
  vm.runInNewContext(source,{window,document:{getElementById:()=>el,querySelector:()=>({content:'synthetic-csrf'})}});
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
test('upload retries a failed association without uploading the original twice and preserves scope',async()=>{
  const {w,api}=setup();let modal,uploads=0,links=0,reloads=0,fields=[];
  const response={success:true,siteId:2,projectId:5,companyId:6,drawings:[],evidence:{rows:[],summary:{linked:0},pagination:{total:0,page:1,lastPage:1}}};
  w.gsRun=async(m,args)=>{if(m==='api_getClaimLineWorkspace')return response;links++;assert.equal(args[0].contractId,3);assert.equal(args[0].lineId,4);assert.equal(args[0].documentId,9);return links===1?{success:false,error:'retry'}:{success:true,reviewOnly:true,message:'Analysis pending'};};
  w.FormData=class{append(k,v){fields.push([k,v]);}};
  w.fetch=async()=>{uploads++;return {ok:true,json:async()=>({success:true,documents:[{id:9}]})};};
  w.AdminUI.modal=opts=>{modal=opts;return {result:new Promise(()=>{})};};
  await api.mount({contractId:3,line:{id:4},records:[],canManage:true,reload:async()=>{reloads++;}});
  api.upload();
  const fileInput={files:[{name:'receipt.pdf'}],value:'receipt.pdf'},choice={checked:true},status={textContent:''};
  const el={querySelector:s=>s==='[data-files]'?fileInput:s==='[data-link]'?choice:status};
  await modal.onAction(null,el);assert.equal(uploads,1);assert.match(status.textContent,/원본 저장됨/);
  await modal.onAction(null,el);assert.equal(uploads,1);assert.equal(links,2);assert.match(status.textContent,/확인 대기 접수/);
  assert.deepEqual(fields.filter(f=>typeof f[1]==='string'),[['site_id','2'],['project_id','5'],['company_id','6']]);
  assert.equal(reloads,2);assert.equal(fileInput.disabled,false);
});
