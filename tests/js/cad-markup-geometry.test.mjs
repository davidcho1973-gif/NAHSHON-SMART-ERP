import test from "node:test";
import assert from "node:assert/strict";
import "../../public/vendor/polygon-clipping/polygon-clipping.js";
import "../../public/js/cad-markup.js";
import "../../public/js/cad-markup-geometry.js";
const M = globalThis.CADMarkup,
    G = globalThis.CADMarkupGeometry;
const box = (x, y, w, h) => M.rect({ x, y }, { x: x + w, y: y + h });
const area = (id, points) => ({
    id,
    kind: "area",
    points,
    color: "#123456",
    opacity: 0.5,
    width: 2,
    size: 2,
    pattern: "solid",
    text: "",
    locked: false,
});
test("holes touching another boundary are rejected before server save", () => {
    const a = area("a", box(0, 0, 10, 10));
    a.holes = [box(0, 2, 2, 2)];
    assert.equal(G.valid(a), false);
    a.holes = [box(2, 2, 2, 2), box(4, 2, 2, 2)];
    assert.equal(G.valid(a), false);
    a.holes = [box(2, 2, 2, 2), box(5, 2, 2, 2)];
    assert.equal(G.valid(a), true);
});
test("union, clipping a column hole, multipart subtraction and picking retain topology", () => {
    const outer = area("a", box(0, 0, 100, 100)),
        column = area("b", box(20, 20, 10, 10));
    const [cut] = G.combine([outer, column], "difference");
    assert.equal(G.area(cut), 9900);
    assert.equal(cut.holes.length, 1);
    assert.equal(G.valid(cut), true);
    assert.equal(M.hit(cut, { x: 25, y: 25 }, 0), false);
    assert.equal(M.hit(cut, { x: 15, y: 25 }, 0), true);
    const filled = G.combine([cut, column], "union");
    assert.equal(filled.length, 1);
    assert.equal(G.area(filled[0]), 10000);
    assert.equal(filled[0].holes.length, 0);
    const pieces = G.combine(
        [outer, area("c", box(45, -10, 10, 120))],
        "difference",
    );
    assert.equal(pieces.length, 2);
    assert.equal(
        pieces.reduce((n, m) => n + G.area(m), 0),
        9000,
    );
    assert.notEqual(pieces[0].id, pieces[1].id);
    assert.deepEqual(G.combine([column, outer], "difference"), []);
    assert.equal(outer.holes, undefined);
});
test("holes rotate, scale, and translate with their parent without mutating the source", () => {
    const item = { ...area("a", box(0, 0, 10, 10)), holes: [box(2, 2, 2, 2)] };
    const [rotated] = G.transform([item], 90, 2, { x: 0, y: 0 });
    assert.ok(Math.abs(G.area(rotated) - 384) < 1e-8);
    const moved = M.translate(rotated, 100, 200);
    assert.ok(Math.abs(moved.holes[0][0].x - 96) < 1e-8);
    assert.ok(Math.abs(moved.holes[0][0].y - 204) < 1e-8);
    assert.deepEqual(item.holes[0][0], { x: 2, y: 2 });
    assert.equal(G.valid({ ...item, holes: [box(9, 9, 2, 2)] }), false);
    assert.equal(
        G.valid({ ...item, holes: [box(2, 2, 2, 2), box(3, 3, 2, 2)] }),
        false,
    );
});
test("text wrapping and rotated label picking share the same geometry", () => {
    const item = {
        ...area("t", [{ x: 0, y: 0 }]),
        kind: "text",
        text: "LONG TEXT\n한글 표시",
        size: 2,
        textStyle: { boxWidth: 10, lineHeight: 2 },
    };
    const label = M.labelBox(item);
    assert.ok(label.lines.length > 2);
    assert.equal(label.width, 10);
    const [turned] = G.transform([item], 90, 1, { x: 0, y: 0 });
    assert.ok(M.hit(turned, { x: 1, y: 5 }, 0));
    assert.ok(!M.hit(turned, { x: -5, y: -5 }, 0));
    const b = M.bounds(turned);
    assert.ok(b.maxY >= 10);
    assert.ok(b.maxX > 10);
});
test("partial eraser preserves exact endpoints and every disconnected surviving segment", () => {
    const line = [
        { x: 0, y: 0 },
        { x: 100, y: 0 },
    ];
    assert.deepEqual(G.erase(line, [{ x: 50, y: 0 }], 10), [
        [
            { x: 0, y: 0 },
            { x: 40, y: 0 },
        ],
        [
            { x: 60, y: 0 },
            { x: 100, y: 0 },
        ],
    ]);
    const swept = G.erase(
        line,
        [
            { x: 50, y: -100 },
            { x: 50, y: 100 },
        ],
        5,
    );
    assert.equal(swept.length, 2);
    assert.deepEqual(swept[0][0], line[0]);
    assert.deepEqual(swept[1].at(-1), line[1]);
    // Computed intersections are floating point; use a sub-nanometre model tolerance.
    assert.ok(Math.abs(swept[0].at(-1).x - 45) < 1e-9);
    assert.ok(Math.abs(swept[1][0].x - 55) < 1e-9);
    assert.deepEqual(
        G.erase(
            line,
            [
                { x: 0, y: 0 },
                { x: 100, y: 0 },
            ],
            2,
        ),
        [],
    );
    assert.deepEqual(G.erase(line, [{ x: 50, y: 20 }], 5), [line]);
    const corner = [
        { x: 0, y: 0 },
        { x: 50, y: 0 },
        { x: 50, y: 50 },
    ];
    const result = G.erase(corner, [{ x: 50, y: 0 }], 10);
    assert.deepEqual(result, [
        [
            { x: 0, y: 0 },
            { x: 40, y: 0 },
        ],
        [
            { x: 50, y: 10 },
            { x: 50, y: 50 },
        ],
    ]);
});
test("angle-constrained routes and equal-gap distribution use model coordinates", () => {
    const p = G.snapAngle({ x: 0, y: 0 }, { x: 10, y: 3 }, 90);
    assert.equal(p.y, 0);
    assert.ok(Math.abs(p.x - Math.sqrt(109)) < 1e-8);
    assert.equal(
        G.length({
            points: [
                { x: 0, y: 0 },
                { x: 30, y: 0 },
                { x: 30, y: 40 },
            ],
        }),
        70,
    );
    const distributed = G.distribute(
        [
            area("a", box(0, 0, 10, 10)),
            area("b", box(25, 0, 20, 10)),
            area("c", box(90, 0, 10, 10)),
        ],
        "x",
    );
    assert.equal(M.bounds(distributed[1]).minX, 40);
    assert.equal(M.bounds(distributed[0]).minX, 0);
    assert.equal(M.bounds(distributed[2]).minX, 90);
});
test("window selection contains shapes while crossing selection includes edge crossings", () => {
    const item = area("a", box(0, 0, 100, 100)),
        region = box(40, -10, 20, 120);
    assert.equal(G.selectRegion([item], region, false).length, 0);
    assert.equal(G.selectRegion([item], region, true).length, 1);
    assert.equal(
        G.selectRegion([item], box(-10, -10, 120, 120), false).length,
        1,
    );
});

test("groups keep internal offsets when aligned and crossing does not select empty bounds", () => {
    const left = area("a", box(0, 0, 10, 10)),
        first = { ...area("b", box(40, 0, 10, 10)), group: "assembly" },
        second = { ...area("c", box(60, 0, 10, 10)), group: "assembly" };
    const aligned = G.align([left, first, second], "left");
    assert.equal(M.bounds(aligned[1]).minX, 0);
    assert.equal(M.bounds(aligned[2]).minX, 20);
    const diagonal = {
        ...area("line", [
            { x: 0, y: 0 },
            { x: 100, y: 100 },
        ]),
        kind: "pen",
    };
    assert.equal(
        G.selectRegion([diagonal], box(10, 70, 10, 10), true).length,
        0,
    );
    assert.equal(G.selectRegion([left], left.points, false).length, 1);
});
