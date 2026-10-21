// Synthetic records only. Exercise production scripts with real pointer/keyboard events.
const { chromium, expect } = require("@playwright/test");
const fs = require("node:fs"),
    vm = require("node:vm"),
    assert = require("node:assert/strict");
const context = { window: {} };
vm.runInNewContext(fs.readFileSync("public/js/cad-core.js", "utf8"), context);
const C = context.window.CADCore;
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
        page.setDefaultTimeout(10000);
        await page.addInitScript(() => {
            window.print = () => {
                window.printRequested = true;
            };
        });
        const initial = C.parse(
            "0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n",
        );
        const line = (x, y, a, b) =>
            initial.entities.push(
                C.newEntity("LINE", { p: { x, y }, q: { x: a, y: b } }),
            );
        initial.entities.push(
            C.newEntity("POINT", { p: { x: 0, y: 0 } }),
            C.newEntity("POINT", { p: { x: 200, y: 120 } }),
        );
        initial.entities.push(
            C.newEntity("LWPOLYLINE", {
                closed: true,
                points: [
                    { x: 125, y: 25 },
                    { x: 180, y: 25 },
                    { x: 180, y: 60 },
                    { x: 125, y: 60 },
                ],
            }),
        );
        for (const [x, y, a, b] of [
            [10, 10, 190, 10],
            [190, 10, 190, 110],
            [190, 110, 10, 110],
            [10, 110, 10, 10],
            [80, 10, 80, 80],
            [80, 95, 80, 110],
            [120, 10, 120, 80],
            [120, 95, 120, 110],
            [10, 65, 80, 65],
            [120, 65, 190, 65],
        ])
            line(x, y, a, b);
        for (const [x, y, text] of [
            [25, 38, "MECHANICAL"],
            [25, 88, "KITCHEN"],
            [135, 38, "OFFICE"],
            [135, 88, "STORAGE"],
        ])
            initial.entities.push(
                C.newEntity("TEXT", { p: { x, y }, text, height: 3 }),
            );
        const original = C.exportDXF(initial).replace(/\r\n/g, "\n");
        let drawing = {
                id: 9,
                title: "SYNTHETIC · 1F 설비 검토",
                site_id: 1,
                revision: 1,
                dxf: original,
                markups: [],
                canManage: true,
                canExport: true,
                warnings: [],
            },
            writes = 0,
            conflict = false,
            holdSave = null,
            saveStarted = null;
        const revisions = new Map([[1, structuredClone(drawing)]]);
        const html = fs
            .readFileSync("resources/views/cad/index.blade.php", "utf8")
            .replace(/<script[^>]*>[\s\S]*?<\/script>/g, "")
            .replace(
                /<link[^>]*>/g,
                '<link rel="stylesheet" href="/cad-editor.css">',
            )
            .replace("{{ csrf_token() }}", "synthetic")
            .replace(
                "</body>",
                ["cad-core", "cad-markup", "cad-markup-tools", "cad-editor"]
                    .map((n) => `<script src="/${n}.js"></script>`)
                    .join("") + "</body>",
            );
        await page.route("https://synthetic.test/**", async (route) => {
            const u = new URL(route.request().url()),
                method = route.request().method();
            let json;
            if (u.pathname === "/cad")
                return route.fulfill({ contentType: "text/html", body: html });
            if (/^\/cad-[a-z-]+\.js$/.test(u.pathname))
                return route.fulfill({
                    contentType: "text/javascript",
                    body: fs.readFileSync("public/js" + u.pathname, "utf8"),
                });
            if (u.pathname === "/cad-editor.css")
                return route.fulfill({
                    contentType: "text/css",
                    body: fs.readFileSync("public/css/cad-editor.css", "utf8"),
                });
            if (u.pathname === "/admin-api/cad/capabilities")
                json = {
                    dwgRead: false,
                    dwgWrite: false,
                    message: "합성 도면 · 실제 현장 데이터 없음",
                };
            else if (u.pathname === "/admin-api/cad")
                json = {
                    canManage: true,
                    sites: [{ id: 1, name: "테스트 현장" }],
                    drawings: [drawing],
                };
            else if (u.pathname === "/admin-api/cad/9/history")
                json = {
                    ...revisions.get(Number(u.searchParams.get("revision"))),
                    canManage: false,
                };
            else if (u.pathname === "/admin-api/cad/9" && method === "GET")
                json = drawing;
            else if (u.pathname === "/admin-api/cad/9" && method === "PUT") {
                if (conflict)
                    return route.fulfill({
                        status: 409,
                        contentType: "application/json",
                        body: '{"message":"Conflict"}',
                    });
                const payload = route.request().postDataJSON();
                assert.equal(payload.revision, drawing.revision);
                assert.equal(
                    payload.dxf,
                    original,
                    "Annotation-only saves must preserve the exact original DXF bytes",
                );
                if (saveStarted) saveStarted();
                if (holdSave) await holdSave;
                drawing = {
                    ...drawing,
                    revision: drawing.revision + 1,
                    markups: payload.markups,
                };
                writes++;
                revisions.set(drawing.revision, structuredClone(drawing));
                json = drawing;
            } else return route.fulfill({ status: 404, body: "not found" });
            await route.fulfill({
                contentType: "application/json",
                body: JSON.stringify(json),
            });
        });
        await page.goto("https://synthetic.test/cad?drawing_id=9");
        await expect(page.locator("#status")).toContainText("SYNTHETIC");
        await page.locator("#snap").uncheck();
        const box = await page.locator("#canvas").boundingBox(),
            scale = 0.85 * Math.min(box.width / 200, box.height / 120);
        const screen = (x, y) => ({
            x: box.x + box.width / 2 + (x - 100) * scale,
            y: box.y + box.height / 2 - (y - 60) * scale,
        });
        const move = async (x, y) => {
            const p = screen(x, y);
            await page.mouse.move(p.x, p.y, { steps: 8 });
        };
        const click = async (x, y) => {
            const p = screen(x, y);
            await page.mouse.click(p.x, p.y);
        };
        const drag = async (a, b) => {
            await move(...a);
            await page.mouse.down();
            await move(...b);
            await page.mouse.up();
        };
        const tool = (name) =>
            page.locator(`[data-markup-tool="${name}"]`).click();
        const count = (n) =>
            expect(page.locator("#markup-count")).toHaveText(`${n}개 표시`);
        const save = async () => {
            const rev = drawing.revision;
            await page.locator("#save").click();
            await expect(page.locator("#dirty")).toHaveText(
                `개정 ${rev + 1} 저장됨`,
            );
        };
        await tool("boundary");
        await click(150, 40);
        await count(1);
        await save();
        assert.deepEqual(drawing.markups[0].points, [
            { x: 125, y: 25 },
            { x: 180, y: 25 },
            { x: 180, y: 60 },
            { x: 125, y: 60 },
        ]);
        await page.locator("#undo").click();
        await count(0);
        await save();
        await tool("box");
        await drag([20, 20], [70, 55]);
        await count(1);
        await page.locator("#markup-pattern").selectOption("diagonal");
        await save();
        const areaId = drawing.markups[0].id;
        assert.equal(drawing.markups[0].pattern, "diagonal");
        await tool("area");
        for (const p of [
            [130, 20],
            [175, 20],
            [170, 55],
            [135, 58],
        ])
            await click(...p);
        await page.keyboard.press("Enter");
        await count(2);
        await page.locator('[data-markup-color="#34d399"]').click();
        await tool("cloud");
        await drag([130, 75], [180, 103]);
        await count(3);
        await page.locator('[data-markup-color="#fb7185"]').click();
        await page.locator("#undo").click();
        await count(3); // undo the color change, then the whole drawing gesture
        await page.locator("#undo").click();
        await count(2);
        await page.locator("#redo").click();
        await count(3);
        await tool("callout");
        await drag([34, 78], [48, 106]);
        await expect(page.locator("#markup-dialog")).toBeVisible();
        await page.locator("#markup-dialog-confirm").click();
        await expect(page.locator("#markup-dialog-error")).toContainText(
            "내용",
        );
        await page
            .locator("#markup-dialog-text")
            .fill("배관 경로 변경\n현장 확인 요청");
        await page.locator("#markup-dialog-confirm").click();
        await count(4);
        await tool("pen");
        await move(88, 40);
        await page.mouse.down();
        await move(94, 48);
        await move(111, 40);
        await page.mouse.up();
        await count(5);
        await tool("highlight");
        await drag([30, 15], [170, 15]);
        await count(6);
        await tool("pin");
        await click(100, 88);
        await count(7);
        await page.locator("#markup-edit-text").click();
        await page.locator("#markup-dialog-text").fill("!");
        await page.locator("#markup-dialog-confirm").click();
        await save();
        assert.deepEqual(
            drawing.markups.map((m) => m.kind),
            ["area", "area", "cloud", "callout", "pen", "highlight", "pin"],
        );
        // Select by list even when shapes overlap; edit a vertex, lock, copy, order, and undo.
        await page.locator(`[data-markup-id="${areaId}"]`).click();
        await drag([20, 20], [16, 20]);
        await save();
        assert.ok(Math.abs(drawing.markups[0].points[0].x - 16) < 0.1);
        await page.locator("#markup-lock").click();
        await expect(page.locator("#markup-delete")).toBeDisabled();
        await page.locator("#markup-lock").click();
        await page.locator("#markup-copy").click();
        await count(8);
        await page.locator("#markup-back").click();
        await save();
        assert.notEqual(drawing.markups[0].id, areaId);
        await page.locator("#markup-delete").click();
        await count(7);
        await save();
        // Cancel a partial polygon without changing history; failed save retains the full working overlay.
        await tool("area");
        await click(85, 20);
        await click(100, 25);
        await page.keyboard.press("Escape");
        await count(7);
        await tool("pin");
        await click(110, 75);
        await count(8);
        conflict = true;
        await page.locator("#save").click();
        await expect(page.locator("#status")).toContainText(
            "현재 편집과 표시는",
        );
        await count(8);
        conflict = false;
        await save();
        // A pending save blocks mutation and discards no working data.
        await tool("pin");
        await click(110, 65);
        let release;
        holdSave = new Promise((resolve) => {
            release = resolve;
        });
        const started = new Promise((resolve) => {
            saveStarted = resolve;
        });
        await page.locator("#save").click();
        await started;
        await click(150, 60);
        await count(9);
        release();
        await expect(page.locator("#dirty")).toContainText("저장됨");
        holdSave = null;
        saveStarted = null;
        await page.locator("#reload").click();
        await expect(page.locator("#status")).toContainText("SYNTHETIC");
        await count(9);
        await expect(page.locator('[data-markup-tool="box"]')).toBeEnabled();
        assert.equal(
            drawing.markups.find((m) => m.kind === "callout").text,
            "배관 경로 변경\n현장 확인 요청",
        );
        await page.locator("#markup-visible").uncheck();
        await page.locator("#print-markups").click();
        assert.ok(await page.evaluate(() => window.printRequested));
        assert.match(
            await page.locator("#cad-print-image").getAttribute("src"),
            /^data:image\/png/,
        );
        await page.emulateMedia({ media: "print" });
        await expect(page.locator("#cad-print-image")).toBeVisible();
        await expect(page.locator("header")).toBeHidden();
        await page.emulateMedia({ media: "screen" });
        await page.locator("#markup-visible").check();
        fs.mkdirSync("test-results/assistant-browser", { recursive: true });
        await page.screenshot({
            path: "test-results/assistant-browser/SYNTHETIC-cad-markup.png",
        });
        const savedRevision = drawing.revision;
        await page.goto(
            `https://synthetic.test/cad?drawing_id=9&revision=${savedRevision}`,
        );
        await expect(page.locator("#status")).toContainText("SYNTHETIC");
        await count(9);
        await expect(page.locator('[data-markup-tool="box"]')).toBeDisabled();
        await expect(page.locator("#save")).toBeDisabled();
        await page.locator(`[data-markup-id="${areaId}"]`).click();
        await drag([16, 20], [60, 40]);
        assert.deepEqual(revisions.get(savedRevision).markups, drawing.markups);
        await page.setViewportSize({ width: 390, height: 844 });
        await expect(page.locator("#canvas")).toBeVisible();
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        );
        assert.deepEqual(errors, []);
        console.log(
            JSON.stringify({
                passed: true,
                writes,
                markups: drawing.markups.length,
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
