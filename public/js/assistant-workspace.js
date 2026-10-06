(function (root) {
    'use strict';
    function createGuard() {
        var generation = 0, pending = new Set();
        return {
            begin: function (key) { if (pending.has(key)) return null; pending.add(key); return { key: key, generation: generation }; },
            valid: function (ticket) { return !!ticket && ticket.generation === generation; },
            end: function (ticket) { if (ticket) pending.delete(ticket.key); },
            change: function () { generation++; },
            busy: function (key) { return pending.has(key); }
        };
    }
    function reportParams(company, site, dataset, search, after) {
        return { company_id: Number(company), site_id: site ? Number(site) : null, dataset: dataset, search: search || '', after_id: after || 0 };
    }
    function proposalInput(operation, values) {
        var payload, record = null;
        if (operation === 'ops.todo.create' || operation === 'ops.todo.update') {
            payload = {title: values.title, detail: values.detail || null, due_on: values.due || null};
            if (operation === 'ops.todo.update') record = Number(values.record);
        } else if (operation === 'daily_plan.draft.update') {
            record = Number(values.record); payload = {work_scope: values.title, notes: values.detail || null};
        } else if (operation === 'daily_report.draft.create') {
            payload = {report_date: values.reportDate, work_title: values.title, work_today: values.workToday, work_tomorrow: values.workTomorrow || null};
        } else if (operation === 'expense.pending.create') {
            payload = {description: values.expenseDescription, amount: String(values.amount), currency: 'USD', expense_date: values.expenseDate,
                accounting_account: values.account, payment_type: values.payment, source_document_id: values.sourceDocument ? Number(values.sourceDocument) : null};
        } else if (operation === 'document.category.update') {
            record = Number(values.record); payload = {category: values.category};
        } else { throw new Error('Unsupported operation'); }
        return {operation: operation, site_id: Number(values.site), record_id: record, payload: payload};
    }
    var exported = { createGuard: createGuard, reportParams: reportParams, proposalInput: proposalInput };
    if (typeof module !== 'undefined' && module.exports) module.exports = exported;
    if (!root.document) return;
    var host = root.document.getElementById('assistant-workspace');
    if (!host) return;
    var doc = root.document, base = host.dataset.base, options = JSON.parse(host.dataset.options || '{}');
    var guard = createGuard(), proposal = null, cursor = null, settings = {}, mutationBusy = false;
    var savedKey = 'erp-assistant-proposal-' + options.actor_id;
    function savedId(value) { try { if (arguments.length) { if (value) root.sessionStorage.setItem(savedKey, value); else root.sessionStorage.removeItem(savedKey); } return root.sessionStorage.getItem(savedKey); } catch (_) { return null; } }
    var tr = function (s) { return typeof root.t === 'function' ? root.t(s) : s; };
    var el = function (id) { return doc.getElementById('assistant-' + id); };
    var csrf = doc.querySelector('meta[name=csrf-token]').content;
    function message(text, bad) { el('status').textContent = text || ''; el('status').className = 'hint' + (bad ? ' bad' : ''); }
    function node(tag, text) { var e = doc.createElement(tag); if (text !== undefined) e.textContent = text == null ? '' : String(text); return e; }
    function select(id, rows, valueKey, labelKey, preferred) {
        el(id).replaceChildren();
        rows.forEach(function (row) { var option = node('option', row[labelKey]); option.value = row[valueKey]; el(id).appendChild(option); });
        if (preferred && rows.some(function (r) { return String(r[valueKey]) === String(preferred); })) el(id).value = preferred;
    }
    function setMutationBusy(value) {
        mutationBusy = value;
        ['company', 'site', 'operation', 'record', 'title', 'detail', 'due', 'approve', 'report-date', 'work-today', 'work-tomorrow', 'expense-description', 'amount', 'expense-date', 'account', 'payment', 'source-document', 'category'].forEach(function (id) { el(id).disabled = value; });
        host.querySelectorAll('button').forEach(function (b) { b.disabled = value || (b.dataset.action === 'activate-check' && !settings.checks_enabled); });
        el('write-fields').disabled = value || !settings.mutations_enabled;
        var confirm = host.querySelector('[data-action=confirm]');
        confirm.disabled = value || !proposal || !el('approve').checked || !settings.mutations_enabled;
    }
    async function api(path, payload, method) {
        var response = await root.fetch(base + path, { method: method || (payload ? 'POST' : 'GET'), credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: payload ? JSON.stringify(payload) : undefined });
        var data;
        try { data = await response.json(); } catch (_) { throw new Error(tr('서버 응답을 확인할 수 없습니다. 다시 시도하세요.')); }
        if (!response.ok || data.success === false) {
            var first = data.errors && Object.values(data.errors)[0];
            throw new Error((first && first[0]) || data.error || data.message || tr('요청을 처리하지 못했습니다.'));
        }
        return data;
    }
    function filters(after) { return reportParams(el('company').value, el('site').value, el('dataset').value, el('search').value, after); }
    function exportLink() {
        var values = filters(0), params = new URLSearchParams();
        Object.keys(values).forEach(function (key) { if (values[key] !== null) params.set(key, values[key]); });
        el('export').href = base + '/export?' + params.toString();
        el('export').hidden = !values.company_id || !values.dataset;
    }
    function resetPreview() { savedId(null); proposal = null; el('preview').hidden = true; el('approve').checked = false; host.querySelector('[data-action=confirm]').disabled = true; }
    function scopeChanged() {
        guard.change(); cursor = null; resetPreview(); el('report').replaceChildren(); host.querySelector('[data-action=next]').hidden = true;
        exportLink(); root.dispatchEvent(new Event('assistant-scope-changed')); refresh();
    }
    function sites() {
        var rows = (options.sites || []).filter(function (s) { return String(s.company_id) === el('company').value; });
        select('site', rows, 'id', 'name', options.default_site_id);
    }
    function drawChecks(rows) {
        el('checks').replaceChildren();
        (rows || []).forEach(function (check) {
            var row = node('div'); row.className = 'row';
            row.appendChild(node('strong', check.site_name + ' · ' + check.label));
            row.appendChild(node('p', (check.enabled ? tr('실행 중') : tr('꺼짐')) + ' · ' + check.interval_hours + 'h'));
            if (check.last_run_at) row.appendChild(node('p', tr('마지막 실행') + ': ' + check.last_run_at));
            if (check.next_run_at) row.appendChild(node('p', tr('다음 실행') + ': ' + check.next_run_at));
            if (check.result) {
                row.appendChild(node('p', tr('현재 조회') + ': ' + check.result.count + ' · ' + check.result.as_of));
                var list = node('ul');
                (check.result.records || []).forEach(function (item) { list.appendChild(node('li', Object.keys(item).map(function (k) { return k + ': ' + item[k]; }).join(' · '))); });
                row.appendChild(list);
                if (check.result.truncated) row.appendChild(node('p', tr('목록은 25건까지 표시합니다. 추가 항목은 보고서에서 확인하세요.')));
            }
            if (check.error) row.appendChild(node('p', check.error));
            if (!check.enabled && settings.checks_enabled) {
                var label = node('label'), consent = node('input'); consent.type = 'checkbox'; consent.dataset.checkConsent = check.id;
                label.appendChild(consent); label.appendChild(node('span', tr('이 간격의 정기 확인 시작을 승인합니다.'))); row.appendChild(label);
            }
            var button = node('button', check.enabled ? tr('중지') : tr('승인 후 시작'));
            button.type = 'button'; button.dataset.action = check.enabled ? 'disable-check' : 'activate-check'; button.dataset.id = check.id; button.dataset.version = check.approval_version;
            button.disabled = !check.enabled && !settings.checks_enabled; row.appendChild(button); el('checks').appendChild(row);
        });
    }
    async function refresh() {
        var ticket = guard.begin('status'); if (!ticket) return;
        try {
            var result = await api('/status?company_id=' + encodeURIComponent(el('company').value || ''));
            if (!guard.valid(ticket)) return;
            settings = result;
            el('mutations-off').hidden = !!settings.mutations_enabled;
            el('write-fields').disabled = !settings.mutations_enabled;
            drawChecks(result.checks);
            if (!el('account').children.length) select('account', [{value:'',label:tr('선택하세요')}].concat((result.expense_accounts || []).map(function (a) { return {value:a,label:a}; })), 'value', 'label', el('account').value);
            if (!el('category').children.length) select('category', [{value:'',label:tr('선택하세요')}].concat(Object.entries(result.document_categories || {}).map(function (entry) { return {value:entry[0],label:entry[1]}; })), 'value', 'label', el('category').value);
            // Recovery may finish before option metadata. Browsers discard select values
            // with no matching option, so reapply only the still-current immutable preview.
            if (proposal && proposal.status === 'pending') {
                if (proposal.operation === 'expense.pending.create') el('account').value = proposal.after.accounting_account || '';
                if (proposal.operation === 'document.category.update') el('category').value = proposal.after.category || '';
            }
            var budget = result.budget || {}, daily = budget.requests && budget.requests.user_day;
            el('budget').textContent = daily ? tr('오늘 AI 질문 잔여') + ': ' + daily.remaining + '/' + daily.limit + ' · ' + tr('UTC 기준') : (budget.message || '');
        } catch (error) { if (guard.valid(ticket)) message(error.message, true); }
        finally { guard.end(ticket); if (!guard.valid(ticket)) refresh(); }
    }
    async function report(next) {
        var ticket = guard.begin('report'); if (!ticket) return;
        message(tr('조회 중입니다.'));
        try {
            var response = await api('/report', filters(next ? cursor : 0));
            if (!guard.valid(ticket)) return;
            var result = response.report; cursor = result.next_after_id;
            el('report').replaceChildren(node('p', result.summary + ' · ' + result.as_of));
            var box = node('div'); box.className = 'assistant-table'; var table = node('table'), head = node('tr');
            result.columns.forEach(function (column) { head.appendChild(node('th', column)); }); table.appendChild(head);
            result.records.forEach(function (record) {
                var row = node('tr'); result.columns.forEach(function (column) { row.appendChild(node('td', record[column])); }); table.appendChild(row);
            });
            box.appendChild(table); el('report').appendChild(box);
            result.limitations.forEach(function (line) { var p = node('p', line); p.className = 'hint'; el('report').appendChild(p); });
            host.querySelector('[data-action=next]').hidden = !cursor; exportLink(); message(result.summary);
        } catch (error) { if (guard.valid(ticket)) message(error.message, true); }
        finally { guard.end(ticket); }
    }
    function drawPreview(value) {
        var company = (options.companies || []).find(function (c) { return Number(c.id) === Number(value.company_id); });
        var site = (options.sites || []).find(function (s) { return Number(s.id) === Number(value.site_id) && Number(s.company_id) === Number(value.company_id); });
        if (!company || !site) { resetPreview(); throw new Error(tr('변경 대상 권한을 다시 확인하려면 새로고침하세요.')); }
        if (String(value.company_id) !== el('company').value || String(value.site_id) !== el('site').value) {
            guard.change(); cursor = null; el('report').replaceChildren();
            el('company').value = String(value.company_id); sites(); el('site').value = String(value.site_id);
            root.dispatchEvent(new Event('assistant-scope-changed')); exportLink();
        }
        el('operation').value = value.operation; el('record').value = value.record_id || '';
        var after = value.after || {};
        el('title').value = value.operation === 'daily_plan.draft.update' ? (after.work_scope || '') : (after.title || '');
        el('detail').value = value.operation === 'daily_plan.draft.update' ? (after.notes || '') : (after.detail || '');
        el('due').value = after.due_on || '';
        el('report-date').value = after.report_date || ''; el('work-today').value = after.work_today || ''; el('work-tomorrow').value = after.work_tomorrow || '';
        if (value.operation === 'daily_report.draft.create') el('title').value = after.work_title || '';
        el('expense-description').value = after.description || ''; el('amount').value = after.amount || ''; el('expense-date').value = after.expense_date || '';
        el('account').value = after.accounting_account || ''; el('payment').value = after.payment_type || '';
        el('source-document').value = after.receipt ? after.receipt.document_id : ''; el('category').value = after.category || '';
        syncOperation();
        proposal = value; savedId(value.id); el('preview').hidden = false; el('approve').checked = false;
        el('preview-body').replaceChildren();
        [tr('변경안 ID') + ': ' + value.id, tr('회사') + ': ' + company.name + ' (ID ' + company.id + ')',
            tr('현장') + ': ' + site.name + ' (ID ' + site.id + ')', tr('작업') + ': ' + value.operation, tr('기록 공개 범위') + ': ' + (value.visibility || ''),
            tr('기록 ID') + ': ' + (value.record_id || tr('새 기록')), tr('상태') + ': ' + value.status,
            tr('기존 내용') + ': ' + JSON.stringify(value.before || {}), tr('변경 내용') + ': ' + JSON.stringify(after), tr('만료') + ': ' + value.expires_at].forEach(function (line) {
            var text = node('div', line); text.className = 'assistant-preview-value'; el('preview-body').appendChild(text);
        });
    }
    async function recover() {
        if (mutationBusy) return;
        var id = proposal ? proposal.id : savedId(); if (!id) return;
        setMutationBusy(true);
        try {
            var result = await api('/proposals/' + encodeURIComponent(id));
            if (result.proposal.status === 'pending') { drawPreview(result.proposal); message(tr('미리보기를 확인한 뒤 승인하세요.')); }
            else {
                resetPreview(); el('preview').hidden = false;
                el('preview-body').replaceChildren(node('p', tr('상태') + ': ' + result.proposal.status + ' · ' + tr('기록 ID') + ': ' + ((result.proposal.result || {}).record_id || result.proposal.record_id || '')));
                message(tr('처리 상태를 확인했습니다.'));
            }
        } catch (error) { message(error.message, true); }
        finally { setMutationBusy(false); }
    }
    async function mutate(action, button) {
        if (mutationBusy) return;
        var payload, path;
        if (action === 'propose') {
            resetPreview();
            payload = proposalInput(el('operation').value, {site:el('site').value,record:el('record').value,title:el('title').value,detail:el('detail').value,due:el('due').value,
                reportDate:el('report-date').value,workToday:el('work-today').value,workTomorrow:el('work-tomorrow').value,expenseDescription:el('expense-description').value,
                amount:el('amount').value,expenseDate:el('expense-date').value,account:el('account').value,payment:el('payment').value,sourceDocument:el('source-document').value,category:el('category').value});
            path = '/proposals';
        } else if (action === 'confirm') {
            if (!proposal || !el('approve').checked) return;
            payload = { preview_token: proposal.preview_token, version: proposal.version, confirmed: true };
            path = '/proposals/' + encodeURIComponent(proposal.id) + '/confirm';
        } else if (action === 'cancel') {
            if (!proposal) return; path = '/proposals/' + encodeURIComponent(proposal.id) + '/cancel'; payload = {};
        } else if (action === 'save-check') {
            path = '/checks'; payload = { site_id: Number(el('site').value), kind: el('check-kind').value, interval_hours: Number(el('check-interval').value) };
        } else if (action === 'activate-check') {
            var consent = host.querySelector('[data-check-consent="' + button.dataset.id + '"]');
            if (!consent || !consent.checked) { message(tr('시작 승인에 체크해 주세요.'), true); return; }
            path = '/checks/' + button.dataset.id + '/activate'; payload = { confirmed: true, version: button.dataset.version };
        } else { path = '/checks/' + button.dataset.id + '/disable'; payload = {}; }
        setMutationBusy(true);
        try {
            var result = await api(path, payload);
            if (action === 'propose') { drawPreview(result.proposal); message(tr('미리보기를 확인한 뒤 승인하세요.')); }
            else { resetPreview(); message(action === 'confirm' ? tr('승인한 변경을 반영했습니다.') : tr('저장했습니다.')); refresh(); }
        } catch (error) { message(error.message, true); }
        finally { setMutationBusy(false); }
    }
    function syncOperation() {
        host.querySelectorAll('[data-operations]').forEach(function (field) { field.hidden = field.dataset.operations.split(' ').indexOf(el('operation').value) < 0; });
    }
    select('company', options.companies || [], 'id', 'name', options.default_company_id);
    select('dataset', options.datasets || [], 'key', 'label'); sites(); exportLink();
    el('company').addEventListener('change', function () { sites(); scopeChanged(); });
    el('site').addEventListener('change', scopeChanged);
    ['dataset', 'search'].forEach(function (id) { el(id).addEventListener('input', function () { guard.change(); cursor = null; el('report').replaceChildren(); host.querySelector('[data-action=next]').hidden = true; exportLink(); }); });
    ['operation', 'record', 'title', 'detail', 'due', 'report-date', 'work-today', 'work-tomorrow', 'expense-description', 'amount', 'expense-date', 'account', 'payment', 'source-document', 'category'].forEach(function (id) { el(id).addEventListener('input', resetPreview); });
    el('operation').addEventListener('change', syncOperation);
    syncOperation();
    el('approve').addEventListener('change', function () { host.querySelector('[data-action=confirm]').disabled = !el('approve').checked || !proposal || mutationBusy; });
    host.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-action]'); if (!button || button.disabled) return;
        var action = button.dataset.action;
        if (action === 'report' || action === 'next') report(action === 'next'); else if (action === 'recover') recover(); else mutate(action, button);
    });
    root.ErpAssistantWorkspace = { siteId: function () { return Number(el('site').value) || null; } };
    refresh();
    if (savedId()) recover();
})(typeof window !== 'undefined' ? window : globalThis);
