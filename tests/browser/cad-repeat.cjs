// Repeated drawing uses real gestures against production scripts and synthetic data only.
const { chromium, expect } = require('@playwright/test');
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({headless: true, channel: process.env.ERP_TEST_BROWSER_CHANNEL || undefined});
    try {
        const page = await browser.newPage({viewport: {width: 1500, height: 1000}}), errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.setDefaultTimeout(10000);
        const context = {window: {}};
        vm.runInNewContext(fs.readFileSync('public/js/cad-core.js', 'utf8'), context);
        const C = context.window.CADCore;
        const doc = C.parse('0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n');
        doc.entities.push(C.newEntity('POINT', {p: {x: 0, y: 0}}), C.newEntity('POINT', {p: {x: 200, y: 120}}));
        doc.entities.push(C.newEntity('LWPOLYLINE', {p: {x: 10, y: 10}, points: [{x: 10, y: 10}, {x: 20, y: 10}, {x: 20, y: 20}, {x: 10, y: 20}], closed: true}));
        let drawing = {id: 9, title: 'SYNTHETIC 연속 그리기', site_id: 1, revision: 1, canManage: true, canExport: true, dxf: C.exportDXF(doc), markups: [], workspace: {}};
        let writes = 0;
        const scripts = ['polygon-clipping', 'cad-core', 'cad-fidelity', 'cad-markup', 'cad-markup-geometry', 'cad-markup-workbench', 'cad-recovery', 'cad-print', 'cad-review', 'cad-review-workbench', 'cad-markup-tools', 'cad-editor'];
        const html = fs.readFileSync('resources/views/cad/index.blade.php', 'utf8')
            .replace(/<script[^>]*>[\s\S]*?<\/script>/g, '')
            .replace(/<link[^>]*>/g, '<link rel="stylesheet" href="/cad-editor.css">')
            .replace('{{ csrf_token() }}', 'synthetic')
            .replaceAll('{{ auth()->id() }}', 'synthetic-user')
            .replaceAll('{{ \\App\\Support\\Org::name() }}', 'SYNTHETIC ERP')
            .replace('</body>', scripts.map(name => `<script src="/${name}.js"></script>`).join('') + '</body>');
        await page.route('https://synthetic.test/**', async route => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/cad') return route.fulfill({contentType: 'text/html', body: html});
            if (path === '/cad-editor.css') return route.fulfill({contentType: 'text/css', body: fs.readFileSync('public/css/cad-editor.css', 'utf8')});
            if (path === '/polygon-clipping.js') return route.fulfill({contentType: 'text/javascript', body: fs.readFileSync('public/vendor/polygon-clipping/polygon-clipping.js', 'utf8')});
            if (/^\/cad-[a-z-]+\.js$/.test(path)) {
                const baseline = process.env.ERP_CAD_REPEAT_BASELINE;
                const file = baseline && ['cad-editor.js', 'cad-markup-tools.js'].includes(path.slice(1)) ? baseline + path : 'public/js' + path;
                return route.fulfill({contentType: 'text/javascript', body: fs.readFileSync(file, 'utf8')});
            }
            let json;
            if (path === '/admin-api/cad/capabilities') json = {dwgRead: false, dwgWrite: false};
            else if (path === '/admin-api/cad') json = {sites: [{id: 1, name: 'SYNTHETIC'}], drawings: [drawing], canManage: true};
            else if (path === '/admin-api/cad/9') {
                if (route.request().method() === 'PUT') {
                    const payload = route.request().postDataJSON();
                    assert.equal(payload.revision, drawing.revision);
                    drawing = {...drawing, ...payload, revision: drawing.revision + 1}; writes++;
                }
                json = drawing;
            } else return route.fulfill({status: 404, body: 'Not part of synthetic fixture'});
            return route.fulfill({json});
        });
        await page.goto('https://synthetic.test/cad?drawing_id=9');
        await expect(page.locator('#status')).toContainText(drawing.title);
        await page.locator('#snap').uncheck();
        await page.locator('[data-workspace="cad"]').click();
        let box = await page.locator('#canvas').boundingBox();const scale = .85 * Math.min(box.width / 200, box.height / 120);
        const screen = (x, y) => ({x: box.x + box.width / 2 + (x - 100) * scale, y: box.y + box.height / 2 - (y - 60) * scale});
        const move = async (x, y) => {const p = screen(x, y); await page.mouse.move(p.x, p.y, {steps: 8});};
        const click = async (x, y) => {const p = screen(x, y); await page.mouse.click(p.x, p.y);};
        const drag = async (a, b) => {await move(...a); await page.mouse.down(); await move(...b); await page.mouse.up();};
        const save = async () => {const revision = drawing.revision; await page.locator('#save').click(); await expect(page.locator('#dirty')).toHaveText(`개정 ${revision + 1} 저장됨`);};
        const nativeCount = type => C.parse(drawing.dxf).entities.filter(entity => entity.type === type).length;
        const activeNative = name => expect(page.locator(`[data-tool="${name}"]`)).toHaveClass(/active/);
        const nativeTools = [
            ['line', 'LINE', async x => drag([x, 95], [x + 30, 95])],
            ['rectangle', 'LWPOLYLINE', async x => drag([x, 80], [x + 30, 60])],
            ['circle', 'CIRCLE', async x => {await click(x, 45); await click(x + 10, 45);}],
            ['arc', 'ARC', async x => {await click(x, 25); await click(x + 10, 25); await click(x, 35);}],
            ['polyline', 'LWPOLYLINE', async x => {await click(x, 105); await click(x + 10, 110); await click(x + 20, 105); await page.keyboard.press('Enter');}],
            ['dimension', 'ERP_DIMENSION', async x => {await click(x, 55); await click(x + 30, 55); await click(x + 15, 65);}],
            ['text', 'MTEXT', async x => {
                await click(x, 90); await expect(page.locator('#text-dialog')).toBeVisible();
                // A prior dialog's asynchronous close event can arrive after reopening.
                if (x === 110) await page.locator('#text-dialog').evaluate(dialog => dialog.dispatchEvent(new Event('close')));
                await page.locator('#text-dialog-content').fill(`반복 문자 ${x}`); await page.locator('#text-dialog-confirm').click();
                await expect(page.locator('#text-dialog')).not.toBeVisible();
            }],
        ];
        for (const [name, type, draw] of nativeTools) {
            const before = nativeCount(type);
            await page.locator(`[data-tool="${name}"]`).click();
            await draw(30); await activeNative(name);
            await draw(110); await activeNative(name);
            await save(); assert.equal(nativeCount(type), before + 2, `${name} must create two independent objects without reselection`);
            await page.keyboard.press('Escape'); await activeNative('select');
        }
        // Measurement stays active but creates no saved geometry or dirty drawing.
        const beforeMeasurements = writes;
        await page.locator('[data-tool="measure"]').click();
        for (const x of [30, 110]) {await click(x, 55); await click(x + 30, 55); await click(x + 15, 65); await activeNative('measure');}
        assert.equal(writes, beforeMeasurements); await expect(page.locator('#dirty')).not.toContainText('저장 안 됨');
        await page.keyboard.press('Escape');
        // Arrow selection remains an arrow over objects; move cursor is reserved for dragging.
        await expect(page.locator('[data-tool="select"] svg')).toHaveCount(1);
        await move(190, 110); await expect(page.locator('#canvas')).toHaveCSS('cursor', 'default');
        await move(45, 95); await expect(page.locator('#canvas')).toHaveCSS('cursor', 'default');
        await page.mouse.down(); await move(45, 92); await expect(page.locator('#canvas')).toHaveCSS('cursor', 'move');
        await page.mouse.up(); await expect(page.locator('#canvas')).toHaveCSS('cursor', 'default');
        await page.keyboard.press('Control+z');
        // Cancelling an incomplete line exits drawing without an extra entity.
        const beforeCancel = nativeCount('LINE');
        await page.locator('[data-tool="line"]').click(); await click(180, 80); await page.keyboard.press('Escape'); await activeNative('select');
        await save(); assert.equal(nativeCount('LINE'), beforeCancel);

        await page.locator('[data-workspace="markup"]').click();
        // The fixed numeric dock changes viewport height; the world center and
        // scale stay fixed, so screen coordinates must use the current viewport.
        box = await page.locator('#canvas').boundingBox();
        const activeMarkup = name => expect(page.locator(`[data-markup-tool="${name}"]`)).toHaveAttribute('aria-pressed', 'true');
        const dialog = async text => {await expect(page.locator('#markup-dialog')).toBeVisible(); await page.locator('#markup-dialog-text').fill(text); await page.locator('#markup-dialog-confirm').click();};
        const polygon = async x => {await click(x, 40); await click(x + 20, 40); await click(x + 10, 55); await page.keyboard.press('Enter');};
        const markupTools = [
            ['box', async x => drag([x, 90], [x + 20, 70])],
            ['cloud', async x => drag([x, 90], [x + 20, 70])],
            ['pen', async x => drag([x, 65], [x + 20, 60])],
            ['highlight', async x => drag([x, 65], [x + 20, 60])],
            ['area', polygon], ['freecloud', polygon],
            ['route', async x => {await click(x, 65); await click(x + 20, 60); await page.keyboard.press('Enter');}],
            ['callout', async x => {await drag([x, 90], [x + 20, 70]); await dialog(`반복 메모 ${x}`);}],
            ['text', async x => {await click(x, 75); await dialog(`반복 상자 ${x}`);}],
            ['pin', async x => click(x, 70)],
            ['boundary', async () => click(15, 15)],
        ];
        for (const [name, draw] of markupTools) {
            const before = drawing.markups.length;
            await page.locator(`[data-markup-tool="${name}"]`).click();
            const firstColor = await page.locator('#markup-color').inputValue();
            await draw(30); await activeMarkup(name);
            if (name === 'box') await page.locator('#markup-color').fill('#fbbf24');
            await draw(110); await activeMarkup(name);
            await save(); assert.equal(drawing.markups.length, before + 2, `${name} must remain active after both creations`);
            if (name === 'box') {assert.equal(drawing.markups[before].color, firstColor); assert.equal(drawing.markups[before + 1].color, '#fbbf24');}
            if (name === 'pin') assert.notEqual(drawing.markups[before].text, drawing.markups[before + 1].text);
            await page.keyboard.press('Escape'); await activeMarkup('select');
        }
        await expect(page.locator('[data-markup-tool="select"] svg')).toHaveCount(1);
        await move(190, 110); await expect(page.locator('#canvas')).toHaveCSS('cursor', 'default');
        // Cancel a new text dialog and draw another without touching its tool button.
        const beforeTextCancel = drawing.markups.length;
        await page.locator('[data-markup-tool="text"]').click(); await click(170, 80);
        await page.locator('#markup-dialog-cancel').click(); await activeMarkup('text');
        await click(170, 60); await dialog('취소 후 새 상자'); await activeMarkup('text'); await save();
        assert.equal(drawing.markups.length, beforeTextCancel + 1);
        await page.keyboard.press('Escape'); await activeMarkup('select');
        assert.deepEqual(errors, []);
        if (process.env.ERP_CAD_REPEAT_SCREENSHOT) await page.screenshot({path: process.env.ERP_CAD_REPEAT_SCREENSHOT, fullPage: true});
        console.log(JSON.stringify({passed: true, nativeTools: nativeTools.length + 1, markupTools: markupTools.length, writes, errors}));
    } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
