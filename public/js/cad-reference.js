(function () {
    "use strict";
    let pdfModule;
    const cache = new Map(),
        pdfTasks = new WeakMap();
    async function pdfjs() {
        if (!pdfModule) {
            pdfModule = await import("/vendor/pdfjs/pdf.min.js");
            pdfModule.GlobalWorkerOptions.workerSrc =
                "/vendor/pdfjs/pdf.worker.min.js";
        }
        return pdfModule;
    }
    async function openPdf(data) {
        const lib = await pdfjs(),
            task = lib.getDocument({
                data,
                isEvalSupported: false,
                standardFontDataUrl: "/vendor/pdfjs/standard_fonts/",
            });
        try {
            const doc = await task.promise;
            pdfTasks.set(doc, task);
            return doc;
        } catch (e) {
            await task.destroy();
            throw e;
        }
    }
    async function bitmap(blob) {
        const image = await createImageBitmap(blob);
        if (image.width * image.height > 40000000) {
            image.close();
            throw Error("이미지는 최대 4천만 화소까지 지원합니다.");
        }
        return image;
    }
    function rasterSize(width, height) {
        const factor = Math.min(
            2,
            4096 / width,
            4096 / height,
            Math.sqrt(16000000 / (width * height)),
        );
        return {
            width: Math.max(1, Math.round(width * factor)),
            height: Math.max(1, Math.round(height * factor)),
            factor,
        };
    }
    async function renderPage(pdf, pageNo) {
        if (pageNo < 1 || pageNo > pdf.numPages)
            throw Error("선택한 PDF 페이지가 없습니다.");
        const page = await pdf.getPage(pageNo),
            viewport = page.getViewport({ scale: 1 }),
            size = rasterSize(viewport.width, viewport.height),
            canvas = document.createElement("canvas");
        canvas.width = size.width;
        canvas.height = size.height;
        await page.render({
            canvasContext: canvas.getContext("2d"),
            viewport: page.getViewport({ scale: size.factor }),
        }).promise;
        page.cleanup();
        return { canvas, width: viewport.width, height: viewport.height };
    }
    async function prepare(file, fixedPage = null) {
        if (!/\.(pdf|png|jpe?g)$/i.test(file.name)) return null;
        if (!/\.pdf$/i.test(file.name)) {
            const image = await bitmap(file),
                result = { page: 1, width: image.width, height: image.height };
            image.close();
            return result;
        }
        const pdf = await openPdf(new Uint8Array(await file.arrayBuffer()));
        try {
            if (pdf.numPages > 500)
                throw Error("500페이지 이하의 PDF를 사용하세요.");
            return await new Promise((resolve, reject) => {
                const dialog = document.createElement("dialog");
                dialog.className = "cad-dialog";
                dialog.id = "cad-reference-dialog";
                dialog.innerHTML =
                    '<h2>PDF에서 편집할 페이지 선택</h2><p>한 페이지를 하나의 편집 도면으로 등록합니다. 다른 페이지도 원본 PDF를 다시 선택하여 추가할 수 있습니다.</p><select id="cad-reference-page" aria-label="PDF 페이지"></select><img id="cad-reference-preview" alt="등록할 PDF 페이지 미리보기" style="max-width:100%;max-height:60vh"><p id="cad-reference-state" role="status"></p><div class="dialog-actions"><button id="cad-reference-cancel">취소</button><button id="cad-reference-confirm" disabled>이 페이지 등록</button></div>';
                document.body.append(dialog);
                const select = dialog.querySelector("select"),
                    preview = dialog.querySelector("img"),
                    state = dialog.querySelector("[role=status]"),
                    confirm = dialog.querySelector("#cad-reference-confirm");
                let rendered = null,
                    version = 0,
                    settled = false;
                for (let i = 1; i <= pdf.numPages; i++)
                    select.add(new Option(`${i} / ${pdf.numPages} 페이지`, i));
                if (fixedPage !== null) {
                    if (fixedPage < 1 || fixedPage > pdf.numPages) {
                        dialog.remove();
                        resolve(false);
                        return;
                    }
                    select.value = String(fixedPage);
                    select.disabled = true;
                }
                const end = (result) => {
                    if (settled) return;
                    settled = true;
                    version++;
                    dialog.close();
                    dialog.remove();
                    resolve(result);
                };
                const refresh = async () => {
                    const n = ++version;
                    confirm.disabled = true;
                    rendered = null;
                    state.textContent = "페이지를 불러오는 중…";
                    try {
                        const result = await renderPage(
                            pdf,
                            Number(select.value),
                        );
                        if (n !== version) return;
                        rendered = result;
                        preview.src = result.canvas.toDataURL("image/png");
                        confirm.disabled = false;
                        state.textContent = `${result.width.toFixed(1)} × ${result.height.toFixed(1)} PDF 좌표 · 실제 거리는 도면에서 보정하세요.`;
                    } catch (e) {
                        if (n === version) state.textContent = e.message;
                    }
                };
                select.onchange = refresh;
                dialog.querySelector("#cad-reference-cancel").onclick = () =>
                    end(false);
                dialog.addEventListener("cancel", (e) => {
                    e.preventDefault();
                    end(false);
                });
                confirm.onclick = () => {
                    if (rendered)
                        end({
                            page: Number(select.value),
                            width: rendered.width,
                            height: rendered.height,
                        });
                };
                dialog.showModal();
                refresh();
            });
        } finally {
            await pdfTasks.get(pdf).destroy();
        }
    }
    async function load(reference) {
        if (!reference) return;
        const key = reference.sha256 + ":" + reference.page;
        if (cache.has(key)) return;
        const url = new URL(reference.url, location.origin);
        if (url.origin !== location.origin)
            throw Error("도면 원본 주소가 올바르지 않습니다.");
        const response = await fetch(url, { credentials: "same-origin" });
        if (!response.ok) throw Error("도면 원본을 읽지 못했습니다.");
        const blob = await response.blob();
        let image;
        if (reference.format === "pdf") {
            const pdf = await openPdf(new Uint8Array(await blob.arrayBuffer()));
            try {
                const page = await renderPage(pdf, reference.page);
                if (
                    Math.abs(page.width - reference.width) > 0.1 ||
                    Math.abs(page.height - reference.height) > 0.1
                )
                    throw Error("저장된 PDF 페이지 크기가 원본과 다릅니다.");
                image = page.canvas;
            } finally {
                await pdfTasks.get(pdf).destroy();
            }
        } else {
            image = await bitmap(blob);
            if (
                image.width !== reference.width ||
                image.height !== reference.height
            ) {
                image.close();
                throw Error("저장된 이미지 크기가 원본과 다릅니다.");
            }
        }
        cache.set(key, image);
        while (cache.size > 3) {
            const first = cache.keys().next().value;
            cache.get(first).close?.();
            cache.delete(first);
        }
    }
    function draw(ctx, doc, screen) {
        const ref = doc.reference;
        if (!ref) return;
        const image = cache.get(ref.sha256 + ":" + ref.page);
        if (!image)
            throw Error("배경 원본이 준비되지 않았습니다. 도면을 다시 여세요.");
        const a = screen({ x: 0, y: ref.height }),
            b = screen({ x: ref.width, y: ref.height }),
            c = screen({ x: 0, y: 0 });
        ctx.save();
        ctx.transform(
            (b.x - a.x) / image.width,
            (b.y - a.y) / image.width,
            (c.x - a.x) / image.height,
            (c.y - a.y) / image.height,
            a.x,
            a.y,
        );
        ctx.drawImage(image, 0, 0);
        ctx.restore();
    }
    window.CADReference = { prepare, load, draw };
})();
