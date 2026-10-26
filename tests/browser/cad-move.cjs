const {chromium,expect}=require('@playwright/test');
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const box={};box.window=box;vm.createContext(box);for(const n of ['cad-core','cad-fidelity'])vm.runInContext(fs.readFileSync('public/js/'+n+'.js','utf8'),box);const C=box.CADCore;
const source='0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n9\n$INSUNITS\n70\n4\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nPOINT\n10\n0\n20\n0\n0\nPOINT\n10\n200\n20\n120\n0\nENDSEC\n0\nEOF\n';
const line=(x,y,X,Y)=>C.newEntity('LINE',{p:{x,y},q:{x:X,y:Y}});
const rect=(x,y,w,h)=>C.newEntity('LWPOLYLINE',{p:{x,y},points:[{x,y},{x:x+w,y},{x:x+w,y:y+h},{x,y:y+h}],closed:true});
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL||undefined});let page;
try{
    page=await browser.newPage({viewport:{width:1500,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
    let drawing={id:9,title:'SYNTHETIC Move 2D',revision:1,canManage:true,canExport:true,dxf:source,workspace:{},markups:[]},writes=0,readonly=false;
    const scripts=['cad-core','cad-fidelity','cad-markup','cad-markup-tools','cad-move-tools','cad-sketch-tools','cad-editor'];
    const html=fs.readFileSync('resources/views/cad/index.blade.php','utf8').replace(/<script[^>]*>[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'<link rel="stylesheet" href="/cad-editor.css">').replace('{{ csrf_token() }}','synthetic').replaceAll('{{ \\App\\Support\\Org::name() }}','SYNTHETIC ERP').replace('</body>',scripts.map(n=>`<script src="/${n}.js"></script>`).join('')+'</body>');
    await page.route('https://synthetic.test/**',async route=>{const u=new URL(route.request().url()),method=route.request().method();let json;
        if(u.pathname==='/cad')return route.fulfill({contentType:'text/html',body:html});
        if(scripts.some(n=>u.pathname==='/'+n+'.js'))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+u.pathname,'utf8')});
        if(u.pathname==='/cad-editor.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync('public/css/cad-editor.css','utf8')});
        if(u.pathname==='/admin-api/cad/capabilities')json={dwgRead:false,dwgWrite:false};
        else if(u.pathname==='/admin-api/cad')json={sites:[],drawings:[drawing],canManage:!readonly};
        else if(u.pathname==='/admin-api/cad/9'&&method==='PUT'){writes++;drawing={...drawing,...route.request().postDataJSON(),revision:drawing.revision+1};json=drawing;}
        else if(u.pathname==='/admin-api/cad/9')json={...drawing,canManage:!readonly};else return route.fulfill({status:404,json:{message:'not found'}});
        return route.fulfill({json});
    });
    await page.goto('https://synthetic.test/cad?drawing_id=9');await expect(page.locator('#status')).toContainText('SYNTHETIC Move');
    await page.locator('[data-sketch-command="move"]').click();
    let bounds,scale;const frame=async()=>{bounds=await page.locator('#canvas').boundingBox();scale=.85*Math.min(bounds.width/200,bounds.height/120);};await frame();
    const screen=(x,y)=>({x:bounds.x+bounds.width/2+(x-100)*scale,y:bounds.y+bounds.height/2-(y-60)*scale});
    const move=async(x,y)=>{const p=screen(x,y);await page.mouse.move(p.x,p.y,{steps:4});};
    const click=async(x,y,button='left')=>{const p=screen(x,y);await page.mouse.click(p.x,p.y,{button});};
    const key=async k=>{await page.locator('#canvas').focus();await page.keyboard.press(k);};
    const numeric=async value=>{await page.locator('#sketch-value').fill(value);await page.locator('#sketch-value').press('Enter');};
    const save=async()=>{const rev=drawing.revision;await page.locator('#save').click();await expect(page.locator('#dirty')).toHaveText(`개정 ${rev+1} 저장됨`);};
    const entities=()=>JSON.parse(JSON.stringify(C.parse(drawing.dxf).entities.filter(e=>e.type!=='POINT')));
    const near=(a,b)=>assert.ok(Math.abs(a-b)<.3,`${a} != ${b}`);
    const menu=label=>page.locator('#sketch-menu').getByRole('menuitem',{name:label,exact:true}).click();
    const reset=async(es=[line(10,10,30,10)])=>{await key('Escape');await key('Space');const d=C.parse(source);d.entities.push(...es);drawing={...drawing,dxf:C.exportDXF(d),revision:drawing.revision+1};await page.locator('#reload').click();await expect(page.locator('#dirty')).toHaveText(`개정 ${drawing.revision}`);await expect(page.locator('#reload')).toBeEnabled();await page.locator('#snap').uncheck();await page.locator('#direction-inference').uncheck();await frame();};
    const select=async(x=20,y=10)=>{await key('Space');await click(x,y);await key('m');};

    await reset();await select();await expect(page.locator('#measurement-hud')).toBeHidden();await click(10,10);await move(20,10);await numeric('-5mm');await numeric('<20,10,0>');await save();near(entities()[0].p.x,30);near(entities()[0].p.y,20);
    await key('Control+z');await save();near(entities()[0].p.x,10);near(entities()[0].p.y,10);await key('Control+y');await save();near(entities()[0].p.x,30);
    // Ctrl shortcuts must not toggle copy mode when their modifier is released.
    await reset();await select();await key('Control+c');await expect(page.locator('#sketch-copy-state')).toHaveText('이동');
    await click(10,10);await move(30,10);await numeric('[50,20,0]');await save();near(entities()[0].p.x,50);near(entities()[0].p.y,20);
    // Click-drag-release and click-move-click share a single operation.
    await reset();await select();await move(10,10);await page.mouse.down();await move(20,20);await page.mouse.up();await save();near(entities()[0].p.x,20);near(entities()[0].p.y,20);
    await reset();await select();await page.keyboard.down('Control');await click(10,10);await click(30,10);await page.keyboard.up('Control');await save();assert.equal(entities().length,2);near(entities()[0].p.x,10);near(entities()[1].p.x,30);
    await reset();await select();await click(10,10);await move(40,20);await click(40,20,'right');await menu('X축 방향 고정');await key('1');await page.keyboard.type('0mm');await page.keyboard.press('Enter');await save();near(entities()[0].p.x,20);near(entities()[0].p.y,10);

    await reset();await select();await key('Control');await click(10,10);await move(20,10);await click(20,10);await numeric('5x');await numeric('3x');await save();assert.equal(entities().length,4);assert.deepEqual(entities().map(e=>Math.round(e.p.x)),[10,20,30,40]);
    await key('Control+z');await save();assert.equal(entities().length,1);await key('Control+y');await save();assert.equal(entities().length,4);
    await reset();await select();await key('Control');await click(10,10);await click(50,10);await numeric('4/');await numeric('80mm');await save();assert.deepEqual(entities().map(e=>Math.round(e.p.x)),[10,30,50,70,90]);
    await click(100,90,'right');await menu('반복 배열');await expect(page.locator('#sketch-value')).toBeFocused();await numeric('2x');await save();assert.deepEqual(entities().map(e=>Math.round(e.p.x)),[10,90,170]);

    await reset();await select();await key('Control');await key('Control');await expect(page.locator('#sketch-copy-state')).toHaveText('연속복사');await click(10,10);await click(30,20);await click(50,30);await click(70,40);await key('Escape');await save();assert.equal(entities().length,4);
    await key('Control+z');await save();assert.equal(entities().length,3);await key('Control+y');await save();assert.equal(entities().length,4);
    // Starting a move and pressing Undo only cancels that unfinished move.
    await key('m');await click(70,40);await move(80,50);await key('Control+z');assert.equal(entities().length,4);await expect(page.locator('#dirty')).toHaveText(`개정 ${drawing.revision} 저장됨`);

    await reset([line(10,10,30,10),line(100,80,140,100)]);await select();await click(10,10);await move(110,85);await key('ArrowDown');await move(50,50);await numeric('20mm');await save();near(entities()[0].p.x,10+20/Math.sqrt(1.25));near(entities()[0].p.y,10+10/Math.sqrt(1.25));
    await reset([line(10,10,30,10),line(100,80,140,100)]);await select();await click(10,10);await move(110,85);await key('ArrowDown');await key('ArrowDown');await move(0,30);await numeric('20mm');await save();near(entities()[0].p.x,10-10/Math.sqrt(1.25));near(entities()[0].p.y,10+20/Math.sqrt(1.25));
    // Shift retains an arbitrary diagonal, not merely whichever axis is nearest.
    await reset();await select();await click(10,10);await move(30,20);await page.keyboard.down('Shift');await move(60,70);await click(60,70);await page.keyboard.up('Shift');await save();near((entities()[0].p.y-10)/(entities()[0].p.x-10),.5);
    await reset();await select();await click(10,10);await key('ArrowLeft');await move(50,40);await numeric('20');await save();near(entities()[0].p.x,10);near(entities()[0].p.y,30);
    // Arbitrary on-edge picking, midpoint/intersection snapping, and axis-reference alignment.
    await reset([line(10,10,30,10),line(100,70,140,90),line(120,60,120,100)]);await page.locator('#snap').check();await select();await click(10,10);await click(120.3,79.8);await save();near(entities()[0].p.x,120);near(entities()[0].p.y,80);

    await reset([line(10,10,30,10),line(30,10,30,30)]);await key('m');await click(30,10);await move(40,20);await numeric('<10,10>');await save();near(entities()[0].p.x,10);near(entities()[0].q.x,40);near(entities()[1].p.x,40);near(entities()[1].q.x,30);
    await reset([rect(30,30,40,20)]);await key('m');await click(70,40);await numeric('<10,0>');await save();assert.deepEqual(entities()[0].points.map(p=>Math.round(p.x)),[30,80,80,30]);
    const ga=line(20,20,40,20),gb=line(40,20,40,40);ga.sketchGroup=gb.sketchGroup='editable_group';await reset([ga,gb]);await key('Space');await click(30,20,'right');await menu('그룹 편집');await key('m');await click(40,20);await numeric('<10,0>');await save();near(entities()[0].q.x,50);near(entities()[1].p.x,50);near(entities()[1].q.x,40);await key('Space');await key('Escape');await expect(page.locator('.sketch-mode-note')).toHaveText('CAD 원본 편집');
    const locked=line(30,10,30,30);locked.sketchLocked=true;await reset([line(10,10,30,10),locked]);await key('m');await click(30,10);await expect(page.locator('#status')).toContainText('잠금');assert.equal(entities()[0].q.x,30);
    // A grouped object touching raw geometry does not stretch that geometry.
    const grouped=rect(30,30,40,20);grouped.sketchGroup='box';await reset([grouped,line(70,30,90,30)]);await select(50,30);await key('Alt');await click(50,30);await move(60,30);await numeric('10mm');await save();near(entities()[0].points[0].x,40);near(entities()[1].p.x,70);
    // Rotation grips in the Move tool.
    await reset([grouped]);await select(50,30);await click(40,35);await numeric('90');await save();near(C.bounds([entities()[0]]).maxX-C.bounds([entities()[0]]).minX,20);near(C.bounds([entities()[0]]).maxY-C.bounds([entities()[0]]).minY,40);

    // Persistent custom connection points align location and orientation on copy.
    const a=rect(20,20,20,10),b=rect(100,70,20,10);a.sketchGroup='a';b.sketchGroup='b';a.sketchSnaps=[{x:20,y:20,angle:0}];b.sketchSnaps=[{x:100,y:70,angle:90}];
    await reset([a,b]);await select(30,20);await key('Control');await click(20,20);await click(100,70);await save();assert.equal(entities().length,3);near(entities()[2].sketchSnaps[0].x,100);near(entities()[2].sketchSnaps[0].y,70);near(entities()[2].sketchSnaps[0].angle,270);
    await reset([a]);await key('Space');await click(30,20,'right');await menu('연결점 추가');await click(40,25);await click(50,25);await save();assert.equal(entities()[0].sketchSnaps.length,2);await page.locator('#reload').click();await expect(page.locator('#reload')).toBeEnabled();

    await reset();await select();await click(10,10);await move(30,10);await numeric('[1,2,3]');await expect(page.locator('#status')).toContainText('Z');await key('Escape');assert.equal(entities()[0].p.x,10);
    readonly=true;await reset();await key('m');await click(20,10);await expect(page.locator('#status')).toContainText('읽기 전용');
    assert.deepEqual(errors,[]);
    readonly=false;const sample=rect(20,30,16,25);sample.sketchGroup='sample';await reset([sample,line(10,25,180,25)]);await select(28,30);await key('Control');await click(20,30);await move(48,30);await numeric('28mm');await numeric('4x');await save();await key('m');await key('Control');await key('Control');await click(48,30);await move(60,80);
    await page.locator('#tools').evaluate(el=>el.scrollTop=0);
    fs.mkdirSync('test-results/assistant-browser',{recursive:true});await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-move.png',fullPage:true});
    console.log(JSON.stringify({passed:true,writes,preciseMove:true,copyStampArrays:true,connectedStretch:true,gripsAndSnaps:true,readonly:true,errors}));
}catch(e){if(page){fs.mkdirSync('test-results/assistant-browser',{recursive:true});await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-move-failure.png',fullPage:true}).catch(()=>{});console.error(await page.locator('#status').textContent().catch(()=>''));}throw e;}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
