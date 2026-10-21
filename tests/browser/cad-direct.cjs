// Actual pointer events against the CAD page and styles; no live records or uploads.
const { chromium } = require('@playwright/test');
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const context = {window: {}};
vm.runInNewContext(fs.readFileSync('public/js/cad-core.js', 'utf8'), context);
const C = context.window.CADCore;
const plain = value => JSON.parse(JSON.stringify(value));
const near = (actual, expected) => assert.ok(Math.abs(actual-expected)<0.3, `${actual} != ${expected}`);
const point = (actual, x, y) => {near(actual.x,x);near(actual.y,y);};
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL||undefined});
 try {
  const page=await browser.newPage({viewport:{width:1500,height:1000}}), errors=[];
  page.on('pageerror',e=>errors.push(e.message));page.setDefaultTimeout(10000);
  const dxf='0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nPOINT\n10\n0\n20\n0\n0\nPOINT\n10\n200\n20\n100\n0\nENDSEC\n0\nEOF\n';
  let drawing={id:9,title:'Pointer fixture',site_id:1,revision:1,dxf,canManage:true,warnings:[]}, writes=0, saveGate=null, saveStarted=null;
  const html=fs.readFileSync('resources/views/cad/index.blade.php','utf8')
   .replace(/<script[^>]*>[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'<link rel="stylesheet" href="/cad-editor.css">')
   .replace('{{ csrf_token() }}','synthetic').replace('</body>','<script src="/cad-core.js"></script><script src="/cad-markup.js"></script><script src="/cad-markup-tools.js"></script><script src="/cad-editor.js"></script></body>');
  await page.route('https://synthetic.test/**',async route=>{
   const u=new URL(route.request().url()),method=route.request().method();let json;
   if(u.pathname==='/cad')return route.fulfill({contentType:'text/html',body:html});
   if(['/cad-core.js','/cad-markup.js','/cad-markup-tools.js','/cad-editor.js'].includes(u.pathname))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+u.pathname,'utf8')});
   if(u.pathname==='/cad-editor.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync('public/css/cad-editor.css','utf8')});
   if(u.pathname==='/admin-api/cad/capabilities')json={dwgRead:false,dwgWrite:false,message:'Synthetic DXF'};
   else if(u.pathname==='/admin-api/cad')json={canManage:true,sites:[{id:1,name:'Synthetic Site'}],drawings:[drawing]};
   else if(u.pathname==='/admin-api/cad/9'&&method==='GET')json=drawing;
   else if(u.pathname==='/admin-api/cad/9/history')json={...drawing,canManage:false};
   else if(u.pathname==='/admin-api/cad/9'&&method==='PUT'){
    const p=route.request().postDataJSON();assert.equal(p.revision,drawing.revision);writes++;if(saveStarted)saveStarted();
    if(saveGate)await saveGate;
    drawing={...drawing,revision:drawing.revision+1,dxf:p.dxf};json=drawing;
   } else return route.fulfill({status:404,body:'not found'});
   return route.fulfill({contentType:'application/json',body:JSON.stringify(json)});
  });
  await page.goto('https://synthetic.test/cad?drawing_id=9');
  await page.getByText('Pointer fixture · 2개 객체',{exact:true}).waitFor();
  await page.locator('#snap').uncheck();
  await page.locator('[data-workspace="cad"]').click();
  const box=await page.locator('#canvas').boundingBox(),scale=.85*Math.min(box.width/200,box.height/100);
  const screen=(x,y)=>({x:box.x+box.width/2+(x-100)*scale,y:box.y+box.height/2-(y-50)*scale});
  const move=async(x,y)=>{const p=screen(x,y);await page.mouse.move(p.x,p.y,{steps:6});};
  const click=async(x,y)=>{const p=screen(x,y);await page.mouse.click(p.x,p.y);};
  const drag=async(a,b)=>{await move(...a);await page.mouse.down();await move(...b);await page.mouse.up();};
  const tool=async name=>page.locator(`[data-tool="${name}"]`).click();
  const entities=()=>plain(C.parse(drawing.dxf).entities);
  const one=type=>{const es=entities().filter(e=>e.type===type);assert.equal(es.length,1);return es[0];};
  const save=async()=>{
   const rev=drawing.revision;await page.locator('#save').click();await page.getByText(`개정 ${rev+1} 저장됨`,{exact:true}).waitFor();
  };
  // Draw with one continuous press, then undo the whole gesture in one step.
  await page.locator('#default-color').fill('#ff6600');await page.locator('#default-width').fill('0.5');
  await page.locator('#ortho').check();await tool('line');await drag([20,70],[80,73]);await save();await page.locator('#ortho').uncheck();
  let line=one('LINE');point(line.p,20,70);point(line.q,80,70);assert.equal(line.color,0xff6600);assert.equal(line.lineWeight,50);
  assert.match(await page.locator('[data-tool="select"]').getAttribute('class'),/active/);
  await page.locator('#undo').click();await save();assert.equal(entities().filter(e=>e.type==='LINE').length,0);
  await page.locator('#redo').click();await save();
  await drag([50,70],[60,60]);await save();line=one('LINE');point(line.p,30,60);point(line.q,90,60);
  await drag([90,60],[100,80]);await save();line=one('LINE');point(line.p,30,60);point(line.q,100,80);
  await page.locator('#undo').click();await save();point(one('LINE').q,90,60);
  await page.locator('#redo').click();await save();
  // Preview cancellation must not mutate the document or consume an undo entry.
  const cleanDXF=drawing.dxf,cleanWrites=writes;
  await click(65,70);await move(65,70);await page.mouse.down();await move(80,90);await page.keyboard.press('Escape');await page.mouse.up();
  assert.doesNotMatch(await page.locator('#dirty').innerText(),/저장 안 됨/);await page.locator('#save').click();assert.equal(writes,cleanWrites);assert.equal(drawing.dxf,cleanDXF);
  await move(65,70);await page.mouse.down();await move(70,90);await page.locator('#canvas').dispatchEvent('pointercancel',{pointerId:1});await page.mouse.up();
  assert.doesNotMatch(await page.locator('#dirty').innerText(),/저장 안 됨/);
  // Rectangle interior selection, movement and both corner/edge grips.
  await page.locator('#ortho').check();await page.locator('#default-color').fill('#22cc77');await tool('rectangle');await drag([115,25],[155,65]);await save();await page.locator('#ortho').uncheck();
  let rect=one('LWPOLYLINE');assert.equal(rect.closed,true);assert.equal(rect.points.length,4);assert.equal(rect.color,0x22cc77);
  await click(190,20);await drag([130,40],[140,45]);await save();rect=one('LWPOLYLINE');point(rect.points[0],125,30);point(rect.points[2],165,70);
  await drag([165,70],[175,80]);await save();rect=one('LWPOLYLINE');point(rect.points[0],125,30);point(rect.points[2],175,80);
  await drag([150,80],[150,90]);await save();rect=one('LWPOLYLINE');point(rect.points[0],125,30);point(rect.points[2],175,90);
  await page.locator('#undo').click();await save();point(one('LWPOLYLINE').points[2],175,80);
  await page.locator('#redo').click();await save();await click(145,55);
  assert.equal(await page.locator('#entity-color').inputValue(),'#22cc77');
  await page.locator('#entity-color').fill('#8844ee');await page.locator('#entity-width').fill('0.7');await page.locator('#apply-style').click();await save();
  rect=one('LWPOLYLINE');assert.equal(rect.color,0x8844ee);assert.equal(rect.lineWeight,70);assert.equal(one('LINE').color,0xff6600);
  // Text defaults are separate from selection edits, including rotation preservation.
  await page.locator('#default-text-height').fill('4');await page.locator('#default-text-color').fill('#ffee44');await page.locator('#angle').fill('15');
  await tool('text');await click(25,20);await page.locator('#text-dialog-content').fill('기성 확인');await page.locator('#text-dialog-confirm').click();await save();let text=one('MTEXT');assert.equal(text.text,'기성 확인');assert.equal(text.height,4);assert.equal(text.color,0xffee44);near(text.angle,15);
  assert.equal(await page.locator('#text').inputValue(),'기성 확인');assert.equal(await page.locator('#height').inputValue(),'4');
  await page.locator('#text').fill('수정 완료');await page.locator('#height').fill('6');await page.locator('#text-color').fill('#00aaff');await page.locator('#apply-text').click();await save();
  text=one('MTEXT');assert.equal(text.text,'수정 완료');assert.equal(text.height,6);assert.equal(text.color,0x00aaff);near(text.angle,15);
  // Writes in flight and locked layers reject further pointer mutations.
  await click(65,70);await page.locator('#entity-color').fill('#cc3300');await page.locator('#apply-style').click();
  let releaseSave;saveGate=new Promise(resolve=>releaseSave=resolve);const started=new Promise(resolve=>saveStarted=resolve),priorWrites=writes,priorRev=drawing.revision;
  await page.locator('#save').click();await started;saveStarted=null;
  assert.equal(writes,priorWrites+1);await drag([65,70],[75,75]);releaseSave();saveGate=null;
  await page.getByText(`개정 ${priorRev+1} 저장됨`,{exact:true}).waitFor();point(one('LINE').p,30,60);point(one('LINE').q,100,80);
  await page.locator('#layers input[title="레이어 잠금"]').check();await save();const lockedWrites=writes;
  await drag([65,70],[75,75]);await page.locator('#save').click();assert.equal(writes,lockedWrites);assert.equal(await page.locator('#apply-style').isDisabled(),true);
  await page.locator('#layers input[title="레이어 잠금"]').uncheck();await save();
  // Reopen persisted geometry/appearance and verify narrow layouts retain the inspector.
  await page.locator('#reload').click();await page.getByText('Pointer fixture · 5개 객체',{exact:true}).waitFor();
  await click(145,55);assert.equal(await page.locator('#entity-color').inputValue(),'#8844ee');
  await click(27,19);assert.equal(await page.locator('#text').inputValue(),'수정 완료');assert.equal(await page.locator('#height').inputValue(),'6');
  fs.mkdirSync('test-results/assistant-browser',{recursive:true});await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-direct.png'});
  await page.setViewportSize({width:760,height:900});assert.equal(await page.locator('#text-color').isVisible(),true);assert.equal(await page.locator('#apply-style').isVisible(),true);
  // Pinned proofs are read-only even when loaded from a manageable drawing.
  await page.goto(`https://synthetic.test/cad?drawing_id=9&revision=${drawing.revision}`);await page.getByText('Pointer fixture · 5개 객체',{exact:true}).waitFor();
  await page.locator('.mode-switch [data-workspace="cad"]').click();
  assert.equal(await page.locator('#save').isDisabled(),true);
  const frozenWrites=writes;await tool('line');const readonlyBox=await page.locator('#canvas').boundingBox();
  await page.mouse.move(readonlyBox.x+30,readonlyBox.y+30);await page.mouse.down();await page.mouse.move(readonlyBox.x+150,readonlyBox.y+100,{steps:4});await page.mouse.up();
  assert.equal(writes,frozenWrites);assert.doesNotMatch(await page.locator('#dirty').innerText(),/저장 안 됨/);assert.deepEqual(errors,[]);
  console.log(JSON.stringify({passed:true,writes,checks:'drag line/rectangle, move, grips, atomic undo, cancellation, styles, text, busy, locks, reopen, narrow viewport, read-only',errors}));
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
