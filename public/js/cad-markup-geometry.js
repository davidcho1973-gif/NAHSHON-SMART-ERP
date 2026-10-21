(function (root) {
    "use strict";
    // One geometry contract for editing, measurements, picking and export.
    const G = {},
        eps = 1e-8;
    G.rings = (item) => [item.points, ...(item.holes || [])];
    G.pointCount = (item) =>
        G.rings(item).reduce((n, ring) => n + ring.length, 0);
    G.map = (item, fn) => ({
        ...structuredClone(item),
        points: item.points.map(fn),
        ...(item.holes
            ? { holes: item.holes.map((ring) => ring.map(fn)) }
            : {}),
    });
    G.area = (item) =>
        Math.abs(root.CADMarkup.signedArea(item.points)) -
        (item.holes || []).reduce(
            (sum, ring) => sum + Math.abs(root.CADMarkup.signedArea(ring)),
            0,
        );
    G.length = (item) =>
        item.points
            .slice(1)
            .reduce(
                (sum, p, i) => sum + root.CADMarkup.distance(p, item.points[i]),
                0,
            );
    G.rotatePoint = (p, center, angle) => {
        const c = Math.cos(angle),
            s = Math.sin(angle),
            x = p.x - center.x,
            y = p.y - center.y;
        return { x: center.x + x * c - y * s, y: center.y + x * s + y * c };
    };
    G.bounds = (items) => {
        const bs = items.map(root.CADMarkup.bounds);
        return {
            minX: Math.min(...bs.map((b) => b.minX)),
            minY: Math.min(...bs.map((b) => b.minY)),
            maxX: Math.max(...bs.map((b) => b.maxX)),
            maxY: Math.max(...bs.map((b) => b.maxY)),
        };
    };
    G.transform = (items, degrees, factor, center) => {
        if (
            !Number.isFinite(degrees) ||
            !Number.isFinite(factor) ||
            factor <= 0
        )
            throw Error("올바른 각도와 배율을 입력하세요.");
        if (!center) {
            const b = G.bounds(items);
            center = { x: (b.minX + b.maxX) / 2, y: (b.minY + b.maxY) / 2 };
        }
        return items.map((item) => {
            const result = G.map(item, (p) => {
                const q = G.rotatePoint(p, center, (degrees * Math.PI) / 180);
                return {
                    x: center.x + (q.x - center.x) * factor,
                    y: center.y + (q.y - center.y) * factor,
                };
            });
            result.size *= factor;
            if (["callout", "text"].includes(item.kind))
                result.rotation = ((item.rotation || 0) + degrees) % 360;
            if (result.textStyle?.boxWidth) result.textStyle.boxWidth *= factor;
            return result;
        });
    };
    G.combine = (items, operation) => {
        if (
            items.length < 2 ||
            items.some((m) => m.kind !== "area" || m.locked)
        )
            throw Error("잠금이 없는 영역을 두 개 이상 선택하세요.");
        if (
            !["union", "difference", "intersection"].includes(operation) ||
            !root.polygonClipping
        )
            throw Error("영역 연산기를 사용할 수 없습니다.");
        const polygons = items.map((item) =>
            G.rings(item).map((ring) => ring.map((p) => [p.x, p.y])),
        );
        const result = root.polygonClipping[operation](...polygons);
        return result.map((polygon, index) => ({
            ...structuredClone(items[0]),
            id: index ? crypto.randomUUID() : items[0].id,
            points: polygon[0].slice(0, -1).map(([x, y]) => ({ x, y })),
            holes: polygon
                .slice(1)
                .map((ring) => ring.slice(0, -1).map(([x, y]) => ({ x, y }))),
        }));
    };
    G.valid = (item) => {
        const M = root.CADMarkup;
        if (!Array.isArray(item.points) || !item.points.length) return false;
        if (item.kind === "detail" && !root.CADReview?.detailValid(item))
            return false;
        if (["text", "pin"].includes(item.kind) && item.points.length !== 1)
            return false;
        if (item.kind === "callout" && item.points.length !== 2) return false;
        if (
            ["pen", "highlight", "route"].includes(item.kind) &&
            item.points.length < 2
        )
            return false;
        if (
            item.textStyle?.boxWidth !== undefined &&
            (!Number.isFinite(item.textStyle.boxWidth) ||
                item.textStyle.boxWidth <= 0 ||
                item.textStyle.boxWidth > 1e9)
        )
            return false;
        if (
            G.rings(item).some((ring) =>
                ring.some(
                    (p) =>
                        !Number.isFinite(p.x) ||
                        !Number.isFinite(p.y) ||
                        Math.abs(p.x) > 1e9 ||
                        Math.abs(p.y) > 1e9,
                ),
            )
        )
            return false;
        if (!Number.isFinite(item.size) || item.size <= 0 || item.size > 1e9)
            return false;
        if (!["area", "cloud"].includes(item.kind)) return true;
        if (!G.rings(item).every(M.validPolygon)) return false;
        if (!item.holes?.length) return true;
        if (item.kind !== "area" || !root.polygonClipping) return false;
        const toRing = (ring) => ring.map((p) => [p.x, p.y]);
        const outer = [toRing(item.points)],
            holes = item.holes.map((ring) => [toRing(ring)]);
        const touch = (a, b) =>
            a.some((p) =>
                b.some(
                    (q, i) =>
                        M.segmentDistance(p, q, b[(i + 1) % b.length]) <= eps,
                ),
            ) ||
            b.some((p) =>
                a.some(
                    (q, i) =>
                        M.segmentDistance(p, q, a[(i + 1) % a.length]) <= eps,
                ),
            );
        try {
            for (let i = 0; i < holes.length; i++) {
                if (touch(item.holes[i], item.points)) return false;
                if (root.polygonClipping.difference(holes[i], outer).length)
                    return false;
                if (item.holes[i].some((p) => !M.inside(p, item.points)))
                    return false;
                for (let j = i + 1; j < holes.length; j++)
                    if (
                        touch(item.holes[i], item.holes[j]) ||
                        root.polygonClipping.intersection(holes[i], holes[j])
                            .length
                    )
                        return false;
            }
            return G.area(item) > eps;
        } catch {
            return false;
        }
    };
    G.validSet = (items) =>
        items.length <= 1000 &&
        items.reduce((n, m) => n + G.pointCount(m), 0) <= 50000 &&
        items.every(
            (m) =>
                G.rings(m).every((ring) => ring.length <= 2048) && G.valid(m),
        ) &&
        new TextEncoder().encode(JSON.stringify(items)).length <= 2000000;
    G.snapAngle = (origin, p, step) => {
        if (!step) return p;
        const angle =
            (Math.round(
                Math.atan2(p.y - origin.y, p.x - origin.x) /
                    ((Math.PI * step) / 180),
            ) *
                Math.PI *
                step) /
            180;
        const length = root.CADMarkup.distance(origin, p);
        return {
            x: origin.x + Math.cos(angle) * length,
            y: origin.y + Math.sin(angle) * length,
        };
    };
    function units(items) {
        const groups = new Map();
        for (const item of items) {
            const key = item.group ? "group:" + item.group : "item:" + item.id;
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(item);
        }
        return [...groups.values()].map((items) => ({
            items,
            b: G.bounds(items),
        }));
    }
    G.align = (items, mode) => {
        const all = G.bounds(items);
        return units(items).flatMap(({ items: members, b }) => {
            const dx =
                mode === "left"
                    ? all.minX - b.minX
                    : mode === "right"
                      ? all.maxX - b.maxX
                      : mode === "center"
                        ? (all.minX + all.maxX - b.minX - b.maxX) / 2
                        : 0;
            const dy =
                mode === "top"
                    ? all.maxY - b.maxY
                    : mode === "bottom"
                      ? all.minY - b.minY
                      : mode === "middle"
                        ? (all.minY + all.maxY - b.minY - b.maxY) / 2
                        : 0;
            return members.map((item) =>
                root.CADMarkup.translate(item, dx, dy),
            );
        });
    };
    G.distribute = (items, axis) => {
        const groups = units(items);
        if (groups.length < 3)
            throw Error("균등 간격에는 표시가 3개 이상 필요합니다.");
        const lo = axis === "x" ? "minX" : "minY",
            hi = axis === "x" ? "maxX" : "maxY";
        const ordered = groups.sort((a, b) => a.b[lo] - b.b[lo]);
        const gap =
            (ordered.at(-1).b[hi] -
                ordered[0].b[lo] -
                ordered.reduce((s, a) => s + a.b[hi] - a.b[lo], 0)) /
            (groups.length - 1);
        let position = ordered[0].b[lo];
        return ordered.flatMap(({ items: members, b }) => {
            const offset = position - b[lo];
            position += b[hi] - b[lo] + gap;
            return members.map((item) =>
                root.CADMarkup.translate(
                    item,
                    axis === "x" ? offset : 0,
                    axis === "y" ? offset : 0,
                ),
            );
        });
    };
    function intersections(a, b, c, d, strict = false) {
        const dx = b.x - a.x,
            dy = b.y - a.y,
            ex = d.x - c.x,
            ey = d.y - c.y;
        const den = dx * ey - dy * ex;
        if (Math.abs(den) < eps)
            return (
                !strict &&
                [
                    root.CADMarkup.segmentDistance(a, c, d),
                    root.CADMarkup.segmentDistance(b, c, d),
                    root.CADMarkup.segmentDistance(c, a, b),
                    root.CADMarkup.segmentDistance(d, a, b),
                ].some((n) => n < eps)
            );
        const t = ((c.x - a.x) * ey - (c.y - a.y) * ex) / den,
            u = ((c.x - a.x) * dy - (c.y - a.y) * dx) / den;
        return strict
            ? t > eps && t < 1 - eps && u > eps && u < 1 - eps
            : t >= -eps && t <= 1 + eps && u >= -eps && u <= 1 + eps;
    }
    G.selectRegion = (items, region, crossing) =>
        items.filter((item) => {
            const M = root.CADMarkup,
                close = (ps) => [...ps, ps[0]];
            let paths = [[...item.points]];
            if (["area", "detail"].includes(item.kind))
                paths = G.rings(item).map(close);
            if (item.kind === "cloud")
                paths = [
                    close(
                        M.cloud(item.points, item.size).flatMap(({ a, b, c }) =>
                            Array.from({ length: 8 }, (_, i) => {
                                const t = i / 8;
                                return {
                                    x:
                                        (1 - t) ** 2 * a.x +
                                        2 * (1 - t) * t * c.x +
                                        t * t * b.x,
                                    y:
                                        (1 - t) ** 2 * a.y +
                                        2 * (1 - t) * t * c.y +
                                        t * t * b.y,
                                };
                            }),
                        ),
                    ),
                ];
            if (item.kind === "pin")
                paths = [
                    close(
                        Array.from({ length: 32 }, (_, i) => ({
                            x:
                                item.points[0].x +
                                Math.cos((i * Math.PI) / 16) * item.size,
                            y:
                                item.points[0].y +
                                Math.sin((i * Math.PI) / 16) * item.size,
                        })),
                    ),
                ];
            if (["text", "callout"].includes(item.kind))
                paths.push(close(M.labelCorners(item)));
            if (item.label && ["cloud", "area", "route"].includes(item.kind)) {
                const b = M.bounds(item);
                paths.push(
                    close(
                        M.rect(
                            { x: b.minX, y: b.minY },
                            { x: b.maxX, y: b.maxY },
                        ),
                    ),
                );
            }
            const points = paths.flat();
            if (
                crossing &&
                (points.some((p) => M.inside(p, region)) ||
                    region.some((p) => M.hit(item, p, 0)))
            )
                return true;
            const crossed = paths.some((path) =>
                path
                    .slice(1)
                    .some((b, i) =>
                        region.some((c, j) =>
                            intersections(
                                path[i],
                                b,
                                c,
                                region[(j + 1) % region.length],
                                !crossing,
                            ),
                        ),
                    ),
            );
            return crossing
                ? crossed
                : !crossed && points.every((p) => M.inside(p, region));
        });
    // Clip each ink segment against the complete swept capsule, not sampled dots.
    // Fast dragging therefore cannot leave gaps or remove the untouched stroke.
    function circleInterval(a, b, c, radius) {
        const x = a.x - c.x,
            y = a.y - c.y,
            dx = b.x - a.x,
            dy = b.y - a.y;
        const aa = dx * dx + dy * dy,
            bb = 2 * (x * dx + y * dy),
            cc = x * x + y * y - radius * radius;
        if (!aa) return cc <= 0 ? [0, 1] : null;
        const disc = bb * bb - 4 * aa * cc;
        if (disc < 0) return null;
        const d = Math.sqrt(disc),
            lo = Math.max(0, (-bb - d) / (2 * aa)),
            hi = Math.min(1, (-bb + d) / (2 * aa));
        return hi > lo + eps ? [lo, hi] : null;
    }
    function stripInterval(a, b, c, d, radius) {
        const len = root.CADMarkup.distance(c, d);
        if (!len) return null;
        const ux = (d.x - c.x) / len,
            uy = (d.y - c.y) / len,
            dx = b.x - a.x,
            dy = b.y - a.y;
        let lo = 0,
            hi = 1;
        for (const [start, change, min, max] of [
            [(a.x - c.x) * ux + (a.y - c.y) * uy, dx * ux + dy * uy, 0, len],
            [
                -(a.x - c.x) * uy + (a.y - c.y) * ux,
                -dx * uy + dy * ux,
                -radius,
                radius,
            ],
        ]) {
            if (Math.abs(change) < eps) {
                if (start < min || start > max) return null;
            } else {
                let t1 = (min - start) / change,
                    t2 = (max - start) / change;
                if (t1 > t2) [t1, t2] = [t2, t1];
                lo = Math.max(lo, t1);
                hi = Math.min(hi, t2);
            }
        }
        return hi > lo + eps ? [lo, hi] : null;
    }
    G.erase = (points, stroke, radius) => {
        if (!stroke.length || !(radius > 0)) return [structuredClone(points)];
        const result = [];
        let current = [];
        const flush = () => {
            if (current.length > 1) result.push(current);
            current = [];
        };
        for (let i = 1; i < points.length; i++) {
            const a = points[i - 1],
                b = points[i],
                intervals = [];
            for (let j = 0; j < stroke.length; j++) {
                const circle = circleInterval(a, b, stroke[j], radius);
                if (circle) intervals.push(circle);
                if (j) {
                    const strip = stripInterval(
                        a,
                        b,
                        stroke[j - 1],
                        stroke[j],
                        radius,
                    );
                    if (strip) intervals.push(strip);
                }
            }
            intervals.sort((x, y) => x[0] - y[0]);
            const merged = [];
            for (const int of intervals) {
                if (merged.length && int[0] <= merged.at(-1)[1] + eps)
                    merged.at(-1)[1] = Math.max(merged.at(-1)[1], int[1]);
                else merged.push([...int]);
            }
            const at = (t) => ({
                x: a.x + (b.x - a.x) * t,
                y: a.y + (b.y - a.y) * t,
            });
            let start = 0;
            for (const [lo, hi] of [...merged, [1, 1]]) {
                if (lo > start + eps) {
                    const p = at(start),
                        q = at(lo);
                    if (
                        current.length &&
                        root.CADMarkup.distance(current.at(-1), p) > eps
                    )
                        flush();
                    if (!current.length) current.push(p);
                    current.push(q);
                }
                if (hi > lo) flush();
                start = Math.max(start, hi);
            }
        }
        flush();
        return result;
    };
    root.CADMarkupGeometry = G;
})(typeof window === "undefined" ? globalThis : window);
