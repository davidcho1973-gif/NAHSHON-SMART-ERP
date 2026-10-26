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
        item.kind === 'ellipse' ? Math.PI * Math.abs(root.CADMarkup.ellipseFrame(item.points).determinant) : Math.abs(root.CADMarkup.signedArea(item.points)) -
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
            throw Error("자유·사각·다각형 영역을 두 개 이상 선택하세요. 원·타원 또는 잠긴 표시는 영역 연산에서 제외하세요.");
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
        if (item.dash !== undefined && !['solid', 'dash', 'dot', 'dashdot'].includes(item.dash)) return false;
        if (item.fillColor !== undefined && (!['area', 'ellipse'].includes(item.kind) || !/^(none|#[0-9a-f]{6})$/i.test(item.fillColor))) return false;
        if (item.fillOpacity !== undefined && (!['area', 'ellipse'].includes(item.kind) || !Number.isFinite(item.fillOpacity) || item.fillOpacity < 0.05 || item.fillOpacity > 1)) return false;
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
        if (item.kind === 'ellipse') {
            const f = M.ellipseFrame(item.points);
            return item.points.length === 4 && !item.holes && !!f && Math.abs(f.determinant) >= 1e-12 &&
                f.residual < Math.max(1, Math.hypot(f.u.x, f.u.y) * 2, Math.hypot(f.v.x, f.v.y) * 2) * 1e-7;
        }
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
    G.shape = (tool, a, b, sides = 6) => {
        const M = root.CADMarkup;
        if (tool === 'circle') {
            const d = Math.max(Math.abs(b.x - a.x), Math.abs(b.y - a.y));
            b = { x: a.x + (b.x < a.x ? -d : d), y: a.y + (b.y < a.y ? -d : d) };
        }
        if (['box', 'cloud', 'circle', 'ellipse'].includes(tool)) return M.rect(a, b);
        const x = (a.x + b.x) / 2, y = (a.y + b.y) / 2,
            rx = Math.abs(b.x - a.x) / 2, ry = Math.abs(b.y - a.y) / 2;
        if (tool === 'triangle') return [{ x, y: y + ry }, { x: x - rx, y: y - ry }, { x: x + rx, y: y - ry }];
        const n = tool === 'star' ? 10 : tool === 'diamond' ? 4 : Math.max(3, Math.min(12, Math.round(sides) || 6));
        return Array.from({ length: n }, (_, i) => {
            const angle = Math.PI / 2 + i * Math.PI * 2 / n, r = tool === 'star' && i % 2 ? 0.45 : 1;
            return { x: x + Math.cos(angle) * rx * r, y: y + Math.sin(angle) * ry * r };
        });
    };
    G.resizeEllipse = (item, index, target) => {
        const points = item.points, fixed = points[(index + 2) % 4],
            u = { x: points[(index + 1) % 4].x - fixed.x, y: points[(index + 1) % 4].y - fixed.y },
            v = { x: points[(index + 3) % 4].x - fixed.x, y: points[(index + 3) % 4].y - fixed.y },
            det = u.x * v.y - u.y * v.x;
        if (!det) return structuredClone(item);
        const x = target.x - fixed.x, y = target.y - fixed.y,
            s = (x * v.y - y * v.x) / det, t = (u.x * y - u.y * x) / det,
            result = structuredClone(item);
        result.points[index] = { ...target };
        result.points[(index + 1) % 4] = { x: fixed.x + s * u.x, y: fixed.y + s * u.y };
        result.points[(index + 3) % 4] = { x: fixed.x + t * v.x, y: fixed.y + t * v.y };
        return result;
    };
    G.resize = (items, width, height, keepRatio = true) => {
        const b = G.bounds(items), w = b.maxX - b.minX, h = b.maxY - b.minY;
        if (![width, height].every(n => Number.isFinite(n) && n > 0 && n <= 1e9) || w <= 1e-12 || h <= 1e-12)
            throw Error('너비와 높이가 있는 표시를 선택하고 양수를 입력하세요.');
        const sx = width / w, sy = keepRatio ? sx : height / h;
        if (Math.abs(sx - sy) > 1e-9 && items.some(m => !['area', 'ellipse', 'route', 'pen', 'highlight'].includes(m.kind) || m.label))
            throw Error('문자·구름·번호·상세도가 포함되면 비율 유지를 켜세요.');
        const center = { x: (b.minX + b.maxX) / 2, y: (b.minY + b.maxY) / 2 };
        return items.map(item => {
            const result = G.map(item, p => ({ x: center.x + (p.x - center.x) * sx, y: center.y + (p.y - center.y) * sy }));
            if (Math.abs(sx - sy) <= 1e-9) {
                result.size *= sx;
                if (result.textStyle?.boxWidth) result.textStyle.boxWidth *= sx;
            }
            return result;
        });
    };
    G.repeat = (items, columns, rows, dx, dy, existingCount = 0) => {
        if (![columns, rows].every(n => Number.isInteger(n) && n >= 1 && n <= 20) || columns * rows < 2 ||
            ![dx, dy].every(n => Number.isFinite(n) && Math.abs(n) <= 1e9) ||
            (columns > 1 && dx === 0) || (rows > 1 && dy === 0))
            throw Error('열·행은 1~20, 반복 방향의 간격은 0이 아닌 수로 입력하세요.');
        if (existingCount + items.length * (columns * rows - 1) > 1000)
            throw Error('반복 결과가 표시 1,000개 한도를 넘습니다.');
        const result = [];
        for (let row = 0; row < rows; row++) for (let column = 0; column < columns; column++) {
            if (!row && !column) continue;
            const groups = new Map();
            for (const item of items) {
                const copy = root.CADMarkup.translate(structuredClone(item), dx * column, dy * row);
                copy.id = crypto.randomUUID();
                if (copy.group) {
                    if (!groups.has(copy.group)) groups.set(copy.group, crypto.randomUUID());
                    copy.group = groups.get(copy.group);
                }
                result.push(copy);
            }
        }
        return result;
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
            if (item.kind === 'ellipse') paths = [close(M.ellipsePoints(item))];
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
            if (item.label && ["cloud", "area", "ellipse", "route"].includes(item.kind)) {
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

    // Discover faces from vector linework only. Never join flattened INSERT paths,
    // snap real gaps closed, or use curve/text/image preview boxes as boundaries.
    G.findDrawingBoundary = (entities, point, options = {}) => {
        const limits = {
            entities: 100000,
            points: 100000,
            segments: 5000,
            comparisons: 200000,
            splits: 20000,
            facePoints: 512,
            ...options.limits,
        };
        const stats = {
            entities: 0,
            points: 0,
            segments: 0,
            comparisons: 0,
            splits: 0,
        };
        const fail = () => {
            const error = Error(
                "경계 분석 한도를 넘었습니다. 불필요한 레이어를 숨긴 뒤 다시 클릭하거나 자유 영역을 사용하세요.",
            );
            error.code = "CAD_BOUNDARY_LIMIT";
            throw error;
        };
        const finite = (p) => p && Number.isFinite(p.x) && Number.isFinite(p.y);
        if (!finite(point))
            throw Error("경계를 찾을 좌표가 올바르지 않습니다.");
        const segments = [],
            M = root.CADMarkup,
            bounds = options.bounds;
        if (
            bounds &&
            (!finite({ x: bounds.minX, y: bounds.minY }) ||
                !finite({ x: bounds.maxX, y: bounds.maxY }) ||
                bounds.minX >= bounds.maxX ||
                bounds.minY >= bounds.maxY)
        )
            throw Error("경계 검색 범위가 올바르지 않습니다.");
        const rawNumber = (e, code, fallback = 0) =>
            Number(e.raw?.find((pair) => pair[0] === code)?.[1] ?? fallback);
        function collect(e, depth = 0) {
            if (++stats.entities > limits.entities || depth > 12) fail();
            if (
                !e ||
                e.hidden ||
                e.paper ||
                options.layers?.[e.layer]?.visible === false ||
                rawNumber(e, 60) === 1 ||
                rawNumber(e, 67)
            )
                return;
            if (e.type === "INSERT") {
                if (e.renderParts?.length) {
                    for (const part of e.renderParts) collect(part, depth + 1);
                } else
                    for (const path of e.preview || [])
                        if (path.length === 2) add(path[0], path[1]);
                return;
            }
            if (!["LINE", "LWPOLYLINE", "POLYLINE"].includes(e.type)) return;
            if (
                [30, 31, 38, 210, 220].some(
                    (code) => rawNumber(e, code) !== 0,
                ) ||
                rawNumber(e, 230, 1) !== 1
            )
                return;
            if ((e.points?.length || 0) > limits.points - stats.points) fail();
            if (
                e.points?.some((p) => p.bulge) ||
                (e.type === "POLYLINE" && rawNumber(e, 70) & ~1)
            )
                return;
            const path =
                e.displayGeometry ||
                (e.type === "LINE" ? [e.p, e.q] : e.points);
            if (!Array.isArray(path)) return;
            for (let i = 1; i < path.length; i++) add(path[i - 1], path[i]);
            if (e.type !== "LINE" && e.closed && path.length > 2)
                add(path.at(-1), path[0]);
        }
        function add(a, b) {
            if ((stats.points += 2) > limits.points) fail();
            if (!finite(a) || !finite(b) || (a.x === b.x && a.y === b.y))
                return;
            if (bounds) {
                // Clip without adding rectangle edges. Any resulting face touching
                // the search border is rejected below, so clipping cannot close a gap.
                let lo = 0,
                    hi = 1;
                const dx = b.x - a.x,
                    dy = b.y - a.y;
                for (const [p, q] of [
                    [-dx, a.x - bounds.minX],
                    [dx, bounds.maxX - a.x],
                    [-dy, a.y - bounds.minY],
                    [dy, bounds.maxY - a.y],
                ]) {
                    if (p === 0) {
                        if (q < 0) return;
                        continue;
                    }
                    const t = q / p;
                    if (p < 0) lo = Math.max(lo, t);
                    else hi = Math.min(hi, t);
                    if (lo > hi) return;
                }
                const start = { x: a.x + dx * lo, y: a.y + dy * lo },
                    end = { x: a.x + dx * hi, y: a.y + dy * hi };
                a = start;
                b = end;
                if (a.x === b.x && a.y === b.y) return;
            }
            if (++stats.segments > limits.segments) fail();
            segments.push({
                a: { x: a.x, y: a.y },
                b: { x: b.x, y: b.y },
                cuts: [0, 1],
                minX: Math.min(a.x, b.x),
                maxX: Math.max(a.x, b.x),
                minY: Math.min(a.y, b.y),
                maxY: Math.max(a.y, b.y),
            });
        }
        for (const e of entities) collect(e);
        if (!segments.length) return null;
        // Only numerical roundoff is merged: never a pixel/world-unit snap tolerance.
        const magnitude = segments.reduce(
            (n, s) =>
                Math.max(
                    n,
                    Math.abs(s.a.x),
                    Math.abs(s.a.y),
                    Math.abs(s.b.x),
                    Math.abs(s.b.y),
                ),
            1,
        );
        const tolerance = Math.max(1e-10, magnitude * Number.EPSILON * 4);
        const cross = (a, b) => a.x * b.y - a.y * b.x;
        const sub = (a, b) => ({ x: a.x - b.x, y: a.y - b.y });
        function cut(segment, t) {
            if (t < 0 || t > 1) return;
            if (++stats.splits > limits.splits) fail();
            segment.cuts.push(t);
        }
        const ordered = [...segments].sort((a, b) => a.minX - b.minX),
            active = [];
        for (const s of ordered) {
            for (let i = active.length - 1; i >= 0; i--)
                if (active[i].maxX < s.minX - tolerance) active.splice(i, 1);
            for (const other of active) {
                if (++stats.comparisons > limits.comparisons) fail();
                if (
                    other.maxY < s.minY - tolerance ||
                    s.maxY < other.minY - tolerance
                )
                    continue;
                const r = sub(s.b, s.a),
                    v = sub(other.b, other.a),
                    q = sub(other.a, s.a),
                    den = cross(r, v);
                const lenR = Math.hypot(r.x, r.y),
                    lenV = Math.hypot(v.x, v.y);
                if (Math.abs(den) > Number.EPSILON * 16 * lenR * lenV) {
                    const t = cross(q, v) / den,
                        u = cross(q, r) / den;
                    if (t >= 0 && t <= 1 && u >= 0 && u <= 1) {
                        cut(s, t);
                        cut(other, u);
                    }
                } else if (Math.abs(cross(q, r)) <= tolerance * lenR) {
                    // Split coincident overlap at all native endpoints, then deduplicate edges.
                    for (const p of [other.a, other.b])
                        cut(
                            s,
                            ((p.x - s.a.x) * r.x + (p.y - s.a.y) * r.y) /
                                (lenR * lenR),
                        );
                    for (const p of [s.a, s.b])
                        cut(
                            other,
                            ((p.x - other.a.x) * v.x +
                                (p.y - other.a.y) * v.y) /
                                (lenV * lenV),
                        );
                }
            }
            active.push(s);
        }
        const vertices = [],
            buckets = new Map(),
            edges = [],
            edgeKeys = new Set();
        function vertex(p) {
            const x = Math.floor(p.x / tolerance),
                y = Math.floor(p.y / tolerance);
            for (let dx = -1; dx <= 1; dx++)
                for (let dy = -1; dy <= 1; dy++)
                    for (const id of buckets.get(x + dx + "," + (y + dy)) || [])
                        if (
                            Math.hypot(
                                vertices[id].x - p.x,
                                vertices[id].y - p.y,
                            ) <= tolerance
                        )
                            return id;
            const id = vertices.length;
            vertices.push({ x: p.x, y: p.y, links: [] });
            const key = x + "," + y;
            if (!buckets.has(key)) buckets.set(key, []);
            buckets.get(key).push(id);
            return id;
        }
        for (const s of segments) {
            s.cuts.sort((a, b) => a - b);
            for (let i = 1; i < s.cuts.length; i++) {
                const at = (t) => ({
                        x: s.a.x + (s.b.x - s.a.x) * t,
                        y: s.a.y + (s.b.y - s.a.y) * t,
                    }),
                    a = vertex(at(s.cuts[i - 1])),
                    b = vertex(at(s.cuts[i]));
                if (a === b) continue;
                const key = Math.min(a, b) + "," + Math.max(a, b);
                if (edgeKeys.has(key)) continue;
                edgeKeys.add(key);
                const id = edges.length;
                edges.push({ a, b });
                vertices[a].links.push(id);
                vertices[b].links.push(id);
                if (edges.length > limits.splits) fail();
            }
        }
        // Remove bridges: dangling/T-joint branches must not pollute face rings.
        const discovery = new Int32Array(vertices.length),
            low = new Int32Array(vertices.length),
            bridges = new Set();
        let clock = 0;
        for (let start = 0; start < vertices.length; start++)
            if (!discovery[start]) {
                discovery[start] = low[start] = ++clock;
                const stack = [{ id: start, parent: -1, edge: -1, next: 0 }];
                while (stack.length) {
                    const frame = stack.at(-1),
                        links = vertices[frame.id].links;
                    if (frame.next < links.length) {
                        const edgeId = links[frame.next++];
                        if (edgeId === frame.edge) continue;
                        const edge = edges[edgeId],
                            to = edge.a === frame.id ? edge.b : edge.a;
                        if (!discovery[to]) {
                            discovery[to] = low[to] = ++clock;
                            stack.push({
                                id: to,
                                parent: frame.id,
                                edge: edgeId,
                                next: 0,
                            });
                        } else
                            low[frame.id] = Math.min(
                                low[frame.id],
                                discovery[to],
                            );
                    } else {
                        stack.pop();
                        if (frame.parent >= 0) {
                            low[frame.parent] = Math.min(
                                low[frame.parent],
                                low[frame.id],
                            );
                            if (low[frame.id] > discovery[frame.parent])
                                bridges.add(frame.edge);
                        }
                    }
                }
            }
        const outgoing = vertices.map(() => []),
            halves = [];
        edges.forEach((edge, id) => {
            if (bridges.has(id)) return;
            const n = halves.length;
            halves.push(
                { from: edge.a, to: edge.b, twin: n + 1 },
                { from: edge.b, to: edge.a, twin: n },
            );
            outgoing[edge.a].push(n);
            outgoing[edge.b].push(n + 1);
        });
        outgoing.forEach((list, id) =>
            list.sort(
                (a, b) =>
                    Math.atan2(
                        vertices[halves[a].to].y - vertices[id].y,
                        vertices[halves[a].to].x - vertices[id].x,
                    ) -
                    Math.atan2(
                        vertices[halves[b].to].y - vertices[id].y,
                        vertices[halves[b].to].x - vertices[id].x,
                    ),
            ),
        );
        const positions = new Int32Array(halves.length);
        outgoing.forEach((list) =>
            list.forEach((id, index) => (positions[id] = index)),
        );
        const seen = new Set(),
            faces = [];
        for (let start = 0; start < halves.length; start++)
            if (!seen.has(start)) {
                let at = start;
                const ids = [];
                do {
                    if (seen.has(at)) break;
                    seen.add(at);
                    const edge = halves[at];
                    ids.push(edge.from);
                    const list = outgoing[edge.to];
                    at =
                        list[
                            (positions[edge.twin] + list.length - 1) %
                                list.length
                        ];
                } while (at !== start);
                if (
                    at !== start ||
                    ids.length < 3 ||
                    new Set(ids).size !== ids.length
                )
                    continue;
                const points = ids.map((id) => ({
                        x: vertices[id].x,
                        y: vertices[id].y,
                    })),
                    area = M.signedArea(points);
                if (Math.abs(area) > tolerance * tolerance)
                    faces.push({ points, area });
            }
        const interior = (p) =>
            !bounds ||
            (p.x > bounds.minX + tolerance &&
                p.x < bounds.maxX - tolerance &&
                p.y > bounds.minY + tolerance &&
                p.y < bounds.maxY - tolerance);
        const containing = faces
            .filter(
                (f) =>
                    f.area > 0 &&
                    f.points.every(interior) &&
                    M.inside(point, f.points),
            )
            .sort((a, b) => a.area - b.area);
        if (!containing.length) return null;
        const chosen = containing[0];
        if (chosen.points.length > limits.facePoints) fail();
        const candidates = faces.filter(
            (f) =>
                f.area < 0 &&
                Math.abs(f.area) < chosen.area - tolerance * tolerance &&
                f.points.every((p) => M.inside(p, chosen.points)),
        );
        const holes = candidates
            .filter(
                (f) =>
                    !candidates.some(
                        (other) =>
                            other !== f &&
                            Math.abs(other.area) > Math.abs(f.area) &&
                            f.points.every((p) => M.inside(p, other.points)),
                    ),
            )
            .map((f) => [...f.points].reverse());
        if (
            chosen.points.length +
                holes.reduce((n, ring) => n + ring.length, 0) >
            limits.facePoints
        )
            fail();
        return { points: chosen.points, holes, stats };
    };
    // A coarse doubling step can jump past a closed room into dense adjacent
    // linework. Refine that failed interval without raising any geometry budget.
    G.findDrawingBoundaryAdaptive = (entities, point, options = {}) => {
        const { radii, refinements = 3, ...queryOptions } = options;
        if (
            !Array.isArray(radii) ||
            !radii.length ||
            radii.length > 8 ||
            radii.some(
                (radius, i) =>
                    !Number.isFinite(radius) ||
                    radius <= 0 ||
                    (i > 0 && radius <= radii[i - 1]),
            ) ||
            !Number.isInteger(refinements) ||
            refinements < 0 ||
            refinements > 3
        ) {
            throw Error("경계 검색 단계가 올바르지 않습니다.");
        }
        let safeRadius = 0,
            attempts = 0;
        const query = (radius) => {
            attempts++;
            const result = G.findDrawingBoundary(entities, point, {
                ...queryOptions,
                bounds: {
                    minX: point.x - radius,
                    minY: point.y - radius,
                    maxX: point.x + radius,
                    maxY: point.y + radius,
                },
            });
            return result && { ...result, search: { attempts, radius } };
        };
        for (const radius of radii) {
            try {
                const result = query(radius);
                if (result) return result;
                safeRadius = radius;
            } catch (error) {
                if (error.code !== "CAD_BOUNDARY_LIMIT") throw error;
                let low = safeRadius,
                    high = radius;
                for (let i = 0; i < refinements; i++) {
                    const middle = (low + high) / 2;
                    try {
                        const result = query(middle);
                        if (result) return result;
                        low = middle;
                    } catch (refinementError) {
                        if (refinementError.code !== "CAD_BOUNDARY_LIMIT")
                            throw refinementError;
                        high = middle;
                    }
                }
                throw error;
            }
        }
        return null;
    };
    root.CADMarkupGeometry = G;
})(typeof window === "undefined" ? globalThis : window);
