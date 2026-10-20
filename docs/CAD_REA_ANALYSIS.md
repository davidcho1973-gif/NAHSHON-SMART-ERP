# LibreCAD REA evidence and CAD integration boundary

Date: 2026-10-20. Scope: the user's installed LibreCAD executable, DWG import/export constraints, and useful editing interactions for NAHSHON ERP. No LibreCAD implementation was copied into ERP.

## Evidence ledger

| ID | Observation / conclusion | Evidence type and limits |
|---|---|---|
| REA-01 | `open_binary` recognized `C:\Program Files\LibreCAD\LibreCAD.exe` as PE x86_64; SHA-256 `9b5ec58c40c9e88b2fc110678f03c5b071191cd8dd79b3c68d6620b269c03773`. | REA shipped-artifact identity; open_binary returned no retained Evidence ID. No runtime functionality established. |
| `ev_221f58af12a54d15555aa3fb4e628d21c83c6b8d4ede9e6272e5d83e248065e5` | REA `inspect_pe_resources` confirmed the same digest, 7,681,536 bytes, PE32+, five resources (three icons, an icon group, a manifest). | Complete resource inventory, 13 entries examined. Most payloads opaque; no DWG or editor semantics established. |
| LOCAL-02 | Read-only ASCII extraction found `v2.2.1.5`, `LC_VISION=v2.2.1.5`, a DWG-support-incomplete warning, and DWG import diagnostic messages. | Local binary strings corroborate version candidate and import code presence; strings alone do not prove successful DWG loading. VersionInfo fields were blank. |
| LOCAL-03 | ASCII extraction found drawing/modify class markers for lines, circles, arcs, ellipses, move, mirror, rotate, scale, offset, trim, stretch, round, bevel, entity attributes, delete and explode text. | Static markers identify investigation seeds only; no individual command was executed or decompiled. |
| REA-LIMIT | Focused `search_strings(pattern: "DWG")` initiated Ghidra analysis but did not return within the investigation window; canceled rather than repeatedly restarting. | No native call graph or decompiled procedure evidence obtained. Do not describe this as completed native behavior reconstruction. |

## DWG findings

The [version-matched v2.2.1.5 filter source](https://raw.githubusercontent.com/LibreCAD/LibreCAD/v2.2.1.5/librecad/src/lib/filters/rs_filterdxfrw.cpp) has conditional DWG import at lines 158–174 (`dwgR.read`), while export at lines 1295–1363 selects DXF versions and calls `dxfRW.write`. This source supports treating the installed-version family as DWG import / DXF output, not a native DWG-save backend. Source/binary correspondence is corroborated by strings, not a reproducible build comparison.

The [current upstream master README](https://raw.githubusercontent.com/LibreCAD/LibreCAD/master/README.md), a prerelease branch, now documents `dwg2dxf` and `dxf2dwg`, gated by `DWGSUPPORT`; DWG targets include r2000 through r2018. Therefore “LibreCAD can never save DWG” would be inaccurate. New upstream functionality does not prove this installed v2.2.1.5 binary has it. No converter command was executed, and no sample DWG round trip was verified.

ERP must advertise actual configured converter capabilities. Preserve original uploads; write a separate revision; fail explicitly when a converter is missing or returns invalid output. A valid DWG header alone does not prove drawing fidelity. Validate final files in an independent CAD implementation using representative customer drawings before enabling production DWG export. Unsupported entities, xrefs, fonts, layouts, blocks and custom objects need explicit coverage rather than silent deletion.

## Editing interaction checklist

The [official drawing tools manual](https://docs.librecad.org/en/latest/ref/tools.html) supports these design references: geometric primitives; polyline editing; point, window and crossing selection; translation/copy, rotation, scale, mirror, trim and offset; dimensions and distance information. The [menu manual](https://docs.librecad.org/en/latest/ref/menu.html) adds undo/redo, clipboard operations, pan/zoom, grid and print/export. These are source/manual-supported product goals, not claims that the new ERP editor implements every LibreCAD tool.

Prioritize primitives and text, accurate numeric properties, grid/endpoint snapping, visible/locked layers, selection and transformations, undo/redo, fit/pan/zoom, dimension/measurement, safe import/export, and revision history. Advanced fillet/chamfer, splines, hatches, blocks and paper-space fidelity require dedicated implementations and round-trip fixtures; toolbar labels do not establish support.

## Checklist status

- Installed target identity: answered.
- Installed release DWG read/write boundary: partially answered by matching source and static strings; runtime sample verification remains open.
- Native internal function behavior: unresolved; Ghidra initialization did not finish.
- Editor design reference list: answered through official manuals, corroborated by binary markers.
- Production DWG round trip: unresolved; converter deployment and real customer sample required.
