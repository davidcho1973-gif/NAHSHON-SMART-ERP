// Repeated editing on expanded block geometry, through the actual editor UI.
// Optional local DXF input stays in this isolated fixture; no production requests.
const {chromium,expect}=require('@playwright/test');
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=process.env.ERP_CAD_HISTORY_LOCAL_DXF ? fs.readFileSync(process.env.ERP_CAD_HISTORY_LOCAL_DXF,'utf8') : require('../Fixtures/cad-history.cjs').drawing();
const context={window:{}};vm.createContext(context);vm.runInContext(fs.readFileSync('public/js/cad-core.js','utf8'),context);
const C=context.window.CADCore,original=C.parse(source),baseCount=original.entities.length;
const originalRaw=JSON.stringify(original.entities.map(e=>e.raw));
(async()=>{
    const browser=await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL||undefined});
    try{
        const page=await browser.newPage({viewport:{width:1500,height:1000}}),errors=[];
        page.setDefaultTimeout(30000);page.on('pageerror',e=>errors.push(e.message));
        let drawing={id:9,title:'Isolated CAD history verification',site_id:1,revision:1,canManage:true,canExport:true,dxf:source,markups:[],workspace:{}};
        const scripts=['polygon-clipping','cad-core','cad-fidelity','cad-markup','cad-markup-geometry','cad-markup-workbench','cad-recovery','cad-print','cad-review','cad-review-workbench','cad-markup-tools','cad-editor'];
        const html=fs.readFileSync('resources/views/cad/index.blade.php','utf8').replace(/<script[^>]*>[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'<link rel="stylesheet" href="/cad-editor.css">').replace('{{ csrf_token() }}','synthetic').replaceAll('{{ auth()->id() }}','synthetic-history-user').replace('</body>',scripts.map(n=>`<script src="/${n}.js"></script>`).join('')+'</body>');
        await page.route('**/*',async route=>{
            const url=new URL(route.request().url());
            assert.equal(url.origin,'https://synthetic.test');
            const path=url.pathname;
            if(path==='/cad')return route.fulfill({contentType:'text/html',body:html});
            if(path==='/cad-editor.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync('public/css/cad-editor.css','utf8')});
            if(path==='/polygon-clipping.js')return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/vendor/polygon-clipping/polygon-clipping.js','utf8')});
            if(/^\/cad-[a-z-]+\.js$/.test(path))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+path,'utf8')});
            let json;
            if(path==='/admin-api/cad/capabilities')json={dwgRead:false,dwgWrite:false};
            else if(path==='/admin-api/cad')json={sites:[{id:1,name:'Synthetic'}],drawings:[drawing],canManage:true};
            else if(path==='/admin-api/cad/9'){
                if(route.request().method()==='PUT'){
                    const payload=route.request().postDataJSON();assert.equal(payload.revision,drawing.revision);
                    drawing={...drawing,...payload,revision:drawing.revision+1};
                }
                json=drawing;
            }else return route.fulfill({status:404,body:'Outside isolated fixture'});
            return route.fulfill({json});
        });
        await page.goto('https://synthetic.test/cad?drawing_id=9');
        await expect(page.locator('#status')).toContainText(drawing.title);
        await page.locator('#snap').uncheck();await page.locator('#grid').uncheck();
        const session=await page.context().newCDPSession(page);await session.send('Performance.enable');
        const heap=async()=>{await session.send('HeapProfiler.collectGarbage');return (await session.send('Performance.getMetrics')).metrics.find(m=>m.name==='JSHeapUsedSize').value;};
        const baseline=await heap(),started=Date.now();
        const box=await page.locator('#canvas').boundingBox();
        const click=async(x,y)=>page.mouse.click(box.x+box.width*x,box.y+box.height*y);
        async function line(i){
            await page.locator('button[data-workspace="cad"]').click();
            await page.locator('[data-tool="line"]').click();
            await click(.3+(i%10)*.015,.3);await click(.35+(i%10)*.015,.4);
        }
        for(let i=0;i<63;i++){
            if(i%3===0)await line(i);
            else if(i%3===1){
                await page.locator('button[data-workspace="cad"]').click();await page.locator('[data-tool="text"]').click();
                await click(.45,.45);await expect(page.locator('#text-dialog')).toBeVisible();
                await page.locator('#text-dialog-content').fill('Repeated text '+i);await page.locator('#text-dialog-confirm').click();
            }else{
                await page.locator('button[data-workspace="markup"]').click();await page.locator('[data-markup-tool="cloud"]').click();
                await page.mouse.move(box.x+box.width*.55,box.y+box.height*.55);await page.mouse.down();
                await page.mouse.move(box.x+box.width*.65,box.y+box.height*.65);await page.mouse.up();
            }
        }
        const after=await heap(),growth=after-baseline;
        assert.ok(growth<128*1048576,`Repeated edits retained ${(growth/1048576).toFixed(1)} MiB`);
        const save=async(nativeAdded,markupCount)=>{
            const revision=drawing.revision;await page.locator('#save').click();
            await expect(page.locator('#dirty')).toHaveText(`개정 ${revision+1} 저장됨`);
            const parsed=C.parse(drawing.dxf);
            assert.equal(parsed.entities.length,baseCount+nativeAdded);
            assert.equal(drawing.markups.length,markupCount);
            assert.equal(JSON.stringify(parsed.entities.slice(0,baseCount).map(e=>e.raw)),originalRaw);
        };
        await save(42,21);
        for(let i=0;i<61;i++)await page.locator('#undo').click();
        await save(2,1); // 60 retained edits; the 61st Undo does nothing.
        for(let i=0;i<60;i++)await page.locator('#redo').click();
        await save(42,21);
        await page.locator('#undo').click();await line(65);await page.locator('#redo').click();
        await save(43,20); // A new edit invalidates the abandoned redo branch.
        await page.locator('#reload').click();await expect(page.locator('#markup-count')).toHaveText('20개 표시');
        await expect(page.locator('#status')).toContainText(`${baseCount+43}개 객체`);
        assert.deepEqual(errors,[]);
        console.log(JSON.stringify({privateLocalInput:!!process.env.ERP_CAD_HISTORY_LOCAL_DXF,sourceBytes:Buffer.byteLength(source),baseCount,edits:63,undo:60,redo:60,saves:4,originalRecordsPreserved:true,heapBeforeMiB:Math.round(baseline/1048576),heapAfterMiB:Math.round(after/1048576),historyGrowthMiB:Math.round(growth/1048576),elapsedMs:Date.now()-started,errors}));
    }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
