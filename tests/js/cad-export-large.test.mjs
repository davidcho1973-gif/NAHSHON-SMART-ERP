import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import { createHash } from "node:crypto";
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(
    fs.readFileSync(
        new URL("../../public/js/cad-core.js", import.meta.url),
        "utf8",
    ),
    sandbox,
);
const C = sandbox.CADCore;
const base =
    "0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nTABLES\n0\nTABLE\n2\nLAYER\n70\n1\n0\nLAYER\n5\nA\n2\n0\n70\n0\n62\n7\n6\nCONTINUOUS\n0\nENDTAB\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nLINE\n5\nB\n8\n0\n10\n1\n20\n2\n11\n3\n21\n4\n0\nENDSEC\n0\nEOF\n";
// Above common JavaScript argument-count limits, with distinct values/order.
const largeRecords = () =>
    Array.from({ length: 160000 }, (_, i) => [999, "opaque record " + i]);
function digest(records) {
    const hash = createHash("sha256");
    for (const [code, value] of records)
        hash.update(code + "\n" + value + "\n");
    return hash.digest("hex");
}
function addLine(doc) {
    doc.entities.push(
        C.newEntity("LINE", { p: { x: 10, y: 20 }, q: { x: 30, y: 40 } }, "0"),
    );
}
for (const location of [
    "unchanged entity",
    "changed entity",
    "layer table",
    "table header",
    "suffix",
]) {
    test(
        "large DXF " +
            location +
            " exports after editing without argument expansion or record loss",
        () => {
            const doc = C.parse(base),
                payload = largeRecords(),
                expected = digest(payload);
            if (location === "unchanged entity")
                doc.entities.push({
                    id: "opaque",
                    type: "ACAD_PROXY_ENTITY",
                    layer: "0",
                    editable: false,
                    p: { x: 0, y: 0 },
                    raw: [
                        [0, "ACAD_PROXY_ENTITY"],
                        [5, "C"],
                        [8, "0"],
                    ].concat(payload),
                });
            if (location === "changed entity") {
                doc.entities[0].raw = doc.entities[0].raw.concat(payload);
                doc.entities[0] = C.transform(doc.entities[0], {
                    dx: 5,
                    dy: 6,
                });
            }
            if (location === "layer table") {
                const at = doc.prefix.findIndex(
                    (pair) => pair[0] === 0 && pair[1] === "ENDTAB",
                );
                doc.prefix = doc.prefix
                    .slice(0, at)
                    .concat(payload, doc.prefix.slice(at));
            }
            if (location === "table header") {
                const at = doc.prefix.findIndex(
                    (pair) => pair[0] === 0 && pair[1] === "LAYER",
                );
                doc.prefix = doc.prefix
                    .slice(0, at)
                    .concat(payload, doc.prefix.slice(at));
            }
            if (location === "suffix")
                doc.suffix = doc.suffix.slice(0, -1).concat(
                    [
                        [0, "SECTION"],
                        [2, "OBJECTS"],
                        [0, "XRECORD"],
                        [5, "D"],
                    ],
                    payload,
                    [
                        [0, "ENDSEC"],
                        [0, "EOF"],
                    ],
                );
            addLine(doc);
            const original = digest(
                doc.prefix.concat(
                    doc.entities.flatMap((e) => e.raw || []),
                    doc.suffix,
                ),
            );
            const exported = C.exportDXF(doc),
                reopened = C.parse(exported);
            assert.equal(
                digest(
                    doc.prefix.concat(
                        doc.entities.flatMap((e) => e.raw || []),
                        doc.suffix,
                    ),
                ),
                original,
                "export must not mutate source records",
            );
            const records = ["layer table", "table header"].includes(location)
                ? reopened.prefix
                : location === "suffix"
                  ? reopened.suffix
                  : reopened.entities.find(
                        (e) =>
                            e.type ===
                            (location === "unchanged entity"
                                ? "ACAD_PROXY_ENTITY"
                                : "LINE"),
                    ).raw;
            const preserved = records.filter((pair) => pair[0] === 999);
            assert.equal(preserved.length, 160000);
            assert.equal(
                digest(preserved),
                expected,
                "opaque values and order must survive",
            );
            assert.ok(
                reopened.entities.some(
                    (e) => e.type === "LINE" && e.p.x === 10 && e.q.y === 40,
                ),
                "new geometry must survive",
            );
            if (location === "changed entity") {
                const line = reopened.entities.find(
                    (e) => e.type === "LINE" && e.p.x === 6,
                );
                assert.ok(line);
                assert.equal(line.p.y, 8);
                assert.equal(line.q.x, 8);
                assert.equal(line.q.y, 10);
            }
        },
    );
}
