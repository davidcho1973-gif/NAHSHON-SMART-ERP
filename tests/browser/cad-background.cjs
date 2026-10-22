// Isolated fixture: actual canvas pixels, editing and personal display preferences.
const {chromium, expect} = require('@playwright/test');
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL || undefined});
    try {
        const page = await browser.newPage({viewport:{width:1500,height:1000}}), errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.setDefaultTimeout(10000);
        const context = {window:{}};
        vm.runInNewContext(fs.readFileSync('public/js/cad-core.js','utf8'),context);
        const C = context.window.CADCore;
        const doc = C.parse('0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n');
        doc.entities.push(C.newEntity('POINT',{p:{x:0,y:0}}),C.newEntity('POINT',{p:{x:200,y:120}}));
        for (const [color,y] of [[0xffffff,20],[0xffff00,40],[0xff0000,60]])
            doc.entities.push(C.newEntity('LINE',{p:{x:20,y},q:{x:80,y},color}));
        doc.entities.push(C.newEntity('TEXT',{p:{x:20,y:80},height:6,text:'WHITE TEXT',color:0xffffff}));
        let drawing = {id:9,title:'SYNTHETIC 배경 전환',site_id:1,revision:1,canManage:true,canExport:true,dxf:C.exportDXF(doc),markups:[],workspace:{}};
        const original = drawing.dxf, raw = JSON.stringify(C.parse(original).entities.map(e => e.raw));
        let writes = 0, owner = 'synthetic-user';
        const scripts = ['cad-core','cad-fidelity','cad-markup','cad-review','cad-markup-tools','cad-editor'];
        const html = fs.readFileSync('resources/views/cad/index.blade.php','utf8')
            .replace(/<script[^>]*>[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'<link rel="stylesheet" href="/cad-editor.css">')
            .replace('{{ csrf_token() }}','synthetic').replaceAll('{{ \\App\\Support\\Org::name() }}','SYNTHETIC ERP')
            .replace('</body>',scripts.map(name => `<script src="/${name}.js"></script>`).join('')+'</body>');
        await page.context().route('https://synthetic.test/**', async route => {
            const path = new URL(route.request().url()).pathname;
            if(path === '/cad')return route.fulfill({contentType:'text/html',body:html.replaceAll('{{ auth()->id() }}',owner)});
            if(path === '/cad-editor.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync('public/css/cad-editor.css','utf8')});
            if(/^\/cad-[a-z-]+\.js$/.test(path))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+path,'utf8')});
            let json;
            if(path === '/admin-api/cad/capabilities')json = {dwgRead:false,dwgWrite:false};
            else if(path === '/admin-api/cad')json = {sites:[{id:1,name:'SYNTHETIC'}],drawings:[drawing],canManage:true};
            else if(path === '/admin-api/cad/9'){
                if(route.request().method() === 'PUT') {drawing = {...drawing,...route.request().postDataJSON(),revision:drawing.revision+1};writes++;}
                json = drawing;
            } else return route.fulfill({status:404,body:'Not part of fixture'});
            return route.fulfill({json});
        });
        const open = async () => {await page.goto('https://synthetic.test/cad?drawing_id=9');await expect(page.locator('#status')).toContainText(drawing.title);await page.locator('#grid').uncheck();};
        const background = page.getByLabel('도면 배경',{exact:true});
        const pixel = (x,y) => page.locator('#canvas').evaluate((canvas,{x,y}) => Array.from(canvas.getContext('2d').getImageData(Math.round(x*canvas.width/canvas.clientWidth),Math.round(y*canvas.height/canvas.clientHeight),1,1).data),{x,y});
        await open();
        await expect(background).toHaveValue('dark');assert.deepEqual(await pixel(5,5),[10,16,32,255]);
        const clean = await page.locator('#dirty').innerText();
        await background.selectOption('white');assert.deepEqual(await pixel(5,5),[255,255,255,255]);
        await expect(page.locator('#dirty')).toHaveText(clean);
        await page.locator('#save').click();assert.equal(writes,0);assert.equal(drawing.dxf,original);
        // White and yellow CAD strokes remain visible at their original coordinates.
        const box = await page.locator('#canvas').boundingBox(), scale = .85*Math.min(box.width/200,box.height/120);
        const screen = (x,y) => ({x:box.width/2+(x-100)*scale,y:box.height/2-(y-60)*scale});
        for(const y of [20,40,60]){
            const p = screen(50,y);const samples = await Promise.all([-1,0,1].map(d => pixel(p.x,p.y+d)));
            assert.ok(samples.some(p => Math.min(...p.slice(0,3))<180),`visible stroke at ${y}`);
        }
        const a = screen(20,87), z = screen(80,78);
        assert.ok(await page.locator('#canvas').evaluate((canvas,{a,z}) => {
            const data = canvas.getContext('2d').getImageData(a.x,a.y,z.x-a.x,z.y-a.y).data;
            let ink = 0;for(let i=0;i<data.length;i+=4)if(data[i]<180)ink++;return ink>40;
        },{a,z}),'white native text remains visible');
        // Theme changes preserve a pending native gesture and never consume Undo.
        await page.locator('button[data-workspace="cad"]').click();await page.locator('#snap').uncheck();
        await page.locator('[data-tool="line"]').click();
        const click = async (x,y) => {const p=screen(x,y);await page.mouse.click(box.x+p.x,box.y+p.y);};
        await click(120,30);await background.selectOption('dark');await click(160,30);
        await background.selectOption('white');await page.locator('#undo').click();await expect(page.locator('#status')).toContainText('6개 객체');
        await page.locator('#redo').click();await page.locator('#save').click();await expect(page.locator('#dirty')).toHaveText('개정 2 저장됨');
        assert.equal(writes,1);assert.equal(JSON.stringify(C.parse(drawing.dxf).entities.slice(0,6).map(e=>e.raw)),raw);
        await open();await expect(background).toHaveValue('white');assert.deepEqual(await pixel(5,5),[255,255,255,255]);
        // Area/highlighter colors stay literal; clouds and transparent text gain contrast.
        const graphics = await page.evaluate(dxf => {
            const canvas = document.createElement('canvas');canvas.width=300;canvas.height=200;const ctx=canvas.getContext('2d');
            const sample=()=>Array.from(ctx.getImageData(40,40,1,1).data);
            const M=window.CADMarkup, C=window.CADCore;
            const item={kind:'area',points:M.rect({x:20,y:20},{x:80,y:80}),color:'#ffff00',opacity:1,width:2,size:10,pattern:'solid'};
            const options={background:'white',color:color=>C.canvasColor(color,'white')};
            ctx.fillStyle='#fff';ctx.fillRect(0,0,300,200);M.draw(ctx,item,p=>p,1,options);const area=sample();
            const paint = item => {ctx.fillStyle='#fff';ctx.fillRect(0,0,300,200);M.draw(ctx,item,p=>p,1,options);};
            paint({...item,kind:'highlight',width:12,points:[{x:20,y:40},{x:80,y:40}]});const highlight=sample();
            const ink = () => {const data=ctx.getImageData(0,0,300,200).data;let count=0;for(let i=0;i<data.length;i+=4)if(data[i]<180)count++;return count;};
            const cloud={...item,kind:'cloud',color:'#ffffff'};const original=JSON.stringify(cloud);
            paint(cloud);const cloudInk=ink(),unchanged=JSON.stringify(cloud)===original;
            paint({...item,kind:'text',color:'#ffffff',text:'VISIBLE',textStyle:{background:'none',border:false},points:[{x:20,y:20}]});const textInk=ink();
            ctx.fillStyle='#000';ctx.fillRect(0,0,300,200);
            M.draw(ctx,{...item,kind:'detail',color:'#ffffff',points:M.rect({x:0,y:0},{x:200,y:120}),detail:{minX:0,minY:0,maxX:200,maxY:120}},p=>p,1,{...options,doc:C.parse(dxf)});
            const detailBackground=Array.from(ctx.getImageData(150,100,1,1).data);
            const detailLine=Array.from(ctx.getImageData(40,20,1,1).data);
            const print = background => {ctx.clearRect(0,0,300,200);C.renderEntities(ctx,C.parse(dxf),C.parse(dxf).entities,p=>p,1,{paper:true,background});M.draw(ctx,item,p=>p,1,{...options,paper:true,background});return canvas.toDataURL();};
            return {area,highlight,cloudInk,textInk,unchanged,detailBackground,detailLine,samePrint:print('white')===print('dark')};
        },drawing.dxf);
        assert.deepEqual(graphics.area,[255,255,0,255]);assert.deepEqual(graphics.highlight,graphics.area);
        assert.ok(graphics.cloudInk>100);assert.ok(graphics.textInk>40);assert.ok(graphics.unchanged);assert.ok(graphics.samePrint);
        assert.deepEqual(graphics.detailBackground,[255,255,255,255]);assert.ok(graphics.detailLine[0]<180);
        await page.setViewportSize({width:390,height:844});await expect(background).toBeVisible();await background.selectOption('dark');await background.selectOption('white');
        assert.ok(await background.evaluate(el=>el.getBoundingClientRect().right<=innerWidth),'control fits mobile viewport');
        await page.setViewportSize({width:1500,height:1000});
        fs.mkdirSync('test-results',{recursive:true});await page.screenshot({path:'test-results/cad-background-white.png'});
        owner='another-user';await open();await expect(background).toHaveValue('dark');
        owner='synthetic-user';await open();await expect(background).toHaveValue('white');
        await page.evaluate(()=>localStorage.setItem('cad:canvas-background:synthetic-user','invalid'));await open();await expect(background).toHaveValue('dark');
        await page.addInitScript(()=>{Storage.prototype.getItem=Storage.prototype.setItem=()=>{throw new DOMException('blocked','SecurityError');};});
        await open();await background.selectOption('white');assert.deepEqual(await pixel(5,5),[255,255,255,255]);
        assert.deepEqual(errors,[]);console.log('CAD background: pixels, contrast, immutable source, Undo, persistence, user isolation, print, mobile and blocked storage passed.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
