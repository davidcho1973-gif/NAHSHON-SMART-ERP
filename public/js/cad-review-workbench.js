(function () {
    "use strict";
    window.createCADReviewWorkbench = function (api) {
        const R = window.CADReview,
            C = window.CADCore,
            M = window.CADMarkup;
        const host = document.createElement("details");
        host.id = "cad-review-tools";
        host.innerHTML =
            '<summary>상세도 · 기호 계수 · 개정 비교</summary><button id="cr-detail">원본 연결 확대 상세도</button><button id="cr-count">반복 기호 찾아 검토</button><button id="cr-compare">신·구 도면 비교</button><p class="note">원본 CAD의 블록·원을 검토 계수합니다. 스캔 이미지 자동 인식은 포함하지 않습니다.</p>';
        document.getElementById("properties").append(host);
        const dialog = document.createElement("dialog");
        dialog.id = "cad-review-dialog";
        dialog.className = "cad-dialog cad-review-dialog";
        dialog.innerHTML =
            '<h2 id="cr-title"></h2><div id="cr-controls"></div><p id="cr-state" role="status"></p><div class="review-canvases"><canvas id="cr-old" width="700" height="450" aria-label="이전 도면 기준점 선택"></canvas><canvas id="cr-new" width="700" height="450" aria-label="현재 도면 검토"></canvas></div><div id="cr-list" class="review-list"></div><div class="dialog-actions"><button id="cr-close">닫기</button><button id="cr-apply">검토한 후보 적용</button></div>';
        document.body.append(dialog);
        const $ = (id) => document.getElementById("cr-" + id);
        let mode = null,
            captured = null,
            oldDoc = null,
            oldRecord = null,
            rows = [],
            checked = new Set(),
            alignment = R.identity,
            anchors = [[], []],
            views = [],
            working = false,
            epoch = 0,
            seedId = null;
        function ready() {
            const s = api.state();
            if (
                !s.doc ||
                s.busy ||
                s.pending ||
                document.querySelector("dialog[open]")
            )
                return null;
            return s;
        }
        function error(e) {
            $("state").textContent = e.message || String(e);
        }
        function draw(doc, index, transform = R.identity) {
            const canvas = index ? $("new") : $("old"),
                ctx = canvas.getContext("2d"),
                b = R.bounds(
                    R.visible(doc)
                        .flatMap((e) => C.geometry(e))
                        .map((p) => R.map(p, transform)),
                );
            if (!Number.isFinite(b.minX)) {
                b.minX = 0;
                b.minY = 0;
                b.maxX = 100;
                b.maxY = 100;
            }
            const scale = Math.min(
                    (canvas.width - 40) / Math.max(1e-9, b.maxX - b.minX),
                    (canvas.height - 40) / Math.max(1e-9, b.maxY - b.minY),
                ),
                center = R.center(b),
                screen = (p) => ({
                    x: canvas.width / 2 + (p.x - center.x) * scale,
                    y: canvas.height / 2 - (p.y - center.y) * scale,
                });
            views[index] = {
                screen,
                scale,
                world: (p) => ({
                    x: center.x + (p.x - canvas.width / 2) / scale,
                    y: center.y - (p.y - canvas.height / 2) / scale,
                }),
            };
            ctx.fillStyle = "#0c1423";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            C.renderEntities(
                ctx,
                doc,
                R.visible(doc),
                (p) => screen(R.map(p, transform)),
                scale * Math.hypot(transform.a, transform.b),
            );
            if (index && mode === "compare" && oldDoc) {
                ctx.save();
                ctx.globalAlpha = Number($("blend")?.value ?? 0.35);
                ctx.strokeStyle = "#fb7185";
                ctx.lineWidth = 1;
                for (const e of R.visible(oldDoc)) {
                    ctx.beginPath();
                    C.geometry(e).forEach((p, i) => {
                        const v = screen(R.map(p, alignment));
                        i ? ctx.lineTo(v.x, v.y) : ctx.moveTo(v.x, v.y);
                    });
                    ctx.stroke();
                }
                ctx.restore();
            }
            for (let i = 0; i < anchors[index].length; i++) {
                const p = screen(anchors[index][i]);
                ctx.fillStyle = "#fbbf24";
                ctx.fillRect(p.x - 5, p.y - 5, 10, 10);
                ctx.font = "16px sans-serif";
                ctx.fillText(String(i + 1), p.x + 8, p.y);
            }
            if (index)
                rows.forEach((row, i) => {
                    if (!checked.has(i)) return;
                    const b = row.bounds,
                        a = screen({ x: b.minX, y: b.maxY }),
                        z = screen({ x: b.maxX, y: b.minY });
                    ctx.strokeStyle =
                        row.kind === "removed" ? "#fb7185" : "#34d399";
                    ctx.lineWidth = 2;
                    ctx.strokeRect(
                        a.x - 3,
                        a.y - 3,
                        z.x - a.x + 6,
                        z.y - a.y + 6,
                    );
                });
        }
        function redraw() {
            if (oldDoc) draw(oldDoc, 0);
            if (captured) draw(captured.doc, 1);
        }
        function renderList() {
            $("list").replaceChildren();
            const summary = document.createElement("p");
            summary.textContent = `후보 ${rows.length}개 · 선택 ${checked.size}개 · 확인한 항목만 체크하세요.`;
            $("list").append(summary);
            const all = document.createElement("button");
            all.textContent = "전체 선택";
            all.onclick = () => {
                checked = new Set(rows.map((_, i) => i));
                renderList();
                redraw();
            };
            const none = document.createElement("button");
            none.textContent = "선택 해제";
            none.onclick = () => {
                checked.clear();
                renderList();
                redraw();
            };
            $("list").append(all, none);
            rows.forEach((row, i) => {
                const label = document.createElement("label"),
                    input = document.createElement("input"),
                    zoom = document.createElement("button");
                input.type = "checkbox";
                input.checked = checked.has(i);
                input.setAttribute("aria-label", `후보 ${i + 1}`);
                input.onchange = () => {
                    input.checked ? checked.add(i) : checked.delete(i);
                    summary.textContent = `후보 ${rows.length}개 · 선택 ${checked.size}개`;
                    redraw();
                };
                label.append(
                    input,
                    document.createTextNode(
                        `${i + 1}. ${row.kind ? { added: "추가", removed: "삭제", changed: "변경" }[row.kind] + " · " : ""}${row.label}`,
                    ),
                );
                zoom.textContent = "위치 보기";
                zoom.onclick = () => {
                    const b = row.bounds;
                    api.focus(b);
                };
                label.append(zoom);
                $("list").append(label);
            });
            $("apply").disabled =
                !captured.canManage || working || !rows.length;
        }
        function start(kind, state) {
            epoch++;
            mode = kind;
            captured = {
                doc: C.copyDocument(state.doc),
                record: structuredClone(state.record),
                canManage: state.canManage,
                version: state.version,
            };
            oldDoc = null;
            oldRecord = null;
            rows = [];
            checked.clear();
            anchors = [[], []];
            alignment = R.identity;
            seedId = null;
            working = false;
            $("controls").replaceChildren();
            $("list").replaceChildren();
            $("state").textContent = "";
            $("old").hidden = kind !== "compare";
            $("title").textContent =
                kind === "count" ? "반복 기호 검토 계수" : "신·구 도면 비교";
            $("apply").textContent =
                kind === "count"
                    ? "체크한 기호를 번호 핀으로 추가"
                    : "체크한 변경에 구름 추가";
            $("apply").disabled = true;
            dialog.showModal();
            redraw();
        }
        $("detail").onclick = () => {
            const s = ready();
            if (!s || !s.canManage) return;
            const version = s.version;
            api.pickRegion((b) => {
                if (!b) return;
                if (api.state().version !== version) {
                    api.status(
                        "도면이 변경되었습니다. 상세 범위를 다시 지정하세요.",
                    );
                    return;
                }
                const w = b.maxX - b.minX,
                    h = b.maxY - b.minY;
                if (w <= 0 || h <= 0) return;
                const x = b.maxX + w * 0.15,
                    y = b.minY,
                    item = {
                        id: crypto.randomUUID(),
                        kind: "detail",
                        points: [
                            { x, y },
                            { x: x + w * 2, y },
                            { x: x + w * 2, y: y + h * 2 },
                            { x, y: y + h * 2 },
                        ],
                        detail: b,
                        color: "#38bdf8",
                        opacity: 1,
                        width: 2,
                        size: 16 / s.scale,
                        pattern: "solid",
                        text: "",
                        label: "DETAIL · 확대 2배",
                        locked: false,
                    };
                try {
                    if (api.append([item])) {
                        api.focus(R.bounds(item.points));
                        api.status(
                            "원본에 연결된 확대 상세도를 추가했습니다. 이동·회전·배율 조절이 가능합니다.",
                        );
                    }
                } catch (e) {
                    api.status(e.message);
                }
            });
        };
        $("count").onclick = () => {
            const s = ready();
            if (!s) return;
            start("count", s);
            const select = document.createElement("select");
            select.id = "cr-seed";
            select.setAttribute("aria-label", "계수 기준 기호");
            const seeds = R.visible(captured.doc).filter(
                (e) =>
                    ["INSERT", "CIRCLE"].includes(e.type) &&
                    C.geometry(e).length,
            );
            select.append(
                new Option("기준 기호를 선택하거나 도면에서 클릭", ""),
            );
            for (const e of seeds)
                select.append(
                    new Option(
                        `${e.id} · ${e.blockName || e.type} · ${e.layer}`,
                        e.id,
                    ),
                );
            const label = document.createElement("label");
            label.textContent = "계수 이름";
            const name = document.createElement("input");
            name.id = "cr-symbol-name";
            name.maxLength = 40;
            name.value = "기호";
            label.append(name);
            $("controls").append(select, label);
            select.onchange = () => count(select.value);
            $("state").textContent =
                "블록 이름·크기 또는 원 반지름이 같은 후보를 찾습니다. 회전과 좌우 대칭은 같은 기호로 봅니다. 후보를 검토한 뒤 체크하세요.";
        };
        function count(id) {
            try {
                seedId = id;
                rows = R.candidates(
                    captured.doc,
                    captured.doc.entities.find((e) => e.id === id),
                );
                if (rows.length > 1000)
                    throw Error(
                        "후보가 1,000개를 넘습니다. 원본 레이어를 좁힌 뒤 다시 실행하세요.",
                    );
                checked.clear();
                renderList();
                redraw();
            } catch (e) {
                rows = [];
                checked.clear();
                renderList();
                error(e);
            }
        }
        $("compare").onclick = async () => {
            const s = ready();
            if (!s) return;
            if (s.doc.reference) {
                api.status(
                    "객체 비교는 CAD 원본끼리 지원합니다. PDF·이미지는 표시 편집과 확대 상세도를 사용하세요.",
                );
                return;
            }
            start("compare", s);
            const token = epoch;
            working = true;
            const drawings = document.createElement("select");
            drawings.id = "cr-drawing";
            drawings.setAttribute("aria-label", "비교할 이전 도면");
            const revisions = document.createElement("select");
            revisions.id = "cr-revision";
            revisions.setAttribute("aria-label", "비교할 이전 개정");
            const load = document.createElement("button");
            load.textContent = "이전 개정 불러오기";
            load.id = "cr-load";
            const reset = document.createElement("button");
            reset.textContent = "기준점 다시 선택";
            reset.onclick = () => {
                anchors = [[], []];
                alignment = R.identity;
                rows = [];
                checked.clear();
                renderList();
                redraw();
            };
            const run = document.createElement("button");
            run.id = "cr-run";
            run.textContent = "정렬하여 비교";
            const tolerance = document.createElement("input");
            tolerance.id = "cr-tolerance";
            tolerance.type = "number";
            tolerance.min = ".000001";
            tolerance.step = "any";
            tolerance.value = ".01";
            tolerance.setAttribute("aria-label", "비교 허용 오차 (도면 단위)");
            const blend = document.createElement("input");
            blend.id = "cr-blend";
            blend.type = "range";
            blend.min = 0;
            blend.max = 1;
            blend.step = ".05";
            blend.value = ".35";
            blend.setAttribute("aria-label", "이전 도면 겹침 불투명도");
            blend.oninput = redraw;
            $("controls").append(
                drawings,
                revisions,
                load,
                reset,
                run,
                document.createTextNode("허용 오차 (도면 단위)"),
                tolerance,
                document.createTextNode("이전 도면 겹침"),
                blend,
            );
            const loadRevisions = async () => {
                const drawingId = drawings.value;
                revisions.replaceChildren();
                try {
                    const data = await api.request(
                        "/" + drawingId + "/history",
                    );
                    if (token !== epoch || drawings.value !== drawingId) return;
                    for (const r of data.revisions)
                        revisions.append(
                            new Option(`개정 ${r.revision}`, r.revision),
                        );
                } catch (e) {
                    if (token === epoch) error(e);
                }
            };
            drawings.onchange = loadRevisions;
            load.onclick = async () => {
                if (!revisions.value) return;
                load.disabled = true;
                try {
                    const data = await api.request(
                        "/" +
                            drawings.value +
                            "/history?revision=" +
                            revisions.value,
                    );
                    if (token !== epoch) return;
                    oldDoc = C.parse(data.dxf);
                    oldRecord = data;
                    anchors = [[], []];
                    rows = [];
                    checked.clear();
                    alignment = R.identity;
                    renderList();
                    redraw();
                    $("state").textContent =
                        "좌우 도면의 같은 지점을 1→2 순서로 클릭하세요. 두 점을 찍지 않으면 원래 좌표 기준으로 비교합니다.";
                } catch (e) {
                    error(e);
                } finally {
                    load.disabled = false;
                }
            };
            run.onclick = () => {
                try {
                    if (!oldDoc) throw Error("이전 개정을 먼저 불러오세요.");
                    alignment = anchors.some((a) => a.length)
                        ? R.alignment(anchors[0], anchors[1])
                        : R.identity;
                    rows = R.compare(
                        oldDoc,
                        captured.doc,
                        alignment,
                        Number(tolerance.value),
                    );
                    if (rows.length > 1000)
                        throw Error(
                            "변경 후보가 1,000개를 넘습니다. 기준점·허용 오차·레이어 범위를 확인하세요.",
                        );
                    checked.clear();
                    renderList();
                    redraw();
                    $("state").textContent =
                        `비교 완료 · 정렬 배율 ${Math.hypot(alignment.a, alignment.b).toPrecision(6)} · 추가 ${rows.filter((r) => r.kind === "added").length} / 삭제 ${rows.filter((r) => r.kind === "removed").length} / 변경 ${rows.filter((r) => r.kind === "changed").length} · 미표시 객체와 래스터는 비교 제외`;
                } catch (e) {
                    rows = [];
                    checked.clear();
                    renderList();
                    error(e);
                }
            };
            try {
                const data = await api.request(
                    "?site_id=" + captured.record.site_id,
                );
                if (token !== epoch) return;
                for (const drawing of data.drawings)
                    drawings.append(new Option(drawing.title, drawing.id));
                drawings.value = String(captured.record.id);
                await loadRevisions();
            } catch (e) {
                error(e);
            } finally {
                working = false;
            }
        };
        [0, 1].forEach((index) => {
            const canvas = index ? $("new") : $("old");
            canvas.onclick = (event) => {
                if (!views[index] || (mode === "compare" && !oldDoc)) return;
                const rect = canvas.getBoundingClientRect(),
                    p = views[index].world({
                        x:
                            ((event.clientX - rect.left) * canvas.width) /
                            rect.width,
                        y:
                            ((event.clientY - rect.top) * canvas.height) /
                            rect.height,
                    });
                if (mode === "count") {
                    const entities = R.visible(captured.doc).filter((e) =>
                            ["INSERT", "CIRCLE"].includes(e.type),
                        ),
                        seed = entities.find((e) => {
                            const b = R.bounds(C.geometry(e));
                            return (
                                p.x >= b.minX - 8 / views[1].scale &&
                                p.x <= b.maxX + 8 / views[1].scale &&
                                p.y >= b.minY - 8 / views[1].scale &&
                                p.y <= b.maxY + 8 / views[1].scale
                            );
                        });
                    if (seed) {
                        $("seed").value = seed.id;
                        count(seed.id);
                    }
                    return;
                }
                if (anchors[index].length === 2) anchors[index] = [];
                anchors[index].push(p);
                rows = [];
                checked.clear();
                renderList();
                redraw();
                $("state").textContent =
                    `기준점: 이전 ${anchors[0].length}/2 · 현재 ${anchors[1].length}/2 · 정렬하여 비교를 누르세요.`;
            };
        });
        $("apply").onclick = () => {
            try {
                const current = api.state();
                if (
                    !current.canManage ||
                    current.version !== captured.version ||
                    current.record.id !== captured.record.id
                )
                    throw Error(
                        "도면이 변경되었습니다. 창을 닫고 다시 검토하세요.",
                    );
                if (!checked.size) throw Error("확인한 후보를 체크하세요.");
                const size = 14 / current.scale,
                    chosen = rows.filter((_, i) => checked.has(i));
                let items;
                if (mode === "count") {
                    const symbol = $("symbol-name").value.trim();
                    if (!symbol) throw Error("계수 이름을 입력하세요.");
                    const existing = current.doc.markups.filter(
                            (m) => m.kind === "pin" && m.symbol === symbol,
                        ),
                        start = existing.length;
                    items = chosen
                        .filter(
                            (row) =>
                                !existing.some(
                                    (m) =>
                                        M.distance(m.points[0], row.point) <
                                        1e-7,
                                ),
                        )
                        .map((row, i) => ({
                            id: crypto.randomUUID(),
                            kind: "pin",
                            points: [row.point],
                            color: "#fbbf24",
                            opacity: 1,
                            width: 2,
                            size,
                            pattern: "solid",
                            text: String(start + i + 1),
                            symbol,
                            locked: false,
                        }));
                } else
                    items = chosen.map((row, i) =>
                        R.cloud(
                            row,
                            i,
                            size,
                            `이전 #${oldRecord.id} r${oldRecord.revision} → 현재 #${captured.record.id} r${captured.record.revision}`,
                        ),
                    );
                if (!items.length)
                    throw Error("이미 같은 위치에 계수한 기호가 있습니다.");
                dialog.close();
                if (api.append(items))
                    api.status(
                        `검토한 ${items.length}개 표시를 추가했습니다. 저장하면 새 개정으로 남습니다.`,
                    );
            } catch (e) {
                error(e);
            }
        };
        $("close").onclick = () => {
            epoch++;
            dialog.close();
        };
        dialog.addEventListener("cancel", () => epoch++);
        return {
            refresh() {
                const s = api.state();
                $("detail").disabled =
                    !s.doc || !s.canManage || s.busy || s.pending;
                $("count").disabled = $("compare").disabled =
                    !s.doc || s.busy || s.pending;
            },
        };
    };
})();
