import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
const box = {};
vm.createContext(box);
vm.runInContext(
    fs.readFileSync(
        new URL("../../public/js/cad-core.js", import.meta.url),
        "utf8",
    ),
    box,
);
const C = box.CADCore;
const fixture =
    [
        "0",
        "SECTION",
        "2",
        "HEADER",
        "9",
        "$ACADVER",
        "1",
        "AC1027",
        "0",
        "ENDSEC",
        "0",
        "SECTION",
        "2",
        "ENTITIES",
        "0",
        "LINE",
        "5",
        "A1",
        "8",
        "WALL",
        "10",
        "1",
        "20",
        "2",
        "11",
        "5",
        "21",
        "2",
        "0",
        "CUSTOM_OBJECT",
        "5",
        "B1",
        "310",
        "DEADBEEF",
        "1001",
        "APP",
        "1000",
        "keep me",
        "0",
        "ENDSEC",
        "0",
        "SECTION",
        "2",
        "OBJECTS",
        "0",
        "DICTIONARY",
        "5",
        "C1",
        "0",
        "ENDSEC",
        "0",
        "EOF",
    ].join("\r\n") + "\r\n";
test("unknown sections/entity and known unchanged entity round trip", () => {
    assert.equal(C.exportDXF(C.parse(fixture)), fixture);
});
test("move changes coordinates while preserving handles and unsupported records", () => {
    const d = C.parse(fixture);
    d.entities[0] = C.transform(d.entities[0], { dx: 12, dy: -4 });
    const saved = C.exportDXF(d),
        again = C.parse(saved);
    assert.equal(again.entities[0].p.x, 13);
    assert.equal(again.entities[0].q.y, -2);
    assert.ok(saved.includes("310\r\nDEADBEEF"));
    assert.ok(saved.includes("5\r\nA1"));
    assert.ok(saved.includes("2\r\nOBJECTS"));
    assert.throws(() => C.transform(d.entities[1], { dx: 1 }));
});
test("rotation and scale use explicit anchor and do not mutate original", () => {
    const e = C.parse(fixture).entities[0],
        n = C.transform(e, { angle: 90, scale: 2, center: { x: 1, y: 2 } });
    assert.equal(e.q.x, 5);
    assert.ok(Math.abs(n.q.x - 1) < 1e-10);
    assert.ok(Math.abs(n.q.y - 10) < 1e-10);
    assert.throws(() => C.transform(e, { scale: 0 }));
});
test("arc mirror reverses endpoints preserving sweep", () => {
    const e = C.newEntity("ARC", {
        p: { x: 2, y: 3 },
        radius: 5,
        start: 10,
        end: 80,
    });
    const n = C.transform(e, { mirror: "x" });
    assert.equal(n.start, -80);
    assert.equal(n.end, -10);
    assert.equal(n.p.y, -3);
});
test("new polyline/text circle survive serialization", () => {
    const d = C.parse(fixture);
    d.entities.push(
        C.newEntity("LWPOLYLINE", {
            p: { x: 2, y: 3 },
            points: [
                { x: 2, y: 3 },
                { x: 8, y: 9 },
            ],
            closed: true,
        }),
        C.newEntity("TEXT", {
            p: { x: 4, y: 2 },
            height: 2.5,
            angle: 30,
            text: "나손",
        }),
        C.newEntity("CIRCLE", { p: { x: 0, y: 0 }, radius: 8 }),
    );
    const a = C.parse(C.exportDXF(d));
    assert.equal(a.entities[2].points[1].y, 9);
    assert.equal(a.entities[2].closed, true);
    assert.equal(a.entities[3].text, "나손");
    assert.equal(a.entities[4].radius, 8);
});
test("paper space/nonplanar entities stay read only", () => {
    for (const pair of ["67\r\n1", "30\r\n4", "230\r\n-1"]) {
        const d = C.parse(fixture.replace("11\r\n5", pair + "\r\n11\r\n5"));
        assert.equal(d.entities[0].editable, false);
        assert.equal(
            C.exportDXF(d),
            fixture.replace("11\r\n5", pair + "\r\n11\r\n5"),
        );
    }
});
test("segment picking handles degenerate and far coordinates", () => {
    assert.ok(
        Math.abs(
            C.segmentDistance({ x: 2, y: 3 }, { x: 0, y: 0 }, { x: 0, y: 0 }) -
                Math.sqrt(13),
        ) < 1e-12,
    );
    assert.equal(
        C.segmentDistance(
            { x: 1e9, y: 4 },
            { x: 1e9, y: 0 },
            { x: 1e9 + 100, y: 0 },
        ),
        4,
    );
});
test("reject malformed/binary DXF", () => {
    assert.throws(() => C.parse("AutoCAD Binary DXF"));
    assert.throws(() => C.parse("0\nSECTION\n2\nENTITIES"));
});

