const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
// Run with: node tests/js/claim-evidence.test.cjs
// Financial meaning belongs to the server; this checks form/API contracts and
// prevents original claims or forecasts from appearing as confirmed work in UI.
const base = path.resolve(__dirname, '../..');
const host = {innerHTML:''};
let form, modalCount=0, calls=[], messages=[], popupHtml='', dialog, dialogResult;
const esc = v => String(v ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const line = {id:1,lineNo:'15',description:'Drywall <script>alert(1)</script>',unit:'LF2',contractQty:2088,unitPrice:9.99,contractAmount:20859.12,status:'accepted',recognitionBasis:'quantity',stageWeights:{installed:100},sourceDocumentId:1,sourceLocator:'계약 3쪽',acceptanceNote:'서명 계약 확인',sourceClaimQty:1044,verifiedQty:15,unverifiedQty:5,forecastQty:10,availableAmount:149.85};
const records = [
 {id:10,lineId:1,recordKind:'source_claim',workDate:'2026-09-20',location:'원본',stage:'installed',reportedQty:1044,verifiedQty:null,status:'pending',evidence:[],allocatedQty:0},
 {id:11,lineId:1,recordKind:'actual',workDate:'2026-09-20',location:'주방 동측',stage:'installed',reportedQty:20,verifiedQty:null,status:'pending',evidence:[{type:'document',id:1,locator:'5쪽',url:'/documents/1'}],allocatedQty:0},
 {id:12,lineId:1,recordKind:'forecast',workDate:'2026-09-30',location:'주방 서측',stage:'installed',reportedQty:10,verifiedQty:null,status:'pending',evidence:[],allocatedQty:0},
 {id:13,lineId:1,recordKind:'actual',workDate:'2026-09-20',location:'주방 남측',stage:'installed',reportedQty:20,verifiedQty:15,status:'verified',evidence:[{type:'document',id:1,locator:'5쪽'}],allocatedQty:0,reviewHistory:[{action:'verify',by:3,at:'2026-09-21',note:'5m 보완 필요',verifiedQty:15}]}
];
const ledger={success:true,contract:{id:1,title:'Kitchen QA',currency:'USD'},canManage:true,lines:[line],records,summary:{sourceClaimAmount:10429.56,verifiedAmount:149.85,availableAmount:149.85,unverifiedCount:3},issues:[{lineId:1,recordId:10,message:'원본 확인 필요'}],sourceOptions:[{value:'document:1',label:'서명 계약서'},{value:'photo:2',label:'현장 사진'}]};
const inbox={success:true,rows:[{id:21,sourceType:'expense',sourceId:55,title:'Stud receipt <script>',date:'2026-10-12',status:'review',lineIds:[1],lineLabels:['15 · Drywall <script>'],reason:'규격 확인 <img>',url:'https://evil.test/receipt'},{id:22,sourceType:'document',sourceId:17,title:'Delivery note',date:'2026-10-12',status:'linked',lineIds:[1,2],lineLabels:['15 · Drywall','16 · Ceiling'],url:'/documents/17'}],summary:{linked:1,review:1,common:0},lines:[line,{id:2,lineNo:'16',description:'Ceiling'}],pagination:{currentPage:1,lastPage:3,total:52},processing:true};
ledger.sourceImports=[{workbookDocumentId:1,drawingDocumentId:2,periodStart:'2026-09-01',periodEnd:'2026-09-30',periodBasis:'Forecast',totals:{current_claim:1000,advance:200,retention:50,current_due:1150,rounding_adjustment:-2.34},rowCount:1}];
const UI={esc,toast:(m)=>messages.push(m),pageHeader:(a,b,c)=>`<h2>${esc(a)}</h2>${esc(b)}${c}`,rowButton:(t,a)=>`<button onclick="${a}">${esc(t)}</button>`,primaryButton:(t,a)=>`<button onclick="${a}">${esc(t)}</button>`,badge:(t)=>esc(t),notice:esc,bindSearch:()=>{},table:o=>o.rows.map(r=>o.columns.map(c=>c.render?c.render(r):esc(r[c.key])).join(' | ')).join('\n')||o.emptyText,formModal:o=>{form=o;modalCount++;return Promise.resolve()},confirmDanger:()=>Promise.resolve(true)};
UI.modal=o=>{dialog=o;modalCount++;let resolve;const result=new Promise(r=>{resolve=r});dialogResult={result,close:resolve};return dialogResult};
const popup={opener:null,document:{body:{textContent:''},open(){},write:s=>popupHtml=s,close(){}},location:{replace(){}},close(){}};
const context={console,Intl,URL,Blob,setTimeout,clearTimeout,crypto:require('node:crypto').webcrypto,location:{href:'https://erp.test/app',origin:'https://erp.test'},AdminUI:UI,_currentView:'claim-evidence-admin',document:{getElementById:()=>host,createElement:()=>({click(){},remove(){}}),body:{appendChild(){}}},open:()=>popup};
context.window=context;
context.gsRun=async(method,args)=>{calls.push({method,args});if(method==='api_getClaimEvidence')return structuredClone(ledger);if(method==='api_getClaimEvidenceInbox')return structuredClone(inbox);if(method==='api_getClaimPacket')return {success:true,contract:ledger.contract,application:{applicationNo:2,periodStart:'2026-09-01',periodEnd:'2026-09-23',currency:'USD',thisPeriodAmount:149.85,amountDue:142.36,retainageHeld:7.49},totals:{amount:149.85,count:1},immutable:true,allocations:[{quantity:15,amount:149.85,snapshot:{line,record:records[3],evidence:[{type:'document',id:1,title:'Contract <img>',locator:'5쪽',sha256:'abc'}],reviewedBy:3,reviewedAt:'2026-09-23',capturedAt:'2026-09-23',reviewNote:'15m 확인'}}]};return {success:true,id:22}};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(base,'public/js/admin-claim-evidence.js'),'utf8'),context);
const api=context.AdminClaimEvidence;
const values=f=>Object.fromEntries(f.fields.map(x=>[x.name,x.value]));
(async()=>{
 api._state.contractId=1;
 await api.reload();
 assert.match(host.innerHTML,/원문 항목 계산합 \(행별 반올림\)/);
 assert.match(host.innerHTML,/Forecast · 예상 포함/);
 assert.match(host.innerHTML,/1,150.00/,'source cash claim is shown separately, never recomputed from line values');
 assert.match(host.innerHTML,/원본 주장/);
 assert.match(host.innerHTML,/&lt;script&gt;/);
 api.openLine(1);
 assert.match(host.innerHTML,/원본 청구 · 미확인/);
 assert.match(host.innerHTML,/검토 이력 1건/);
 assert.ok(!host.innerHTML.includes("review(10,'verify')"),'source claim cannot be confirmed in UI');
 assert.ok(!host.innerHTML.includes("review(12,'verify')"),'forecast cannot be confirmed in UI');
 api.editLine();let v=values(form);Object.assign(v,{lineNo:'16',description:'New line',unit:'M',contractQty:'100',unitPrice:'10',status:'accepted',acceptanceNote:'signed',sourceDocumentId:'',sourceLocator:''});
 let result=await form.onSave(v);assert.equal(result.success,false);assert.ok(result.errors.sourceDocumentId);assert.ok(result.errors.sourceLocator);
 api.editLine();v=values(form);Object.assign(v,{lineNo:'16',description:'New line',unit:'M',contractQty:'100',unitPrice:'10',status:'accepted',acceptanceNote:'signed',sourceDocumentId:'1',sourceLocator:'3쪽'});await form.onSave(v);
 let saved=calls.findLast(c=>c.method==='api_saveClaimLine').args[0];assert.ok(!('id' in saved));assert.equal(saved.projectContractId,1);assert.equal(saved.status,'accepted');
 api.editRecord(1);let recordForm=form;v=values(form);Object.assign(v,{workDate:'2999-01-01',location:'Kitchen A',reportedQty:'20'});result=await recordForm.onSave(v);assert.equal(result.success,false);assert.ok(result.errors.workDate);
 v=values(recordForm);Object.assign(v,{workDate:'2026-09-20',location:'Kitchen A',reportedQty:'20',evidenceSource0:'document:1',evidenceLocator0:''});result=await recordForm.onSave(v);assert.equal(result.success,false);assert.ok(result.errors.evidenceLocator0);
 v=values(recordForm);Object.assign(v,{workDate:'2026-09-20',location:'Kitchen A',reportedQty:'20',evidenceSource0:'document:1',evidenceLocator0:'5쪽'});await recordForm.onSave(v);saved=calls.findLast(c=>c.method==='api_saveClaimRecord').args[0];assert.ok(!('id' in saved));assert.equal(saved.evidence[0].id,1);assert.equal(saved.evidence[0].locator,'5쪽');assert.match(saved.sourceRef,/^field-ui:/);
 api.editRecord(1,12);assert.equal(form.fields.find(f=>f.name==='recordKind').options.length,1,'saved forecast cannot be relabeled actual');
 api.review(11,'verify');v=values(form);Object.assign(v,{verifiedQty:'15',reviewNote:'15m confirmed, 5m missing inspection'});await form.onSave(v);saved=calls.findLast(c=>c.method==='api_reviewClaimRecord').args[0];assert.equal(saved.verifiedQty,'15');assert.equal(saved.action,'verify');
 await api.packet(2);assert.match(popupHtml,/20 LF2 → 15 LF2/);assert.match(popupHtml,/사용자 #3/);assert.match(popupHtml,/Contract &lt;img&gt;/);assert.match(popupHtml,/인쇄 \/ PDF 저장/);
 line.recognitionBasis='milestone';line.stageWeights={fabrication:80,installation:20};line.unitPrice=4.4444;records[3].stage='fabrication';
 const oldCall=context.gsRun;
 context.gsRun=async(method,args)=>{
   const response=await oldCall(method,args);
   if(method==='api_getClaimPacket'){
     response.allocations[0].snapshot.contractEvidence=[{type:'document',id:1,title:'Signed terms',locator:'계약 12쪽 / Rev.2',verifiedFileSha256:'contract-file-digest',fileCheckAt:'2026-09-23'}];
     response.allocations[0].snapshot.evidence.push({type:'photo',id:2,title:'Factory photo',locator:'Panel A',url:'/wbs-api/photos/2/file',originalFilePath:'original.jpg',originalSha256:'photo-original-digest'});
   }
   return response;
 };
 await api.packet(2);
 assert.match(popupHtml,/제작 80% · 설치 20%/);
 assert.match(popupHtml,/4\.4444/,'contract unit price precision is preserved');
 assert.match(popupHtml,/contract-file-digest/,'frozen contract document proof is included');
 assert.match(popupHtml,/\/wbs-api\/photos\/2\/file\?original=1/,'original photo uses protected endpoint');
 context._currentView='billing-admin';host.innerHTML='Another view';await api.reload();assert.equal(host.innerHTML,'Another view','late ledger response cannot replace another screen');context._currentView='claim-evidence-admin';
 await api.openInbox();
 assert.match(host.innerHTML,/매일 증빙 — Kitchen QA/);assert.match(host.innerHTML,/확인 필요 1/);assert.match(host.innerHTML,/분석 대기 \/ 진행 중/);
 const freshCall=context.gsRun;const inboxCacheKey='api_getClaimEvidenceInbox'+JSON.stringify([1,{page:1,status:'review'}]);const unrelatedCache={data:{kept:true},timestamp:Date.now()};let freshInboxReads=0;
 context.apiCache={[inboxCacheKey]:{data:structuredClone(inbox),timestamp:Date.now()},'api_getBillingContracts[]':unrelatedCache};
 context.gsRun=async(method,args)=>{const cached=context.apiCache[method+JSON.stringify(args)];if(cached)return cached.data;if(method==='api_getClaimEvidenceInbox')freshInboxReads++;return freshCall(method,args)};
 inbox.processing=false;await api.reloadInbox();assert.equal(freshInboxReads,1,'manual refresh bypasses a still-valid queued-state cache');assert.equal(api._state.inbox.processing,false);assert.equal(context.apiCache['api_getBillingContracts[]'],unrelatedCache,'refresh preserves unrelated module cache');context.gsRun=freshCall;inbox.processing=true;
 assert.match(host.innerHTML,/자동 연결 시작/);inbox.automationEnabled=true;await api.reloadInbox();assert.match(host.innerHTML,/새 자료 찾기/);assert.match(host.innerHTML,/자동 연결 · 10분마다/);
 inbox.processing=false;inbox.processingMessage='자동 연결 실패 <script>';await api.reloadInbox();assert.match(host.innerHTML,/자동 연결 실패 &lt;script&gt;/,'failed background job remains visible when not running');inbox.processing=true;inbox.processingMessage='';
 assert.match(host.innerHTML,/Stud receipt &lt;script&gt;/);assert.ok(!host.innerHTML.includes('<img'),'no evidence bytes or thumbnails are loaded in list');
 assert.ok(!calls.some(c=>c.method==='api_getSourceDocument'),'opening daily inbox does not fetch originals');
 api.inboxDetail(21);assert.ok(!dialog.body.includes('https://evil.test/receipt'),'cross-origin evidence URL is never made clickable');assert.match(dialog.body,/규격 확인 &lt;img&gt;/);dialogResult.close(null);
 api.inboxDetail(22);assert.match(dialog.body,/https:\/\/erp.test\/documents\/17/);dialogResult.close(null);
 await api.filterInbox('common');assert.deepEqual(JSON.parse(JSON.stringify(calls.findLast(c=>c.method==='api_getClaimEvidenceInbox').args)),[1,{page:1,status:'common'}]);
 await api.pageInbox(2);assert.equal(calls.findLast(c=>c.method==='api_getClaimEvidenceInbox').args[1].page,2);
 await api.openInbox(1);assert.deepEqual(JSON.parse(JSON.stringify(calls.findLast(c=>c.method==='api_getClaimEvidenceInbox').args)),[1,{page:1,status:'all',lineId:1}]);assert.match(host.innerHTML,/전체 공정 증빙/);
 await api.filterInbox('linked');assert.equal(calls.findLast(c=>c.method==='api_getClaimEvidenceInbox').args[1].lineId,1,'status filters retain selected contract line');await api.openInbox();
 api.editInbox(22);assert.match(dialog.body,/value="1" checked/);assert.match(dialog.body,/value="2" checked/);assert.match(dialog.body,/Drywall &lt;script&gt;/);
 inbox.lines=[{id:1,lineNo:'15',description:'Metal stud',spec:'6in / 16ga <img>',unit:'LF'}, {id:2,lineNo:'16',description:'Metal stud',spec:'3-5/8in / 18ga',unit:'EA'}];await api.reloadInbox();api.editInbox(22);
 assert.match(dialog.body,/6in \/ 16ga &lt;img&gt; · 단위 LF/);assert.match(dialog.body,/3-5\/8in \/ 18ga · 단위 EA/,'same-name contract lines show distinct specification and unit');
 let searchChanged;const selector={value:'link',addEventListener(){}},searchInput={addEventListener:(event,listener)=>{searchChanged=listener}},lineSection={hidden:false};
 const lineLabels=[{textContent:'15 · Metal stud 6in / 16ga <img> · 단위 LF',style:{}},{textContent:'16 · Metal stud 3-5/8in / 18ga · 단위 EA',style:{}}];
 dialog.onReady({querySelector:s=>s==='#ce-review-action'?selector:s==='[data-ce-line-section]'?lineSection:searchInput,querySelectorAll:()=>lineLabels});
 searchChanged({target:{value:'18ga'}});assert.equal(lineLabels[0].hidden,true);assert.equal(lineLabels[1].hidden,false,'specification is included in actual checkbox search');
 searchChanged({target:{value:'단위 LF'}});assert.equal(lineLabels[0].hidden,false);assert.equal(lineLabels[1].hidden,true);
 const actionControl={value:'link'},errorControl={textContent:''};let selectedLines=[];
 const reviewElement={querySelector:s=>s==='#ce-review-action'?actionControl:errorControl,querySelectorAll:()=>selectedLines};
 let beforeWrites=calls.filter(c=>c.method==='api_reviewClaimEvidence').length;
 await dialog.onAction({},reviewElement);assert.match(errorControl.textContent,/공정을 선택/);assert.equal(calls.filter(c=>c.method==='api_reviewClaimEvidence').length,beforeWrites);
 selectedLines=[{value:'1'},{value:'2'}];await dialog.onAction({},reviewElement);
 assert.deepEqual(JSON.parse(JSON.stringify(calls.findLast(c=>c.method==='api_reviewClaimEvidence').args[0])),{id:22,action:'link',lineIds:[1,2]});
 api.editInbox(22);actionControl.value='common';await dialog.onAction({},reviewElement);
 assert.deepEqual(JSON.parse(JSON.stringify(calls.findLast(c=>c.method==='api_reviewClaimEvidence').args[0])),{id:22,action:'common',lineIds:[]},'common costs retain source but do not claim item amounts');
 await api.matchInbox();assert.equal(calls.findLast(c=>c.method==='api_matchClaimEvidence').args[0],1);assert.equal(api._state.matching,false);
 const instantCall=context.gsRun;let releaseInbox;
 context.gsRun=(method,args)=>method==='api_getClaimEvidenceInbox'?new Promise(resolve=>{releaseInbox=resolve}):instantCall(method,args);
 const inFlight=api.reloadInbox();api.showLedger();const ledgerHtml=host.innerHTML;releaseInbox(structuredClone(inbox));await inFlight;assert.equal(host.innerHTML,ledgerHtml,'late inbox response cannot replace ledger after tab change');context.gsRun=instantCall;
 ledger.canManage=false;await api.reload();let before=modalCount;api.editLine();api.editRecord(1);api.review(11,'verify');api.draft();api.importSource();api.editInbox(22);api.uploadSources();await api.matchInbox();assert.equal(modalCount,before,'read-only UI cannot open write forms');
 assert.ok(!host.innerHTML.includes("review(11,'verify')"));
 await api.openInbox();assert.ok(!host.innerHTML.includes('matchInbox()'),'read-only inbox offers no reclassification or matching actions');
 console.log('PASS claim-evidence: source totals, form/API guards, snapshot provenance, daily evidence pagination, secure lazy source links, multi-line review, common costs, read-only controls and navigation races.');
})().catch(e=>{console.error(e);process.exitCode=1});
