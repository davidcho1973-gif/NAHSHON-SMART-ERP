import test from "node:test";
import assert from "node:assert/strict";
import "../../public/js/cad-core.js";
import "../../public/js/cad-markup.js";
import "../../public/js/cad-markup-geometry.js";
import "../../public/js/cad-review.js";
const C = globalThis.CADCore,
    R = globalThis.CADReview;
const doc = (entities) => ({ entities, layers: { 0: { visible: true } } });
const line = (id, p, q, handle) => ({
    ...C.newEntity("LINE", { p, q }),
    id,
    raw: [[5, handle]],
});
test("detail projection rotates original and markup text with the drawing", () => {
    const angles = [];
    const ctx = new Proxy(
        {
            canvas: { width: 100, height: 100 },
            rotate: (a) => angles.push(a),
            measureText: (s) => ({ width: s.length * 6 }),
        },
        { get: (o, k) => o[k] ?? (() => {}) },
    );
    const screen = (p) => ({ x: p.y, y: p.x });
    C.renderEntities(
        ctx,
        { layers: { 0: { color: "#fff" } } },
        [C.newEntity("TEXT", { p: { x: 0, y: 0 }, height: 2, text: "A" })],
        screen,
        1,
    );
    globalThis.CADMarkup.draw(
        ctx,
        {
            kind: "text",
            points: [{ x: 0, y: 0 }],
            size: 2,
            text: "A",
            color: "#fff",
            width: 1,
            opacity: 1,
        },
        screen,
        1,
    );
    assert.equal(angles.length, 2);
    angles.forEach((a) => assert.ok(Math.abs(a - Math.PI / 2) < 1e-9));
});
test("block text changes are detected even with identical geometry and raster references are rejected", () => {
    const a = {
        ...line("a", { x: 0, y: 0 }, { x: 10, y: 0 }, "A"),
        type: "INSERT",
        displayGeometry: [
            { x: 0, y: 0 },
            { x: 10, y: 0 },
        ],
        renderParts: [{ type: "TEXT", text: "A01" }],
    };
    const b = structuredClone(a);
    b.renderParts[0].text = "B01";
    assert.equal(R.compare(doc([a]), doc([b]))[0].kind, "changed");
    assert.throws(() =>
        R.compare({ ...doc([a]), reference: { type: "pdf" } }, doc([b])),
    );
});
test("two matching anchors remove translation, rotation and scale before change detection", () => {
    const old = doc([line("old", { x: 0, y: 0 }, { x: 10, y: 0 }, "A")]),
        current = doc([line("new", { x: 100, y: 20 }, { x: 100, y: 40 }, "A")]);
    const transform = R.alignment(
        [
            { x: 0, y: 0 },
            { x: 10, y: 0 },
        ],
        [
            { x: 100, y: 20 },
            { x: 100, y: 40 },
        ],
    );
    assert.deepEqual(R.map({ x: 5, y: 0 }, transform), { x: 100, y: 30 });
    assert.deepEqual(R.compare(old, current, transform), []);
    assert.throws(() =>
        R.alignment(
            [
                { x: 0, y: 0 },
                { x: 0, y: 0 },
            ],
            [
                { x: 1, y: 0 },
                { x: 2, y: 0 },
            ],
        ),
    );
});
test("changed handles, regenerated handles, duplicate lines, additions and removals are distinct", () => {
    const a = line("a", { x: 0, y: 0 }, { x: 10, y: 0 }, "A"),
        b = line("b", { x: 0, y: 2 }, { x: 10, y: 2 }, "B");
    assert.deepEqual(
        R.compare(doc([a]), doc([{ ...a, id: "new", raw: [[5, "NEW"]] }])),
        [],
    );
    const result = R.compare(
        doc([a, b]),
        doc([
            { ...a, q: { x: 12, y: 0 } },
            line("c", { x: 0, y: 5 }, { x: 4, y: 5 }, "C"),
        ]),
    );
    assert.deepEqual(result.map((r) => r.kind).sort(), [
        "added",
        "changed",
        "removed",
    ]);
    assert.equal(
        R.compare(doc([a, a]), doc([a])).filter((r) => r.kind === "removed")
            .length,
        1,
    );
});
test("repeated symbols preserve individual locations, filter size, and ignore hidden layers", () => {
    const circle = (id, x, r = 2) =>
        C.newEntity("CIRCLE", { id, p: { x, y: 0 }, radius: r });
    const a = circle("a", 0),
        b = circle("b", 10),
        c = circle("c", 20, 3),
        d = { ...circle("d", 30), hidden: true };
    assert.deepEqual(
        R.candidates(doc([a, b, c, d]), a).map((r) => r.entityId),
        ["a", "b"],
    );
});
test("linked detail source is preserved by movement and similarity transforms", () => {
    const item = {
        id: "detail",
        kind: "detail",
        points: [
            { x: 20, y: 0 },
            { x: 40, y: 0 },
            { x: 40, y: 20 },
            { x: 20, y: 20 },
        ],
        detail: { minX: 0, minY: 0, maxX: 10, maxY: 10 },
        size: 1,
    };
    assert.equal(R.detailValid(item), true);
    const moved = globalThis.CADMarkupGeometry.transform([item], 35, 1.5)[0];
    assert.deepEqual(moved.detail, item.detail);
    assert.equal(R.detailValid(moved), true);
    moved.points[2].x += 3;
    assert.equal(R.detailValid(moved), false);
});
