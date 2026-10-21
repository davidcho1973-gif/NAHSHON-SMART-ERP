const { chromium, expect } = require("@playwright/test");
const fs = require("node:fs"),
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
            errors = [],
            writes = [];
        page.on("pageerror", (e) => errors.push(e.message));
        const mark = {
            id: "zone-A",
            kind: "area",
            points: [
                { x: 0, y: 0 },
                { x: 10, y: 0 },
                { x: 10, y: 10 },
                { x: 0, y: 10 },
            ],
            color: "#38bdf8",
            opacity: 0.4,
            width: 2,
            size: 1,
            pattern: "solid",
            text: "구역 A",
            locked: false,
        };
        const drawing = {
            id: 9,
            title: "SYNTHETIC WORKFLOW",
            site_id: 1,
            revision: 2,
            canManage: true,
            canExport: true,
            snapshotToken: "a".repeat(64),
            dxf: fs.readFileSync("tests/Fixtures/cad-line.dxf", "utf8"),
            markups: [mark],
            workspace: {
                measurement: {
                    unit: "m",
                    metersPerUnit: 1,
                    source: "declared",
                },
            },
        };
        const ref = {
                type: "wbs",
                id: 3,
                title: "SYNTHETIC DUCT",
                status: "in_progress",
                progress: 40,
                date: "2026-10-25",
                url: "/?view=wbs&site=1",
            },
            locations = [],
            drafts = [],
            packets = [];
        const context = () => ({
            locations,
            assignees: [{ id: 1, name: "SYNTHETIC REVIEWER" }],
            events: [],
            purchaseDrafts: drafts,
            canManage: true,
            canClose: true,
            canRequestPurchase: true,
        });
        const basis = {
            drawingId: 9,
            revision: 2,
            markupId: "zone-A",
            quantity: 100,
            unit: "M2",
            description: "구역 A",
        };
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
            "cad-workflow",
            "cad-packets",
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
            .replaceAll("{{ auth()->id() }}", "synthetic-workflow")
            .replace(
                "</body>",
                assets.map((n) => `<script src="/${n}.js"></script>`).join("") +
                    "</body>",
            );
        await page.route("https://synthetic.test/**", async (route) => {
            const req = route.request(),
                u = new URL(req.url()),
                p = u.pathname,
                method = req.method();
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
            if (method !== "GET") writes.push(p);
            const wf = "/admin-api/cad/9/workflow";
            if (p === "/admin-api/cad/capabilities")
                data = { dwgRead: false, dwgWrite: false };
            else if (p === "/admin-api/cad")
                data = {
                    sites: [{ id: 1, name: "SYNTHETIC" }],
                    drawings: [drawing],
                    canManage: true,
                };
            else if (p === "/admin-api/cad/9") data = drawing;
            else if (p === wf) data = context();
            else if (p === wf + "/targets") data = { items: [ref], next: null };
            else if (p === wf + "/links") {
                const b = req.postDataJSON();
                assert.equal(b.markupId, "zone-A");
                locations.push({
                    id: "location-A",
                    markupId: "zone-A",
                    present: true,
                    sourceRevision: 2,
                    url: "/cad?drawing_id=9&revision=2&markup_id=zone-A",
                    links: [{ ...ref, linkId: "link-A" }],
                    tasks: [],
                });
                data = {};
            } else if (p === wf + "/backlinks")
                data = {
                    items: [
                        {
                            title: drawing.title,
                            revision: 2,
                            markupId: "zone-A",
                            url: locations[0].url,
                        },
                    ],
                };
            else if (p === wf + "/tasks") {
                const b = req.postDataJSON();
                data = {
                    ...b,
                    status: "open",
                    version: 1,
                    assignee: "SYNTHETIC REVIEWER",
                    evidence: [],
                    resolution: "",
                };
                locations[0].tasks.push(data);
            } else if (p.startsWith(wf + "/tasks/")) {
                const b = req.postDataJSON();
                data = Object.assign(locations[0].tasks[0], b, {
                    version: b.version + 1,
                });
            } else if (p === wf + "/purchase-basis") data = basis;
            else if (p === wf + "/purchase-drafts") {
                const b = req.postDataJSON();
                data = {
                    ...b,
                    locationId: "location-A",
                    content: { ...b.content, basis },
                    version: b.version + 1,
                };
                drafts.splice(0, drafts.length, data);
            } else if (p.endsWith("/submit")) {
                assert.equal(req.postDataJSON().reviewed, true);
                drafts[0].requestId = 17;
                data = { request: { id: 17 } };
            } else if (p === wf + "/packets" && method === "GET")
                data = { items: packets };
            else if (p === wf + "/packets") {
                const raw = req.postDataBuffer().toString("latin1");
                assert.ok(raw.includes("%PDF-1.4"));
                assert.ok(raw.includes("snapshotToken"));
                assert.ok(raw.includes("a".repeat(64)));
                data = {
                    id: "packet-A",
                    title: "SYNTHETIC ARCHIVE",
                    revision: 2,
                    createdAt: "2026-10-21",
                    sha256: "b".repeat(64),
                    url: wf + "/packets/packet-A/download?format=zip",
                    pdfUrl: wf + "/packets/packet-A/download?format=pdf",
                    files: [{ name: "marked.pdf", bytes: 1000 }],
                };
                packets.push(data);
            } else if (p === wf + "/share-options")
                data = {
                    rooms: [{ id: 7, name: "SYNTHETIC ROOM" }],
                    mailReady: true,
                    mailFrom: "test@example.org",
                    mailBudget: 18 * 1024 * 1024,
                };
            else if (p.endsWith("/share")) {
                const b = req.postDataJSON();
                assert.equal(b.room, 7);
                assert.equal(b.reviewed, true);
                data = { status: "sent", duplicate: false };
            } else if (p.includes("/ai/"))
                data = {
                    connections: [],
                    canManage: false,
                    message: "SYNTHETIC",
                };
            else
                return route.fulfill({
                    status: 404,
                    json: { message: "Unexpected " + p },
                });
            return route.fulfill({ json: data });
        });
        await page.goto(
            "https://synthetic.test/cad?drawing_id=9&markup_id=zone-A",
        );
        await expect(page.locator("#dirty")).toHaveText("개정 2");
        await page.locator("#cad-workflow-open").click();
        await page.locator("#cw-type").selectOption("wbs");
        await page
            .getByRole("button", { name: "기록 찾기", exact: true })
            .click();
        await page
            .getByRole("button", {
                name: "선택한 도면 표시에 연결",
                exact: true,
            })
            .click();
        await expect(page.locator("#cw-body")).toContainText("40%");
        await page
            .getByRole("button", { name: "이 기록에 연결된 도면", exact: true })
            .click();
        await expect(page.locator("#cw-body")).toContainText(
            "SYNTHETIC WORKFLOW · 개정 2",
        );
        await page
            .getByRole("button", { name: "질의·검측·시정", exact: true })
            .click();
        await page.locator("#cw-task-title").fill("DUCT 간섭 확인");
        await page.locator("#cw-task-details").fill("천장 간격 확인");
        await page.locator("#cw-task-due").fill("2026-10-25");
        await page
            .getByRole("button", { name: "이 위치에 업무 등록", exact: true })
            .click();
        await page
            .getByRole("button", { name: "처리 / 검토", exact: true })
            .click();
        await page
            .locator("[id^=cw-resolution-]")
            .fill("설계 담당자 확인 완료");
        await page
            .getByRole("button", { name: "검토 요청", exact: true })
            .click();
        assert.equal(locations[0].tasks[0].status, "submitted");
        await page
            .getByRole("button", { name: "구매 요청 초안", exact: true })
            .click();
        await page
            .getByRole("button", {
                name: "선택 표시에서 구매 초안 작성",
                exact: true,
            })
            .click();
        await page.locator("#cw-purchase-name-0").fill("Duct insulation");
        await page.locator("#cw-purchase-qty-0").fill("110");
        await page.locator("#cw-purchase-save").click();
        await expect(page.locator("#cw-purchase-submit")).toBeEnabled();
        await page
            .getByRole("button", { name: "품목 추가", exact: true })
            .click();
        await expect(page.locator("#cw-purchase-submit")).toBeDisabled();
        await page
            .getByRole("button", { name: "품목 제외", exact: true })
            .last()
            .click();
        await page.locator("#cw-purchase-save").click();
        await page.locator("#cw-purchase-reviewed").check();
        await page.locator("#cw-purchase-submit").click();
        await expect(page.locator("#cw-message")).toContainText("#17");
        assert.equal(drafts[0].content.lines[0].quantity, 110);
        assert.equal(drafts[0].content.basis.quantity, 100);
        await page.locator("#cw-close").click();
        await page.locator("#print-markups").click();
        await expect(page.locator("#cp-preview")).toBeVisible();
        await page.locator("#cp-archive").click();
        await expect(page.locator("#pk-create")).toBeVisible();
        await page.locator("#pk-reviewed").check();
        await page.locator("#pk-create").click();
        await expect(page.locator("#pk-body")).toContainText(
            "SYNTHETIC ARCHIVE",
        );
        await page
            .getByRole("button", {
                name: "이 PDF를 메신저 / 메일로 전달",
                exact: true,
            })
            .click();
        await page.locator("#pk-send-reviewed").check();
        await page.locator("#pk-send").click();
        await expect(page.locator("#pk-status")).toContainText("전송했습니다");
        await expect(page.locator("#pk-send")).toBeDisabled();
        assert.equal(writes.filter((p) => p.endsWith("/share")).length, 1);
        await page.locator("#pk-close").click();
        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator("#cad-workflow-open").click();
        await expect(page.locator("#cw-close")).toBeVisible();
        assert.ok(
            await page
                .locator("#cad-workflow-dialog")
                .evaluate(
                    (e) => e.getBoundingClientRect().right <= innerWidth + 1,
                ),
        );
        fs.mkdirSync("test-results/assistant-browser", { recursive: true });
        await page.screenshot({
            path: "test-results/assistant-browser/SYNTHETIC-cad-workflow-mobile.png",
        });
        assert.deepEqual(errors, []);
        console.log(
            JSON.stringify({
                workflowWrites: writes.length,
                packet: packets.length,
                purchase: drafts[0].requestId,
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
