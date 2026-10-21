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

test('rectangle interior and resize grips use its own rotated axes',()=>{const base=C.newEntity('LWPOLYLINE',{p:{x:0,y:0},points:[{x:0,y:0},{x:20,y:0},{x:20,y:10},{x:0,y:10}],closed:true});assert.equal(C.contains(base,{x:8,y:4}),true);assert.equal(C.contains(base,{x:21,y:4}),false);assert.equal(C.grips(base).length,8);const resized=C.resize(base,'corner2',{x:30,y:15});assert.equal(resized.points[2].x,30);assert.equal(resized.points[2].y,15);assert.equal(base.points[2].x,20);const edge=C.resize(base,'edge1',{x:25,y:100});assert.equal(edge.points[1].x,25);assert.equal(edge.points[2].y,10);const rotated=C.transform(base,{angle:90});assert.equal(C.contains(rotated,{x:-5,y:10}),true);const r=C.resize(rotated,'corner2',{x:-15,y:30});assert.ok(Math.abs(r.points[2].x+15)<1e-9);assert.ok(Math.abs(r.points[2].y-30)<1e-9);});
test('direct resizing supports crossing corners and preserves raw DXF attributes',()=>{const base=C.newEntity('LWPOLYLINE',{p:{x:0,y:0},points:[{x:0,y:0},{x:20,y:0},{x:20,y:10},{x:0,y:10}],closed:true,color:0xff0000,lineWeight:35});const n=C.resize(base,'corner0',{x:30,y:20});assert.equal(n.points[0].x,30);assert.equal(n.points[2].x,20);assert.equal(n.color,0xff0000);assert.equal(n.lineWeight,35);assert.throws(()=>C.resize(base,'edge1',{x:0,y:4}));const d=C.parse(fixture);d.entities[0]=C.resize(d.entities[0],'q',{x:15,y:25});const out=C.exportDXF(d),again=C.parse(out);assert.equal(again.entities[0].q.x,15);assert.equal(again.entities[0].q.y,25);assert.ok(out.includes('5\r\nA1'));});
test('direct line resizing rejects readonly/nonfinite/zero lengths',()=>{const line=C.newEntity('LINE',{p:{x:0,y:0},q:{x:10,y:0}});assert.equal(C.grips(line).length,2);assert.equal(C.resize(line,'p',{x:-5,y:2}).p.x,-5);assert.throws(()=>C.resize(line,'q',{x:0,y:0}));assert.throws(()=>C.resize(line,'q',{x:Infinity,y:0}));assert.throws(()=>C.resize({...line,editable:false},'q',{x:20,y:0}));assert.equal(C.rectangleFrame(C.newEntity('LWPOLYLINE',{closed:true,points:[{x:0,y:0},{x:4,y:0},{x:3,y:5},{x:0,y:5}]})),null);});

