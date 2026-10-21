(function () {
    "use strict";
    window.createCADWorkflow = function (api) {
        const types = {
                section: "공정 구역",
                boq: "계약·기성 항목",
                work_record: "작업·기성 기록",
                photo: "현장 사진",
                daily_report: "작업 일지",
                wbs: "WBS 공정",
                submittal: "제출물·승인 자재",
                document: "시방·문서",
                purchase: "구매·입고 요청",
                contract_change: "계약 변경",
            },
            taskKinds = {
                design_question: "설계 질의",
                inspection: "검측",
                corrective: "시정 작업",
            },
            statuses = {
                open: "접수",
                in_progress: "처리 중",
                submitted: "검토 요청",
                closed: "검토 완료",
            };
        const button = (text, fn) => {
                const b = document.createElement("button");
                b.type = "button";
                b.textContent = text;
                b.onclick = fn;
                return b;
            },
            text = (tag, value) => {
                const e = document.createElement(tag);
                e.textContent = value;
                return e;
            };
        const trigger = button("ERP 연결", open);
        trigger.id = "cad-workflow-open";
        document.querySelector("header").append(trigger);
        const overlay = document.createElement("details");
        overlay.id = "cad-workflow-overlay";
        overlay.innerHTML =
            '<summary>ERP 공정 상태 보기</summary><label><input id="cw-overlay" type="checkbox"> 연결 공정·작업 상태 표시</label><label>상태 검색<input id="cw-filter" placeholder="진행중, 완료, 검토 요청 등"></label><label>기준일 이후<input id="cw-from" type="date"></label><label>기준일 이전<input id="cw-to" type="date"></label><button id="cw-refresh">ERP 상태 새로고침</button><p id="cw-overlay-state" class="note"></p>';
        document.getElementById("properties").append(overlay);
        const dialog = document.createElement("dialog");
        dialog.id = "cad-workflow-dialog";
        dialog.className = "cad-dialog cad-workflow-dialog";
        dialog.innerHTML =
            '<h2>도면 ↔ ERP 업무 연결</h2><p id="cw-context"></p><p id="cw-message" role="status"></p><nav id="cw-tabs" class="dialog-actions"></nav><div id="cw-body"></div><div class="dialog-actions"><button id="cw-close">닫기</button></div>';
        document.body.append(dialog);
        const $ = (id) => document.getElementById("cw-" + id);
        let context = null,
            key = "",
            captured = null,
            tab = "links",
            selectedLocation = null,
            working = false,
            token = 0,
            searchAfter = null,
            catalog = [];
        const endpoint = (s) => "/" + s.record.id + "/workflow";
        const link = (label, url) => {
            const a = document.createElement("a");
            a.textContent = label;
            a.href = url;
            a.target = "_blank";
            a.rel = "noopener";
            return a;
        };
        function field(parent, label, name, value = "", kind = "text") {
            const wrap = text("label", label),
                input = document.createElement(
                    kind === "textarea" ? "textarea" : "input",
                );
            input.id = "cw-" + name;
            if (kind !== "textarea") input.type = kind;
            input.value = value ?? "";
            wrap.append(input);
            parent.append(wrap);
            return input;
        }
        function choice(parent, label, name, options, value) {
            const wrap = text("label", label),
                select = document.createElement("select");
            select.id = "cw-" + name;
            for (const [id, title] of options)
                select.add(new Option(title, id));
            if (value !== undefined) select.value = String(value);
            wrap.append(select);
            parent.append(wrap);
            return select;
        }
        function message(value) {
            $("message").textContent = value;
        }
        function canWrite() {
            const s = api.state();
            return (
                s.canManage &&
                !s.dirty &&
                !s.busy &&
                !s.pending &&
                s.record?.id === captured?.record.id &&
                s.version === captured.version
            );
        }
        function selected() {
            return api.selected().length === 1 ? api.selected()[0] : null;
        }
        function location() {
            return (
                context?.locations.find((l) => l.id === selectedLocation) ||
                null
            );
        }
        function requireWrite() {
            if (!canWrite())
                throw Error(
                    "변경 내용을 먼저 저장하고 ERP 연결을 다시 여세요.",
                );
            if (!selected())
                throw Error("연결할 도면 표시를 한 개 선택하세요.");
        }
        async function refreshContext() {
            const s = api.state();
            if (!s.record) return;
            const n = ++token,
                k = s.record.id + ":" + s.record.revision;
            try {
                const result = await api.request(
                    endpoint(s) + "?revision=" + s.record.revision,
                );
                if (n !== token || api.state().record?.id !== s.record.id)
                    return;
                context = result;
                key = k;
                const chosen = selected();
                if (chosen)
                    selectedLocation =
                        context.locations.find((l) => l.markupId === chosen.id)
                            ?.id || null;
                $("overlay-state").textContent =
                    `연결 위치 ${context.locations.length}개 · ${new Date().toLocaleTimeString()} 조회`;
                api.render();
                if (dialog.open) render();
            } catch (e) {
                if (n === token) {
                    message(e.message);
                    $("overlay-state").textContent = e.message;
                }
            }
        }
        async function run(fn) {
            if (working) return;
            working = true;
            dialog.setAttribute("aria-busy", "true");
            try {
                await fn();
            } catch (e) {
                message(e.message);
            } finally {
                working = false;
                dialog.removeAttribute("aria-busy");
            }
        }
        async function open() {
            const s = api.state();
            if (
                !s.record ||
                s.busy ||
                s.pending ||
                document.querySelector("dialog[open]")
            )
                return;
            captured = {
                record: structuredClone(s.record),
                version: s.version,
            };
            message("ERP 연결을 불러오는 중…");
            $("body").replaceChildren();
            dialog.showModal();
            await refreshContext();
        }
        function render() {
            if (!context || !dialog.open) return;
            const mark = selected();
            $("context").textContent =
                `${captured.record.title} · 개정 ${captured.record.revision} · ${mark ? mark.label || mark.text || mark.kind : "도면 표시를 하나 선택하면 새 연결 가능"}${api.state().dirty ? " · 저장하지 않은 변경 있음" : ""}`;
            $("tabs").replaceChildren();
            for (const [id, title] of [
                ["links", "업무 연결"],
                ["tasks", "질의·검측·시정"],
                ["purchase", "구매 요청 초안"],
                ["history", "이력"],
            ]) {
                const b = button(title, () => {
                    tab = id;
                    render();
                });
                b.setAttribute("aria-pressed", String(tab === id));
                $("tabs").append(b);
            }
            $("body").replaceChildren();
            message("");
            const select = choice(
                $("body"),
                "조회할 연결 위치",
                "location",
                [
                    ["", "현재 선택 / 새 위치"],
                    ...context.locations.map((l) => [
                        l.id,
                        `${l.present ? "" : "[현재 개정에서 삭제됨] "}${l.markupId} · 연결 ${l.links.length} · 작업 ${l.tasks.length}`,
                    ]),
                ],
                selectedLocation || "",
            );
            select.onchange = () => {
                selectedLocation = select.value || null;
                render();
            };
            const loc = location();
            if (loc) {
                const row = text(
                    "p",
                    `최초 연결 개정 ${loc.sourceRevision} · `,
                );
                row.append(
                    link("당시 도면 위치", loc.url),
                    button("현재 위치 보기", () => {
                        const m = api
                            .state()
                            .doc.markups.find((m) => m.id === loc.markupId);
                        if (m) {
                            api.select([m.id]);
                            api.focus(window.CADMarkup.bounds(m));
                        } else
                            message(
                                "현재 개정에 이 표시가 없습니다. 당시 도면 위치를 여세요.",
                            );
                    }),
                );
                $("body").append(row);
            }
            if (tab === "links") renderLinks(loc);
            else if (tab === "tasks") renderTasks(loc);
            else if (tab === "purchase") renderPurchases(loc);
            else renderHistory();
        }
        function targetCard(target, loc) {
            const card = document.createElement("article");
            card.className = "cad-business-card";
            card.append(
                text("strong", types[target.type] + " · " + target.title),
                text(
                    "p",
                    `${target.status || ""}${target.progress !== undefined ? " · " + target.progress + "%" : ""}${target.date ? " · " + target.date : ""}`,
                ),
            );
            if (target.quantities)
                card.append(
                    text(
                        "p",
                        Object.entries(target.quantities)
                            .map(
                                ([k, v]) =>
                                    (({
                                        unit: "단위",
                                        reportedQty: "보고",
                                        verifiedQty: "검토 인정",
                                        availableQty: "미청구",
                                        unallocatedQty: "미배정",
                                        unverifiedQty: "미검토",
                                        stageQuantities: "단계별 수량",
                                    })[k] || k) +
                                    ": " +
                                    (typeof v === "object"
                                        ? JSON.stringify(v)
                                        : v),
                            )
                            .join(" · "),
                    ),
                );
            if (target.lines)
                for (const line of target.lines)
                    card.append(
                        text(
                            "p",
                            `${line.name} · 요청 ${line.quantity ?? "미확정"} ${line.unit || ""} · 구매 ${line.ordered_quantity ?? 0} · 입고 ${line.received_quantity ?? 0}`,
                        ),
                    );
            if (target.thumbUrl) {
                const img = document.createElement("img");
                img.src = target.thumbUrl;
                img.alt = target.title;
                img.loading = "lazy";
                card.append(img);
            }
            card.append(
                link("ERP 원장 열기", target.url),
                button("이 기록에 연결된 도면", () =>
                    run(async () => {
                        const result = await api.request(
                            endpoint(captured) +
                                "/backlinks?type=" +
                                target.type +
                                "&targetId=" +
                                target.id,
                        );
                        const area = document.createElement("div");
                        for (const item of result.items)
                            area.append(
                                link(
                                    `${item.title} · 개정 ${item.revision} · ${item.markupId}`,
                                    item.url,
                                ),
                                document.createElement("br"),
                            );
                        card.append(area);
                    }),
                ),
            );
            for (const doc of target.documents || [])
                card.append(link(doc.title + " · " + doc.kind, doc.url));
            if (target.linkId && context.canManage) {
                const b = button("연결 해제", () =>
                    run(async () => {
                        await api.request(
                            endpoint(captured) + "/links/" + target.linkId,
                            { method: "DELETE" },
                        );
                        await refreshContext();
                        message(
                            "연결을 해제했습니다. 기존 도면·기성·업무 기록과 이력은 유지됩니다.",
                        );
                    }),
                );
                card.append(b);
            }
            return card;
        }
        function renderLinks(loc) {
            const list = document.createElement("div");
            for (const target of loc?.links || [])
                list.append(targetCard(target, loc));
            if (!loc?.links.length)
                list.append(text("p", "이 위치에 연결된 업무가 없습니다."));
            $("body").append(list);
            const tools = document.createElement("section");
            tools.className = "cad-business-form";
            tools.append(text("h3", "기존 ERP 기록 연결"));
            const type = choice(
                    tools,
                    "기록 종류",
                    "type",
                    Object.entries(types),
                ),
                search = field(tools, "검색어", "search");
            const results = document.createElement("div");
            results.id = "cw-results";
            const find = button("기록 찾기", () =>
                    run(async () => {
                        searchAfter = null;
                        catalog = [];
                        await loadTargets();
                    }),
                ),
                next = button("다음 기록", () => run(loadTargets));
            next.disabled = true;
            tools.append(find, next, results);
            $("body").append(tools);
            async function loadTargets() {
                const data = await api.request(
                    endpoint(captured) +
                        "/targets?type=" +
                        type.value +
                        "&q=" +
                        encodeURIComponent(search.value) +
                        "&after=" +
                        (searchAfter || 0),
                );
                catalog.push(...data.items);
                searchAfter = data.next;
                next.disabled = !data.next;
                results.replaceChildren();
                if (!catalog.length)
                    results.append(
                        text("p", "열람 가능한 일치 기록이 없습니다."),
                    );
                for (const target of catalog) {
                    const card = targetCard(target);
                    const add = button("선택한 도면 표시에 연결", () =>
                        run(async () => {
                            requireWrite();
                            await api.request(endpoint(captured) + "/links", {
                                method: "POST",
                                body: JSON.stringify({
                                    revision: captured.record.revision,
                                    markupId: selected().id,
                                    type: target.type,
                                    targetId: target.id,
                                }),
                            });
                            await refreshContext();
                            message(
                                "업무 기록을 연결했습니다. 원장 수량과 승인 상태는 유지됩니다.",
                            );
                        }),
                    );
                    add.disabled = !canWrite() || !selected();
                    card.append(add);
                    results.append(card);
                }
            }
        }
        function evidenceChoices(parent, loc, existing = []) {
            const all = new Map();
            for (const item of [...(loc?.links || []), ...existing])
                if (
                    [
                        "photo",
                        "document",
                        "submittal",
                        "contract_change",
                    ].includes(item.type)
                )
                    all.set(item.type + ":" + item.id, item);
            const controls = [];
            parent.append(
                text("p", "근거 자료 (업무 연결 탭에서 먼저 연결하세요)"),
            );
            for (const ref of all.values()) {
                const label = document.createElement("label"),
                    input = document.createElement("input");
                input.type = "checkbox";
                input.checked = existing.some(
                    (e) => e.type === ref.type && e.id === ref.id,
                );
                label.append(
                    input,
                    document.createTextNode(
                        types[ref.type] + " · " + ref.title,
                    ),
                );
                parent.append(label);
                controls.push({ input, ref });
            }
            return () =>
                controls
                    .filter((c) => c.input.checked)
                    .map((c) => ({ type: c.ref.type, id: c.ref.id }));
        }
        function renderTasks(loc) {
            const taskList = document.createElement("section");
            for (const task of loc?.tasks || []) {
                const card = document.createElement("article");
                card.className = "cad-business-card";
                card.append(
                    text("strong", taskKinds[task.kind] + " · " + task.title),
                    text(
                        "p",
                        `${statuses[task.status]} · ${task.assignee || "담당자"} · 기한 ${task.dueOn}`,
                    ),
                    text("p", task.details),
                );
                if (task.resolution)
                    card.append(text("p", "처리 결과: " + task.resolution));
                for (const ref of task.evidence)
                    card.append(link("근거: " + ref.title, ref.url));
                const edit = button("처리 / 검토", () => {
                    const form = document.createElement("section");
                    form.className = "cad-business-form";
                    const resolution = field(
                            form,
                            "답변·처리 결과",
                            "resolution-" + task.id,
                            task.resolution,
                            "textarea",
                        ),
                        getEvidence = evidenceChoices(form, loc, task.evidence),
                        actions = {
                            open: ["in_progress", "submitted"],
                            in_progress: ["open", "submitted"],
                            submitted: ["in_progress", "closed"],
                            closed: ["open"],
                        }[task.status];
                    for (const status of actions) {
                        const save = button(
                            status === "closed"
                                ? "근거 검토 후 완료"
                                : status === "open"
                                  ? "재개 / 접수"
                                  : statuses[status],
                            () =>
                                run(async () => {
                                    await api.request(
                                        endpoint(captured) +
                                            "/tasks/" +
                                            task.id,
                                        {
                                            method: "PUT",
                                            body: JSON.stringify({
                                                version: task.version,
                                                requestKey: crypto.randomUUID(),
                                                status,
                                                resolution: resolution.value,
                                                evidence: getEvidence(),
                                            }),
                                        },
                                    );
                                    await refreshContext();
                                    message(
                                        "업무 상태와 처리 이력을 저장했습니다.",
                                    );
                                }),
                        );
                        save.disabled =
                            status === "closed" || task.status === "closed"
                                ? !context.canClose
                                : !context.canManage;
                        form.append(save);
                    }
                    card.append(form);
                    edit.disabled = true;
                });
                edit.disabled = !context.canManage && !context.canClose;
                card.append(edit);
                taskList.append(card);
            }
            if (!loc?.tasks.length)
                taskList.append(
                    text("p", "이 위치의 질의·검측·시정 작업이 없습니다."),
                );
            $("body").append(taskList);
            const form = document.createElement("section");
            form.className = "cad-business-form";
            form.append(text("h3", "새 업무"));
            const kind = choice(
                    form,
                    "구분",
                    "task-kind",
                    Object.entries(taskKinds),
                ),
                title = field(form, "제목", "task-title"),
                details = field(
                    form,
                    "질의 / 확인할 내용",
                    "task-details",
                    "",
                    "textarea",
                ),
                assignee = choice(
                    form,
                    "담당자",
                    "task-assignee",
                    context.assignees.map((u) => [u.id, u.name]),
                ),
                due = field(form, "기한", "task-due", "", "date");
            let id = crypto.randomUUID();
            const save = button("이 위치에 업무 등록", () =>
                run(async () => {
                    requireWrite();
                    if (
                        !title.value.trim() ||
                        !details.value.trim() ||
                        !due.value
                    )
                        throw Error("제목·내용·기한을 입력하세요.");
                    await api.request(endpoint(captured) + "/tasks", {
                        method: "POST",
                        body: JSON.stringify({
                            id,
                            revision: captured.record.revision,
                            markupId: selected().id,
                            kind: kind.value,
                            title: title.value,
                            details: details.value,
                            assignedUserId: Number(assignee.value),
                            dueOn: due.value,
                        }),
                    });
                    id = crypto.randomUUID();
                    await refreshContext();
                    message(
                        "담당 업무를 등록했습니다. 설계 질의는 계약 변경 승인과 별도로 관리합니다.",
                    );
                }),
            );
            save.disabled = !canWrite() || !selected();
            form.append(save);
            $("body").append(form);
        }
        function renderPurchases(loc) {
            const list = document.createElement("section");
            for (const draft of context.purchaseDrafts.filter(
                (d) => !loc || d.locationId === loc.id,
            )) {
                const card = document.createElement("article");
                card.className = "cad-business-card";
                card.append(
                    text(
                        "strong",
                        draft.content.lines.map((l) => l.name).join(", "),
                    ),
                    text(
                        "p",
                        draft.requestId
                            ? "구매 요청 #" + draft.requestId + "로 제출됨"
                            : "개인 초안 · 버전 " + draft.version,
                    ),
                );
                if (draft.requestId)
                    card.append(
                        link(
                            "구매 요청 열기",
                            "/?view=purchase-requests&site=" +
                                captured.record.site_id +
                                "&request=" +
                                draft.requestId,
                        ),
                    );
                else
                    card.append(
                        button("검토 / 수정", () => {
                            const location = context.locations.find(
                                (l) => l.id === draft.locationId,
                            );
                            if (location?.present)
                                api.select([location.markupId]);
                            purchaseForm(draft);
                        }),
                    );
                list.append(card);
            }
            $("body").append(list);
            const create = button("선택 표시에서 구매 초안 작성", () =>
                run(async () => {
                    requireWrite();
                    const basis = await api.request(
                        endpoint(captured) +
                            "/purchase-basis?revision=" +
                            captured.record.revision +
                            "&markupId=" +
                            encodeURIComponent(selected().id),
                    );
                    purchaseForm({
                        id: crypto.randomUUID(),
                        version: 0,
                        content: {
                            basis,
                            lines: [
                                {
                                    name: basis.description || "",
                                    specification: "",
                                    quantity:
                                        basis.quantity === null
                                            ? ""
                                            : Number(basis.quantity.toFixed(3)),
                                    unit: basis.unit || "",
                                },
                            ],
                        },
                    });
                }),
            );
            create.disabled =
                !context.canRequestPurchase || !canWrite() || !selected();
            $("body").append(
                create,
                text(
                    "p",
                    "도면 참고 물량을 검토하여 개인 초안에 저장합니다. 구매 요청 제출 이후에도 발주·입고 처리는 기존 구매 업무에서 진행합니다.",
                ),
            );
        }
        function purchaseForm(draft) {
            $("body").replaceChildren();
            const form = document.createElement("section");
            form.className = "cad-business-form";
            $("body").append(form);
            form.append(text("h3", "구매 요청 검토"));
            const basis = draft.content.basis;
            form.append(
                text(
                    "p",
                    `근거: 도면 #${basis.drawingId} 개정 ${basis.revision} · 참고 물량 ${basis.quantity ?? "단위 미확정"} ${basis.unit || ""}`,
                ),
            );
            const need = field(
                    form,
                    "필요일",
                    "purchase-date",
                    draft.content.need_by,
                    "date",
                ),
                note = field(
                    form,
                    "검토 내용·여유분 사유",
                    "purchase-note",
                    draft.content.note,
                    "textarea",
                ),
                lineHost = document.createElement("div"),
                inputs = [];
            form.append(lineHost);
            let lineSequence = 0;
            const addLine = (value) => {
                const lineId = lineSequence++;
                const row = document.createElement("div");
                row.className = "purchase-draft-line";
                const name = field(
                        row,
                        "품명",
                        "purchase-name-" + lineId,
                        value.name,
                    ),
                    spec = field(
                        row,
                        "규격",
                        "purchase-spec-" + lineId,
                        value.specification,
                    ),
                    qty = field(
                        row,
                        "검토 수량",
                        "purchase-qty-" + lineId,
                        value.quantity,
                        "number",
                    ),
                    unit = field(
                        row,
                        "단위",
                        "purchase-unit-" + lineId,
                        value.unit,
                    );
                qty.min = ".001";
                qty.step = ".001";
                const item = { row, name, spec, qty, unit };
                row.append(
                    button("품목 제외", () => {
                        row.remove();
                        inputs.splice(inputs.indexOf(item), 1);
                        form.dispatchEvent(
                            new Event("input", { bubbles: true }),
                        );
                    }),
                );
                inputs.push(item);
                lineHost.append(row);
                form.dispatchEvent(new Event("input", { bubbles: true }));
            };
            draft.content.lines.forEach(addLine);
            form.append(button("품목 추가", () => addLine({})));
            let current = draft;
            const reviewLabel = document.createElement("label"),
                review = document.createElement("input");
            review.type = "checkbox";
            review.id = "cw-purchase-reviewed";
            reviewLabel.append(
                review,
                document.createTextNode(
                    "규격·수량·단위·필요일을 확인했습니다.",
                ),
            );
            form.append(reviewLabel);
            const submit = button("검토한 초안을 구매 요청으로 제출", () =>
                run(async () => {
                    if (!review.checked) throw Error("검토 확인에 체크하세요.");
                    if (!current.version)
                        throw Error("개인 초안을 먼저 저장하세요.");
                    const result = await api.request(
                        endpoint(captured) +
                            "/purchase-drafts/" +
                            current.id +
                            "/submit",
                        {
                            method: "POST",
                            body: JSON.stringify({
                                version: current.version,
                                reviewed: true,
                            }),
                        },
                    );
                    await refreshContext();
                    message(
                        "구매 요청 #" +
                            result.request.id +
                            "를 등록했습니다. 발주·입고는 별도 처리합니다.",
                    );
                }),
            );
            submit.id = "cw-purchase-submit";
            submit.disabled = true;
            const save = button("개인 초안 저장", () =>
                run(async () => {
                    requireWrite();
                    if (
                        current.locationId &&
                        context.locations.find(
                            (l) => l.id === current.locationId,
                        )?.markupId !== selected().id
                    )
                        throw Error(
                            "초안의 원래 도면 표시를 선택한 뒤 수정하세요.",
                        );
                    if (
                        !inputs.length ||
                        inputs.some(
                            (i) =>
                                !i.name.value.trim() ||
                                !i.unit.value.trim() ||
                                !(Number(i.qty.value) > 0),
                        )
                    )
                        throw Error("품명·규격·수량·단위를 확인하세요.");
                    current = await api.request(
                        endpoint(captured) + "/purchase-drafts",
                        {
                            method: "POST",
                            body: JSON.stringify({
                                id: current.id,
                                version: current.version,
                                revision: captured.record.revision,
                                markupId: selected().id,
                                content: {
                                    need_by: need.value || null,
                                    note: note.value,
                                    lines: inputs.map((i) => ({
                                        name: i.name.value,
                                        specification: i.spec.value,
                                        quantity: Number(i.qty.value),
                                        unit: i.unit.value,
                                    })),
                                },
                            }),
                        },
                    );
                    submit.disabled = false;
                    review.checked = false;
                    message(
                        "개인 초안을 저장했습니다. 검토 확인 후 구매 요청으로 제출하세요.",
                    );
                }),
            );
            save.id = "cw-purchase-save";
            save.disabled = !canWrite() || !selected();
            form.append(save, submit, button("목록으로", render));
            form.addEventListener("input", (e) => {
                if (e.target !== review) submit.disabled = true;
            });
            if (draft.version) submit.disabled = false;
        }
        function renderHistory() {
            for (const event of context.events) {
                $("body").append(
                    text(
                        "p",
                        `${event.created_at} · ${{ link: "업무 연결", unlink: "연결 해제", task_created: "업무 등록", task_status: "업무 상태 변경", purchase_draft_saved: "구매 초안 저장", purchase_submitted: "구매 요청 제출", packet_created: "검토본 보관", shared: "검토본 전송" }[event.kind] || event.kind} · 사용자 #${event.user_id ?? "-"}`,
                    ),
                );
            }
            if (!context.events.length)
                $("body").append(text("p", "기록된 업무 이력이 없습니다."));
        }
        $("close").onclick = () => {
            if (!working) dialog.close();
        };
        dialog.addEventListener("cancel", (e) => {
            if (working) e.preventDefault();
        });
        for (const id of ["overlay", "filter", "from", "to"])
            $(id).oninput = () => {
                if ($("overlay").checked && !context) refreshContext();
                api.render();
            };
        $("refresh").onclick = refreshContext;
        function renderOverlay(ctx, screen) {
            if (
                !$("overlay").checked ||
                !context ||
                key !==
                    api.state().record?.id + ":" + api.state().record?.revision
            )
                return;
            const marks = api.state().doc.markups || [];
            for (const loc of context.locations) {
                const mark = marks.find((m) => m.id === loc.markupId);
                if (!mark) continue;
                const entries = [
                    ...loc.links.filter((t) => t.type === "wbs"),
                    ...loc.tasks.map((t) => ({
                        title: t.title,
                        status: statuses[t.status],
                        date: t.dueOn,
                        progress: t.status === "closed" ? 100 : 0,
                    })),
                ];
                const rows = entries.filter(
                    (r) =>
                        (r.status + " " + r.title).includes(
                            $("filter").value,
                        ) &&
                        (!$("from").value ||
                            (r.date && r.date >= $("from").value)) &&
                        (!$("to").value || (r.date && r.date <= $("to").value)),
                );
                if (!rows.length) continue;
                const b = window.CADMarkup.bounds(mark),
                    p = screen({ x: b.minX, y: b.maxY });
                ctx.save();
                ctx.font = "12px sans-serif";
                ctx.textBaseline = "bottom";
                rows.slice(0, 4).forEach((r, i) => {
                    const color = r.hold
                            ? "#fb7185"
                            : r.progress === 100
                              ? "#34d399"
                              : "#fbbf24",
                        label =
                            r.status +
                            (r.progress !== undefined
                                ? " " + r.progress + "%"
                                : "");
                    ctx.fillStyle = "#0b1220";
                    ctx.fillRect(
                        p.x - 2,
                        p.y - 18 - i * 20,
                        ctx.measureText(label).width + 8,
                        19,
                    );
                    ctx.fillStyle = color;
                    ctx.fillText(label, p.x + 2, p.y - 3 - i * 20);
                });
                ctx.restore();
            }
        }
        return {
            open,
            renderOverlay,
            refresh() {
                const s = api.state();
                trigger.disabled = !s.record || s.busy || s.pending;
                const next = s.record
                    ? s.record.id + ":" + s.record.revision
                    : "";
                if (key && key !== next) {
                    context = null;
                    key = "";
                    token++;
                    $("overlay-state").textContent =
                        "새 개정 · 상태 새로고침 필요";
                }
            },
        };
    };
})();
