const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

const host = {innerHTML:''};
const calls = [];
let form;
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const application = {id:5,applicationNo:2,status:'draft',periodStart:'2026-09-01',periodEnd:'2026-09-30',amountDue:1000,currency:'USD'};
const workspace = {success:true,application,profile:{confirmed_at:'2026-09-01'},canManage:true,
  requirements:[{key:'photos',title:'Photos <script>',active:true,required:true,missing:false,evidence:[{type:'document',id:9}]}],
  draftEvidence:{photos:[{type:'document',id:9}]},
  sourceOptions:[{type:'document',id:9,value:'document:9',label:'Evidence <img>'}],
  submissions:[{id:12,revision:1,status:'prepared',downloadUrl:'/billing-export/submissions/12/packet.zip',history:[]}]};
const UI = {
  esc,toast:()=>{},pageHeader:(a,b,c)=>esc(a)+esc(b)+(c||''),badge:esc,
  rowButton:(t,a)=>'<button onclick="'+a+'">'+esc(t)+'</button>',
  primaryButton:(t,a)=>'<button onclick="'+a+'">'+esc(t)+'</button>',
  table:o=>o.rows.map(r=>o.columns.map(c=>c.render?c.render(r):esc(r[c.key])).join(' | ')).join('\n') || o.emptyText,
  bindSearch:()=>{},formModal:o=>{form=o;},confirmDanger:()=>Promise.resolve(true)
};
const context = {console,AdminUI:UI,apiCache:{},document:{
  getElementById:()=>host,
  querySelectorAll:()=>[{getAttribute:()=> 'photos',selectedOptions:[{value:'document:9'}]}]
}};
context.window=context;
context.gsRun=async(method,args)=>{
  calls.push({method,args});
  if(method==='api_getClaimSubmissionWorkspace') return structuredClone(workspace);
  if(method==='api_getBillings') return {success:true,contract:{id:2,currency:'USD',title:'Contract'},rows:[application],canManage:true};
  if(method==='api_getClaimWorklist') return {success:true,canManage:true,rows:[
    {id:2,title:'USD project',currency:'USD',outstanding:10},
    {id:3,title:'KRW project',currency:'KRW',outstanding:1000}
  ]};
  return {success:true};
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname,'../../public/js/admin-billing.js'),'utf8'),context);
(async()=>{
  const api=context.AdminBilling;
  api._state.contractId=2;
  api._state.detail={contract:{id:2},rows:[application]};
  api._state.canManage=true;
  await api.submitApp(5);
  assert.equal(calls[0].method,'api_getClaimSubmissionWorkspace','old submit action opens evidence workflow, never silently submits');
  assert.ok(!calls.some(c=>c.method==='api_setBillingStatus'));
  assert.match(host.innerHTML,/&lt;script&gt;/);
  assert.match(host.innerHTML,/&lt;img&gt;/);
  assert.match(host.innerHTML,/송부 처리되지 않습니다/);
  assert.match(host.innerHTML,/packet.zip/);
  await api.prepareSubmission();
  assert.ok(calls.some(c=>c.method==='api_saveClaimSubmissionEvidence'));
  assert.ok(calls.some(c=>c.method==='api_prepareClaimSubmission'));
  assert.ok(!calls.some(c=>c.method==='api_transitionClaimSubmission'),'preparing a ZIP is not sending');
  api.submissionTransition(12,'accepted');
  assert.ok(form.fields.some(f=>f.name==='response' && f.required),'acceptance needs original response');
  await form.onSave({occurred_at:'2026-10-01',reference:'GC response',response:'document:9',note:''});
  const sent=calls.find(c=>c.method==='api_transitionClaimSubmission').args[0];
  assert.equal(sent.status,'accepted');
  assert.equal(sent.response_document.id,9);
  assert.ok(!calls.some(c=>c.method==='api_setBillingStatus'),'document acceptance is distinct from monetary approval');
  const originalCall=context.gsRun;
  context.gsRun=async(method,args)=>method==='api_transitionClaimSubmission' ? {success:false,errors:{thisPeriodAmount:'No claimable amount'}} : originalCall(method,args);
  api.submissionTransition(12,'sent');
  const invalid=await form.onSave({occurred_at:'2026-10-01',reference:'Dispatch'});
  assert.equal(invalid.error,'No claimable amount','billing field errors are visible in the response form');
  context.gsRun=originalCall;
  workspace.submissions[0].status='sent';
  await api.openSubmission(5);
  assert.ok(!host.innerHTML.includes('prepareSubmission()'),'sent evidence is read-only until a documented change request');
  workspace.submissions[0].status='prepared';
  workspace.sourceOptions=[];
  workspace.requirements[0].evidence=[{type:'document',id:9,label:'Archived linked original'}];
  await api.openSubmission(5);
  assert.match(host.innerHTML,/Archived linked original/,'linked originals remain selected even outside recent search window');
  workspace.canManage=false;
  await api.openSubmission(5);
  assert.ok(!host.innerHTML.includes('prepareSubmission()'),'viewers cannot prepare submissions');
  api.render();
  await new Promise(resolve=>setImmediate(resolve));
  assert.match(host.innerHTML,/\$10/);
  assert.match(host.innerHTML,/₩1,000/);
  assert.ok(!host.innerHTML.includes('$1,010'),'different currencies are never summed as USD');
})().catch(e=>{console.error(e);process.exitCode=1;});
