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
    annotations.entities.push(C.newDimension({x:10,y:20}, {x:40,y:60}, {x:-15,y:90}, {mode,height:3,color:0x22bb88,layer:annotationLayer}));
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
