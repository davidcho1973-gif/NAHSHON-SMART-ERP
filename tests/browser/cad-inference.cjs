// Independent pointer/keyboard regression: saved coordinates must match displayed direction constraints.
const {chromium, expect} = require('@playwright/test');
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const context = {window: {}};
vm.runInNewContext(fs.readFileSync('public/js/cad-core.js', 'utf8'), context);
const C = context.window.CADCore;
const near = (a,b) => assert.ok(Math.abs(a-b)<1e-7, `${a} != ${b}`);

(async()=>{
    const browser=await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL||undefined});
    try {
        const page=await browser.newPage({viewport:{width:1500,height:1000}}),errors=[];
        page.on('pageerror',e=>errors.push(e.message));page.setDefaultTimeout(10000);
        const initial=C.parse('0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n9\n$INSUNITS\n70\n4\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n');
        initial.entities.push(C.newEntity('POINT',{p:{x:0,y:0}}),C.newEntity('POINT',{p:{x:200,y:120}}));
        // A reference after the first 2,000 entities must still be discoverable in larger plans.
        for(let i=0;i<2100;i++)initial.entities.push(C.newEntity('LINE',{p:{x:0,y:0},q:{x:1,y:1}}));
        initial.entities.push(C.newEntity('LINE',{p:{x:20,y:20},q:{x:80,y:50}}));
        let drawing={id:9,title:'Direction fixture',site_id:1,revision:1,dxf:C.exportDXF(initial),canManage:true,canAnnotate:true,canExport:true,warnings:[],workspace:{}},writes=0;
        const assets=['cad-core','cad-fidelity','cad-markup','cad-markup-tools','cad-editor'];
        const html=fs.readFileSync('resources/views/cad/index.blade.php','utf8').replace(/<script[^>]*>[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'<link rel="stylesheet" href="/cad-editor.css">').replace('{{ csrf_token() }}','synthetic').replaceAll('{{ \\App\\Support\\Org::name() }}','SYNTHETIC ERP').replace('</body>',assets.map(a=>`<script src="/${a}.js"></script>`).join('')+'</body>');
        await page.route('https://synthetic.test/**',async route=>{
            const path=new URL(route.request().url()).pathname,method=route.request().method();let json;
            if(path==='/cad')return route.fulfill({contentType:'text/html',body:html});
            if(assets.some(a=>path===`/${a}.js`))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+path,'utf8')});
            if(path==='/cad-editor.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync('public/css/cad-editor.css','utf8')});
            if(path==='/admin-api/cad/capabilities')json={dwgRead:false,dwgWrite:false,message:'Synthetic DXF'};
            else if(path==='/admin-api/cad')json={canManage:true,sites:[{id:1,name:'Synthetic'}],drawings:[drawing]};
            else if(path==='/admin-api/cad/9'&&method==='GET')json=drawing;
            else if(path==='/admin-api/cad/9'&&method==='PUT'){const data=route.request().postDataJSON();assert.equal(data.revision,drawing.revision);drawing={...drawing,...data,revision:drawing.revision+1};writes++;json=drawing;}
            else return route.fulfill({status:404,body:'not found'});
            return route.fulfill({contentType:'application/json',body:JSON.stringify(json)});
        });
        await page.goto('https://synthetic.test/cad?drawing_id=9');
        await expect(page.locator('#status')).toContainText('Direction fixture');
        await page.locator('.mode-switch [data-workspace="cad"]').click();
        await expect(page.locator('#direction-inference')).toBeChecked();
        const box=await page.locator('#canvas').boundingBox(),scale=.85*Math.min(box.width/200,box.height/120);
        const screen=(x,y)=>({x:box.x+box.width/2+(x-100)*scale,y:box.y+box.height/2-(y-60)*scale});
        const move=async(x,y)=>{const p=screen(x,y);await page.mouse.move(p.x,p.y,{steps:5});};
        const click=async(x,y)=>{const p=screen(x,y);await page.mouse.click(p.x,p.y);};
        const tool=async name=>page.locator(`[data-tool="${name}"]`).click();
        const save=async()=>{const revision=drawing.revision;await page.locator('#save').click();await expect(page.locator('#dirty')).toHaveText(`개정 ${revision+1} 저장됨`);return C.parse(drawing.dxf).entities.filter(e=>e.type==='LINE').at(-1);};
        const draw=async(a,b)=>{await tool('line');await click(...a);await move(...b);await click(...b);return save();};

        let line=await draw([110,20],[175,21]);near(line.p.y,line.q.y);
        line=await draw([110,40],[111,105]);near(line.p.x,line.q.x);

        // Drag mode uses the same solver as click-click; inference commits rather than only painting a hint.
        await tool('line');await move(125,30);await page.mouse.down();await move(185,31);await page.mouse.up();
        line=await save();near(line.p.y,line.q.y);

        // Existing tilted line establishes a reference for parallel/perpendicular construction.
        line=await draw([20,20],[63,42]);near((line.q.x-line.p.x)*.5,line.q.y-line.p.y);
        line=await draw([80,50],[56,100]);near((line.q.x-line.p.x)*2+(line.q.y-line.p.y),0);

        // Lock must recompute the pending endpoint immediately, without another mousemove.
        await tool('line');await click(130,50);await move(150,66);await page.keyboard.press('ArrowRight');
        await page.keyboard.type('25mm');await page.keyboard.press('Enter');line=await save();
        near(line.q.x-line.p.x,25);near(line.p.y,line.q.y);

        // Exact polar angle overrides approximate mouse inference.
        await tool('line');await click(140,70);await move(180,70);await page.keyboard.type('20mm<80');await page.keyboard.press('Enter');line=await save();
        near(Math.hypot(line.q.x-line.p.x,line.q.y-line.p.y),20);
        near(Math.atan2(line.q.y-line.p.y,line.q.x-line.p.x)*180/Math.PI,80);

        await page.locator('#inference-step').fill('10');
        line=await draw([30,60],[37,100]);near(Math.atan2(line.q.y-line.p.y,line.q.x-line.p.x)*180/Math.PI,80);

        // Holding Shift preserves an arbitrary direction when the pointer later moves away.
        await page.locator('#direction-inference').uncheck();
        await tool('line');await click(70,80);await move(110,100);await page.keyboard.down('Shift');await move(130,90);await click(130,90);await page.keyboard.up('Shift');
        line=await save();near((line.q.x-line.p.x)*.5,line.q.y-line.p.y);

        // Turning inference off is respected for free drawing.
        line=await draw([150,90],[190,91]);assert.ok(Math.abs(line.q.y-line.p.y)>.5);
        await page.locator('#direction-inference').check();await tool('line');await click(25,105);await move(90,106);
        fs.mkdirSync('test-results/assistant-browser',{recursive:true});
        await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-inference.png'});
        await expect(page.locator('#tape-readout')).toContainText('X');
        assert.deepEqual(errors,[]);
        console.log(JSON.stringify({passed:true,writes,errors}));
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
