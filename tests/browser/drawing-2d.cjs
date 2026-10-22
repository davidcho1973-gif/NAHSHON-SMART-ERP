// Synthetic embedded viewer: actual pointer/keyboard events, no ERP or external sends.
const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
const {start}=require('./fixture-2d.cjs');
(async()=>{
  const server=await start(0),url='http://127.0.0.1:'+server.address().port;
  const browser=await chromium.launch({headless:true,channel:process.env.STUDIO_BROWSER_CHANNEL||undefined});
  try{
    const p=await browser.newPage({viewport:{width:1700,height:1000}}),errors=[];p.setDefaultTimeout(10000);p.on('pageerror',e=>errors.push(e.message));
    await p.goto(url);await p.locator('#open').click();await p.locator('.ds-toolbar').waitFor();
    const click=async(x,y)=>{const b=await p.locator('.ds-overlay').boundingBox();await p.mouse.click(b.x+x*b.width,b.y+y*b.height);};
    const tool=async t=>p.locator('.ds-toolbar [data-tool="'+t+'"]').click();
    const expand=async name=>{const d=p.locator('[data-panel-section="'+name+'"]');if(await d.evaluate(e=>!e.open))await d.locator('summary').click();};
    const state=async()=>fetch(url+'/admin-api/drawing-sheets/1/studio').then(r=>r.json());
    const save=async()=>{await p.locator('[data-stage]').press('Control+s');await p.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('버전'));};
    await tool('polygon');await click(.3,.3);await click(.7,.3);
    await p.keyboard.press('Enter');assert.equal(await p.locator('.ds-entity').count(),0,'Insufficient points must remain a draft');
    await click(.7,.7);await click(.3,.7);await p.keyboard.press('Enter');
    assert.equal(await p.locator('.ds-entity').count(),1,'Enter must work after a toolbar re-render without forcibly focusing the canvas');
    assert.match(await p.locator('.ds-measurement').textContent(),/768.00 ft²/);
    await expand('precision');await p.locator('[data-hole]').click();
    for(const q of [[.4,.4],[.5,.4],[.5,.5],[.4,.5]])await click(...q);
    await p.keyboard.press('Enter');assert.match(await p.locator('.ds-measurement').textContent(),/720.00 ft²/);
    await p.locator('[data-takeoff]').click();await p.waitForFunction(()=>document.querySelector('#result').textContent.startsWith('시험 공정 수량')).catch(async e=>{console.error({result:await p.locator('#result').textContent(),status:await p.locator('[data-status]').textContent()});throw e;});
    assert.ok(Math.abs(Number((await p.locator('#result').textContent()).match(/수량 ([\d.]+)/)[1])-720)<.001);
    let saved=await state();assert.equal(saved.document.entities[0].holes.length,1);assert.ok(saved.revision>0);
    await p.locator('#reopen').click();await p.locator('.ds-toolbar').waitFor();
    await tool('select');await click(.3,.5);assert.match(await p.locator('.ds-measurement').textContent(),/720.00 ft²/);
    await expand('precision');await p.locator('[data-insert-vertex]').click();assert.equal(await p.locator('[data-vertex-index] option').count(),5);
    await p.locator('[data-undo]').click();await click(.3,.5);await expand('precision');assert.equal(await p.locator('[data-vertex-index] option').count(),4);
    await expand('library');await p.locator('[data-library-name]').fill('Room with opening');await p.locator('[data-save-block]').click();
    await p.locator('[data-library-name]').fill('East view');await p.locator('[data-save-view]').click();await save();
    saved=await state();assert.equal(saved.document.blocks[0].entities[0].holes.length,1);assert.equal(saved.document.views.length,1);
    await p.locator('[data-insert-block]').click();assert.equal(await p.locator('.ds-entity').count(),2);await p.locator('[data-undo]').click();
    await tool('line');await click(.4,.8);await expand('precision');await p.locator('[data-next-length]').fill('10 ft');await p.locator('[data-next-point]').click();
    assert.match(await p.locator('.ds-measurement').textContent(),/10.00 ft/);
    await p.locator('[data-takeoff]').click();assert.match(await p.locator('[data-status]').textContent(),/면적 공정/);
    // A new unfinished path survives refresh independently of the last server acknowledgement.
    await tool('polygon');await click(.35,.2);await click(.5,.2);
    p.on('dialog',d=>d.accept());await p.reload();await p.locator('#open').click();await p.locator('.ds-toolbar').waitFor();
    await click(.5,.25);await p.keyboard.press('Enter');assert.equal(await p.locator('.ds-entity').count(),3);
    await save();saved=await state();assert.equal(saved.document.entities.length,3);assert.equal(saved.document.blocks.length,1);
    await expand('files');const downloaded=p.waitForEvent('download');await p.locator('[data-dxf]').click();const file=await downloaded;
    fs.mkdirSync('test-results/assistant-browser',{recursive:true});await file.saveAs('test-results/assistant-browser/SYNTHETIC-2d.dxf');
    assert.match(fs.readFileSync('test-results/assistant-browser/SYNTHETIC-2d.dxf','utf8'),/POLYLINE/);
    await p.screenshot({path:'test-results/assistant-browser/SYNTHETIC-2d-editor.png'});await p.setViewportSize({width:390,height:844});await p.locator('[data-fit]').click();assert.ok((await p.locator('[data-stage]').boundingBox()).width>350,'Mobile tools must not consume the drawing width');await p.screenshot({path:'test-results/assistant-browser/SYNTHETIC-2d-mobile.png'});assert.deepEqual(errors,[]);
    console.log(JSON.stringify({passed:true,enter:true,netArea:720,savedRevisions:saved.revision,draftRecovery:true,blocks:true,views:true}));
  }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(e=>{console.error(e);process.exitCode=1;});
