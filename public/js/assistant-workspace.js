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
    // Suggestions are an editable-field patch, never a proposal or an approval envelope.
    var suggestionFields = {
        'ops.todo.create': {title: 'title', detail: 'detail', due_on: 'due'},
        'ops.todo.update': {title: 'title', detail: 'detail', due_on: 'due'},
        'daily_plan.draft.update': {work_scope: 'title', notes: 'detail'},
        'daily_report.draft.create': {report_date: 'report-date', work_title: 'title', work_today: 'work-today', work_tomorrow: 'work-tomorrow'},
        'expense.pending.create': {description: 'expense-description', amount: 'amount', expense_date: 'expense-date', accounting_account: 'account', payment_type: 'payment'},
        'document.category.update': {category: 'category'}
    };
    function own(object, key) { return Object.prototype.hasOwnProperty.call(object, key); }
    function suggestionInput(operation, values) {
        if (!own(suggestionFields, operation)) throw new Error('Unsupported operation');
        var text = values.requestText;
        if (typeof text !== 'string' || !text.trim() || Array.from(text).length > 2000) throw new Error('Invalid request text');
        function id(value) { var result = Number(value); if (!Number.isSafeInteger(result) || result < 1) throw new Error('Invalid selection'); return result; }
        return {operation: operation, company_id: id(values.company), site_id: id(values.site),
            record_id: operation.endsWith('.update') ? id(values.record) : null,
            source_document_id: operation === 'expense.pending.create' && values.sourceDocument ? id(values.sourceDocument) : null,
            request_text: text};
    }
    function suggestionPatch(request, suggestion, values) {
        var envelope = ['operation', 'company_id', 'site_id', 'record_id', 'source_document_id'];
        var keys = envelope.concat(['fields', 'questions', 'missing_fields']);
        function object(value) { return value !== null && typeof value === 'object' && !Array.isArray(value); }
        if (!object(suggestion) || keys.some(function (key) { return !own(suggestion, key); }) ||
            Object.keys(suggestion).some(function (key) { return keys.indexOf(key) < 0; }) ||
            envelope.some(function (key) { return suggestion[key] !== request[key]; }) || !own(suggestionFields, request.operation)) throw new Error('Invalid suggestion envelope');
        var mapping = suggestionFields[request.operation], fields = suggestion.fields;
        if (!object(fields) || Object.keys(fields).some(function (key) { return !own(mapping, key) || (fields[key] !== null && typeof fields[key] !== 'string'); }) ||
            !Array.isArray(suggestion.questions) || suggestion.questions.length > 5 || suggestion.questions.some(function (value) { return typeof value !== 'string' || Array.from(value).length > 200; }) ||
            !Array.isArray(suggestion.missing_fields) || suggestion.missing_fields.length > Object.keys(mapping).length || suggestion.missing_fields.some(function (key) { return typeof key !== 'string' || !own(mapping, key); })) throw new Error('Invalid suggestion fields');
        var patch = {};
        Object.keys(fields).forEach(function (key) {
            var value = fields[key], input = mapping[key];
            if (value !== null && value !== '' && values[input] === '') patch[input] = value;
        });
        return patch;
    }
    var exported = { createGuard: createGuard, reportParams: reportParams, proposalInput: proposalInput, suggestionInput: suggestionInput, suggestionPatch: suggestionPatch };
    if (typeof module !== 'undefined' && module.exports) module.exports = exported;
    if (!root.document) return;
    var host = root.document.getElementById('assistant-workspace');
    if (!host) return;
    var doc = root.document, base = host.dataset.base, options = JSON.parse(host.dataset.options || '{}');
    var guard = createGuard(), suggestionGuard = createGuard(), proposal = null, cursor = null, settings = {}, mutationBusy = false, suggestionBusy = false, refreshQueued = false;
    var formIds = ['operation', 'record', 'title', 'detail', 'due', 'report-date', 'work-today', 'work-tomorrow', 'expense-description', 'amount', 'expense-date', 'account', 'payment', 'source-document', 'category', 'request-text'];
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
    function suggestionsEnabled() { return settings.mutations_enabled === true && settings.suggestions_enabled === true; }
    function syncControls() {
        // Status and request completions share these controls; neither may override another in-flight gate.
        ['company', 'site', 'check-kind', 'check-interval'].concat(formIds).forEach(function (id) { el(id).disabled = mutationBusy; });
        host.querySelectorAll('button').forEach(function (b) {
            var action = b.dataset.action;
            b.disabled = mutationBusy || (suggestionBusy && ['report', 'next', 'recover'].indexOf(action) < 0) ||
                (action === 'activate-check' && !settings.checks_enabled) ||
                (['propose', 'confirm', 'cancel'].indexOf(action) >= 0 && !settings.mutations_enabled) ||
                (action === 'suggest' && !suggestionsEnabled());
        });
        el('write-fields').disabled = mutationBusy || !settings.mutations_enabled;
        el('request-text').disabled = mutationBusy || !suggestionsEnabled();
        el('suggestions-off').hidden = suggestionsEnabled();
        el('approve').disabled = mutationBusy || suggestionBusy || !settings.mutations_enabled;
        var confirm = host.querySelector('[data-action=confirm]');
        confirm.disabled = mutationBusy || suggestionBusy || !proposal || !el('approve').checked || !settings.mutations_enabled;
    }
    function setMutationBusy(value) { mutationBusy = value; syncControls(); }
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
    function invalidateSuggestion() {
        suggestionGuard.change(); el('suggestion-result').replaceChildren();
        if (suggestionBusy) el('suggestion-result').appendChild(node('p', tr('입력이 바뀌어 이전 제안을 무시합니다. 다시 요청하거나 직접 입력하세요.')));
    }
    function formChanged() { invalidateSuggestion(); resetPreview(); }
    function scopeChanged() {
        guard.change(); cursor = null; formChanged(); el('report').replaceChildren(); host.querySelector('[data-action=next]').hidden = true;
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
            row.appendChild(node('strong', check.site_name + ' · ' + tr(check.label)));
            if (check.description) row.appendChild(node('p', tr(check.description)));
            row.appendChild(node('p', (check.enabled ? tr('실행 중') : tr('꺼짐')) + ' · ' + check.interval_hours + 'h'));
            if (check.last_run_at) row.appendChild(node('p', tr('마지막 실행') + ': ' + check.last_run_at));
            if (check.next_run_at) row.appendChild(node('p', tr('다음 실행') + ': ' + check.next_run_at));
            if (check.result) {
                row.appendChild(node('p', tr('현재 조회') + ': ' + check.result.count + ' · ' + check.result.as_of));
                if (check.result.message && check.result.message !== check.description) row.appendChild(node('p', tr(check.result.message)));
                if (check.result.due_at) row.appendChild(node('p', tr('현장 보고 마감') + ': ' + check.result.due_at));
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
        var ticket = guard.begin('status'); if (!ticket) { refreshQueued = true; return; }
        try {
            var result = await api('/status?company_id=' + encodeURIComponent(el('company').value || ''));
            if (!guard.valid(ticket)) return;
            settings = result;
            syncCheckKind();
            el('mutations-off').hidden = !!settings.mutations_enabled;
            if (!suggestionsEnabled() && suggestionBusy) invalidateSuggestion();
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
            syncControls();
        } catch (error) { if (guard.valid(ticket)) message(error.message, true); }
        finally { guard.end(ticket); if (!guard.valid(ticket) || refreshQueued) { refreshQueued = false; refresh(); } }
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
        invalidateSuggestion();
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
        invalidateSuggestion(); el('approve').checked = false;
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
        if (mutationBusy || suggestionBusy) return;
        if (['propose', 'confirm', 'cancel'].indexOf(action) >= 0 && !settings.mutations_enabled) return;
        var payload, path;
        if (action === 'propose') {
            invalidateSuggestion();
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
    function formValues() {
        var values = {};
        ['company', 'site'].concat(formIds).forEach(function (id) { values[id] = String(el(id).value); });
        return values;
    }
    async function suggest() {
        if (mutationBusy || suggestionBusy || !suggestionsEnabled()) return;
        var values = formValues(), request;
        try {
            request = suggestionInput(values.operation, {company: values.company, site: values.site, record: values.record,
                sourceDocument: values['source-document'], requestText: values['request-text']});
        } catch (_) {
            el('suggestion-result').replaceChildren(node('p', tr('회사·현장·수정할 기록을 선택하고 요청을 1~2,000자로 입력하세요.'))); return;
        }
        var ticket = suggestionGuard.begin('suggest'); if (!ticket) return;
        var snapshot = JSON.stringify(values);
        suggestionBusy = true; el('approve').checked = false; syncControls();
        el('suggestion-result').replaceChildren(node('p', tr('입력할 내용을 제안하고 있습니다.')));
        try {
            var result = await api('/suggestions', request);
            if (!suggestionGuard.valid(ticket) || snapshot !== JSON.stringify(formValues()) || !suggestionsEnabled()) {
                el('suggestion-result').replaceChildren(node('p', tr('입력이 바뀌어 이전 제안을 무시합니다. 다시 요청하거나 직접 입력하세요.'))); return;
            }
            if (result.success !== true) throw new Error('Invalid suggestion response');
            var patch = suggestionPatch(request, result.suggestion, values), changed = false;
            Object.keys(patch).forEach(function (id) {
                var input = el(id);
                if (input.value !== '' || input.disabled || input.readOnly) return;
                input.value = patch[id]; changed = changed || input.value !== '';
            });
            if (changed) resetPreview();
            el('suggestion-result').replaceChildren(node('p', changed ? tr('빈 항목만 제안으로 채웠습니다. 내용을 검토하고 변경안을 직접 만드세요.') : tr('채울 수 있는 빈 항목이 없습니다. 확인 질문을 검토하거나 직접 입력하세요.')));
            var questions = node('ul');
            result.suggestion.questions.forEach(function (question) { questions.appendChild(node('li', question)); });
            var labels = {title:'제목 / 계획 작업 내용',detail:'내용',due:'기한','report-date':'보고 날짜','work-today':'오늘 작업','work-tomorrow':'내일 작업',
                'expense-description':'경비 설명',amount:'총액 (USD · 세금 포함)','expense-date':'경비 날짜',account:'계정과목',payment:'결제 구분',category:'문서 분류'};
            var missing = result.suggestion.missing_fields.filter(function (field) { return el(suggestionFields[request.operation][field]).value === ''; });
            if (missing.length) questions.appendChild(node('li', tr('직접 확인할 필수 항목') + ': ' + missing.map(function (field) { return tr(labels[suggestionFields[request.operation][field]]); }).join(', ')));
            if (questions.children.length) el('suggestion-result').appendChild(questions);
        } catch (_) {
            // Provider failures and malformed payloads must not disclose raw model text/errors.
            if (suggestionGuard.valid(ticket)) el('suggestion-result').replaceChildren(node('p', tr('제안을 가져오지 못했습니다. 다시 요청하거나 직접 입력하세요.')));
        } finally {
            suggestionGuard.end(ticket); suggestionBusy = false; syncControls(); refresh();
        }
    }
    function syncOperation() {
        host.querySelectorAll('[data-operations]').forEach(function (field) { field.hidden = field.dataset.operations.split(' ').indexOf(el('operation').value) < 0; });
    }
    function syncCheckKind() {
        var kind = el('check-kind').value, preferred = el('check-interval').value;
        var allowed = (settings.check_intervals || {})[kind] || (kind === 'missing_trade_reports' ? [1] : [1, 6, 24]);
        select('check-interval', allowed.slice().sort(function (a, b) { return b - a; }).map(function (hours) { return { value: String(hours), label: hours + 'h' }; }), 'value', 'label', preferred);
        var option = Array.from(el('check-kind').children).find(function (item) { return item.value === kind; });
        el('check-meaning').textContent = tr((settings.check_descriptions || {})[kind] || (option && option.dataset.description) || '');
    }
    select('company', options.companies || [], 'id', 'name', options.default_company_id);
    select('dataset', (options.datasets || []).map(function (d) { return {key:d.key,label:tr(d.label)}; }), 'key', 'label'); sites(); exportLink();
    el('company').addEventListener('change', function () { sites(); scopeChanged(); });
    el('site').addEventListener('change', scopeChanged);
    ['dataset', 'search'].forEach(function (id) { el(id).addEventListener('input', function () { guard.change(); cursor = null; el('report').replaceChildren(); host.querySelector('[data-action=next]').hidden = true; exportLink(); }); });
    formIds.forEach(function (id) { el(id).addEventListener('input', formChanged); el(id).addEventListener('change', formChanged); });
    el('operation').addEventListener('change', syncOperation);
    syncOperation();
    el('check-kind').addEventListener('change', syncCheckKind);
    syncCheckKind(); syncControls();
    el('approve').addEventListener('change', syncControls);
    ['pagehide', 'popstate'].forEach(function (name) { root.addEventListener(name, function () { invalidateSuggestion(); el('approve').checked = false; syncControls(); }); });
    el('change-panel').addEventListener('toggle', function () { if (!el('change-panel').open) formChanged(); });
    host.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-action]'); if (!button || button.disabled) return;
        var action = button.dataset.action;
        if (action === 'report' || action === 'next') report(action === 'next'); else if (action === 'recover') recover(); else if (action === 'suggest') suggest(); else mutate(action, button);
    });
    root.ErpAssistantWorkspace = { siteId: function () { return Number(el('site').value) || null; } };
    refresh();
    if (savedId()) recover();
})(typeof window !== 'undefined' ? window : globalThis);
