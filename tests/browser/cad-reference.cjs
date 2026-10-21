const { chromium, expect } = require("@playwright/test"),
    fs = require("node:fs"),
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
        const assets = [
            "polygon-clipping",
            "cad-core",
            "cad-fidelity",
            "cad-reference",
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
            .replaceAll("{{ auth()->id() }}", "synthetic-reference")
            .replaceAll("{{ \\App\\Support\\Org::name() }}", "SYNTHETIC ERP")
            .replace(
                "</body>",
                assets.map((n) => `<script src="/${n}.js"></script>`).join("") +
                    "</body>",
            );
        let drawing = null,
            bytes = null,
            format = null,
            uploadCount = 0;
        const extents = (w, h) =>
            `0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nPOINT\n10\n0\n20\n0\n0\nPOINT\n10\n${w}\n20\n${h}\n0\nENDSEC\n0\nEOF\n`;
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
            if (p.startsWith("/vendor/pdfjs/"))
                return route.fulfill({
                    contentType: p.endsWith(".js")
                        ? "text/javascript"
                        : "application/octet-stream",
                    body: fs.readFileSync("public" + p),
                });
            if (p === "/admin-api/cad/capabilities")
                data = { dwgRead: false, dwgWrite: false };
            else if (
                p === "/admin-api/cad" &&
                route.request().method() === "POST"
            ) {
                uploadCount++;
                drawing = {
                    id: 9,
                    title: "SYNTHETIC " + format,
                    site_id: 1,
                    revision: 1,
                    canManage: true,
                    canExport: true,
                    dxf: extents(400, 300),
                    markups: [],
                    workspace: {},
                    reference: {
                        format,
                        page: 1,
                        width: 400,
                        height: 300,
                        url: "/admin-api/cad/9/reference",
                        sha256: format,
                    },
                };
                data = drawing;
            } else if (p === "/admin-api/cad")
                data = {
                    sites: [{ id: 1, name: "SYNTHETIC" }],
                    drawings: drawing ? [drawing] : [],
                    canManage: true,
                };
            else if (p === "/admin-api/cad/9/reference")
                return route.fulfill({
                    contentType:
                        format === "pdf" ? "application/pdf" : "image/png",
                    body: bytes,
                });
            else if (p === "/admin-api/cad/9") {
                if (route.request().method() === "PUT") {
                    const input = route.request().postDataJSON();
                    assert.equal(input.dxf, drawing.dxf);
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
        await page.goto("https://synthetic.test/cad");
        await page.locator("#sites").selectOption("1");
        const image = await page.evaluate(() => {
            const canvas = document.createElement("canvas");
            canvas.width = 400;
            canvas.height = 300;
            const c = canvas.getContext("2d");
            c.fillStyle = "#fff";
            c.fillRect(0, 0, 400, 300);
            c.fillStyle = "#f00";
            c.fillRect(100, 100, 200, 100);
            return canvas.toDataURL("image/png").split(",")[1];
        });
        format = "png";
        bytes = Buffer.from(image, "base64");
        await page.locator("#upload").setInputFiles({
            name: "SYNTHETIC.png",
            mimeType: "image/png",
            buffer: bytes,
        });
        await expect(page.locator("#status")).toContainText("SYNTHETIC png");
        await expect(page.locator("#dxf")).toBeDisabled();
        await expect(page.locator("#dwg")).toBeDisabled();
        const pixel = await page.evaluate(() => {
            const c = document.getElementById("canvas"),
                r = c.getBoundingClientRect();
            return [
                ...c
                    .getContext("2d")
                    .getImageData(c.width / 2, c.height / 2, 1, 1).data,
            ];
        });
        assert.ok(pixel[0] > 240 && pixel[1] < 30 && pixel[2] < 30);
        await page.locator('[data-markup-tool="cloud"]').click();
        const b = await page.locator("#canvas").boundingBox();
        await page.mouse.move(b.x + b.width * 0.4, b.y + b.height * 0.4);
        await page.mouse.down();
        await page.mouse.move(b.x + b.width * 0.6, b.y + b.height * 0.6, {
            steps: 12,
        });
        await page.mouse.up();
        await page.locator("#save").click();
        await expect(page.locator("#dirty")).toHaveText("개정 2 저장됨");
        assert.equal(drawing.markups.length, 1);
        await page.locator("#reload").click();
        await expect(page.locator("#markup-count")).toHaveText("1개 표시");
        await page.locator("#print-markups").click();
        await expect(page.locator("#cp-preview")).toBeVisible();
        await page.locator("#cp-close").click();
        // Generate a valid single-page PDF with the same physical page coordinates, then run the real bundled PDF.js worker.
        const pdf = await page.evaluate(async () => {
            const canvas = document.createElement("canvas");
            canvas.width = 400;
            canvas.height = 300;
            const c = canvas.getContext("2d");
            c.fillStyle = "#fff";
            c.fillRect(0, 0, 400, 300);
            c.fillStyle = "#00f";
            c.fillRect(100, 100, 200, 100);
            return Array.from(
                new Uint8Array(
                    await CADPrint.pdfBlob(
                        canvas,
                        (400 * 25.4) / 72,
                        (300 * 25.4) / 72,
                    ).arrayBuffer(),
                ),
            );
        });
        format = "pdf";
        bytes = Buffer.from(pdf);
        await page.locator("#upload").setInputFiles({
            name: "SYNTHETIC.pdf",
            mimeType: "application/pdf",
            buffer: bytes,
        });
        await expect(page.locator("#cad-reference-confirm")).toBeEnabled();
        await page.locator("#cad-reference-confirm").click();
        await expect(page.locator("#status")).toContainText("SYNTHETIC pdf");
        const pdfPixel = await page.evaluate(() => {
            const c = document.getElementById("canvas");
            return [
                ...c
                    .getContext("2d")
                    .getImageData(c.width / 2, c.height / 2, 1, 1).data,
            ];
        });
        assert.ok(pdfPixel[2] > 230 && pdfPixel[0] < 40 && pdfPixel[1] < 40);
        await page.locator("#print-markups").click();
        await expect(page.locator("#cp-preview")).toBeVisible();
        assert.equal(uploadCount, 2);
        await page.locator("#cp-close").click();
        let sheetImports = 0;
        await page.route(
            "https://synthetic.test/admin-api/cad/source-sheets/7",
            async (route) => {
                if (route.request().method() === "GET")
                    return route.fulfill({
                        json: {
                            title: "SYNTHETIC PDF STUDIO",
                            siteId: 1,
                            page: 1,
                            url: "/admin-api/cad/9/reference",
                        },
                    });
                const input = route.request().postDataJSON();
                assert.equal(input.reference.page, 1);
                assert.equal(input.reference.width, 400);
                sheetImports++;
                drawing = { ...drawing, title: input.title };
                return route.fulfill({ json: drawing });
            },
        );
        await page.goto("https://synthetic.test/cad?sheet_id=7");
        await expect(page.locator("#cad-reference-page")).toBeDisabled();
        await expect(page.locator("#cad-reference-confirm")).toBeEnabled();
        await page.locator("#cad-reference-confirm").click();
        await expect(page).toHaveURL("https://synthetic.test/cad?drawing_id=9");
        assert.equal(sheetImports, 1);
        await expect(page.locator("#status")).toContainText(
            "SYNTHETIC PDF STUDIO",
        );
        assert.deepEqual(errors, []);
        console.log(
            JSON.stringify({
                imageOriginal: true,
                pdfWorker: true,
                overlayReload: true,
                printPreview: true,
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
