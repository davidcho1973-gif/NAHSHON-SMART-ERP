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
function convert(from, to) {
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
    assert.equal(metadata.entities, 3);
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
