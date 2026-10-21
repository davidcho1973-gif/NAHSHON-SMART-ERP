(function () {
    "use strict";
    window.createCADPackets = function (api) {
        const dialog = document.createElement("dialog");
        dialog.className = "cad-dialog cad-workflow-dialog";
        dialog.id = "cad-packet-dialog";
        dialog.innerHTML =
            '<h2>검토본 보관 · 전달</h2><p id="pk-status" role="status"></p><div id="pk-body"></div><div class="dialog-actions"><button id="pk-close">닫기</button></div>';
        document.body.append(dialog);
        const $ = (id) => document.getElementById("pk-" + id),
            text = (tag, value) => {
                const e = document.createElement(tag);
                e.textContent = value;
                return e;
            },
            button = (label, fn) => {
                const b = document.createElement("button");
                b.type = "button";
                b.textContent = label;
                b.onclick = fn;
                return b;
            };
        const library = button("검토본 보관함", openLibrary);
        library.id = "cad-packet-library";
        document.querySelector("header").append(library);
        let working = false,
            epoch = 0;
        function field(parent, title, id, value = "", type = "text") {
            const label = text("label", title),
                input = document.createElement(
                    type === "textarea" ? "textarea" : "input",
                );
            input.id = "pk-" + id;
            if (type !== "textarea") input.type = type;
            if (type === "checkbox") input.checked = !!value;
            else input.value = value;
            label.append(input);
            parent.append(label);
            return input;
        }
        function link(title, url) {
            const a = text("a", title);
            a.href = url;
            a.target = "_blank";
            a.rel = "noopener";
            return a;
        }
        function message(s) {
            $("status").textContent = s;
        }
        async function run(fn) {
            if (working) return;
            working = true;
            try {
                await fn();
            } catch (e) {
                message(e.message);
            } finally {
                working = false;
            }
        }
        function show() {
            epoch++;
            $("body").replaceChildren();
            message("");
            dialog.showModal();
            return epoch;
        }
        function packetCard(packet, record) {
            const card = document.createElement("article");
            card.className = "cad-business-card";
            card.append(
                text("strong", packet.title + " · 개정 " + packet.revision),
                text("p", packet.createdAt + " · SHA256 " + packet.sha256),
                link("보관 ZIP 내려받기", packet.url),
                link("검토한 PDF 보기", packet.pdfUrl + "&inline=1"),
            );
            for (const f of packet.files)
                card.append(
                    text(
                        "p",
                        `${f.name} · ${Number(f.bytes).toLocaleString()} bytes`,
                    ),
                );
            if (record.canManage)
                card.append(
                    button("이 PDF를 메신저 / 메일로 전달", () =>
                        run(() => shareForm(packet, record)),
                    ),
                );
            return card;
        }
        async function openLibrary() {
            const s = api.state();
            if (
                !s.record ||
                s.busy ||
                s.pending ||
                document.querySelector("dialog[open]")
            )
                return;
            if (!s.record.canExport) {
                api.status("문서 내보내기 권한이 필요합니다.");
                return;
            }
            const n = show(),
                record = structuredClone(s.record);
            await run(async () => {
                const result = await api.request(
                    "/" + record.id + "/workflow/packets",
                );
                if (n !== epoch) return;
                if (!result.items.length)
                    $("body").append(
                        text(
                            "p",
                            "아직 보관한 검토본이 없습니다. 출력 작업대에서 미리보기를 확인한 뒤 보관하세요.",
                        ),
                    );
                for (const packet of result.items)
                    $("body").append(packetCard(packet, record));
            });
        }
        async function review(artifact) {
            if (!artifact || artifact.dirty || !artifact.record.snapshotToken) {
                api.status("도면을 저장한 후 미리보기를 새로 만들어 주세요.");
                return;
            }
            const n = show(),
                record = artifact.record,
                id = crypto.randomUUID(),
                form = document.createElement("section");
            form.className = "cad-business-form";
            $("body").append(form);
            const image = document.createElement("img");
            image.src = artifact.preview;
            image.alt = "보관할 표시 도면";
            image.style.cssText =
                "max-width:100%;max-height:50vh;object-fit:contain";
            form.append(
                image,
                text(
                    "p",
                    `도면 #${record.id} · 개정 ${record.revision} · 이 이미지와 원본·표시 데이터를 함께 보관합니다.`,
                ),
            );
            const title = field(
                    form,
                    "검토본 제목",
                    "title",
                    artifact.title || record.title,
                ),
                refs = document.createElement("div");
            form.append(text("h3", "함께 보관할 근거 자료"), refs);
            const controls = [];
            try {
                const data = await api.request(
                    "/" + record.id + "/workflow?revision=" + record.revision,
                );
                if (n !== epoch) return;
                const unique = new Map();
                for (const loc of data.locations)
                    for (const ref of loc.links)
                        unique.set(ref.type + ":" + ref.id, ref);
                for (const ref of unique.values()) {
                    const checked = field(
                        refs,
                        ref.title + " · " + ref.status,
                        "ref-" + ref.type + "-" + ref.id,
                        false,
                        "checkbox",
                    );
                    controls.push({ ref, checked });
                }
                if (!controls.length)
                    refs.append(
                        text(
                            "p",
                            "연결된 근거 자료가 없습니다. 도면 원본과 표시 데이터는 함께 보관됩니다.",
                        ),
                    );
            } catch (e) {
                message(e.message);
            }
            const reviewed = field(
                form,
                "위 PDF의 표시와 개정, 선택한 근거 자료를 확인했습니다.",
                "reviewed",
                false,
                "checkbox",
            );
            const create = button("이 검토본을 보관", () =>
                run(async () => {
                    if (!reviewed.checked)
                        throw Error("검토 확인에 체크하세요.");
                    const chosen = controls.filter((c) => c.checked.checked);
                    if (chosen.length > 40)
                        throw Error("근거 자료는 최대 40개씩 보관하세요.");
                    const body = new FormData();
                    body.append("id", id);
                    body.append("title", title.value);
                    body.append("revision", record.revision);
                    body.append("snapshotToken", record.snapshotToken);
                    body.append("reviewed", "1");
                    body.append("pdf", artifact.blob, "marked.pdf");
                    chosen.forEach((c, i) => {
                        body.append(`references[${i}][type]`, c.ref.type);
                        body.append(`references[${i}][id]`, c.ref.id);
                    });
                    const packet = await api.request(
                        "/" + record.id + "/workflow/packets",
                        { method: "POST", body },
                    );
                    form.replaceChildren(packetCard(packet, record));
                    message(
                        "검토본을 보관했습니다. 이후 도면 수정과 별도로 이 파일들이 유지됩니다.",
                    );
                }),
            );
            create.id = "pk-create";
            form.append(create);
        }
        async function shareForm(packet, record) {
            const options = await api.request(
                    "/" + record.id + "/workflow/share-options",
                ),
                form = document.createElement("section");
            form.className = "cad-business-form";
            $("body").replaceChildren(form);
            form.append(
                text("h3", "보관한 PDF 전달"),
                text("p", packet.title + " · 개정 " + packet.revision),
                link("전송할 PDF 확인", packet.pdfUrl + "&inline=1"),
            );
            const channel = document.createElement("select");
            channel.id = "pk-channel";
            channel.add(new Option("ERP 메신저", "chat"));
            channel.add(new Option("이메일", "mail"));
            form.append(channel);
            const room = document.createElement("select");
            room.id = "pk-room";
            room.setAttribute("aria-label", "보낼 대화방");
            for (const r of options.rooms) room.add(new Option(r.name, r.id));
            form.append(room);
            const to = field(form, "이메일 수신자", "to", "", "email"),
                subject = field(
                    form,
                    "제목",
                    "subject",
                    packet.title.slice(0, 200),
                ),
                body = field(
                    form,
                    "전달 내용",
                    "message",
                    `도면 #${record.id} · 개정 ${packet.revision} 검토본입니다.`,
                    "textarea",
                ),
                reviewed = field(
                    form,
                    "수신 대상과 첨부 PDF를 확인했습니다.",
                    "send-reviewed",
                    false,
                    "checkbox",
                ),
                info = text("p", "");
            form.append(info);
            let requestId = crypto.randomUUID(),
                attempted = false;
            const update = () => {
                room.disabled = channel.value !== "chat";
                to.disabled = channel.value !== "mail";
                info.textContent =
                    channel.value === "mail"
                        ? options.mailReady
                            ? `발신 ${options.mailFrom} · 첨부 한도 ${Math.floor(options.mailBudget / 1024 / 1024)}MB`
                            : "메일 발송 설정이 필요합니다."
                        : "선택한 대화방에 보관된 PDF를 첨부합니다.";
                reviewed.checked = false;
            };
            channel.onchange = update;
            update();
            const send = button("확인한 대상에게 PDF 전송", () =>
                run(async () => {
                    if (!reviewed.checked)
                        throw Error("수신 대상과 PDF를 확인한 뒤 체크하세요.");
                    if (channel.value === "chat" && !room.value)
                        throw Error("보낼 수 있는 대화방을 선택하세요.");
                    if (
                        channel.value === "mail" &&
                        (!options.mailReady || !to.validity.valid || !to.value)
                    )
                        throw Error("메일 설정과 수신 주소를 확인하세요.");
                    attempted = true;
                    send.disabled = true;
                    try {
                        const result = await api.request(
                            "/" +
                                record.id +
                                "/workflow/packets/" +
                                packet.id +
                                "/share",
                            {
                                method: "POST",
                                body: JSON.stringify({
                                    requestId,
                                    channel: channel.value,
                                    room:
                                        channel.value === "chat"
                                            ? Number(room.value)
                                            : null,
                                    to:
                                        channel.value === "mail"
                                            ? to.value
                                            : null,
                                    subject: subject.value,
                                    body: body.value,
                                    reviewed: true,
                                }),
                            },
                        );
                        message(
                            result.duplicate
                                ? "이 검토본 전송은 이미 처리되었습니다. 중복 전송하지 않았습니다."
                                : "보관된 PDF를 전송했습니다.",
                        );
                    } catch (e) {
                        message(
                            e.message +
                                " 전송 결과는 대화방 또는 보낸 메일에서 확인하세요. 자동으로 다시 보내지 않습니다.",
                        );
                    }
                }),
            );
            send.id = "pk-send";
            form.append(send);
            form.addEventListener("input", (e) => {
                if (e.target !== reviewed) reviewed.checked = false;
                if (attempted) send.disabled = true;
            });
        }
        $("close").onclick = () => {
            if (!working) {
                epoch++;
                dialog.close();
            }
        };
        dialog.addEventListener("cancel", (e) => {
            if (working) e.preventDefault();
            else epoch++;
        });
        return {
            review,
            refresh() {
                const s = api.state();
                library.disabled = !s.record?.canExport || s.busy || s.pending;
            },
        };
    };
})();
