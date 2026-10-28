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
test('viewport raster reuses unchanged CAD pixels and refreshes on CAD edits, pan, layers and resize', () => {
    let allocated = 0, paints = 0, copied = 0;
    const buffer = {save(){},restore(){},setTransform(){},clearRect(){}};
    const canvas = {width:0,height:0,getContext:()=>buffer};
    const target = {drawImage(surface,x,y,w,h){assert.equal(surface,canvas);assert.equal(w,canvas.width/2);assert.equal(h,canvas.height/2);copied++;}};
    const cache = C.createRasterCache(()=>{allocated++;return canvas;});
    const doc = {}, key = [doc, 1, 'visible'];
    const options = {width:2000,height:1000,dpr:2,key,reuse:true};
    const paint = ctx => {assert.equal(ctx,buffer);paints++;};
    cache.draw(target,options,paint);
    for(let i=0;i<20;i++)cache.draw(target,options,paint);
    assert.equal(paints,1);assert.equal(copied,21);assert.equal(allocated,1);
    cache.draw(target,{...options,reuse:false},paint); // Geometry or style edited in place.
    cache.draw(target,{...options,key:[doc,2,'visible']},paint); // Pan/zoom.
    cache.draw(target,{...options,key:[doc,2,'hidden']},paint); // Layer visibility.
    cache.draw(target,{...options,key:[{},2,'hidden']},paint); // Another drawing.
    cache.draw(target,{...options,width:1600},paint);
    assert.equal(paints,6);assert.equal(allocated,1);
});
test('failed and oversized viewport paints cannot reuse stale pixels or retain a huge canvas', () => {
    let paints = 0;
    const buffer = {save(){},restore(){},setTransform(){},clearRect(){}};
    const canvas = {width:0,height:0,getContext:()=>buffer};
    const target = {drawImage(){}};
    const cache = C.createRasterCache(()=>canvas), options={width:100,height:100,dpr:1,key:['view'],reuse:true};
    assert.throws(()=>cache.draw(target,options,()=>{throw Error('paint failed');}),/paint failed/);
    cache.draw(target,options,()=>paints++);assert.equal(paints,1);
    cache.draw(target,{...options,width:5000,height:5000},ctx=>{assert.equal(ctx,target);paints++;});
    assert.equal(canvas.width*canvas.height,0);
    cache.draw(target,options,()=>paints++);assert.equal(paints,3);
});
test('white canvas keeps CAD colors legible without changing dark or paper colors', () => {
    const luminance = hex => [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16) / 255)
        .map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4)
        .reduce((sum, c, i) => sum + c * [.2126, .7152, .0722][i], 0);
    for (const color of ['#ffffff', '#e2e8f0', '#FFFF00', '#62dbed', '#58df8a', '#000000', '#ff0000', '#123456']) {
        assert.ok(1.05 / (luminance(C.canvasColor(color, 'white')) + .05) >= 4.5, color);
        assert.equal(C.canvasColor(color, 'dark'), color);
        assert.equal(C.canvasColor(color), color);
    }
    assert.equal(C.canvasColor('#123456', 'white'), '#123456');
    const entity = C.newEntity('LINE', {p:{x:0,y:0},q:{x:10,y:0},color:0xffffff});
    const before = JSON.stringify(entity), strokes = [];
    const ctx = {setLineDash(){},beginPath(){},moveTo(){},lineTo(){},stroke(){strokes.push([this.strokeStyle,this.lineWidth]);}};
    const doc = {layers:{0:{color:'#e2e8f0'}}};
    for (const options of [{},{background:'white'},{paper:true},{paper:true,background:'white'}])
        C.renderEntities(ctx, doc, [entity], p => p, 1, options);
    assert.deepEqual(strokes[0], ['#ffffff',1]);
    assert.deepEqual(strokes[1], ['#334155',1]);
    assert.deepEqual(strokes[2], strokes[3]);
    assert.equal(JSON.stringify(entity), before);
});
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

