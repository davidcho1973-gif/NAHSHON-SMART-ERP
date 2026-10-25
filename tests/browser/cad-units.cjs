// Real pointer/keyboard interactions against the CAD page; all records are synthetic.
const { chromium, expect } = require('@playwright/test');
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const context = { window: {} };
vm.runInNewContext(fs.readFileSync('public/js/cad-core.js', 'utf8'), context);
const C = context.window.CADCore;
const plain = value => JSON.parse(JSON.stringify(value));
const near = (actual, expected) => assert.ok(Math.abs(actual - expected) < 0.3, `${actual} != ${expected}`);
const point = (actual, x, y) => { near(actual.x, x); near(actual.y, y); };

(async () => {
    const browser = await chromium.launch({ headless: true, channel: process.env.ERP_TEST_BROWSER_CHANNEL || undefined });
    try {
        const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } }), errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.setDefaultTimeout(10000);
        const initial = C.parse('0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n9\n$INSUNITS\n70\n1\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n');
        initial.entities.push(
            C.newEntity('POINT', { p: { x: 0, y: 0 } }),
            C.newEntity('POINT', { p: { x: 200, y: 120 } }),
            C.newEntity('LINE', { p: { x: 20, y: 30 }, q: { x: 100, y: 30 } }),
            C.newEntity('LWPOLYLINE', { p: { x: 120, y: 30 }, points: [{ x: 120, y: 30 }, { x: 170, y: 30 }, { x: 170, y: 70 }, { x: 120, y: 70 }], closed: true }),
        );
        let drawing = { id: 9, title: 'Annotation fixture', site_id: 1, revision: 1, dxf: C.exportDXF(initial), canManage: true, warnings: [] }, writes = 0;
        const html = fs.readFileSync('resources/views/cad/index.blade.php', 'utf8')
            .replace(/<script[^>]*>[\s\S]*?<\/script>/g, '').replace(/<link[^>]*>/g, '<link rel="stylesheet" href="/cad-editor.css">')
            .replace('{{ csrf_token() }}', 'synthetic').replaceAll('{{ \\App\\Support\\Org::name() }}', 'SYNTHETIC ERP').replace('</body>', '<script src="/cad-core.js"></script><script src="/cad-fidelity.js"></script><script src="/cad-markup.js"></script><script src="/cad-markup-tools.js"></script><script src="/cad-editor.js"></script></body>');
        await page.route('https://synthetic.test/**', async route => {
            const url = new URL(route.request().url()), method = route.request().method();
            let json;
            if (url.pathname === '/cad') return route.fulfill({ contentType: 'text/html', body: html });
            if (['/cad-core.js','/cad-fidelity.js', '/cad-markup.js', '/cad-markup-tools.js', '/cad-editor.js'].includes(url.pathname)) return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync('public/js' + url.pathname, 'utf8') });
            if (url.pathname === '/cad-editor.css') return route.fulfill({ contentType: 'text/css', body: fs.readFileSync('public/css/cad-editor.css', 'utf8') });
            if (url.pathname === '/admin-api/cad/capabilities') json = { dwgRead: false, dwgWrite: false, message: 'Synthetic DXF' };
            else if (url.pathname === '/admin-api/cad') json = { canManage: true, sites: [{ id: 1, name: 'Synthetic Site' }], drawings: [drawing] };
            else if (url.pathname === '/admin-api/cad/9' && method === 'GET') json = drawing;
            else if (url.pathname === '/admin-api/cad/9/history') json = { ...drawing, canManage: false };
            else if (url.pathname === '/admin-api/cad/9' && method === 'PUT') {
                const data = route.request().postDataJSON();
                assert.equal(data.revision, drawing.revision);
                writes++;
                drawing = { ...drawing, revision: drawing.revision + 1, dxf: data.dxf };
                json = drawing;
            } else return route.fulfill({ status: 404, body: 'not found' });
            return route.fulfill({ contentType: 'application/json', body: JSON.stringify(json) });
        });
        await page.goto('https://synthetic.test/cad?drawing_id=9');
        await expect(page.locator('#status')).toContainText('Annotation fixture');
        await page.locator('[data-workspace="cad"]').click();
        const box = await page.locator('#canvas').boundingBox(), scale = .85 * Math.min(box.width / 200, box.height / 120);
        const screen = (x, y) => ({ x: box.x + box.width / 2 + (x - 100) * scale, y: box.y + box.height / 2 - (y - 60) * scale });
        const move = async (x, y) => { const p = screen(x, y); await page.mouse.move(p.x, p.y, { steps: 6 }); };
        const click = async (x, y) => { const p = screen(x, y); await page.mouse.click(p.x, p.y); };
        const drag = async (a, b) => { await move(...a); await page.mouse.down(); await move(...b); await page.mouse.up(); };
        const tool = async name => page.locator(`[data-tool="${name}"]`).click();
        const entities = () => plain(C.parse(drawing.dxf).entities);
        const save = async () => {
            const revision = drawing.revision;
            await page.locator('#save').click();
            await expect(page.locator('#dirty')).toHaveText(`개정 ${revision + 1} 저장됨`);
        };


        const originalRaw=entities().map(e=>e.raw);
        await expect(page.locator('#source-unit-control')).toBeHidden();
        await expect(page.locator('#tool-buttons svg')).toHaveCount(15);
        for(const name of ['line','polyline','dimension','measure','rotate','delete']){
            await expect(page.locator('[data-tool="'+name+'"]')).toHaveAttribute('aria-label',/.+/);
        }
        await page.locator('#dimension-unit').selectOption('ft');
        await expect(page.locator('#length-input-unit')).toContainText('ft');
        assert.ok(Math.abs(Number(await page.locator('#offset-distance').inputValue())-10/12)<1e-10);
        assert.equal(writes,0);
        // Create an exact 66-inch dimension by entering decimal feet.
        await tool('dimension');
        const coordinate=async(x,y)=>{await page.locator('#x').fill(String(x));await page.locator('#y').fill(String(y));await page.locator('#coordinate').click();};
        await coordinate(0,0);await coordinate(5.5,0);await coordinate(0,1);
        const selectDimension=async()=>{await tool('select');await click(30,12);};
        await selectDimension();await expect(page.locator('#annotation-readout')).toContainText('5.500 ft');
        await save();
        let dim=entities().find(e=>e.type==='ERP_DIMENSION');point(dim.q,66,0);
        assert.equal(C.dimensionLayout(dim).label,'5.500 ft');
        const originalShape=JSON.stringify([dim.p,dim.q,dim.placement,dim.height]);
        for(const [unit,label] of [['in','66.000 in'],['ft-in',"5' 6\""],['ft','5.500 ft']]){
            await page.locator('#dimension-unit').selectOption(unit);
            await expect(page.locator('#annotation-readout')).toContainText(label);
            await save();dim=entities().find(e=>e.type==='ERP_DIMENSION');
            assert.equal(JSON.stringify([dim.p,dim.q,dim.placement,dim.height]),originalShape);
            assert.equal(C.dimensionLayout(dim).label,label);
            assert.deepEqual(entities().filter(e=>e.type!=='ERP_DIMENSION').map(e=>e.raw),originalRaw);
        }
        // Reload must restore display units from the saved DXF, without browser preference.
        await page.evaluate(()=>localStorage.clear());await page.locator('#reload').click();
        await expect(page.locator('#dimension-unit')).toHaveValue('ft');
        await page.locator('#dimension-unit').selectOption('in');
        await page.locator('#undo').click();await expect(page.locator('#dimension-unit')).toHaveValue('ft');
        await page.locator('#redo').click();await expect(page.locator('#dimension-unit')).toHaveValue('in');
        await page.locator('#dimension-unit').selectOption('ft-in');await save();
        // Converted coordinate entry creates a 12-inch line from a 1-foot input.
        await tool('line');await coordinate(0,6);await coordinate(1,6);await save();
        assert.ok(entities().some(e=>e.type==='LINE'&&Math.abs(e.p.y-72)<1e-9&&Math.abs(e.q.x-e.p.x-12)<1e-9));
        fs.mkdirSync('test-results/assistant-browser',{recursive:true});
        await page.locator('[data-tool=select]').click();await click(30,12);
        await page.locator('#tools').evaluate(e=>e.scrollTop=0);
        await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-units.png'});
        await page.setViewportSize({width:390,height:844});
        await expect(page.locator('[data-tool="dimension"] svg')).toBeVisible();
        assert.ok(await page.locator('#tools').evaluate(e=>e.scrollWidth<=e.clientWidth+1));
        await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-units-mobile.png'});
        // A source without unit metadata requires an explicit base; no assumption of inches.
        await page.setViewportSize({width:1500,height:1000});await page.evaluate(()=>localStorage.clear());
        drawing={...drawing,dxf:C.exportDXF(C.parse('0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nPOINT\n10\n0\n20\n0\n0\nPOINT\n10\n200\n20\n120\n0\nENDSEC\n0\nEOF\n'))};
        await page.locator('#reload').click();await expect(page.locator('#source-unit-control')).toBeVisible();
        await page.locator('#dimension-unit').selectOption('ft');await expect(page.locator('#dimension-unit')).toHaveValue('drawing');
        await expect(page.locator('#status')).toContainText('기준 단위');
        await page.locator('#source-unit').selectOption('in');await page.locator('#dimension-unit').selectOption('ft');
        await tool('dimension');await coordinate(0,0);await coordinate(5.5,0);await coordinate(0,1);await save();
        await page.evaluate(()=>localStorage.clear());await page.locator('#reload').click();
        await expect(page.locator('#source-unit')).toHaveValue('in');await expect(page.locator('#dimension-unit')).toHaveValue('ft');
        assert.equal(C.dimensionLayout(entities().find(e=>e.type==='ERP_DIMENSION')).label,'5.500 ft');
        // Read-only revisions cannot change persisted labels or trigger any write.
        const savedBytes=drawing.dxf,savedWrites=writes;
        await page.goto('https://synthetic.test/cad?drawing_id=9&revision='+drawing.revision);
        await expect(page.locator('#status')).toContainText('Annotation fixture');
        await page.locator('.mode-switch [data-workspace="cad"]').click();
        await page.locator('#dimension-unit').selectOption('in');
        await expect(page.locator('#save')).toBeDisabled();assert.equal(writes,savedWrites);assert.equal(drawing.dxf,savedBytes);
        assert.deepEqual(errors,[]);console.log(JSON.stringify({passed:true,writes,errors}));
    }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
