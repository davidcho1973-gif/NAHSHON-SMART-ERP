/* Read-only presentation of native DXF. Raw entity/block records remain the export source.
 * Group-code references: Autodesk DXF SPLINE, HATCH Boundary Path Data, DIMENSION and INSERT.
 */
(function (root) {
    "use strict";
    const value = (r, c, d = "") => r.find((p) => p[0] === c)?.[1] ?? d,
        number = (r, c, d = 0) => Number(value(r, c, d));
    const point = (r, c = 10) => ({ x: number(r, c), y: number(r, c + 10) }),
        all = (r, c) => r.filter((p) => p[0] === c).map((p) => Number(p[1]));
    function vertices(r, code = 10) {
        const out = [];
        for (let i = 0; i < r.length; i++)
            if (r[i][0] === code) {
                let end = i + 1;
                while (end < r.length && r[end][0] !== code) end++;
                const part = r.slice(i, end);
                out.push({
                    x: Number(r[i][1]),
                    y: number(part, code + 10),
                    bulge: number(part, 42),
                });
            }
        return out;
    }
    const finite = (ps) =>
        ps.every(
            (p) =>
                Number.isFinite(p.x) &&
                Number.isFinite(p.y) &&
                Math.abs(p.x) <= 1e12 &&
                Math.abs(p.y) <= 1e12,
        );
    function polyline(points, closed) {
        const out = [];
        for (let i = 0; i < points.length - (closed ? 0 : 1); i++) {
            const a = points[i],
                b = points[(i + 1) % points.length],
                bulge = a.bulge || 0,
                dx = b.x - a.x,
                dy = b.y - a.y;
            out.push({ x: a.x, y: a.y });
            if (!bulge || Math.hypot(dx, dy) < 1e-10) continue;
            const cx =
                    (a.x + b.x) / 2 - (dy * (1 - bulge * bulge)) / (4 * bulge),
                cy = (a.y + b.y) / 2 + (dx * (1 - bulge * bulge)) / (4 * bulge),
                radius = Math.hypot(a.x - cx, a.y - cy),
                start = Math.atan2(a.y - cy, a.x - cx),
                sweep = 4 * Math.atan(bulge),
                n = Math.min(256, Math.max(8, Math.ceil(Math.abs(sweep) * 32)));
            for (let j = 1; j < n; j++) {
                const t = start + (sweep * j) / n;
                out.push({
                    x: cx + radius * Math.cos(t),
                    y: cy + radius * Math.sin(t),
                });
            }
        }
        if (points.length)
            out.push({ ...points[closed ? 0 : points.length - 1] });
        return out;
    }
    function spline(degree, knots, controls, weights = []) {
        const n = controls.length;
        if (
            !Number.isInteger(degree) ||
            degree < 1 ||
            degree > 10 ||
            n <= degree ||
            n > 2048 ||
            knots.length !== n + degree + 1 ||
            !finite(controls) ||
            knots.some(
                (k, i) => !Number.isFinite(k) || (i && k < knots[i - 1]),
            ) ||
            !(knots[n] > knots[degree]) ||
            (weights.length && weights.length !== n) ||
            weights.some((w) => !Number.isFinite(w) || w <= 0)
        )
            throw Error("spline_definition");
        const evaluate = (t) => {
            let span = n - 1;
            if (t < knots[n]) {
                span = degree;
                while (span < n - 1 && t >= knots[span + 1]) span++;
            }
            const d = Array.from({ length: degree + 1 }, (_, j) => {
                const i = span - degree + j,
                    w = weights[i] ?? 1;
                return [controls[i].x * w, controls[i].y * w, w];
            });
            for (let r = 1; r <= degree; r++)
                for (let j = degree; j >= r; j--) {
                    const i = span - degree + j,
                        den = knots[i + degree - r + 1] - knots[i],
                        alpha = den ? (t - knots[i]) / den : 0;
                    for (let k = 0; k < 3; k++)
                        d[j][k] = (1 - alpha) * d[j - 1][k] + alpha * d[j][k];
                }
            return {
                x: d[degree][0] / d[degree][2],
                y: d[degree][1] / d[degree][2],
            };
        };
        const xs = controls.map((p) => p.x),
            ys = controls.map((p) => p.y),
            tolerance = Math.max(
                1e-8,
                Math.hypot(
                    Math.max(...xs) - Math.min(...xs),
                    Math.max(...ys) - Math.min(...ys),
                ) * 1e-6,
            ),
            out = [];
        const subdivide = (a, b, pa, pb, depth) => {
            const t = (a + b) / 2,
                p = evaluate(t);
            if (
                depth < 10 &&
                out.length < 8190 &&
                root.CADCore.segmentDistance(p, pa, pb) > tolerance
            ) {
                subdivide(a, t, pa, p, depth + 1);
                subdivide(t, b, p, pb, depth + 1);
            } else out.push(pb);
        };
        for (let k = degree; k < n; k++)
            if (knots[k + 1] > knots[k])
                for (let j = 0; j < 8; j++) {
                    const a = knots[k] + ((knots[k + 1] - knots[k]) * j) / 8,
                        b =
                            knots[k] +
                            ((knots[k + 1] - knots[k]) * (j + 1)) / 8,
                        pa = evaluate(a),
                        pb = evaluate(b);
                    if (!out.length) out.push(pa);
                    subdivide(a, b, pa, pb, 0);
                    if (out.length > 8192) throw Error("curve_complexity");
                }
        if (!finite(out)) throw Error("spline_coordinates");
        return out;
    }
    function arc(center, rx, ry, axis, start, end, ccw = true) {
        if (ccw) while (end <= start) end += Math.PI * 2;
        else while (end >= start) end -= Math.PI * 2;
        const n = Math.min(
                512,
                Math.max(8, Math.ceil(Math.abs(end - start) * 32)),
            ),
            c = Math.cos(axis),
            s = Math.sin(axis);
        return Array.from({ length: n + 1 }, (_, i) => {
            const t = start + ((end - start) * i) / n,
                x = rx * Math.cos(t),
                y = ry * Math.sin(t);
            return { x: center.x + x * c - y * s, y: center.y + x * s + y * c };
        });
    }
    function hatch(raw) {
        const xdata = raw.findIndex((p) => p[0] === 1001),
            r = xdata < 0 ? raw : raw.slice(0, xdata);
        let cursor = r.findIndex((p) => p[0] === 91);
        if (cursor < 0) throw Error("hatch_boundary");
        const count = Number(r[cursor++][1]),
            rings = [],
            flags = [];
        const take = (code, optional = false, fallback = 0) => {
            if (r[cursor]?.[0] !== code) {
                if (optional) return fallback;
                throw Error("hatch_boundary");
            }
            return Number(r[cursor++][1]);
        };
        const p = (code = 10) => ({ x: take(code), y: take(code + 10) });
        if (!Number.isInteger(count) || count < 1 || count > 256)
            throw Error("hatch_complexity");
        for (let loop = 0; loop < count; loop++) {
            const flag = take(92);
            flags.push(flag);
            let ring = [];
            if (flag & 2) {
                const bulged = take(72),
                    closed = take(73),
                    n = take(93),
                    ps = [];
                if (!closed || n < 3 || n > 2048) throw Error("hatch_boundary");
                for (let j = 0; j < n; j++) {
                    const q = p();
                    q.bulge = bulged ? take(42, true) : 0;
                    ps.push(q);
                }
                ring = polyline(ps, true);
            } else {
                const edges = take(93);
                if (edges < 1 || edges > 2048) throw Error("hatch_complexity");
                for (let j = 0; j < edges; j++) {
                    const kind = take(72);
                    let path;
                    if (kind === 1) path = [p(), p(11)];
                    else if (kind === 2) {
                        const center = p(),
                            radius = take(40),
                            start = (take(50) * Math.PI) / 180,
                            end = (take(51) * Math.PI) / 180,
                            ccw = !!take(73);
                        path = arc(center, radius, radius, 0, start, end, ccw);
                    } else if (kind === 3) {
                        const center = p(),
                            major = p(11),
                            ratio = take(40),
                            start = (take(50) * Math.PI) / 180,
                            end = (take(51) * Math.PI) / 180,
                            ccw = !!take(73),
                            radius = Math.hypot(major.x, major.y);
                        path = arc(
                            center,
                            radius,
                            radius * ratio,
                            Math.atan2(major.y, major.x),
                            start,
                            end,
                            ccw,
                        );
                    } else if (kind === 4) {
                        const degree = take(94),
                            rational = take(73);
                        take(74);
                        const nk = take(95),
                            nc = take(96);
                        if (nk > 4096 || nc > 2048)
                            throw Error("hatch_complexity");
                        const knots = Array.from({ length: nk }, () =>
                                take(40),
                            ),
                            controls = [],
                            weights = [];
                        for (let k = 0; k < nc; k++) {
                            controls.push(p());
                            if (rational) weights.push(take(42, true, 1));
                        }
                        const fits = take(97, true);
                        if (fits > 2048) throw Error("hatch_complexity");
                        for (let k = 0; k < fits; k++) p(11);
                        if (r[cursor]?.[0] === 12) p(12);
                        if (r[cursor]?.[0] === 13) p(13);
                        path = spline(degree, knots, controls, weights);
                    } else throw Error("hatch_edge");
                    if (
                        ring.length &&
                        root.CADCore.distance(ring.at(-1), path[0]) > 1e-5
                    )
                        throw Error("hatch_disconnected");
                    ring.push(...path.slice(ring.length ? 1 : 0));
                }
                if (root.CADCore.distance(ring[0], ring.at(-1)) > 1e-5)
                    throw Error("hatch_open");
            }
            if (
                !finite(ring) ||
                ring.length > 16000 ||
                rings.reduce((n, r) => n + r.length, 0) + ring.length > 50000
            )
                throw Error("hatch_complexity");
            rings.push(ring);
            const sources = take(97, true);
            if (sources > 4096) throw Error("hatch_complexity");
            for (let j = 0; j < sources; j++) take(330);
        }
        const patterns = [];
        for (let i = cursor; i < r.length; i++)
            if (r[i][0] === 53) {
                let end = i + 1;
                while (end < r.length && ![53, 98, 450].includes(r[end][0]))
                    end++;
                const data = r.slice(i, end);
                patterns.push({
                    angle: (number(data, 53) * Math.PI) / 180,
                    base: { x: number(data, 43), y: number(data, 44) },
                    offset: { x: number(data, 45), y: number(data, 46) },
                    dashes: all(data, 49),
                });
                i = end - 1;
            }
        return {
            rings,
            flags,
            solid: !!number(r, 70),
            style: number(r, 75),
            gradient: !!number(r, 450),
            patterns,
        };
    }
    function displayText(text) {
        return String(text)
            .replace(/\\U\+([a-f0-9]{4})/gi, (_, h) =>
                String.fromCharCode(parseInt(h, 16)),
            )
            .replace(/%%d/gi, "°")
            .replace(/%%p/gi, "±")
            .replace(/%%c/gi, "Ø")
            .replace(/\\P/g, "\n")
            .replace(/\\~/g, " ")
            .replace(/\\[ACcFfHhQqTtWw][^;]*;/g, "")
            .replace(/\\S([^;]*);/g, (_, fraction) =>
                fraction.replace(/[\^#]/g, "/"),
            )
            .replace(/\\[LlOoKk]/g, "")
            .replace(/[{}]/g, "");
    }
    function baseEntity(raw) {
        const type = raw[0][1],
            e = {
                type,
                raw,
                p: point(raw),
                layer: value(raw, 8, "0"),
                color: raw.some((p) => p[0] === 420) ? number(raw, 420) : null,
                aci: number(raw, 62, 256),
                lineType: value(raw, 6, "BYLAYER"),
                lineWeight: number(raw, 370, -1),
                hidden: !!number(raw, 60) || number(raw, 62, 256) < 0,
            };
        if (type === "LINE") e.q = point(raw, 11);
        if (["ARC", "CIRCLE"].includes(type)) {
            e.radius = number(raw, 40);
            e.start = number(raw, 50);
            e.end = number(raw, 51);
        }
        if (type === "ELLIPSE") {
            e.major = point(raw, 11);
            e.ratio = number(raw, 40, 1);
            e.startParam = number(raw, 41);
            e.endParam = number(raw, 42, Math.PI * 2);
        }
        if (type === "LWPOLYLINE") {
            e.points = vertices(raw);
            e.closed = !!(number(raw, 70) & 1);
        }
        if (["TEXT", "MTEXT", "ATTRIB", "ATTDEF"].includes(type)) {
            e.text = raw
                .filter((p) => [1, 3].includes(p[0]))
                .map((p) => p[1])
                .join("");
            e.height = number(raw, 40, 2.5);
            e.width = type === "MTEXT" ? number(raw, 41) : 0;
            e.angle =
                type === "MTEXT"
                    ? ((raw.some((p) => p[0] === 11)
                          ? Math.atan2(number(raw, 21), number(raw, 11))
                          : number(raw, 50)) *
                          180) /
                      Math.PI
                    : number(raw, 50);
        }
        return e;
    }
    function decorate(e) {
        const r = e.raw || [];
        e.presentationWarnings = [];
        if (e.type === "SPLINE")
            e.displayGeometry = spline(
                number(r, 71),
                all(r, 40),
                vertices(r),
                all(r, 41),
            );
        if (
            ["LWPOLYLINE", "POLYLINE"].includes(e.type) &&
            e.points?.some((p) => p.bulge)
        )
            e.displayGeometry = polyline(e.points, e.closed);
        if (e.type === "HATCH") {
            e.hatch = hatch(r);
            e.displayGeometry = e.hatch.rings.flat();
            if (e.hatch.gradient)
                e.presentationWarnings.push("gradient_outline");
            if (!e.hatch.solid && !e.hatch.patterns.length)
                e.presentationWarnings.push("hatch_pattern_missing");
        }
        if (["SOLID", "TRACE", "3DFACE"].includes(e.type)) {
            const points = [
                point(r, 10),
                point(r, 11),
                point(r, 13),
                point(r, 12),
            ];
            e.displayGeometry = [...points, points[0]];
            e.solid = e.type !== "3DFACE";
        }
        if (["TEXT", "MTEXT", "ATTRIB", "ATTDEF"].includes(e.type)) {
            e.displayText = displayText(e.text);
            e.fontStyle = value(r, 7, "STANDARD");
            if (e.type !== "MTEXT") {
                e.textAlign =
                    number(r, 72) === 1
                        ? "center"
                        : number(r, 72) === 2
                          ? "right"
                          : "left";
                e.textBaseline =
                    ["alphabetic", "bottom", "middle", "top"][number(r, 73)] ||
                    "alphabetic";
                if (
                    (number(r, 72) || number(r, 73)) &&
                    r.some((p) => p[0] === 11)
                )
                    e.textAnchor = point(r, 11);
                e.widthFactor = number(r, 41, 1);
                e.oblique = (number(r, 51) * Math.PI) / 180;
                if (number(r, 72) >= 3)
                    e.presentationWarnings.push("text_fit_alignment");
            } else {
                const attach = number(r, 71, 1);
                e.textAlign =
                    ["left", "center", "right"][(attach - 1) % 3] || "left";
                e.textBaseline =
                    attach <= 3 ? "top" : attach <= 6 ? "middle" : "bottom";
                if (e.displayText !== e.text)
                    e.presentationWarnings.push("mtext_format_substitution");
            }
            if (["ATTRIB", "ATTDEF"].includes(e.type)) {
                const part = { ...e, type: "TEXT" };
                delete part.raw;
                e.renderParts = [part];
            }
        }
        if (number(r, 210) || number(r, 220))
            e.presentationWarnings.push("nonplanar_projection");
        if (
            number(r, 230, 1) < 0 &&
            [
                "HATCH",
                "LWPOLYLINE",
                "POLYLINE",
                "CIRCLE",
                "ARC",
                "SOLID",
                "TRACE",
            ].includes(e.type)
        ) {
            if (!e.displayGeometry)
                e.displayGeometry = root.CADCore.geometry(e);
            e.displayGeometry = e.displayGeometry.map((p) => ({
                x: -p.x,
                y: p.y,
            }));
            if (e.hatch) {
                e.hatch.rings = e.hatch.rings.map((ring) =>
                    ring.map((p) => ({ x: -p.x, y: p.y })),
                );
                e.hatch.patterns = e.hatch.patterns.map((p) => ({
                    ...p,
                    angle: Math.PI - p.angle,
                    base: { x: -p.base.x, y: p.base.y },
                    offset: { x: -p.offset.x, y: p.offset.y },
                }));
            }
        }
        return e;
    }
    function enhance(doc, pairs) {
        const blocks = new Map(),
            styles = new Map(),
            warnings = [];
        let generated = 0, placedCount = 0, placedPoints = 0;
        const expandedBlocks = new Map();
        for (let i = 0; i < pairs.length; i++) {
            if (pairs[i][0] === 0 && pairs[i][1] === "STYLE") {
                let end = i + 1;
                while (end < pairs.length && pairs[end][0] !== 0) end++;
                const r = pairs.slice(i, end);
                styles.set(value(r, 2), value(r, 3));
            }
            if (pairs[i][0] !== 0 || pairs[i][1] !== "BLOCK") continue;
            let end = i + 1;
            while (end < pairs.length && pairs[end][0] !== 0) end++;
            const header = pairs.slice(i, end),
                records = [];
            i = end;
            while (
                i < pairs.length &&
                !(pairs[i][0] === 0 && pairs[i][1] === "ENDBLK")
            ) {
                end = i + 1;
                while (end < pairs.length && pairs[end][0] !== 0) end++;
                records.push(pairs.slice(i, end));
                i = end;
            }
            blocks.set(value(header, 2), { base: point(header), records });
        }
        function expand(name, stack = []) {
            if (stack.includes(name) || stack.length > 8)
                throw Error("block_recursion");
            if (expandedBlocks.has(name)) return expandedBlocks.get(name);
            const block = blocks.get(name);
            if (!block) throw Error("external_or_missing_block");
            const parts = [];
            for (let i = 0; i < block.records.length; i++) {
                const raw = block.records[i],
                    e = baseEntity(raw);
                if (e.hidden || ["SEQEND", "VERTEX", "ATTDEF"].includes(e.type))
                    continue;
                if (e.type === "POLYLINE") {
                    e.points = [];
                    while (block.records[i + 1]?.[0][1] === "VERTEX")
                        e.points.push({
                            ...point(block.records[++i]),
                            bulge: number(block.records[i], 42),
                        });
                    e.closed = !!(number(raw, 70) & 1);
                }
                if (e.type === "INSERT") {
                    const nested = placed(e, expand(value(raw, 2), [...stack, name]), blocks.get(value(raw, 2))?.base);
                    if (parts.length + nested.length > 50000) throw Error("block_complexity");
                    for (const part of nested) parts.push(part);
                    continue;
                }
                try {
                    decorate(e);
                    if (root.CADCore.geometry(e).length) parts.push(e);
                    else
                        warnings.push({
                            type: e.type,
                            reason: "block_entity_unavailable",
                        });
                } catch (error) {
                    warnings.push({ type: e.type, reason: error.message });
                }
                if (++generated > 50000) throw Error("block_complexity");
            }
            expandedBlocks.set(name, parts);
            return parts;
        }
        function placed(insert, parts, base = { x: 0, y: 0 }) {
            const r = insert.raw,
                angle = (number(r, 50) * Math.PI) / 180,
                sx = number(r, 41, 1),
                sy = number(r, 42, 1),
                c = Math.cos(angle),
                s = Math.sin(angle);
            if (![sx, sy, angle].every(Number.isFinite) || sx === 0 || sy === 0)
                throw Error("block_transform");
            const transform = (p) => {
                const x = (p.x - base.x) * sx,
                    y = (p.y - base.y) * sy;
                return {
                    x: insert.p.x + x * c - y * s,
                    y: insert.p.y + x * s + y * c,
                };
            };
            // Cache definitions, but bound materialized instance parts and points.
            let pointCount = 0;
            for (const part of parts) pointCount += root.CADCore.geometry(part).length;
            if (placedCount + parts.length > 200000 || placedPoints + pointCount > 2000000)
                throw Error("block_instance_complexity");
            placedCount += parts.length;
            placedPoints += pointCount;
            return parts.map((part) => {
                const e = { ...part };
                e.sourcePlanarXY = root.CADCore.planarXY ? root.CADCore.planarXY(part) : part.editable;
                e.blockColorInherited = part.aci === 0 && part.color === null;
                e.blockLayerInherited = part.layer === "0";
                delete e.raw;
                e.layer = part.layer === "0" ? insert.layer : part.layer;
                if (part.aci === 0 && part.color === null) {
                    e.aci = insert.aci;
                    e.color = insert.color;
                }
                if (["TEXT", "MTEXT"].includes(e.type)) {
                    e.p = transform(e.p);
                    if (e.textAnchor) e.textAnchor = transform(e.textAnchor);
                    e.height *= Math.abs(sy);
                    e.width *= Math.abs(sx);
                    e.widthFactor = (e.widthFactor || 1) * Math.abs(sx / sy);
                    e.angle = (e.angle || 0) + (angle * 180) / Math.PI;
                    if (sx * sy < 0)
                        e.presentationWarnings = [
                            ...(e.presentationWarnings || []),
                            "mirrored_text_substitution",
                        ];
                } else {
                    e.displayGeometry =
                        root.CADCore.geometry(part).map(transform);
                    if (e.hatch)
                        e.hatch = {
                            ...e.hatch,
                            rings: e.hatch.rings.map((ring) =>
                                ring.map(transform),
                            ),
                            patterns: e.hatch.patterns.map((p) => {
                                const b = transform(p.base),
                                    o = transform({
                                        x: p.base.x + p.offset.x,
                                        y: p.base.y + p.offset.y,
                                    }),
                                    u = transform({
                                        x: p.base.x + Math.cos(p.angle),
                                        y: p.base.y + Math.sin(p.angle),
                                    });
                                return {
                                    ...p,
                                    base: b,
                                    offset: { x: o.x - b.x, y: o.y - b.y },
                                    angle: Math.atan2(u.y - b.y, u.x - b.x),
                                    dashes: p.dashes.map(
                                        (n) =>
                                            n *
                                            Math.hypot(u.x - b.x, u.y - b.y),
                                    ),
                                };
                            }),
                        };
                }
                return e;
            });
        }
        for (const e of doc.entities) {
            try {
                if (e.type === "POLYLINE" && e.points) {
                    const at = doc.entities.indexOf(e);
                    for (let i = 0; i < e.points.length; i++)
                        e.points[i].bulge = number(
                            doc.entities[at + i + 1]?.raw || [],
                            42,
                        );
                }
                decorate(e);
                if (["INSERT", "DIMENSION"].includes(e.type)) {
                    const name = value(e.raw, 2),
                        parts = expand(name);
                    e.renderParts =
                        e.type === "INSERT"
                            ? placed(e, parts, blocks.get(name)?.base)
                            : parts;
                    e.preview = e.renderParts.map((part) =>
                        root.CADCore.geometry(part),
                    );
                    e.displayGeometry = e.preview.flat();
                    e.blockName = name;
                    for (const part of e.renderParts)
                        for (const reason of part.presentationWarnings || [])
                            warnings.push({ type: part.type, reason });
                }
            } catch (error) {
                if (["INSERT", "DIMENSION"].includes(e.type)) {
                    // Do not present an incomplete fallback as the complete block.
                    e.renderParts = [];
                    e.preview = [];
                    e.displayGeometry = [];
                }
                e.presentationWarnings = [
                    ...(e.presentationWarnings || []),
                    error.message,
                ];
            }
            if (e.fontStyle && styles.get(e.fontStyle))
                e.presentationWarnings.push("font_substitution");
            for (const reason of e.presentationWarnings || [])
                warnings.push({ type: e.type, reason });
        }
        doc.presentation = {
            warnings,
            unavailable: doc.entities
                .filter(
                    (e) =>
                        !e.paper &&
                        !e.hidden &&
                        doc.layers[e.layer]?.visible !== false &&
                        !root.CADCore.geometry(e).length,
                )
                .map((e) => ({ id: e.id, type: e.type })),
            fontFiles: [...new Set(styles.values())].filter(Boolean),
        };
        return doc;
    }
    function drawHatch(ctx, e, screen, scale, options = {}) {
        const h = e.hatch;
        const inside = (p, ring) => {
            let result = false;
            for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
                const a = ring[i],
                    b = ring[j];
                if (
                    a.y > p.y !== b.y > p.y &&
                    p.x < ((b.x - a.x) * (p.y - a.y)) / (b.y - a.y) + a.x
                )
                    result = !result;
            }
            return result;
        };
        const rings = h.rings.filter((ring, i) => {
            const depth = h.rings.filter(
                (other, j) => i !== j && inside(ring[0], other),
            ).length;
            return h.style === 2
                ? depth === 0
                : h.style === 1
                  ? depth <= 1
                  : true;
        });
        ctx.save();
        ctx.beginPath();
        for (const ring of rings) {
            ring.map(screen).forEach((p, i) =>
                i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y),
            );
            ctx.closePath();
        }
        if (h.gradient || (!h.solid && !h.patterns.length)) {
            ctx.stroke();
            ctx.restore();
            return;
        }
        if (h.solid) {
            ctx.fill("evenodd");
            ctx.restore();
            return;
        }
        ctx.clip("evenodd");
        const pts = rings.flat().map(screen),
            left = Math.max(0, Math.min(...pts.map((p) => p.x))),
            right = Math.min(
                ctx.canvas.width,
                Math.max(...pts.map((p) => p.x)),
            ),
            top = Math.max(0, Math.min(...pts.map((p) => p.y))),
            bottom = Math.min(
                ctx.canvas.height,
                Math.max(...pts.map((p) => p.y)),
            );
        if (right <= left || bottom <= top) {
            ctx.restore();
            return;
        }
        let budget = 5000;
        for (const pattern of h.patterns) {
            const b = screen(pattern.base),
                other = screen({
                    x: pattern.base.x + pattern.offset.x,
                    y: pattern.base.y + pattern.offset.y,
                }),
                direction = screen({
                    x: pattern.base.x + Math.cos(pattern.angle),
                    y: pattern.base.y + Math.sin(pattern.angle),
                }),
                directionLength = Math.hypot(
                    direction.x - b.x,
                    direction.y - b.y,
                ),
                u = {
                    x: (direction.x - b.x) / directionLength,
                    y: (direction.y - b.y) / directionLength,
                },
                n = { x: -u.y, y: u.x },
                off = { x: other.x - b.x, y: other.y - b.y },
                step = off.x * n.x + off.y * n.y;
            if (Math.abs(step) < 1e-9) continue;
            const corners = [
                    { x: left, y: top },
                    { x: right, y: top },
                    { x: right, y: bottom },
                    { x: left, y: bottom },
                ],
                project = corners.map(
                    (p) => ((p.x - b.x) * n.x + (p.y - b.y) * n.y) / step,
                ),
                first = Math.floor(Math.min(...project)) - 1,
                last = Math.ceil(Math.max(...project)) + 1;
            // Extremely dense patterns become a bounded light tone; do not hang on zoom-out.
            if (last - first > budget) {
                ctx.save();
                ctx.globalAlpha *= 0.25;
                ctx.fillRect(left, top, right - left, bottom - top);
                ctx.restore();
                continue;
            }
            for (let index = first; index <= last; index++) {
                if (--budget < 0) break;
                const origin = {
                        x: b.x + off.x * index,
                        y: b.y + off.y * index,
                    },
                    along = corners.map(
                        (p) => (p.x - origin.x) * u.x + (p.y - origin.y) * u.y,
                    ),
                    start = Math.min(...along) - 1,
                    end = Math.max(...along) + 1;
                const spans = dashSpans(
                    pattern.dashes.map((d) => d * scale),
                    start,
                    end,
                    10000,
                );
                ctx.setLineDash([]);
                ctx.beginPath();
                for (const [a, b] of spans) {
                    ctx.moveTo(origin.x + u.x * a, origin.y + u.y * a);
                    ctx.lineTo(origin.x + u.x * b, origin.y + u.y * b);
                }
                ctx.stroke();
            }
        }
        ctx.restore();
    }
    // DXF signs encode ink/gap; adjacent positive entries are both ink, not Canvas alternation.
    function dashSpans(dashes, start, end, limit = 10000) {
        if (!dashes.length) return [[start, end]];
        const lengths = dashes.map((d) => Math.max(Math.abs(d), 0.1)),
            period = lengths.reduce((a, b) => a + b, 0);
        if (!Number.isFinite(period) || period <= 0) return [];
        if (((end - start) / period) * dashes.length > limit)
            return [[start, end]];
        const out = [];
        for (
            let cycle = Math.floor(start / period);
            cycle * period < end;
            cycle++
        ) {
            let at = cycle * period;
            for (let i = 0; i < dashes.length; i++) {
                const next = at + lengths[i];
                if (dashes[i] >= 0 && next > start && at < end)
                    out.push([
                        Math.max(start, at),
                        Math.min(end, dashes[i] === 0 ? at + 0.1 : next),
                    ]);
                at = next;
            }
        }
        return out;
    }
    root.CADFidelity = {
        enhance,
        spline,
        polyline,
        hatch,
        displayText,
        drawHatch,
        dashSpans,
    };
})(typeof window === "undefined" ? globalThis : window);
