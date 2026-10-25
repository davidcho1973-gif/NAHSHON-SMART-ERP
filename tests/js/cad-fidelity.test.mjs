import test from "node:test";
import assert from "node:assert/strict";
import "../../public/js/cad-core.js";
import "../../public/js/cad-fidelity.js";
const C = globalThis.CADCore,
    F = globalThis.CADFidelity;
test("hatch dash signs preserve adjacent ink segments and leading gaps", () => {
    assert.deepEqual(F.dashSpans([-2, 3, 1, -2], 0, 8), [
        [2, 5],
        [5, 6],
    ]);
    assert.deepEqual(F.dashSpans([2, -2], -3, 3), [
        [-3, -2],
        [0, 2],
    ]);
    assert.deepEqual(F.dashSpans([], 1, 8), [[1, 8]]);
});
const pairs = (p) => p.map(([c, v]) => `${c}\n${v}\n`).join("");
const drawing = (entities, blocks = []) =>
    pairs([
        [0, "SECTION"],
        [2, "HEADER"],
        [9, "$ACADVER"],
        [1, "AC1032"],
        [0, "ENDSEC"],
        ...(blocks.length
            ? [[0, "SECTION"], [2, "BLOCKS"], ...blocks, [0, "ENDSEC"]]
            : []),
        [0, "SECTION"],
        [2, "ENTITIES"],
        ...entities,
        [0, "ENDSEC"],
        [0, "EOF"],
    ]);
test("rational B-spline evaluates a circular arc rather than drawing control polygons", () => {
    const ps = F.spline(
        2,
        [0, 0, 0, 1, 1, 1],
        [
            { x: 1, y: 0 },
            { x: 1, y: 1 },
            { x: 0, y: 1 },
        ],
        [1, Math.SQRT1_2, 1],
    );
    assert.deepEqual(ps[0], { x: 1, y: 0 });
    assert.deepEqual(ps.at(-1), { x: 0, y: 1 });
    assert.ok(ps.length > 20);
    assert.ok(ps.every((p) => Math.abs(Math.hypot(p.x, p.y) - 1) < 1e-10));
    assert.ok(
        ps.some(
            (p) =>
                Math.abs(p.x - Math.SQRT1_2) < 1e-8 &&
                Math.abs(p.y - Math.SQRT1_2) < 1e-8,
        ),
    );
    assert.throws(() => F.spline(2, [0, 1, 0], [{ x: 0, y: 0 }], []));
});
test("polyline bulges retain the circular sweep and original DXF records", () => {
    const source = drawing([
            [0, "LWPOLYLINE"],
            [90, 2],
            [70, 0],
            [10, 0],
            [20, 0],
            [42, 1],
            [10, 10],
            [20, 0],
        ]),
        doc = C.parse(source),
        ps = C.geometry(doc.entities[0]);
    assert.equal(doc.entities[0].editable, true);
    assert.equal(C.allows(doc.entities[0],'resize'),false);
    assert.ok(ps.some((p) => p.y < -4.99));
    assert.ok(ps.every((p) => Math.abs(Math.hypot(p.x - 5, p.y) - 5) < 1e-8));
    assert.equal(C.exportDXF(doc).replace(/\r\n/g, "\n"), source);
});
test("native hatch rings retain their holes and support safe native editing", () => {
    const loop = (ps) => [
        [92, 2],
        [72, 0],
        [73, 1],
        [93, ps.length],
        ...ps.flatMap(([x, y]) => [
            [10, x],
            [20, y],
        ]),
        [97, 0],
    ];
    const source = drawing([
            [0, "HATCH"],
            [2, "SOLID"],
            [70, 1],
            [91, 2],
            ...loop([
                [0, 0],
                [100, 0],
                [100, 100],
                [0, 100],
            ]),
            ...loop([
                [20, 20],
                [30, 20],
                [30, 30],
                [20, 30],
            ]),
            [75, 0],
            [76, 1],
            [98, 0],
        ]),
        doc = C.parse(source),
        e = doc.entities[0];
    assert.equal(e.hatch.rings.length, 2);
    assert.equal(e.hatch.solid, true);
    assert.equal(C.allows(e,'style'), true);
    assert.equal(C.allows(e,'move'), true);
    assert.ok(C.geometry(e).length > 4);
    assert.equal(C.exportDXF(doc).replace(/\r\n/g, "\n"), source);
});
test("nested blocks transform once and native dimensions use their stored graphics block", () => {
    const block = (name, entities) => [
        [0, "BLOCK"],
        [2, name],
        [10, 0],
        [20, 0],
        ...entities,
        [0, "ENDBLK"],
    ];
    const blocks = [
        ...block("A", [
            [0, "LINE"],
            [10, 0],
            [20, 0],
            [11, 10],
            [21, 0],
        ]),
        ...block("B", [
            [0, "INSERT"],
            [2, "A"],
            [10, 10],
            [20, 0],
            [41, 2],
            [42, 2],
            [50, 90],
        ]),
    ];
    const source = drawing(
            [
                [0, "INSERT"],
                [2, "B"],
                [10, 100],
                [20, 0],
                [0, "DIMENSION"],
                [2, "A"],
                [10, 5000],
                [20, 5000],
                [70, 32],
            ],
            blocks,
        ),
        doc = C.parse(source),
        [insert, dimension] = doc.entities;
    const ps = C.geometry(insert);
    assert.ok(Math.abs(ps[0].x - 110) < 1e-8);
    assert.ok(Math.abs(ps[1].y - 20) < 1e-8);
    assert.deepEqual(C.geometry(dimension), [
        { x: 0, y: 0 },
        { x: 10, y: 0 },
    ]);
    assert.equal(dimension.editable, false);
    assert.equal(C.exportDXF(doc).replace(/\r\n/g, "\n"), source);
});
test("formatted text is readable while unsupported formatting and missing blocks stay explicit", () => {
    const source = drawing([
            [0, "MTEXT"],
            [10, 0],
            [20, 0],
            [40, 2],
            [71, 1],
            [1, "{\\fArial;검토\\P%%c100}"],
            [0, "INSERT"],
            [2, "MISSING"],
            [10, 0],
            [20, 0],
        ]),
        doc = C.parse(source);
    assert.equal(doc.entities[0].displayText, "검토\nØ100");
    assert.ok(
        doc.presentation.warnings.some(
            (w) => w.reason === "mtext_format_substitution",
        ),
    );
    assert.ok(
        doc.presentation.warnings.some(
            (w) => w.reason === "external_or_missing_block",
        ),
    );
    assert.equal(doc.presentation.unavailable.length, 1);
    assert.equal(C.exportDXF(doc).replace(/\r\n/g, "\n"), source);
});
