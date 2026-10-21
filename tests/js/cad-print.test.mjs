import test from "node:test";
import assert from "node:assert/strict";
import "../../public/js/cad-print.js";
const P = globalThis.CADPrint,
    mm = { metersPerUnit: 0.001, unit: "mm", source: "declared" };
test("fixed-scale paper geometry is independent of DPI and retains the requested physical scale", () => {
    const bounds = { minX: 0, minY: 0, maxX: 10000, maxY: 5000 };
    for (const dpi of [150, 200, 300]) {
        const plan = P.layout(
            bounds,
            {
                paper: "A3",
                landscape: true,
                dpi,
                fixed: true,
                denominator: 100,
            },
            mm,
        );
        assert.equal(plan.widthMm, 420);
        assert.equal(plan.heightMm, 297);
        const a = plan.screen({ x: 0, y: 0 }),
            b = plan.screen({ x: 1000, y: 0 });
        assert.ok(Math.abs((b.x - a.x) / plan.px - 10) < 1e-9);
        assert.equal(plan.denominator, 100);
        assert.ok(Math.abs(plan.crop.width / plan.px - 100) < 1e-9);
    }
});
test("unknown units, overflowing paper and oversized raster allocations never silently change scale", () => {
    const bounds = { minX: 0, minY: 0, maxX: 10000, maxY: 5000 },
        config = {
            paper: "A4",
            landscape: true,
            dpi: 150,
            fixed: true,
            denominator: 1,
        };
    assert.throws(() => P.layout(bounds, config, null), /단위/);
    assert.throws(() => P.layout(bounds, config, mm), /용지를 넘습니다/);
    assert.throws(
        () =>
            P.layout(
                bounds,
                { ...config, paper: "A1", dpi: 300, fixed: false },
                mm,
            ),
        /해상도/,
    );
    assert.equal(
        P.layout(bounds, { ...config, fixed: false }, null).denominator,
        null,
    );
});
test("PDF MediaBox uses millimetres and cross-reference offsets point to every object", async () => {
    const canvas = {
        width: 2976,
        height: 2105,
        toDataURL: () => "data:image/jpeg;base64,/9j/2Q==",
    };
    const bytes = new Uint8Array(
            await P.pdfBlob(canvas, 420, 297).arrayBuffer(),
        ),
        text = new TextDecoder("latin1").decode(bytes);
    assert.match(text, /MediaBox \[0 0 1190\.55118 841\.88976\]/);
    const xref = Number(text.match(/startxref\n(\d+)/)[1]);
    assert.equal(new TextDecoder().decode(bytes.slice(xref, xref + 4)), "xref");
    const entries = text.slice(xref).split("\n").slice(3, 8);
    entries.forEach((entry, i) => {
        const offset = Number(entry.slice(0, 10));
        assert.equal(
            new TextDecoder().decode(bytes.slice(offset, offset + 7)),
            `${i + 1} 0 obj`,
        );
    });
});
