import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { execFileSync } from "node:child_process";
import vm from "node:vm";
import assert from "node:assert/strict";
import path from "node:path";

// Uses the same serializer as the browser and the real DWG engine, without mocks.
const [dotnet, converter, directory] = process.argv.slice(2);
if (!dotnet || !converter || !directory)
    throw new Error(
        "Usage: node scripts/test-cad-roundtrip.mjs <dotnet> <converter.dll> <scratch-directory>",
    );
mkdirSync(directory, { recursive: true });
const context = vm.createContext({});
vm.runInContext(readFileSync("public/js/cad-core.js", "utf8"), context);
const C = context.CADCore;
let d = C.parse(
    "0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n",
);
d.entities.push(
    C.newEntity("LINE", {
        p: { x: 1.25, y: 2.5 },
        q: { x: 100.125, y: 50.25 },
    }),
);
d.entities.push(C.newEntity("CIRCLE", { p: { x: 20, y: 20 }, radius: 5 }));
d.entities.push(
    C.newEntity("TEXT", {
        p: { x: 5, y: 10 },
        text: "NAHSHON 테스트",
        height: 2.5,
        angle: 0,
    }),
);
const input = path.resolve(directory, "frontend.dxf");
writeFileSync(input, C.exportDXF(d));
function convert(from, to, expectedEntities = 3) {
    const output = path.resolve(directory, to);
    const metadata = JSON.parse(
        execFileSync(
            dotnet,
            [
                converter,
                "convert",
                "--input",
                path.resolve(directory, from),
                "--output",
                output,
            ],
            { encoding: "utf8" },
        ),
    );
    assert.equal(metadata.entities, expectedEntities);
    return output;
}
const dwg = convert("frontend.dxf", "original.dwg");
assert.equal(readFileSync(dwg).subarray(0, 6).toString(), "AC1032");
convert("original.dwg", "reopened.dxf");
d = C.parse(readFileSync(path.resolve(directory, "reopened.dxf"), "utf8"));
const line = d.entities.find((e) => e.type === "LINE");
assert.ok(line, "DWG reread contains LINE");
assert.equal(line.p.x, 1.25);
assert.equal(line.q.x, 100.125);
d.entities = d.entities.map((e) =>
    e.type === "LINE" ? C.transform(e, { dx: 12.5, dy: -3.25 }) : e,
);
writeFileSync(path.resolve(directory, "edited.dxf"), C.exportDXF(d));
convert("edited.dxf", "edited.dwg");
convert("edited.dwg", "verified.dxf");
const verified = C.parse(
    readFileSync(path.resolve(directory, "verified.dxf"), "utf8"),
);
const moved = verified.entities.find((e) => e.type === "LINE");
assert.equal(moved.p.x, 13.75);
assert.equal(moved.p.y, -0.75);
assert.equal(moved.q.x, 112.625);
assert.equal(moved.q.y, 47);
assert.equal(verified.entities.find((e) => e.type === "CIRCLE").radius, 5);
assert.equal(
    verified.entities.find((e) => e.type === "TEXT").text,
    "NAHSHON 테스트",
);
const legacy = C.parse(readFileSync(input, "utf8").replace("AC1032", "AC1015"));
legacy.entities[0] = C.transform(legacy.entities[0], { dx: 0.5 });
const legacyText = C.exportDXF(legacy);
assert.ok(
    legacyText.includes("\\U+"),
    "Legacy DXF stores Unicode escapes rather than invalid codepage bytes",
);
writeFileSync(path.resolve(directory, "legacy.dxf"), legacyText);
convert("legacy.dxf", "legacy.dwg");
convert("legacy.dwg", "legacy-verified.dxf");
const legacyVerified = C.parse(
    readFileSync(path.resolve(directory, "legacy-verified.dxf"), "utf8"),
);
assert.equal(
    legacyVerified.entities.find((e) => e.type === "TEXT").text,
    "NAHSHON 테스트",
);
console.log(
    "PASS: browser DXF → real DWG → reopen → edit → DWG → reopen; coordinates, circle, modern and legacy Korean text verified.",
);