test("bulged polylines preserved as read only; straight polylines editable", () => {
    const source = fixture.replace(
        "0\r\nCUSTOM_OBJECT",
        "0\r\nLWPOLYLINE\r\n90\r\n2\r\n10\r\n0\r\n20\r\n0\r\n42\r\n1\r\n10\r\n10\r\n20\r\n0\r\n0\r\nCUSTOM_OBJECT",
    );
    const d = C.parse(source);
    assert.equal(d.entities[1].editable, false);
    assert.equal(C.exportDXF(d), source);
    const straight = C.parse(source.replace("42\r\n1", "42\r\n0"));
    assert.equal(straight.entities[1].editable, true);
});

test("offset line/circle validate distance and preserve source", () => {
    const e = C.newEntity("LINE", { p: { x: 0, y: 0 }, q: { x: 10, y: 0 } }),
        n = C.offset(e, 3);
    assert.equal(n.p.y, 3);
    assert.equal(e.p.y, 0);
    assert.equal(
        C.offset(C.newEntity("CIRCLE", { p: { x: 0, y: 0 }, radius: 5 }), 2)
            .radius,
        7,
    );
    assert.throws(() => C.offset(C.newEntity("CIRCLE", { radius: 1 }), -2));
});
test("trim and extend compute actual line intersection", () => {
    const e = C.newEntity("LINE", { p: { x: 0, y: 0 }, q: { x: 10, y: 0 } }),
        b = C.newEntity("LINE", { p: { x: 4, y: -2 }, q: { x: 4, y: 2 } });
    assert.equal(C.trim(e, b, { x: 0, y: 0 }).p.x, 4);
    const far = C.newEntity("LINE", { p: { x: 20, y: 0 }, q: { x: 20, y: 2 } });
    assert.equal(C.trim(e, far, { x: 0, y: 0 }, true).q.x, 20);
    assert.throws(() => C.trim(e, far, { x: 0, y: 0 }));
});
test("array makes independent translated copies and bounds its size", () => {
    const e = C.newEntity("LINE", { p: { x: 0, y: 0 }, q: { x: 1, y: 0 } }),
        out = C.array([e], 2, 3, 10, 20);
    assert.equal(out.length, 5);
    assert.equal(out.at(-1).p.x, 20);
    assert.equal(out.at(-1).p.y, 20);
    assert.equal(e.p.x, 0);
    assert.throws(() => C.array([e], 501, 1, 1, 1));
});
test("layers persist and new entities have unique DXF handles/subclasses", () => {
    const d = C.parse(fixture);
    d.layers.NEW = { color: "#ff0000", visible: false, locked: true };
    d.layersDirty = true;
    d.entities.push(
        C.newEntity("CIRCLE", { p: { x: 4, y: 6 }, radius: 3 }, "NEW"),
        C.newEntity(
            "ARC",
            { p: { x: 4, y: 6 }, radius: 3, start: 10, end: 90 },
            "NEW",
        ),
    );
    const saved = C.exportDXF(d),
        a = C.parse(saved);
    assert.equal(a.layers.NEW.visible, false);
    assert.equal(a.layers.NEW.locked, true);
    assert.equal(a.layers.NEW.color, "#ff0000");
    const handles = a.entities
        .map((e) => e.raw.find((p) => p[0] === 5)?.[1])
        .filter(Boolean);
    assert.equal(new Set(handles).size, handles.length);
    assert.ok(saved.includes("AcDbArc"));
});
test("nonfinite values and nonplanar LINE second endpoint cannot edit", () => {
    assert.throws(() => C.parse(fixture.replace("10\r\n1", "10\r\nInfinity")));
    const d = C.parse(fixture.replace("11\r\n5", "31\r\n6\r\n11\r\n5"));
    assert.equal(d.entities[0].editable, false);
});

