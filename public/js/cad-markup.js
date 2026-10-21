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
        if (item.kind === "cloud")
            ps = cloud(ps, item.size).flatMap((p) => [p.a, p.b, p.c]);
        if (["callout", "text"].includes(item.kind))
            ps = [...ps, ...labelCorners(item)];
        if (["area", "cloud", "route"].includes(item.kind) && item.label) {
            const p = item.points[0];
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
        if(item.kind === 'detail') return inside(p,item.points);
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
        ctx.strokeStyle = ctx.fillStyle = item.color;
        const lineScale = options.lineScale || 1;
        ctx.lineWidth = item.width * lineScale;
        ctx.lineJoin = ctx.lineCap = "round";
        ctx.setLineDash([]);
        ctx.globalAlpha = item.opacity;
        ctx.beginPath();
        ps.forEach((p, i) => (i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y)));
        if (item.kind === 'detail') {
            root.CADReview?.drawDetail(ctx,item,screen,scale,options);
        } else if (item.kind === "area") {
            ctx.closePath();
            for (const ring of item.holes || []) {
                ring.map(screen).forEach((p, i) =>
                    i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y),
                );
                ctx.closePath();
            }
            ctx.fill("evenodd");
            if (item.pattern !== "solid") {
                ctx.save();
                ctx.clip("evenodd");
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
            ctx.beginPath();
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
            }
            const box = labelBox(item);
            const b = ps[item.kind === "text" ? 0 : 1],
                style = item.textStyle || {};
            ctx.translate(b.x, b.y);
            const anchor=item.points[item.kind==='text'?0:1],angle=(item.rotation||0)*Math.PI/180,axis=screen({x:anchor.x+Math.cos(angle),y:anchor.y+Math.sin(angle)});
            ctx.rotate(Math.atan2(axis.y-b.y,axis.x-b.x));
            ctx.globalAlpha = 1;
            ctx.fillStyle =
                style.background || (options.paper ? "#ffffff" : "#152338");
            if (style.background !== "none")
                ctx.fillRect(0, 0, box.width * scale, box.height * scale);
            if (style.border !== false)
                ctx.strokeRect(0, 0, box.width * scale, box.height * scale);
            ctx.fillStyle = item.color;
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
            ctx.stroke();
            if (
                item.kind === "route" &&
                ps.length > 1 &&
                item.arrows !== "none"
            ) {
                const arrow = (a, b) => {
                    const angle = Math.atan2(b.y - a.y, b.x - a.x),
                        length = Math.min(14, Math.max(6, size * 0.6));
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
                arrow(ps.at(-2), ps.at(-1));
                if (item.arrows === "both") arrow(ps[1], ps[0]);
            }
        }
        if (["cloud", "area", "route"].includes(item.kind) && item.label) {
            const p = ps[0];
            ctx.globalAlpha = 1;
            ctx.font = `600 ${Math.max(1, size)}px sans-serif`;
            ctx.textBaseline = "bottom";
            ctx.strokeStyle = options.paper ? "#fff" : "#152338";
            ctx.lineWidth = 3;
            ctx.strokeText(item.label, p.x, p.y - size * 0.4);
            ctx.fillText(item.label, p.x, p.y - size * 0.4);
        }
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
        labelCorners,
        bounds,
        hit,
        translate,
        draw,
    };
})(typeof window === "undefined" ? globalThis : window);
