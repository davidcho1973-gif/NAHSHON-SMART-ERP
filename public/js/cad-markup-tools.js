(function () {
    "use strict";
    const M = window.CADMarkup,
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
    };
    window.createCADMarkupTools = function (api) {
        let mode = "markup",
            tool = "select",
            selection = new Set(),
            gesture = null,
            polygon = [],
            hover = null,
            draft = null;
        let panelKey = "",
            presets = [],
            visible = true,
            space = false;
        const canvas = api.canvas,
            clone = (value) => structuredClone(value);
        const items = () => api.document()?.markups || [];
        const permitted = () =>
            api.document() && api.canManage() && !api.busy();
        const chosen = () => items().filter((item) => selection.has(item.id));
        const writable = () => chosen().filter((item) => !item.locked);
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
            return {
                id: uid(),
                kind,
                points: clone(points),
                ...style,
                size: 16 / api.scale(),
                text: "",
                locked: false,
            };
        }
        function commit(item) {
            if (!permitted()) return;
            if (
                items().length >= 1000 ||
                items().reduce((n, m) => n + m.points.length, 0) +
                    item.points.length >
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
                item.size > 1e9
            ) {
                api.status(
                    "도면 좌표 범위를 벗어났습니다. 전체 보기 후 다시 시도하세요.",
                );
                return;
            }
            api.snapshot();
            api.document().markups.push(item);
            selection = new Set([item.id]);
            polygon = [];
            hover = null;
            tool = "select";
            panelKey = "";
            $("properties").scrollTop = 0;
            api.render();
        }
        function cancel() {
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
        }
        function pending() {
            return !!gesture || polygon.length > 0 || $("markup-dialog").open;
        }
        function setTool(next) {
            if (api.busy()) return;
            cancel();
            tool = next;
            selection.clear();
            panelKey = "";
            if (["cloud", "pen", "callout", "pin"].includes(next))
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
            const button = document.createElement("button");
            button.type = "button";
            button.dataset.markupTool = key;
            button.textContent = name;
            button.onclick = () => setTool(key);
            $("markup-tools").append(button);
        }
        function editText(item, fresh = false) {
            if (!permitted() || item.locked) return;
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
            if (!permitted() || polygon.length < 3) return;
            if (!M.validPolygon(polygon)) {
                api.status(
                    "경계가 겹치거나 면적이 없습니다. Esc로 취소한 뒤 경계 순서대로 지정하세요.",
                );
                return;
            }
            commit(create("area", polygon));
        }
        function pointerDown(event) {
            if (mode !== "markup" || event.button !== 0) return;
            stop(event);
            canvas.focus();
            if (!api.document() || api.busy() || $("markup-dialog").open)
                return;
            const raw = api.world(event),
                p = ["area", "box", "cloud", "callout"].includes(tool)
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
                const grip =
                    chosen().length === 1 && !chosen()[0].locked
                        ? chosen()[0].points.findIndex(
                              (q) => M.distance(q, raw) * api.scale() < 8,
                          )
                        : -1;
                const hit = visible
                    ? [...items()]
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
                        if (event.shiftKey) {
                            selection.has(hit.id)
                                ? selection.delete(hit.id)
                                : selection.add(hit.id);
                        } else if (!selection.has(hit.id))
                            selection = new Set([hit.id]);
                    } else if (!event.shiftKey) selection.clear();
                }
                if (
                    permitted() &&
                    (grip >= 0 || hit) &&
                    !event.shiftKey &&
                    writable().length
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
                panelKey = "";
                api.render();
                return;
            }
            if (!permitted()) {
                api.status("읽기 전용 도면입니다.");
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
            if (tool === "area") {
                if (
                    polygon.length >= 3 &&
                    M.distance(polygon[0], p) * api.scale() < 12
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
                    M.distance(p, polygon.at(-1)) * api.scale() > 2
                )
                    polygon.push(p);
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
                kind: "draw",
                tool,
                pointer: event.pointerId,
                start: p,
                end: p,
                path: [p],
                moved: false,
            };
            canvas.setPointerCapture(event.pointerId);
            api.render();
        }
        function pointerMove(event) {
            if (mode !== "markup" || (!gesture && event.buttons !== 0)) return;
            if (event.buttons === 2 || event.buttons === 4) return;
            stop(event);
            if (!api.document() || api.busy()) return;
            if (!gesture && !polygon.length) return;
            const raw = api.world(event);
            hover = polygon.length ? api.snap(raw) : raw;
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
                if (g.kind === "draw") {
                    g.end = ["pen", "highlight"].includes(g.tool)
                        ? raw
                        : api.snap(raw);
                    if (
                        ["pen", "highlight"].includes(g.tool) &&
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
                            : {
                                  ...item,
                                  points: item.points.map((p, i) =>
                                      i === g.grip ? end : p,
                                  ),
                              },
                    );
                }
            }
            api.render();
        }
        function preview(g) {
            if (!g || g.kind !== "draw") return null;
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
            if (!permitted() || !g.moved) {
                api.render();
                return;
            }
            if (g.kind === "draw") {
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
                                !M.validPolygon(item.points)),
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
                if (tool === "area") {
                    finishArea();
                    return;
                }
                const one = chosen();
                if (
                    one.length === 1 &&
                    ["pin", "callout"].includes(one[0].kind)
                )
                    editText(one[0]);
            },
            true,
        );
        function mutate(fn, includeLocked = false) {
            if (!permitted() || pending()) return;
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
            if (!permitted() || pending() || !writable().length) return;
            const copies = writable().map((m) => ({
                ...M.translate(m, 20 / api.scale(), -20 / api.scale()),
                id: uid(),
            }));
            if (
                items().length + copies.length > 1000 ||
                [...items(), ...copies].reduce(
                    (n, m) => n + m.points.length,
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
                !["callout", "pin"].includes(one[0].kind);
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
                    }[item.kind] +
                    (item.text
                        ? " · " + item.text.replace(/\n/g, " ").slice(0, 35)
                        : "");
                button.append(swatch, label);
                button.onclick = (event) => {
                    if (pending()) cancel();
                    if (event.shiftKey) {
                        selection.has(item.id)
                            ? selection.delete(item.id)
                            : selection.add(item.id);
                    } else selection = new Set([item.id]);
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
            if (visible || options.paper) {
                const previewMap = new Map(
                    (gesture?.preview || []).map((m) => [m.id, m]),
                );
                for (const item of items())
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
                    const item = create("area", ps);
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
                            !item.locked &&
                            api.canManage()
                        )
                            shown.points
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
            if (!options.paper) panel();
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
                    if (event.key === "Enter" && tool === "area") {
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
                    selection = new Set(items().map((m) => m.id));
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
                            m.points = M.translate(m, dx, dy).points;
                        }),
                    );
                }
            },
            true,
        );
        document.addEventListener("keyup", (event) => {
            if (event.code === "Space") space = false;
        });
        window.addEventListener("blur", () => {
            space = false;
            if (gesture) cancel();
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
        };
    };
})();
