// Isolated manual browser regression fixture. No production API or credentials are used.
const http=require('node:http'),fs=require('node:fs'),path=require('node:path');
let draft=null,records=[],receipts=new Map(),writes=0,formWrites=0;
const clone=x=>JSON.parse(JSON.stringify(x));
const workspace=()=>({success:true,canManage:true,contract:{id:1,today:'2026-10-20',currency:'USD',siteId:1},
  lines:[{id:1,lineNo:'1',description:'저장 시험 공정',unit:'EA',contractQty:100,unitPrice:1,availableAmount:0,recognitionBasis:'quantity'}],
  records:clone(records),drafts:draft?[clone(draft)]:[],sourceOptions:[],applications:[]});
const html=`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="save-user" content="test-901"><meta name="csrf-token" content="fixture">
<title>기성 저장 복구 시험</title><body style="font:16px system-ui;background:#eef2f6;padding:20px">
<h1>기성 저장 복구 시험 · 운영 자료와 무관</h1>
<label><input id="delay" type="checkbox" checked>저장 응답 2초 지연</label>
<label><input id="lost" type="checkbox">다음 저장 응답 끊김 모의</label>
<button id="open">기성 편집 열기</button><button id="side">도면·자료 편집 열기</button><button id="form">팀장 폼 열기</button><button id="other">다른 창의 수정 모의</button>
<pre id="result">서버 저장 0회</pre>
<script src="/js/claim-save-safety.js"></script><script src="/js/admin-shell.js"></script><script src="/js/admin-claim-editor.js"></script>
<script>
window.gsRun=async function(method,args){
 const lost=['api_saveClaimEditDraft','fixtureFormSave'].includes(method)&&document.querySelector('#lost').checked;
 if(lost)document.querySelector('#lost').checked=false;
 const response=await fetch('/api',{method:'POST',body:JSON.stringify({method,args,delay:document.querySelector('#delay').checked})});
 const data=await response.json();document.querySelector('#result').textContent=JSON.stringify(data.inspect,null,2);
 if(lost)throw Error('시험: 서버 저장 후 응답 끊김');return data.result;
};
document.querySelector('#open').onclick=()=>AdminClaimEditor.open(1,1,[]);
document.querySelector('#side').onclick=()=>AdminClaimEditor.open(1,1,[],{lineId:1,fullscreen:true});
document.querySelector('#other').onclick=()=>gsRun('other',[]);
document.querySelector('#form').onclick=()=>AdminUI.formModal({title:'팀장 입력 복구 시험',draftKey:'fixture-field:1',fields:[{name:'notes',label:'작업 내용',type:'textarea',required:true}],onSave:values=>gsRun('fixtureFormSave',[values])});
</script></body></html>`;
const server=http.createServer(async(req,res)=>{
  if(req.method==='GET'&&req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
  if(req.method==='GET'&&['/js/claim-save-safety.js','/js/admin-shell.js','/js/admin-claim-editor.js'].includes(req.url)){res.setHeader('Content-Type','text/javascript; charset=utf-8');return res.end(fs.readFileSync(path.join(__dirname,'../../public',req.url)));}
  if(req.method!=='POST'||req.url!=='/api'){res.statusCode=404;return res.end();}
  const parts=[];for await(const p of req)parts.push(p);const {method,args,delay}=JSON.parse(Buffer.concat(parts));let result={success:true};
  if(method==='api_getClaimEditor')result=workspace();
  if(method==='other'&&draft){draft.content.notes='다른 창에서 저장한 최신 내용';draft.revision++;writes++;}
  if(method==='api_saveClaimEditDraft'){
    if(delay)await new Promise(r=>setTimeout(r,2000));const input=args[0];
    if(receipts.has(input.requestId))result=receipts.get(input.requestId);
    else if(input.revision!==(draft?.revision||0))result={success:false,code:'draft_conflict',error:'다른 창의 수정과 충돌',currentDraft:clone(draft)};
    else {draft={lineId:1,revision:input.revision+1,content:clone(input.content),history:[],recordId:null,savedAt:new Date().toISOString()};writes++;result={success:true,draft:clone(draft)};receipts.set(input.requestId,result);}
  }
  if(method==='api_commitClaimEditDraft'){
    if(!draft.recordId){draft.recordId=1;records.push({id:1,lineId:1,status:'pending',recordKind:'actual',workDate:draft.content.workDate,location:draft.content.location,reportedQty:draft.content.quantity});}
    result={success:true,id:1};
  }
  if(method==='fixtureFormSave'){
    if(delay)await new Promise(r=>setTimeout(r,1500));
    const input=args[0],prior=receipts.get(input.requestId);
    if(prior){if(prior.payload!==JSON.stringify(input))result={success:false,error:'Retry payload changed'};}
    else if(input.notes==='invalid')result={success:false,error:'Correct the notes'};
    else{formWrites++;receipts.set(input.requestId,{payload:JSON.stringify(input)});}
  }
  res.setHeader('Content-Type','application/json');res.end(JSON.stringify({result,inspect:{writes,revision:draft?.revision,content:draft?.content,records:records.length,formWrites}}));
});server.listen(9093,'127.0.0.1',()=>console.log('Synthetic fixture: http://127.0.0.1:9093'));
