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
        doc.entities.push(
            C.newEntity("LINE", {
                p: { x: 80, y: 100 },
                q: { x: 120, y: 100 },
            }),
            C.newEntity("LINE", {
                p: { x: 100, y: 80 },
                q: { x: 100, y: 120 },
            }),
            C.newEntity("CIRCLE", { p: { x: 160, y: 90 }, radius: 8 }),
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

        await page.locator("#mw-infer").uncheck();
        await expect(
            page.locator("[data-markup-tool]").first(),
        ).toHaveAttribute("data-markup-tool", "select");
        const approx = (a, b, tolerance = 0.02) =>
            assert.ok(Math.abs(a - b) < tolerance, `${a} != ${b}`);
        const specs = [
            [
                "arc2",
                [
                    [15, 20],
                    [45, 20],
                    [30, 35],
                ],
                "arc",
            ],
            [
                "arc3",
                [
                    [55, 20],
                    [70, 35],
                    [85, 20],
                ],
                "arc",
            ],
            [
                "arc",
                [
                    [110, 20],
                    [125, 20],
                    [110, 35],
                ],
                "arc",
            ],
            [
                "pie",
                [
                    [150, 20],
                    [170, 20],
                    [150, 40],
                ],
                "arc",
            ],
            [
                "curve",
                [
                    [15, 55],
                    [45, 55],
                    [30, 80],
                ],
                "curve",
            ],
            [
                "cubic",
                [
                    [55, 55],
                    [55, 85],
                    [85, 35],
                    [85, 65],
                ],
                "curve",
            ],
            [
                "rotbox",
                [
                    [110, 55],
                    [135, 65],
                    [130, 80],
                ],
                "area",
            ],
            [
                "rounded",
                [
                    [15, 90],
                    [45, 110],
                ],
                "area",
            ],
            [
                "capsule",
                [
                    [55, 90],
                    [80, 110],
                ],
                "area",
            ],
        ];
        for (const [name, points, kind] of specs) {
            await tool(name);
            for (const p of points) await click(...p);
            await save();
            assert.equal(drawing.markups.at(-1).kind, kind, name);
        }
        const arc = drawing.markups[0];
        approx(arc.arc.sweep, -Math.PI);
        assert.equal(arc.points.length, 4);
        assert.equal(drawing.markups[3].arc.pie, true);
        assert.equal(drawing.markups[4].arrows, "end");
        assert.equal(drawing.markups[5].points.length, 4);
        await tool("line");
        await click(130, 95);
        await page.locator("#markup-numeric").fill("20<30");
        await page.locator("#markup-numeric").press("Enter");
        await save();
        const numeric = drawing.markups.at(-1);
        approx(numeric.points[1].x - numeric.points[0].x, Math.sqrt(300));
        approx(numeric.points[1].y - numeric.points[0].y, 10);
        await tool("arc");
        await click(180, 70);
        await click(190, 70);
        await page.locator("#markup-numeric").fill("120");
        await page.locator("#markup-numeric").press("Enter");
        await save();
        approx(drawing.markups.at(-1).arc.sweep, (2 * Math.PI) / 3);
        assert.deepEqual(
            await page.locator("#canvas").boundingBox(),
            box,
            "saving revisions must not move the canvas",
        );
        await page.locator("#mw-snap").check();
        await tool("arrow");
        let p = screen(100, 100);
        await page.mouse.move(p.x + 1, p.y + 1);
        await expect(page.locator("#canvas")).toHaveAttribute(
            "data-markup-snap",
            "교차점",
        );
        await page.mouse.click(p.x + 1, p.y + 1);
        await click(160, 90);
        await save();
        const snapped = drawing.markups.at(-1);
        approx(snapped.points[0].x, 100, 1e-8);
        approx(snapped.points[0].y, 100, 1e-8);
        approx(snapped.points[1].x, 160, 1e-8);
        approx(snapped.points[1].y, 90, 1e-8);
        await page.locator("#mw-snap").uncheck();
        await tool("box");
        await drag([155, 45], [190, 65]);
        await save();
        let region = drawing.markups.at(-1);
        const originalRegion = structuredClone(region);
        await tool("select");
        await select([region.id]);
        await tool("split");
        await click(172, 40);
        await click(172, 70);
        await save();
        let parts = drawing.markups.slice(-2);
        assert.equal(parts.length, 2);
        assert.ok(parts.every((m) => m.kind === "area"));
        await page.locator("#canvas").focus();
        await page.keyboard.press("Control+z");
        await save();
        assert.deepEqual(drawing.markups.at(-1), originalRegion);
        await page.locator("#canvas").focus();
        await page.keyboard.press("Control+y");
        await save();
        parts = drawing.markups.slice(-2);
        await tool("select");
        await select([parts[0].id]);
        await tool("hole");
        const xs = parts[0].points.map((p) => p.x),
            minX = Math.min(...xs);
        for (const p of [
            [minX + 3, 50],
            [minX + 7, 50],
            [minX + 7, 55],
            [minX + 3, 55],
        ])
            await click(...p);
        await page.keyboard.press("Enter");
        await save();
        const holed = drawing.markups.find((m) => m.id === parts[0].id);
        assert.equal(holed.holes.length, 1);
        await tool("select");
        await select([holed.id]);
        await detail("경계 편집");
        await page.locator("#mw-offset-distance").fill("1");
        await page.locator("#mw-offset").click();
        await save();
        assert.equal(drawing.markups.at(-1).holes.length, 1);
        assert.notEqual(drawing.markups.at(-1).id, holed.id);
        const pixels = await page.evaluate(
            ({ x, y }) => {
                const c = document.getElementById("canvas"),
                    b = c.getBoundingClientRect(),
                    ctx = c.getContext("2d"),
                    ratio = c.width / b.width;
                return Array.from(
                    ctx.getImageData(
                        Math.round(x * ratio) - 2,
                        Math.round(y * ratio) - 2,
                        5,
                        5,
                    ).data,
                );
            },
            { x: screen(30, 35).x - box.x, y: screen(30, 35).y - box.y },
        );
        assert.ok(
            pixels.some(
                (v, i) =>
                    i % 4 === 0 &&
                    v < 100 &&
                    pixels[i + 1] > 140 &&
                    pixels[i + 2] > 160,
            ),
            "arc is actually drawn at the expected curved edge",
        );
        await tool("select");
        const cubic = drawing.markups[5];
        await select([cubic.id]);
        await drag([55, 85], [60, 88]);
        await save();
        approx(drawing.markups.find((m) => m.id === cubic.id).points[1].x, 60);
        approx(drawing.markups.find((m) => m.id === cubic.id).points[1].y, 88);
        await tool("line");
        await click(94, 92);
        await click(106, 108);
        await save();
        const trimmedId = drawing.markups.at(-1).id;
        await tool("select");
        await select([trimmedId]);
        await tool("trim");
        await click(102, 102.6667);
        await save();
        approx(
            drawing.markups.find((m) => m.id === trimmedId).points[1].x,
            100,
        );
        approx(
            drawing.markups.find((m) => m.id === trimmedId).points[1].y,
            100,
        );
        await tool("line");
        await click(94, 94);
        await click(98, 98);
        await save();
        const extendedId = drawing.markups.at(-1).id;
        await tool("select");
        await select([extendedId]);
        await tool("extend");
        await click(97.5, 97.5);
        await save();
        approx(
            drawing.markups.find((m) => m.id === extendedId).points[1].x,
            100,
        );
        approx(
            drawing.markups.find((m) => m.id === extendedId).points[1].y,
            100,
        );
        for (const [button, points, distance] of [
            [
                "fillet",
                [
                    [95, 45],
                    [95, 5],
                    [130, 5],
                ],
                4,
            ],
            [
                "chamfer",
                [
                    [130, 45],
                    [130, 40],
                    [145, 40],
                ],
                2,
            ],
        ]) {
            const ids = [];
            for (let i = 0; i < 2; i++) {
                await tool("line");
                await click(...points[i]);
                await click(...points[i + 1]);
                await save();
                ids.push(drawing.markups.at(-1).id);
            }
            await tool("select");
            await select(ids);
            const before = structuredClone(drawing.markups);
            await detail("경계 편집");
            await page.locator("#mw-corner-distance").fill(String(distance));
            await page.locator("#mw-" + button).click();
            await save();
            assert.equal(drawing.markups.length, before.length + 1);
            assert.equal(
                drawing.markups.at(-1).kind,
                button === "fillet" ? "arc" : "route",
            );
            await page.locator("#canvas").focus();
            await page.keyboard.press("Control+z");
            await save();
            assert.deepEqual(drawing.markups, before);
            await page.locator("#canvas").focus();
            await page.keyboard.press("Control+y");
            await save();
        }
        await tool("select");
        await select([drawing.markups[3].id]);
        await detail("선 · 채우기");
        await page.locator("#mw-trade-style").selectOption("fire");
        await page.locator("#mw-trade-apply").click();
        await save();
        assert.equal(drawing.markups[3].fillColor, "#dc2626");
        assert.equal(drawing.markups[3].pattern, "diagonal");
        await tool("arc3");
        await click(10, 5);
        await click(20, 5);
        await click(30, 5);
        await expect(page.locator("#status")).toContainText("일직선");
        const invalidRevision = drawing.revision;
        await page.keyboard.press("Escape");
        assert.equal(drawing.revision, invalidRevision);
        await tool("line");
        await click(10, 8);
        await page.keyboard.press("ArrowRight");
        await click(30, 11);
        await save();
        approx(drawing.markups.at(-1).points[1].y, 8);
        await page.keyboard.press("Escape");
        const saved = structuredClone(drawing.markups);
        await page.reload();
        await expect(page.locator("#status")).toContainText(
            "SYNTHETIC WORKBENCH",
        );
        await tool("pin");
        await click(180, 110);
        await save();
        assert.deepEqual(drawing.markups.slice(0, saved.length), saved);
        await tool("select");
        await select([saved[0].id]);
        await page.locator("#markup-lock").click();
        await save();
        const locked = structuredClone(drawing.markups[0]);
        await detail("경계 편집");
        const lockedRevision = drawing.revision;
        await page.locator("#mw-offset").click();
        await expect(page.locator("#status")).toContainText("잠긴");
        assert.equal(drawing.revision, lockedRevision);
        assert.deepEqual(drawing.markups[0], locked);
        const rendering = await page.evaluate(() => {
            const M = window.CADMarkup,
                G = window.CADMarkupGeometry,
                c = document.createElement("canvas");
            c.width = 1200;
            c.height = 800;
            const ctx = c.getContext("2d");
            const base = {
                id: "print",
                kind: "arc",
                points: M.rect({ x: 50, y: 50 }, { x: 150, y: 150 }),
                arc: { start: 0, sweep: Math.PI / 2, pie: true },
                color: "#ff0000",
                fillColor: "#ff0000",
                opacity: 1,
                fillOpacity: 1,
                width: 2,
                size: 20,
                pattern: "solid",
                text: "",
                locked: false,
            };
            M.draw(ctx, base, (p) => p, 1, { paper: true });
            const inside = Array.from(ctx.getImageData(120, 120, 1, 1).data),
                outside = ctx.getImageData(80, 80, 1, 1).data[3];
            ctx.clearRect(0, 0, c.width, c.height);
            const arrow = {
                ...base,
                kind: "curve",
                points: [
                    { x: 50, y: 150 },
                    { x: 50, y: 150 },
                    { x: 50, y: 50 },
                    { x: 50, y: 50 },
                ],
                arrows: "both",
            };
            delete arrow.arc;
            delete arrow.fillColor;
            delete arrow.fillOpacity;
            M.draw(ctx, arrow, (p) => p, 1, { paper: true });
            const wing = ctx.getImageData(47, 58, 1, 1).data[3];
            ctx.clearRect(0, 0, c.width, c.height);
            M.draw(ctx, arrow, (p) => ({ x: p.x * 3, y: p.y * 3 }), 3, {
                paper: true,
                lineScale: 3,
            });
            const printWing = ctx.getImageData(141, 174, 1, 1).data[3];
            const items = Array.from({ length: 1000 }, (_, i) => ({
                ...arrow,
                id: "stress-" + i,
                points: [
                    { x: (i % 40) * 30, y: Math.floor(i / 40) * 30 },
                    { x: (i % 40) * 30, y: Math.floor(i / 40) * 30 + 20 },
                    { x: (i % 40) * 30 + 20, y: Math.floor(i / 40) * 30 },
                    { x: (i % 40) * 30 + 20, y: Math.floor(i / 40) * 30 + 20 },
                ],
            }));
            const start = performance.now();
            for (const item of items)
                M.draw(ctx, item, (p) => p, 1, { paper: true });
            const renderMs = performance.now() - start;
            const pickStart = performance.now();
            for (const item of items) M.hit(item, { x: 150, y: 150 }, 2);
            const pickMs = performance.now() - pickStart;
            return {
                inside,
                outside,
                wing,
                printWing,
                renderMs: Math.round(renderMs),
                pickMs: Math.round(pickMs),
                valid: G.validSet(items),
                count: items.length,
            };
        });
        assert.deepEqual(rendering.inside, [255, 0, 0, 255]);
        assert.equal(rendering.outside, 0);
        assert.ok(
            rendering.wing > 100 && rendering.printWing > 100,
            "coincident Bezier handles still produce tangent arrowheads at screen and print DPI",
        );
        assert.ok(rendering.valid);
        assert.ok(
            rendering.renderMs < 2000 && rendering.pickMs < 2000,
            "bounded 1000-curve render and picking must finish promptly",
        );
        await page.locator("#canvas-background").selectOption("white");
        await tool("arc2");
        if (process.env.CAD_DRAFTING_SCREENSHOT)
            await page.screenshot({
                path: process.env.CAD_DRAFTING_SCREENSHOT,
                fullPage: true,
            });
        for (const width of [1180, 800]) {
            await page.setViewportSize({ width, height: 900 });
            await tool("arc2");
            await expect(
                page.locator('[data-markup-tool="arc2"]'),
            ).toBeVisible();
            const bounds = await page.locator("#canvas").boundingBox();
            assert.ok(bounds.width > 200 && bounds.height > 200);
        }
        drawing.canManage = false;
        await page.reload();
        await expect(page.locator('[data-markup-tool="arc2"]')).toBeDisabled();
        assert.equal(errors.length, 0, errors.join("\n"));
        console.log(
            JSON.stringify({
                passed: true,
                tools: specs.length,
                rendering,
                checks: [
                    "arcs",
                    "bezier",
                    "rounded",
                    "numeric",
                    "source-snaps",
                    "split-history",
                    "holes",
                    "offset",
                    "trim",
                    "extend",
                    "fillet",
                    "chamfer",
                    "control-grips",
                    "trade-styles",
                    "invalid-input",
                    "direction-lock",
                    "pixels",
                    "reload",
                    "locks",
                    "permissions",
                    "source-bytes",
                ],
            }),
        );
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