// Editable annotations must survive the actual native serializer, including
// its XDATA, MTEXT chunking, Unicode and angle representation changes.
const annotations = C.parse('0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n');
const annotationLayer = '천장_배관_기성치수_'.repeat(10);
annotations.layers[annotationLayer] = {color:'#22bb88', visible:true, locked:false};
for (const mode of ['aligned', 'horizontal', 'vertical']) {
    annotations.entities.push(C.newDimension({x:10,y:20}, {x:40,y:60}, {x:-15,y:90}, {mode,height:3,color:0x22bb88,layer:annotationLayer,measurement:{unit:{aligned:'in',horizontal:'ft',vertical:'ft-in'}[mode],mmPerUnit:25.4}}));
}
const note = '한글 여러 줄\nsecond \\P {literal}\n' + '현장 메모 '.repeat(80);
annotations.entities.push(C.newEntity('MTEXT', {p:{x:80,y:100},text:note,height:3,width:36,angle:30,color:0x40aaee,plainText:true}));
writeFileSync(path.resolve(directory, 'annotations.dxf'), C.exportDXF(annotations));
convert('annotations.dxf', 'annotations.dwg', 19);
convert('annotations.dwg', 'annotations-reopened.dxf', 19);
const reopenedAnnotations = C.parse(readFileSync(path.resolve(directory, 'annotations-reopened.dxf'), 'utf8'));
const dimensions = reopenedAnnotations.entities.filter(e => e.type === 'ERP_DIMENSION');
assert.equal(dimensions.length, 3, 'Complete annotations remain editable after real DWG roundtrip');
for (const dim of dimensions) {
    assert.equal(dim.layer, annotationLayer);
    assert.ok(Math.abs(C.dimensionLayout(dim).value - {aligned:50,horizontal:30,vertical:40}[dim.mode]) < 1e-8);
    assert.equal(C.dimensionLayout(dim).label,{aligned:'50.000 in',horizontal:'2.500 ft',vertical:"3' 4\""}[dim.mode]);
}
const reopenedNote = reopenedAnnotations.entities.find(e => e.type === 'MTEXT');
assert.equal(reopenedNote.text, note);
assert.equal(reopenedNote.editable, true);
assert.equal(reopenedNote.width, 36);
assert.equal(reopenedNote.height, 3);
assert.ok(Math.abs(reopenedNote.angle - 30) < 1e-8, `Expected 30 degrees, got ${reopenedNote.angle}`);
reopenedAnnotations.entities = reopenedAnnotations.entities.map(e => {
    if (e.type === 'ERP_DIMENSION') return C.resize(C.resize(e, 'dimension-line', {x:-25,y:105}), 'dimension-text', {x:65,y:110});
    // Native DXF also carries read-only layout records. Edit the selected note,
    // exactly as the browser does, and retain every unrelated entity unchanged.
    if (e.id === reopenedNote.id) return {...C.transform(e, {dx:7,dy:-5}), text:note+'\n수정 완료', width:48, dirty:true};
    return e;
});
writeFileSync(path.resolve(directory, 'annotations-edited.dxf'), C.exportDXF(reopenedAnnotations));
convert('annotations-edited.dxf', 'annotations-edited.dwg', 19);
convert('annotations-edited.dwg', 'annotations-verified.dxf', 19);
const finalAnnotations = C.parse(readFileSync(path.resolve(directory, 'annotations-verified.dxf'), 'utf8'));
assert.equal(finalAnnotations.entities.filter(e => e.type === 'ERP_DIMENSION').length, 3);
for (const dim of finalAnnotations.entities.filter(e => e.type === 'ERP_DIMENSION')) {
    assert.equal(dim.placement.y, 105);
    assert.equal(dim.textPosition.x, 65);
    assert.equal(dim.textPosition.y, 110);
}
const finalNote = finalAnnotations.entities.find(e => e.type === 'MTEXT');
assert.equal(finalNote.text, note+'\n수정 완료');
assert.equal(finalNote.width, 48);
assert.equal(finalNote.p.x, 87);
assert.equal(finalNote.p.y, 95);
assert.equal(finalNote.editable, true);
console.log('PASS: aligned/horizontal/vertical dimensions, repositioned labels and long multiline Unicode text remain editable through DWG re-save.');

// Imported 2D entities commonly carry elevation, zero/variable vertex widths
// and text alignment. Exercise native files, not only newly drawn primitives.
const base2D = C.parse(readFileSync(input, 'utf8'));
const importedRecords = [
    [0,'LINE'],[5,'F101'],[100,'AcDbEntity'],[8,'0'],[100,'AcDbLine'],
    [10,10],[20,20],[30,12.5],[11,50],[21,20],[31,12.5],
    [0,'LWPOLYLINE'],[5,'F102'],[100,'AcDbEntity'],[8,'0'],[100,'AcDbPolyline'],
    [90,3],[70,129],[38,12.5],[43,0],
    [10,10],[20,40],[40,0.25],[41,0.5],[42,0.5],
    [10,50],[20,40],[40,0.5],[41,0.75],[42,0],
    [10,50],[20,70],[40,0.75],[41,0.25],[42,0],
    [0,'TEXT'],[5,'F103'],[100,'AcDbEntity'],[8,'0'],[100,'AcDbText'],
    [10,20],[20,80],[30,12.5],[40,3],[1,'Imported aligned text'],[7,'Standard'],[72,1],
    [11,40],[21,80],[31,12.5],[100,'AcDbText'],[73,0],
    [0,'MTEXT'],[5,'F104'],[100,'AcDbEntity'],[8,'0'],[100,'AcDbMText'],
    [10,70],[20,80],[30,12.5],[40,3],[41,30],[71,5],[72,1],
    [1,'Imported centered note'],[7,'Standard'],[11,1],[21,0],[31,0],
];
const importedSource = base2D.prefix.concat(importedRecords, base2D.suffix)
    .map(([code,value]) => `${code}\n${value}\n`).join('');
