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

test('ellipses retain exact affine bounds, area and picking through rotation and corner resizing', () => {
    const ellipse = { ...area('oval', box(100, 200, 40, 20)), kind: 'ellipse' };
    assert.equal(G.valid(ellipse), true);
    assert.ok(Math.abs(G.area(ellipse) - 200 * Math.PI) < 1e-8);
    assert.deepEqual(M.bounds(ellipse), { minX: 100, minY: 200, maxX: 140, maxY: 220 });
    assert.ok(M.hit(ellipse, { x: 120, y: 210 }, 0));
    assert.ok(!M.hit(ellipse, { x: 100, y: 200 }, 0));
    const rotated = G.transform([ellipse], 45, 1)[0], radius = Math.sqrt(250), bounds = M.bounds(rotated);
    assert.ok(Math.abs(bounds.maxX - (120 + radius)) < 1e-8);
    assert.ok(Math.abs(bounds.maxY - (210 + radius)) < 1e-8);
    assert.ok(Math.abs(G.area(rotated) - G.area(ellipse)) < 1e-8);
    for (let i = 0; i < 4; i++) {
        const old = structuredClone(rotated), corner = rotated.points[i], resized = G.resizeEllipse(rotated, i, { x: corner.x + 3, y: corner.y - 2 });
        assert.ok(G.valid(resized));
        assert.deepEqual(resized.points[(i + 2) % 4], rotated.points[(i + 2) % 4]);
        assert.deepEqual(rotated, old);
    }
    assert.equal(G.valid({ ...ellipse, points: box(1, 1, 0, 10) }), false);
    assert.equal(G.valid({ ...ellipse, points: [...ellipse.points.slice(0, 3), { x: 130, y: 210 }] }), false);
    assert.equal(G.valid({ ...ellipse, holes: [] }), false);
    assert.equal(G.selectRegion([ellipse], box(99, 199, 3, 3), true).length, 0, 'empty frame corner is not the ellipse');
    assert.equal(G.selectRegion([ellipse], box(119, 209, 2, 2), true).length, 1);
    assert.equal(G.selectRegion([ellipse], box(99, 199, 42, 22), false).length, 1);
});

test('shape creation and exact resizing preserve valid geometry and source data', () => {
    for (const tool of ['triangle', 'diamond', 'polygon', 'star']) {
        const points = G.shape(tool, { x: 20, y: 40 }, { x: -10, y: 0 }, 7);
        assert.ok(M.validPolygon(points), tool);
        assert.equal(points.length, { triangle: 3, diamond: 4, polygon: 7, star: 10 }[tool]);
    }
    const circle = { ...area('circle', G.shape('circle', { x: 5, y: 8 }, { x: -5, y: 30 })), kind: 'ellipse' };
    assert.equal(G.area(circle), 121 * Math.PI);
    const before = structuredClone(circle), sized = G.resize([circle], 100, 30, false)[0], b = M.bounds(sized);
    assert.ok(Math.abs(b.maxX - b.minX - 100) < 1e-10);
    assert.ok(Math.abs(b.maxY - b.minY - 30) < 1e-10);
    assert.equal(G.valid(sized), true);
    assert.deepEqual(circle, before);
    assert.throws(() => G.resize([{ ...area('text', [{ x: 0, y: 0 }]), kind: 'text', text: 'Read me' }], 100, 20, false), /비율/);
    assert.throws(() => G.resize([circle], Infinity, 20), /양수/);
});

test('repeated copies isolate instance IDs and groups and reject excessive or overlapping arrays', () => {
    const first = { ...area('a', box(0, 0, 10, 10)), group: 'pair' }, second = { ...area('b', box(12, 0, 10, 10)), group: 'pair' }, before = structuredClone([first, second]);
    const copies = G.repeat([first, second], 3, 2, 40, -30, 2);
    assert.equal(copies.length, 10);
    assert.equal(new Set(copies.map(m => m.id)).size, 10);
    assert.equal(new Set(copies.map(m => m.group)).size, 5);
    assert.equal(copies[0].group, copies[1].group);
    assert.notEqual(copies[0].group, 'pair');
    assert.deepEqual(copies.at(-1).points[0], { x: 92, y: -30 });
    assert.deepEqual([first, second], before);
    assert.ok(G.validSet(copies));
    assert.throws(() => G.repeat([first], 2, 1, 0, 1), /간격/);
    assert.throws(() => G.repeat([first, second], 20, 20, 10, 10, 500), /1,000/);
});

test('appearance fields are bounded and fill fields cannot leak onto lines or text', () => {
    const a = area('styled', box(0, 0, 10, 10));
    assert.ok(G.valid({ ...a, fillColor: '#ff0000', fillOpacity: 0.25, dash: 'dashdot' }));
    for (const change of [{ fillColor: 'url(javascript:bad)' }, { fillOpacity: NaN }, { fillOpacity: 0 }, { dash: 'unknown' }, { kind: 'route', fillColor: '#112233' }])
        assert.equal(G.valid({ ...a, ...change }), false);
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

test("adaptive search finds a tall room before dense adjacent equipment hits the limit", () => {
    const walls = wallBox(0,0,10,30);
    for (let i=0;i<500;i++) {
        walls.push(wall(33+i/500,0,33+i/500,10));
        walls.push(wall(33,i/50,34,i/50));
    }
    const point = {x:5,y:5};
    assert.equal(G.findDrawingBoundary(walls, point, {bounds:{minX:-11,minY:-11,maxX:21,maxY:21}}), null);
    assert.throws(() => G.findDrawingBoundary(walls, point, {bounds:{minX:-27,minY:-27,maxX:37,maxY:37}}), error => error.code === 'CAD_BOUNDARY_LIMIT');
    const result = G.findDrawingBoundaryAdaptive(walls, point, {radii:[16,32,64,128]});
    assert.equal(boundaryArea(result), 300);
    assert.ok(result.search.attempts <= 8);
    assert.throws(() => G.findDrawingBoundaryAdaptive(walls, {x:NaN,y:5}, {radii:[16,32]}), /좌표/);
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