test("ELLIPSE and classic POLYLINE preview without allowing unsafe edits", () => {
    const source = fixture.replace(
        "0\r\nCUSTOM_OBJECT",
        "0\r\nELLIPSE\r\n10\r\n10\r\n20\r\n20\r\n11\r\n5\r\n21\r\n0\r\n40\r\n0.5\r\n0\r\nPOLYLINE\r\n70\r\n1\r\n0\r\nVERTEX\r\n10\r\n2\r\n20\r\n3\r\n0\r\nVERTEX\r\n10\r\n7\r\n20\r\n8\r\n0\r\nSEQEND\r\n0\r\nCUSTOM_OBJECT",
    );
    const d = C.parse(source),
        ellipse = d.entities[1],
        poly = d.entities[2];
    assert.equal(ellipse.editable, false);
    assert.equal(poly.editable, false);
    assert.equal(C.geometry(ellipse)[0].x, 15);
    assert.equal(C.geometry(poly).length, 3);
    assert.equal(C.geometry(d.entities.at(-1)).length, 0);
    assert.equal(C.exportDXF(d), source);
});
test("block INSERT previews transformed geometry without modifying references", () => {
    const block =
        [
            "0",
            "SECTION",
            "2",
            "BLOCKS",
            "0",
            "BLOCK",
            "2",
            "BOX",
            "10",
            "0",
            "20",
            "0",
            "0",
            "LINE",
            "10",
            "0",
            "20",
            "0",
            "11",
            "10",
            "21",
            "0",
            "0",
            "ENDBLK",
            "0",
            "ENDSEC",
        ].join("\r\n") + "\r\n";
    const source = fixture
        .replace(
            "0\r\nSECTION\r\n2\r\nENTITIES",
            block + "0\r\nSECTION\r\n2\r\nENTITIES",
        )
        .replace(
            "0\r\nCUSTOM_OBJECT",
            "0\r\nINSERT\r\n2\r\nBOX\r\n10\r\n100\r\n20\r\n200\r\n41\r\n2\r\n50\r\n90\r\n0\r\nCUSTOM_OBJECT",
        );
    const d = C.parse(source),
        e = d.entities[1],
        ps = C.geometry(e);
    assert.equal(ps.length, 2);
    assert.equal(ps[0].x, 100);
    assert.ok(Math.abs(ps[1].y - 220) < 1e-10);
    assert.equal(e.editable, false);
    assert.equal(C.exportDXF(d), source);
});