writeFileSync(path.resolve(directory, 'imported-2d.dxf'), importedSource);
convert('imported-2d.dxf', 'imported-2d.dwg', 4);
convert('imported-2d.dwg', 'imported-2d-reopened.dxf', 4);
const imported = C.parse(readFileSync(path.resolve(directory, 'imported-2d-reopened.dxf'), 'utf8'));
const importedTypes = ['LINE','LWPOLYLINE','TEXT','MTEXT'];
const rawNumber = (entity, code, fallback=0) => Number(entity.raw.find(pair=>pair[0]===code)?.[1] ?? fallback);
const importedBefore = Object.fromEntries(importedTypes.map(type => [type, imported.entities.find(entity=>entity.type===type)]));
for (const type of importedTypes) assert.equal(importedBefore[type].editable, true, `${type} imported from DWG is editable in XY`);
imported.entities = imported.entities.map(entity => {
    if (!importedTypes.includes(entity.type) || entity.paper) return entity;
    const moved = C.transform(entity, {dx:7,dy:-3});
    if (entity.type==='TEXT') moved.text = '수정된 정렬 문자';
    if (entity.type==='MTEXT') moved.text = '수정된 가운데 메모';
    return moved;
});
writeFileSync(path.resolve(directory, 'imported-2d-edited.dxf'), C.exportDXF(imported));
convert('imported-2d-edited.dxf', 'imported-2d-edited.dwg', 4);
convert('imported-2d-edited.dwg', 'imported-2d-verified.dxf', 4);
const importedVerified = C.parse(readFileSync(path.resolve(directory, 'imported-2d-verified.dxf'), 'utf8'));
for (const type of importedTypes) {
    const before = importedBefore[type], after = importedVerified.entities.find(entity=>entity.type===type&&!entity.paper);
    assert.equal(after.editable, true, `${type} stays editable after native re-save`);
    assert.equal(rawNumber(after, type==='LWPOLYLINE'?38:30), 12.5, `${type} retains elevation`);
    // Native writers may recompute aligned TEXT's first point after content
    // changes; the second alignment point is its stable positional anchor.
    const xCode=type==='TEXT'?11:10, yCode=type==='TEXT'?21:20;
    assert.ok(Math.abs(rawNumber(after,xCode)-rawNumber(before,xCode)-7)<1e-8, `${type} moves X`);
    assert.ok(Math.abs(rawNumber(after,yCode)-rawNumber(before,yCode)+3)<1e-8, `${type} moves Y`);
}
const importedPoly = importedVerified.entities.find(entity=>entity.type==='LWPOLYLINE');
assert.equal(rawNumber(importedPoly,70),129,'Closed and line-generation flags survive');
assert.equal(importedPoly.points[0].bulge,0.5,'Arc shape survives native save');
assert.deepEqual(importedPoly.raw.filter(pair=>pair[0]===40||pair[0]===41).map(pair=>Number(pair[1])),
    importedBefore.LWPOLYLINE.raw.filter(pair=>pair[0]===40||pair[0]===41).map(pair=>Number(pair[1])), 'Variable widths survive');
const importedText = importedVerified.entities.find(entity=>entity.type==='TEXT');
assert.equal(rawNumber(importedText,72),1);
assert.equal(importedText.text,'수정된 정렬 문자');
const importedNote = importedVerified.entities.find(entity=>entity.type==='MTEXT');
assert.equal(rawNumber(importedNote,71),5);
assert.equal(importedNote.text,'수정된 가운데 메모');
console.log('PASS: imported elevated 2D line, bulged/variable-width polyline and aligned text retain geometry and metadata through edit/DWG/reopen.');

