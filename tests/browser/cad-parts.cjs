// Real editor gestures and library UI with synthetic data; authorization is tested in PostgreSQL.
const {chromium,expect}=require('@playwright/test');
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
    const browser=await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL||undefined});
    try{
        const page=await browser.newPage({viewport:{width:1500,height:1000}}),errors=[];
        page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());page.setDefaultTimeout(12000);
        const context={window:{}};vm.runInNewContext(fs.readFileSync('public/js/cad-core.js','utf8'),context);
        const C=context.window.CADCore,doc=C.parse('0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n');
        doc.entities.push(C.newEntity('POINT',{p:{x:0,y:0}}),C.newEntity('POINT',{p:{x:200,y:120}}));
        const original=C.exportDXF(doc);let drawing={id:9,title:'SYNTHETIC PARTS',revision:1,site_id:1,canManage:true,canExport:true,dxf:original,markups:[],workspace:{}};
        const catalog=new Map(),archived=new Set();
        const scripts=['polygon-clipping','cad-core','cad-fidelity','cad-markup','cad-markup-geometry','cad-parts','cad-markup-workbench','cad-recovery','cad-print','cad-review','cad-review-workbench','cad-markup-tools','cad-editor'];
        const html=fs.readFileSync('resources/views/cad/index.blade.php','utf8').replace(/<script[^>]*>[\s\S]*?<\/script>/g,'')
            .replace(/<link[^>]*>/g,'').replace('</head>','<link rel="stylesheet" href="/cad-editor.css"><link rel="stylesheet" href="/cad-parts.css"></head>')
            .replace('{{ csrf_token() }}','synthetic').replaceAll('{{ auth()->id() }}','synthetic-user').replaceAll('{{ \\App\\Support\\Org::name() }}','SYNTHETIC ERP')
            .replace('</body>',scripts.map(n=>`<script src="/${n}.js"></script>`).join('')+'</body>');
        await page.route('https://synthetic.test/**',async route=>{
            const request=route.request(),url=new URL(request.url()),path=url.pathname;let data;
            if(path==='/cad')return route.fulfill({contentType:'text/html',body:html});
            if(path.endsWith('.css'))return route.fulfill({contentType:'text/css',body:fs.readFileSync('public/css'+path,'utf8')});
            if(path==='/polygon-clipping.js')return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/vendor/polygon-clipping/polygon-clipping.js','utf8')});
            if(/^\/cad-[a-z-]+\.js$/.test(path))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+path,'utf8')});
            if(path==='/admin-api/cad/capabilities')data={dwgRead:false,dwgWrite:false};
            else if(path==='/admin-api/cad')data={sites:[{id:1,name:'SYNTHETIC'}],drawings:[drawing],canManage:true};
            else if(path==='/admin-api/cad/9/parts'){
                if(request.method()==='POST'){data={id:require('node:crypto').randomUUID(),...request.postDataJSON()};catalog.set(data.id,data);}
                else data={items:[...catalog.values()].filter(p=>!archived.has(p.id)&&p.name.includes(url.searchParams.get('search')||'')),next:null,company:'SYNTHETIC COMPANY',canManage:true};
            }else if(path.startsWith('/admin-api/cad/9/parts/')){
                const id=path.split('/').at(-1);if(request.method()==='DELETE'){archived.add(id);data={success:true};}else data=catalog.get(id);
            }else if(path==='/admin-api/cad/9'){
                if(request.method()==='PUT'){const payload=request.postDataJSON();assert.equal(payload.revision,drawing.revision);assert.equal(payload.dxf,original);drawing={...drawing,...payload,revision:drawing.revision+1};}data=drawing;
            }else return route.fulfill({status:404,body:'not a fixture'});
            return route.fulfill({json:data});
        });
        await page.goto('https://synthetic.test/cad?drawing_id=9');await expect(page.locator('#status')).toContainText('SYNTHETIC PARTS');
        const open=async()=>{await page.locator('#cad-parts-open').click();await expect(page.locator('#pl-company')).toHaveText('SYNTHETIC COMPANY');};
        await open();await page.locator('#cad-parts-dialog summary').click();await page.locator('#pl-name').fill('Gate valve');await page.locator('#pl-spec').fill('DN50');
        await page.locator('#pl-save-starter').click();await expect(page.locator('#pl-message')).toContainText('저장했습니다');
        await page.locator('#pl-width').fill('15');await page.locator('#pl-angle').fill('30');await page.locator('#pl-place').click();
        const box=await page.locator('#canvas').boundingBox();await page.mouse.click(box.x+box.width/2,box.y+box.height/2);
        await expect(page.locator('#status')).toContainText('부품 1개 배치');
        await page.keyboard.press('Control+d');await page.locator('#save').click();await expect(page.locator('#dirty')).toHaveText('개정 2 저장됨');
        assert.equal(drawing.markups.length,2);assert.equal(drawing.markups[0].kind,'part');assert.equal(drawing.markups[0].part.id,drawing.markups[1].part.id);assert.notEqual(drawing.markups[0].id,drawing.markups[1].id);
        const printed=await page.evaluate(data=>{
            const doc=window.CADCore.parse(data.dxf);doc.markups=data.markups;doc.workspace=data.workspace;
            const labels=[],original=CanvasRenderingContext2D.prototype.fillText;
            CanvasRenderingContext2D.prototype.fillText=function(text,...args){labels.push(text);return original.call(this,text,...args);};
            try{const out=window.CADPrint.render(doc,data,{minX:0,minY:0,maxX:200,maxY:120},{paper:'A4',landscape:true,dpi:150,fixed:false,legend:true,title:data.title});
                const crop=out.plan.frame,pixels=out.canvas.getContext('2d').getImageData(crop.x,crop.y,crop.width,crop.height).data;
                let blue=0;for(let i=0;i<pixels.length;i+=4)if(pixels[i]<100&&pixels[i+1]>130&&pixels[i+2]>180)blue++;
                return {labels,blue,pdfBytes:out.blob().size};
            }finally{CanvasRenderingContext2D.prototype.fillText=original;}
        },drawing);
        assert.ok(printed.labels.some(s=>s.includes('Gate valve')&&s.includes('DN50')&&s.includes('(2)')));
        assert.ok(printed.blue>100);assert.ok(printed.pdfBytes>1000);
        await page.locator('#reload').click();await expect(page.locator('#dirty')).toHaveText('개정 2');await open();
        await expect(page.locator('#pl-counts')).toContainText('2개');await expect(page.locator('#pl-basis')).toContainText('저장 개정 2');
        const download=page.waitForEvent('download');await page.locator('#pl-csv').click();assert.match((await download).suggestedFilename(),/cad-9-r2-parts.csv/);
        await page.locator('#pl-list').selectOption([...catalog.keys()][0]);await expect(page.locator('#pl-place')).toBeEnabled();
        fs.mkdirSync('test-results/assistant-browser',{recursive:true});await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-parts.png'});
        await page.locator('#pl-archive').click();await expect(page.locator('#pl-message')).toContainText('사용 중지했습니다');
        await expect(page.locator('#pl-list option')).toHaveCount(0);await expect(page.locator('#pl-counts')).toContainText('2개');
        // Quantity row selects exact placement IDs; deletion and undo affect the same count.
        await page.locator('#pl-counts button').click();await page.locator('#canvas').focus();await page.keyboard.press('Delete');
        await open();await expect(page.locator('#pl-counts')).toContainText('없습니다');await page.locator('#pl-close').click();
        await page.locator('#canvas').focus();await page.keyboard.press('Control+z');await open();await expect(page.locator('#pl-counts')).toContainText('2개');
        assert.deepEqual(errors,[]);console.log('CAD company library: save, place, copy, reload, evidence selection, export, archive and undo passed');
    }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