test("editing a line preserves unrelated ACI layer record exactly", () => {
    const table =
        [
            "0",
            "SECTION",
            "2",
            "TABLES",
            "0",
            "TABLE",
            "2",
            "LAYER",
            "5",
            "10",
            "70",
            "1",
            "0",
            "LAYER",
            "5",
            "11",
            "2",
            "WALL",
            "70",
            "16",
            "62",
            "140",
            "6",
            "DASHED",
            "0",
            "ENDTAB",
            "0",
            "ENDSEC",
        ].join("\r\n") + "\r\n";
    const source = fixture.replace(
        "0\r\nSECTION\r\n2\r\nENTITIES",
        table + "0\r\nSECTION\r\n2\r\nENTITIES",
    );
    const d = C.parse(source);
    d.entities[0] = C.transform(d.entities[0], { dx: 1 });
    const out = C.exportDXF(d);
    assert.ok(
        out.includes(
            "0\r\nLAYER\r\n5\r\n11\r\n2\r\nWALL\r\n70\r\n16\r\n62\r\n140\r\n6\r\nDASHED",
        ),
    );
    const raw = C.parse(out).layers.WALL;
    assert.equal(raw.originalColor, d.layers.WALL.originalColor);
});
test("width/vertex IDs and special flags polylines are read-only", () => {
    for (const extra of [
        "40\r\n2",
        "41\r\n3",
        "91\r\n100",
        "70\r\n128",
        "43\r\n2",
    ]) {
        const source = fixture.replace(
            "0\r\nCUSTOM_OBJECT",
            "0\r\nLWPOLYLINE\r\n90\r\n2\r\n" +
                extra +
                "\r\n10\r\n0\r\n20\r\n0\r\n10\r\n10\r\n20\r\n0\r\n0\r\nCUSTOM_OBJECT",
        );
        const d = C.parse(source);
        assert.equal(d.entities[1].editable, false);
        assert.equal(C.exportDXF(d), source);
    }
});
test("aligned TEXT and all MTEXT preserve orientation as read-only", () => {
    for (const extra of ["72\r\n1", "73\r\n2", "71\r\n2"]) {
        const source = fixture.replace(
            "0\r\nCUSTOM_OBJECT",
            "0\r\nTEXT\r\n1\r\nTest\r\n" + extra + "\r\n0\r\nCUSTOM_OBJECT",
        );
        const d = C.parse(source);
        assert.equal(d.entities[1].editable, false);
    }
    const source = fixture.replace(
        "0\r\nCUSTOM_OBJECT",
        "0\r\nMTEXT\r\n1\r\nTest\r\n50\r\n1.5707963267948966\r\n0\r\nCUSTOM_OBJECT",
    );
    const d = C.parse(source);
    assert.equal(d.entities[1].editable, false);
    assert.ok(Math.abs(d.entities[1].angle - 90) < 1e-9);
    assert.equal(C.exportDXF(d), source);
});
test("prototype-like layer names never collide or pollute prototypes", () => {
    for (const name of ["__proto__", "constructor", "prototype"]) {
        const d = C.parse(fixture.replace("8\r\nWALL", "8\r\n" + name));
        assert.ok(Object.hasOwn(d.layers, name));
        d.entities[0] = C.transform(d.entities[0], { dx: 1 });
        assert.ok(C.exportDXF(d).includes(name));
    }
    assert.equal({}.visible, undefined);
});
test("legacy unicode escapes roundtrip and R12 refuses incompatible regeneration", () => {
    const d = C.parse(fixture.replace("AC1027", "AC1015"));
    d.entities.push(
        C.newEntity("TEXT", {
            p: { x: 1, y: 1 },
            height: 2,
            angle: 0,
            text: "나손",
        }),
    );
    const out = C.exportDXF(d);
    assert.ok(out.includes("\\U+B098\\U+C190"));
    assert.equal(C.parse(out).entities.at(-1).text, "나손");
    assert.ok(out.includes("AC1015"));
    const old = C.parse(fixture.replace("AC1027", "AC1009"));
    assert.equal(old.entities[0].editable, false);
    old.entities.push(C.newEntity("CIRCLE", { p: { x: 1, y: 2 }, radius: 1 }));
    assert.throws(() => C.exportDXF(old));
});
test("array bounds total additions before allocation", () => {
    const e = C.newEntity("LINE", { p: { x: 0, y: 0 }, q: { x: 1, y: 0 } });
    assert.throws(() => C.array(Array(200).fill(e), 20, 20, 1, 1));
});

test("layer rename preserves handle and current-layer header and refuses opaque references", () => {
    const source =
        "0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n9\n$CLAYER\n8\nWALL\n0\nENDSEC\n0\nSECTION\n2\nTABLES\n0\nTABLE\n2\nLAYER\n70\n1\n0\nLAYER\n5\nAE\n2\nWALL\n70\n0\n62\n42\n0\nENDTAB\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nLINE\n8\nWALL\n10\n0\n20\n0\n11\n10\n21\n10\n0\nENDSEC\n0\nEOF\n";
    const original = C.parse(source);
    const changed = C.renameLayer(original, "WALL", "PIPE");
    const output = C.exportDXF(changed);
    assert.ok(output.includes("$CLAYER\r\n8\r\nPIPE"));
    assert.ok(output.includes("LAYER\r\n5\r\nAE\r\n2\r\nPIPE"));
    assert.equal(C.parse(output).entities[0].layer, "PIPE");
    assert.equal(original.entities[0].layer, "WALL");
    const opaque = C.parse(
        source.replace("0\nEOF", "0\nSECTION\n2\nOBJECTS\n0\nENDSEC\n0\nEOF"),
    );
    assert.throws(() => C.renameLayer(opaque, "WALL", "PIPE"));
});
