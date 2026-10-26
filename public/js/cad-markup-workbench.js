(function () {
    "use strict";
    const M = window.CADMarkup,
        G = window.CADMarkupGeometry;
    const units = { mm: 0.001, cm: 0.01, m: 1, in: 0.0254, ft: 0.3048 };
    window.createCADMarkupWorkbench = function (api, editor) {
        const host = document.getElementById("markup-workbench");
        if (!host) return null;
        host.innerHTML = `
        <details open><summary>선 · 채우기</summary>
          <p class="note">위의 색상은 선·글자 색입니다. 채우기는 별도 색을 지정할 수 있습니다. 새 표시에 기본값으로 적용됩니다.</p>
          <label>선 모양<select id="mw-dash"><option value="solid">실선</option><option value="dash">파선</option><option value="dot">점선</option><option value="dashdot">일점쇄선</option></select></label>
          <label>영역 채우기<select id="mw-fill-mode"><option value="same">선과 같은 색</option><option value="color">별도 색</option><option value="none">채우기 없음</option></select></label>
          <div class="action-grid"><label>채우기 색<input id="mw-fill-color" type="color" value="#38bdf8"></label><label>채우기 농도 (%)<input id="mw-fill-opacity" type="number" min="5" max="100" value="35"></label></div>
          <label><input id="mw-fill-independent" type="checkbox"> 채우기 농도를 별도로 지정</label>
          <button id="mw-appearance-apply">선택 표시의 선·채우기 적용</button>
          <label>다각형 꼭짓점 수<input id="mw-polygon-sides" type="number" min="3" max="12" step="1" value="6"></label>
        </details>
        <details><summary>정밀 이동 · 크기 · 반복 복사</summary>
          <p class="note">값은 도면 좌표 단위입니다. +X는 오른쪽, +Y는 위쪽입니다. 크기는 선택 전체의 중심을 유지합니다.</p>
          <output id="mw-selection-bounds"></output>
          <div class="action-grid"><label>X 이동<input id="mw-move-x" type="number" step="any" value="0"></label><label>Y 이동<input id="mw-move-y" type="number" step="any" value="0"></label></div><button id="mw-move">입력한 거리만큼 이동</button>
          <div class="action-grid"><label>너비<input id="mw-resize-width" type="number" min="0.000001" step="any" value="100"></label><label>높이<input id="mw-resize-height" type="number" min="0.000001" step="any" value="100"></label></div>
          <label><input id="mw-keep-ratio" type="checkbox" checked> 비율 유지 (너비 기준)</label><button id="mw-resize">정확한 크기 적용</button>
          <div class="action-grid"><label>열 수<input id="mw-repeat-columns" type="number" min="1" max="20" value="2"></label><label>행 수<input id="mw-repeat-rows" type="number" min="1" max="20" value="1"></label><label>X 반복 간격<input id="mw-repeat-x" type="number" step="any" value="100"></label><label>Y 반복 간격<input id="mw-repeat-y" type="number" step="any" value="-100"></label></div>
          <p class="note">원본을 포함한 행·열 수입니다. 간격은 같은 기준점 사이 거리이며 아래로 반복하려면 Y에 음수를 입력합니다.</p><button id="mw-repeat">선택 표시 반복 복사</button>
        </details>
        <details open><summary>영역 · 그룹 · 정렬</summary>
          <p class="note">Alt+꼭짓점 클릭: 삭제 · Alt+변 클릭: 점 추가. 빼기는 먼저 선택한 영역에서 나머지를 뺍니다.</p>
          <div class="action-grid"><button id="mw-union">영역 합치기</button><button id="mw-difference">영역 빼기</button><button id="mw-intersection">겹친 영역</button><button id="mw-group">그룹 묶기</button><button id="mw-ungroup">그룹 풀기</button><button id="mw-same">같은 색 선택</button></div>
          <label>정렬<select id="mw-align"><option value="left">왼쪽</option><option value="center">가로 가운데</option><option value="right">오른쪽</option><option value="top">위</option><option value="middle">세로 가운데</option><option value="bottom">아래</option><option value="x">가로 균등 간격</option><option value="y">세로 균등 간격</option></select></label><button id="mw-align-apply">선택 표시 정렬</button>
          <div class="action-grid"><label>회전(°)<input id="mw-angle" type="number" value="0" min="-360" max="360"></label><label>크기 배율<input id="mw-factor" type="number" value="1" min="0.001" max="1000" step="0.1"></label></div><button id="mw-transform">중심 기준 회전 / 크기 적용</button>
          <div class="action-grid"><button id="mw-format-copy">서식 복사</button><button id="mw-format-paste">서식 붙여넣기</button></div>
        </details>
        <details><summary>문자 · 변경 번호 · 배관 표기</summary>
          <label>변경 번호 / 규격<input id="mw-label" maxlength="80" placeholder="예: REV-03 · Ø100 CW"></label><button id="mw-label-apply">선택 표시에 적용</button>
          <div class="action-grid"><label>글꼴<select id="mw-font"><option value="sans">고딕</option><option value="serif">명조</option><option value="mono">고정폭</option></select></label><label>정렬<select id="mw-text-align"><option value="left">왼쪽</option><option value="center">가운데</option><option value="right">오른쪽</option></select></label></div>
          <div class="action-grid"><label><input id="mw-bold" type="checkbox"> 굵게</label><label><input id="mw-italic" type="checkbox"> 기울임</label></div>
          <label>글자 높이 (도면 단위)<input id="mw-text-size" type="number" min="0.000001" step="any" value="2.5"></label>
          <label>문자 상자 폭 (0 = 자동)<input id="mw-text-width" type="number" min="0" step="any" value="0"></label>
          <label>줄 간격<input id="mw-line-height" type="number" min="1" max="3" step="0.1" value="1.35"></label>
          <label>배경 색<input id="mw-background" type="color" value="#ffffff"></label>
          <div class="action-grid"><label><input id="mw-no-background" type="checkbox"> 투명 배경</label><label><input id="mw-border" type="checkbox" checked> 테두리</label></div><button id="mw-text-apply">선택 문자 서식 적용</button>
          <label><input id="mw-text-default" type="checkbox"> 새 문자·메모에도 위 서식과 글자 높이 사용</label>
          <label>배관 꺾임<select id="mw-route-angle"><option value="90">90°</option><option value="45">45°</option><option value="0">자유</option></select></label><label>흐름 방향<select id="mw-arrows"><option value="end">진행 방향</option><option value="both">양방향</option><option value="none">없음</option></select></label><button id="mw-route-apply">선택 경로에 적용</button>
          <label>지우개 반경 (화면 px)<input id="mw-eraser-size" type="number" min="4" max="80" value="12"></label>
        </details>
        <details><summary>단위 · 축척 · 면적 / 길이</summary>
          <p id="mw-measurement-state" class="note">실제 단위를 지정해야 면적과 길이를 계산합니다.</p>
          <label>도면 / 실제 거리 단위<select id="mw-unit"><option value="mm">mm</option><option value="cm">cm</option><option value="m">m</option><option value="in">inch</option><option value="ft">feet</option></select></label>
          <button id="mw-unit-apply">도면 1단위를 선택 단위로 확정</button>
          <label>두 기준점 사이 실제 거리<input id="mw-calibration-distance" type="number" min="0.000001" step="any" value="1000"></label><button id="mw-calibrate">도면의 두 점으로 축척 보정</button>
          <output id="mw-measurement" class="note"></output>
        </details>
        <details><summary>공종 레이어 · 보기</summary>
          <label>작업 레이어<select id="mw-layer"><option value="">기본 표시</option></select></label>
          <input id="mw-layer-name" maxlength="40" placeholder="예: 급수 · 소방 · 철거" aria-label="새 공종 레이어 이름"><div class="action-grid"><button id="mw-layer-add">레이어 추가</button><button id="mw-layer-assign">선택 표시 이동</button></div><div id="mw-layers"></div>
          <label>원본 밝기<input id="mw-underlay-opacity" type="range" min="10" max="100" value="100"></label><label><input id="mw-underlay-gray" type="checkbox"> 원본 회색조</label>
          <input id="mw-view-name" maxlength="40" placeholder="예: 2층 소방 검토" aria-label="저장할 보기 이름"><button id="mw-view-save">현재 위치·레이어 보기 저장</button><select id="mw-views" aria-label="저장한 보기"></select><div class="action-grid"><button id="mw-view-open">보기 열기</button><button id="mw-view-delete">보기 삭제</button></div>
        </details>
        <details><summary>복합 도구 · 기호 수량</summary>
          <p class="note">선택한 구름·화살표·메모를 함께 저장해 재사용합니다. 도구함은 현재 브라우저에 저장됩니다.</p>
          <input id="mw-template-name" maxlength="40" placeholder="예: 배관 변경 검토" aria-label="복합 도구 이름"><button id="mw-template-save">선택을 복합 도구로 저장</button><select id="mw-templates" aria-label="복합 도구"></select><div class="action-grid"><button id="mw-template-place">화면 가운데 삽입</button><button id="mw-template-delete">도구 삭제</button></div>
          <label>번호 / 기호 분류<input id="mw-symbol" maxlength="40" value="일반" placeholder="예: 스프링클러"></label><button id="mw-symbol-apply">선택 기호 분류 적용</button><p class="note">수량은 표시한 위치의 개수입니다. 기성·발주 수량으로 자동 반영되지 않습니다.</p><div id="mw-counts"></div>
        </details>`;
        const $ = (name) => document.getElementById("mw-" + name);
        const workspace = () => api.document()?.workspace || {};
        const layers = () => workspace().layers || [];
        const enabled = () =>
            !!api.document() &&
            api.canManage() &&
            !api.busy() &&
            !editor.pending();
        const act = (name, fn) =>
            ($(name).onclick = () => {
                try {
                    fn();
                } catch (error) {
                    api.status(error.message);
                }
            });
        function updateWorkspace(fn) {
            if (!enabled()) return;
            const next = structuredClone(workspace());
            fn(next);
            api.snapshot();
            api.document().workspace = next;
            api.render();
        }
        function replace(next) {
            const ids = new Set(next.map((m) => m.id));
            editor.apply(
                editor
                    .items()
                    .map((m) =>
                        ids.has(m.id) ? next.find((n) => n.id === m.id) : m,
                    ),
                [...ids],
            );
        }
        function selected() {
            const selected = editor.chosen();
            if (!enabled() || !selected.length)
                throw Error("수정할 표시를 먼저 선택하세요.");
            if (selected.some(locked))
                throw Error("잠긴 표시 또는 잠긴 레이어가 포함되어 있습니다.");
            return structuredClone(selected);
        }
        const hidden = (item) =>
            layers().some((layer) => layer.id === item.layer && !layer.visible);
        const locked = (item) =>
            item.locked ||
            layers().some((layer) => layer.id === item.layer && layer.locked);
        function appearance(item) {
            item.dash = $('dash').value;
            if (['area', 'ellipse'].includes(item.kind)) {
                const mode = $('fill-mode').value;
                if (mode === 'same') delete item.fillColor;
                else item.fillColor = mode === 'none' ? 'none' : $('fill-color').value;
                if ($('fill-independent').checked) item.fillOpacity = Number($('fill-opacity').value) / 100;
                else delete item.fillOpacity;
            }
            return item;
        }
        act('appearance-apply', () => replace(selected().map(appearance)));
        act('move', () => {
            const next = selected(), dx = Number($('move-x').value), dy = Number($('move-y').value);
            if (![dx, dy].every(n => Number.isFinite(n) && Math.abs(n) <= 1e9)) throw Error('이동 거리를 확인하세요.');
            if (!dx && !dy) return;
            replace(next.map(m => M.translate(m, dx, dy)));
        });
        act('resize', () => replace(G.resize(selected(), Number($('resize-width').value), Number($('resize-height').value), $('keep-ratio').checked)));
        act('repeat', () => {
            const copies = G.repeat(selected(), Number($('repeat-columns').value), Number($('repeat-rows').value), Number($('repeat-x').value), Number($('repeat-y').value), editor.items().length);
            editor.apply([...editor.items(), ...copies], copies.map(m => m.id));
            api.status(`${copies.length}개 복사 완료 · 실행 취소로 한 번에 되돌릴 수 있습니다.`);
        });
        for (const operation of ["union", "difference", "intersection"])
            act(operation, () => {
                const chosen = selected(),
                    output = G.combine(chosen, operation),
                    ids = new Set(chosen.map((m) => m.id));
                editor.apply(
                    [
                        ...editor.items().filter((m) => !ids.has(m.id)),
                        ...output,
                    ],
                    output.map((m) => m.id),
                );
                api.status(
                    output.length
                        ? "영역 연산 완료 · 실행 취소로 되돌릴 수 있습니다."
                        : "남는 영역이 없어 선택 영역을 제거했습니다.",
                );
            });
        act("group", () => {
            const next = selected(),
                group = crypto.randomUUID();
            next.forEach((m) => (m.group = group));
            replace(next);
        });
        act("ungroup", () => {
            const next = selected();
            next.forEach((m) => delete m.group);
            replace(next);
        });
        act("same", () => {
            const one = editor.chosen()[0];
            if (one)
                editor.select(
                    editor
                        .items()
                        .filter((m) => m.color === one.color && !hidden(m))
                        .map((m) => m.id),
                );
        });
        act("align-apply", () => {
            const next = selected(),
                mode = $("align").value;
            replace(
                ["x", "y"].includes(mode)
                    ? G.distribute(next, mode)
                    : G.align(next, mode),
            );
        });
        act("transform", () =>
            replace(
                G.transform(
                    selected(),
                    Number($("angle").value),
                    Number($("factor").value),
                ),
            ),
        );
        let format = null;
        act("format-copy", () => {
            const item = editor.chosen()[0];
            if (!item) return;
            format = Object.fromEntries(
                ["color", "opacity", "width", "size", "pattern", "textStyle", "dash", "fillColor", "fillOpacity"]
                    .filter((k) => item[k] !== undefined)
                    .map((k) => [k, structuredClone(item[k])]),
            );
            api.status("서식을 복사했습니다. 적용할 표시를 선택하세요.");
        });
        act("format-paste", () => {
            if (!format) throw Error("먼저 서식을 복사하세요.");
            const next = selected();
            next.forEach((m) => {
                const style = structuredClone(format);
                if (!["text", "callout"].includes(m.kind))
                    delete style.textStyle;
                for (const field of ['dash', 'fillColor', 'fillOpacity']) delete m[field];
                if (!['area', 'ellipse'].includes(m.kind)) { delete style.fillColor; delete style.fillOpacity; }
                Object.assign(m, style);
            });
            replace(next);
        });
        act("label-apply", () => {
            const next = selected();
            next.forEach((m) => (m.label = $("label").value.trim()));
            replace(next);
        });
        function textStyle() {
            const boxWidth = Number($("text-width").value),
                lineHeight = Number($("line-height").value);
            if (
                !Number.isFinite(boxWidth) ||
                boxWidth < 0 ||
                boxWidth > 1e9 ||
                !Number.isFinite(lineHeight) ||
                lineHeight < 1 ||
                lineHeight > 3
            )
                throw Error("문자 상자 폭과 줄 간격을 확인하세요.");
            return {
                font: $("font").value,
                bold: $("bold").checked,
                italic: $("italic").checked,
                align: $("text-align").value,
                lineHeight,
                background: $("no-background").checked
                    ? "none"
                    : $("background").value,
                border: $("border").checked,
                ...(boxWidth ? { boxWidth } : {}),
            };
        }
        act("text-apply", () => {
            const next = selected().filter((m) =>
                ["text", "callout"].includes(m.kind),
            );
            if (!next.length) throw Error("문자 또는 연결 메모를 선택하세요.");
            const style = textStyle(),
                size = Number($("text-size").value);
            next.forEach((m) => {
                m.textStyle = structuredClone(style);
                m.size = size;
            });
            replace(next);
        });
        act("route-apply", () => {
            const next = selected().filter((m) => m.kind === "route");
            next.forEach((m) => {
                m.arrows = $("arrows").value;
                m.label = $("label").value.trim();
            });
            replace(next);
        });
        act("unit-apply", () =>
            updateWorkspace(
                (w) =>
                    (w.measurement = {
                        metersPerUnit: units[$("unit").value],
                        unit: $("unit").value,
                        source: "declared",
                    }),
            ),
        );
        act("calibrate", () => {
            if (enabled()) editor.setTool("calibrate");
        });
        function calibrate(points) {
            const unit = $("unit").value,
                distance = Number($("calibration-distance").value),
                length = M.distance(...points);
            if (
                !Number.isFinite(distance) ||
                distance <= 0 ||
                distance > 1e9 ||
                length <= 1e-9
            ) {
                api.status("실제 거리와 두 기준점을 확인하세요.");
                return;
            }
            const metersPerUnit = (distance * units[unit]) / length;
            if (metersPerUnit > 1e6) {
                api.status(
                    "보정 배율이 너무 큽니다. 기준점과 단위를 확인하세요.",
                );
                return;
            }
            updateWorkspace(
                (w) =>
                    (w.measurement = {
                        metersPerUnit,
                        unit,
                        source: "calibrated",
                        points,
                        distance,
                    }),
            );
        }
        act("layer-add", () => {
            const name = $("layer-name").value.trim();
            if (!name) throw Error("공종 레이어 이름을 입력하세요.");
            if (layers().length >= 32)
                throw Error("레이어는 32개까지 만들 수 있습니다.");
            const id = crypto.randomUUID();
            updateWorkspace((w) => {
                w.layers ||= [];
                w.layers.push({
                    id,
                    name,
                    color: document.getElementById("markup-color").value,
                    visible: true,
                    locked: false,
                });
            });
            $("layer").value = id;
        });
        act("layer-assign", () => {
            const next = selected(),
                layer = $("layer").value;
            if (layers().some((l) => l.id === layer && l.locked))
                throw Error("잠긴 레이어에는 옮길 수 없습니다.");
            next.forEach((m) => {
                if (layer) m.layer = layer;
                else delete m.layer;
            });
            replace(next);
        });
        for (const name of ["underlay-opacity", "underlay-gray"])
            $(name).onchange = () =>
                updateWorkspace(
                    (w) =>
                        (w.underlay = {
                            opacity: Number($("underlay-opacity").value) / 100,
                            grayscale: $("underlay-gray").checked,
                        }),
                );
        act("view-save", () => {
            const name = $("view-name").value.trim();
            if (!name) throw Error("보기 이름을 입력하세요.");
            if ((workspace().views || []).length >= 20)
                throw Error("저장한 보기는 20개까지입니다.");
            updateWorkspace((w) => {
                w.views ||= [];
                w.views.push({
                    id: crypto.randomUUID(),
                    name,
                    ...api.view(),
                    hiddenLayers: layers()
                        .filter((l) => !l.visible)
                        .map((l) => l.id),
                });
            });
        });
        act("view-open", () => {
            const view = (workspace().views || []).find(
                (v) => v.id === $("views").value,
            );
            if (!view) return;
            updateWorkspace((w) => {
                for (const layer of w.layers || [])
                    layer.visible = !view.hiddenLayers.includes(layer.id);
            });
            editor.select([]);
            api.setView(view);
        });
        act("view-delete", () =>
            updateWorkspace(
                (w) =>
                    (w.views = (w.views || []).filter(
                        (v) => v.id !== $("views").value,
                    )),
            ),
        );
        const templateKey =
            "erp.cad.compound-tools.v1:" +
            (document.body.dataset.editorOwner || "local");
        let templates = [];
        try {
            const stored = JSON.parse(
                localStorage.getItem(templateKey) || "[]",
            );
            if (Array.isArray(stored))
                templates = stored
                    .filter(
                        (t) =>
                            typeof t.name === "string" &&
                            t.name.length <= 40 &&
                            Array.isArray(t.items) &&
                            t.items.length <= 100 &&
                            G.validSet(t.items),
                    )
                    .slice(0, 6);
        } catch {}
        function listTemplates() {
            $("templates").replaceChildren(
                ...templates.map((t, i) => new Option(t.name, String(i))),
            );
        }
        function saveTemplates(next) {
            const value = JSON.stringify(next);
            if (new TextEncoder().encode(value).length > 1000000)
                throw Error("복합 도구함의 최대 크기는 1MB입니다.");
            localStorage.setItem(templateKey, value);
            templates = next;
            listTemplates();
        }
        act("template-save", () => {
            const name = $("template-name").value.trim(),
                next = selected();
            if (!name) throw Error("도구 이름을 입력하세요.");
            if (next.length > 100)
                throw Error("복합 도구 하나에는 100개 표시까지 저장합니다.");
            const exists = templates.findIndex((t) => t.name === name);
            if (exists < 0 && templates.length >= 6)
                throw Error("복합 도구는 6개까지 저장합니다.");
            const data = next.map((m) => {
                    delete m.layer;
                    delete m.group;
                    return m;
                }),
                all = structuredClone(templates);
            if (exists < 0) all.push({ name, items: data });
            else all[exists] = { name, items: data };
            saveTemplates(all);
            api.status("선택한 도형과 문자를 복합 도구로 저장했습니다.");
        });
        act("template-place", () => {
            if (!enabled()) return;
            const saved = templates[Number($("templates").value)];
            if (!saved) return;
            const b = G.bounds(saved.items),
                center = api.view().center,
                group = crypto.randomUUID(),
                copies = saved.items.map((m) => ({
                    ...M.translate(
                        structuredClone(m),
                        center.x - (b.minX + b.maxX) / 2,
                        center.y - (b.minY + b.maxY) / 2,
                    ),
                    id: crypto.randomUUID(),
                    group,
                    ...($("layer").value ? { layer: $("layer").value } : {}),
                }));
            editor.apply(
                [...editor.items(), ...copies],
                copies.map((m) => m.id),
            );
        });
        act("template-delete", () =>
            saveTemplates(
                templates.filter((_, i) => i !== Number($("templates").value)),
            ),
        );
        act("symbol-apply", () => {
            const symbol = $("symbol").value.trim();
            if (!symbol) throw Error("기호 분류를 입력하세요.");
            const next = selected().filter((m) => m.kind === "pin");
            next.forEach((m) => (m.symbol = symbol));
            replace(next);
        });
        listTemplates();
        let key = "",
            chosenKey = "";
        function refresh() {
            const doc = api.document(),
                marks = editor.items(),
                selection = editor.chosen(),
                signature = JSON.stringify([
                    workspace(),
                    marks,
                    selection.map((m) => m.id),
                    api.canManage(),
                    api.busy(),
                    editor.pending(),
                ]);
            if (key === signature) return;
            key = signature;
            for (const button of host.querySelectorAll("button"))
                button.disabled = !enabled();
            const nextChosenKey = JSON.stringify(selection);
            if (nextChosenKey !== chosenKey) {
                chosenKey = nextChosenKey;
                const one = selection[0];
                $('selection-bounds').textContent = '';
                if (one) {
                    const b = G.bounds(selection), w = b.maxX - b.minX, h = b.maxY - b.minY;
                    $('selection-bounds').textContent = `선택 범위 ${Number(w.toPrecision(7))} × ${Number(h.toPrecision(7))}`;
                    $('resize-width').value = Number(w.toPrecision(12));
                    $('resize-height').value = Number(h.toPrecision(12));
                    $('dash').value = one.dash || 'solid';
                    $('fill-mode').value = one.fillColor === 'none' ? 'none' : one.fillColor ? 'color' : 'same';
                    $('fill-color').value = one.fillColor && one.fillColor !== 'none' ? one.fillColor : one.color;
                    $('fill-independent').checked = one.fillOpacity !== undefined;
                    $('fill-opacity').value = Math.round((one.fillOpacity ?? one.opacity) * 100);
                    $("label").value = one.label || "";
                    if (one.symbol) $("symbol").value = one.symbol;
                    if (["callout", "text"].includes(one.kind)) {
                        const style = one.textStyle || {};
                        $("text-size").value = one.size;
                        $("font").value = style.font || "sans";
                        $("bold").checked = !!style.bold;
                        $("italic").checked = !!style.italic;
                        $("text-align").value = style.align || "left";
                        $("text-width").value = style.boxWidth || 0;
                        $("line-height").value = style.lineHeight || 1.35;
                        $("no-background").checked =
                            style.background === "none";
                        $("background").value = /^#[0-9a-f]{6}$/i.test(
                            style.background,
                        )
                            ? style.background
                            : "#ffffff";
                        $("border").checked = style.border !== false;
                    }
                }
            }
            const currentLayer = $("layer").value;
            $("layer").replaceChildren(
                new Option("기본 표시", ""),
                ...layers().map((l) => new Option(l.name, l.id)),
            );
            $("layer").value = layers().some((l) => l.id === currentLayer)
                ? currentLayer
                : "";
            $("layers").replaceChildren();
            for (const layer of layers()) {
                const row = document.createElement("div");
                row.className = "workbench-layer";
                const toggle = document.createElement("input");
                toggle.type = "checkbox";
                toggle.checked = layer.visible;
                toggle.setAttribute("aria-label", layer.name + " 표시");
                toggle.disabled = !enabled();
                toggle.onchange = () => {
                    updateWorkspace(
                        (w) =>
                            (w.layers.find((l) => l.id === layer.id).visible =
                                toggle.checked),
                    );
                    editor.select([]);
                };
                const name = document.createElement("span");
                name.textContent = layer.name;
                const lock = document.createElement("button");
                lock.textContent = layer.locked ? "잠금 해제" : "잠금";
                lock.disabled = !enabled();
                lock.onclick = () =>
                    updateWorkspace((w) => {
                        const l = w.layers.find((l) => l.id === layer.id);
                        l.locked = !l.locked;
                    });
                row.append(toggle, name, lock);
                $("layers").append(row);
            }
            const currentView = $("views").value;
            $("views").replaceChildren(
                ...(workspace().views || []).map(
                    (v) => new Option(v.name, v.id),
                ),
            );
            if (currentView) $("views").value = currentView;
            $("underlay-opacity").value =
                (workspace().underlay?.opacity ?? 1) * 100;
            $("underlay-gray").checked = !!workspace().underlay?.grayscale;
            const measurement = workspace().measurement;
            $("measurement-state").textContent = measurement
                ? `단위 확인됨 · ${measurement.source === "calibrated" ? "두 점 보정" : "도면 단위 지정"} · 1도면 단위 = ${measurement.metersPerUnit.toPrecision(6)}m`
                : "실제 단위를 지정해야 면적과 길이를 계산합니다.";
            if (measurement) {
                const area =
                    selection
                        .filter((m) => ['area', 'ellipse'].includes(m.kind))
                        .reduce((n, m) => n + G.area(m), 0) *
                    measurement.metersPerUnit ** 2;
                const length =
                    selection
                        .filter((m) => m.kind === "route")
                        .reduce((n, m) => n + G.length(m), 0) *
                    measurement.metersPerUnit;
                const imperial = ["in", "ft"].includes(measurement.unit),
                    factor = imperial ? 0.3048 : 1;
                $("measurement").textContent =
                    `선택 면적 ${(area / factor ** 2).toLocaleString(undefined, { maximumFractionDigits: 3 })} ${imperial ? "ft²" : "m²"} · 경로 길이 ${(length / factor).toLocaleString(undefined, { maximumFractionDigits: 3 })} ${imperial ? "ft" : "m"}`;
            } else
                $("measurement").textContent =
                    "단위 미확정 · 실제 면적/길이를 표시하지 않습니다.";
            $("counts").replaceChildren();
            const counts = new Map();
            for (const item of marks.filter(
                (m) => m.kind === "pin" && !hidden(m),
            )) {
                const symbol = item.symbol || "일반";
                if (!counts.has(symbol)) counts.set(symbol, []);
                counts.get(symbol).push(item.id);
            }
            for (const [name, ids] of counts) {
                const button = document.createElement("button");
                button.textContent = `${name} · ${ids.length}개 · 위치 선택`;
                button.onclick = () => editor.select(ids);
                $("counts").append(button);
            }
            if (!doc) $("measurement").textContent = "";
        }
        return {
            refresh,
            hidden,
            locked,
            calibrate,
            routeAngle: () => Number($("route-angle").value),
            polygonSides: () => Number($('polygon-sides').value),
            eraserRadius: () =>
                Math.max(
                    4,
                    Math.min(80, Number($("eraser-size").value) || 12),
                ) / api.scale(),
            decorate: (item) => {
                appearance(item);
                if (['text', 'callout'].includes(item.kind) && $('text-default').checked) {
                    // Invalid defaults are rejected at commit, without changing existing marks.
                    try { item.textStyle = textStyle(); } catch { item.size = NaN; return item; }
                    item.size = Number($('text-size').value);
                }
                const layer = $("layer").value;
                if (layer) item.layer = layer;
                if (item.kind === "pin")
                    item.symbol = $("symbol").value.trim() || "일반";
                if (["cloud", "route"].includes(item.kind))
                    item.label = $("label").value.trim();
                if (item.kind === "route") item.arrows = $("arrows").value;
                return item;
            },
        };
    };
})();
