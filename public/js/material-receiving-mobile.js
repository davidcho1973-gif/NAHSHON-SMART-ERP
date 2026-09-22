(function () {
    'use strict';
    const cfg = window.materialReceivingConfig;
    const t = s => cfg.translations[s] || s;
    const el = id => document.getElementById(id);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const state = {id: null, key: uuid(), photo: null, file: null, uploading: false, saving: false, confirmed: false, uploadFailed: false, records: [], preview: null, saved: false, touched: new Set(), existingProof: false};

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
    }
    function say(id, text, kind) {
        const node = el(id);
        node.textContent = text;
        node.className = 'message' + (kind ? ' ' + kind : '');
    }
    function busy() {
        el('receipt-fields').disabled = !cfg.hasSites || state.uploading || state.saving;
        el('save-receipt').disabled = state.confirmed || state.uploadFailed;
        el('save-receipt').textContent = t(state.saving ? '저장 중…' : '입고 기록 저장');
        el('refresh-receipts').disabled = state.uploading || state.saving;
        el('new-receipt').hidden = !state.id;
        el('receipt-site').disabled = !!state.id && state.existingProof;
        // Prevent navigation to another draft while a file/save is in flight.
        document.querySelectorAll('[data-edit], [data-confirm]').forEach(b => { b.disabled = state.uploading || state.saving; });
    }
    async function request(path, options) {
        const response = await fetch(cfg.baseUrl + path, Object.assign({credentials: 'same-origin'}, options, {
            headers: Object.assign({'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, options && options.headers)
        }));
        let data;
        try { data = await response.json(); } catch (_) {
            throw new Error(t('서버에 연결하지 못했습니다. 입력을 유지했으니 다시 시도하세요.'));
        }
        if (!response.ok || data.success === false) {
            if (data.errors) throw new Error(Object.values(data.errors).flat().map(t).join('\n'));
            throw new Error(t(data.error || data.message || '처리하지 못했습니다. 다시 시도하세요.'));
        }
        return data;
    }
    function addLine(line) {
        const data = line || {};
        const row = document.createElement('div');
        row.className = 'line';
        row.receiptMetadata = {item_id: data.item_id || data.itemId || null, note: data.note || null};
        row.innerHTML = '<div class="line-head"><strong>' + t('입고 품목') + '</strong><button type="button" data-remove>' + t('삭제') + '</button></div>' +
            '<label>' + t('품명 · 규격') + '<input data-field="name" maxlength="255" required value="' + esc(data.name) + '"></label>' +
            '<div class="pair"><label>' + t('입고 수량') + '<input data-field="quantity" type="number" inputmode="decimal" min="0.001" max="999999999" step="0.001" required value="' + esc(data.quantity) + '"></label>' +
            '<label>' + t('단위') + '<input data-field="unit" maxlength="32" placeholder="EA / box / ft" value="' + esc(data.unit) + '"></label></div>' +
            '<label>' + t('단가 (선택)') + '<input data-field="unit_price" type="number" inputmode="decimal" min="0" max="999999999" step="0.01" value="' + esc(data.unit_price == null ? data.unitPrice : data.unit_price) + '"></label>';
        row.querySelector('[data-remove]').addEventListener('click', () => {
            if (el('receipt-lines').children.length > 1) row.remove();
            else {
                row.querySelectorAll('input').forEach(input => { input.value = ''; });
                row.receiptMetadata = {item_id: null, note: null};
            }
            changed();
        });
        row.querySelector('[data-field="name"]').addEventListener('input', () => { row.receiptMetadata.item_id = null; });
        el('receipt-lines').appendChild(row);
    }
    function changed() {
        state.saved = false;
        el('saved-actions').replaceChildren();
    }
    function readLines() {
        return Array.from(el('receipt-lines').children).map(row => {
            const data = Object.assign({}, row.receiptMetadata);
            row.querySelectorAll('[data-field]').forEach(input => {
                data[input.dataset.field] = input.value.trim();
            });
            data.quantity = Number(data.quantity);
            data.unit_price = data.unit_price === '' ? null : Number(data.unit_price);
            return data;
        });
    }
    function clearPreview() {
        if (state.preview) URL.revokeObjectURL(state.preview);
        state.preview = null;
        el('proof-preview').hidden = true;
        el('proof-preview').removeAttribute('src');
    }
    function fill(data) {
        el('vendor').value = data.vendor || '';
        el('received-on').value = data.received_on || data.receivedOn || cfg.today;
        el('po-no').value = data.po_no || data.poNo || '';
        el('delivery-no').value = data.delivery_no || data.deliveryNo || '';
        el('receipt-note').value = data.note || '';
        el('receipt-lines').replaceChildren();
        const lines = data.lines && data.lines.length ? data.lines : [{}];
        lines.forEach(addLine);
    }
    async function upload() {
        if (!state.file || state.uploading) return;
        if (!el('receipt-site').value) {
            state.uploadFailed = true;
            say('upload-message', t('먼저 현장을 선택하세요.'), 'error');
            el('retry-upload').hidden = false;
            busy();
            return;
        }
        state.uploading = true;
        state.uploadFailed = false;
        state.photo = null;
        el('retry-upload').hidden = true;
        changed();
        busy();
        say('upload-message', t('첨부 중… 자동 읽기는 잠시 걸릴 수 있습니다.'));
        const form = new FormData();
        form.append('file', state.file);
        form.append('site_id', el('receipt-site').value);
        form.append('analyze', el('use-ai').checked ? '1' : '0');
        try {
            const result = await request('/upload', {method: 'POST', body: form});
            state.photo = result.file;
            // Read the slip only into a blank form: a new attachment must not erase human corrections.
            const hasLines = readLines().some(l => l.name || l.quantity);
            if (result.data && !state.id) {
                const suggestions = {'vendor': result.data.vendor, 'received-on': result.data.received_on, 'po-no': result.data.po_no, 'delivery-no': result.data.delivery_no};
                Object.entries(suggestions).forEach(([id, value]) => {
                    if (value && !state.touched.has(id) && (!el(id).value || id === 'received-on')) el(id).value = value;
                });
                if (!hasLines && result.data.lines && result.data.lines.length) {
                    el('receipt-lines').replaceChildren(); result.data.lines.forEach(addLine);
                }
            }
            say('upload-message', t(result.warning || '첨부 완료. 품목과 수량을 확인하고 저장하세요.'), result.warning ? '' : 'success');
        } catch (error) {
            state.uploadFailed = true;
            say('upload-message', error.message, 'error');
            el('retry-upload').hidden = false;
        } finally {
            state.uploading = false;
            busy();
        }
    }
    async function selected(input) {
        const file = input.files && input.files[0];
        input.value = '';
        if (!file) return;
        if (file.size > 32 * 1024 * 1024) {
            say('upload-message', t('파일은 32MB 이하로 선택하세요.'), 'error');
            return;
        }
        state.file = file;
        el('remove-upload').hidden = false;
        el('proof-name').textContent = file.name;
        clearPreview();
        if (/^image\/(jpeg|png|webp)$/.test(file.type)) {
            state.preview = URL.createObjectURL(file);
            el('proof-preview').src = state.preview;
            el('proof-preview').hidden = false;
        }
        await upload();
    }
    function reset() {
        state.id = null;
        state.key = uuid();
        state.photo = null;
        state.file = null;
        state.confirmed = false;
        state.uploadFailed = false;
        state.saved = false;
        state.touched.clear();
        state.existingProof = false;
        clearPreview();
        fill({});
        el('proof-name').replaceChildren();
        el('saved-actions').replaceChildren();
        el('retry-upload').hidden = true;
        el('remove-upload').hidden = true;
        say('upload-message', '');
        say('save-message', '');
        el('form-title').textContent = t('새 입고 등록');
        busy();
    }
    function edit(record) {
        if (state.saving || state.uploading) return;
        if (!state.saved && (state.file || readLines().some(l => l.name)) && !window.confirm(t('현재 입력을 닫고 다른 입고 기록을 여시겠습니까?'))) return;
        reset();
        state.id = record.id;
        state.saved = true;
        state.confirmed = record.status === 'confirmed';
        state.existingProof = !!record.photoUrl;
        el('receipt-site').value = record.siteId;
        fill(record);
        el('form-title').textContent = t('입고 기록') + ' #' + record.id;
        el('proof-name').textContent = record.photoName || '';
        if (record.photoUrl) {
            const link = document.createElement('a');
            link.href = record.photoUrl; link.target = '_blank'; link.rel = 'noopener';
            link.textContent = t('첨부파일 보기');
            el('proof-name').append(' · ', link);
        }
        busy();
        showConfirm();
        el('form-title').scrollIntoView({behavior: 'smooth', block: 'start'});
    }
    function showConfirm() {
        el('saved-actions').replaceChildren();
        if (!state.id || state.confirmed || !state.saved) return;
        const button = document.createElement('button');
        button.type = 'button'; button.dataset.confirm = state.id;
        button.textContent = t('수량 확인 후 입고 확정');
        button.addEventListener('click', () => confirmReceipt(state.id));
        el('saved-actions').appendChild(button);
    }
    async function confirmReceipt(id) {
        if (state.saving || state.uploading) return;
        if (!window.confirm(t('실제 받은 품목과 수량을 확인했습니까? 확인한 입고 기록을 확정합니다.'))) return;
        state.saving = true; busy();
        try {
            await request('/' + encodeURIComponent(id) + '/confirm', {method: 'POST'});
            if (state.id === id) { state.confirmed = true; showConfirm(); }
            say('save-message', t('입고를 확정했습니다.'), 'success');
            await history();
        } catch (error) { say('save-message', error.message, 'error'); }
        finally { state.saving = false; busy(); }
    }
    async function save(event) {
        event.preventDefault();
        if (state.saving || state.uploading || state.confirmed || state.uploadFailed) return;
        if (!el('receipt-form').reportValidity()) return;
        state.saving = true; busy();
        say('save-message', t('저장 중…'));
        const payload = {
            id: state.id, request_key: state.key, site_id: el('receipt-site').value,
            received_on: el('received-on').value, vendor: el('vendor').value.trim(),
            po_no: el('po-no').value.trim(), delivery_no: el('delivery-no').value.trim(),
            note: el('receipt-note').value.trim(), lines: readLines()
        };
        if (state.photo) payload.photo = state.photo;
        try {
            const result = await request('', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
            state.id = result.id;
            state.confirmed = result.status === 'confirmed';
            el('form-title').textContent = t('입고 기록') + ' #' + result.id;
            await history();
            const stored = state.records.find(record => Number(record.id) === Number(result.id));
            if (!stored) throw new Error(t('저장 결과를 다시 불러오지 못했습니다. 새로고침 후 입고 기록을 확인하세요.'));
            // Always review server quantities, including a retry whose first response was lost.
            fill(stored);
            state.photo = null; state.file = null;
            clearPreview();
            el('proof-name').textContent = stored.photoName || '';
            if (stored.photoUrl) {
                const link = document.createElement('a');
                link.href = stored.photoUrl; link.target = '_blank'; link.rel = 'noopener';
                link.textContent = t('첨부파일 보기');
                el('proof-name').append(' · ', link);
            }
            state.existingProof = !!stored.photoUrl;
            state.saved = true;
            el('remove-upload').hidden = true;
            say('save-message', t(result.replayed ? '앞서 저장된 입고 기록을 복구했습니다. 화면의 저장된 수량을 확인하세요.' : '입고 기록을 저장했습니다. 수량을 검토한 뒤 확정해 주세요.'), 'success');
            showConfirm();
        } catch (error) { say('save-message', error.message, 'error'); }
        finally { state.saving = false; busy(); }
    }
    async function history() {
        const host = el('receipt-history');
        state.records = [];
        try {
            const site = el('receipt-site').value || 'ALL';
            const result = await request('/items?site_id=' + encodeURIComponent(site));
            state.records = result.items || [];
            host.replaceChildren();
            if (!state.records.length) { host.textContent = t('등록된 입고가 없습니다.'); return; }
            state.records.forEach(record => {
                const row = document.createElement('article');
                row.className = 'receipt';
                row.innerHTML = '<div class="history-head"><h3>' + esc(record.receivedOn) + ' · ' + esc(record.vendor || t('업체 미입력')) + '</h3>' +
                    '<span class="badge' + (record.status === 'confirmed' ? ' confirmed' : '') + '">' + t(record.status === 'confirmed' ? '확정' : '확인 대기') + '</span></div>' +
                    '<div class="field-subtle">#' + esc(record.id) + ' · ' + esc(record.siteCode || record.site) + '</div>' +
                    '<ul>' + record.lines.map(line => '<li>' + esc(line.name) + ' · <b>' + esc(line.quantity) + ' ' + esc(line.unit) + '</b></li>').join('') + '</ul>';
                const links = document.createElement('div'); links.className = 'links';
                if (record.photoUrl) {
                    const proof = document.createElement('a'); proof.href = record.photoUrl; proof.target = '_blank'; proof.rel = 'noopener';
                    proof.textContent = t('첨부파일 보기'); links.appendChild(proof);
                }
                if (record.status !== 'confirmed') {
                    const button = document.createElement('button'); button.type = 'button'; button.dataset.edit = record.id;
                    button.textContent = t('열기 · 수정 · 확정'); button.addEventListener('click', () => edit(record)); links.appendChild(button);
                }
                row.appendChild(links); host.appendChild(row);
            });
            busy();
        } catch (error) { host.textContent = error.message; }
    }
    el('take-photo').addEventListener('click', () => el('camera-file').click());
    el('choose-file').addEventListener('click', () => el('proof-file').click());
    el('camera-file').addEventListener('change', event => selected(event.target));
    el('proof-file').addEventListener('change', event => selected(event.target));
    el('retry-upload').addEventListener('click', upload);
    el('remove-upload').addEventListener('click', () => {
        state.file = null; state.photo = null; state.uploadFailed = false;
        clearPreview(); el('proof-name').replaceChildren();
        el('remove-upload').hidden = true; el('retry-upload').hidden = true;
        say('upload-message', state.existingProof ? t('기존에 저장된 첨부파일은 유지됩니다.') : '');
        changed(); busy();
    });
    el('add-line').addEventListener('click', () => { addLine(); changed(); });
    el('receipt-form').addEventListener('input', event => { state.touched.add(event.target.id); changed(); });
    el('receipt-form').addEventListener('submit', save);
    el('receipt-site').addEventListener('change', async () => {
        if (state.file) await upload();
        await history();
    });
    el('new-receipt').addEventListener('click', () => {
        if (!state.saved && !window.confirm(t('현재 입력을 닫고 새 입고를 작성하시겠습니까?'))) return;
        reset();
    });
    el('refresh-receipts').addEventListener('click', history);
    window.addEventListener('beforeunload', event => {
        if (state.uploading || state.saving || (!state.saved && (state.file || readLines().some(l => l.name)))) {
            event.preventDefault(); event.returnValue = '';
        }
    });
    addLine(); busy(); history();
})();
