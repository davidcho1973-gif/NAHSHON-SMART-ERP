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
        await tool("box");
        await drag([20, 20], [90, 90]);
        await tool("box");
        await drag([40, 40], [60, 60]);
        await save();
        const [outer, inner] = drawing.markups;
        await select([outer.id, inner.id]);
        await page.locator("#mw-difference").click();
        await save();
        assert.equal(drawing.markups.length, 1);
        assert.equal(drawing.markups[0].holes.length, 1);
        const hole = structuredClone(drawing.markups[0].holes);
        await page.locator("#mw-angle").fill("90");
        await page.locator("#mw-factor").fill("1.5");
        await page.locator("#mw-transform").click();
        await save();
        assert.notDeepEqual(drawing.markups[0].holes, hole);
        await page.locator("#undo").click();
        await save();
        assert.deepEqual(drawing.markups[0].holes, hole);
        await tool("freecloud");
        await click(110, 60);
        await click(170, 60);
        await click(180, 100);
        await click(120, 105);
        await page.keyboard.press("Enter");
        await save();
        assert.equal(drawing.markups.at(-1).kind, "cloud");
        assert.equal(drawing.markups.at(-1).points.length, 4);
        await detail("문자 · 변경");
        await page.locator("#mw-label").fill("REV-03");
        await page.locator("#mw-label-apply").click();
        await save();
        assert.equal(drawing.markups.at(-1).label, "REV-03");
        await tool("text");
        await click(115, 45);
        await page.locator("#markup-dialog-text").fill("배관 변경\n현장 확인");
        await page.locator("#markup-dialog-confirm").click();
        await page.locator("#mw-bold").check();
        await page.locator("#mw-text-size").fill("2");
        await page.locator("#mw-text-width").fill("35");
        await page.locator("#mw-text-align").selectOption("center");
        await page.locator("#mw-text-apply").click();
        await save();
        const text = drawing.markups.at(-1);
        assert.equal(text.kind, "text");
        assert.equal(text.textStyle.bold, true);
        assert.equal(text.textStyle.boxWidth, 35);
        await select([drawing.markups[1].id, text.id]);
        await page.locator("#mw-group").click();
        await save();
        assert.equal(drawing.markups[1].group, drawing.markups[2].group);
        await page.locator("#markup-copy").click();
        await save();
        assert.notEqual(drawing.markups[1].group, drawing.markups.at(-1).group);
        assert.equal(
            drawing.markups.at(-1).group,
            drawing.markups.at(-2).group,
        );
        await page.locator("#undo").click();
        await save();
        await detail("단위 · 축척");
        await page.locator("#mw-unit").selectOption("mm");
        await page.locator("#mw-unit-apply").click();
        await save();
        assert.equal(drawing.workspace.measurement.metersPerUnit, 0.001);
        await select([drawing.markups[1].id]);
        await detail("공종 레이어");
        await page.locator("#mw-layer-name").fill("소방");
        await page.locator("#mw-layer-add").click();
        await page.locator("#mw-layer-assign").click();
        await page.locator("#mw-underlay-gray").check();
        await save();
        assert.equal(drawing.workspace.layers[0].name, "소방");
        assert.equal(drawing.workspace.underlay.grayscale, true);
        assert.equal(drawing.markups[1].layer, drawing.workspace.layers[0].id);
        await tool("route");
        await click(15, 105);
        await click(65, 105);
        await click(65, 115);
        await page.keyboard.press("Enter");
        await save();
        assert.equal(drawing.markups.at(-1).kind, "route");
        assert.equal(drawing.markups.at(-1).points.length, 3);
        await tool("pen");
        await drag([100, 20], [180, 20]);
        await save();
        const pen = drawing.markups.at(-1);
        await tool("eraser");
        await drag([140, 12], [140, 28]);
        await save();
        const segments = drawing.markups.filter((m) => m.kind === "pen");
        assert.equal(segments.length, 2);
        assert.deepEqual(segments[0].points[0], pen.points[0]);
        assert.deepEqual(segments[1].points.at(-1), pen.points.at(-1));
        const saved = structuredClone(drawing);
        await page.locator("#reload").click();
        await expect(page.locator("#status")).toContainText(
            "SYNTHETIC WORKBENCH",
        );
        await expect(page.locator("#mw-underlay-gray")).toBeChecked();
        assert.deepEqual(drawing, saved);
        const beforePrint=structuredClone(drawing);
        const cropPixels=await page.evaluate(dxf=>{
            const doc=CADCore.parse(dxf);doc.entities=[5,20].map(x=>CADCore.newEntity("LINE",{p:{x,y:0},q:{x,y:10},color:0}));doc.markups=[];doc.workspace={measurement:{metersPerUnit:.001,unit:"mm",source:"declared"}};
            const out=CADPrint.render(doc,{id:1,revision:1,title:"Crop test"},{minX:0,minY:0,maxX:10,maxY:10},{paper:"A3",landscape:true,dpi:150,fixed:true,denominator:1,legend:false});
            const ctx=out.canvas.getContext("2d"),sample=x=>{const p=out.plan.screen({x,y:5});return [...ctx.getImageData(Math.floor(p.x),Math.floor(p.y),1,1).data];};return {inside:sample(5),outside:sample(20)};
        },original);
        assert.ok(cropPixels.inside.slice(0,3).reduce((sum,n)=>sum+n,0)<700);assert.deepEqual(cropPixels.outside,[255,255,255,255]);
        await page.locator("#print-markups").click();
        await expect(page.locator("#cad-print-dialog")).toBeVisible();
        await page.locator("#cp-scale").selectOption("fixed");await page.locator("#cp-denominator").fill("1");await page.locator("#cp-preview-button").click();
        await expect(page.locator("#cp-state")).toContainText("1:1");
        await page.locator("#cp-pick").click();await drag([20,20],[150,100]);await expect(page.locator("#cad-print-dialog")).toBeVisible();await expect(page.locator("#cp-scope")).toHaveValue("custom");await page.locator("#cp-preview-button").click();
        const downloaded=page.waitForEvent("download");await page.locator("#cp-download").click();const pdf=await downloaded;fs.mkdirSync("test-results/assistant-browser",{recursive:true});await pdf.saveAs("test-results/assistant-browser/SYNTHETIC-cad-print.pdf");
        assert.match(fs.readFileSync("test-results/assistant-browser/SYNTHETIC-cad-print.pdf").toString("latin1"),/MediaBox \[0 0 1190\.55118 841\.88976\]/);
        await page.screenshot({path:"test-results/assistant-browser/SYNTHETIC-cad-print.png"});await page.locator("#cp-close").click();assert.deepEqual(drawing,beforePrint);
        const beforeRecovery = drawing.markups.length;
        await tool("pin");
        await click(8, 8);
        await expect(page.locator("#cad-recovery-status")).toContainText(
            "임시 저장됨",
        );
        await page.reload();
        await expect(page.locator("#cad-recovery-dialog")).toBeVisible();
        await expect(page.locator("#cad-recovery-restore")).toBeEnabled();
        await page.locator("#cad-recovery-restore").click();
        await expect(page.locator("#markup-count")).toHaveText(
            `${beforeRecovery + 1}개 표시`,
        );
        await save();
        await tool("pin");
        await click(15, 8);
        await expect(page.locator("#cad-recovery-status")).toContainText(
            "임시 저장됨",
        );
        const currentServer = structuredClone(drawing);
        drawing = { ...drawing, revision: drawing.revision + 1 };
        await page.reload();
        await expect(page.locator("#cad-recovery-dialog")).toBeVisible();
        await expect(page.locator("#cad-recovery-restore")).toBeDisabled();
        await expect(page.locator("#cad-recovery-message")).toContainText(
            "개정 충돌",
        );
        await page.locator("#cad-recovery-copy").click();
        await expect(page.locator("#cad-recovery-dialog")).not.toBeVisible();
        await expect(page.locator("#status")).toContainText("복구 사본");
        assert.equal(drawing.id, 10);
        assert.equal(drawing.revision, 1);
        assert.equal(drawing.markups.length, currentServer.markups.length + 1);
        assert.deepEqual(errors, []);
        fs.mkdirSync("test-results/assistant-browser", { recursive: true });
        await page.screenshot({
            path: "test-results/assistant-browser/SYNTHETIC-cad-workbench.png",
        });
        console.log(
            JSON.stringify({
                passed: true,
                revision: drawing.revision,
                tests: [
                    "holes",
                    "transform-undo",
                    "free-cloud",
                    "rich-text",
                    "group-copy",
                    "scale",
                    "layers",
                    "underlay",
                    "route",
                    "partial-eraser",
                    "reload",
                    "draft-recovery",
                    "conflict-copy",
                ],
                errors,
            }),
        );
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
