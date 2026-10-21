(function () {
    "use strict";
    window.createCADRecovery = function (api) {
        const owner = document.body.dataset.editorOwner;
        if (!owner || !window.indexedDB) return null;
        let tab;
        try {
            tab = sessionStorage.getItem("erp.cad.tab");
            if (!tab) {
                tab = crypto.randomUUID();
                sessionStorage.setItem("erp.cad.tab", tab);
            }
        } catch {
            tab = crypto.randomUUID();
        }
        const indicator = document.createElement("button");
        indicator.id = "cad-recovery-status";
        indicator.type = "button";
        indicator.textContent = "임시 저장 준비";
        indicator.className = "recovery-status";
        document.querySelector("header").append(indicator);
        const dialog = document.createElement("dialog");
        dialog.id = "cad-recovery-dialog";
        dialog.className = "cad-dialog";
        dialog.innerHTML = `<h2>저장하지 못한 작업 복구</h2><p id="cad-recovery-message"></p><select id="cad-recovery-list" aria-label="복구할 임시 작업"></select><p class="note">이 브라우저에 7일 동안 보관합니다. 최대 10개 작업·100MB이며, 공간이 부족하면 경고합니다. 다른 기기에는 전달되지 않습니다.</p><p class="note">서버 개정이 달라졌다면 별도 도면 사본으로 복구합니다. 기존 기성·승인 연결은 새 사본으로 이동하지 않습니다.</p><p id="cad-recovery-error" role="status"></p><div class="dialog-actions"><button id="cad-recovery-delete" class="danger">이 임시 작업 삭제</button><button id="cad-recovery-close">현재 도면 계속</button><button id="cad-recovery-copy">별도 복구 사본 저장</button><button id="cad-recovery-restore">현재 개정에 복구</button></div>`;
        document.body.append(dialog);
        const $ = (id) => document.getElementById("cad-recovery-" + id);
        let timer = null,
            queue = Promise.resolve(),
            candidates = [],
            offered = "",
            restoredKey = null,
            working = false,
            generation = 0;
        const dbPromise = new Promise((resolve, reject) => {
            const request = indexedDB.open("erp.cad.recovery.v1", 1);
            request.onupgradeneeded = () => {
                const store = request.result.createObjectStore("drafts", {
                    keyPath: "key",
                });
                store.createIndex("owner", "owner", { unique: false });
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
            request.onblocked = () =>
                reject(Error("임시 저장소를 여는 다른 탭을 확인하세요."));
        });
        dbPromise.catch(() => {
            indicator.textContent = "임시 저장 사용 불가 · 서버에 저장하세요";
        });
        const run = (fn) => {
            const next = queue.catch(() => {}).then(fn);
            queue = next;
            return next;
        };
        function transaction(mode, fn) {
            return dbPromise.then(
                (db) =>
                    new Promise((resolve, reject) => {
                        const tx = db.transaction("drafts", mode);
                        let result;
                        tx.oncomplete = () => resolve(result);
                        tx.onerror = () => reject(tx.error);
                        tx.onabort = () =>
                            reject(
                                tx.error ||
                                    Error("임시 저장이 취소되었습니다."),
                            );
                        fn(
                            tx.objectStore("drafts"),
                            (value) => (result = value),
                        );
                    }),
            );
        }
        const readAll = () =>
            transaction("readonly", (store, done) => {
                const request = store.index("owner").getAll(owner);
                request.onsuccess = () => done(request.result);
            });
        const remove = (keys) =>
            transaction("readwrite", (store) =>
                keys.forEach((key) => store.delete(key)),
            );
        function error(message) {
            indicator.textContent = "임시 저장 오류 · 서버 저장 필요";
            indicator.title = message;
            api.status(message);
        }
        async function flush() {
            clearTimeout(timer);
            timer = null;
            const state = api.state();
            if (
                !state.record ||
                !state.dirty ||
                !state.canWrite ||
                state.busy ||
                state.pending
            )
                return;
            const payload = api.capture(),
                entry = {
                    ...payload,
                    key: owner + ":" + state.record.id + ":" + tab,
                    owner,
                    drawingId: state.record.id,
                    siteId: state.record.site_id,
                    baseRevision: state.record.revision,
                    title: state.record.title,
                    version: state.version,
                    updatedAt: Date.now(),
                    requestKey: crypto.randomUUID(),
                };
            entry.bytes = new TextEncoder().encode(
                JSON.stringify(entry),
            ).length;
            const epoch = generation;
            indicator.textContent = "임시 저장 중…";
            try {
                await run(async () => {
                    if (epoch !== generation) return;
                    const all = await readAll(),
                        expired = all.filter(
                            (row) => row.updatedAt < Date.now() - 7 * 86400000,
                        );
                    if (expired.length)
                        await remove(expired.map((row) => row.key));
                    const other = all.filter(
                        (row) =>
                            row.key !== entry.key && !expired.includes(row),
                    );
                    if (
                        other.length >= 10 ||
                        other.reduce((sum, row) => sum + (row.bytes || 0), 0) +
                            entry.bytes >
                            100000000
                    )
                        throw Error(
                            "브라우저 임시 저장 한도에 도달했습니다. 서버에 저장하거나 이전 임시 작업을 정리하세요.",
                        );
                    await transaction("readwrite", (store) => store.put(entry));
                });
                if (epoch === generation)
                    indicator.textContent =
                        "임시 저장됨 · " +
                        new Date(entry.updatedAt).toLocaleTimeString();
            } catch (err) {
                error("임시 저장 실패: " + err.message);
            }
        }
        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(flush, 400);
        }
        async function saved() {
            clearTimeout(timer);
            timer = null;
            generation++;
            const record = api.state().record;
            if (!record) return;
            const keys = [
                owner + ":" + record.id + ":" + tab,
                ...(restoredKey ? [restoredKey] : []),
            ];
            restoredKey = null;
            try {
                await run(() => remove(keys));
                indicator.textContent = "서버 저장됨";
            } catch {
                indicator.textContent = "서버 저장됨 · 임시본 정리 대기";
            }
        }
        function render() {
            const entry = candidates.find((row) => row.key === $("list").value),
                state = api.state(),
                matches =
                    entry && entry.baseRevision === state.record?.revision;
            $("message").textContent = entry
                ? `${entry.title} · 임시 작업 기준 개정 ${entry.baseRevision} · 서버 현재 개정 ${state.record?.revision} · ${new Date(entry.updatedAt).toLocaleString()}`
                : "복구할 임시 작업이 없습니다.";
            $("restore").disabled =
                !entry || !matches || !state.canWrite || working;
            $("copy").disabled = !entry || !state.canWrite || working;
            $("delete").disabled = !entry || working;
            $("close").disabled = working;
            $("list").disabled = working;
            if (entry && !matches)
                $("message").textContent +=
                    " · 개정 충돌: 별도 사본으로 복구하세요.";
        }
        async function offer(force = false) {
            const state = api.state();
            if (
                !state.record ||
                state.busy ||
                !state.canWrite ||
                state.pending ||
                dialog.open
            )
                return;
            const signature = state.record.id + ":" + state.record.revision;
            if (!force && offered === signature) return;
            offered = signature;
            try {
                candidates = (await readAll())
                    .filter(
                        (row) =>
                            row.drawingId === state.record.id &&
                            row.siteId === state.record.site_id &&
                            row.updatedAt > Date.now() - 7 * 86400000,
                    )
                    .sort((a, b) => b.updatedAt - a.updatedAt);
                if (api.state().record?.id !== state.record.id) return;
                if (!candidates.length) {
                    if (force) api.status("복구할 임시 작업이 없습니다.");
                    return;
                }
                $("list").replaceChildren(
                    ...candidates.map(
                        (row) =>
                            new Option(
                                `개정 ${row.baseRevision} · ${new Date(row.updatedAt).toLocaleString()}`,
                                row.key,
                            ),
                    ),
                );
                $("error").textContent = "";
                render();
                dialog.showModal();
            } catch (err) {
                error("임시 작업을 확인하지 못했습니다: " + err.message);
            }
        }
        indicator.onclick = () => offer(true);
        $("list").onchange = render;
        $("close").onclick = () => dialog.close();
        dialog.addEventListener("cancel", (event) => {
            if (working) event.preventDefault();
        });
        $("delete").onclick = async () => {
            const key = $("list").value;
            if (!key || working) return;
            working = true;
            render();
            try {
                await run(() => remove([key]));
                candidates = candidates.filter((row) => row.key !== key);
                $("list")
                    .querySelector(`option[value="${CSS.escape(key)}"]`)
                    ?.remove();
                if (!candidates.length) dialog.close();
            } catch (err) {
                $("error").textContent = err.message;
            } finally {
                working = false;
                render();
            }
        };
        $("restore").onclick = async () => {
            const entry = candidates.find((row) => row.key === $("list").value);
            if (
                !entry ||
                entry.baseRevision !== api.state().record?.revision ||
                working
            )
                return;
            working = true;
            render();
            try {
                dialog.close();
                if (await api.restore(entry)) {
                    restoredKey = entry.key;
                    indicator.textContent = "임시 작업 복구됨 · 서버 저장 필요";
                    schedule();
                }
            } catch (err) {
                $("error").textContent = err.message;
                dialog.showModal();
            } finally {
                working = false;
                render();
            }
        };
        $("copy").onclick = async () => {
            const entry = candidates.find((row) => row.key === $("list").value);
            if (!entry || working) return;
            working = true;
            render();
            try {
                const result = await api.copy(entry);
                if (result) {
                    await run(() => remove([entry.key]));
                    dialog.close();
                    api.status("별도 복구 사본을 저장했습니다.");
                }
            } catch (err) {
                $("error").textContent = err.message;
            } finally {
                working = false;
                render();
            }
        };
        document.addEventListener("visibilitychange", () => {
            if (document.visibilityState === "hidden") flush();
        });
        window.addEventListener("pagehide", flush);
        return { schedule, flush, saved, offer };
    };
})();
