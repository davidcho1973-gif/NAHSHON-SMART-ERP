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
        const initial = C.parse('0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n');
        initial.entities.push(
            C.newEntity('POINT', { p: { x: 0, y: 0 } }),
            C.newEntity('POINT', { p: { x: 200, y: 120 } }),
            C.newEntity('LINE', { p: { x: 20, y: 30 }, q: { x: 100, y: 30 } }),
            C.newEntity('LWPOLYLINE', { p: { x: 120, y: 30 }, points: [{ x: 120, y: 30 }, { x: 170, y: 30 }, { x: 170, y: 70 }, { x: 120, y: 70 }], closed: true }),
        );
        let drawing = { id: 9, title: 'Annotation fixture', site_id: 1, revision: 1, dxf: C.exportDXF(initial), canManage: true, warnings: [] }, writes = 0;
        const html = fs.readFileSync('resources/views/cad/index.blade.php', 'utf8')
            .replace(/<script[^>]*>[\s\S]*?<\/script>/g, '').replace(/<link[^>]*>/g, '<link rel="stylesheet" href="/cad-editor.css">')
            .replace('{{ csrf_token() }}', 'synthetic').replace('</body>', '<script src="/cad-core.js"></script><script src="/cad-editor.js"></script></body>');
        await page.route('https://synthetic.test/**', async route => {
            const url = new URL(route.request().url()), method = route.request().method();
            let json;
            if (url.pathname === '/cad') return route.fulfill({ contentType: 'text/html', body: html });
            if (['/cad-core.js', '/cad-editor.js'].includes(url.pathname)) return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync('public/js' + url.pathname, 'utf8') });
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

        // Hover/click snaps within a few pixels; measurement is a transient overlay.
        await tool('measure');
        await move(20.4, 30.4);
        await expect(page.locator('#canvas')).toHaveAttribute('data-snap-active', 'true');
        near(Number(await page.locator('#canvas').getAttribute('data-snap-x')), 20);
        await click(20.4, 30.4);
        await move(100.4, 30.4);
        await expect(page.locator('#annotation-readout')).toContainText('80');
        await click(100.4, 30.4);
        await move(60, 12);
        await click(60, 12);
        await expect(page.locator('#annotation-readout')).toContainText('80');
        assert.doesNotMatch(await page.locator('#dirty').innerText(), /저장 안 됨/);
        await page.locator('#save').click();
        assert.equal(writes, 0, 'Measuring must not save or create any entity');
        await page.keyboard.press('Escape');

        // Two snapped points plus a freely positioned dimension line.
        await tool('dimension');
        await page.locator('#dimension-mode').selectOption('horizontal');
        await click(20.4, 30.4); await click(100.4, 30.4);
        await move(60, 15);
        await expect(page.locator('#annotation-readout')).toContainText('80');
        assert.equal(writes, 0);
        await click(60, 15); await save();
        const dimension = () => entities().find(e => e.type === 'ERP_DIMENSION');
        let dim = dimension();
        assert.ok(dim); point(dim.p, 20, 30); point(dim.q, 100, 30); near(dim.placement.y, 15);
        await page.locator('#snap').uncheck();
        let lineGrip = C.grips(dim).find(g => g.key === 'dimension-line').point;
        await drag([lineGrip.x, lineGrip.y], [lineGrip.x, 5]); await save();
        dim = dimension(); near(dim.placement.y, 5); point(dim.p, 20, 30); point(dim.q, 100, 30);
        let labelGrip = C.grips(dim).find(g => g.key === 'dimension-text').point;
        await drag([labelGrip.x, labelGrip.y], [85, 12]); await save();
        dim = dimension(); point(dim.textPosition, 85, 12); near(dim.placement.y, 5);
        await page.locator('#undo').click(); await save();
        assert.equal(dimension().textPosition, null);
        await page.locator('#redo').click(); await save(); point(dimension().textPosition, 85, 12);

        // A polyline corner snaps and vertical projection differs from actual distance.
        await page.locator('#snap').check(); await tool('dimension');
        await page.locator('#dimension-mode').selectOption('vertical');
        await click(120.4, 30.4); await click(170.4, 70.4); await click(184, 50); await save();
        const vertical = entities().find(e => e.type === 'ERP_DIMENSION' && e.mode === 'vertical');
        point(vertical.p, 120, 30); point(vertical.q, 170, 70); near(C.dimensionLayout(vertical).value, 40);
        await page.locator('#snap').uncheck();

        // Cancelled and empty input cannot create the old literal placeholder.
        await tool('text'); await click(35, 105);
        await expect(page.locator('#text-dialog')).toBeVisible();
        await page.locator('#text-dialog-content').fill(''); await page.locator('#text-dialog-confirm').click();
        await expect(page.locator('#text-dialog')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.locator('#text-dialog')).not.toBeVisible();
        assert.doesNotMatch(await page.locator('#dirty').innerText(), /저장 안 됨/);
        assert.equal(entities().filter(e => e.type === 'MTEXT').length, 0);

        // Drag a width, type multiple lines, and explicitly confirm the new textbox.
        await tool('text'); await drag([25, 108], [90, 88]);
        await expect(page.locator('#text-dialog')).toBeVisible();
        await page.locator('#text-dialog-content').fill('천장 배관\n검측 완료 {A} \\P');
        await page.locator('#text-dialog-height').fill('4');
        await page.locator('#text-dialog-color').fill('#ffcc44');
        await page.locator('#text-dialog-width').fill('65');
        fs.mkdirSync('test-results/assistant-browser', {recursive:true});
        await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-text-dialog.png'});
        const beforeText = writes;
        await page.locator('#text-dialog-content').press('Control+s');
        assert.equal(writes, beforeText, 'Typing dialog must not trigger document save');
        await page.locator('#text-dialog-content').press('Control+Enter'); await save();
        const textbox = () => entities().find(e => e.type === 'MTEXT');
        let text = textbox();
        assert.equal(text.text, '천장 배관\n검측 완료 {A} \\P'); assert.equal(text.height, 4); assert.equal(text.width, 65); assert.equal(text.color, 0xffcc44);
        await page.locator('#edit-text-dialog').click();
        await page.locator('#text-dialog-content').fill('입력 취소'); await page.locator('#text-dialog-cancel').click();
        assert.doesNotMatch(await page.locator('#dirty').innerText(), /저장 안 됨/);
        await page.locator('#edit-text-dialog').click();
        await page.locator('#text-dialog-content').fill('천장 배관\n수정 완료'); await page.locator('#text-dialog-confirm').click(); await save();
        assert.equal(textbox().text, '천장 배관\n수정 완료');
        const widthGrip = C.grips(textbox()).find(g => g.key === 'text-width').point;
        await drag([widthGrip.x, widthGrip.y], [widthGrip.x + 12, widthGrip.y]); await save();
        near(textbox().width, 77);

        // Reopen persisted annotations and keep read-only proofs immutable.
        await page.locator('#reload').click();
        await expect(page.locator('#status')).toContainText('Annotation fixture');
        await click(85, 12);
        await expect(page.locator('#selection')).toContainText('1');
        point(dimension().textPosition, 85, 12);
        fs.mkdirSync('test-results/assistant-browser', {recursive:true});
        await page.screenshot({path:'test-results/assistant-browser/SYNTHETIC-cad-annotations.png'});
        await page.goto(`https://synthetic.test/cad?drawing_id=9&revision=${drawing.revision}`);
        await expect(page.locator('#status')).toContainText('Annotation fixture');
        const readOnlyWrites = writes;
        await expect(page.locator('#save')).toBeDisabled();
        await tool('text'); await click(30, 100);
        await expect(page.locator('#text-dialog')).not.toBeVisible();
        assert.equal(writes, readOnlyWrites);

        assert.deepEqual(errors, []);
        console.log(JSON.stringify({ passed: true, writes, errors }));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