test("bulged polylines preserve raw records and support safe 2D transforms", () => {
    const source = fixture.replace(
        "0\r\nCUSTOM_OBJECT",
        "0\r\nLWPOLYLINE\r\n90\r\n2\r\n10\r\n0\r\n20\r\n0\r\n42\r\n1\r\n10\r\n10\r\n20\r\n0\r\n0\r\nCUSTOM_OBJECT",
    );
    const d = C.parse(source);
    assert.equal(d.entities[1].editable, true);
    assert.equal(C.exportDXF(d), source);assert.equal(C.allows(d.entities[1],'resize'),false);
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
test("width/vertex IDs and 2D flags preserve records during safe editing", () => {
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
        assert.equal(d.entities[1].editable, true);assert.equal(C.allows(d.entities[1],'move'),true);
        assert.equal(C.exportDXF(d), source);
    }
});
test("aligned TEXT and plain MTEXT preserve native alignment with limited operations", () => {
    for (const extra of ["72\r\n1", "73\r\n2", "71\r\n2"]) {
        const source = fixture.replace(
            "0\r\nCUSTOM_OBJECT",
            "0\r\nTEXT\r\n1\r\nTest\r\n" + extra + "\r\n0\r\nCUSTOM_OBJECT",
        );
        const d = C.parse(source);
        assert.equal(d.entities[1].editable, true);
    }
    const source = fixture.replace(
        "0\r\nCUSTOM_OBJECT",
        "0\r\nMTEXT\r\n1\r\nTest\r\n50\r\n1.5707963267948966\r\n0\r\nCUSTOM_OBJECT",
    );
    const d = C.parse(source);
    assert.equal(d.entities[1].editable, true);
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

test('AI quantities use exact primitive lengths and closed straight polygon areas',()=>{const rect=C.newEntity('LWPOLYLINE',{p:{x:0,y:0},points:[{x:0,y:0},{x:3,y:0},{x:3,y:4},{x:0,y:4}],closed:true});assert.equal(C.quantities(rect).length,14);assert.equal(C.quantities(rect).area,12);assert.equal(C.quantities(C.newEntity('LINE',{p:{x:0,y:0},q:{x:3,y:4}})).length,5);assert.ok(Math.abs(C.quantities(C.newEntity('CIRCLE',{p:{x:0,y:0},radius:2})).area-4*Math.PI)<1e-10);assert.ok(Math.abs(C.quantities(C.newEntity('ARC',{p:{x:0,y:0},radius:3,start:350,end:10})).length-Math.PI/3)<1e-10);assert.equal(C.quantities({...rect,points:[{x:0,y:0,bulge:1},{x:4,y:2}]}).available,false);assert.equal(C.quantities({type:'ELLIPSE'}).area,null);});
test('AI context shares only explicit visible selection and never raw DXF',()=>{const d=C.parse(fixture);d.entities[0].raw=[...d.entities[0].raw,[1000,'SENSITIVE_RAW_ONLY']];d.entities.push(C.newEntity('TEXT',{p:{x:0,y:0},height:2,text:'not selected',hidden:true}));const context=C.aiContext(d,{scope:'selection',selection:[d.entities[0].id],unit:'mm',documentHash:'a'.repeat(64)});assert.equal(context.entities.length,1);assert.equal(context.entities[0].quantities.length,4);assert.equal(context.layers.length,1);assert.equal(JSON.stringify(context).includes('SENSITIVE_RAW_ONLY'),false);assert.equal(JSON.stringify(context).includes('not selected'),false);assert.throws(()=>C.aiContext(d,{scope:'selection',selection:[],unit:'drawing',documentHash:'a'.repeat(64)}));assert.throws(()=>C.aiContext(d,{scope:'visible',unit:'',documentHash:'a'.repeat(64)}));});
test('AI action transactions validate all actions before exposing any document change',()=>{const d=C.parse(fixture),original=C.exportDXF(d),id=d.entities[0].id,options={canManage:true,sharedIds:[id],sharedLayers:['WALL'],expectedHash:'a'.repeat(64),currentHash:'a'.repeat(64),transactionId:'cmd-1'};assert.throws(()=>C.applyAIActions(d,[{type:'move',entityIds:[id],dx:2,dy:3},{type:'delete',entityIds:['outside']}],options));assert.equal(C.exportDXF(d),original);const result=C.applyAIActions(d,[{type:'move',entityIds:[id],dx:2,dy:3},{type:'style',entityIds:[id],color:'#112233'}],options);assert.equal(result.document.entities[0].p.x,3);assert.equal(result.document.entities[0].color,0x112233);assert.equal(d.entities[0].p.x,1);assert.throws(()=>C.applyAIActions(d,[{type:'delete',entityIds:[id]}],{...options,currentHash:'b'.repeat(64)}));});
test('AI actions refuse locked hidden readonly outside scope and unknown parameters',()=>{const source=C.parse(fixture),id=source.entities[0].id,options={canManage:true,sharedIds:[id],sharedLayers:['WALL'],expectedHash:'a'.repeat(64),currentHash:'a'.repeat(64)};for(const patch of [{hidden:true},{paper:true},{editable:false}]){const d=C.clone(source);Object.assign(d.entities[0],patch);assert.throws(()=>C.applyAIActions(d,[{type:'move',entityIds:[id],dx:1,dy:0}],options));}const locked=C.clone(source);locked.layers.WALL.locked=true;assert.throws(()=>C.applyAIActions(locked,[{type:'delete',entityIds:[id]}],options));assert.throws(()=>C.applyAIActions(source,[{type:'move',entityIds:[id],dx:1,dy:0,url:'https://example.com'}],options));assert.throws(()=>C.applyAIActions(source,[{type:'add_line',p:{x:0,y:0},q:{x:1,y:2},layer:'0'}],options));assert.throws(()=>C.applyAIActions(source,[{type:'move',entityIds:[id],dx:Infinity,dy:0}],options));});
test('AI additions and partial text edits persist without rewriting unrelated text properties',()=>{const d=C.parse(fixture),id=d.entities[0].id,options={canManage:true,sharedIds:[id],sharedLayers:['WALL'],expectedHash:'a'.repeat(64),currentHash:'a'.repeat(64),transactionId:'test'};const result=C.applyAIActions(d,[{type:'add_text',p:{x:5,y:6},text:'note',height:3,width:20,layer:'WALL',color:'#ff8800'},{type:'add_rectangle',p:{x:0,y:0},q:{x:3,y:4},layer:'WALL'},{type:'add_dimension',p:{x:0,y:0},q:{x:10,y:0},placement:{x:0,y:5},height:2,mode:'horizontal',layer:'WALL'}],options);const text=result.document.entities.find(e=>e.type==='MTEXT');const changed=C.applyAIActions(result.document,[{type:'text',entityIds:[text.id],color:'#001122'}],{...options,sharedIds:[text.id]});assert.equal(changed.document.entities.find(e=>e.id===text.id).text,'note');assert.equal(changed.document.entities.find(e=>e.id===text.id).height,3);const again=C.parse(C.exportDXF(changed.document));assert.ok(again.entities.some(e=>e.type==='ERP_DIMENSION'));assert.ok(again.entities.some(e=>e.text==='note'));});
test('AI nonplanar geometry stays unavailable rather than publishing projected quantities',()=>{const d=C.parse(fixture),e=d.entities[0];e.raw=[...e.raw,[31,'5']];e.editable=false;assert.equal(C.aiGeometry(e),null);assert.equal(C.quantities(e).available,false);assert.equal(C.quantities(e).length,null);});
test('AI action counts and shared context bytes are bounded',()=>{const d=C.parse(fixture),id=d.entities[0].id,options={canManage:true,sharedIds:[id],sharedLayers:['WALL'],expectedHash:'a'.repeat(64),currentHash:'a'.repeat(64)};assert.throws(()=>C.applyAIActions(d,Array.from({length:101},()=>({type:'delete',entityIds:[id]})),options));d.entities=Array.from({length:2001},(_,i)=>({...C.newEntity('POINT',{p:{x:i,y:0}}),id:'p'+i}));assert.throws(()=>C.aiContext(d,{scope:'visible',unit:'drawing',documentHash:'a'.repeat(64)}));});

test('dimension unit conversion keeps native geometry and survives DXF reopen',()=>{
    const d=C.parse(fixture),original=C.exportDXF(d),p={x:0,y:0},q={x:66,y:0},placement={x:0,y:12};
    for(const [unit,label] of [['in','66.000 in'],['ft','5.500 ft'],['ft-in',"5' 6\""],['mm','1676.400 mm']]){
        const e=C.newDimension(p,q,placement,{measurement:{unit,mmPerUnit:25.4}});
        const before=JSON.stringify([e.p,e.q,e.placement,e.height]);
        d.entities.push(e);const out=C.exportDXF(d),again=C.parse(out),saved=again.entities.at(-1);
        assert.equal(saved.type,'ERP_DIMENSION');assert.equal(C.dimensionLayout(saved).label,label);
        assert.equal(JSON.stringify([saved.p,saved.q,saved.placement,saved.height]),before);
        assert.equal(C.exportDXF(again),out);d.entities.pop();
    }
    assert.equal(C.exportDXF(d),original);
    assert.equal(C.formatLength(1676.4,{unit:'ft',mmPerUnit:1}),'5.500 ft');
    assert.equal(C.formatLength(5.5,{unit:'in',mmPerUnit:304.8}),'66.000 in');
    assert.equal(C.formatLength(11.999999,{unit:'ft-in',mmPerUnit:25.4}),"1' 0\"");
    assert.equal(C.formatLength(-66,{unit:'ft-in',mmPerUnit:25.4}),"-5' 6\"");
    assert.equal(C.lengthInputScale({unit:'ft-in',mmPerUnit:25.4}),1/12);
});
test('drawing unit detection never guesses unknown or unitless DXF',()=>{
    const doc=code=>({prefix:[[9,'$INSUNITS'],[70,String(code)]]});
    assert.equal(C.drawingUnit(doc(1)).mmPerUnit,25.4);assert.equal(C.drawingUnit(doc(2)).mmPerUnit,304.8);
    assert.equal(C.drawingUnit(doc(4)).mmPerUnit,1);assert.equal(C.drawingUnit(doc(21)).mmPerUnit,1200000/3937);
    for(const value of [doc(0),doc(999),{prefix:[]},{prefix:[[9,'$INSUNITS'],[9,'$LUNITS'],[70,1]]}])assert.equal(C.drawingUnit(value),null);
    for(const measurement of [{unit:'bad',mmPerUnit:1},{unit:'ft',mmPerUnit:0},{unit:'ft',mmPerUnit:Infinity},{unit:'ft',mmPerUnit:-1}])assert.throws(()=>C.newDimension({x:0,y:0},{x:5,y:0},{x:0,y:3},{measurement}));
});
