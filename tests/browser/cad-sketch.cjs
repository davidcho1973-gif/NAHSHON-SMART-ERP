const {chromium,expect}=require('@playwright/test');
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const box={};box.window=box;vm.createContext(box);for(const n of ['cad-core','cad-fidelity'])vm.runInContext(fs.readFileSync('public/js/'+n+'.js','utf8'),box);const C=box.CADCore;
const source='0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n9\n$INSUNITS\n70\n4\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nPOINT\n10\n0\n20\n0\n0\nPOINT\n10\n200\n20\n120\n0\nLINE\n8\n0\n10\n10\n20\n10\n11\n30\n21\n10\n0\nENDSEC\n0\nEOF\n';
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL||undefined});let page;
try{
    page=await browser.newPage({viewport:{width:1500,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
    let drawing={id:9,title:'SYNTHETIC Sketch 2D',site_id:1,revision:1,canManage:true,canExport:true,dxf:source,workspace:{},markups:[]},writes=0,readonly=false;
    const scripts=['cad-core','cad-fidelity','cad-markup','cad-markup-tools','cad-move-tools','cad-sketch-tools','cad-editor'];
    const html=fs.readFileSync('resources/views/cad/index.blade.php','utf8').replace(/<script[^>]*>[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'<link rel="stylesheet" href="/cad-editor.css">').replace('{{ csrf_token() }}','synthetic').replaceAll('{{ \\App\\Support\\Org::name() }}','SYNTHETIC ERP').replace('</body>',scripts.map(n=>`<script src="/${n}.js"></script>`).join('')+'</body>');
    await page.route('https://synthetic.test/**',async route=>{const u=new URL(route.request().url()),method=route.request().method();let json;
        if(u.pathname==='/cad')return route.fulfill({contentType:'text/html',body:html});
        if(scripts.some(n=>u.pathname==='/'+n+'.js'))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+u.pathname,'utf8')});
        if(u.pathname==='/cad-editor.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync('public/css/cad-editor.css','utf8')});
        if(u.pathname==='/admin-api/cad/capabilities')json={dwgRead:false,dwgWrite:false};
        else if(u.pathname==='/admin-api/cad')json={sites:[{id:1,name:'Synthetic'}],drawings:[drawing],canManage:!readonly};
        else if(u.pathname==='/admin-api/cad/9'&&method==='PUT'){writes++;drawing={...drawing,...route.request().postDataJSON(),revision:drawing.revision+1};json=drawing;}
        else if(u.pathname==='/admin-api/cad/9'){await new Promise(resolve=>setTimeout(resolve,150));json={...drawing,canManage:!readonly};}else return route.fulfill({status:404,json:{message:'not found'}});
        return route.fulfill({json});
    });
    await page.goto('https://synthetic.test/cad?drawing_id=9');await expect(page.locator('#status')).toContainText('SYNTHETIC Sketch');
    // Toolbar entry activates native CAD mode, even from the default markup workspace.
    await page.locator('[data-sketch-command="rectangle"]').click();await expect(page.locator('body')).toHaveAttribute('data-workspace','cad');
    await page.locator('#snap').uncheck();await page.locator('#direction-inference').uncheck();
    let bounds,scale;const frame=async()=>{bounds=await page.locator('#canvas').boundingBox();scale=.85*Math.min(bounds.width/200,bounds.height/120);};await frame();
    const screen=(x,y)=>({x:bounds.x+bounds.width/2+(x-100)*scale,y:bounds.y+bounds.height/2-(y-60)*scale});
    const move=async(x,y)=>{const p=screen(x,y);await page.mouse.move(p.x,p.y,{steps:4});};
    const click=async(x,y,button='left')=>{const p=screen(x,y);await page.mouse.click(p.x,p.y,{button});};
    const key=async k=>{await page.locator('#canvas').focus();await page.keyboard.press(k);};
    const numeric=async value=>{await page.locator('#sketch-value').fill(value);await page.locator('#sketch-value').press('Enter');};
    const save=async()=>{const rev=drawing.revision;await page.locator('#save').click();await expect(page.locator('#dirty')).toHaveText(`개정 ${rev+1} 저장됨`);};
    const es=()=>C.parse(drawing.dxf).entities,polys=()=>es().filter(e=>e.type==='LWPOLYLINE');
    const near=(x,y)=>assert.ok(Math.abs(x-y)<.2,`${x} != ${y}`);
    const menu=label=>page.locator('#sketch-menu').getByRole('menuitem',{name:label,exact:true}).click();
    await click(50,40);await click(90,60);await save();assert.equal(polys().length,1);
    await key('Space');await click(70,40);await expect(page.locator('#selection')).toHaveText('선택 1개');
    await key('m');await click(50,40);await move(60,40);await numeric('10mm');await save();near(C.bounds(polys()).minX,60);
    await key('q');await click(60,40);await click(100,40);await move(60,80);await numeric('90');await save();near(C.bounds(polys()).minX,40);near(C.bounds(polys()).maxY,80);
    await key('s');await click(40,40);await move(30,20);await numeric('1.5');await save();near(C.bounds(polys()).maxX-C.bounds(polys()).minX,30);near(C.bounds(polys()).maxY-C.bounds(polys()).minY,60);
    await key('f');await click(30,50);await move(25,50);await numeric('5mm');await save();assert.equal(polys().length,2);near(C.bounds([polys()[1]]).minX,25);
    await key('Control+z');await save();assert.equal(polys().length,1);await key('Control+y');await save();assert.equal(polys().length,2);
    // Ctrl toggles copy for a whole move; copies receive independent groups later.
    await key('m');await click(25,50);await key('Control');await move(45,50);await numeric('20mm');await save();assert.equal(polys().length,3);
    // Context operations select the clicked shape and use the same undo transaction.
    await key('Space');await click(45,50,'right');await menu('그룹 만들기');await save();const group=polys().find(e=>e.sketchGroup);assert.ok(group);
    await click(45,50,'right');await menu('잠금');await save();assert.ok(polys().some(e=>e.sketchLocked));
    await page.locator('#reload').click();await expect(page.locator('#dirty')).toHaveText(`개정 ${drawing.revision}`);await expect(page.locator('#reload')).toBeEnabled();await frame();
    await click(45,50,'right');await expect(page.locator('#sketch-menu').getByRole('menuitem',{name:'지우기',exact:true})).toBeDisabled();await menu('잠금 해제');await save();assert.ok(!polys().some(e=>e.sketchLocked));
    await click(45,50,'right');await menu('숨기기');await save();assert.ok(polys().some(e=>e.hidden));
    await click(150,100,'right');await menu('숨김 모두 표시');await save();assert.ok(!polys().some(e=>e.hidden));
    await key('Space');await click(45,50);await key('Control+c');await expect(page.locator('#status')).toContainText('복사됨');await key('Control+Shift+v');await save();assert.equal(polys().length,4);assert.equal(new Set(polys().filter(e=>e.sketchGroup).map(e=>e.sketchGroup)).size,2);
    // Native arc and polygon creation, not markup-only approximations.
    await key('a');await click(110,80);await click(130,80);await move(120,90);await click(120,90);await save();const a=es().find(e=>e.type==='ARC');near(a.radius,10);
    await page.locator('.sketch-tool-family').filter({has:page.locator('[data-sketch-command="rectangle"]')}).locator('.sketch-more').click();await menu('다각형');await click(150,40);await move(160,40);await numeric('10');await save();assert.equal(polys().at(-1).points.length,6);
    await key('b');await page.locator('#default-color').fill('#ee5533');await click(150,40);await save();assert.equal(es().filter(e=>e.type==='HATCH').length,1);assert.equal(es().find(e=>e.type==='HATCH').color,0xee5533);
    await key('c');await click(175,100);await click(183,100);await save();await key('b');await click(175,100);await save();assert.equal(es().filter(e=>e.type==='HATCH').length,2);assert.ok(es().filter(e=>e.type==='HATCH').at(-1).raw.some(p=>p[0]===42&&Number(p[1])!==0),'circular paint retains exact bulge arcs');
    await page.locator('.sketch-tool-family').filter({has:page.locator('[data-sketch-command="line"]')}).locator('.sketch-more').click();await menu('자유형');await move(110,20);await page.mouse.down();await move(120,25);await move(130,20);await page.mouse.up();await save();assert.equal(polys().at(-1).points.length>2,true);
    await key('Space');await move(105,30);await page.mouse.down();await move(135,15);await page.mouse.up();await expect(page.locator('#selection')).toHaveText('선택 1개');
    await key('Shift+Space');await move(105,15);await page.mouse.down();await move(135,15);await move(135,30);await move(105,30);await move(105,15);await page.mouse.up();await expect(page.locator('#selection')).toHaveText('선택 1개');
    await key('e');await page.keyboard.down('Shift');await click(120,25);await page.keyboard.up('Shift');await save();assert.ok(polys().at(-1).hidden);await key('Control+z');await save();assert.ok(!polys().at(-1).hidden);
    // Middle-button navigation works while a custom editing tool is selected.
    await key('q');await move(150,100);await page.mouse.down({button:'middle'});await page.mouse.move(screen(150,100).x+40,screen(150,100).y+20);await page.mouse.up({button:'middle'});await key('Shift+z');await frame();
    // Right click must open a menu without panning, and native inputs must keep typing.
    await click(150,100,'right');await expect(page.locator('#sketch-menu')).toBeVisible();await page.keyboard.press('Escape');await expect(page.locator('#sketch-menu')).toBeHidden();
    await page.locator('#x').fill('123');await page.locator('#x').press('Control+a');await page.locator('#x').press('r');await expect(page.locator('#x')).not.toHaveValue('123');
    await key('Shift+s');await expect(page.locator('#sketch-search')).toBeVisible();await page.locator('#sketch-search-query').fill('줄자');await page.locator('#sketch-search-results button').click();await expect(page.locator('[data-sketch-command="tape"]')).toHaveAttribute('aria-pressed','true');
    fs.mkdirSync('test-results/assistant-browser',{recursive:true});await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-sketch.png',fullPage:true});
    await click(150,100,'right');await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-sketch-menu.png',fullPage:true});await page.keyboard.press('Escape');
    readonly=true;await page.locator('#reload').click();await expect(page.locator('#reload')).toBeEnabled();await frame();await key('r');await click(120,10);await click(130,20);await expect(page.locator('#status')).toContainText('읽기 전용');
    const count=writes;await key('e');await click(45,50);await expect(page.locator('#status')).toContainText('읽기 전용');assert.equal(writes,count);
    assert.deepEqual(errors,[]);console.log(JSON.stringify({passed:true,writes,toolbar:true,transform:true,contextPersistence:true,readonly:true,errors}));
}catch(e){if(page){fs.mkdirSync('test-results/assistant-browser',{recursive:true});await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-sketch-failure.png',fullPage:true}).catch(()=>{});console.error(await page.locator('#status').textContent().catch(()=>''));}throw e;}
finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
