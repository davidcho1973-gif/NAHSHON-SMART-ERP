import test from "node:test";
import assert from "node:assert/strict";
import "../../public/js/cad-markup.js";
const M = globalThis.CADMarkup;
const box = M.rect({ x: 0, y: 0 }, { x: 100, y: 60 });
test("area picking works inside concave boundaries and rejects crossed or zero area polygons", () => {
    assert.equal(M.validPolygon(box), true);
    assert.equal(
        M.validPolygon([
            { x: 0, y: 0 },
            { x: 10, y: 10 },
            { x: 0, y: 10 },
            { x: 10, y: 0 },
        ]),
        false,
    );
    assert.equal(
        M.validPolygon([
            { x: 0, y: 0 },
            { x: 1, y: 1 },
            { x: 2, y: 2 },
        ]),
        false,
    );
    const concave = [
        { x: 0, y: 0 },
        { x: 10, y: 0 },
        { x: 10, y: 5 },
        { x: 5, y: 5 },
        { x: 5, y: 10 },
        { x: 0, y: 10 },
    ];
    assert.equal(M.inside({ x: 2, y: 8 }, concave), true);
    assert.equal(M.inside({ x: 8, y: 8 }, concave), false);
    assert.equal(
        M.validPolygon(box.map((p) => ({ x: p.x + 1e8, y: p.y + 1e8 }))),
        true,
    );
});
test("revision clouds stay outward for either winding and bound tessellation at extreme zoom", () => {
    for (const points of [box, [...box].reverse()]) {
        const arcs = M.cloud(points, 5);
        assert.ok(arcs.length >= 12);
        assert.ok(arcs.every((a) => !M.inside(a.c, points)));
        assert.deepEqual(arcs.at(-1).b, arcs[0].a);
    }
    assert.ok(M.cloud(box, 1e-12).length <= 512);
});
test("copy and callout bounds preserve anchor and independently movable label", () => {
    const item = {
        kind: "callout",
        points: [
            { x: 0, y: 0 },
            { x: 20, y: 40 },
        ],
        size: 2,
        text: "배관 변경\n검토 요청",
    };
    const moved = M.translate(item, 10, -5);
    assert.deepEqual(item.points[0], { x: 0, y: 0 });
    assert.deepEqual(moved.points[1], { x: 30, y: 35 });
    const b = M.bounds(item);
    assert.ok(b.maxX > 20);
    assert.ok(M.hit(item, { x: 22, y: 38 }, 0.1));
    assert.ok(!M.hit(item, { x: 1000, y: 0 }, 0.1));
    assert.ok(
        M.hit(
            { kind: "pin", points: [{ x: 10, y: 10 }], size: 3 },
            { x: 12, y: 10 },
            0,
        ),
    );
});
