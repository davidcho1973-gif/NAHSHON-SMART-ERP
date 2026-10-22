// Exercises the real viewer and CAD modules with synthetic data; no external sends.
const {chromium}=require('playwright');
const fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
  const browser=await chromium.launch({headless:true,channel:process.env.STUDIO_BROWSER_CHANNEL||undefined});
  try {
    const page=await browser.newPage({viewport:{width:1600,height:1000}}),errors=[],dispatches=[];
    page.on('pageerror',e=>errors.push(e.message));
    let revision=0,document={version:1,unit:'ft',precision:2,layers:[{id:'default',name:'작도·주석',visible:true,locked:false}],entities:[]},conflict=false,ocrCalls=0,ocrLabels=['12\'-6 1/2"'];
    await page.route('**/*',async route=>{
      const req=route.request(),path=new URL(req.url()).pathname;
      if(path==='/')return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="csrf-token" content="synthetic"><body style="margin:0;font-family:Arial,sans-serif;background:#0f172a"></body></html>'});
      let data={};
      if(path.endsWith('/studio')){
        if(req.method()==='PUT'){
          if(conflict)return route.fulfill({status:409,json:{message:'다른 창에서 수정했습니다.'}});
          const p=req.postDataJSON();assert.equal(p.revision,revision);document=p.document;revision++;data={success:true,revision};
        }else data={document,revision,canManage:true};
      }else if(path.endsWith('/read-dimension')){ocrCalls++;assert.ok(req.postDataBuffer().includes(Buffer.from('image/png')));data={dimensions:ocrLabels,source:'ocr'};}
      else if(path.endsWith('/share-options'))data={rooms:[{id:7,name:'현장 검토방'}],mailReady:true,mailFrom:'fixture@example.org',mailBudget:18874368};
      else if(path.endsWith('/messages'))data={messages:[{body:'변경 위치에 치수와 수정구름을 표시해 주세요.',created_at:'2026-10-18'}],url:'/room/7'};
      else if(path.endsWith('/share')){dispatches.push(req.postDataBuffer());data={success:true,status:'sent'};}
      else throw Error('Unexpected request: '+req.url());
      return route.fulfill({json:data});
    });
    await page.goto('https://studio.test/');
    await page.addStyleTag({path:'public/css/drawing-studio.css'});
    await page.evaluate(()=>{
      window.__pdfText=[];window.__scale={feetPerPoint:null,scaleLabel:null};window.__scaleCalls=[];
      window.__pdfjsMock={GlobalWorkerOptions:{},getDocument:()=>({destroy(){},promise:Promise.resolve({getPage:()=>Promise.resolve({
        getViewport:({scale})=>({width:800*scale,height:600*scale,convertToViewportPoint:(x,y)=>[x*scale,(600-y)*scale]}),getTextContent:()=>Promise.resolve({items:window.__pdfText}),
        render:({canvasContext:c,viewport:v})=>{c.fillStyle='#fff';c.fillRect(0,0,v.width,v.height);c.save();c.scale(v.width/800,v.height/600);c.strokeStyle='#64748b';c.lineWidth=2;
          c.strokeRect(90,75,620,450);[[90,75,210,180],[300,75,410,180],[90,255,210,270],[300,255,240,270],[540,255,170,270]].forEach(r=>c.strokeRect(...r));
          c.fillStyle='#64748b';c.font='11px Arial';[['MECHANICAL',130,120],['KITCHEN',480,120],['STORAGE',160,440],['LOBBY',410,440],['OFFICE',600,440]].forEach(t=>c.fillText(...t));
          c.strokeStyle='#0f172a';c.lineWidth=1;c.beginPath();c.moveTo(100,180);c.lineTo(350,180);c.moveTo(100,172);c.lineTo(100,188);c.moveTo(350,172);c.lineTo(350,188);c.stroke();c.font='14px Arial';c.fillText('12\'-6 1/2"',200,160);
          c.font='16px Arial';c.fillText('A-101  |  SYNTHETIC FLOOR PLAN',90,560);c.restore();return {promise:Promise.resolve()};}
      })})})};
      window.gsRun=async(method,args)=>{if(method==='api_setSheetScale'){window.__scale={feetPerPoint:args[2],scaleLabel:args[3]};window.__scaleCalls.push(window.__scale);return {success:true,...window.__scale};}
        return {success:true,canManage:true,sheetNo:'A-101',marks:[],sheet:{id:1,pdfUrl:'/fixture.pdf',pageNo:1,widthPt:800,heightPt:600,...window.__scale}};};
      window.AdminUI={toast:()=>{}};
    });
    for(const path of ['public/js/drawing-studio-core.js','public/js/drawing-studio.js'])await page.addScriptTag({path});
    await page.addScriptTag({content:fs.readFileSync('public/js/drawing-marker.js','utf8').replace('import(PDFJS)','Promise.resolve(window.__pdfjsMock)')});
    const open=async()=>{await page.evaluate(()=>DrawingMarker.open({siteId:1,sheetNo:'A-101'}));await page.waitForFunction(()=>document.querySelector('.ds-overlay')?.getAttribute('width'));await page.locator('[data-studio]').click();await page.locator('.ds-panel [data-save]').waitFor();};
    const click=async(x,y)=>{const b=await page.locator('.ds-overlay').boundingBox();await page.mouse.click(b.x+x*b.width,b.y+y*b.height);};
    const tool=async name=>{const button=page.locator('.ds-toolbar [data-tool="'+name+'"]');if(!await button.isVisible())await page.locator('.ds-more-tools summary').click();await button.click();};
    const expand=async name=>{const section=page.locator('[data-panel-section="'+name+'"]');if(!await section.getAttribute('open')&&await section.evaluate(el=>!el.open))await section.locator('summary').click();};
    const count=()=>page.locator('.ds-overlay [data-entity]').count();
    await open();
    assert.equal(await page.locator('[data-unit]').inputValue(),'ft');
    fs.mkdirSync('test-results/assistant-browser',{recursive:true});
    const readDimension=async()=>{if(await page.locator('.ds-panel [data-read-scale]').isVisible())await page.locator('[data-read-scale]').click();else{await page.locator('[data-scale]').click();await page.locator('[data-read-dimension]').click();}await click(.23,.23);await click(.43,.29);await page.locator('[data-dimension-choice], [data-retry]').first().waitFor();};
    await readDimension();assert.equal(ocrCalls,1);assert.equal(await page.evaluate(()=>window.__scaleCalls.length),0);
    assert.match(await page.locator('[data-dimension-choice]').textContent(),/12\.541667 ft/);
    await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-dimension-read.png'});
    await page.locator('[data-dimension-choice]').click();await click(.125,.3);await click(.4375,.3);
    assert.equal(await page.locator('[data-len]').inputValue(),'12\'-6 1/2"');
    await page.locator('.dm-form [data-save]').click();await page.locator('.ds-panel [data-unit]').waitFor();
    assert.equal(await page.locator('[data-unit]').inputValue(),'ft');
    const calibrated=await page.evaluate(()=>window.__scale.feetPerPoint);
    assert.ok(Math.abs(calibrated-(12+6.5/12)/250)<1e-7,'DOM pointer coordinates retain subpixel precision: '+calibrated);
    await page.evaluate(()=>window.__pdfText.push({str:"12'",width:25,height:14,transform:[14,0,0,14,200,440]},{str:'-6 1/2"',width:75,height:14,transform:[14,0,0,14,225,440]}));
    await readDimension();assert.equal(ocrCalls,1,'Native PDF text must not trigger an OCR request');
    await page.locator('.dm-form [data-cancel]').click();await page.evaluate(()=>window.__pdfText.splice(0));
    ocrLabels=[];await readDimension();assert.equal(ocrCalls,2);assert.equal(await page.locator('[data-dimension-choice]').count(),0);
    assert.equal(await page.evaluate(()=>window.__scaleCalls.length),1,'Unreadable labels must never change scale');
    await page.locator('.dm-form [data-cancel]').click();await page.locator('[data-studio]').click();
    await page.locator('[data-unit]').selectOption('m');
    await tool('dimension');await click(.25,.2);await click(.5,.2);assert.equal(await count(),1);
    await page.locator('[data-length]').fill('5');await page.locator('[data-transform]').click();
    assert.match(await page.locator('.ds-overlay').textContent(),/5.00 m/);
    await tool('rect');await click(.4,.4);await click(.65,.65);assert.equal(await count(),2);
    await page.locator('[data-size-width]').fill('3000 mm');await page.locator('[data-size-height]').fill('2 m');await page.locator('[data-transform]').click();
    assert.match(await page.locator('.ds-measurement').textContent(),/6.00 m²/);
    await page.locator('[data-size-width]').fill('bad');await page.locator('[data-transform]').click();
    assert.match(await page.locator('[data-status]').textContent(),/0보다 큰/);
    assert.match(await page.locator('.ds-measurement').textContent(),/6.00 m²/);
    await expand('style');
    await page.locator('[data-style="fill"]').fill('#38bdf8');await page.locator('[data-style="opacity"]').fill('0.35');
    await tool('arrow');await click(.7,.3);await click(.6,.4);assert.equal(await count(),3);
    await page.locator('[data-undo]').click();assert.equal(await count(),2);await page.locator('[data-redo]').click();assert.equal(await count(),3);
    await tool('text');await page.locator('[data-text]').fill('배관 경로 검토');await click(.3,.75);assert.equal(await count(),4);
    await expand('layers');await page.locator('[data-add-layer]').click();await page.locator('[data-ortho]').click();await tool('cloud');await click(.7,.6);await click(.84,.8);assert.equal(await count(),5);
    const cloud=await page.locator('.ds-overlay [data-entity]').last().locator('path').evaluate(el=>{const b=el.getBBox(),svg=el.ownerSVGElement;return {path:el.getAttribute('d'),width:b.width/Number(svg.getAttribute('width')),height:b.height/Number(svg.getAttribute('height'))};});
    assert.match(cloud.path,/ A /);assert.ok(cloud.width>.14&&cloud.height>.2,'Round arcs must bulge outward; Ortho must not collapse the cloud');
    await page.locator('[data-ortho]').click();
    await page.locator('[data-layer]').last().locator('[data-visible]').click();assert.equal(await count(),4);
    await page.locator('[data-layer]').last().locator('[data-visible]').click();assert.equal(await count(),5);
    if(await page.locator('[data-save]').isEnabled())await page.locator('[data-save]').click();await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('버전 '));assert.ok(revision>=1);
    assert.equal(document.entities.length,5);assert.equal(document.layers.length,2);
    fs.mkdirSync('test-results/assistant-browser',{recursive:true});
    await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-drawing-studio.png'});
    await page.locator('[data-close]').click();await open();assert.equal(await count(),5);assert.match(await page.locator('.ds-overlay').textContent(),/5.00 m/);const savedRevision=revision;
    await expand('layers');
    await tool('select');await click(.5,.5);
    const rectangle=page.locator('.ds-entity > rect').first(),xBefore=await rectangle.getAttribute('x');
    const drag=async()=>{const b=await page.locator('.ds-overlay').boundingBox();await page.mouse.move(b.x+.5*b.width,b.y+.5*b.height);await page.mouse.down();await page.mouse.move(b.x+.56*b.width,b.y+.54*b.height,{steps:5});await page.mouse.up();};
    await page.locator('[data-layer]').first().locator('[data-lock]').click();await drag();assert.equal(await rectangle.getAttribute('x'),xBefore);
    await page.locator('[data-layer]').first().locator('[data-lock]').click();await drag();assert.notEqual(await rectangle.getAttribute('x'),xBefore);
    await page.keyboard.press('Control+z');assert.equal(await rectangle.getAttribute('x'),xBefore);
    await page.locator('[data-zin]').click();const transform=await page.locator('[data-world]').getAttribute('style');
    assert.match(await page.locator('.ds-overlay').textContent(),/5.00 m/);
    await page.locator('[data-share-studio]').click();assert.equal(await page.locator('[data-world]').getAttribute('style'),transform);
    await page.locator('[data-body]').fill('검토 요청 — 이 메시지를 유지하세요');const saved=revision>savedRevision?null:page.waitForResponse(r=>r.url().endsWith('/studio')&&r.request().method()==='PUT');await page.locator('[data-body]').press('Control+s');if(saved)await saved;
    assert.equal(await page.locator('[data-body]').inputValue(),'검토 요청 — 이 메시지를 유지하세요');assert.ok(revision>savedRevision);
    await page.locator('[data-scope]').selectOption('full');
    await page.locator('[data-preview]').click();await page.locator('.ds-preview').waitFor();assert.equal(dispatches.length,0);
    await page.locator('[data-room]').selectOption('7');await page.waitForFunction(()=>document.querySelector('[data-chat]').textContent.includes('변경 위치'));
    await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-drawing-share.png'});
    await page.locator('[data-send]').click();await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('전송했습니다'));
    assert.equal(dispatches.length,1);assert.ok(dispatches[0].includes(Buffer.from('image/png')));assert.equal(await page.locator('[data-send]').isDisabled(),true);
    await page.locator('[data-format]').selectOption('pdf');await page.locator('[data-preview]').click();await page.locator('.ds-preview').waitFor();
    await page.locator('[data-channel]').selectOption('mail');await page.locator('[data-to]').fill('synthetic@example.org');await page.locator('[data-send]').click();
    await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('메일 서버'));
    assert.equal(dispatches.length,2);assert.ok(dispatches[1].includes(Buffer.from('%PDF-1.4')));
    await page.locator('[data-edit]').click();
    await page.locator('.ds-panel details').filter({has:page.locator('[data-object]')}).locator('summary').click();
    await page.locator('[data-object]').first().click();
    const coordinatesBefore=JSON.stringify(document.entities[0].points);
    await page.locator('[data-selected-scale]').click();
    await page.locator('[data-len]').fill('12\'-6"');
    assert.match(await page.locator('[data-scale-preview]').textContent(),/12.5 ft/);
    await page.locator('.dm-form [data-save]').click();
    await page.locator('.ds-panel [data-selected-scale]').waitFor();
    assert.equal(JSON.stringify(document.entities[0].points),coordinatesBefore,'Reference calibration must not resize the source line');
    assert.match(await page.locator('.ds-overlay').textContent(),/16.40 ft/,'Previously recorded measurements retain their calibration until explicitly changed');
    await expand('precision');page.once('dialog',d=>d.accept());await page.locator('[data-recalibrate]').click();
    assert.match(await page.locator('.ds-overlay').textContent(),/12.50 ft/);
    await page.locator('[data-length]').fill('6′-3″');await page.locator('[data-transform]').click();
    assert.match(await page.locator('.ds-overlay').textContent(),/6.25 ft/);
    await page.locator('[data-undo]').click();
    assert.match(await page.locator('.ds-overlay').textContent(),/12.50 ft/);
    await page.locator('[data-fit]').click();await tool('line');await click(.35,.55);await click(.5,.6);conflict=true;
    await page.locator('[data-save]').click();await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('다른 창'));
    assert.equal(await count(),6);assert.equal(document.entities.length,5);assert.equal(await page.locator('[data-save]').isDisabled(),true);
    await page.setViewportSize({width:1024,height:768});await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-drawing-1024.png'});
    assert.deepEqual(errors,[]);console.log(JSON.stringify({passed:true,entities:document.entities.length,dispatches:dispatches.length,revision,errors}));
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
