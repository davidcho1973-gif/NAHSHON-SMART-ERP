(function (root) {
    "use strict";
    const papers = {
        A4: [210, 297],
        A3: [297, 420],
        A2: [420, 594],
        A1: [594, 841],
        Letter: [215.9, 279.4],
        Tabloid: [279.4, 431.8],
    };
    function layout(bounds, settings, measurement) {
        if (!papers[settings.paper]) throw Error("지원되는 용지를 선택하세요.");
        let [widthMm, heightMm] = papers[settings.paper];
        if (settings.landscape) [widthMm, heightMm] = [heightMm, widthMm];
        const dpi = Number(settings.dpi),
            denominator = Number(settings.denominator),
            px = dpi / 25.4;
        if (![150, 200, 300].includes(dpi))
            throw Error("출력 해상도를 확인하세요.");
        if (
            Object.values(bounds).some(
                (n) => !Number.isFinite(n) || Math.abs(n) > 1e9,
            ) ||
            bounds.maxX <= bounds.minX ||
            bounds.maxY <= bounds.minY
        )
            throw Error("출력 범위의 최소·최대 좌표를 확인하세요.");
        const width = Math.round(widthMm * px),
            height = Math.round(heightMm * px);
        if (width * height > 28000000)
            throw Error(
                "선택 용지와 해상도가 너무 큽니다. 150 DPI 또는 작은 용지를 선택하세요.",
            );
        const legendRows = Math.ceil((settings.legendCount || 0) / 3),
            footerMm = 14 + legendRows * 7;
        const frame = {
            x: 10 * px,
            y: 30 * px,
            width: (widthMm - 20) * px,
            height: (heightMm - 40 - footerMm) * px,
        };
        if (frame.height < 30 * px)
            throw Error(
                "범례가 출력 공간을 초과합니다. 용지를 키우거나 범례를 끄세요.",
            );
        let scale = Math.min(
            frame.width / (bounds.maxX - bounds.minX),
            frame.height / (bounds.maxY - bounds.minY),
        );
        if (settings.fixed) {
            if (!measurement?.metersPerUnit)
                throw Error(
                    "정확한 축척 출력에는 도면 단위 또는 두 점 보정이 필요합니다.",
                );
            if (
                !Number.isFinite(denominator) ||
                denominator < 1 ||
                denominator > 1000000
            )
                throw Error("축척 분모는 1~1,000,000 사이여야 합니다.");
            scale = (measurement.metersPerUnit * 1000 * px) / denominator;
            if (
                (bounds.maxX - bounds.minX) * scale > frame.width + 0.01 ||
                (bounds.maxY - bounds.minY) * scale > frame.height + 0.01
            )
                throw Error(
                    "이 축척에서는 선택 범위가 용지를 넘습니다. 축척 분모를 늘리거나 범위·용지를 조정하세요.",
                );
        }
        const center = {
            x: (bounds.minX + bounds.maxX) / 2,
            y: (bounds.minY + bounds.maxY) / 2,
        };
        const crop = {
            x:
                frame.x +
                (frame.width - (bounds.maxX - bounds.minX) * scale) / 2,
            y:
                frame.y +
                (frame.height - (bounds.maxY - bounds.minY) * scale) / 2,
            width: (bounds.maxX - bounds.minX) * scale,
            height: (bounds.maxY - bounds.minY) * scale,
        };
        return {
            widthMm,
            heightMm,
            width,
            height,
            px,
            frame,
            crop,
            scale,
            center,
            denominator: settings.fixed ? denominator : null,
            screen: (p) => ({
                x: frame.x + frame.width / 2 + (p.x - center.x) * scale,
                y: frame.y + frame.height / 2 - (p.y - center.y) * scale,
            }),
        };
    }
    // A fixed-size raster PDF. Physical MediaBox is independent of pixel DPI.
    function pdfBlob(canvas, widthMm, heightMm) {
        if (!(widthMm > 0 && heightMm > 0))
            throw Error("PDF 용지 크기를 확인하세요.");
        const binary = atob(canvas.toDataURL("image/jpeg", 0.98).split(",")[1]),
            jpg = Uint8Array.from(binary, (c) => c.charCodeAt(0)),
            enc = new TextEncoder(),
            chunks = [],
            offsets = [0];
        let size = 0;
        const add = (value) => {
            const bytes = typeof value === "string" ? enc.encode(value) : value;
            chunks.push(bytes);
            size += bytes.length;
        };
        const w = ((widthMm * 72) / 25.4).toFixed(5),
            h = ((heightMm * 72) / 25.4).toFixed(5);
        const obj = (id, value) => {
            offsets[id] = size;
            add(`${id} 0 obj\n${value}\nendobj\n`);
        };
        add("%PDF-1.4\n");
        obj(1, "<< /Type /Catalog /Pages 2 0 R >>");
        obj(2, "<< /Type /Pages /Kids [3 0 R] /Count 1 >>");
        obj(
            3,
            `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${w} ${h}] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>`,
        );
        offsets[4] = size;
        add(
            `4 0 obj\n<< /Type /XObject /Subtype /Image /Width ${canvas.width} /Height ${canvas.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpg.length} >>\nstream\n`,
        );
        add(jpg);
        add("\nendstream\nendobj\n");
        const stream = `q ${w} 0 0 ${h} 0 0 cm /Im0 Do Q`;
        obj(5, `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`);
        const xref = size;
        add("xref\n0 6\n0000000000 65535 f \n");
        for (let i = 1; i <= 5; i++)
            add(String(offsets[i]).padStart(10, "0") + " 00000 n \n");
        add(`trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`);
        return new Blob(chunks, { type: "application/pdf" });
    }
    function visibleItems(doc) {
        return (doc.markups || []).filter(
            (m) =>
                !(doc.workspace?.layers || []).some(
                    (layer) => layer.id === m.layer && !layer.visible,
                ),
        );
    }
    function visibleEntities(doc) {
        return doc.entities.filter(
            (e) =>
                !e.hidden && !e.paper && doc.layers[e.layer]?.visible !== false,
        );
    }
    function legend(doc, items = visibleItems(doc)) {
        const names = {
                area: "영역",
                cloud: "변경 구름",
                callout: "연결 메모",
                text: "문자",
                route: "배관 / 덕트",
                pen: "펜",
                highlight: "형광펜",
                pin: "기호",
            },
            result = new Map();
        for (const item of items) {
            const layer = (doc.workspace?.layers || []).find(
                    (l) => l.id === item.layer,
                ),
                name = item.kind === 'part' ? [item.part.name, item.part.specification].filter(Boolean).join(' · ') : layer?.name || names[item.kind] || item.kind,
                key = (item.kind === 'part' ? item.part.id : name) + item.color;
            if (!result.has(key))
                result.set(key, { color: item.color, name, count: 0 });
            result.get(key).count++;
        }
        return [...result.values()];
    }
    function render(doc, record, bounds, settings) {
        const inRange = root.CADMarkupGeometry.selectRegion(
            visibleItems(doc),
            root.CADMarkup.rect(
                { x: bounds.minX, y: bounds.minY },
                { x: bounds.maxX, y: bounds.maxY },
            ),
            true,
        );
        const entries = settings.legend ? legend(doc, inRange) : [];
        if (entries.length > 24)
            throw Error(
                "범례가 24개를 초과합니다. 공종 레이어를 정리하거나 범례를 끄세요.",
            );
        const plan = layout(
                bounds,
                { ...settings, legendCount: entries.length },
                doc.workspace?.measurement,
            ),
            canvas = document.createElement("canvas");
        canvas.width = plan.width;
        canvas.height = plan.height;
        const ctx = canvas.getContext("2d"),
            px = plan.px;
        ctx.fillStyle = "#fff";
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.save();
        ctx.beginPath();
        ctx.rect(plan.crop.x, plan.crop.y, plan.crop.width, plan.crop.height);
        ctx.clip();
        ctx.globalAlpha = doc.workspace?.underlay?.opacity ?? 1;
        if (doc.workspace?.underlay?.grayscale) ctx.filter = "grayscale(1)";
        root.CADCore.renderEntities(
            ctx,
            doc,
            visibleEntities(doc),
            plan.screen,
            plan.scale,
            { paper: true, pixelsPerMm: px },
        );
        ctx.globalAlpha = 1;
        ctx.filter = "none";
        if (settings.markups !== false)
            for (const item of visibleItems(doc))
                root.CADMarkup.draw(ctx, item, plan.screen, plan.scale, {
                    paper: true,
                    lineScale: settings.dpi / 96,
                    pixelsPerMm: px,
                    doc,
                });
        ctx.restore();
        ctx.strokeStyle = "#94a3b8";
        ctx.lineWidth = 0.2 * px;
        ctx.strokeRect(
            plan.frame.x,
            plan.frame.y,
            plan.frame.width,
            plan.frame.height,
        );
        function text(
            value,
            x,
            y,
            mm = 3,
            bold = false,
            maxWidth = plan.width - 20 * px,
        ) {
            ctx.fillStyle = "#172536";
            ctx.font = `${bold ? "600 " : ""}${mm * px}px sans-serif`;
            ctx.textAlign = "left";
            ctx.textBaseline = "top";
            ctx.fillText(String(value), x * px, y * px, maxWidth);
        }
        text(settings.title || record.title, 10, 8, 4.5, true);
        text(
            `도면 #${record.id} · 개정 ${record.revision}${settings.dirty ? " · 저장 전 작업" : " · 저장된 개정"}`,
            10,
            16,
            2.7,
        );
        text(
            plan.denominator
                ? `축척 1:${plan.denominator} · 지정 단위/보정 기준`
                : doc.workspace?.measurement
                  ? "용지 맞춤 · 축척 고정 아님"
                  : "용지 맞춤 · 도면 단위 미확정",
            10,
            22,
            2.7,
        );
        const foot = (plan.frame.y + plan.frame.height) / px + 3;
        entries.forEach((entry, i) => {
            const col = i % 3,
                row = Math.floor(i / 3),
                x = 10 + (col * (plan.widthMm - 20)) / 3,
                y = foot + row * 7;
            ctx.fillStyle = entry.color;
            ctx.fillRect(x * px, y * px, 4 * px, 3 * px);
            text(
                `${entry.name} (${entry.count})`,
                x + 6,
                y,
                2.6,
                false,
                ((plan.widthMm - 20) / 3 - 8) * px,
            );
        });
        const notes = doc.entities.some((e) => !e.editable)
            ? "원본 미지원 객체는 누락될 수 있음 · 원본 CAD 대조 필요"
            : "표시 포함 이미지 PDF";
        text(
            `${notes} · ${settings.dpi} DPI · 인쇄 시 실제 크기(100%)`,
            10,
            plan.heightMm - 8,
            2.3,
        );
        return {
            canvas,
            plan,
            blob: () => pdfBlob(canvas, plan.widthMm, plan.heightMm),
        };
    }
    root.CADPrint = {
        papers,
        layout,
        pdfBlob,
        render,
        visibleItems,
        visibleEntities,
    };
    root.createCADPrintWorkbench = function (api) {
        const dialog = document.createElement("dialog");
        dialog.id = "cad-print-dialog";
        dialog.className = "cad-dialog print-workbench";
        dialog.innerHTML = `<h2>출력 작업대</h2><div class="print-workbench-grid"><section><label>제목<input id="cp-title" maxlength="160"></label><div class="action-grid"><label>용지<select id="cp-paper">${Object.keys(
            papers,
        )
            .map((name) => `<option>${name}</option>`)
            .join(
                "",
            )}</select></label><label>방향<select id="cp-orientation"><option value="landscape">가로</option><option value="portrait">세로</option></select></label></div><label>해상도<select id="cp-dpi"><option>150</option><option>200</option><option>300</option></select></label><label>범위<select id="cp-scope"><option value="view">현재 화면</option><option value="all">전체 도면</option><option value="selection">선택한 표시</option><option value="custom">직접 지정</option></select></label><button id="cp-pick">도면에서 출력 범위 지정</button><div class="action-grid"><label>최소 X<input id="cp-minX" type="number" step="any"></label><label>최소 Y<input id="cp-minY" type="number" step="any"></label><label>최대 X<input id="cp-maxX" type="number" step="any"></label><label>최대 Y<input id="cp-maxY" type="number" step="any"></label></div><label>축척<select id="cp-scale"><option value="fit">용지에 맞추기</option><option value="fixed">지정 축척</option></select></label><label>축척 1 : <input id="cp-denominator" type="number" min="1" max="1000000" value="100"></label><label><input id="cp-markups" type="checkbox" checked> 도면 위 표시 포함</label><label><input id="cp-legend" type="checkbox" checked> 공종·표시 범례 포함</label></section><section><p id="cp-state" role="status"></p><img id="cp-preview" alt="출력할 도면 미리보기" hidden><p class="note">PDF 용지 크기와 도면 축척을 고정합니다. PDF 인쇄 설정에서는 ‘실제 크기 / 100%’를 선택하세요. 출력은 이미지 기반이며, 원본 CAD의 미지원 객체는 별도 확인이 필요합니다.</p></section></div><div class="dialog-actions"><button id="cp-close">닫기</button><button id="cp-preview-button">미리보기 갱신</button><button id="cp-download" disabled>이 미리보기 PDF 저장</button></div>`;
        document.body.append(dialog);
        const archive = document.createElement("button");
        archive.id = "cp-archive";
        archive.textContent = "검토본 보관 · 전달";
        archive.disabled = true;
        dialog.querySelector(".dialog-actions").append(archive);
        const $ = (id) => document.getElementById("cp-" + id);
        let output = null,
            url = null,
            captured = null;
        function clear() {
            archive.disabled = true;
            output = null;
            $("download").disabled = true;
            if (url) URL.revokeObjectURL(url);
            url = null;
            $("preview").hidden = true;
        }
        function bounds() {
            return Object.fromEntries(
                ["minX", "minY", "maxX", "maxY"].map((key) => [
                    key,
                    Number($(key).value),
                ]),
            );
        }
        function setBounds(b) {
            for (const key of ["minX", "minY", "maxX", "maxY"])
                $(key).value = Number(b[key].toPrecision(13));
            clear();
        }
        function scope() {
            const kind = $("scope").value;
            if (kind === "view") setBounds(api.bounds());
            else if (kind === "selection") {
                const items = api.selected();
                if (!items.length)
                    throw Error("범위로 사용할 표시를 먼저 선택하세요.");
                setBounds(root.CADMarkupGeometry.bounds(items));
            } else if (kind === "all") {
                let b = root.CADCore.bounds(visibleEntities(captured.doc));
                const marks = visibleItems(captured.doc);
                if (marks.length) {
                    const m = root.CADMarkupGeometry.bounds(marks);
                    b = {
                        minX: Math.min(b.minX, m.minX),
                        minY: Math.min(b.minY, m.minY),
                        maxX: Math.max(b.maxX, m.maxX),
                        maxY: Math.max(b.maxY, m.maxY),
                    };
                }
                setBounds(b);
            }
        }
        function preview() {
            clear();
            try {
                output = render(captured.doc, captured.record, bounds(), {
                    paper: $("paper").value,
                    landscape: $("orientation").value === "landscape",
                    dpi: Number($("dpi").value),
                    fixed: $("scale").value === "fixed",
                    denominator: Number($("denominator").value),
                    title: $("title").value,
                    legend: $("legend").checked && $("markups").checked,
                    markups: $("markups").checked,
                    dirty: captured.dirty,
                });
                $("preview").src = output.canvas.toDataURL("image/png");
                $("preview").hidden = false;
                $("download").disabled = false;
                archive.disabled =
                    !api.reviewArtifact ||
                    captured.dirty ||
                    !captured.record.canManage ||
                    !captured.record.snapshotToken;
                $("state").textContent =
                    `${output.plan.widthMm} × ${output.plan.heightMm} mm · ${output.canvas.width} × ${output.canvas.height} px${output.plan.denominator ? " · 1:" + output.plan.denominator : " · 용지 맞춤"}`;
            } catch (error) {
                $("state").textContent = error.message;
            }
        }
        for (const field of dialog.querySelectorAll("input,select"))
            field.addEventListener("change", () => {
                clear();
                $("state").textContent =
                    "설정이 바뀌었습니다. 미리보기를 갱신하세요.";
            });
        $("scope").onchange = () => {
            try {
                scope();
            } catch (error) {
                $("state").textContent = error.message;
            }
        };
        $("pick").onclick = () => {
            dialog.close();
            api.pickRegion((b) => {
                if (b) {
                    $("scope").value = "custom";
                    setBounds(b);
                }
                dialog.showModal();
            });
        };
        $("preview-button").onclick = preview;
        $("close").onclick = () => dialog.close();
        archive.onclick = () => {
            if (archive.disabled || !output) return;
            const artifact = {
                blob: output.blob(),
                record: captured.record,
                dirty: captured.dirty,
                preview: output.canvas.toDataURL("image/png"),
                title: $("title").value,
            };
            dialog.close();
            api.reviewArtifact(artifact);
        };
        $("download").onclick = () => {
            if (!output || !captured.record.canExport) return;
            const blob = output.blob(),
                link = document.createElement("a"),
                fileUrl = URL.createObjectURL(blob);
            link.href = fileUrl;
            link.download = `drawing-${captured.record.id}-r${captured.record.revision}${captured.dirty ? "-draft" : ""}.pdf`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(fileUrl), 30000);
        };
        return {
            open() {
                const state = api.state();
                if (
                    !state.doc ||
                    state.busy ||
                    state.pending ||
                    dialog.open ||
                    document.querySelector("dialog[open]")
                )
                    return;
                if (!state.record.canExport) {
                    api.status("이 계정에는 문서 내보내기 권한이 없습니다.");
                    return;
                }
                captured = {
                    doc: root.CADCore.copyDocument(state.doc),
                    record: structuredClone(state.record),
                    dirty: state.dirty,
                };
                $("title").value = state.record.title;
                $("paper").value = "A3";
                $("scope").value = "view";
                setBounds(api.bounds());
                dialog.showModal();
                preview();
            },
            artifact: () =>
                output
                    ? {
                          blob: output.blob(),
                          record: captured.record,
                          dirty: captured.dirty,
                      }
                    : null,
        };
    };
})(typeof window === "undefined" ? globalThis : window);
