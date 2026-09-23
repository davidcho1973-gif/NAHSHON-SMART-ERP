const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
// Run with: node tests/js/claim-evidence.test.cjs
// Financial meaning belongs to the server; this checks form/API contracts and
// prevents original claims or forecasts from appearing as confirmed work in UI.
const base = path.resolve(__dirname, '../..');
const host = {innerHTML:''};
let form, modalCount=0, calls=[], messages=[], popupHtml='';
const esc = v => String(v ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const line = {id:1,lineNo:'15',description:'Drywall <script>alert(1)</script>',unit:'LF2',contractQty:2088,unitPrice:9.99,contractAmount:20859.12,status:'accepted',recognitionBasis:'quantity',stageWeights:{installed:100},sourceDocumentId:1,sourceLocator:'계약 3쪽',acceptanceNote:'서명 계약 확인',sourceClaimQty:1044,verifiedQty:15,unverifiedQty:5,forecastQty:10,availableAmount:149.85};
const records = [
 {id:10,lineId:1,recordKind:'source_claim',workDate:'2026-09-20',location:'원본',stage:'installed',reportedQty:1044,verifiedQty:null,status:'pending',evidence:[],allocatedQty:0},
 {id:11,lineId:1,recordKind:'actual',workDate:'2026-09-20',location:'주방 동측',stage:'installed',reportedQty:20,verifiedQty:null,status:'pending',evidence:[{type:'document',id:1,locator:'5쪽',url:'/documents/1'}],allocatedQty:0},
 {id:12,lineId:1,recordKind:'forecast',workDate:'2026-09-30',location:'주방 서측',stage:'installed',reportedQty:10,verifiedQty:null,status:'pending',evidence:[],allocatedQty:0},
 {id:13,lineId:1,recordKind:'actual',workDate:'2026-09-20',location:'주방 남측',stage:'installed',reportedQty:20,verifiedQty:15,status:'verified',evidence:[{type:'document',id:1,locator:'5쪽'}],allocatedQty:0,reviewHistory:[{action:'verify',by:3,at:'2026-09-21',note:'5m 보완 필요',verifiedQty:15}]}
];
const ledger={success:true,contract:{id:1,title:'Kitchen QA',currency:'USD'},canManage:true,lines:[line],records,summary:{sourceClaimAmount:10429.56,verifiedAmount:149.85,availableAmount:149.85,unverifiedCount:3},issues:[{lineId:1,recordId:10,message:'원본 확인 필요'}],sourceOptions:[{value:'document:1',label:'서명 계약서'},{value:'photo:2',label:'현장 사진'}]};
ledger.sourceImports=[{workbookDocumentId:1,drawingDocumentId:2,periodStart:'2026-09-01',periodEnd:'2026-09-30',periodBasis:'Forecast',totals:{current_claim:1000,advance:200,retention:50,current_due:1150,rounding_adjustment:-2.34},rowCount:1}];
const UI={esc,toast:(m)=>messages.push(m),pageHeader:(a,b,c)=>`<h2>${esc(a)}</h2>${esc(b)}${c}`,rowButton:(t,a)=>`<button onclick="${a}">${esc(t)}</button>`,primaryButton:(t,a)=>`<button onclick="${a}">${esc(t)}</button>`,badge:(t)=>esc(t),notice:esc,bindSearch:()=>{},table:o=>o.rows.map(r=>o.columns.map(c=>c.render?c.render(r):esc(r[c.key])).join(' | ')).join('\n')||o.emptyText,formModal:o=>{form=o;modalCount++;return Promise.resolve()},confirmDanger:()=>Promise.resolve(true)};
const popup={opener:null,document:{body:{textContent:''},open(){},write:s=>popupHtml=s,close(){}},location:{replace(){}},close(){}};
const context={console,Intl,URL,Blob,setTimeout,clearTimeout,crypto:require('node:crypto').webcrypto,location:{href:'https://erp.test/app',origin:'https://erp.test'},AdminUI:UI,_currentView:'claim-evidence-admin',document:{getElementById:()=>host,createElement:()=>({click(){},remove(){}}),body:{appendChild(){}}},open:()=>popup};
context.window=context;
context.gsRun=async(method,args)=>{calls.push({method,args});if(method==='api_getClaimEvidence')return structuredClone(ledger);if(method==='api_getClaimPacket')return {success:true,contract:ledger.contract,application:{applicationNo:2,periodStart:'2026-09-01',periodEnd:'2026-09-23',currency:'USD',thisPeriodAmount:149.85,amountDue:142.36,retainageHeld:7.49},totals:{amount:149.85,count:1},immutable:true,allocations:[{quantity:15,amount:149.85,snapshot:{line,record:records[3],evidence:[{type:'document',id:1,title:'Contract <img>',locator:'5쪽',sha256:'abc'}],reviewedBy:3,reviewedAt:'2026-09-23',capturedAt:'2026-09-23',reviewNote:'15m 확인'}}]};return {success:true,id:22}};
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
 ledger.canManage=false;await api.reload();let before=modalCount;api.editLine();api.editRecord(1);api.review(11,'verify');api.draft();api.importSource();assert.equal(modalCount,before,'read-only UI cannot open write forms');
 assert.ok(!host.innerHTML.includes("review(11,'verify')"));
 console.log('PASS claim-evidence: source totals, data distinctions, form/API guards, partial verification, snapshot provenance, stage weights, original photo URLs, decimal precision, read-only controls and navigation race.');
})().catch(e=>{console.error(e);process.exitCode=1});