importedVerified.entities=importedVerified.entities.map(entity=>entity.id===importedPoly.id?C.transform(entity,{scale:2,mirror:'y'}):entity);
writeFileSync(path.resolve(directory,'polyline-2d-scaled.dxf'),C.exportDXF(importedVerified));
convert('polyline-2d-scaled.dxf','polyline-2d-scaled.dwg',4);
convert('polyline-2d-scaled.dwg','polyline-2d-scaled-verified.dxf',4);
const scaledPoly=C.parse(readFileSync(path.resolve(directory,'polyline-2d-scaled-verified.dxf'),'utf8')).entities.find(entity=>entity.type==='LWPOLYLINE');
assert.equal(scaledPoly.points[0].bulge,-0.5,'Mirroring reverses the curve sweep');
assert.equal(rawNumber(scaledPoly,38),12.5,'XY scaling leaves elevation intact');
assert.equal(rawNumber(scaledPoly,70),129);
for(let i=0;i<importedPoly.points.length;i++){
    assert.equal(scaledPoly.points[i].x,-2*importedPoly.points[i].x);
    assert.equal(scaledPoly.points[i].y,2*importedPoly.points[i].y);
}
assert.deepEqual(scaledPoly.raw.filter(pair=>pair[0]===40||pair[0]===41).map(pair=>Number(pair[1])),
    importedPoly.raw.filter(pair=>pair[0]===40||pair[0]===41).map(pair=>2*Number(pair[1])),'Polyline widths scale with geometry');
console.log('PASS: native 2D polyline scaling/mirroring preserves width ratios, sweep, flags and elevation.');

vm.runInContext(readFileSync('public/js/cad-fidelity.js','utf8'), context);
const blockRecords = [
    [0,'SECTION'],[2,'BLOCKS'],
    [0,'BLOCK'],[5,'F200'],[100,'AcDbEntity'],[8,'0'],[100,'AcDbBlockBegin'],
    [2,'PART_2D'],[70,0],[10,0],[20,0],[30,0],[3,'PART_2D'],[1,''],
    [0,'LINE'],[5,'F201'],[100,'AcDbEntity'],[8,'0'],[100,'AcDbLine'],
    [10,0],[20,0],[30,0],[11,15],[21,10],[31,0],
    [0,'ENDBLK'],[5,'F202'],[100,'AcDbEntity'],[8,'0'],[100,'AcDbBlockEnd'],[0,'ENDSEC'],
];
const insertionRecords = [
    [0,'INSERT'],[5,'F203'],[100,'AcDbEntity'],[8,'0'],[100,'AcDbBlockReference'],
    [2,'PART_2D'],[10,20],[20,30],[30,12.5],[41,1],[42,1],[43,1],[50,0],
];
const blockSource = base2D.prefix.slice(0,-2).concat(blockRecords,base2D.prefix.slice(-2),insertionRecords,base2D.suffix)
    .map(([code,value])=>`${code}\n${value}\n`).join('');
writeFileSync(path.resolve(directory,'block-2d.dxf'),blockSource);
convert('block-2d.dxf','block-2d.dwg',1);
convert('block-2d.dwg','block-2d-reopened.dxf',1);
const blockDoc=C.parse(readFileSync(path.resolve(directory,'block-2d-reopened.dxf'),'utf8'));
const inserted=blockDoc.entities.find(entity=>entity.type==='INSERT');
assert.equal(inserted.editable,true,'Simple native 2D block can move');
const blockMoved=C.transform(inserted,{dx:7,dy:-3});
const blockCopies=C.array([blockMoved],1,2,50,0);
blockDoc.entities=blockDoc.entities.map(entity=>entity.id===inserted.id?blockMoved:entity).concat(blockCopies);
writeFileSync(path.resolve(directory,'block-2d-edited.dxf'),C.exportDXF(blockDoc));
convert('block-2d-edited.dxf','block-2d-edited.dwg',2);
convert('block-2d-edited.dwg','block-2d-verified.dxf',2);
const blockVerified=C.parse(readFileSync(path.resolve(directory,'block-2d-verified.dxf'),'utf8'));
const nativeBlocks=blockVerified.entities.filter(entity=>entity.type==='INSERT').sort((a,b)=>a.p.x-b.p.x);
assert.equal(nativeBlocks.length,2);
assert.deepEqual(Array.from(nativeBlocks,entity=>[entity.p.x,entity.p.y,rawNumber(entity,30)]),[[27,27,12.5],[77,27,12.5]]);
assert.equal(new Set(nativeBlocks.map(entity=>entity.raw.find(pair=>pair[0]===5)[1])).size,2,'Copied INSERT gets a unique handle');
for(const entity of nativeBlocks){
    assert.equal(entity.editable,true);
    const bounds=C.bounds([entity]);
    assert.equal(bounds.minX,entity.p.x);assert.equal(bounds.maxX,entity.p.x+15);
    assert.equal(bounds.minY,entity.p.y);assert.equal(bounds.maxY,entity.p.y+10);
}
console.log('PASS: ordinary 2D block move/copy preserves its definition, elevation and unique handles through actual DWG re-save.');
