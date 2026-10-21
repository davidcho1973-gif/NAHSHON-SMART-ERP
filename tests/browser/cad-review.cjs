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
        page.on("pageerror", (e) => errors.push(e.message));
        page.on("dialog", (d) => d.accept());
        const context = { window: {} };
        vm.runInNewContext(
            fs.readFileSync("public/js/cad-core.js", "utf8"),
            context,
        );
        const C = context.window.CADCore;
        const d = C.parse(
            "0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n",
        );
        d.entities.push(
            C.newEntity("LINE", { p: { x: 0, y: 0 }, q: { x: 200, y: 120 } }),
            C.newEntity("CIRCLE", { p: { x: 40, y: 40 }, radius: 6 }),
            C.newEntity("CIRCLE", { p: { x: 80, y: 40 }, radius: 6 }),
        );
        const source = C.exportDXF(d),
            old = C.clone(d);
        old.entities.pop();
        const oldSource = C.exportDXF(old);
        let drawing = {
            id: 9,
            title: "SYNTHETIC REVIEW",
            site_id: 1,
            revision: 2,
            canManage: true,
            canExport: true,
            dxf: source,
            markups: [],
            workspace: {},
        };
        const assets = [
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
            .replaceAll("{{ csrf_token() }}", "synthetic")
            .replaceAll("{{ auth()->id() }}", "synthetic-review")
            .replaceAll("{{ \\App\\Support\\Org::name() }}", "SYNTHETIC ERP")
            .replace(
                "</body>",
                assets.map((n) => `<script src="/${n}.js"></script>`).join("") +
                    "</body>",
            );
        await page.route("https://synthetic.test/**", async (route) => {
            const u = new URL(route.request().url()),
                p = u.pathname;
            let data;
            if (p === "/cad")
                return route.fulfill({ contentType: "text/html", body: html });
            if (p === "/cad-editor.css")
                return route.fulfill({
                    contentType: "text/css",
                    body: fs.readFileSync("public/css/cad-editor.css", "utf8"),
                });
            if (p === "/polygon-clipping.js")
                return route.fulfill({
                    contentType: "text/javascript",
                    body: fs.readFileSync(
                        "public/vendor/polygon-clipping/polygon-clipping.js",
                        "utf8",
                    ),
                });
            if (/^\/cad-[a-z-]+\.js$/.test(p))
                return route.fulfill({
                    contentType: "text/javascript",
                    body: fs.readFileSync("public/js" + p, "utf8"),
                });
            if (p === "/admin-api/cad/capabilities")
                data = { dwgRead: false, dwgWrite: false };
            else if (p === "/admin-api/cad")
                data = {
                    sites: [{ id: 1, name: "SYNTHETIC" }],
                    drawings: [drawing],
                    canManage: true,
                };
            else if (p === "/admin-api/cad/9/history")
                data = u.searchParams.has("revision")
                    ? { ...drawing, revision: 1, dxf: oldSource }
                    : { revisions: [{ revision: 1 }] };
            else if (p === "/admin-api/cad/9") {
                if (route.request().method() === "PUT") {
                    const input = route.request().postDataJSON();
                    assert.equal(input.dxf, source);
                    assert.equal(input.revision, drawing.revision);
                    drawing = {
                        ...drawing,
                        ...input,
                        revision: drawing.revision + 1,
                    };
                }
                data = drawing;
            } else return route.fulfill({ status: 404, body: "not fixture" });
            return route.fulfill({ json: data });
        });
        await page.goto("https://synthetic.test/cad?drawing_id=9");
        await expect(page.locator("#status")).toContainText("SYNTHETIC REVIEW");
        await page.locator("#snap").uncheck();
        await page.locator("#cad-review-tools summary").click();
        await page.locator("#cr-count").click();
        await page.locator("#cr-seed").selectOption("e1");
        await expect(page.locator("#cr-list")).toContainText(
            "후보 2개 · 선택 0개",
        );
        await page
            .getByRole("button", { name: "전체 선택", exact: true })
            .click();
        await page.locator("#cr-symbol-name").fill("밸브");
        await page.locator("#cr-apply").click();
        await page.locator("#save").click();
        await expect(page.locator("#dirty")).toHaveText("개정 3 저장됨");
        assert.equal(
            drawing.markups.filter((m) => m.symbol === "밸브").length,
            2,
        );
        await page.locator("#cr-compare").click();
        await page.locator("#cr-load").click();
        await expect(page.locator("#cr-state")).toContainText("좌우 도면");
        await page.locator("#cr-run").click();
        await expect(page.locator("#cr-state")).toContainText(
            "추가 1 / 삭제 0 / 변경 0",
        );
        await page.locator("#cr-list input[type=checkbox]").check();
        await page.locator("#cr-apply").click();
        await page.locator("#save").click();
        await expect(page.locator("#dirty")).toHaveText("개정 4 저장됨");
        assert.ok(
            drawing.markups.some(
                (m) => m.kind === "cloud" && m.text.includes("r1"),
            ),
        );
        const b = await page.locator("#canvas").boundingBox();
        await page.locator("#cr-detail").click();
        await page.mouse.move(b.x + b.width * 0.25, b.y + b.height * 0.4);
        await page.mouse.down();
        await page.mouse.move(b.x + b.width * 0.45, b.y + b.height * 0.6, {
            steps: 10,
        });
        await page.mouse.up();
        await expect(page.locator("#markup-list")).toContainText("확대 상세도");
        await page.locator("#save").click();
        await expect(page.locator("#dirty")).toHaveText("개정 5 저장됨");
        const detail = drawing.markups.find((m) => m.kind === "detail");
        assert.ok(detail.detail.maxX > detail.detail.minX);
        await page.locator("#reload").click();
        await expect(page.locator("#markup-list")).toContainText("확대 상세도");
        await page.locator("#print-markups").click();
        await expect(page.locator("#cp-preview")).toBeVisible();
        await page.locator("#cp-close").click();
        fs.mkdirSync("test-results/assistant-browser", { recursive: true });
        await page.screenshot({
            path: "test-results/assistant-browser/SYNTHETIC-cad-review.png",
        });
        assert.deepEqual(errors, []);
        console.log(
            JSON.stringify({
                reviewedPins: 2,
                comparisonClouds: 1,
                linkedDetails: 1,
                revision: drawing.revision,
                errors,
            }),
        );
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
