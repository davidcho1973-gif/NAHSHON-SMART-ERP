/* Presentation/review operations never rewrite native DXF or approve ERP quantities. */
(function (root) {
    "use strict";
    const C = root.CADCore,
        M = root.CADMarkup,
        G = root.CADMarkupGeometry;
    const visible = (doc) =>
        doc.entities.filter(
            (e) =>
                !e.hidden && !e.paper && doc.layers[e.layer]?.visible !== false,
        );
    const bounds = (ps) =>
        ps.reduce(
            (b, p) => ({
                minX: Math.min(b.minX, p.x),
                minY: Math.min(b.minY, p.y),
                maxX: Math.max(b.maxX, p.x),
                maxY: Math.max(b.maxY, p.y),
            }),
            {
                minX: Infinity,
                minY: Infinity,
                maxX: -Infinity,
                maxY: -Infinity,
            },
        );
    const center = (b) => ({
        x: (b.minX + b.maxX) / 2,
        y: (b.minY + b.maxY) / 2,
    });
    const identity = { a: 1, b: 0, tx: 0, ty: 0 };
    function alignment(from, to) {
        if (from.length !== 2 || to.length !== 2)
            throw Error("양쪽 도면에서 동일한 기준점 두 개를 지정하세요.");
        const x = from[1].x - from[0].x,
            y = from[1].y - from[0].y,
            u = to[1].x - to[0].x,
            v = to[1].y - to[0].y,
            d = x * x + y * y;
        if (d < 1e-12 || u * u + v * v < 1e-12)
            throw Error("서로 떨어진 기준점 두 개가 필요합니다.");
        const a = (x * u + y * v) / d,
            b = (x * v - y * u) / d,
            t = {
                a,
                b,
                tx: to[0].x - a * from[0].x + b * from[0].y,
                ty: to[0].y - b * from[0].x - a * from[0].y,
            };
        if (
            !Object.values(t).every(Number.isFinite) ||
            Math.hypot(a, b) < 1e-6 ||
            Math.hypot(a, b) > 1e6
        )
            throw Error("정렬 배율의 범위를 확인하세요.");
        return t;
    }
    const map = (p, t = identity) => ({
        x: t.a * p.x - t.b * p.y + t.tx,
        y: t.b * p.x + t.a * p.y + t.ty,
    });
    function signature(e, t, tolerance) {
        const ps = C.geometry(e).map((p) => map(p, t)),
            round = (v) => Math.round(v / tolerance);
        // Native handles are optional; coordinates provide a fallback when an export regenerates them.
        return JSON.stringify([
            e.type,
            e.layer,
            e.color,
            e.aci,
            e.lineType,
            e.lineWeight,
            e.text || "",
            (e.renderParts || []).map((p) => [
                p.type,
                p.text || p.displayText || "",
                p.color,
                p.aci,
                p.lineType,
                p.lineWeight,
                p.fillStyle,
            ]),
            ps.map((p) => [round(p.x), round(p.y)]),
        ]);
    }
    function compare(oldDoc, newDoc, t = identity, tolerance = 0.01) {
        if (oldDoc.reference || newDoc.reference)
            throw Error(
                "객체 비교는 CAD 원본끼리 지원합니다. PDF·이미지는 표시 편집과 확대 상세도를 사용하세요.",
            );
        if (!Number.isFinite(tolerance) || tolerance <= 0)
            throw Error("비교 허용 오차는 0보다 커야 합니다.");
        const old = visible(oldDoc).filter((e) => C.geometry(e).length),
            next = visible(newDoc).filter((e) => C.geometry(e).length),
            bySignature = new Map(),
            used = new Set(),
            results = [];
        old.forEach((e, i) => {
            const key = signature(e, t, tolerance);
            if (!bySignature.has(key)) bySignature.set(key, []);
            bySignature.get(key).push(i);
        });
        const remaining = [];
        for (const e of next) {
            const same = bySignature.get(signature(e, identity, tolerance));
            if (same?.length) used.add(same.pop());
            else remaining.push(e);
        }
        const handles = new Map();
        old.forEach((e, i) => {
            const h = e.raw?.find((p) => p[0] === 5)?.[1];
            if (!used.has(i) && h) handles.set(h, i);
        });
        for (const e of remaining) {
            const h = e.raw?.find((p) => p[0] === 5)?.[1],
                i = handles.get(h),
                previous = i !== undefined && !used.has(i) ? old[i] : null;
            if (previous) used.add(i);
            const ps = [
                ...C.geometry(e),
                ...(previous ? C.geometry(previous).map((p) => map(p, t)) : []),
            ];
            results.push({
                kind: previous ? "changed" : "added",
                entityId: e.id,
                previousId: previous?.id,
                bounds: bounds(ps),
                label: `${e.type} · ${e.layer}`,
            });
        }
        old.forEach((e, i) => {
            if (!used.has(i))
                results.push({
                    kind: "removed",
                    previousId: e.id,
                    bounds: bounds(C.geometry(e).map((p) => map(p, t))),
                    label: `${e.type} · ${e.layer}`,
                });
        });
        return results;
    }
    function candidates(doc, seed, tolerance = 0.001) {
        if (!seed || !["INSERT", "CIRCLE"].includes(seed.type))
            throw Error("원본 블록 또는 원을 기준 기호로 선택하세요.");
        if (!Number.isFinite(tolerance) || tolerance <= 0)
            throw Error("기호 허용 오차가 올바르지 않습니다.");
        const get = (e, c, d) =>
                Number(e.raw?.find((p) => p[0] === c)?.[1] ?? d),
            name = (e) => e.blockName || e.raw?.find((p) => p[0] === 2)?.[1];
        return visible(doc)
            .filter(
                (e) =>
                    e.type === seed.type &&
                    C.geometry(e).length &&
                    (e.type === "CIRCLE"
                        ? Math.abs(e.radius - seed.radius) <= tolerance
                        : name(e) === name(seed) &&
                          Math.abs(
                              Math.abs(get(e, 41, 1)) -
                                  Math.abs(get(seed, 41, 1)),
                          ) <= tolerance &&
                          Math.abs(
                              Math.abs(get(e, 42, 1)) -
                                  Math.abs(get(seed, 42, 1)),
                          ) <= tolerance),
            )
            .map((e) => ({
                entityId: e.id,
                point: e.p || center(bounds(C.geometry(e))),
                bounds: bounds(C.geometry(e)),
                label: `${name(e) || e.type} · ${e.layer}`,
            }));
    }
    function detailValid(item) {
        const b = item.detail,
            ps = item.points;
        if (
            !b ||
            ps.length !== 4 ||
            !Object.values(b).every(Number.isFinite) ||
            Object.values(b).some((v) => Math.abs(v) > 1e9) ||
            b.maxX <= b.minX ||
            b.maxY <= b.minY ||
            !M.validPolygon(ps)
        )
            return false;
        const scale = Math.max(
            1,
            M.distance(ps[0], ps[1]),
            M.distance(ps[0], ps[3]),
        );
        return (
            Math.hypot(
                ps[0].x + ps[2].x - ps[1].x - ps[3].x,
                ps[0].y + ps[2].y - ps[1].y - ps[3].y,
            ) <
            scale * 1e-7
        );
    }
    function drawDetail(ctx, item, screen, scale, options) {
        const doc = options.doc;
        if (!doc || !detailValid(item)) return;
        const b = item.detail,
            [p, q, , s] = item.points,
            project = (v) =>
                screen({
                    x:
                        p.x +
                        ((q.x - p.x) * (v.x - b.minX)) / (b.maxX - b.minX) +
                        ((s.x - p.x) * (v.y - b.minY)) / (b.maxY - b.minY),
                    y:
                        p.y +
                        ((q.y - p.y) * (v.x - b.minX)) / (b.maxX - b.minX) +
                        ((s.y - p.y) * (v.y - b.minY)) / (b.maxY - b.minY),
                });
        const detailScale = (scale * M.distance(p, q)) / (b.maxX - b.minX),
            ps = item.points.map(screen);
        ctx.save();
        ctx.beginPath();
        ps.forEach((v, i) => (i ? ctx.lineTo(v.x, v.y) : ctx.moveTo(v.x, v.y)));
        ctx.closePath();
        ctx.fillStyle = options.paper || options.background === 'white' ? "#fff" : "#0c1423";
        ctx.fill();
        ctx.clip();
        C.renderEntities(ctx, doc, visible(doc), project, detailScale, {
            paper: options.paper,
            pixelsPerMm: options.pixelsPerMm,
            background: options.background,
        });
        for (const m of doc.markups || [])
            if (
                m.kind !== "detail" &&
                !(doc.workspace?.layers || []).some(
                    (l) => l.id === m.layer && !l.visible,
                )
            )
                M.draw(ctx, m, project, detailScale, options);
        ctx.restore();
        ctx.beginPath();
        ps.forEach((v, i) => (i ? ctx.lineTo(v.x, v.y) : ctx.moveTo(v.x, v.y)));
        ctx.closePath();
        const color = !options.paper && options.color ? options.color(item.color) : item.color;
        ctx.strokeStyle = color;
        ctx.stroke();
        ctx.font = `600 ${Math.max(8, item.size * scale)}px sans-serif`;
        ctx.textAlign = "left";
        ctx.textBaseline = "bottom";
        ctx.fillStyle = color;
        ctx.fillText(
            item.label || "확대 상세 · 원본 연결",
            ps[3].x,
            ps[3].y - 4 * (options.lineScale || 1),
        );
    }
    function cloud(row, index, size, source) {
        const b = row.bounds,
            pad = Math.max(
                size,
                Math.max(b.maxX - b.minX, b.maxY - b.minY) * 0.05,
            );
        return {
            id: crypto.randomUUID(),
            kind: "cloud",
            points: M.rect(
                { x: b.minX - pad, y: b.minY - pad },
                { x: b.maxX + pad, y: b.maxY + pad },
            ),
            color: "#fb7185",
            opacity: 1,
            width: 2,
            size,
            pattern: "solid",
            locked: false,
            text: `${source} · ${row.kind} · ${row.label}`,
            label: `REV-${index + 1}`,
        };
    }
    root.CADReview = {
        alignment,
        map,
        identity,
        compare,
        candidates,
        bounds,
        center,
        visible,
        detailValid,
        drawDetail,
        cloud,
    };
})(typeof window === "undefined" ? globalThis : window);
