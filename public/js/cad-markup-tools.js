(function () {
    "use strict";
    const M = window.CADMarkup,
        G = window.CADMarkupGeometry,
        $ = (id) => document.getElementById(id);
    const names = {
        arc2: "두 끝점 호",
        arc3: "세 점 호",
        arc: "중심 기준 호",
        pie: "부채꼴",
        curve: "곡선 화살표",
        cubic: "자유 곡선",
        rotbox: "회전 사각형",
        rounded: "둥근 사각형",
        capsule: "캡슐형",
        split: "영역 나누기",
        hole: "내부 비우기",
        trim: "직선 자르기",
        extend: "직선 연장",
        select: "선택 / 이동",
        boundary: "닫힌 경계 색칠",
        area: "자유 영역",
        box: "사각 영역",
        circle: "원",
        ellipse: "타원",
        triangle: "삼각형",
        diamond: "마름모",
        polygon: "다각형",
        star: "별표",
        line: "직선",
        arrow: "화살표",
        doublearrow: "양방향 화살표",
        cloud: "변경 구름",
        callout: "연결 메모",
        pen: "자유 펜",
        highlight: "형광펜",
        pin: "번호 / 기호",
        freecloud: "자유 경계 구름",
        text: "문자 상자",
        route: "배관 / 덕트 경로",
        eraser: "부분 지우개",
        lasso: "올가미 선택",
        calibrate: "두 점 축척 보정",
        region: "출력 범위 지정",
    };
    const hints = {
        arc2: "시작점 → 끝점 → 휘어질 위치 · 마지막 단계에서 돌출 높이 입력",
        arc3: "시작점 → 지나갈 점 → 끝점",
        arc: "중심 → 시작점 → 끝 방향 · 마지막 단계에서 회전각 입력",
        pie: "중심 → 시작점 → 끝 방향 · 시계 방향은 속성에서 변경",
        curve: "시작점 → 끝점 → 곡률 조절점 · 손잡이로 다시 수정",
        cubic: "시작점 → 첫 손잡이 → 두 번째 손잡이 → 끝점",
        rotbox: "첫 모서리 → 첫 변의 끝 → 폭",
        rounded: "대각선 드래그 또는 두 모서리 클릭 · 반지름은 속성에서 지정",
        capsule: "대각선 드래그 또는 두 모서리 클릭",
        split: "영역 하나 선택 → 나눌 선의 두 점 지정 · 구멍과 서식 유지",
        hole: "영역 하나 선택 → 내부 경계 클릭 → Enter",
        trim: "직선 표시 선택 → 지울 부분 클릭 · 교차하는 직선·영역 경계 기준",
        extend: "직선 표시 선택 → 늘릴 끝 쪽 클릭 · 처음 만나는 직선·영역 경계까지",
        select: "클릭하여 선택 · Shift로 추가 · 드래그 이동 · 꼭짓점으로 모양 수정",
        boundary:
            "연결된 원본 직선 경계 안쪽 클릭 · 실제 틈이나 곡선·이미지는 자유 영역 사용",
        area: "경계를 차례로 클릭 · 첫 점 클릭 또는 Enter로 완성 · Esc 취소",
        box: "대각선으로 드래그하여 영역 지정",
        circle: "대각선 드래그 · 원 크기 지정 · 네 모서리로 크기 조절",
        ellipse: "대각선 드래그 · 가로·세로 크기 지정",
        triangle: "대각선 드래그 · 삼각형의 범위 지정",
        diamond: "대각선 드래그 · 마름모의 범위 지정",
        polygon: "대각선 드래그 · 꼭짓점 수는 속성의 선·채우기에서 지정",
        star: "대각선 드래그 · 주의할 위치를 별표로 강조",
        line: "시작점에서 끝점까지 드래그 · Shift로 45° 각도 맞춤",
        arrow: "시작점에서 지시할 위치까지 드래그 · Shift로 45° 각도 맞춤",
        doublearrow: "두 지점을 연결하도록 드래그 · Shift로 45° 각도 맞춤",
        cloud: "변경된 부분을 감싸도록 드래그",
        callout: "지시할 위치에서 메모를 놓을 위치까지 드래그",
        pen: "마우스를 누른 채 그리기 · Esc 취소",
        highlight: "강조할 선을 따라 드래그",
        pin: "위치를 클릭하면 번호 / 기호가 놓입니다",
        freecloud: "변경 경계를 차례로 클릭 · Enter 완성",
        text: "문자를 놓을 위치 클릭",
        route: "경로를 차례로 클릭 · Enter 완성 · 규격과 각도는 속성에서 지정",
        eraser: "펜·형광펜의 지울 부분을 드래그 · 잠긴 표시는 보존",
        lasso: "선택할 표시를 감싸도록 드래그 · Shift로 추가 선택",
        calibrate: "실제 거리를 입력한 뒤 도면의 두 기준점 클릭",
        region: "대각선 드래그로 출력 범위 지정 · Esc 취소",
    };
    // Static SVG artwork keeps the toolbar legible without platform icon fonts.
    const toolArtwork = {
        arc2: [
            "blue",
            '<path d="M3 19Q12 0 21 19"/><circle cx="3" cy="19" r="2"/><circle cx="21" cy="19" r="2"/>',
        ],
        arc3: [
            "blue",
            '<path d="M3 19Q12 0 21 19"/><circle cx="12" cy="9.5" r="2"/>',
        ],
        arc: [
            "blue",
            '<path d="M4 20V4a16 16 0 0116 16z" stroke-dasharray="3 2"/><path d="M4 4a16 16 0 0116 16"/>',
        ],
        pie: [
            "teal",
            '<path d="M4 20V4a16 16 0 0116 16z" fill="currentColor" fill-opacity=".25"/>',
        ],
        curve: ["blue", '<path d="M3 20Q3 3 21 4m-5-3 5 3-4 5"/>'],
        cubic: [
            "blue",
            '<path d="M3 20C4-8 20 32 21 4"/><path d="M3 20L4 3m17 1-1 17" stroke-dasharray="2 2"/>',
        ],
        rotbox: [
            "teal",
            '<path d="M3 10L17 3l5 11-14 7z" fill="currentColor" fill-opacity=".2"/>',
        ],
        rounded: ["teal", '<rect x="3" y="5" width="18" height="14" rx="4"/>'],
        capsule: ["teal", '<rect x="2" y="7" width="20" height="10" rx="5"/>'],
        split: [
            "rose",
            '<rect x="3" y="3" width="18" height="18"/><path d="M1 16L23 8" stroke-width="3"/>',
        ],
        hole: [
            "rose",
            '<path d="M3 3h18v18H3zM8 8v8h8V8z" fill="currentColor" fill-opacity=".2" fill-rule="evenodd"/>',
        ],
        trim: [
            "rose",
            '<path d="M3 5h18M3 19h18M12 2v20"/><path d="m9 9 6 6m0-6-6 6" stroke-width="3"/>',
        ],
        extend: ["blue", '<path d="M3 4h18M12 22V4m-4 5 4-5 4 5"/>'],
        select: [
            "blue",
            '<path d="M5 3l14 10-7 1-3 7z" fill="currentColor" stroke-width="1.3"/>',
        ],
        boundary: [
            "teal",
            '<rect x="3" y="3" width="18" height="18" rx="2" stroke-dasharray="3 3"/><path d="M12 7c-1.5 2-4 4-4 6a4 4 0 008 0c0-2-2.5-4-4-6z" fill="currentColor" fill-opacity=".35"/><path d="M10 14c0 1 1 2 2 2"/>',
        ],
        area: [
            "teal",
            '<path d="M4 16L7 5l10 2 4 10-10 4z" fill="currentColor" fill-opacity=".2"/><circle cx="7" cy="5" r="1.4" fill="currentColor"/><circle cx="21" cy="17" r="1.4" fill="currentColor"/>',
        ],
        box: [
            "teal",
            '<rect x="4" y="5" width="16" height="14" rx="1.5" fill="currentColor" fill-opacity=".2"/><path d="M4 9V5h4m8 14h4v-4" stroke-width="2.5"/>',
        ],
        circle: [
            "teal",
            '<circle cx="12" cy="12" r="8" fill="currentColor" fill-opacity=".2"/>',
        ],
        ellipse: [
            "teal",
            '<ellipse cx="12" cy="12" rx="9" ry="6" fill="currentColor" fill-opacity=".2"/>',
        ],
        triangle: [
            "teal",
            '<path d="M12 3L22 21H2z" fill="currentColor" fill-opacity=".2"/>',
        ],
        diamond: [
            "teal",
            '<path d="M12 2L22 12 12 22 2 12z" fill="currentColor" fill-opacity=".2"/>',
        ],
        polygon: [
            "teal",
            '<path d="M7 3h10l5 9-5 9H7l-5-9z" fill="currentColor" fill-opacity=".2"/>',
        ],
        star: [
            "amber",
            '<path d="M12 2l3 7 7 1-5 5 1 7-6-4-6 4 1-7-5-5 7-1z" fill="currentColor" fill-opacity=".2"/>',
        ],
        line: [
            "blue",
            '<path d="M3 20L21 4"/><circle cx="3" cy="20" r="1.5"/><circle cx="21" cy="4" r="1.5"/>',
        ],
        arrow: ["blue", '<path d="M3 20L21 4m-9 0h9v9"/>'],
        doublearrow: ["blue", '<path d="M3 20L21 4m-9 0h9v9M3 11v9h9"/>'],
        cloud: [
            "coral",
            '<path d="M6 6c0-3 5-3 6-1 3-3 7-1 6 2 4 0 5 5 2 6 2 4-2 7-5 5-2 3-6 3-7 0-4 1-7-3-4-6-3-2-2-6 2-6z"/>',
        ],
        callout: [
            "violet",
            '<path d="M9 4h12v10H11l-3 3V5z" fill="currentColor" fill-opacity=".16"/><path d="M5 20l6-6M2 18l3 2 1-4M12 8h6m-6 3h4"/>',
        ],
        pen: [
            "amber",
            '<path d="M14 5l5 5M4 20l2-7L16 3l5 5-10 10z"/><path d="M4 20l7-2M3 22h12"/>',
        ],
        highlight: [
            "amber",
            '<path d="M9 13l7-10 6 5-9 9z" fill="currentColor" fill-opacity=".24"/><path d="M9 13l4 4-3 3-5-2zM3 22h15" stroke-width="2.4"/>',
        ],
        pin: [
            "coral",
            '<path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1114 0z" fill="currentColor" fill-opacity=".18"/><path d="M11 7h2v7m-3 0h5"/>',
        ],
        freecloud: [
            "coral",
            '<path d="M4 15c-3-3 0-7 3-6-1-5 5-7 7-3 4-2 8 2 5 5 4 2 2 7-2 6-1 4-7 4-8 0-3 3-6 1-5-2z"/><path d="M7 11l5-3 4 5-5 3z" stroke-dasharray="2 2" stroke-width="1.2"/>',
        ],
        text: [
            "violet",
            '<rect x="3" y="4" width="18" height="16" rx="2" stroke-dasharray="3 2"/><path d="M8 8h8m-4 0v9m-2 0h4" stroke-width="2.2"/>',
        ],
        route: [
            "teal",
            '<path d="M4 18h7V6h9" stroke-width="5" opacity=".24"/><path d="M4 18h7V6h9"/><circle cx="4" cy="18" r="2" fill="currentColor"/><path d="M17 3l3 3-3 3"/>',
        ],
        eraser: [
            "rose",
            '<path d="M4 14l9-10a2 2 0 013 0l5 5-10 11H8z" fill="currentColor" fill-opacity=".18"/><path d="M8 10l8 7M11 20h10"/>',
        ],
        lasso: [
            "blue",
            '<path d="M19 11c0 4-4 6-9 6s-8-3-8-6 4-7 9-7 8 3 8 7z" stroke-dasharray="3 2"/><path d="M9 17c-2 5 4 6 6 2 1-2-2-4-4-2"/>',
        ],
        calibrate: [
            "blue",
            '<path d="M3 17L17 3l5 5L8 22z" fill="currentColor" fill-opacity=".16"/><path d="M7 13l3 3m1-7l3 3m1-7l3 3"/><circle cx="3" cy="17" r="1.5" fill="currentColor"/><circle cx="17" cy="3" r="1.5" fill="currentColor"/>',
        ],
    };
    window.createCADMarkupTools = function (api) {
        let mode = "markup",
            tool = "select",
            selection = new Set(),
            gesture = null,
            polygon = [],
            hover = null,
            draft = null,
            workbench = null,
            regionReceiver = null;
        let panelKey = "",
            presets = [],
            visible = true,
            space = false;
        let snapCache = null,
            preciseMark = null,
            directionLock = null,
            referenceAngle = null,
            lastRaw = null;
        const canvas = api.canvas,
            clone = (value) => structuredClone(value);
        const items = () => api.document()?.markups || [];
        const permitted = () =>
            api.document() && api.canManage() && !api.busy();
        const chosen = () =>
            [...selection]
                .map((id) => items().find((item) => item.id === id))
                .filter(Boolean);
        const isLocked = (item) => workbench?.locked(item) ?? item.locked;
        const shown = () => items().filter((item) => !workbench?.hidden(item));
        const writable = () => chosen().filter((item) => !isLocked(item));
        const handles = (item) =>
            (item.kind === "detail"
                ? []
                : [item.points, ...(item.holes || [])]
            ).flatMap((ring, r) =>
                ring.map((point, index) => ({ point, ring: r, index })),
            );
        const stop = (event) => {
            event.preventDefault();
            event.stopImmediatePropagation();
        };
        const finite = (p) =>
            Number.isFinite(p.x) &&
            Number.isFinite(p.y) &&
            Math.abs(p.x) <= 1e9 &&
            Math.abs(p.y) <= 1e9;
        const uid = () => crypto.randomUUID();
        const hud = document.createElement("div");
        hud.id = "markup-drawing-hud";
        hud.className = "markup-only";
        hud.innerHTML =
            '<output id="markup-drawing-readout" aria-live="off"></output><label><span id="markup-numeric-label">다음 점 길이</span><input id="markup-numeric" type="text" autocomplete="off" placeholder="예: 1200mm&lt;45" aria-label="표시 그리기 치수"></label><button id="markup-numeric-apply" type="button">확정</button>';
        canvas.parentElement.append(hud);
        const multiTools = new Set([
            "arc2",
            "arc3",
            "arc",
            "pie",
            "curve",
            "cubic",
            "rotbox",
            "split",
        ]);
        const clickShapes = new Set([
            "line",
            "arrow",
            "doublearrow",
            "box",
            "circle",
            "ellipse",
            "triangle",
            "diamond",
            "polygon",
            "star",
            "rounded",
            "capsule",
        ]);
        function precision(raw, anchor = polygon.at(-1), event = {}) {
            lastRaw = raw;
            preciseMark = null;
            if (!G || !api.document()) return raw;
            let p = { ...raw };
            const tolerance = 10 / api.scale(),
                C = window.CADCore;
            if ($("mw-snap")?.checked) {
                const key = [
                    api.version?.(),
                    items().length,
                    api.document(),
                    JSON.stringify(api.document().layers || {}),
                    JSON.stringify(api.document().workspace?.layers || []),
                    gesture?.original,
                ];
                if (!snapCache || key.some((v, i) => snapCache.key[i] !== v)) {
                    const candidates = [],
                        edges = [];
                    let count = 0;
                    const edge = (a, b) => {
                        edges.push([a, b]);
                        candidates.push(
                            { ...a, kind: "끝점" },
                            { ...b, kind: "끝점" },
                            {
                                x: (a.x + b.x) / 2,
                                y: (a.y + b.y) / 2,
                                kind: "중점",
                            },
                        );
                    };
                    try {
                        const collect = (e) => {
                            if (
                                e.hidden ||
                                e.paper ||
                                api.document().layers?.[e.layer]?.visible ===
                                    false
                            )
                                return;
                            if (++count > 200000)
                                throw Error(
                                    "맞춤점 검색 한도를 넘었습니다. 불필요한 레이어를 숨기세요.",
                                );
                            if (e.type === "INSERT" && e.renderParts) {
                                e.renderParts.forEach(collect);
                                return;
                            }
                            for (const [i, p] of C.snapPoints(e).entries()) {
                                if (candidates.length >= 200000)
                                    throw Error(
                                        "맞춤점 한도를 넘었습니다. 불필요한 레이어를 숨기세요.",
                                    );
                                candidates.push({
                                    ...p,
                                    kind:
                                        ["CIRCLE", "ARC", "ELLIPSE"].includes(
                                            e.type,
                                        ) && p === e.p
                                            ? "중심"
                                            : e.type === "LINE" && i === 2
                                              ? "중점"
                                              : e.type === "POINT"
                                                ? "점"
                                                : "끝점",
                                });
                            }
                        };
                        api.entities().forEach(collect);
                        for (const item of shown()) {
                            if (
                                gesture?.original?.some((m) => m.id === item.id)
                            )
                                continue;
                            if (M.curved(item)) {
                                for (const t of [0, 0.5, 1])
                                    candidates.push({
                                        ...M.curvePoint(item, t),
                                        kind:
                                            t === 0.5 ? "호·곡선 중점" : "끝점",
                                    });
                                if (item.kind === "arc")
                                    candidates.push({
                                        ...M.ellipseFrame(item.points).center,
                                        kind: "중심",
                                    });
                            } else if (item.kind === "ellipse") {
                                const f = M.ellipseFrame(item.points);
                                candidates.push({ ...f.center, kind: "중심" });
                                for (const t of [
                                    0,
                                    Math.PI / 2,
                                    Math.PI,
                                    Math.PI * 1.5,
                                ])
                                    candidates.push({
                                        x:
                                            f.center.x +
                                            f.u.x * Math.cos(t) +
                                            f.v.x * Math.sin(t),
                                        y:
                                            f.center.y +
                                            f.u.y * Math.cos(t) +
                                            f.v.y * Math.sin(t),
                                        kind: "사분점",
                                    });
                            } else
                                for (const ring of G.rings(item)) {
                                    for (let i = 1; i < ring.length; i++)
                                        edge(ring[i - 1], ring[i]);
                                    if (["area", "cloud"].includes(item.kind))
                                        edge(ring.at(-1), ring[0]);
                                    if (ring.length === 1)
                                        candidates.push({
                                            ...ring[0],
                                            kind: "기준점",
                                        });
                                }
                        }
                        snapCache = {
                            key,
                            index: G.snapIndex(candidates),
                            edges,
                        };
                    } catch (error) {
                        snapCache = { key, index: G.snapIndex([]), edges: [] };
                        api.status(error.message);
                    }
                }
                const edges = [
                    ...(api.sourceEdges?.(raw) || []).map((e) => [e.p, e.q]),
                    ...snapCache.edges.filter(
                        ([a, b]) => M.segmentDistance(raw, a, b) < tolerance,
                    ),
                ].slice(0, 64);
                let point = snapCache.index.query(raw, tolerance);
                for (const [a, b] of edges) {
                    const mid = {
                        x: (a.x + b.x) / 2,
                        y: (a.y + b.y) / 2,
                        kind: "중점",
                    };
                    if (
                        M.distance(mid, raw) < tolerance &&
                        (!point ||
                            M.distance(mid, raw) < M.distance(point, raw))
                    )
                        point = mid;
                }
                for (let i = 0; i < edges.length; i++)
                    for (let j = i + 1; j < edges.length; j++) {
                        const h = G.lineIntersection(...edges[i], ...edges[j]);
                        if (
                            h &&
                            h.t >= 0 &&
                            h.t <= 1 &&
                            h.s >= 0 &&
                            h.s <= 1 &&
                            M.distance(h.point, raw) < tolerance &&
                            (!point ||
                                M.distance(h.point, raw) <=
                                    M.distance(point, raw) + 1e-10)
                        )
                            point = { ...h.point, kind: "교차점" };
                    }
                const guide = C.guideSnap(
                    raw,
                    api.document().workspace?.guidesVisible === false
                        ? []
                        : api.document().workspace?.guides || [],
                    tolerance,
                    edges.map(([p, q]) => ({ p, q })),
                );
                if (
                    guide &&
                    (!point || M.distance(guide, raw) < M.distance(point, raw))
                )
                    point = { ...guide, kind: "안내선" };
                if (!point)
                    for (const [a, b] of edges) {
                        const q = C.segmentPoint(raw, a, b);
                        if (
                            M.distance(q, raw) < tolerance &&
                            (!point ||
                                M.distance(q, raw) < M.distance(point, raw))
                        )
                            point = { ...q, kind: "선 위" };
                    }
                if (point) {
                    p = { x: point.x, y: point.y };
                    preciseMark = point;
                }
                if (edges.length) {
                    const [a, b] = edges[0];
                    if (M.segmentDistance(raw, a, b) < tolerance / 2)
                        referenceAngle =
                            (Math.atan2(b.y - a.y, b.x - a.x) * 180) / Math.PI;
                }
            }
            if (anchor) {
                const lock =
                    directionLock ??
                    (event.shiftKey
                        ? Math.round(
                              (Math.atan2(p.y - anchor.y, p.x - anchor.x) * 4) /
                                  Math.PI,
                          ) * 45
                        : null);
                if (!preciseMark || lock !== null) {
                    const found = C.inferDirection(anchor, p, {
                        enabled: !!$("mw-infer")?.checked,
                        lock,
                        reference: referenceAngle,
                        step: Number($("mw-step")?.value) || 0,
                    });
                    if (found.kind) {
                        p = found.point;
                        preciseMark = {
                            ...p,
                            kind: {
                                x: "수평",
                                y: "수직",
                                parallel: "평행",
                                perpendicular: "수직 맞춤",
                                locked: "방향 고정",
                                angle: "각도 맞춤",
                            }[found.kind],
                        };
                    }
                }
            }
            return p;
        }
        function makeGeometry(shape) {
            let item = { ...create(shape.kind, shape.points), ...shape };
            item = workbench?.decorate(item) || item;
            if (M.curved(item)) item.arrows = shape.arrows || "none";
            return item;
        }
        function construction(ps) {
            if (ps.length < 3) return null;
            if (tool === "arc2") return G.arcThrough(ps[0], ps[2], ps[1]);
            if (tool === "arc3") return G.arcThrough(...ps);
            if (tool === "arc" || tool === "pie")
                return G.arcFromCenter(
                    ...ps,
                    $("mw-clockwise").checked,
                    tool === "pie",
                );
            if (tool === "curve")
                return {
                    ...G.bezierThrough(ps[0], ps[2], ps[1]),
                    arrows: "end",
                };
            if (tool === "cubic")
                return ps.length < 4
                    ? null
                    : { kind: "curve", points: ps, arrows: "none" };
            if (tool === "rotbox")
                return { kind: "area", points: G.rotatedBox(...ps) };
            return null;
        }
        function acceptPoint(p) {
            if (!permitted()) return;
            if (tool === "split") {
                polygon.push(p);
                if (polygon.length === 2) {
                    try {
                        const target = chosen()[0];
                        if (!target || isLocked(target))
                            throw Error("나눌 영역을 선택하세요.");
                        const next = G.splitArea(target, ...polygon);
                        polygon = [];
                        apply(
                            [
                                ...items().filter((m) => m.id !== target.id),
                                ...next,
                            ],
                            next.map((m) => m.id),
                        );
                        tool = "select";
                        api.status(
                            `${next.length}개 영역으로 나눴습니다. 각 영역의 색을 바꿀 수 있습니다.`,
                        );
                    } catch (error) {
                        polygon.pop();
                        api.status(error.message);
                    }
                }
                api.render();
                return;
            }
            if (multiTools.has(tool)) {
                const required = tool === "cubic" ? 4 : 3;
                if (polygon.length + 1 === required) {
                    try {
                        const item = makeGeometry(
                            construction([...polygon, p]),
                        );
                        if (tool === "curve") item.arrows = "end";
                        commit(item);
                    } catch (error) {
                        api.status(error.message);
                    }
                } else polygon.push(p);
                api.render();
                return;
            }
            if (clickShapes.has(tool) && polygon.length) {
                const item = preview({
                    kind: "draw",
                    tool,
                    start: polygon[0],
                    end: p,
                });
                if (item && G.valid(item)) commit(item);
                else api.status("크기 또는 좌표를 확인하세요.");
                return;
            }
            if (["area", "freecloud", "route", "hole"].includes(tool)) {
                if (polygon.length >= 512) {
                    api.status("512개 꼭짓점까지 지정할 수 있습니다.");
                    return;
                }
                if (
                    polygon.length >= 3 &&
                    M.distance(polygon[0], p) * api.scale() < 10 &&
                    tool !== "route"
                ) {
                    finishArea();
                    return;
                }
                if (
                    !polygon.length ||
                    M.distance(p, polygon.at(-1)) * api.scale() > 1
                )
                    polygon.push(p);
                api.render();
            }
        }
        function numericPoint() {
            if (!permitted() || gesture || !polygon.length) return;
            const C = window.CADCore,
                text = $("markup-numeric").value.trim(),
                a = polygon.at(-1),
                cursor = hover || { x: a.x + 1, y: a.y },
                settings = api.numericSettings?.() || {};
            try {
                if (!text) throw Error("치수를 입력하세요.");
                let p;
                if (["arc", "pie"].includes(tool) && polygon.length === 2) {
                    const angle = Number(text.replace(/°|deg/gi, ""));
                    if (!Number.isFinite(angle) || angle <= 0 || angle >= 360)
                        throw Error(
                            "회전각은 0보다 크고 360보다 작아야 합니다.",
                        );
                    p = G.rotatePoint(
                        polygon[1],
                        polygon[0],
                        ((angle * Math.PI) / 180) *
                            ($("mw-clockwise").checked ? -1 : 1),
                    );
                } else if (tool === "arc2" && polygon.length === 2) {
                    const [a, b] = polygon,
                        d = C.parseLengthInput(text, settings),
                        len = M.distance(a, b),
                        mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 },
                        n = { x: -(b.y - a.y) / len, y: (b.x - a.x) / len },
                        sign =
                            (cursor.x - mid.x) * n.x +
                                (cursor.y - mid.y) * n.y <
                            0
                                ? -1
                                : 1;
                    p = {
                        x: mid.x + n.x * d * sign,
                        y: mid.y + n.y * d * sign,
                    };
                } else if (tool === "rotbox" && polygon.length === 2) {
                    const [a, b] = polygon,
                        d = C.parseLengthInput(text, settings),
                        len = M.distance(a, b),
                        n = { x: -(b.y - a.y) / len, y: (b.x - a.x) / len },
                        sign =
                            (cursor.x - a.x) * n.x + (cursor.y - a.y) * n.y < 0
                                ? -1
                                : 1;
                    p = { x: b.x + n.x * d * sign, y: b.y + n.y * d * sign };
                } else if (
                    [
                        "box",
                        "ellipse",
                        "triangle",
                        "diamond",
                        "polygon",
                        "star",
                        "rounded",
                        "capsule",
                    ].includes(tool)
                )
                    p = C.numericDrawing(
                        "rectangle",
                        polygon[0],
                        cursor,
                        text,
                        settings,
                    ).q;
                else if (tool === "circle") {
                    const d = C.parseLengthInput(text, settings);
                    p = { x: a.x + d, y: a.y + d };
                } else
                    p = C.numericDrawing("line", a, cursor, text, settings).q;
                if (!finite(p)) throw Error("도면 좌표 범위를 확인하세요.");
                acceptPoint(p);
                $("markup-numeric").value = "";
                canvas.focus();
            } catch (error) {
                api.status(error.message);
            }
        }
        $("markup-numeric-apply").onclick = numericPoint;
        $("markup-numeric").addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                stop(event);
                numericPoint();
            } else if (event.key === "Escape") {
                stop(event);
                $("markup-numeric").value = "";
                canvas.focus();
            }
        });
        function settings() {
            const width = Number($("markup-width").value);
            return {
                color: $("markup-color").value,
                opacity: Number($("markup-opacity").value) / 100,
                width: Number.isFinite(width)
                    ? Math.max(1, Math.min(40, width))
                    : 2,
                pattern: $("markup-pattern").value,
            };
        }
        function create(kind, points) {
            const style = settings();
            if (kind === "highlight") style.width = Math.max(12, style.width);
            const item = {
                id: uid(),
                kind,
                points: clone(points),
                ...style,
                size: 16 / api.scale(),
                text: "",
                locked: false,
            };
            return workbench?.decorate(item) || item;
        }
        function select(ids) {
            selection = new Set(ids);
            for (const item of chosen())
                if (item.group)
                    for (const other of items())
                        if (other.group === item.group) selection.add(other.id);
            panelKey = "";
            api.render();
        }
        function pick(item, additive, preserve = false) {
            const ids = item.group
                ? items()
                      .filter((m) => m.group === item.group)
                      .map((m) => m.id)
                : [item.id];
            if (additive) {
                const remove = ids.every((id) => selection.has(id));
                for (const id of ids)
                    remove ? selection.delete(id) : selection.add(id);
            } else if (!preserve || !selection.has(item.id))
                selection = new Set(ids);
        }
        function apply(next, ids = [...selection]) {
            if (!permitted() || pending()) return false;
            if (G && !G.validSet(next))
                throw Error(
                    "경계 교차, 좌표 또는 표시 한도를 확인하세요. 변경하지 않았습니다.",
                );
            api.snapshot();
            api.document().markups = next;
            selection = new Set(ids);
            panelKey = "";
            api.render();
            return true;
        }
        function commit(item) {
            if (!permitted()) return;
            if (
                items().length >= 1000 ||
                items().reduce(
                    (n, m) => n + (G ? G.pointCount(m) : m.points.length),
                    0,
                ) +
                    (G ? G.pointCount(item) : item.points.length) >
                    50000
            ) {
                api.status(
                    "표시 한도에 도달했습니다. 불필요한 표시를 정리한 뒤 다시 시도하세요.",
                );
                return;
            }
            if (
                !item.points.every(finite) ||
                !Number.isFinite(item.size) ||
                item.size > 1e9 ||
                (G && !G.validSet([...items(), item])) ||
                isLocked(item)
            ) {
                api.status(
                    "도면 좌표 범위를 벗어났습니다. 전체 보기 후 다시 시도하세요.",
                );
                return;
            }
            api.snapshot();
            api.document().markups.push(item);
            selection.clear();
            polygon = [];
            hover = null;
            panelKey = "";
            $("properties").scrollTop = 0;
            api.render();
            return true;
        }
        function cancel() {
            preciseMark = null;
            directionLock = null;
            snapCache = null;
            const receiver = regionReceiver;
            regionReceiver = null;
            const pointer = gesture?.pointer;
            gesture = null;
            polygon = [];
            hover = null;
            api.clearSnap();
            if (pointer !== undefined && canvas.hasPointerCapture(pointer))
                canvas.releasePointerCapture(pointer);
            if ($("markup-dialog").open) $("markup-dialog").close();
            draft = null;
            api.render();
            receiver?.(null);
        }
        function pending() {
            return (
                !!gesture ||
                !!regionReceiver ||
                polygon.length > 0 ||
                $("markup-dialog").open
            );
        }
        function setTool(next) {
            if (api.busy()) return;
            cancel();
            tool = next;
            if (!["split", "hole", "trim", "extend"].includes(next))
                selection.clear();
            panelKey = "";
            if (
                [
                    "cloud",
                    "freecloud",
                    "pen",
                    "callout",
                    "text",
                    "route",
                    "line",
                    "arrow",
                    "doublearrow",
                    "arc2",
                    "arc3",
                    "arc",
                    "curve",
                    "cubic",
                    "pin",
                ].includes(next)
            )
                $("markup-opacity").value = "100";
            else if (
                [
                    "boundary",
                    "box",
                    "area",
                    "highlight",
                    "circle",
                    "ellipse",
                    "triangle",
                    "diamond",
                    "polygon",
                    "star",
                ].includes(next)
            )
                $("markup-opacity").value = "35";
            api.render();
            canvas.focus();
        }
        function setMode(next) {
            if (api.busy() || $("text-dialog").open || $("markup-dialog").open)
                return;
            cancel();
            api.clearCAD();
            mode = next;
            selection.clear();
            document.body.dataset.workspace = mode;
            for (const button of document.querySelectorAll(
                ".mode-switch [data-workspace]",
            ))
                button.setAttribute(
                    "aria-pressed",
                    String(button.dataset.workspace === mode),
                );
            api.render();
        }
        for (const button of document.querySelectorAll(
            ".mode-switch [data-workspace]",
        ))
            button.onclick = () => setMode(button.dataset.workspace);
        const toolGroups = [
            [
                "선택 · 수정",
                [
                    "select",
                    "lasso",
                    "eraser",
                    "split",
                    "hole",
                    "trim",
                    "extend",
                ],
            ],
            [
                "영역 · 도형",
                [
                    "boundary",
                    "area",
                    "box",
                    "circle",
                    "ellipse",
                    "triangle",
                    "diamond",
                    "polygon",
                    "star",
                    "rotbox",
                    "rounded",
                    "capsule",
                    "pie",
                ],
            ],
            [
                "선 · 화살표",
                ["line", "arrow", "doublearrow", "route", "pen", "highlight"],
            ],
            ["호 · 곡선", ["arc2", "arc3", "arc", "curve", "cubic"]],
            [
                "문자 · 변경 표시",
                ["text", "callout", "pin", "cloud", "freecloud", "calibrate"],
            ],
        ];
        const toolHosts = new Map();
        for (const [title, keys] of toolGroups) {
            const group = document.createElement("div"),
                heading = document.createElement("h3");
            group.className = "markup-tool-group";
            heading.textContent = title;
            group.append(heading);
            $("markup-tools").append(group);
            keys.forEach((key) => toolHosts.set(key, group));
        }
        for (const key of toolGroups.flatMap(([, keys]) => keys)) {
            const name = names[key];
            const button = document.createElement("button");
            button.type = "button";
            button.dataset.markupTool = key;
            const [family, artwork] = toolArtwork[key];
            button.dataset.toolTone = family;
            button.setAttribute("aria-label", key === "select" ? "선택" : name);
            button.title = name + " · " + hints[key];
            const icon = document.createElement("span"),
                label = document.createElement("span");
            icon.className = "markup-tool-icon";
            icon.setAttribute("aria-hidden", "true");
            icon.innerHTML =
                '<svg viewBox="0 0 24 24" width="23" height="23" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
                artwork +
                "</svg>";
            label.className = "markup-tool-label";
            label.textContent = name;
            button.append(icon, label);
            button.onclick = () => setTool(key);
            toolHosts.get(key).append(button);
        }
        function editText(item, fresh = false) {
            if (!permitted() || isLocked(item)) return;
            draft = { item: clone(item), fresh };
            $("markup-dialog-title").textContent =
                item.kind === "pin" ? "번호 / 기호 입력" : "연결 메모";
            $("markup-dialog-text").value = item.text;
            $("markup-dialog-error").textContent = "";
            $("markup-dialog-text").maxLength = item.kind === "pin" ? 8 : 500;
            $("markup-dialog").showModal();
            $("markup-dialog-text").focus();
        }
        $("markup-dialog-confirm").onclick = () => {
            if (!draft || !permitted()) return;
            const text = $("markup-dialog-text").value.trim();
            if (!text) {
                $("markup-dialog-error").textContent = "내용을 입력하세요.";
                return;
            }
            const { item, fresh } = draft;
            item.text = text;
            if (fresh) commit(item);
            else {
                api.snapshot();
                const index = items().findIndex((m) => m.id === item.id);
                if (index >= 0) items()[index] = item;
            }
            $("markup-dialog").close();
            draft = null;
            panelKey = "";
            api.render();
            canvas.focus();
        };
        $("markup-dialog-cancel").onclick = () => {
            draft = null;
            $("markup-dialog").close();
            canvas.focus();
        };
        $("markup-dialog").addEventListener("cancel", () => {
            draft = null;
        });
        function finishArea() {
            if (tool === "hole") {
                try {
                    const one = chosen()[0];
                    if (
                        !permitted() ||
                        polygon.length < 3 ||
                        !one ||
                        isLocked(one)
                    )
                        return;
                    const item = G.hole(one, clone(polygon));
                    polygon = [];
                    apply(items().map((m) => (m.id === one.id ? item : m)));
                    tool = "select";
                    api.render();
                } catch (error) {
                    api.status(error.message);
                }
                return;
            }
            if (!permitted() || polygon.length < (tool === "route" ? 2 : 3))
                return;
            if (tool !== "route" && !M.validPolygon(polygon)) {
                api.status(
                    "경계가 겹치거나 면적이 없습니다. Esc로 취소한 뒤 경계 순서대로 지정하세요.",
                );
                return;
            }
            commit(
                create(
                    tool === "freecloud"
                        ? "cloud"
                        : tool === "route"
                          ? "route"
                          : "area",
                    polygon,
                ),
            );
        }
        function pointerDown(event) {
            if (mode !== "markup" || event.button !== 0) return;
            stop(event);
            canvas.focus();
            if (!api.document() || api.busy() || $("markup-dialog").open)
                return;
            const raw = api.world(event),
                p =
                    multiTools.has(tool) ||
                    clickShapes.has(tool) ||
                    tool === "hole" ||
                    [
                        "circle",
                        "ellipse",
                        "triangle",
                        "diamond",
                        "polygon",
                        "star",
                        "line",
                        "arrow",
                        "doublearrow",
                        "area",
                        "box",
                        "cloud",
                        "freecloud",
                        "route",
                        "calibrate",
                        "callout",
                    ].includes(tool)
                        ? precision(raw, polygon.at(-1), event)
                        : raw;
            if (space) {
                gesture = {
                    kind: "pan",
                    pointer: event.pointerId,
                    last: { x: event.clientX, y: event.clientY },
                };
                canvas.setPointerCapture(event.pointerId);
                return;
            }
            if (tool === "select") {
                const one = chosen()[0];
                if (
                    event.altKey &&
                    chosen().length === 1 &&
                    !isLocked(one) &&
                    ["area", "cloud", "route"].includes(one.kind) &&
                    permitted() &&
                    G
                ) {
                    const copy = clone(one),
                        rings = [copy.points, ...(copy.holes || [])];
                    let changed = false;
                    for (const ring of rings) {
                        const vertex = ring.findIndex(
                            (q) => M.distance(q, raw) * api.scale() < 8,
                        );
                        if (
                            vertex >= 0 &&
                            ring.length > (one.kind === "route" ? 2 : 3)
                        ) {
                            ring.splice(vertex, 1);
                            changed = true;
                            break;
                        }
                        const edge = ring.findIndex(
                            (q, i) =>
                                (one.kind !== "route" || i < ring.length - 1) &&
                                M.segmentDistance(
                                    raw,
                                    q,
                                    ring[(i + 1) % ring.length],
                                ) *
                                    api.scale() <
                                    8,
                        );
                        if (vertex < 0 && edge >= 0 && ring.length < 2048) {
                            const a = ring[edge],
                                b = ring[(edge + 1) % ring.length],
                                d = (b.x - a.x) ** 2 + (b.y - a.y) ** 2,
                                t = d
                                    ? Math.max(
                                          0,
                                          Math.min(
                                              1,
                                              ((raw.x - a.x) * (b.x - a.x) +
                                                  (raw.y - a.y) * (b.y - a.y)) /
                                                  d,
                                          ),
                                      )
                                    : 0;
                            ring.splice(edge + 1, 0, {
                                x: a.x + t * (b.x - a.x),
                                y: a.y + t * (b.y - a.y),
                            });
                            changed = true;
                            break;
                        }
                    }
                    if (changed)
                        try {
                            apply(
                                items().map((m) =>
                                    m.id === copy.id ? copy : m,
                                ),
                            );
                        } catch (error) {
                            api.status(error.message);
                        }
                    return;
                }
                const grip =
                    chosen().length === 1 && !isLocked(chosen()[0])
                        ? handles(chosen()[0]).findIndex(
                              (handle, i, points) =>
                                  (points.length < 50 ||
                                      i === 0 ||
                                      i === points.length - 1) &&
                                  M.distance(handle.point, raw) * api.scale() <
                                      8,
                          )
                        : -1;
                const hit = visible
                    ? [...shown()]
                          .reverse()
                          .find((item) =>
                              M.hit(
                                  item,
                                  raw,
                                  (8 + item.width / 2) / api.scale(),
                              ),
                          )
                    : null;
                if (grip < 0) {
                    if (hit) {
                        pick(hit, event.shiftKey, true);
                    } else if (!event.shiftKey) selection.clear();
                }
                if (
                    permitted() &&
                    (grip >= 0 || hit) &&
                    !event.shiftKey &&
                    writable().length &&
                    !chosen().some(isLocked)
                ) {
                    gesture = {
                        kind: grip >= 0 ? "vertex" : "move",
                        grip,
                        pointer: event.pointerId,
                        start: raw,
                        original: clone(writable()),
                        preview: [],
                        moved: false,
                    };
                    canvas.setPointerCapture(event.pointerId);
                }
                if (grip < 0 && !hit) {
                    gesture = {
                        kind: "region",
                        pointer: event.pointerId,
                        start: raw,
                        end: raw,
                        path: [raw],
                        add: event.shiftKey,
                        moved: false,
                    };
                    canvas.setPointerCapture(event.pointerId);
                }
                panelKey = "";
                api.render();
                return;
            }
            if (!permitted() && !["lasso", "region"].includes(tool)) {
                api.status("읽기 전용 도면입니다.");
                return;
            }
            if (tool === "text") {
                editText(create("text", [p]), true);
                return;
            }
            if (tool === "calibrate") {
                polygon.push(p);
                if (polygon.length === 2) {
                    const reference = clone(polygon);
                    polygon = [];
                    tool = "select";
                    workbench?.calibrate(reference);
                    api.render();
                } else api.render();
                return;
            }
            if (!visible) {
                visible = true;
                $("markup-visible").checked = true;
            }
            if (["split", "hole", "trim", "extend"].includes(tool)) {
                const target = chosen()[0],
                    needed = ["split", "hole"].includes(tool)
                        ? "area"
                        : "route";
                if (chosen().length !== 1 || target.kind !== needed) {
                    const hit = [...shown()]
                        .reverse()
                        .find(
                            (m) =>
                                m.kind === needed &&
                                !isLocked(m) &&
                                M.hit(m, raw, 8 / api.scale()),
                        );
                    if (hit) select([hit.id]);
                    else
                        api.status(
                            needed === "area"
                                ? "먼저 다각형 영역 하나를 선택하세요."
                                : "먼저 직선 표시 하나를 선택하세요.",
                        );
                    return;
                }
                if (isLocked(target)) {
                    api.status("잠긴 표시입니다.");
                    return;
                }
                if (["trim", "extend"].includes(tool)) {
                    if (
                        target.points.length !== 2 ||
                        M.segmentDistance(raw, ...target.points) >
                            12 / api.scale()
                    ) {
                        api.status("편집할 직선의 해당 부분을 클릭하세요.");
                        return;
                    }
                    try {
                        const cutters = [
                            ...(api.sourceEdges?.() || []).map((e) => [
                                e.p,
                                e.q,
                            ]),
                        ];
                        for (const m of shown().filter(
                            (m) =>
                                m.id !== target.id &&
                                ["area", "route"].includes(m.kind),
                        ))
                            for (const ring of G.rings(m)) {
                                for (let i = 1; i < ring.length; i++)
                                    cutters.push([ring[i - 1], ring[i]]);
                                if (m.kind === "area")
                                    cutters.push([ring.at(-1), ring[0]]);
                            }
                        const next = G.trimRoute(
                            target,
                            cutters,
                            raw,
                            tool === "extend",
                        );
                        apply(
                            [
                                ...items().filter((m) => m.id !== target.id),
                                ...next,
                            ],
                            next.map((m) => m.id),
                        );
                        api.status(
                            "편집 완료 · 실행 취소로 되돌릴 수 있습니다.",
                        );
                    } catch (error) {
                        api.status(error.message);
                    }
                    return;
                }
            }
            if (
                multiTools.has(tool) ||
                tool === "hole" ||
                (clickShapes.has(tool) && polygon.length)
            ) {
                acceptPoint(p);
                return;
            }
            if (tool === "boundary") {
                try {
                    const boundary = api.boundary(raw);
                    if (!boundary) {
                        api.status(
                            "클릭한 곳을 둘러싼 닫힌 직선 경계를 찾지 못했습니다. 선의 틈·숨긴 레이어를 확인하세요. 곡선이나 이미지 영역은 자유 영역으로 지정하세요.",
                        );
                        return;
                    }
                    const item = create(
                        "area",
                        Array.isArray(boundary) ? boundary : boundary.points,
                    );
                    if (boundary.holes?.length)
                        item.holes = clone(boundary.holes);
                    if (commit(item))
                        api.status(
                            "영역을 색칠했습니다. 다른 닫힌 구역을 계속 클릭하거나 Esc로 마치세요.",
                        );
                } catch (error) {
                    api.status(error.message);
                }
                return;
            }
            if (["area", "freecloud", "route"].includes(tool)) {
                const point =
                    tool === "route" && polygon.length && G
                        ? G.snapAngle(
                              polygon.at(-1),
                              p,
                              workbench?.routeAngle() || 0,
                          )
                        : p;
                if (
                    polygon.length >= 3 &&
                    M.distance(polygon[0], point) * api.scale() < 12 &&
                    tool !== "route"
                ) {
                    finishArea();
                    return;
                }
                if (polygon.length >= 512) {
                    api.status(
                        "한 영역은 512개 꼭짓점까지 지정할 수 있습니다. Enter로 완성하세요.",
                    );
                    return;
                }
                if (
                    !polygon.length ||
                    M.distance(point, polygon.at(-1)) * api.scale() > 2
                )
                    polygon.push(point);
                api.render();
                return;
            }
            if (tool === "pin") {
                const item = create("pin", [p]),
                    numeric = items()
                        .filter((m) => m.kind === "pin" && /^\d+$/.test(m.text))
                        .map((m) => Number(m.text));
                item.text = String(
                    Math.min(99999999, Math.max(0, ...numeric) + 1),
                );
                item.opacity = 1;
                commit(item);
                return;
            }
            gesture = {
                kind: ["lasso", "region"].includes(tool) ? "region" : "draw",
                tool,
                pointer: event.pointerId,
                start: p,
                end: p,
                path: [p],
                moved: false,
                add: event.shiftKey,
            };
            canvas.setPointerCapture(event.pointerId);
            api.render();
        }
        function pointerMove(event) {
            if (mode !== "markup" || (!gesture && event.buttons !== 0)) return;
            if (event.buttons === 2 || event.buttons === 4) return;
            stop(event);
            if (!api.document() || api.busy()) return;
            if (
                !gesture &&
                !polygon.length &&
                tool !== "eraser" &&
                !multiTools.has(tool) &&
                !clickShapes.has(tool) &&
                !["area", "route", "hole"].includes(tool)
            ) {
                const raw = api.world(event);
                canvas.style.cursor = space
                    ? "grab"
                    : tool === "select" &&
                        chosen().some(
                            (item) =>
                                !isLocked(item) &&
                                handles(item).some(
                                    (h) =>
                                        M.distance(h.point || h, raw) *
                                            api.scale() <
                                        8,
                                ),
                        )
                      ? "nwse-resize"
                      : tool === "select"
                        ? "default"
                        : "crosshair";
                return;
            }
            const raw = api.world(event);
            hover = precision(raw, polygon.at(-1) || gesture?.start, event);
            if (tool === "route" && polygon.length && G)
                hover = G.snapAngle(
                    polygon.at(-1),
                    hover,
                    workbench?.routeAngle() || 0,
                );
            if (gesture?.kind === "pan") {
                api.pan(
                    event.clientX - gesture.last.x,
                    event.clientY - gesture.last.y,
                );
                gesture.last = { x: event.clientX, y: event.clientY };
                return;
            }
            if (gesture) {
                const g = gesture;
                g.moved ||= M.distance(g.start, raw) * api.scale() > 3;
                if (g.kind === "draw" || g.kind === "region") {
                    g.end =
                        ["pen", "highlight", "eraser"].includes(g.tool) ||
                        g.kind === "region"
                            ? raw
                            : precision(raw, g.start, event);
                    if (
                        event.shiftKey &&
                        ["line", "arrow", "doublearrow"].includes(g.tool)
                    )
                        g.end = G.snapAngle(g.start, g.end, 45);
                    if (
                        (["pen", "highlight", "eraser"].includes(g.tool) ||
                            g.tool === "lasso") &&
                        g.path.length < 2048 &&
                        M.distance(g.path.at(-1), raw) * api.scale() >= 2
                    )
                        g.path.push(raw);
                } else {
                    const end =
                        g.kind === "vertex" ? precision(raw, null, event) : raw;
                    g.preview = g.original.map((item) =>
                        g.kind === "move"
                            ? M.translate(
                                  item,
                                  end.x - g.start.x,
                                  end.y - g.start.y,
                              )
                            : (() => {
                                  if (["ellipse", "arc"].includes(item.kind))
                                      return G.resizeEllipse(item, g.grip, end);
                                  const result = clone(item),
                                      handle = handles(result)[g.grip];
                                  if (handle) {
                                      const ring =
                                          handle.ring === 0
                                              ? result.points
                                              : result.holes[handle.ring - 1];
                                      ring[handle.index] = end;
                                  }
                                  return result;
                              })(),
                    );
                }
            }
            api.render();
        }
        function preview(g) {
            if (!g || g.kind !== "draw" || g.tool === "eraser") return null;
            const kind = {
                box: "area",
                rounded: "area",
                capsule: "area",
                circle: "ellipse",
                ellipse: "ellipse",
                triangle: "area",
                diamond: "area",
                polygon: "area",
                star: "area",
                line: "route",
                arrow: "route",
                doublearrow: "route",
                cloud: "cloud",
                callout: "callout",
                pen: "pen",
                highlight: "highlight",
            }[g.tool];
            const ps = ["rounded", "capsule"].includes(g.tool)
                ? G.roundedBox(
                      g.start,
                      g.end,
                      g.tool === "capsule"
                          ? Infinity
                          : Number($("mw-corner-radius").value) || 1,
                  )
                : [
                        "box",
                        "cloud",
                        "circle",
                        "ellipse",
                        "triangle",
                        "diamond",
                        "polygon",
                        "star",
                    ].includes(g.tool)
                  ? G.shape(
                        g.tool,
                        g.start,
                        g.end,
                        workbench?.polygonSides() || 6,
                    )
                  : ["pen", "highlight"].includes(g.tool)
                    ? g.path
                    : [g.start, g.end];
            const item = create(kind, ps);
            if (["line", "arrow", "doublearrow"].includes(g.tool))
                item.arrows = {
                    line: "none",
                    arrow: "end",
                    doublearrow: "both",
                }[g.tool];
            if (kind === "callout") item.text = "메모 입력";
            return item;
        }
        function pointerUp(event) {
            if (mode !== "markup" || event.button !== 0) return;
            stop(event);
            const g = gesture;
            if (!g) return;
            gesture = null;
            api.clearSnap();
            if (canvas.hasPointerCapture(event.pointerId))
                canvas.releasePointerCapture(event.pointerId);
            if (g.kind === "pan") {
                api.render();
                return;
            }
            if (g.kind === "region") {
                if (regionReceiver) {
                    const receiver = regionReceiver;
                    regionReceiver = null;
                    tool = "select";
                    receiver(
                        g.moved
                            ? {
                                  minX: Math.min(g.start.x, g.end.x),
                                  minY: Math.min(g.start.y, g.end.y),
                                  maxX: Math.max(g.start.x, g.end.x),
                                  maxY: Math.max(g.start.y, g.end.y),
                              }
                            : null,
                    );
                    api.render();
                    return;
                }
                if (g.moved && G) {
                    const region =
                        g.tool === "lasso" ? g.path : M.rect(g.start, g.end);
                    if (region.length >= 3) {
                        const matches = G.selectRegion(
                            shown(),
                            region,
                            g.tool !== "lasso" && g.end.x < g.start.x,
                        );
                        select([
                            ...(g.add ? selection : []),
                            ...matches.map((m) => m.id),
                        ]);
                    }
                }
                api.render();
                return;
            }
            if (!permitted() || !g.moved) {
                if (
                    permitted() &&
                    !g.moved &&
                    g.kind === "draw" &&
                    clickShapes.has(g.tool)
                ) {
                    polygon = [g.start];
                    hover = null;
                }
                api.render();
                return;
            }
            if (g.kind === "draw") {
                if (g.tool === "eraser" && G) {
                    const next = items().flatMap((item) => {
                        if (
                            isLocked(item) ||
                            workbench?.hidden(item) ||
                            !["pen", "highlight"].includes(item.kind)
                        )
                            return [item];
                        return G.erase(
                            item.points,
                            g.path,
                            workbench?.eraserRadius() || 12 / api.scale(),
                        ).map((points, i) => ({
                            ...clone(item),
                            points,
                            id: i ? uid() : item.id,
                        }));
                    });
                    if (JSON.stringify(next) !== JSON.stringify(items()))
                        try {
                            apply(next, []);
                        } catch (error) {
                            api.status(error.message);
                        }
                    api.render();
                    return;
                }
                const item = preview(g);
                if (
                    ["area", "ellipse", "cloud"].includes(item.kind) &&
                    (!M.validPolygon(item.points) ||
                        M.distance(g.start, { x: g.end.x, y: g.start.y }) *
                            api.scale() <
                            3 ||
                        Math.abs(g.start.y - g.end.y) * api.scale() < 3)
                ) {
                    api.render();
                    return;
                }
                if (item.kind === "callout") {
                    item.text = "";
                    editText(item, true);
                } else commit(item);
            } else {
                if (
                    g.preview.some(
                        (item) =>
                            !item.points.every(finite) ||
                            ([
                                "area",
                                "ellipse",
                                "arc",
                                "curve",
                                "cloud",
                            ].includes(item.kind) &&
                                !(G
                                    ? G.valid(item)
                                    : M.validPolygon(item.points))),
                    )
                ) {
                    api.status(
                        "경계가 교차하거나 좌표 범위를 벗어나 변경을 취소했습니다.",
                    );
                    api.render();
                    return;
                }
                api.snapshot();
                for (const item of g.preview) {
                    const index = items().findIndex((m) => m.id === item.id);
                    if (index >= 0) items()[index] = item;
                }
            }
            panelKey = "";
            api.render();
        }
        canvas.addEventListener("pointerdown", pointerDown, true);
        canvas.addEventListener("pointermove", pointerMove, true);
        canvas.addEventListener("pointerup", pointerUp, true);
        for (const name of ["pointercancel", "lostpointercapture"])
            canvas.addEventListener(
                name,
                (event) => {
                    if (mode === "markup" && gesture) {
                        stop(event);
                        cancel();
                    }
                },
                true,
            );
        canvas.addEventListener(
            "dblclick",
            (event) => {
                if (mode !== "markup") return;
                stop(event);
                if (["area", "freecloud", "route", "hole"].includes(tool)) {
                    finishArea();
                    return;
                }
                const one = chosen();
                if (
                    one.length === 1 &&
                    ["pin", "callout", "text", "cloud", "route"].includes(
                        one[0].kind,
                    )
                )
                    editText(one[0]);
            },
            true,
        );
        function mutate(fn, includeLocked = false) {
            if (!permitted() || pending()) return;
            if (!includeLocked && chosen().some(isLocked)) {
                api.status(
                    "잠긴 표시가 포함되어 있습니다. 잠금을 해제한 뒤 편집하세요.",
                );
                return;
            }
            const selected = includeLocked ? chosen() : writable();
            if (!selected.length) return;
            api.snapshot();
            fn(selected);
            panelKey = "";
            api.render();
        }
        function remove() {
            mutate((selected) => {
                const ids = new Set(selected.map((m) => m.id));
                api.document().markups = items().filter((m) => !ids.has(m.id));
                selection.clear();
            });
        }
        function duplicate() {
            if (chosen().some(isLocked)) {
                api.status("잠긴 표시가 포함되어 있습니다.");
                return;
            }
            if (!permitted() || pending() || !writable().length) return;
            const groups = new Map();
            const copies = writable().map((m) => ({
                ...M.translate(clone(m), 20 / api.scale(), -20 / api.scale()),
                id: uid(),
                ...(m.group
                    ? {
                          group:
                              groups.get(m.group) ||
                              (groups.set(m.group, uid()), groups.get(m.group)),
                      }
                    : {}),
            }));
            if (
                items().length + copies.length > 1000 ||
                [...items(), ...copies].reduce(
                    (n, m) => n + (G ? G.pointCount(m) : m.points.length),
                    0,
                ) > 50000
            ) {
                api.status("표시 한도를 넘어서 복사할 수 없습니다.");
                return;
            }
            api.snapshot();
            items().push(...copies);
            selection = new Set(copies.map((m) => m.id));
            panelKey = "";
            api.render();
        }
        $("markup-delete").onclick = remove;
        $("markup-copy").onclick = duplicate;
        $("markup-edit-text").onclick = () => {
            if (chosen().length === 1) editText(chosen()[0]);
        };
        $("markup-lock").onclick = () =>
            mutate((selected) => {
                const lock = selected.some((m) => !m.locked);
                selected.forEach((m) => (m.locked = lock));
            }, true);
        $("markup-front").onclick = () =>
            mutate((selected) => {
                const ids = new Set(selected.map((m) => m.id));
                api.document().markups = [
                    ...items().filter((m) => !ids.has(m.id)),
                    ...selected,
                ];
            });
        $("markup-back").onclick = () =>
            mutate((selected) => {
                const ids = new Set(selected.map((m) => m.id));
                api.document().markups = [
                    ...selected,
                    ...items().filter((m) => !ids.has(m.id)),
                ];
            });
        $("markup-visible").onchange = () => {
            if (pending()) cancel();
            visible = $("markup-visible").checked;
            selection.clear();
            panelKey = "";
            api.render();
        };
        for (const id of [
            "markup-color",
            "markup-opacity",
            "markup-width",
            "markup-pattern",
        ])
            $(id).addEventListener("change", () => {
                const key = {
                        "markup-color": "color",
                        "markup-opacity": "opacity",
                        "markup-width": "width",
                        "markup-pattern": "pattern",
                    }[id],
                    value = settings()[key];
                if (chosen().length)
                    mutate((selected) =>
                        selected.forEach((m) => (m[key] = value)),
                    );
                else api.render();
            });
        $("markup-size").onchange = () => {
            const factor = Number($("markup-size").value);
            if (!Number.isFinite(factor) || factor < 25 || factor > 400) return;
            mutate((selected) =>
                selected.forEach(
                    (m) => (m.size = Math.min(1e9, (m.size * factor) / 100)),
                ),
            );
            $("markup-size").value = "100";
        };
        for (const button of document.querySelectorAll("[data-markup-color]"))
            button.onclick = () => {
                $("markup-color").value = button.dataset.markupColor;
                $("markup-color").dispatchEvent(new Event("change"));
            };
        const key = "erp.cad.markup-presets.v1";
        try {
            const saved = JSON.parse(localStorage.getItem(key) || "[]");
            if (Array.isArray(saved))
                presets = saved
                    .filter(
                        (p) =>
                            typeof p.name === "string" &&
                            p.name.length <= 30 &&
                            /^#[0-9a-f]{6}$/i.test(p.color) &&
                            p.opacity >= 0.05 &&
                            p.opacity <= 1 &&
                            p.width >= 1 &&
                            p.width <= 40 &&
                            ["solid", "diagonal", "cross"].includes(p.pattern),
                    )
                    .slice(0, 12);
        } catch {}
        function listPresets() {
            $("markup-presets").replaceChildren(new Option("내 도구 선택", ""));
            presets.forEach((p, i) =>
                $("markup-presets").add(new Option(p.name, String(i))),
            );
        }
        function storePresets() {
            try {
                localStorage.setItem(key, JSON.stringify(presets));
                listPresets();
                api.status("이 브라우저에 내 도구를 저장했습니다.");
            } catch {
                api.status("브라우저 저장 공간을 사용할 수 없습니다.");
            }
        }
        $("markup-preset-save").onclick = () => {
            const name = $("markup-preset-name").value.trim();
            if (!name) {
                api.status("내 도구 이름을 입력하세요.");
                return;
            }
            const existing = presets.findIndex((p) => p.name === name);
            if (existing < 0 && presets.length >= 12) {
                api.status("내 도구는 12개까지 저장합니다.");
                return;
            }
            const value = { name, ...settings() };
            if (existing < 0) presets.push(value);
            else presets[existing] = value;
            storePresets();
        };
        $("markup-presets").onchange = () => {
            const p = presets[Number($("markup-presets").value)];
            if (!p || $("markup-presets").value === "") return;
            for (const [id, value] of Object.entries({
                "markup-color": p.color,
                "markup-opacity": p.opacity * 100,
                "markup-width": p.width,
                "markup-pattern": p.pattern,
            }))
                $(id).value = value;
            mutate((selected) =>
                selected.forEach((m) =>
                    Object.assign(m, {
                        color: p.color,
                        opacity: p.opacity,
                        width: p.width,
                        pattern: p.pattern,
                    }),
                ),
            );
            api.render();
        };
        $("markup-preset-delete").onclick = () => {
            if ($("markup-presets").value === "") return;
            presets.splice(Number($("markup-presets").value), 1);
            storePresets();
        };
        listPresets();
        function panel() {
            selection = new Set(
                [...selection].filter((id) => items().some((m) => m.id === id)),
            );
            const one = chosen(),
                signature = JSON.stringify([
                    one,
                    api.canManage(),
                    api.busy(),
                    tool,
                    items().length,
                    visible,
                ]);
            $("markup-opacity-value").textContent =
                $("markup-opacity").value + "%";
            if (panelKey === signature) return;
            panelKey = signature;
            $("markup-count").textContent = items().length + "개 표시";
            $("markup-selection").textContent = one.length
                ? `${one.length}개 선택${one.some((m) => m.locked) ? " · 잠금 포함" : ""}`
                : "선택한 표시 없음";
            $("markup-property-title").textContent = one.length
                ? "선택한 표시 속성"
                : "새 표시 기본값";
            if (one.length) {
                const m = one[0];
                $("markup-color").value = m.color;
                $("markup-opacity").value = Math.round(m.opacity * 100);
                $("markup-width").value = m.width;
                $("markup-pattern").value = m.pattern;
                $("markup-opacity-value").textContent =
                    $("markup-opacity").value + "%";
            }
            for (const id of [
                "markup-delete",
                "markup-copy",
                "markup-front",
                "markup-back",
                "markup-size",
            ])
                $(id).disabled = !permitted() || !writable().length;
            for (const id of [
                "markup-color",
                "markup-opacity",
                "markup-width",
                "markup-pattern",
            ])
                $(id).disabled =
                    !permitted() || (one.length > 0 && !writable().length);
            $("markup-edit-text").disabled =
                !permitted() ||
                one.length !== 1 ||
                one[0].locked ||
                !["callout", "pin", "text", "cloud", "route"].includes(
                    one[0].kind,
                );
            $("markup-lock").disabled = !permitted() || !one.length;
            $("markup-lock").textContent =
                one.length && one.every((m) => m.locked) ? "잠금 해제" : "잠금";
            for (const button of document.querySelectorAll(
                "[data-markup-tool]",
            )) {
                button.setAttribute(
                    "aria-pressed",
                    String(button.dataset.markupTool === tool),
                );
                button.disabled =
                    !api.document() ||
                    api.busy() ||
                    (button.dataset.markupTool !== "select" &&
                        !api.canManage());
            }
            $("markup-list").replaceChildren();
            for (const item of [...items()].reverse()) {
                const button = document.createElement("button");
                button.className = "markup-list-item";
                button.dataset.markupId = item.id;
                button.setAttribute(
                    "aria-pressed",
                    String(selection.has(item.id)),
                );
                const swatch = document.createElement("span");
                swatch.className = "markup-swatch";
                swatch.style.background = item.color;
                const label = document.createElement("span");
                label.textContent =
                    (item.locked ? "🔒 " : "") +
                    {
                        area: "영역",
                        ellipse: "원 / 타원",
                        cloud: "변경 구름",
                        pen: "자유 펜",
                        highlight: "형광펜",
                        pin: "핀",
                        callout: "메모",
                        text: "문자",
                        route: "경로",
                        detail: "확대 상세도",
                    }[item.kind] +
                    (item.text
                        ? " · " + item.text.replace(/\n/g, " ").slice(0, 35)
                        : "");
                button.append(swatch, label);
                button.onclick = (event) => {
                    if (pending()) cancel();
                    pick(item, event.shiftKey);
                    tool = "select";
                    visible = true;
                    $("markup-visible").checked = true;
                    panelKey = "";
                    api.render();
                };
                $("markup-list").append(button);
            }
        }
        function render(ctx, options = {}) {
            if (mode === "markup" && !options.paper)
                canvas.style.cursor =
                    gesture?.kind === "pan"
                        ? "grabbing"
                        : space
                          ? "grab"
                          : gesture?.kind === "move"
                            ? "move"
                            : gesture?.kind === "vertex"
                              ? "nwse-resize"
                              : tool === "select"
                                ? "default"
                                : "crosshair";
            options = { ...options, doc: api.document() };
            if (visible || options.paper) {
                const previewMap = new Map(
                    (gesture?.preview || []).map((m) => [m.id, m]),
                );
                for (const item of shown())
                    M.draw(
                        ctx,
                        previewMap.get(item.id) || item,
                        api.screen,
                        api.scale(),
                        options,
                    );
                if (!options.paper) {
                    const p = preview(gesture);
                    if (p) M.draw(ctx, p, api.screen, api.scale(), options);
                }
            }
            if (!options.paper && mode === "markup") {
                if (polygon.length) {
                    const ps = [...polygon, ...(hover ? [hover] : [])];
                    let item;
                    if (multiTools.has(tool) || clickShapes.has(tool)) {
                        try {
                            const shape = multiTools.has(tool)
                                ? construction(ps)
                                : null;
                            item = shape
                                ? makeGeometry(shape)
                                : clickShapes.has(tool) && ps.length === 2
                                  ? preview({
                                        kind: "draw",
                                        tool,
                                        start: ps[0],
                                        end: ps[1],
                                    })
                                  : create("route", ps);
                            if (tool === "curve") item.arrows = "end";
                        } catch {
                            item = create("route", ps);
                        }
                    } else
                        item = create(
                            tool === "route" || tool === "calibrate"
                                ? "route"
                                : tool === "freecloud"
                                  ? "cloud"
                                  : "area",
                            ps,
                        );
                    if (item.points.length > 1)
                        M.draw(ctx, item, api.screen, api.scale(), options);
                    ctx.save();
                    ctx.fillStyle =
                        options.background === "white" ? "#334155" : "#fff";
                    polygon.forEach((p) => {
                        const q = api.screen(p);
                        ctx.fillRect(q.x - 3, q.y - 3, 6, 6);
                    });
                    ctx.restore();
                }
                if (visible)
                    for (const item of chosen()) {
                        if (workbench?.hidden(item)) continue;
                        const shown =
                                gesture?.preview?.find(
                                    (m) => m.id === item.id,
                                ) || item,
                            b = M.bounds(shown),
                            a = api.screen({ x: b.minX, y: b.maxY }),
                            z = api.screen({ x: b.maxX, y: b.minY });
                        ctx.save();
                        ctx.strokeStyle =
                            options.color?.("#7dd3fc") || "#7dd3fc";
                        ctx.lineWidth = 1;
                        ctx.setLineDash([4, 4]);
                        ctx.strokeRect(
                            a.x - 4,
                            a.y - 4,
                            z.x - a.x + 8,
                            z.y - a.y + 8,
                        );
                        ctx.setLineDash([]);
                        if (shown.kind === "curve") {
                            ctx.setLineDash([3, 3]);
                            ctx.beginPath();
                            const ps = shown.points.map(api.screen);
                            ctx.moveTo(ps[0].x, ps[0].y);
                            ctx.lineTo(ps[1].x, ps[1].y);
                            ctx.moveTo(ps[2].x, ps[2].y);
                            ctx.lineTo(ps[3].x, ps[3].y);
                            ctx.stroke();
                            ctx.setLineDash([]);
                        }
                        if (
                            chosen().length === 1 &&
                            !isLocked(item) &&
                            api.canManage()
                        )
                            handles(shown)
                                .map((handle) => handle.point)
                                .filter(
                                    (_, i) =>
                                        item.points.length < 50 ||
                                        i === 0 ||
                                        i === item.points.length - 1,
                                )
                                .forEach((p) => {
                                    const q = api.screen(p);
                                    ctx.fillStyle = "#102033";
                                    ctx.fillRect(q.x - 4, q.y - 4, 8, 8);
                                    ctx.strokeRect(q.x - 4, q.y - 4, 8, 8);
                                });
                        ctx.restore();
                    }
                $("hint").textContent =
                    names[tool] +
                    " · " +
                    hints[tool] +
                    " · Space+드래그 화면 이동";
            }
            if (!options.paper) {
                hud.hidden = mode !== "markup";
                const entering =
                    polygon.length > 0 &&
                    !gesture &&
                    (multiTools.has(tool) ||
                        clickShapes.has(tool) ||
                        ["area", "freecloud", "route", "hole"].includes(tool));
                $("markup-numeric").disabled = !entering || !permitted();
                $("markup-numeric-apply").disabled = !entering || !permitted();
                if (entering) {
                    const a = polygon.at(-1),
                        p = hover || a;
                    let label = [
                        "box",
                        "ellipse",
                        "triangle",
                        "diamond",
                        "polygon",
                        "star",
                        "rounded",
                        "capsule",
                    ].includes(tool)
                        ? "가로,세로"
                        : tool === "circle"
                          ? "지름"
                          : tool === "arc2" && polygon.length === 2
                            ? "돌출 높이"
                            : tool === "rotbox" && polygon.length === 2
                              ? "사각형 폭"
                              : ["arc", "pie"].includes(tool) &&
                                  polygon.length === 2
                                ? "회전각(°)"
                                : "다음 점 길이<각도";
                    $("markup-numeric-label").textContent = label;
                    $("markup-drawing-readout").textContent =
                        `${names[tool]} · ${polygon.length}점 지정 · 거리 ${Number(M.distance(a, p).toPrecision(6))} · 각도 ${Number(((Math.atan2(p.y - a.y, p.x - a.x) * 180) / Math.PI).toFixed(1))}°`;
                } else {
                    $("markup-numeric-label").textContent = "치수";
                    $("markup-drawing-readout").textContent =
                        names[tool] +
                        " · 점을 지정하면 길이·각도를 입력할 수 있습니다.";
                }
                canvas.dataset.markupSnap = preciseMark?.kind || "";
                if (preciseMark && mode === "markup") {
                    const p = api.screen(preciseMark);
                    canvas.dataset.markupSnapX = preciseMark.x;
                    canvas.dataset.markupSnapY = preciseMark.y;
                    ctx.save();
                    ctx.strokeStyle =
                        options.background === "white" ? "#b45309" : "#fbbf24";
                    ctx.fillStyle =
                        options.background === "white" ? "#ffffff" : "#0f172a";
                    ctx.lineWidth = 2;
                    ctx.setLineDash([]);
                    ctx.strokeRect(p.x - 5, p.y - 5, 10, 10);
                    ctx.font = "12px sans-serif";
                    const w = ctx.measureText(preciseMark.kind).width;
                    ctx.fillRect(p.x + 10, p.y - 24, w + 10, 21);
                    ctx.fillStyle = ctx.strokeStyle;
                    ctx.fillText(preciseMark.kind, p.x + 15, p.y - 9);
                    ctx.restore();
                }
                if (gesture?.kind === "region") {
                    const ps =
                        gesture.tool === "lasso"
                            ? gesture.path
                            : M.rect(gesture.start, gesture.end);
                    ctx.save();
                    const color =
                        gesture.end.x < gesture.start.x ? "#34d399" : "#38bdf8";
                    ctx.strokeStyle = options.color?.(color) || color;
                    ctx.fillStyle = "rgba(56,189,248,.1)";
                    ctx.lineWidth = 1;
                    ctx.setLineDash([5, 3]);
                    ctx.beginPath();
                    ps.map(api.screen).forEach((p, i) =>
                        i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y),
                    );
                    ctx.closePath();
                    ctx.fill();
                    ctx.stroke();
                    ctx.restore();
                }
                if (tool === "eraser" && hover) {
                    const p = api.screen(hover);
                    ctx.save();
                    ctx.strokeStyle =
                        options.background === "white" ? "#334155" : "#fff";
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.arc(
                        p.x,
                        p.y,
                        (workbench?.eraserRadius() || 12 / api.scale()) *
                            api.scale(),
                        0,
                        Math.PI * 2,
                    );
                    ctx.stroke();
                    ctx.restore();
                }
                panel();
                workbench?.refresh();
            }
        }
        document.addEventListener(
            "keydown",
            (event) => {
                if ($("markup-dialog").open) {
                    if (
                        (event.ctrlKey || event.metaKey) &&
                        event.key === "Enter"
                    ) {
                        stop(event);
                        $("markup-dialog-confirm").click();
                    } else if (
                        (event.ctrlKey || event.metaKey) &&
                        event.key.toLowerCase() === "s"
                    )
                        stop(event);
                    return;
                }
                if (
                    mode !== "markup" ||
                    ["INPUT", "SELECT", "TEXTAREA"].includes(
                        event.target.tagName,
                    ) ||
                    $("text-dialog").open
                )
                    return;
                if (event.code === "Space") {
                    stop(event);
                    space = true;
                    canvas.style.cursor = "grab";
                    return;
                }
                if (event.key === "Escape") {
                    stop(event);
                    cancel();
                    tool = "select";
                    selection.clear();
                    panelKey = "";
                    api.render();
                    return;
                }
                if (
                    (polygon.length || gesture?.kind === "draw") &&
                    [
                        "ArrowRight",
                        "ArrowLeft",
                        "ArrowUp",
                        "ArrowDown",
                    ].includes(event.key)
                ) {
                    stop(event);
                    directionLock =
                        event.key === "ArrowRight"
                            ? 0
                            : ["ArrowLeft", "ArrowUp"].includes(event.key)
                              ? 90
                              : null;
                    if (lastRaw)
                        hover = precision(
                            lastRaw,
                            polygon.at(-1) || gesture?.start,
                        );
                    api.render();
                    return;
                }
                if (
                    polygon.length &&
                    /^[0-9.\-]$/.test(event.key) &&
                    !event.ctrlKey &&
                    !event.metaKey
                ) {
                    stop(event);
                    $("markup-numeric").value = event.key;
                    $("markup-numeric").focus();
                    return;
                }
                if (pending()) {
                    if (event.key === "Backspace" && polygon.length) {
                        stop(event);
                        polygon.pop();
                        api.render();
                        return;
                    }
                    if (
                        event.key === "Enter" &&
                        ["area", "freecloud", "route", "hole"].includes(tool)
                    ) {
                        stop(event);
                        finishArea();
                    } else if (
                        (event.ctrlKey || event.metaKey) &&
                        ["s", "z", "y"].includes(event.key.toLowerCase())
                    ) {
                        stop(event);
                        api.status("표시를 완성하거나 Esc로 취소하세요.");
                    }
                    return;
                }
                if (event.key === "Delete" || event.key === "Backspace") {
                    stop(event);
                    remove();
                }
                if (
                    (event.ctrlKey || event.metaKey) &&
                    event.key.toLowerCase() === "d"
                ) {
                    stop(event);
                    duplicate();
                }
                if (
                    (event.ctrlKey || event.metaKey) &&
                    event.key.toLowerCase() === "a"
                ) {
                    stop(event);
                    selection = new Set(shown().map((m) => m.id));
                    panelKey = "";
                    api.render();
                }
                if (
                    [
                        "ArrowLeft",
                        "ArrowRight",
                        "ArrowUp",
                        "ArrowDown",
                    ].includes(event.key)
                ) {
                    stop(event);
                    const step = (event.shiftKey ? 10 : 1) / api.scale();
                    mutate((selected) =>
                        selected.forEach((m) => {
                            const dx =
                                    event.key === "ArrowLeft"
                                        ? -step
                                        : event.key === "ArrowRight"
                                          ? step
                                          : 0,
                                dy =
                                    event.key === "ArrowUp"
                                        ? step
                                        : event.key === "ArrowDown"
                                          ? -step
                                          : 0;
                            Object.assign(m, M.translate(m, dx, dy));
                        }),
                    );
                }
            },
            true,
        );
        document.addEventListener("keyup", (event) => {
            if (event.code === "Space") {
                space = false;
                api.render();
            }
        });
        window.addEventListener("blur", () => {
            space = false;
            if (gesture) cancel();
        });
        workbench = window.createCADMarkupWorkbench?.(api, {
            items,
            chosen,
            pending,
            apply,
            select,
            setTool,
        });
        document.body.dataset.workspace = mode;
        return {
            render,
            refresh: panel,
            pending,
            cancel,
            mode: () => mode,
            reset: () => {
                cancel();
                selection.clear();
                panelKey = "";
                visible = true;
                $("markup-visible").checked = true;
                tool = "select";
                $("properties").scrollTop = 0;
            },
            changed: () => {
                selection.clear();
                panelKey = "";
            },
            items,
            selected: chosen,
            apply,
            select,
            pickRegion: (receiver) => {
                setMode("markup");
                setTool("region");
                regionReceiver = receiver;
                api.render();
            },
        };
    };
})();