test('dimensions serialize ordinary DXF and restore complete matching groups only',()=>{const d=C.parse(fixture),e=C.newDimension({x:0,y:0},{x:30,y:40},{x:-10,y:20},{height:2,color:0xff00ff});d.entities.push(e);const out=C.exportDXF(d),again=C.parse(out),dim=again.entities.find(e=>e.type==='ERP_DIMENSION');assert.ok(out.includes('ERP_CAD_DIM'));assert.ok(dim);assert.equal(C.dimensionLayout(dim).value,50);assert.equal(C.exportDXF(again),out);const moved=C.resize(dim,'dimension-text',{x:90,y:80});assert.equal(moved.textPosition.x,90);const n=C.resize(moved,'dimension-line',{x:-30,y:10});d.entities=[n];const restored=C.parse(C.exportDXF(d)).entities[0];assert.equal(restored.textPosition.y,80);assert.equal(restored.placement.x,-30);const tampered=out.replace('40\r\n2\r\n','40\r\n3\r\n');assert.equal(C.parse(tampered).entities.some(e=>e.type==='ERP_DIMENSION'),false);});
test('dimension modes and atomic transformations retain projected values',()=>{const a={x:10,y:20},b={x:40,y:60};assert.equal(C.dimensionLayout(C.newDimension(a,b,{x:2,y:90},{mode:'horizontal'})).value,30);assert.equal(C.dimensionLayout(C.newDimension(a,b,{x:2,y:90},{mode:'vertical'})).value,40);const original=C.newDimension(a,b,{x:-10,y:80},{mode:'horizontal'}),moved=C.transform(original,{angle:90,scale:2,dx:4});assert.ok(Math.abs(C.dimensionLayout(moved).value-60)<1e-9);const copies=C.array([original],1,2,10,0);assert.notEqual(copies[0].groupId,original.groupId);assert.equal(C.grips(original).length,2);});
test('plain MTEXT multiline Unicode escaping width and angle roundtrip',()=>{const d=C.parse(fixture),text='나손\nsecond \\P {literal}';d.entities.push(C.newEntity('MTEXT',{p:{x:1,y:2},text,height:3,width:24,angle:30,color:0x123456,plainText:true}));const out=C.exportDXF(d),e=C.parse(out).entities.at(-1);assert.equal(e.type,'MTEXT');assert.equal(e.text,text);assert.equal(e.width,24);assert.equal(e.editable,true);assert.ok(Math.abs(e.angle-30)<1e-9);const wider=C.resize(e,'text-width',{x:1+Math.cos(Math.PI/6)*49,y:2+Math.sin(Math.PI/6)*49});assert.ok(Math.abs(wider.width-49)<1e-9);assert.ok(C.textLines(e).length>=2);});

test('incomplete duplicate and unknown-version dimension records stay native',()=>{const d=C.parse(fixture);d.entities=[C.newDimension({x:0,y:0},{x:12,y:5},{x:0,y:10})];const saved=C.exportDXF(d),native=C.parse(saved.replace(/ERP_CAD_DIM/g,'UNRECOGNIZED_APP'));assert.equal(native.entities.length,6);const incomplete=C.clone(native);incomplete.entities.pop();const partial=C.exportDXF(incomplete).replace(/UNRECOGNIZED_APP/g,'ERP_CAD_DIM');assert.equal(C.parse(partial).entities.some(e=>e.type==='ERP_DIMENSION'),false);const duplicate=C.clone(native);duplicate.entities.push(...C.clone(native.entities));assert.equal(C.parse(C.exportDXF(duplicate).replace(/UNRECOGNIZED_APP/g,'ERP_CAD_DIM')).entities.length,12);assert.equal(C.parse(saved.replace(/"v":1/g,'"v":2')).entities.some(e=>e.type==='ERP_DIMENSION'),false);});
test('dimension arrays have distinct group IDs and DXF handles after reopening',()=>{const d=C.parse(fixture),e=C.newDimension({x:2,y:4},{x:22,y:4},{x:10,y:15},{mode:'horizontal'});d.entities=[e,...C.array([e],2,3,40,50)];const saved=C.exportDXF(d),parsed=C.parse(saved);assert.equal(parsed.entities.length,6);assert.equal(new Set(parsed.entities.map(e=>e.groupId)).size,6);const handles=parsed.entities.flatMap(e=>e.rawParts.flat().filter(p=>p[0]===5).map(p=>p[1]));assert.equal(handles.length,36);assert.equal(new Set(handles).size,36);parsed.entities[0]=C.resize(parsed.entities[0],'dimension-line',{x:0,y:25});assert.equal(C.parse(C.exportDXF(parsed)).entities.length,6);});
test('dimensions reject malformed axes nonfinite parameters and zero projection',()=>{const a={x:0,y:0},b={x:3,y:4},p={x:8,y:8};for(const options of [{axis:{x:0,y:0}},{axis:{x:Infinity,y:0}},{axis:{x:2,y:0}},{height:0},{height:-1},{mode:'invalid'},{textPosition:{x:NaN,y:3}}])assert.throws(()=>C.newDimension(a,b,p,options));assert.throws(()=>C.newDimension(a,{x:0,y:5},p,{mode:'horizontal'}));assert.throws(()=>C.newDimension(a,{x:5,y:0},p,{mode:'vertical'}));});
test('long escaped multiline MTEXT chunks and rotated grips survive reparse',()=>{const d=C.parse(fixture),text=('한글 문장 {중괄호} \\P literal \\dir\n').repeat(40),e=C.newEntity('MTEXT',{p:{x:4,y:8},text,height:2,width:40,angle:30,color:0x55aa88});d.entities=[e];const out=C.exportDXF(d),parsed=C.parse(out).entities[0];assert.equal(parsed.text,text);assert.equal(parsed.editable,true);assert.ok(parsed.raw.filter(p=>p[0]===1||p[0]===3).every(p=>p[1].length<=250));const box=C.textBox(parsed),target={x:parsed.p.x+box.u.x*60,y:parsed.p.y+box.u.y*60};const resized=C.resize(parsed,'text-width',target);assert.ok(Math.abs(resized.width-60)<1e-8);d.entities=[resized];const round=C.parse(C.exportDXF(d)).entities[0];assert.equal(round.text,text);assert.ok(Math.abs(round.width-60)<1e-8);assert.ok(Math.abs(round.angle-30)<1e-8);assert.notEqual(JSON.stringify(C.geometry(parsed)),JSON.stringify(C.geometry(resized)));});

