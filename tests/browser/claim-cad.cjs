// Synthetic CAD/claim integration only. No live financial data or real uploads.
const { chromium } = require('@playwright/test');
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL||undefined});
 try{
  const page=await browser.newPage({viewport:{width:1500,height:1000}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));page.setDefaultTimeout(15000);
  const dxf='0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nLINE\n10\n0\n20\n0\n11\n100\n21\n50\n0\nENDSEC\n0\nEOF\n';
  let drawing={id:9,title:'Claim CAD',site_id:1,revision:1,dxf,canManage:true,warnings:[],sourceFormat:'dxf'},attachments=0,conflictNext=false;
  const revisions=new Map([[1,{...drawing}]]);
  const html=fs.readFileSync('resources/views/cad/index.blade.php','utf8').replace(/<script[^>]*>[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'').replace('{{ csrf_token() }}','synthetic').replace('</body>','<script src="/cad-core.js"></script><script src="/cad-editor.js"></script></body>');
  await page.route('https://synthetic.test/**',async route=>{
   const url=new URL(route.request().url()),method=route.request().method();let json;
   if(url.pathname==='/'){return route.fulfill({contentType:'text/html',body:'<html><body></body></html>'});}
   if(url.pathname==='/cad'){return route.fulfill({contentType:'text/html',body:html});}
   if(['/cad-core.js','/cad-editor.js'].includes(url.pathname))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+url.pathname,'utf8')});
   if(url.pathname==='/admin-api/cad/claim-context')json={line:{id:8,contractId:3,siteId:1,description:'Wall'},draftRevision:await page.evaluate(()=>testDraft.revision),recordId:null,canManage:true};
   else if(url.pathname==='/admin-api/cad/capabilities')json={dwgRead:false,dwgWrite:false,message:'DXF synthetic'};
   else if(url.pathname==='/admin-api/cad')json={canManage:true,sites:[{id:1,name:'Test Site'}],drawings:[drawing]};
   else if(url.pathname==='/admin-api/cad/9/history')json={...revisions.get(Number(url.searchParams.get('revision'))),canManage:false};
   else if(url.pathname==='/admin-api/cad/9'&&method==='GET')json=drawing;
   else if(url.pathname==='/admin-api/cad/9'&&method==='PUT'){const p=route.request().postDataJSON();assert.equal(p.revision,drawing.revision);drawing={...drawing,revision:drawing.revision+1,dxf:p.dxf};revisions.set(drawing.revision,{...drawing});json=drawing;}
   else if(url.pathname==='/admin-api/cad/9/claim-draft'){
    const p=route.request().postDataJSON();if(conflictNext){conflictNext=false;return route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({message:'Synthetic stale draft'})});}attachments++;assert.equal(p.lineId,8);assert.equal(p.drawingRevision,drawing.revision);
    const draft=await page.evaluate(p=>{if(testDraft.revision!==p.draftRevision)throw Error('Stale parent revision');testDraft.revision++;testDraft.content.evidence.push({type:'cad_revision',id:91,locator:'CAD r'+p.drawingRevision,sha256:'a'.repeat(64),drawingId:9,drawingRevision:p.drawingRevision});return testDraft;},p);
    json={success:true,draft,evidence:draft.content.evidence.at(-1)};
   }else return route.fulfill({status:404,body:'not found'});
   return route.fulfill({contentType:'application/json',body:JSON.stringify(json)});
  });
  await page.goto('https://synthetic.test/');await page.addScriptTag({path:path.resolve('public/js/admin-shell.js')});
  await page.evaluate(()=>{
   window.testDraft={lineId:8,revision:1,content:{workDate:'2026-10-20',location:'North',stage:'installed',quantity:3,calculations:[],evidence:[],marks:[],notes:'Original'}};window.calls=[];
   window.gsRun=async(m,a)=>{calls.push([m,a]);if(m==='api_getClaimEditor')return {success:true,canManage:true,contract:{today:'2026-10-20'},lines:[{id:8,lineNo:'8',description:'Wall',unit:'EA',contractQty:10,recognitionBasis:'quantity'}],drafts:[structuredClone(testDraft)],records:[],sourceOptions:testDraft.content.evidence.map(e=>({value:e.type+':'+e.id,label:'CAD · Claim CAD · r'+e.drawingRevision,...e}))};if(m==='api_saveClaimEditDraft'){if(a[0].revision!==testDraft.revision)throw Error('Stale draft');testDraft={lineId:8,revision:testDraft.revision+1,content:structuredClone(a[0].content)};return {success:true,draft:structuredClone(testDraft)};}return {success:true};};
  });
  await page.addScriptTag({path:path.resolve('public/js/claim-save-safety.js')});await page.addScriptTag({path:path.resolve('public/js/admin-claim-editor.js')});await page.evaluate(()=>AdminClaimEditor.open(3,1,[],{lineId:8}));
  await page.locator('[data-field="notes"]').fill('Unsaved notes retained');await page.locator('[data-field="quantity"]').fill('7');await page.locator('[data-cad]').click();
  const frame=page.frameLocator('[data-claim-cad]');await frame.locator('#drawings').selectOption('9');await frame.getByText('Claim CAD · 1개 객체',{exact:true}).waitFor();
  assert.equal(await page.locator('.ce-editor').evaluate(el=>el.inert),true);assert.equal(await frame.locator('#sites').isEnabled(),false);
  // Unrelated window cannot close or refresh the current CAD session.
  await page.evaluate(()=>window.postMessage({type:'erp:cad-claim-attached',lineId:8,contractId:3},location.origin));assert.equal(await page.locator('[data-claim-cad]').count(),1);await frame.locator('body').evaluate(()=>parent.postMessage({type:'erp:cad-claim-attached',lineId:999,contractId:3},location.origin));assert.equal(await page.locator('[data-claim-cad]').count(),1);
  await frame.getByRole('button',{name:'선',exact:true}).click();await frame.locator('#x').fill('10');await frame.locator('#y').fill('10');await frame.locator('#coordinate').click();await frame.locator('#x').fill('20');await frame.locator('#y').fill('20');await frame.locator('#coordinate').click();await frame.locator('#claim-attach').click();assert.equal(attachments,0);
  await frame.locator('#save').click();await frame.getByText('개정 2 저장됨',{exact:true}).waitFor();await frame.locator('#claim-attach').click();await page.locator('[data-claim-cad]').waitFor({state:'detached'});await page.getByText('CAD 개정이 기성 초안 증빙에 연결됨 · 수량 유지',{exact:true}).waitFor();
  assert.equal(await page.locator('.ce-editor').evaluate(el=>el.inert),false);assert.equal(await page.locator('[data-field="notes"]').inputValue(),'Unsaved notes retained');assert.equal(await page.locator('[data-field="quantity"]').inputValue(),'7');assert.equal(attachments,1);assert.match(await page.locator('[data-linked]').innerText(),/CAD/);
  assert.equal(await page.evaluate(()=>calls.filter(c=>c[0]==='api_commitClaimEditDraft').length),0);assert.equal(await page.evaluate(()=>testDraft.content.evidence[0].drawingRevision),2);assert.deepEqual(errors,[]);
  drawing={...drawing,revision:3};revisions.set(3,{...drawing});await page.locator('[data-proof]').click();const proof=page.frameLocator('iframe[title="저장된 CAD 증빙 개정"]');await proof.getByText('개정 2',{exact:true}).waitFor();assert.equal(await proof.locator('#save').isEnabled(),false);assert.equal(await proof.locator('#history').isEnabled(),false);await proof.locator('#reload').click();await proof.getByText('개정 2',{exact:true}).waitFor();await page.getByRole('button',{name:'닫기',exact:true}).click();
  await page.locator('[data-cad]').click();await frame.locator('#drawings').selectOption('9');await frame.getByText('Claim CAD · 2개 객체',{exact:true}).waitFor();conflictNext=true;await frame.locator('#claim-attach').click();await frame.getByText('기성 초안이 다른 화면에서 변경되었습니다. CAD 편집은 유지됩니다. 기성으로 돌아가 최신 초안을 확인한 뒤 다시 연결하세요.',{exact:true}).waitFor();assert.equal(await page.locator('[data-claim-cad]').count(),1);await frame.locator('#claim-return').click();await page.locator('[data-claim-cad]').waitFor({state:'detached'});assert.equal(await page.locator('[data-field="quantity"]').inputValue(),'7');assert.equal(attachments,1);
  console.log(JSON.stringify({passed:true,attachments,quantity:7,revision:await page.evaluate(()=>testDraft.revision),errors}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
