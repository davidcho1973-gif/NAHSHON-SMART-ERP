(function () {
    "use strict";
    const M = window.CADMarkup,
        G = window.CADMarkupGeometry,
        $ = (id) => document.getElementById(id);
    const names = {
        select: "선택 / 이동",
        boundary: "닫힌 경계 색칠",
        area: "자유 영역",
        box: "사각 영역",
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
        select: "클릭하여 선택 · Shift로 추가 · 드래그 이동 · 꼭짓점으로 모양 수정",
        boundary:
            "원본의 닫힌 직선 폴리선 안쪽 클릭 · 경계가 없으면 자유 영역 사용",
        area: "경계를 차례로 클릭 · 첫 점 클릭 또는 Enter로 완성 · Esc 취소",
        box: "대각선으로 드래그하여 영역 지정",
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
    window.createCADMarkupTools = function (api) {
        let mode = "markup",
            tool = "select",
            selection = new Set(),
            gesture = null,
            polygon = [],
            hover = null,
            draft = null,
            workbench = null, regionReceiver = null;
        let panelKey = "",
            presets = [],
            visible = true,
            space = false;
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
            (item.kind === 'detail' ? [] : [item.points, ...(item.holes || [])]).flatMap((ring, r) =>
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
                (G && !G.valid(item)) ||
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
        }
        function cancel() {
            const receiver=regionReceiver;regionReceiver=null;
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
            return !!gesture || !!regionReceiver || polygon.length > 0 || $("markup-dialog").open;
        }
        function setTool(next) {
            if (api.busy()) return;
            cancel();
            tool = next;
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
                    "pin",
                ].includes(next)
            )
                $("markup-opacity").value = "100";
            else if (["boundary", "box", "area", "highlight"].includes(next))
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
        for (const [key, name] of Object.entries(names)) {
            if(key==="region")continue;
            const button = document.createElement("button");
            button.type = "button";
            button.dataset.markupTool = key;
            button.textContent = name;
            if(key==='select'){button.innerHTML='<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M5 3L19 13L12 14L9 21Z" fill="currentColor" stroke="currentColor" stroke-linejoin="round"/></svg><span>선택</span>';button.setAttribute('aria-label','선택');}
            button.onclick = () => setTool(key);
            $("markup-tools").append(button);
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
                p = [
                    "area",
                    "box",
                    "cloud",
                    "freecloud",
                    "route",
                    "calibrate",
                    "callout",
                ].includes(tool)
                    ? api.snap(raw)
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
            if (!permitted() && !["lasso","region"].includes(tool)) {
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
            if (tool === "boundary") {
                const boundary = api.boundary(raw);
                if (!boundary) {
                    api.status(
                        "이 위치에는 지원되는 닫힌 직선 폴리선이 없습니다. 자유 영역으로 경계를 지정하세요.",
                    );
                    return;
                }
                commit(create("area", boundary));
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
                kind: ["lasso","region"].includes(tool) ? "region" : "draw",
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
            if (!gesture && !polygon.length && tool !== 'eraser'){const raw=api.world(event);canvas.style.cursor=space?'grab':tool==='select'&&chosen().some(item=>!isLocked(item)&&handles(item).some(h=>M.distance(h.point||h,raw)*api.scale()<8))?'nwse-resize':tool==='select'?'default':'crosshair';return;}
            const raw = api.world(event);
            hover = polygon.length ? api.snap(raw) : raw;
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
                            : api.snap(raw);
                    if (
                        (["pen", "highlight", "eraser"].includes(g.tool) ||
                            g.tool === "lasso") &&
                        g.path.length < 2048 &&
                        M.distance(g.path.at(-1), raw) * api.scale() >= 2
                    )
                        g.path.push(raw);
                } else {
                    const end = g.kind === "vertex" ? api.snap(raw) : raw;
                    g.preview = g.original.map((item) =>
                        g.kind === "move"
                            ? M.translate(
                                  item,
                                  end.x - g.start.x,
                                  end.y - g.start.y,
                              )
                            : (() => {
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
                cloud: "cloud",
                callout: "callout",
                pen: "pen",
                highlight: "highlight",
            }[g.tool];
            const ps = ["box", "cloud"].includes(g.tool)
                ? M.rect(g.start, g.end)
                : ["pen", "highlight"].includes(g.tool)
                  ? g.path
                  : [g.start, g.end];
            const item = create(kind, ps);
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
                if(regionReceiver){const receiver=regionReceiver;regionReceiver=null;tool="select";receiver(g.moved?{minX:Math.min(g.start.x,g.end.x),minY:Math.min(g.start.y,g.end.y),maxX:Math.max(g.start.x,g.end.x),maxY:Math.max(g.start.y,g.end.y)}:null);api.render();return;}
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
                    ["area", "cloud"].includes(item.kind) &&
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
                            (["area", "cloud"].includes(item.kind) &&
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
                if (["area", "freecloud", "route"].includes(tool)) {
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
            if(mode==='markup'&&!options.paper)canvas.style.cursor=gesture?.kind==='pan'?'grabbing':space?'grab':gesture?.kind==='move'?'move':gesture?.kind==='vertex'?'nwse-resize':tool==='select'?'default':'crosshair';
            options = {...options,doc:api.document()};
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
                    if (p) M.draw(ctx, p, api.screen, api.scale());
                }
            }
            if (!options.paper && mode === "markup") {
                if (polygon.length) {
                    const ps = [...polygon, ...(hover ? [hover] : [])];
                    const item = create(
                        tool === "route" || tool === "calibrate"
                            ? "route"
                            : tool === "freecloud"
                              ? "cloud"
                              : "area",
                        ps,
                    );
                    M.draw(ctx, item, api.screen, api.scale());
                    ctx.save();
                    ctx.fillStyle = "#fff";
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
                        ctx.strokeStyle = "#7dd3fc";
                        ctx.lineWidth = 1;
                        ctx.setLineDash([4, 4]);
                        ctx.strokeRect(
                            a.x - 4,
                            a.y - 4,
                            z.x - a.x + 8,
                            z.y - a.y + 8,
                        );
                        ctx.setLineDash([]);
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
                if (gesture?.kind === "region") {
                    const ps =
                        gesture.tool === "lasso"
                            ? gesture.path
                            : M.rect(gesture.start, gesture.end);
                    ctx.save();
                    ctx.strokeStyle =
                        gesture.end.x < gesture.start.x ? "#34d399" : "#38bdf8";
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
                    ctx.strokeStyle = "#fff";
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
                    space = true;canvas.style.cursor="grab";
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
                if (pending()) {
                    if (
                        event.key === "Enter" &&
                        ["area", "freecloud", "route"].includes(tool)
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
            if (event.code === "Space") {space = false;api.render();}
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
            apply,select,
            pickRegion: receiver => {setMode("markup");setTool("region");regionReceiver=receiver;api.render();},
        };
    };
})();