test('plain MTEXT exports unambiguous direction vector instead of code50',()=>{const d=C.parse(fixture);d.entities=[C.newEntity('MTEXT',{p:{x:1,y:2},text:'회전 문자',height:3,width:30,angle:30})];const entity=C.parse(C.exportDXF(d)).entities[0];assert.equal(entity.raw.some(p=>p[0]===50),false);assert.ok(Math.abs(Number(entity.raw.find(p=>p[0]===11)[1])-Math.cos(Math.PI/6))<1e-12);assert.ok(Math.abs(Number(entity.raw.find(p=>p[0]===21)[1])-.5)<1e-12);assert.ok(Math.abs(entity.angle-30)<1e-9);});

test('dimension XDATA chunks stay ASCII byte bounded with Korean layer in legacy DXF',()=>{const d=C.parse(fixture.replace('AC1027','AC1015')),layer='한글배관층'.repeat(20);d.layers[layer]={color:'#ddee11',visible:true,locked:false};d.entities=[C.newDimension({x:2,y:4},{x:32,y:44},{x:-10,y:20},{layer,mode:'aligned',height:2})];const out=C.exportDXF(d),lines=out.split('\r\n'),payload=[];for(let i=0;i<lines.length-1;i+=2)if(lines[i]==='1000')payload.push(lines[i+1]);assert.ok(payload.length>6);for(const chunk of payload){assert.ok(/^[\x00-\x7f]*$/.test(chunk));assert.ok(Buffer.byteLength(chunk,'utf8')<=200);}const reopened=C.parse(out),dimension=reopened.entities.find(e=>e.type==='ERP_DIMENSION');assert.ok(dimension);assert.equal(dimension.layer,reopened.entities[0].layer);assert.equal(dimension.layer,layer);assert.ok(Object.hasOwn(reopened.layers,dimension.layer));assert.equal(C.dimensionLayout(dimension).value,50);});

test('reserved native ByLayer casing is equivalent but changed line type is not',()=>{const d=C.parse(fixture);d.entities=[C.newDimension({x:2,y:4},{x:32,y:44},{x:-10,y:20})];const out=C.exportDXF(d),native=out.replace(/6\r\nBYLAYER/g,'6\r\nByLayer');assert.equal(C.parse(native).entities[0].type,'ERP_DIMENSION');const changed=native.replace('6\r\nByLayer','6\r\nDASHED');assert.equal(C.parse(changed).entities.some(e=>e.type==='ERP_DIMENSION'),false);});
