(function (root) {
    "use strict";
    const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    function segmentDistance(p, a, b) {
        const dx = b.x - a.x,
            dy = b.y - a.y,
            d = dx * dx + dy * dy;
        const t = d
            ? Math.max(
                  0,
                  Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / d),
              )
            : 0;
        return distance(p, { x: a.x + t * dx, y: a.y + t * dy });
    }
    function inside(p, points) {
        let result = false;
        for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
            const a = points[i],
                b = points[j];
            if (segmentDistance(p, a, b) < 1e-9) return true;
            if (
                a.y > p.y !== b.y > p.y &&
                p.x < ((b.x - a.x) * (p.y - a.y)) / (b.y - a.y) + a.x
            )
                result = !result;
        }
        return result;
    }
    function signedArea(points) {
        // Translate before multiplying to retain precision at large site coordinates.
        const o = points[0];
        return (
            points.reduce((sum, a, i) => {
                const b = points[(i + 1) % points.length];
                return (
                    sum + (a.x - o.x) * (b.y - o.y) - (b.x - o.x) * (a.y - o.y)
                );
            }, 0) / 2
        );
    }
    function validPolygon(points) {
        if (points.length < 3 || Math.abs(signedArea(points)) < 1e-12)
            return false;
        const cross = (a, b, c) =>
            (b.x - a.x) * (c.y - a.y) - (b.y - a.y) * (c.x - a.x);
        for (let i = 0; i < points.length; i++) {
            const a = points[i],
                b = points[(i + 1) % points.length];
            if (distance(a, b) < 1e-10) return false;
            for (let j = i + 2; j < points.length; j++) {
                if (i === 0 && j === points.length - 1) continue;
                const c = points[j],
                    d = points[(j + 1) % points.length];
                if (
                    cross(a, b, c) * cross(a, b, d) <= 0 &&
                    cross(c, d, a) * cross(c, d, b) <= 0 &&
                    Math.max(Math.min(a.x, b.x), Math.min(c.x, d.x)) <=
                        Math.min(Math.max(a.x, b.x), Math.max(c.x, d.x)) &&
                    Math.max(Math.min(a.y, b.y), Math.min(c.y, d.y)) <=
                        Math.min(Math.max(a.y, b.y), Math.max(c.y, d.y))
                )
                    return false;
            }
        }
        return true;
    }
    const rect = (a, b) => [a, { x: b.x, y: a.y }, b, { x: a.x, y: b.y }];
    // An ellipse stores an affine frame, never hundreds of editable polygon vertices.
    function ellipseFrame(points) {
        const [a, b, c, d] = points;
        if (!d) return null;
        const u = { x: (b.x - a.x) / 2, y: (b.y - a.y) / 2 },
            v = { x: (d.x - a.x) / 2, y: (d.y - a.y) / 2 };
        return {
            center: { x: a.x + u.x + v.x, y: a.y + u.y + v.y },
            u,
            v,
            determinant: u.x * v.y - u.y * v.x,
            residual: Math.hypot(
                c.x - b.x - (d.x - a.x),
                c.y - b.y - (d.y - a.y),
            ),
        };
    }
    function ellipsePoints(item, count = 128) {
        const f = ellipseFrame(item.points);
        if (!f) return [];
        return Array.from({ length: count }, (_, i) => {
            const t = (i * 2 * Math.PI) / count;
            return {
                x: f.center.x + f.u.x * Math.cos(t) + f.v.x * Math.sin(t),
                y: f.center.y + f.u.y * Math.cos(t) + f.v.y * Math.sin(t),
            };
        });
    }
    const curved = (item) => ["arc", "curve"].includes(item.kind);
    const filled = (item) =>
        ["area", "ellipse"].includes(item.kind) ||
        (item.kind === "arc" && item.arc?.pie);
    function curvePoint(item, t) {
        if (item.kind === "arc") {
            const f = ellipseFrame(item.points),
                a = item.arc.start + item.arc.sweep * t;
            return {
                x: f.center.x + f.u.x * Math.cos(a) + f.v.x * Math.sin(a),
                y: f.center.y + f.u.y * Math.cos(a) + f.v.y * Math.sin(a),
            };
        }
        const [a, b, c, d] = item.points,
            s = 1 - t;
        return {
            x:
                s * s * s * a.x +
                3 * s * s * t * b.x +
                3 * s * t * t * c.x +
                t * t * t * d.x,
            y:
                s * s * s * a.y +
                3 * s * s * t * b.y +
                3 * s * t * t * c.y +
                t * t * t * d.y,
        };
    }
    function curveTangent(item, t) {
        if (item.kind === "arc") {
            const f = ellipseFrame(item.points),
                a = item.arc.start + item.arc.sweep * t;
            return {
                x:
                    (-f.u.x * Math.sin(a) + f.v.x * Math.cos(a)) *
                    item.arc.sweep,
                y:
                    (-f.u.y * Math.sin(a) + f.v.y * Math.cos(a)) *
                    item.arc.sweep,
            };
        }
        const [a, b, c, d] = item.points,
            s = 1 - t;
        return {
            x:
                3 * s * s * (b.x - a.x) +
                6 * s * t * (c.x - b.x) +
                3 * t * t * (d.x - c.x),
            y:
                3 * s * s * (b.y - a.y) +
                6 * s * t * (c.y - b.y) +
                3 * t * t * (d.y - c.y),
        };
    }
    function curvePoints(item, count = 128) {
        return Array.from({ length: count + 1 }, (_, i) =>
            curvePoint(item, i / count),
        );
    }
    function endpointDirection(item, end) {
        if (item.kind === "arc") return curveTangent(item, end ? 1 : 0);
        const points = end ? [...item.points].reverse() : item.points,
            a = points[0];
        const b = points.slice(1).find((p) => distance(p, a) > 1e-12) || a;
        const sign = end ? -1 : 1;
        return { x: (b.x - a.x) * sign, y: (b.y - a.y) * sign };
    }
    // Isolate every stationary point instead of picking a fixed polyline. A
    // fixed segment count misses the rendered curve when zoomed in on big plans.
    function polynomialRoots(coefficients, low, high) {
        const scale = Math.max(...coefficients.map(Math.abs));
        if (!scale) return [];
        const c = coefficients.map((v) => v / scale);
        while (c.length > 1 && Math.abs(c.at(-1)) < 1e-14) c.pop();
        if (c.length === 1) return [];
        if (c.length === 2) {
            const t = -c[0] / c[1];
            return t >= low && t <= high ? [t] : [];
        }
        const evaluate = (t) => c.reduceRight((sum, v) => sum * t + v, 0);
        const stops = [
            low,
            ...polynomialRoots(
                c.slice(1).map((v, i) => v * (i + 1)),
                low,
                high,
            ),
            high,
        ];
        const roots = stops.filter((t) => Math.abs(evaluate(t)) < 1e-12);
        for (let i = 1; i < stops.length; i++) {
            let a = stops[i - 1],
                b = stops[i],
                fa = evaluate(a);
            if (fa * evaluate(b) >= 0) continue;
            for (let step = 0; step < 48; step++) {
                const mid = (a + b) / 2,
                    fm = evaluate(mid);
                if (fa * fm <= 0) b = mid;
                else {
                    a = mid;
                    fa = fm;
                }
            }
            roots.push((a + b) / 2);
        }
        return roots
            .sort((a, b) => a - b)
            .filter((t, i, all) => !i || t - all[i - 1] > 1e-12);
    }
    function curveDistance(item, p) {
        const dot = (a, b) => a.x * b.x + a.y * b.y,
            ts = [0, 1];
        if (item.kind === "curve") {
            const [q, r, s, v] = item.points.map((a) => ({
                x: a.x - p.x,
                y: a.y - p.y,
            }));
            const a = {},
                b = {},
                c = {};
            for (const k of ["x", "y"]) {
                a[k] = -q[k] + 3 * r[k] - 3 * s[k] + v[k];
                b[k] = 3 * q[k] - 6 * r[k] + 3 * s[k];
                c[k] = 3 * (r[k] - q[k]);
            }
            ts.push(
                ...polynomialRoots(
                    [
                        dot(q, c),
                        dot(c, c) + 2 * dot(q, b),
                        3 * dot(q, a) + 3 * dot(c, b),
                        4 * dot(c, a) + 2 * dot(b, b),
                        5 * dot(a, b),
                        3 * dot(a, a),
                    ],
                    0,
                    1,
                ),
            );
        } else {
            const f = ellipseFrame(item.points),
                q = { x: f.center.x - p.x, y: f.center.y - p.y };
            const C = dot(f.v, f.v) - dot(f.u, f.u),
                D = dot(f.u, f.v);
            // Two half-angle charts cover the ellipse with bounded [-1,1] roots.
            for (const sign of [1, -1]) {
                const A = sign * dot(q, f.v),
                    B = -sign * dot(q, f.u);
                for (const r of polynomialRoots(
                    [A + D, 2 * (B + C), -6 * D, 2 * (B - C), D - A],
                    -1,
                    1,
                )) {
                    const angle = 2 * Math.atan(r) + (sign < 0 ? Math.PI : 0);
                    for (let n = -3; n <= 3; n++) {
                        const t =
                            (angle + 2 * n * Math.PI - item.arc.start) /
                            item.arc.sweep;
                        if (t > 0 && t < 1) ts.push(t);
                    }
                }
            }
        }
        return Math.min(...ts.map((t) => distance(p, curvePoint(item, t))));
    }
    function curveExtrema(item) {
        const ts = [0, 1];
        if (item.kind === "arc") {
            const f = ellipseFrame(item.points),
                { start, sweep } = item.arc;
            for (const k of ["x", "y"]) {
                const a = Math.atan2(f.v[k], f.u[k]);
                for (let n = -4; n <= 4; n++) {
                    const t = (a + n * Math.PI - start) / sweep;
                    if (t > 0 && t < 1) ts.push(t);
                }
            }
        } else {
            for (const k of ["x", "y"]) {
                const [p, q, r, s] = item.points.map((p) => p[k]),
                    a = -p + 3 * q - 3 * r + s,
                    b = 2 * (p - 2 * q + r),
                    c = q - p;
                const roots =
                    Math.abs(a) < 1e-14
                        ? Math.abs(b) < 1e-14
                            ? []
                            : [-c / b]
                        : b * b - 4 * a * c < 0
                          ? []
                          : [
                                (-b + Math.sqrt(b * b - 4 * a * c)) / (2 * a),
                                (-b - Math.sqrt(b * b - 4 * a * c)) / (2 * a),
                            ];
                ts.push(...roots.filter((t) => t > 0 && t < 1));
            }
        }
        const ps = ts.map((t) => curvePoint(item, t));
        if (item.kind === "arc" && item.arc.pie)
            ps.push(ellipseFrame(item.points).center);
        return ps;
    }
    function cloud(points, size) {
        const lengths = points.map((p, i) =>
            distance(p, points[(i + 1) % points.length]),
        );
        const perimeter = lengths.reduce((a, b) => a + b, 0);
        if (!perimeter) return [];
        const count = Math.min(
            512,
            Math.max(12, Math.ceil(perimeter / Math.max(size * 1.5, 1e-9))),
        );
        const step = perimeter / count,
            direction = Math.sign(signedArea(points)) || 1;
        const samples = [];
        let edge = 0,
            base = 0;
        for (let n = 0; n < count; n++) {
            const at = n * step;
            while (edge < lengths.length - 1 && base + lengths[edge] < at)
                base += lengths[edge++];
            const a = points[edge],
                b = points[(edge + 1) % points.length],
                t = lengths[edge] ? (at - base) / lengths[edge] : 0;
            samples.push({
                x: a.x + (b.x - a.x) * t,
                y: a.y + (b.y - a.y) * t,
            });
        }
        return samples.map((a, i) => {
            const b = samples[(i + 1) % samples.length];
            return {
                a,
                b,
                c: {
                    x: (a.x + b.x) / 2 + (b.y - a.y) * 0.65 * direction,
                    y: (a.y + b.y) / 2 - (b.x - a.x) * 0.65 * direction,
                },
            };
        });
    }
    function lines(text, maxWidth = 28) {
        return String(text || "")
            .split("\n")
            .flatMap((line) => {
                const chars = Array.from(line),
                    result = [];
                let current = "",
                    width = 0;
                for (const char of chars) {
                    const w = char.charCodeAt(0) > 255 ? 1 : 0.65;
                    if (current && width + w > maxWidth) {
                        result.push(current);
                        current = "";
                        width = 0;
                    }
                    current += char;
                    width += w;
                }
                result.push(current);
                return result;
            });
    }
    let metricsContext;
    function textWidth(text, style = {}) {
        if (!metricsContext && root.document)
            metricsContext = root.document
                .createElement("canvas")
                .getContext("2d");
        if (metricsContext) {
            metricsContext.font = `${style.italic ? "italic " : ""}${style.bold ? "700 " : "400 "}100px ${{ sans: "sans-serif", serif: "serif", mono: "monospace" }[style.font] || "sans-serif"}`;
            return metricsContext.measureText(text).width / 100;
        }
        return Array.from(text).reduce(
            (n, c) => n + (c.charCodeAt(0) > 255 ? 1 : 0.65),
            0,
        );
    }
    function labelBox(item) {
        const style = item.textStyle || {},
            limit = style.boxWidth
                ? Math.max(0.01, style.boxWidth / item.size - 1)
                : 28,
            ls = String(item.text || "")
                .split("\n")
                .flatMap((line) => {
                    const wrapped = [];
                    let part = "";
                    for (const char of Array.from(line)) {
                        if (part && textWidth(part + char, style) > limit) {
                            wrapped.push(part);
                            part = "";
                        }
                        part += char;
                    }
                    wrapped.push(part);
                    return wrapped;
                }),
            p = item.points[item.kind === "text" ? 0 : 1],
            s = item.size;
        const width =
            Math.max(3, ...ls.map((line) => textWidth(line, style))) * s + s;
        return {
            x: p.x,
            y: p.y,
            width: style.boxWidth || width,
            height: (ls.length * (style.lineHeight || 1.35) + 0.8) * s,
            lines: ls,
        };
    }
    function labelCorners(item) {
        const b = labelBox(item),
            origin = { x: b.x, y: b.y },
            angle = ((item.rotation || 0) * Math.PI) / 180;
        return rect(origin, { x: b.x + b.width, y: b.y - b.height }).map(
            (p) => ({
                x:
                    origin.x +
                    (p.x - origin.x) * Math.cos(angle) -
                    (p.y - origin.y) * Math.sin(angle),
                y:
                    origin.y +
                    (p.x - origin.x) * Math.sin(angle) +
                    (p.y - origin.y) * Math.cos(angle),
            }),
        );
    }
    function bounds(item) {
        let ps = item.points;
        if (curved(item)) ps = curveExtrema(item);
        if (item.kind === "ellipse") {
            const f = ellipseFrame(ps),
                rx = Math.hypot(f.u.x, f.v.x),
                ry = Math.hypot(f.u.y, f.v.y);
            ps = [
                { x: f.center.x - rx, y: f.center.y - ry },
                { x: f.center.x + rx, y: f.center.y + ry },
            ];
        }
        if (item.kind === "cloud")
            ps = cloud(ps, item.size).flatMap((p) => [p.a, p.b, p.c]);
        if (["callout", "text"].includes(item.kind))
            ps = [...ps, ...labelCorners(item)];
        if (
            ["area", "ellipse", "arc", "curve", "cloud", "route"].includes(
                item.kind,
            ) &&
            item.label
        ) {
            const p = curved(item) ? curvePoint(item, 0) : item.points[0];
            ps = [
                ...ps,
                p,
                {
                    x: p.x + textWidth(item.label, { bold: true }) * item.size,
                    y: p.y + item.size * 1.4,
                },
            ];
        }
        const padding = item.kind === "pin" ? item.size : 0;
        return {
            minX: Math.min(...ps.map((p) => p.x)) - padding,
            minY: Math.min(...ps.map((p) => p.y)) - padding,
            maxX: Math.max(...ps.map((p) => p.x)) + padding,
            maxY: Math.max(...ps.map((p) => p.y)) + padding,
        };
    }
    function hit(item, p, tolerance) {
        if (curved(item)) {
            const b = bounds(item);
            if (
                p.x < b.minX - tolerance ||
                p.x > b.maxX + tolerance ||
                p.y < b.minY - tolerance ||
                p.y > b.maxY + tolerance
            )
                return false;
            if (item.kind === "arc" && item.arc.pie) {
                const f = ellipseFrame(item.points),
                    dx = p.x - f.center.x,
                    dy = p.y - f.center.y;
                const x = (dx * f.v.y - dy * f.v.x) / f.determinant,
                    y = (f.u.x * dy - f.u.y * dx) / f.determinant;
                const turn = 2 * Math.PI,
                    relative =
                        (((Math.sign(item.arc.sweep) *
                            (Math.atan2(y, x) - item.arc.start)) %
                            turn) +
                            turn) %
                        turn;
                if (
                    x * x + y * y <= 1 + 1e-12 &&
                    relative <= Math.abs(item.arc.sweep) + 1e-12
                )
                    return true;
                if (
                    [0, 1].some(
                        (t) =>
                            segmentDistance(p, f.center, curvePoint(item, t)) <=
                            tolerance,
                    )
                )
                    return true;
            }
            return curveDistance(item, p) <= tolerance;
        }
        if (item.kind === "ellipse") {
            const f = ellipseFrame(item.points);
            if (!f || !f.determinant) return false;
            const dx = p.x - f.center.x,
                dy = p.y - f.center.y,
                x = (dx * f.v.y - dy * f.v.x) / f.determinant,
                y = (f.u.x * dy - f.u.y * dx) / f.determinant;
            if (x * x + y * y <= 1 + 1e-12) return true;
            const outline = ellipsePoints(item);
            return outline.some(
                (a, i) =>
                    segmentDistance(p, a, outline[(i + 1) % outline.length]) <=
                    tolerance,
            );
        }
        if (item.kind === "detail") return inside(p, item.points);
        if (item.kind === "pin")
            return distance(p, item.points[0]) <= item.size + tolerance;
        if (
            ["callout", "text"].includes(item.kind) &&
            inside(p, labelCorners(item))
        )
            return true;
        if (
            ["area", "cloud"].includes(item.kind) &&
            inside(p, item.points) &&
            !(item.holes || []).some((ring) => inside(p, ring))
        )
            return true;
        const ps = ["area", "cloud"].includes(item.kind)
            ? [...item.points, item.points[0]]
            : item.points;
        return (
            (item.holes || []).some((ring) =>
                ring.some(
                    (a, i) =>
                        segmentDistance(p, a, ring[(i + 1) % ring.length]) <=
                        tolerance,
                ),
            ) ||
            ps
                .slice(1)
                .some(
                    (q, i) =>
                        segmentDistance(p, ps[i], q) <=
                        tolerance + (item.kind === "cloud" ? item.size : 0),
                )
        );
    }
    function translate(item, dx, dy) {
        return {
            ...item,
            points: item.points.map((p) => ({ x: p.x + dx, y: p.y + dy })),
            ...(item.holes
                ? {
                      holes: item.holes.map((ring) =>
                          ring.map((p) => ({ x: p.x + dx, y: p.y + dy })),
                      ),
                  }
                : {}),
        };
    }
    function draw(ctx, item, screen, scale, options = {}) {
        const ps = item.points.map(screen),
            size = item.size * scale;
        ctx.save();
        const color =
            !options.paper && options.color
                ? options.color(item.color)
                : item.color;
        ctx.strokeStyle = item.kind === "highlight" ? item.color : color;
        ctx.fillStyle =
            filled(item) || ["pin", "highlight"].includes(item.kind)
                ? item.fillColor && item.fillColor !== "none"
                    ? item.fillColor
                    : item.color
                : color;
        const lineScale = options.lineScale || 1;
        ctx.lineWidth = item.width * lineScale;
        ctx.lineJoin = ctx.lineCap = "round";
        const dash = {
            solid: [],
            dash: [8, 5],
            dot: [1, 4],
            dashdot: [8, 4, 1, 4],
        }[item.dash || "solid"];
        ctx.setLineDash(
            dash.map((n) => n * Math.max(1, item.width / 2) * lineScale),
        );
        ctx.globalAlpha = item.opacity;
        ctx.beginPath();
        ps.forEach((p, i) => (i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y)));
        if (item.kind === "detail") {
            root.CADReview?.drawDetail(ctx, item, screen, scale, options);
        } else if (filled(item)) {
            const outline = () => {
                ctx.beginPath();
                if (["ellipse", "arc"].includes(item.kind)) {
                    const f = ellipseFrame(ps);
                    if (!f || Math.abs(f.determinant) < 1e-15) return;
                    ctx.save();
                    ctx.transform(
                        f.u.x,
                        f.u.y,
                        f.v.x,
                        f.v.y,
                        f.center.x,
                        f.center.y,
                    );
                    if (item.kind === "arc") {
                        ctx.moveTo(0, 0);
                        ctx.lineTo(
                            Math.cos(item.arc.start),
                            Math.sin(item.arc.start),
                        );
                        ctx.arc(
                            0,
                            0,
                            1,
                            item.arc.start,
                            item.arc.start + item.arc.sweep,
                            item.arc.sweep < 0,
                        );
                    } else ctx.arc(0, 0, 1, 0, Math.PI * 2);
                    ctx.restore();
                } else
                    ps.forEach((p, i) =>
                        i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y),
                    );
                ctx.closePath();
                for (const ring of item.holes || []) {
                    ring.map(screen).forEach((p, i) =>
                        i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y),
                    );
                    ctx.closePath();
                }
            };
            outline();
            ctx.globalAlpha = item.fillOpacity ?? item.opacity;
            if (item.fillColor !== "none") ctx.fill("evenodd");
            if (item.pattern !== "solid" && item.fillColor !== "none") {
                ctx.save();
                ctx.clip("evenodd");
                ctx.globalAlpha = Math.min(
                    1,
                    (item.fillOpacity ?? item.opacity) + 0.35,
                );
                ctx.setLineDash([]);
                const b = bounds(item),
                    a = screen({ x: b.minX, y: b.maxY }),
                    z = screen({ x: b.maxX, y: b.minY });
                // Clip to the viewport before iterating: extreme zoom never creates unbounded hatch work.
                const w = ctx.canvas.width,
                    h = ctx.canvas.height,
                    left = Math.max(-40, a.x),
                    top = Math.max(-40, a.y),
                    right = Math.min(w + 40, z.x),
                    bottom = Math.min(h + 40, z.y);
                const step = Math.max(8, Math.min(32, size));
                ctx.lineWidth = lineScale;
                ctx.beginPath();
                for (let x = left - (bottom - top); x < right; x += step) {
                    ctx.moveTo(x, top);
                    ctx.lineTo(x + bottom - top, bottom);
                    if (item.pattern === "cross") {
                        ctx.moveTo(x, bottom);
                        ctx.lineTo(x + bottom - top, top);
                    }
                }
                ctx.stroke();
                ctx.restore();
            }
            ctx.globalAlpha = Math.min(1, item.opacity + 0.5);
            outline();
            ctx.stroke();
        } else if (item.kind === "cloud") {
            ctx.beginPath();
            cloud(item.points, item.size).forEach((part, i) => {
                const a = screen(part.a),
                    b = screen(part.b),
                    c = screen(part.c);
                if (!i) ctx.moveTo(a.x, a.y);
                ctx.quadraticCurveTo(c.x, c.y, b.x, b.y);
            });
            ctx.closePath();
            ctx.stroke();
        } else if (item.kind === "pin") {
            const p = ps[0];
            ctx.beginPath();
            ctx.arc(p.x, p.y, size, 0, Math.PI * 2);
            ctx.fill();
            ctx.globalAlpha = 1;
            ctx.strokeStyle = "#fff";
            ctx.lineWidth = 1.5 * lineScale;
            ctx.stroke();
            ctx.fillStyle = "#fff";
            ctx.font = `600 ${Math.max(1, size * Math.min(1.05, 2 / Math.max(1, Array.from(item.text).length)))}px sans-serif`;
            ctx.textAlign = "center";
            ctx.textBaseline = "middle";
            ctx.fillText(item.text, p.x, p.y);
        } else if (["callout", "text"].includes(item.kind)) {
            if (item.kind === "callout") {
                ctx.stroke();
                const [a, b] = ps,
                    angle = Math.atan2(a.y - b.y, a.x - b.x),
                    arrow = Math.min(14 * lineScale, size * 0.65);
                ctx.beginPath();
                ctx.moveTo(a.x, a.y);
                ctx.lineTo(
                    a.x - Math.cos(angle - 0.45) * arrow,
                    a.y - Math.sin(angle - 0.45) * arrow,
                );
                ctx.lineTo(
                    a.x - Math.cos(angle + 0.45) * arrow,
                    a.y - Math.sin(angle + 0.45) * arrow,
                );
                ctx.closePath();
                ctx.fill();
            }
            const box = labelBox(item);
            const b = ps[item.kind === "text" ? 0 : 1],
                style = item.textStyle || {};
            ctx.translate(b.x, b.y);
            const anchor = item.points[item.kind === "text" ? 0 : 1],
                angle = ((item.rotation || 0) * Math.PI) / 180,
                axis = screen({
                    x: anchor.x + Math.cos(angle),
                    y: anchor.y + Math.sin(angle),
                });
            ctx.rotate(Math.atan2(axis.y - b.y, axis.x - b.x));
            ctx.globalAlpha = 1;
            ctx.fillStyle =
                style.background || (options.paper ? "#ffffff" : "#152338");
            if (style.background !== "none")
                ctx.fillRect(0, 0, box.width * scale, box.height * scale);
            if (style.border !== false)
                ctx.strokeRect(0, 0, box.width * scale, box.height * scale);
            ctx.fillStyle = style.background === "none" ? color : item.color;
            ctx.textBaseline = "top";
            ctx.font = `${style.italic ? "italic " : ""}${style.bold ? "700 " : "400 "}${Math.max(1, size)}px ${{ sans: "sans-serif", serif: "serif", mono: "monospace" }[style.font] || "sans-serif"}`;
            ctx.textAlign = style.align || "left";
            const x =
                style.align === "center"
                    ? (box.width * scale) / 2
                    : style.align === "right"
                      ? box.width * scale - size * 0.5
                      : size * 0.5;
            box.lines.forEach((line, i) =>
                ctx.fillText(
                    line,
                    x,
                    size * 0.4 + i * size * (style.lineHeight || 1.35),
                ),
            );
        } else {
            if (curved(item)) {
                ctx.beginPath();
                if (item.kind === "curve") {
                    ctx.moveTo(ps[0].x, ps[0].y);
                    ctx.bezierCurveTo(
                        ps[1].x,
                        ps[1].y,
                        ps[2].x,
                        ps[2].y,
                        ps[3].x,
                        ps[3].y,
                    );
                } else {
                    const f = ellipseFrame(ps);
                    ctx.save();
                    ctx.transform(
                        f.u.x,
                        f.u.y,
                        f.v.x,
                        f.v.y,
                        f.center.x,
                        f.center.y,
                    );
                    ctx.arc(
                        0,
                        0,
                        1,
                        item.arc.start,
                        item.arc.start + item.arc.sweep,
                        item.arc.sweep < 0,
                    );
                    ctx.restore();
                }
            }
            ctx.stroke();
        }
        if (
            (item.kind === "route" || curved(item)) &&
            ps.length > 1 &&
            item.arrows &&
            item.arrows !== "none"
        ) {
            ctx.fillStyle = color;
            ctx.globalAlpha = item.opacity;
            const arrow = (a, b) => {
                const angle = Math.atan2(b.y - a.y, b.x - a.x),
                    length = Math.min(
                        14 * lineScale,
                        Math.max(6 * lineScale, size * 0.6),
                    );
                ctx.beginPath();
                ctx.moveTo(b.x, b.y);
                ctx.lineTo(
                    b.x - Math.cos(angle - 0.45) * length,
                    b.y - Math.sin(angle - 0.45) * length,
                );
                ctx.lineTo(
                    b.x - Math.cos(angle + 0.45) * length,
                    b.y - Math.sin(angle + 0.45) * length,
                );
                ctx.closePath();
                ctx.fill();
            };
            if (curved(item)) {
                const start = curvePoint(item, 0),
                    end = curvePoint(item, 1),
                    u = endpointDirection(item, false),
                    v = endpointDirection(item, true);
                arrow(screen({ x: end.x - v.x, y: end.y - v.y }), screen(end));
                if (item.arrows === "both")
                    arrow(
                        screen({ x: start.x + u.x, y: start.y + u.y }),
                        screen(start),
                    );
            } else {
                arrow(ps.at(-2), ps.at(-1));
                if (item.arrows === "both") arrow(ps[1], ps[0]);
            }
        }
        if (
            ["cloud", "area", "ellipse", "arc", "curve", "route"].includes(
                item.kind,
            ) &&
            item.label
        ) {
            const p = curved(item) ? screen(curvePoint(item, 0)) : ps[0];
            ctx.globalAlpha = 1;
            ctx.font = `600 ${Math.max(1, size)}px sans-serif`;
            ctx.textBaseline = "bottom";
            ctx.strokeStyle =
                options.paper || options.background === "white"
                    ? "#fff"
                    : "#152338";
            ctx.fillStyle = color;
            ctx.lineWidth = 3;
            ctx.setLineDash([]);
            ctx.strokeText(item.label, p.x, p.y - size * 0.4);
            ctx.fillText(item.label, p.x, p.y - size * 0.4);
        }
        ctx.restore();
    }
    root.CADMarkup = {
        curved,
        filled,
        curvePoint,
        curveTangent,
        curvePoints,
        curveExtrema,
        distance,
        segmentDistance,
        inside,
        signedArea,
        validPolygon,
        rect,
        ellipseFrame,
        ellipsePoints,
        cloud,
        lines,
        labelBox,
        labelCorners,
        bounds,
        hit,
        translate,
        draw,
    };
})(typeof window === "undefined" ? globalThis : window);
