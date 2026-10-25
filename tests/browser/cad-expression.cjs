// Production UI with synthetic CAD and intercepted persistence. Backend validation
// and authorization have separate PostgreSQL feature tests; this exercises gestures.
const { chromium, expect } = require("@playwright/test");
const fs = require("node:fs"),
    vm = require("node:vm"),
    assert = require("node:assert/strict");
(async () => {
    const browser = await chromium.launch({
        headless: true,
        channel: process.env.ERP_TEST_BROWSER_CHANNEL || undefined,
    });
    try {
        const page = await browser.newPage({
                viewport: { width: 1500, height: 1000 },
            }),
            errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        page.on("dialog", (dialog) => dialog.accept());
        page.setDefaultTimeout(10000);
        const context = { window: {} };
        vm.runInNewContext(
            fs.readFileSync("public/js/cad-core.js", "utf8"),
            context,
        );
        const C = context.window.CADCore,
            doc = C.parse(
                "0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n",
            );
        doc.entities.push(
            C.newEntity("POINT", { p: { x: 0, y: 0 } }),
            C.newEntity("POINT", { p: { x: 200, y: 120 } }),
        );
        const original = C.exportDXF(doc);
        let drawing = {
            id: 9,
            title: "SYNTHETIC WORKBENCH",
            revision: 1,
            site_id: 1,
            canManage: true,
            canExport: true,
            dxf: original,
            markups: [],
            workspace: {},
        };
        const scripts = [
            "polygon-clipping",
            "cad-core",
            "cad-fidelity",
            "cad-markup",
            "cad-markup-geometry",
            "cad-markup-workbench",
            "cad-recovery",
            "cad-print",
            "cad-review",
            "cad-review-workbench",
            "cad-markup-tools",
            "cad-editor",
        ];
        const html = fs
            .readFileSync("resources/views/cad/index.blade.php", "utf8")
            .replace(/<script[^>]*>[\s\S]*?<\/script>/g, "")
            .replace(
                /<link[^>]*>/g,
                '<link rel="stylesheet" href="/cad-editor.css">',
            )
            .replace("{{ csrf_token() }}", "synthetic")
            .replaceAll("{{ auth()->id() }}", "synthetic-user")
            .replaceAll("{{ \\App\\Support\\Org::name() }}", "SYNTHETIC ERP")
            .replace(
                "</body>",
                scripts
                    .map((name) => `<script src="/${name}.js"></script>`)
                    .join("") + "</body>",
            );
        await page.route("https://synthetic.test/**", async (route) => {
            const path = new URL(route.request().url()).pathname;
            let data;
            if (path === "/cad")
                return route.fulfill({ contentType: "text/html", body: html });
            if (path === "/cad-editor.css")
                return route.fulfill({
                    contentType: "text/css",
                    body: fs.readFileSync("public/css/cad-editor.css", "utf8"),
                });
            if (path === "/polygon-clipping.js")
                return route.fulfill({
                    contentType: "text/javascript",
                    body: fs.readFileSync(
                        "public/vendor/polygon-clipping/polygon-clipping.js",
                        "utf8",
                    ),
                });
            if (/^\/cad-[a-z-]+\.js$/.test(path))
                return route.fulfill({
                    contentType: "text/javascript",
                    body: fs.readFileSync("public/js" + path, "utf8"),
                });
            if (path === "/admin-api/cad/capabilities")
                data = { dwgRead: false, dwgWrite: false };
            else if (path === "/admin-api/cad")
                data = {
                    sites: [{ id: 1, name: "SYNTHETIC" }],
                    drawings: [drawing],
                    canManage: true,
                };
            else if (path === "/admin-api/cad/9/copies") {
                const payload = route.request().postDataJSON();
                assert.equal(payload.revision, drawing.revision - 1);
                assert.ok(payload.requestKey);
                drawing = { ...drawing, ...payload, id: 10, revision: 1 };
                data = drawing;
            } else if (
                ["/admin-api/cad/9", "/admin-api/cad/10"].includes(path)
            ) {
                if (route.request().method() === "PUT") {
                    const payload = route.request().postDataJSON();
                    assert.equal(payload.dxf, original);
                    assert.equal(payload.revision, drawing.revision);
                    drawing = {
                        ...drawing,
                        ...payload,
                        revision: drawing.revision + 1,
                    };
                }
                data = drawing;
            } else return route.fulfill({ status: 404, body: "not a fixture" });
            return route.fulfill({ json: data });
        });
        await page.goto("https://synthetic.test/cad?drawing_id=9");
        await expect(page.locator("#status")).toContainText(
            "SYNTHETIC WORKBENCH",
        );
        await page.locator("#snap").uncheck();
        const box = await page.locator("#canvas").boundingBox(),
            scale = 0.85 * Math.min(box.width / 200, box.height / 120);
        const screen = (x, y) => ({
            x: box.x + box.width / 2 + (x - 100) * scale,
            y: box.y + box.height / 2 - (y - 60) * scale,
        });
        const click = async (x, y) => {
            const p = screen(x, y);
            await page.mouse.click(p.x, p.y);
        };
        const drag = async (a, b) => {
            const p = screen(...a),
                q = screen(...b);
            await page.mouse.move(p.x, p.y);
            await page.mouse.down();
            await page.mouse.move(q.x, q.y, { steps: 12 });
            await page.mouse.up();
        };
        const tool = (name) =>
            page.locator(`[data-markup-tool="${name}"]`).click();
        const save = async () => {
            const revision = drawing.revision;
            await page.locator("#save").click();
            await expect(page.locator("#dirty")).toHaveText(
                `개정 ${revision + 1} 저장됨`,
            );
        };
        const detail = async (text) => {
            const panel = page
                .locator("#markup-workbench details")
                .filter({ has: page.locator("summary", { hasText: text }) });
            if ((await panel.getAttribute("open")) === null)
                await panel.locator("summary").click();
        };
        const select = async (ids) => {
            await page.locator(`[data-markup-id="${ids[0]}"]`).click();
            for (const id of ids.slice(1))
                await page
                    .locator(`[data-markup-id="${id}"]`)
                    .click({ modifiers: ["Shift"] });
        };
        const specifications = [
            ['circle', 'ellipse', 4], ['ellipse', 'ellipse', 4],
            ['triangle', 'area', 3], ['diamond', 'area', 4],
            ['polygon', 'area', 7], ['star', 'area', 10],
            ['line', 'route', 2], ['arrow', 'route', 2], ['doublearrow', 'route', 2],
        ];
        await page.locator('#mw-polygon-sides').fill('7');
        for (let i = 0; i < specifications.length; i++) {
            const [name, kind, points] = specifications[i], x = 15 + (i % 3) * 60, y = 15 + Math.floor(i / 3) * 35;
            await tool(name);
            await drag([x, y], [x + 30, y + 20]);
            await save();
            assert.equal(drawing.markups.at(-1).kind, kind, name);
            assert.equal(drawing.markups.at(-1).points.length, points, name);
            await expect(page.locator('[data-markup-tool="' + name + '"]')).toHaveAttribute('aria-pressed', 'true');
        }
        assert.deepEqual(drawing.markups.slice(-3).map(m => m.arrows), ['none', 'end', 'both']);
        const ellipse = structuredClone(drawing.markups[1]);
        await select([ellipse.id]);
        await detail('선 · 채우기');
        await page.locator('#markup-color').fill('#0000ff');
        await page.locator('#mw-fill-mode').selectOption('color');
        await page.locator('#mw-fill-color').fill('#ff3300');
        await page.locator('#mw-fill-independent').check();
        await page.locator('#mw-fill-opacity').fill('25');
        await page.locator('#mw-dash').selectOption('dashdot');
        await page.locator('#mw-appearance-apply').click();
        await save();
        assert.equal(drawing.markups[1].fillColor, '#ff3300');
        assert.equal(drawing.markups[1].fillOpacity, .25);
        assert.equal(drawing.markups[1].dash, 'dashdot');
        assert.equal(drawing.markups[1].color, '#0000ff');
        await page.reload();
        await expect(page.locator('#status')).toContainText('SYNTHETIC WORKBENCH');
        await page.locator('#snap').uncheck();
        await select([ellipse.id]);
        await detail('선 · 채우기');
        await expect(page.locator('#mw-fill-mode')).toHaveValue('color');
        await expect(page.locator('#mw-dash')).toHaveValue('dashdot');
        await detail('정밀 이동');
        await page.locator('#mw-move-x').fill('12.5');
        await page.locator('#mw-move-y').fill('-6.25');
        await page.locator('#mw-move').click();
        await save();
        assert.ok(Math.abs(drawing.markups[1].points[0].x - ellipse.points[0].x - 12.5) < 1e-9);
        assert.ok(Math.abs(drawing.markups[1].points[0].y - ellipse.points[0].y + 6.25) < 1e-9);
        await page.locator('#mw-keep-ratio').uncheck();
        await page.locator('#mw-resize-width').fill('50');
        await page.locator('#mw-resize-height').fill('15');
        await page.locator('#mw-resize').click();
        await save();
        const sized = structuredClone(drawing.markups[1]);
        assert.ok(Math.abs(sized.points[1].x - sized.points[0].x - 50) < 1e-8);
        assert.ok(Math.abs(Math.abs(sized.points[3].y - sized.points[0].y) - 15) < 1e-8);
        await page.locator('#mw-repeat-columns').fill('3');
        await page.locator('#mw-repeat-rows').fill('2');
        await page.locator('#mw-repeat-x').fill('55');
        await page.locator('#mw-repeat-y').fill('-20');
        await page.locator('#mw-repeat').click();
        await save();
        assert.equal(drawing.markups.length, 14);
        assert.ok(drawing.markups.slice(-5).every(m => m.kind === 'ellipse' && m.fillColor === '#ff3300'));
        await page.locator('#undo').click();
        await save();
        assert.equal(drawing.markups.length, 9);
        await page.locator('#redo').click();
        await save();
        assert.equal(drawing.markups.length, 14);
        await page.locator('#undo').click();
        await select([ellipse.id]);
        await detail('선 · 채우기');
        await page.locator('#mw-fill-mode').selectOption('none');
        await page.locator('#mw-appearance-apply').click();
        await save();
        assert.equal(drawing.markups[1].fillColor, 'none');
        // A rejected edit must be atomic and leave history untouched.
        await detail('정밀 이동');
        await page.locator('#mw-resize-width').fill('0');
        await page.locator('#mw-resize').click();
        await expect(page.locator('#status')).toContainText('양수');
        await page.locator('#mw-repeat-columns').fill('20');
        await page.locator('#mw-repeat-rows').fill('20');
        await select(drawing.markups.slice(0, 3).map(m => m.id));
        await page.locator('#mw-repeat').click();
        await expect(page.locator('#status')).toContainText('1,000');
        await select([ellipse.id]);
        await page.locator('#markup-lock').click();
        await page.locator('#mw-move').click();
        await expect(page.locator('#status')).toContainText('잠긴');
        await page.locator('#markup-lock').click();
        // Corner dragging preserves the opposite corner and a valid affine ellipse.
        const corner = sized.points[0], opposite = sized.points[2];
        await drag([corner.x, corner.y], [corner.x - 3, corner.y - 2]);
        await save();
        assert.ok(Math.abs(drawing.markups[1].points[2].x - opposite.x) < 1e-8);
        assert.ok(Math.abs(drawing.markups[1].points[2].y - opposite.y) < 1e-8);
        assert.ok(Math.abs(drawing.markups[1].points[0].x - corner.x + 3) < .2);
        // New text can share explicitly chosen typography without changing the CAD source.
        await tool('text');
        await detail('문자 · 변경');
        await page.locator('#mw-text-default').check();
        await page.locator('#mw-text-size').fill('3');
        await page.locator('#mw-bold').check();
        await page.locator('#mw-text-align').selectOption('center');
        await page.locator('#mw-no-background').check();
        await page.locator('#mw-border').uncheck();
        await click(60, 110);
        await page.locator('#markup-dialog-text').fill('급수 변경\n현장 확인');
        await page.locator('#markup-dialog-confirm').click();
        await save();
        assert.equal(drawing.markups.at(-1).size, 3);
        assert.equal(drawing.markups.at(-1).textStyle.bold, true);
        assert.equal(drawing.markups.at(-1).textStyle.background, 'none');
        assert.equal(drawing.markups.at(-1).fillColor, undefined);
        // Pixel evidence uses the exact renderer shared by canvas and paper, with no mock context.
        const pixels = await page.evaluate(() => {
            const c = document.createElement('canvas'); c.width = c.height = 200;
            const ctx = c.getContext('2d'), M = window.CADMarkup;
            const item = {id:'pixel',kind:'ellipse',points:M.rect({x:20,y:50},{x:180,y:150}),color:'#0000ff',fillColor:'#ff0000',fillOpacity:.5,opacity:1,width:4,size:10,pattern:'solid',text:'',locked:false,dash:'solid'};
            const pixel = (x,y) => Array.from(ctx.getImageData(x,y,1,1).data);
            M.draw(ctx,item,p=>p,1,{paper:true});
            const fill = pixel(100,100), border = pixel(20,100), corner = pixel(21,51);
            ctx.clearRect(0,0,200,200); M.draw(ctx,{...item,fillColor:'none'},p=>p,1,{paper:true});
            const empty = pixel(100,100);
            ctx.clearRect(0,0,200,200);
            M.draw(ctx,{...item,kind:'route',points:[{x:10,y:100},{x:190,y:100}],dash:'dash',arrows:'none'},p=>p,1,{paper:true});
            const dashed = Array.from({length:160},(_,i)=>pixel(i+20,100)[3]);
            return {fill,border,corner,empty,hasGap:dashed.some(v=>v===0),hasInk:dashed.some(v=>v===255)};
        });
        assert.deepEqual(pixels.fill, [255,0,0,128]);
        assert.deepEqual(pixels.border, [0,0,255,255]);
        assert.equal(pixels.corner[3], 0);
        assert.equal(pixels.empty[3], 0);
        assert.ok(pixels.hasGap && pixels.hasInk);
        const persisted = structuredClone(drawing.markups);
        await page.reload();
        await expect(page.locator('#status')).toContainText('SYNTHETIC WORKBENCH');
        await tool('arrow');
        await drag([10, 105], [35, 115]);
        await save();
        assert.deepEqual(drawing.markups.slice(0, -1), persisted);
        if (process.env.ERP_CAD_EXPRESSION_SCREENSHOT) {
            await page.evaluate(() => { document.getElementById('tools').scrollTop = 0; document.getElementById('properties').scrollTop = 0; });
            await page.screenshot({path:process.env.ERP_CAD_EXPRESSION_SCREENSHOT});
        }
        await page.setViewportSize({ width: 700, height: 900 });
        assert.ok(await page.locator('#markup-tools').evaluate(el => el.scrollWidth <= el.clientWidth + 1), 'tools fit the narrow sidebar');
        await page.setViewportSize({ width: 1500, height: 1000 });
        drawing.canManage = false;
        await page.reload();
        await expect(page.locator('[data-markup-tool="circle"]')).toBeDisabled();
        await expect(page.locator('#mw-repeat')).toBeDisabled();
        assert.deepEqual(errors, []);
        console.log(JSON.stringify({passed:true, tools:specifications.length, checks:['styles','pixels','ellipse-grips','move','resize','array-undo-redo','text-defaults','reload','limits','locks','permissions','source-preserved']}));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
