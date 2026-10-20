// Synthetic records only. No live work records or external email delivery.
const {chromium}=require('@playwright/test');
const path=require('node:path'),assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const p=await browser.newPage({viewport:{width:1600,height:1000}}),errors=[];
  p.setDefaultTimeout(15000);
  p.on('pageerror',e=>errors.push(e.message));
  await p.route('https://synthetic.test/',r=>r.fulfill({contentType:'text/html',body:'<html></html>'}));
  await p.goto('https://synthetic.test/');
  await p.setContent('<html><body style="--bg-surface:white;--text-primary:#123;--border-default:#ccc"></body></html>');
  await p.addStyleTag({path:path.resolve('public/css/claim-record-studio.css')});
  await p.addScriptTag({path:path.resolve('public/js/admin-shell.js')});
  await p.evaluate(()=>{
   let drafts=[],records=[];window.markSubmitted=()=>{drafts[0].recordId=33;drafts[0].content.quantity=5;records=[{id:33,lineId:8,reportedQty:5,status:'verified'}];};window.readRecords=()=>records;window.calls=[];window.viewers=0;
   window.DrawingMarker={open(opts){window.viewers++;const el=document.createElement('div');el.textContent='Embedded drawing '+opts.sheetNo;opts.host.appendChild(el);return {beforeClose:()=>true,close:()=>el.remove()};}};
   window.gsRun=async(m,a)=>{calls.push([m,a]);
    if(m==='api_getClaimEditor')return {success:true,contract:{today:'2026-10-18'},canManage:true,lines:[{id:1,lineNo:'1',description:'Other',unit:'EA'},{id:8,lineNo:'8',description:'Steel door',unit:'EA',contractQty:5,recognitionBasis:'milestone',stageWeights:{stored:40,installation:60},workSectionId:2}],drafts,records,sourceOptions:[{value:'document:7',label:'Door delivery'}]};
    if(m==='api_saveClaimEditDraft'){const d={lineId:a[0].lineId,revision:a[0].revision+1,content:a[0].content};drafts=[d];return {success:true,draft:d};}
    if(m==='api_commitClaimEditDraft'){drafts[0].recordId=33;records=[{id:33,lineId:8,recordKind:'actual',status:'pending',...drafts[0].content,reportedQty:5}];return {success:true,id:33};}
    if(m==='api_getSectionDrawings')return {success:true,noSite:true,sites:[]};
    return {success:true};};
  });
  await p.addScriptTag({path:path.resolve('public/js/admin-claim-editor.js')});
  await p.addScriptTag({path:path.resolve('public/js/admin-section-drawings.js')});
  await p.evaluate(()=>{document.body.insertAdjacentHTML('beforeend','<div id="page-container"></div>');AdminSectionDrawings._state.data={siteId:1,site:'Synthetic',canManage:true,documents:[],catalog:[{id:4,documentId:1,sheetNo:'A-1',title:'Plan'},{id:5,documentId:1,sheetNo:'A-2',title:'Detail'}],billing:{canManage:true},sections:[{id:2,code:'E01',name:'Power',contractId:3,sheets:[{found:true,sheetNo:'A-1',sheet:{id:4,title:'Plan'}}],billing:{lines:[{id:8,lineNo:'8',description:'Steel door',contractQty:5,status:'accepted',sheetNos:null},{id:9,lineNo:'9',description:'Cable',contractQty:10,status:'accepted',sheetNos:['A-2']}]}}]};AdminSectionDrawings.lines(2);});
  assert.equal(await p.locator('[data-boq-card]').count(),2);
  assert.match(await p.locator('[data-boq-card="8"]').innerText(),/상위 공정 도면/);
  assert.match(await p.locator('[data-boq-card="9"]').innerText(),/A-2/);
  assert.doesNotMatch(await p.locator('[data-boq-card="9"]').innerText(),/A-1/);
  await p.screenshot({path:'test-results/assistant-browser/child-process-cards.png',fullPage:true});
  await p.locator('[data-boq-card="8"]').getByRole('button',{name:'기성 편집',exact:true}).click();
  await p.getByText('#8 Steel door',{exact:true}).first().waitFor();
  assert.equal(await p.locator('.ce-studio').count(),1);
  assert.equal(await p.locator('[data-studio-drawing]').innerText(),'Embedded drawing A-1');
  for (const selector of ['[data-export-format]','[data-send-report]','[data-commit]','[data-calcs]','[data-jump]','[data-field="location"]','[data-field="quantity"]','[data-search]','[data-template]']) assert.equal(await p.locator(selector).count(),0,selector+' must be absent, not merely hidden');
  await p.locator('[data-field="notes"]').fill('Door installed — see attached delivery ticket');
  await p.route('**/document-hub/api/upload',route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({documents:[{id:20}]})}));
  await p.locator('[data-upload]').setInputFiles({name:'delivery.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4 synthetic evidence')});
  await p.getByText('업로드 완료 · 원본 보존 · 분석 및 검토 전',{exact:true}).waitFor();
  assert.match(await p.locator('[data-linked]').innerText(),/delivery.pdf/);
  assert.equal(await p.evaluate(()=>viewers),1,'Upload must preserve the live CAD canvas');
  let saved=await p.evaluate(()=>calls.filter(c=>c[0]==='api_saveClaimEditDraft').at(-1)[1][0]);
  assert.equal(saved.lineId,8);assert.equal(saved.content.quantity,0);assert.equal(saved.content.evidence[0].id,20);
  assert.match(saved.content.notes,/Door installed/);
  assert.equal(await p.evaluate(()=>calls.some(c=>c[0]==='api_commitClaimEditDraft')),false,'Evidence upload must not claim quantities');
  await p.screenshot({path:'test-results/assistant-browser/record-studio.png',fullPage:true});
  await p.getByRole('button',{name:'저장하고 닫기',exact:true}).click();
  assert.equal(await p.locator('.ce-studio').count(),0);
  await p.evaluate(()=>AdminClaimEditor.open(3,1,[{id:4,sheetNo:'A-1',title:'Plan'}],{lineId:8,fullscreen:true}));
  await p.locator('[data-field="notes"]').waitFor();
  assert.match(await p.locator('[data-field="notes"]').inputValue(),/Door installed/);
  assert.match(await p.locator('[data-linked]').innerText(),/연결 자료 #20/);
  await p.route('**/document-hub/api/upload',route=>route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({message:'Synthetic upload failed'})}));
  await p.locator('[data-upload]').setInputFiles({name:'bad.pdf',mimeType:'application/pdf',buffer:Buffer.from('bad')});
  await p.locator('[data-upload-state]').getByText('bad.pdf: Synthetic upload failed',{exact:true}).waitFor();
  assert.equal(await p.locator('[data-linked] .ce-attachment').count(),1);
  await p.getByRole('button',{name:'저장하고 닫기',exact:true}).click();
  await p.evaluate(()=>{markSubmitted();AdminClaimEditor.open(3,1,[{id:4,sheetNo:'A-1',title:'Plan'}],{lineId:8,fullscreen:true});});
  await p.locator('[data-field="notes"]').waitFor();
  assert.equal(await p.locator('[data-field="notes"]').inputValue(),'');
  await p.locator('[data-field="notes"]').fill('Additional supporting document');
  await p.getByRole('button',{name:'저장 · Ctrl+S',exact:true}).click();
  await p.waitForFunction(()=>calls.filter(c=>c[0]==='api_saveClaimEditDraft').at(-1)[1][0].newWork===true);
  saved=await p.evaluate(()=>calls.filter(c=>c[0]==='api_saveClaimEditDraft').at(-1)[1][0]);
  assert.equal(saved.content.quantity,0);
  assert.equal(await p.evaluate(()=>readRecords()[0].reportedQty),5);
  await p.setViewportSize({width:390,height:844});
  assert.equal(await p.locator('[data-upload]').isVisible(),true);
  await p.getByRole('button',{name:'저장하고 닫기',exact:true}).click();
  assert.deepEqual(errors,[]);
  console.log(JSON.stringify({passed:true,selectedLine:8,canvasPreserved:true}));
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
