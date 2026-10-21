// Import state regressions using synthetic files only; never contacts a live ERP.
const {chromium, expect} = require('@playwright/test');
const fs = require('node:fs'), assert = require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL||undefined});
 try {
  const page=await browser.newPage({viewport:{width:1500,height:1000}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  const dxf='0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nLINE\n10\n0\n20\n0\n11\n200\n21\n100\n0\nENDSEC\n0\nEOF\n';
  let mode='fail',posts=0,refreshFails=false,releaseUpload,startedUpload,gate=null,saved;
  let drawing={id:9,title:'Synthetic plan',site_id:1,revision:1,dxf,canManage:true,warnings:[]};
  const html=fs.readFileSync('resources/views/cad/index.blade.php','utf8')
   .replace(/<script[^>]*>[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'<link rel="stylesheet" href="/cad-editor.css">')
   .replace('{{ csrf_token() }}','synthetic').replace('</body>','<script src="/cad-core.js"></script><script src="/cad-markup.js"></script><script src="/cad-markup-tools.js"></script><script src="/cad-editor.js"></script></body>');
  await page.route('https://synthetic.test/**',async route=>{
   const u=new URL(route.request().url()),method=route.request().method();let json;
   if(u.pathname==='/cad')return route.fulfill({contentType:'text/html',body:html});
   if(['/cad-core.js','/cad-markup.js','/cad-markup-tools.js','/cad-editor.js'].includes(u.pathname))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+u.pathname,'utf8')});
   if(u.pathname==='/cad-editor.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync('public/css/cad-editor.css','utf8')});
   if(u.pathname==='/admin-api/cad/capabilities')json={dwgRead:true,dwgWrite:true,message:'Synthetic converter'};
   else if(u.pathname==='/admin-api/cad'&&method==='POST'){
    posts++;startedUpload?.();if(gate)await gate;
    if(mode==='fail')return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({message:'별색 정보를 변환하지 못했습니다.'})});
    if(mode==='html')return route.fulfill({status:413,contentType:'text/html',body:'<h1>Payload too large</h1>'});
    json=drawing;
   }else if(u.pathname==='/admin-api/cad'){
    if(refreshFails)return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'목록 일시 중단'})});
    json={canManage:true,sites:[{id:1,name:'Synthetic Site'}],drawings:[]};
   }else if(u.pathname==='/admin-api/cad/9'&&method==='GET')json=drawing;
   else if(u.pathname==='/admin-api/cad/9'&&method==='PUT'){
    saved=route.request().postDataJSON();drawing={...drawing,dxf:saved.dxf,revision:drawing.revision+1};json=drawing;
   }else return route.fulfill({status:404,body:'not found'});
   return route.fulfill({contentType:'application/json',body:JSON.stringify(json)});
  });
  await page.goto('https://synthetic.test/cad?site_id=1');
  await expect(page.locator('#upload')).toBeEnabled();
  const file={name:'synthetic-plan.dwg',mimeType:'application/octet-stream',buffer:Buffer.from('AC1032 synthetic')};
  gate=new Promise(r=>releaseUpload=r);const started=new Promise(r=>startedUpload=r);
  await page.locator('#upload').setInputFiles(file);await started;
  await expect(page.locator('#drawing-state')).toHaveAttribute('data-state','loading');
  await expect(page.locator('#sites')).toBeDisabled();
  releaseUpload();gate=null;startedUpload=null;
  await expect(page.locator('#drawing-state')).toHaveAttribute('data-state','error');
  await expect(page.locator('#drawing-state-message')).toContainText('별색 정보를 변환하지 못했습니다.');
  await expect(page.locator('#upload')).toHaveValue('');
  await expect(page.locator('#upload')).toBeEnabled();
  // Same file can be retried after failure; non-JSON errors are readable.
  mode='html';await page.locator('#upload').setInputFiles(file);
  await expect(page.locator('#drawing-state-message')).toContainText('업로드 한도');
  await expect(page.locator('#upload')).toHaveValue('');assert.equal(posts,2);
  // A failed list refresh cannot hide a successfully imported, editable drawing.
  mode='ok';refreshFails=true;await page.locator('#upload').setInputFiles(file);
  await expect(page.locator('#drawing-state')).toBeHidden();
  await expect(page.locator('#drawings')).toHaveValue('9');
  await expect(page.locator('#save')).toBeEnabled();
  await expect(page.locator('#status')).toContainText('목록 새로고침에 실패');
  await page.locator('.mode-switch [data-workspace="cad"]').click();
  await page.locator('#snap').uncheck();await page.locator('[data-tool="rectangle"]').click();
  const box=await page.locator('#canvas').boundingBox();
  await page.mouse.move(box.x+box.width*.2,box.y+box.height*.2);await page.mouse.down();
  await page.mouse.move(box.x+box.width*.4,box.y+box.height*.4);await page.mouse.up();
  await expect(page.locator('#dirty')).toContainText('저장 안 됨');
  // Attempting a different import must not clear an edited drawing on failure.
  page.once('dialog',d=>d.accept());mode='fail';await page.locator('#upload').setInputFiles(file);
  await expect(page.locator('#drawing-state')).toHaveAttribute('data-state','error');
  await expect(page.locator('#dirty')).toContainText('저장 안 됨');
  await page.locator('#drawing-state-dismiss').click();await page.locator('#save').click();
  await expect(page.locator('#dirty')).toContainText('개정 2 저장됨');
  assert.match(saved.dxf,/LWPOLYLINE/);assert.match(saved.dxf,/LINE/);
  assert.deepEqual(errors,[]);
  console.log(JSON.stringify({passed:true,posts,checks:'loading, visible error, same-file retry, non-JSON error, list failure, dirty drawing preservation'}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
