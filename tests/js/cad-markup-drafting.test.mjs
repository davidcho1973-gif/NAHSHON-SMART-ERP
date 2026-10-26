import test from "node:test";
import assert from "node:assert/strict";
import "../../public/vendor/polygon-clipping/polygon-clipping.js";
import "../../public/js/cad-markup.js";
import "../../public/js/cad-markup-geometry.js";
const M = globalThis.CADMarkup,
    G = globalThis.CADMarkupGeometry;
const point = (x, y) => ({ x, y });
const mark = (shape, id = "test") => ({
    id,
    color: "#123456",
    opacity: 0.7,
    width: 2,
    size: 2,
    pattern: "solid",
    text: "",
    locked: false,
    ...shape,
});
const area = (x = 0, y = 0, w = 100, h = 80) =>
    mark({ kind: "area", points: M.rect(point(x, y), point(x + w, y + h)) });
const near = (a, b, eps = 1e-7) =>
    assert.ok(Math.abs(a - b) < eps, `${a} ≠ ${b}`);
test("three-point arcs pass their anchors and retain exact geometry under affine edits", () => {
    const arc = mark(G.arcThrough(point(0, 0), point(10, 10), point(20, 0)));
    assert.ok(G.valid(arc));
    near(M.curvePoint(arc, 0).x, 0);
    near(M.curvePoint(arc, 0.5).y, 10);
    near(M.curvePoint(arc, 1).x, 20);
    const b = M.bounds(arc);
    near(b.minX, 0);
    near(b.maxX, 20);
    near(b.minY, 0);
    near(b.maxY, 10);
    assert.ok(M.hit(arc, point(10, 10), 0.01));
    assert.ok(!M.hit(arc, point(10, 0), 0.1));
    const moved = G.map(arc, (p) => point(p.x * 2 + 1, p.y * 3 - 4));
    near(M.curvePoint(moved, 0.5).y, 26);
    assert.ok(G.valid(moved));
    assert.throws(
        () => G.arcThrough(point(0, 0), point(1, 1), point(2, 2)),
        /일직선/,
    );
    const site = mark(
        G.arcThrough(
            point(1e8, 1e8),
            point(1e8 + 10, 1e8 + 10),
            point(1e8 + 20, 1e8),
        ),
    );
    near(M.curvePoint(site, 0.5).y, 1e8 + 10);
    assert.ok(!G.valid({ ...arc, arc: { ...arc.arc, sweep: 0 } }));
    assert.ok(!G.valid({ ...arc, arc: { ...arc.arc, sweep: 7 } }));
    assert.ok(!G.valid({ ...arc, points: arc.points.slice(1) }));
});
test("clockwise/reflex pie bounds, area, holes prohibition and transforms agree", () => {
    const pie = mark(
        G.arcFromCenter(point(0, 0), point(10, 0), point(0, 10), true, true),
    );
    near(G.area(pie), 75 * Math.PI);
    assert.ok(M.hit(pie, point(-2, -2), 0));
    assert.ok(!M.hit(pie, point(2, 2), 0));
    const b = M.bounds(pie);
    for (const key of ["minX", "minY"]) near(b[key], -10);
    for (const key of ["maxX", "maxY"]) near(b[key], 10);
    const changed = G.transform([pie], 33, 2)[0];
    near(G.area(changed), 4 * G.area(pie));
    assert.ok(G.valid(changed));
    assert.ok(!G.valid({ ...pie, holes: [] }));
});
test("curve picking stays accurate at survey coordinates and large zoom without chord approximations", () => {
    const arc = mark(
        G.arcFromCenter(
            point(1e8, 1e8),
            point(1e8 + 1e6, 1e8),
            point(1e8, 1e8 + 1e6),
        ),
    );
    const bezier = mark({
        kind: "curve",
        points: [point(0, 0), point(0, 1e6), point(1e6, -1e6), point(1e6, 0)],
    });
    for (const item of [
        arc,
        G.map(arc, (p) => point(p.x * 0.7 + p.y * 0.2, p.y * 1.3)),
        bezier,
    ])
        for (const t of [0.00123, 0.123456, 0.45555, 0.982345]) {
            const p = M.curvePoint(item, t),
                v = M.curveTangent(item, t),
                length = Math.hypot(v.x, v.y);
            assert.ok(M.hit(item, p, 0.0001));
            assert.ok(
                !M.hit(
                    item,
                    point(p.x - v.y / length, p.y + v.x / length),
                    0.001,
                ),
            );
        }
    near(G.length(arc), Math.PI * 5e5, 1e-5);
    near(
        G.length(
            mark({
                kind: "curve",
                points: [
                    point(0, 0),
                    point(0, 100),
                    point(100, 100),
                    point(100, 0),
                ],
            }),
        ),
        200,
        1e-6,
    );
});
test("Bezier extrema, tangent arrows and bounds use curve geometry instead of control polygon", () => {
    const curve = mark({
        kind: "curve",
        points: [point(0, 0), point(0, 100), point(100, 100), point(100, 0)],
        arrows: "both",
    });
    const b = M.bounds(curve);
    near(b.maxY, 75);
    near(b.minY, 0);
    near(M.curvePoint(curve, 0.5).x, 50);
    near(M.curveTangent(curve, 0).x, 0);
    assert.ok(M.hit(curve, point(50, 75), 0.01));
    assert.ok(!M.hit(curve, point(0, 50), 0.01));
    assert.ok(G.valid(curve));
    assert.ok(!G.valid({ ...curve, fillColor: "#ffffff" }));
    assert.ok(
        !G.valid({
            ...curve,
            points: [point(1, 1), point(1, 1), point(1, 1), point(1, 1)],
        }),
    );
    near(
        G.length(
            mark({
                kind: "route",
                points: [point(0, 0), point(3, 4), point(6, 8)],
            }),
        ),
        10,
    );
    assert.equal(
        G.selectRegion([curve], M.rect(point(49, 74), point(51, 76)), true)
            .length,
        1,
    );
});
test("split preserves concavity, holes, styles, area and source across directions/site coordinates", () => {
    for (const origin of [0, 1e7])
        for (const angle of [0, 0.1, 0.7, 1.2, Math.PI / 2]) {
            const source = area(origin, origin),
                o = point(origin + 50, origin + 40);
            source.holes = [
                M.rect(
                    point(origin + 20, origin + 20),
                    point(origin + 30, origin + 30),
                ),
            ];
            source.fillColor = "#abcdef";
            source.layer = "trade";
            const before = structuredClone(source);
            const result = G.splitArea(
                source,
                o,
                point(o.x + Math.cos(angle) * 100, o.y + Math.sin(angle) * 100),
            );
            near(
                result.reduce((s, m) => s + G.area(m), 0),
                G.area(source),
                1e-5,
            );
            assert.ok(G.validSet(result));
            assert.ok(
                result.every(
                    (m) => m.layer === "trade" && m.fillColor === "#abcdef",
                ),
            );
            assert.equal(new Set(result.map((m) => m.id)).size, result.length);
            assert.deepEqual(source, before);
        }
    const concave = mark({
        kind: "area",
        points: [
            point(0, 0),
            point(60, 0),
            point(60, 60),
            point(40, 60),
            point(40, 20),
            point(20, 20),
            point(20, 60),
            point(0, 60),
        ],
    });
    const parts = G.splitArea(concave, point(-10, 30), point(80, 30));
    assert.equal(parts.length, 3);
    near(
        parts.reduce((s, m) => s + G.area(m), 0),
        G.area(concave),
    );
    assert.throws(
        () => G.splitArea(area(), point(0, 100), point(100, 100)),
        /가로지르는/,
    );
    assert.throws(
        () =>
            G.splitArea({ ...area(), locked: true }, point(0, 1), point(1, 1)),
        /잠기지/,
    );
});
test("holes reject touching boundaries and keep remaining filled topology", () => {
    const source = area(),
        ring = M.rect(point(10, 10), point(20, 20)),
        next = G.hole(source, ring);
    near(G.area(next), 7900);
    assert.ok(!M.hit(next, point(15, 15), 0));
    assert.equal(source.holes, undefined);
    assert.throws(
        () => G.hole(source, M.rect(point(0, 0), point(10, 10))),
        /닿거나/,
    );
    assert.throws(() => G.hole(next, ring), /닿거나/);
});
test("offsets preserve holes and reject collapse while rounded shapes remain editable", () => {
    const source = area();
    source.holes = [M.rect(point(20, 20), point(60, 60))];
    const next = G.offsetPath(source, 5);
    near(G.area(next), 110 * 90 - 30 * 30);
    assert.ok(G.valid(next));
    assert.throws(() => G.offsetPath(area(0, 0, 10, 10), -6), /뒤집/);
    assert.throws(() => G.offsetPath(source, 21), /뒤집|境|겹치|사라/);
    const arc = mark(G.arcFromCenter(point(0, 0), point(10, 0), point(0, 10)));
    near(M.ellipseFrame(G.offsetPath(arc, 3).points).u.x, 13);
    for (const [w, h] of [
        [40, 10],
        [10, 40],
        [10, 10],
    ])
        for (const radius of [2, Infinity])
            assert.ok(
                G.valid(
                    mark({
                        kind: "area",
                        points: G.roundedBox(point(0, 0), point(w, h), radius),
                    }),
                ),
            );
});
test("fillet is tangent and chamfer trims both lines without moving far endpoints", () => {
    const lines = [
        mark(
            {
                kind: "route",
                points: [point(0, 30), point(0, 0)],
                arrows: "none",
            },
            "a",
        ),
        mark(
            {
                kind: "route",
                points: [point(0, 0), point(30, 0)],
                arrows: "none",
            },
            "b",
        ),
    ];
    const result = G.cornerLines(lines, 5);
    assert.ok(G.validSet(result));
    near(result[0].points[1].y, 5);
    near(result[1].points[0].x, 5);
    const arc = result[2];
    near(M.curvePoint(arc, 0).x, 0);
    near(M.curvePoint(arc, 1).y, 0);
    near(M.curveTangent(arc, 0).x, 0);
    assert.equal(G.cornerLines(lines, 5, false)[2].kind, "route");
    assert.throws(() => G.cornerLines(lines, 50), /크기가/);
});
test("trim removes only clicked interval and extend finds nearest positive ray intersection", () => {
    const line = mark({
            kind: "route",
            points: [point(0, 0), point(100, 0)],
            arrows: "none",
        }),
        cutters = [
            [point(20, -10), point(20, 10)],
            [point(70, -10), point(70, 10)],
        ];
    const result = G.trimRoute(line, cutters, point(50, 0));
    assert.equal(result.length, 2);
    near(result[0].points[1].x, 20);
    near(result[1].points[0].x, 70);
    const dense = Array.from({ length: 5000 }, (_, i) => [
        point(i, 100),
        point(i, 120),
    ]);
    assert.deepEqual(
        G.trimRoute(line, [...dense, ...cutters], point(10, 0))[0].points,
        [point(20, 0), point(100, 0)],
    );
    near(
        G.trimRoute(
            line,
            [
                [point(120, -10), point(120, 10)],
                [point(140, -10), point(140, 10)],
            ],
            point(99, 0),
            true,
        )[0].points[1].x,
        120,
    );
    assert.throws(() => G.trimRoute(line, [], point(1, 0)), /교차/);
});
test("indexed snap finds late points and refuses over-budget sources explicitly", () => {
    const points = Array.from({ length: 100000 }, (_, i) =>
        point(i * 10, i % 20),
    );
    points.push({ ...point(333.5, 777), kind: "중심" });
    const index = G.snapIndex(points);
    assert.equal(index.query(point(333.6, 777), 1).kind, "중심");
    assert.equal(index.query(point(-100, -100), 1), null);
    assert.throws(() => G.snapIndex(Array(200001).fill(point(0, 0))), /한도/);
});
