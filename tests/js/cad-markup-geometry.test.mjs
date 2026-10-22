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

const wall = (x1, y1, x2, y2, extra = {}) => ({
    type: "LINE", p: {x: x1, y: y1}, q: {x: x2, y: y2}, ...extra,
});
const wallBox = (x, y, w, h) => [
    wall(x, y, x+w, y), wall(x+w, y, x+w, y+h),
    wall(x+w, y+h, x, y+h), wall(x, y+h, x, y),
];
const boundaryArea = boundary => {
    assert.ok(boundary, "Expected a closed boundary");
    assert.ok(G.valid({...area("boundary", boundary.points), holes: boundary.holes}));
    return G.area(boundary);
};

test("boundary fill recognizes separate wall lines without changing native geometry", () => {
    const walls = wallBox(0, 0, 10, 8), before = structuredClone(walls);
    assert.equal(boundaryArea(G.findDrawingBoundary(walls, {x: 3, y: 4})), 80);
    assert.deepEqual(walls, before);
    assert.equal(G.findDrawingBoundary(walls, {x: 12, y: 4}), null);
});

test("boundary fill splits crossings and T joints and ignores dangling branches", () => {
    const walls = [...wallBox(0, 0, 10, 10), wall(5, -2, 5, 12), wall(-2, 5, 12, 5), wall(0, 2, 2, 2)];
    for (const x of [2, 8]) for (const y of [3, 8]) {
        assert.equal(boundaryArea(G.findDrawingBoundary(walls, {x, y})), 25);
    }
});

test("boundary fill deduplicates reversed and partially overlapping walls", () => {
    const walls = [...wallBox(0, 0, 10, 10), wall(10, 0, 0, 0), wall(4, 0, 12, 0), wall(0, 3, 0, 7)];
    assert.equal(boundaryArea(G.findDrawingBoundary(walls, {x: 5, y: 5})), 100);
});

test("boundary fill accepts closed and connected open polylines without edit permission", () => {
    const ring = box(0, 0, 10, 10);
    assert.equal(boundaryArea(G.findDrawingBoundary([{type: "LWPOLYLINE", points: ring, closed: true, editable: false}], {x: 5, y: 5})), 100);
    const walls = [{type: "POLYLINE", points: ring, closed: false}, wall(0, 10, 0, 0)];
    assert.equal(boundaryArea(G.findDrawingBoundary(walls, {x: 5, y: 5})), 100);
});

test("boundary fill preserves nested columns as holes and can fill a column itself", () => {
    const walls = [...wallBox(0, 0, 20, 20), ...wallBox(5, 5, 8, 8), ...wallBox(7, 7, 2, 2)];
    const room = G.findDrawingBoundary(walls, {x: 2, y: 2});
    assert.equal(boundaryArea(room), 336);
    assert.equal(room.holes.length, 1);
    assert.equal(boundaryArea(G.findDrawingBoundary(walls, {x: 6, y: 6})), 60);
    assert.equal(boundaryArea(G.findDrawingBoundary(walls, {x: 8, y: 8})), 4);
});

test("boundary fill does not close real door gaps or bridge disconnected block paths", () => {
    const open = wallBox(0, 0, 10, 10);
    open[3].q.y = 0.001;
    assert.equal(G.findDrawingBoundary(open, {x: 5, y: 5}), null);
    assert.equal(G.findDrawingBoundary([{type: "INSERT", preview: [[{x:0,y:0},{x:10,y:0}], [{x:10,y:10},{x:0,y:10}]]}], {x: 5, y: 5}), null);
});

test("boundary fill reads transformed block parts but ignores text boxes and hidden linework", () => {
    const block = {type: "INSERT", renderParts: wallBox(100, 200, 12, 10)};
    assert.equal(boundaryArea(G.findDrawingBoundary([block], {x: 105, y: 205})), 120);
    assert.equal(G.findDrawingBoundary([{type: "TEXT", displayGeometry: [...box(0, 0, 10, 10), {x:0,y:0}]}], {x: 5, y: 5}), null);
    for (const excluded of [{hidden:true}, {paper:true}, {layer:"hidden"}]) {
        const walls = wallBox(0, 0, 10, 10);
        Object.assign(walls[0], excluded);
        assert.equal(G.findDrawingBoundary(walls, {x:5,y:5}, {layers:{hidden:{visible:false}}}), null);
    }
});

test("boundary fill keeps precision at site coordinates and handles a concave room", () => {
    const walls = wallBox(1e8, -1e8, 10, 10);
    assert.equal(boundaryArea(G.findDrawingBoundary(walls, {x:1e8+5,y:-1e8+5})), 100);
    const points = [{x:0,y:0},{x:10,y:0},{x:10,y:4},{x:4,y:4},{x:4,y:10},{x:0,y:10}];
    const lines = points.map((p,i) => wall(p.x,p.y,points[(i+1)%points.length].x,points[(i+1)%points.length].y));
    assert.equal(boundaryArea(G.findDrawingBoundary(lines, {x:2,y:8})), 64);
    assert.equal(G.findDrawingBoundary(lines, {x:8,y:8}), null);
});

test("boundary analysis stops at explicit complexity limits instead of partial fills", () => {
    assert.throws(() => G.findDrawingBoundary(wallBox(0,0,10,10), {x:5,y:5}, {limits:{segments:3}}), /한도/);
    assert.throws(() => G.findDrawingBoundary(wallBox(0,0,10,10), {x:5,y:5}, {limits:{comparisons:1}}), /한도/);
    assert.throws(() => G.findDrawingBoundary(wallBox(0,0,10,10), {x:5,y:5}, {limits:{points:2}, bounds:{minX:100,minY:100,maxX:110,maxY:110}}), /한도/);
    assert.throws(() => G.findDrawingBoundary([], {x:NaN,y:1}), /좌표/);
});

test("local boundary search ignores distant density and never closes the search box", () => {
    const bounds = {minX:-2,minY:-2,maxX:12,maxY:12};
    const distant = Array.from({length:6000}, (_,i) => wall(100+i,100,101+i,101));
    const room = G.findDrawingBoundary([...wallBox(0,0,10,10), ...distant], {x:5,y:5}, {bounds});
    assert.equal(boundaryArea(room), 100);
    assert.equal(G.findDrawingBoundary(wallBox(-20,-20,40,40), {x:0,y:0}, {bounds}), null);
    const open = [wall(-20,0,20,0),wall(0,-20,0,20)];
    assert.equal(G.findDrawingBoundary(open, {x:5,y:5}, {bounds}), null);
    // A genuine closed region is still rejected if its boundary was cropped.
    assert.equal(G.findDrawingBoundary(wallBox(0,0,20,10), {x:5,y:5}, {bounds}), null);
});

test("an unrelated long outer face does not prevent a small closed fill", () => {
    const ring = [];
    for (let i=0;i<150;i++) ring.push({x:i,y:0});
    for (let i=0;i<150;i++) ring.push({x:150,y:i});
    for (let i=0;i<150;i++) ring.push({x:150-i,y:150});
    for (let i=0;i<150;i++) ring.push({x:0,y:150-i});
    const walls = [{type:"LWPOLYLINE",points:ring,closed:true}, ...wallBox(20,20,10,10)];
    assert.equal(boundaryArea(G.findDrawingBoundary(walls, {x:25,y:25})), 100);
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
