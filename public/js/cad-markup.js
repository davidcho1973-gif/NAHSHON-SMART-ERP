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
    function lines(text) {
        return String(text || "")
            .split("\n")
            .flatMap((line) => {
                const chars = Array.from(line),
                    result = [];
                do {
                    result.push(chars.splice(0, 28).join(""));
                } while (chars.length);
                return result;
            });
    }
    function labelBox(item) {
        const ls = lines(item.text),
            p = item.points[1],
            s = item.size;
        const width =
            Math.max(
                3,
                ...ls.map((line) =>
                    Array.from(line).reduce(
                        (n, c) => n + (c.charCodeAt(0) > 255 ? 1 : 0.65),
                        0,
                    ),
                ),
            ) *
                s +
            s;
        return { x: p.x, y: p.y, width, height: (ls.length * 1.35 + 0.8) * s };
    }
    function bounds(item) {
        let ps = item.points;
        if (item.kind === "cloud")
            ps = cloud(ps, item.size).flatMap((p) => [p.a, p.b, p.c]);
        if (item.kind === "callout") {
            const b = labelBox(item);
            ps = [...ps, { x: b.x + b.width, y: b.y - b.height }];
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
        if (item.kind === "pin")
            return distance(p, item.points[0]) <= item.size + tolerance;
        if (item.kind === "callout") {
            const b = labelBox(item);
            if (
                p.x >= b.x &&
                p.x <= b.x + b.width &&
                p.y <= b.y &&
                p.y >= b.y - b.height
            )
                return true;
        }
        if (["area", "cloud"].includes(item.kind) && inside(p, item.points))
            return true;
        const ps = ["area", "cloud"].includes(item.kind)
            ? [...item.points, item.points[0]]
            : item.points;
        return ps
            .slice(1)
            .some(
                (q, i) =>
                    segmentDistance(p, ps[i], q) <=
                    tolerance + (item.kind === "cloud" ? item.size : 0),
            );
    }
    function translate(item, dx, dy) {
        return {
            ...item,
            points: item.points.map((p) => ({ x: p.x + dx, y: p.y + dy })),
        };
    }
    function draw(ctx, item, screen, scale, options = {}) {
        const ps = item.points.map(screen),
            size = item.size * scale;
        ctx.save();
        ctx.strokeStyle = ctx.fillStyle = item.color;
        ctx.lineWidth = item.width;
        ctx.lineJoin = ctx.lineCap = "round";
        ctx.setLineDash([]);
        ctx.globalAlpha = item.opacity;
        ctx.beginPath();
        ps.forEach((p, i) => (i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y)));
        if (item.kind === "area") {
            ctx.closePath();
            ctx.fill();
            if (item.pattern !== "solid") {
                ctx.save();
                ctx.clip();
                ctx.globalAlpha = Math.min(1, item.opacity + 0.35);
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
                ctx.lineWidth = 1;
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
            ctx.beginPath();
            ps.forEach((p, i) =>
                i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y),
            );
            ctx.closePath();
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
            ctx.lineWidth = 1.5;
            ctx.stroke();
            ctx.fillStyle = "#fff";
            ctx.font = `600 ${Math.max(1, size * Math.min(1.05, 2 / Math.max(1, Array.from(item.text).length)))}px sans-serif`;
            ctx.textAlign = "center";
            ctx.textBaseline = "middle";
            ctx.fillText(item.text, p.x, p.y);
        } else if (item.kind === "callout") {
            ctx.stroke();
            const [a, b] = ps,
                angle = Math.atan2(a.y - b.y, a.x - b.x),
                arrow = Math.min(14, size * 0.65);
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
            const box = labelBox(item);
            ctx.globalAlpha = 1;
            ctx.fillStyle = options.paper ? "#ffffff" : "#152338";
            ctx.fillRect(b.x, b.y, box.width * scale, box.height * scale);
            ctx.strokeRect(b.x, b.y, box.width * scale, box.height * scale);
            ctx.fillStyle = item.color;
            ctx.textBaseline = "top";
            ctx.font = `${Math.max(1, size)}px sans-serif`;
            lines(item.text).forEach((line, i) =>
                ctx.fillText(
                    line,
                    b.x + size * 0.5,
                    b.y + size * 0.4 + i * size * 1.35,
                ),
            );
        } else ctx.stroke();
        ctx.restore();
    }
    root.CADMarkup = {
        distance,
        segmentDistance,
        inside,
        signedArea,
        validPolygon,
        rect,
        cloud,
        lines,
        labelBox,
        bounds,
        hit,
        translate,
        draw,
    };
})(typeof window === "undefined" ? globalThis : window);
