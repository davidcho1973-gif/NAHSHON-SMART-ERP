/**
 * 계약 BOQ → 현장 기록 → 사람이 확인한 수량 → 기성 초안.
 * 원본 청구 수량과 실제 확인 수량이 섞이면 근거 없는 금액이 청구되므로,
 * 이 화면은 두 사실을 분리해 보여주고 계산은 ClaimEvidenceService에 맡긴다.
 */
(function (global) {
  'use strict';

  var state = { contractId: null, ledger: null, lineId: null, filter: 'all', packet: null, request: 0 };
  var kinds = { source_claim: '원본 청구 · 미확인', actual: '실제 작업 보고', forecast: '예상 작업' };
  var stages = { installed: '시공 완료', fabrication: '제작', stored: '보관 자재', installation: '설치' };
  var statuses = { pending: '검토 대기', verified: '수량 확인', rejected: '반려', draft: '계약 조건 검토 중', accepted: '계약 조건 확정' };
  function ui() { return global.AdminUI; }
  function esc(v) { return ui().esc(v === undefined || v === null ? '' : String(v)); }
  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (r) {
      if (!r) throw new Error('서버 응답이 없습니다. 다시 시도해 주세요.');
      return r;
    });
  }
  function requireSuccess(r) {
    if (r.success === false) throw new Error(r.error || firstError(r.errors) || '요청을 처리하지 못했습니다.');
    return r;
  }
  function firstError(errors) { return errors && Object.values(errors).map(function (x) { return Array.isArray(x) ? x.join(' ') : x; }).join(' '); }
  function fail(e) { ui().toast(e.message || '요청을 처리하지 못했습니다.', 'error'); }
  function money(v, precision) {
    if (v === undefined || v === null) return '—';
    var currency = (state.ledger && state.ledger.contract.currency) || 'USD';
    try { return new Intl.NumberFormat('ko-KR', { style: 'currency', currency: currency, maximumFractionDigits: precision || 2 }).format(Number(v)); }
    catch (e) { return currency + ' ' + Number(v).toLocaleString('en-US', { maximumFractionDigits: precision || 2 }); }
  }
  function qty(v, unit) { return (v === undefined || v === null ? '—' : Number(v).toLocaleString('en-US', { maximumFractionDigits: 4 })) + (unit ? ' ' + unit : ''); }
  function today() { if (state.ledger && state.ledger.contract.today) return state.ledger.contract.today; var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function button(text, action, kind) { return ui().rowButton(text, 'window.AdminClaimEvidence.' + action, kind); }
  function hint(text) { return '<div style="font-size:12px;color:var(--text-secondary);line-height:1.6;margin-top:5px;overflow-wrap:anywhere">' + esc(text) + '</div>'; }
  function badge(status) { return ui().badge(statuses[status] || status, status === 'accepted' || status === 'verified' ? 'ok' : status === 'rejected' ? 'danger' : 'warn'); }
  function lineById(id) { return ((state.ledger && state.ledger.lines) || []).find(function (l) { return Number(l.id) === Number(id); }); }
  function recordById(id) { return ((state.ledger && state.ledger.records) || []).find(function (r) { return Number(r.id) === Number(id); }); }
  function manage() { return state.ledger && state.ledger.canManage; }
  function docOptions() {
    return sourceOptions().filter(function (s) { return String(s.value).indexOf('document:') === 0; })
      .map(function (s) { return { value: String(s.value).split(':')[1], label: s.label }; });
  }
  function sourceOptions() { return (state.ledger && state.ledger.sourceOptions) || []; }
  function sourceName(e) {
    var key = e.type + ':' + e.id;
    var s = sourceOptions().find(function (o) { return String(o.value) === key; });
    return s ? s.label : ({ document: '문서', intake: '현장 접수', photo: '공정 사진' }[e.type] || e.type) + ' #' + e.id;
  }
  function sourceLink(e) {
    if (e.url) {
      try {
        var url = new URL(e.url, global.location.href);
        if (url.origin === global.location.origin && /^https?:$/.test(url.protocol)) {
          var link = ' <a href="' + esc(url.href) + '" target="_blank" rel="noopener" style="color:var(--brand-primary);white-space:nowrap">' + (e.type === 'photo' ? '사진 보기' : '원문 열기') + ' ↗</a>';
          if (e.type === 'photo' && e.originalFilePath) {
            url.searchParams.set('original', '1');
            link += ' <a href="' + esc(url.href) + '" target="_blank" rel="noopener" style="color:var(--brand-primary);white-space:nowrap">업로드 원본 ↗</a>';
          }
          return link;
        }
      } catch (ignore) { /* A malformed source URL must never become an executable link. */ }
    }
    return e.type === 'document' ? ' ' + button('원문 열기', 'openDocument(' + Number(e.id) + ')') : '';
  }
  function evidenceHtml(items) {
    if (!items || !items.length) return ui().badge('연결된 증빙 없음', 'warn');
    return items.map(function (e) { return '<div style="margin-bottom:6px">' + esc(e.title || sourceName(e)) + sourceLink(e) + hint([e.locator, e.note].filter(Boolean).join(' · ')) + '</div>'; }).join('');
  }
  function reviewHistory(r) {
    var history = r.reviewHistory || [];
    if (!history.length) return '';
    return '<details style="margin-top:6px"><summary style="cursor:pointer;font-size:12px">검토 이력 ' + history.length + '건</summary>' + history.map(function (h) {
      return hint(({ verify: '확인', reject: '반려', reopen: '검토 재개' }[h.action] || h.action) + ' · 사용자 #' + h.by + ' · ' + h.at + ' · ' + h.note + (h.verifiedQty !== null && h.verifiedQty !== undefined ? ' · 확인수량 ' + qty(h.verifiedQty) : ''));
    }).join('') + '</details>';
  }
  function sourceReference(value) {
    return value ? '<details style="margin-top:6px"><summary style="cursor:pointer;font-size:12px">원본 식별 정보</summary>' + hint(value) + '</details>' : '';
  }
  function paint(html) {
    if (global._currentView && global._currentView !== 'claim-evidence-admin') return;
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }
  function refresh() {
    if (!state.contractId) return Promise.resolve();
    var token = ++state.request;
    return call('api_getClaimEvidence', [state.contractId]).then(requireSuccess).then(function (r) {
      if (token !== state.request) return;
      state.ledger = r;
      draw();
    });
  }
  function save(method, payload, message) {
    return call(method, [payload]).then(function (r) {
      if (r.success === false) {
        // Nested evidence validation has no matching form field; surface it instead of hiding it.
        return { success: false, error: r.error || firstError(r.errors) || '저장하지 못했습니다.' };
      }
      global.apiCache = {};
      ui().toast(message);
      return refresh().then(function () { return { success: true }; });
    });
  }
  function summary() {
    var s = state.ledger.summary || {};
    var cells = [
      ['원문 항목 계산합 (행별 반올림)', money(s.sourceClaimAmount), '가져온 항목수량 × 단가의 계산합 · 원본 요약 청구액과 구분'],
      ['확인된 작업 금액', money(s.verifiedAmount), '확정 계약 조건과 검토된 실제 작업 기준'],
      ['초안 편입 가능 금액', money(s.availableAmount), '기존 회차 배정분 제외 · 선급금/유보금 별도'],
      ['검토 대기 기록', (s.unverifiedCount || 0) + '건', '예상 작업과 원본 청구는 확인 실적에 미포함']
    ];
    return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:18px">' + cells.map(function (c) {
      return '<div style="padding:15px;border:1px solid var(--border-default);border-radius:12px;background:var(--bg-surface)"><div style="font-size:12px;color:var(--text-secondary)">' + esc(c[0]) + '</div><div style="font-size:22px;font-weight:700;margin:6px 0">' + esc(c[1]) + '</div>' + hint(c[2]) + '</div>';
    }).join('') + '</div>';
  }
  function sourceImports() {
    var imports = state.ledger.sourceImports || [];
    if (!imports.length) return '';
    return imports.map(function (s) {
      var totals = s.totals || {};
      var basis = /forecast/i.test(s.periodBasis || '') ? 'Forecast · 예상 포함' : s.periodBasis || '작성 기준 미확인';
      var values = [['금회 시공 청구', totals.current_claim], ['선급금 청구', totals.advance], ['유보금 공제', totals.retention], ['원본 순청구액', totals.current_due]];
      return '<section aria-label="원본 청구서 요약" style="padding:16px;border:1px solid var(--border-default);border-radius:12px;margin-bottom:16px;background:var(--bg-surface)">' +
        '<div style="display:flex;gap:10px;justify-content:space-between;align-items:center;flex-wrap:wrap"><strong>원본 청구서에 적힌 금액</strong><span>' + ui().badge('원본 기록 · 실적 미확인', 'warn') + ' ' + ui().badge(basis, 'warn') + '</span></div>' +
        hint((s.periodStart || '') + ' ~ ' + (s.periodEnd || '') + ' · 작성일 ' + (s.applicationDate || '미확인') + ' · 가져온 항목 ' + (s.rowCount || 0) + '건') +
        '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin:14px 0">' + values.map(function (entry) {
          return '<div><div style="font-size:12px;color:var(--text-secondary)">' + esc(entry[0]) + '</div><strong style="font-size:18px">' + esc(money(entry[1])) + '</strong></div>';
        }).join('') + '</div>' +
        hint('원본 계산 구조: 금회 시공 청구 + 선급금 청구 − 유보금 공제 = 원본 순청구액') +
        (totals.rounding_adjustment !== undefined ? hint('원본 요약에 기록된 금액 조정: ' + money(totals.rounding_adjustment) + ' · 항목별 반올림 계산합과 구분합니다.') : '') +
        hint(s.notice || '원본 청구 내용만 가져온 자료입니다. 현장 실적·원청 승인·입금이 확인된 금액이 아닙니다.') +
        '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">' +
        (s.workbookDocumentId ? button('원본 청구서', 'openDocument(' + Number(s.workbookDocumentId) + ')') : '') +
        (s.drawingDocumentId ? button('원본 마크업 도면', 'openDocument(' + Number(s.drawingDocumentId) + ')') : '') + '</div>' +
        hint('가져온 사람 #' + (s.importedBy || '미기록') + ' · ' + (s.importedAt || '시각 미기록')) + '</section>';
    }).join('');
  }
  function issuePanel(lineId) {
    var issues = (state.ledger.issues || []).filter(function (x) { return !lineId || Number(x.lineId) === Number(lineId); });
    if (!issues.length) return '';
    return '<details style="border:1px solid var(--border-default);border-radius:10px;padding:12px;margin-bottom:16px"' + (lineId ? ' open' : '') + '><summary style="cursor:pointer;font-weight:600">확인할 사항 ' + issues.length + '건</summary><ul style="padding-left:22px;line-height:1.8">' + issues.map(function (x) {
      var line = lineById(x.lineId);
      return '<li>' + (line ? 'BOQ ' + esc(line.lineNo) + ' · ' : '') + esc(x.message) + '</li>';
    }).join('') + '</ul></details>';
  }
  function ledgerView() {
    var u = ui();
    var rows = state.ledger.lines || [];
    if (state.filter === 'issues') rows = rows.filter(function (r) { return (state.ledger.issues || []).some(function (i) { return Number(i.lineId) === Number(r.id); }); });
    if (state.filter === 'available') rows = rows.filter(function (r) { return Number(r.availableAmount) > 0; });
    var actions = manage() ? u.primaryButton('계약 항목 등록', 'window.AdminClaimEvidence.editLine()', 'plus') + button('원본 청구 가져오기', 'importSource()') + button('확인 근거로 초안 만들기', 'draft()') : '';
    return button('← 기성·수금 원장', 'backToBilling()') + '<div style="height:12px"></div>' +
      u.pageHeader('기성 근거 대장 — ' + state.ledger.contract.title, '원본 청구와 확인 실적을 구분하고, 계약 항목별로 작업 위치·수량·증빙을 연결합니다.', actions) +
      sourceImports() + summary() + issuePanel() +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px" role="group" aria-label="계약 항목 필터">' +
      button((state.filter === 'all' ? '✓ ' : '') + '전체 항목', "filter('all')") +
      button((state.filter === 'issues' ? '✓ ' : '') + '확인할 사항', "filter('issues')") +
      button((state.filter === 'available' ? '✓ ' : '') + '초안 편입 가능', "filter('available')") + button('새로고침', 'reload()') + '</div>' +
      u.table({ id: 'ce-lines', rows: rows, searchPlaceholder: 'BOQ 번호 · 항목명 · 단위 검색', emptyText: '계약 항목이 없습니다. 계약 내역을 등록하거나 원본 청구 자료를 가져오세요. 가져온 자료는 검토 전까지 미확인 상태입니다.', columns: [
        { key: 'lineNo', label: 'BOQ / 계약 항목', width: '250px', render: function (r) { return button('BOQ ' + r.lineNo, 'openLine(' + Number(r.id) + ')') + '<div title="' + esc(r.description) + '" style="margin-top:6px;font-weight:600;min-width:170px;max-width:275px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">' + esc(r.description) + '</div>' + hint(r.recognitionBasis === 'milestone' ? '단계별 인정 · 상세에서 단계별 수량 확인' : '수량 기준'); } },
        { key: 'contractQty', label: '계약 조건', render: function (r) { return esc(qty(r.contractQty, r.unit)) + hint('단가 ' + money(r.unitPrice, 4)) + badge(r.status); } },
        { key: 'sourceClaimQty', label: '원본 청구수량', align: 'right', render: function (r) { return esc(qty(r.sourceClaimQty, r.unit)) + hint('원본 주장 · 미확인'); } },
        { key: 'verifiedQty', label: '확인 실적수량', align: 'right', render: function (r) { return r.recognitionBasis === 'milestone' ? hint('단계별 상세 확인') : esc(qty(r.verifiedQty, r.unit)); } },
        { key: 'unverifiedQty', label: '미확인 / 예상', align: 'right', render: function (r) { return (r.recognitionBasis === 'milestone' ? hint('단계별 상세 확인') : esc(qty(r.unverifiedQty, r.unit)) + hint('예상 ' + qty(r.forecastQty, r.unit))); } },
        { key: 'availableAmount', label: '초안 편입 가능액', align: 'right', render: function (r) { return '<strong>' + esc(money(r.availableAmount)) + '</strong>'; } },
        { key: 'action', label: '', render: function (r) { return button('근거 보기', 'openLine(' + Number(r.id) + ')'); } }
      ] });
  }
  function recordsTable(records, packetMode) {
    return ui().table({ id: packetMode ? 'ce-packet-records' : 'ce-records', rows: records, searchPlaceholder: '위치 · 작업일 · 단계 · 근거 검색', emptyText: '연결된 작업 기록이 없습니다. 현장 기록과 증빙을 연결하면 확인 대상에 나타납니다.', columns: [
      { key: 'workDate', label: '종류 / 작업일', render: function (r) { return '<div style="min-width:145px"><strong>' + esc(kinds[r.recordKind] || r.recordKind) + '</strong>' + hint(r.workDate || '작업일 미확인') + '</div>'; } },
      { key: 'location', label: '위치 / 단계', render: function (r) { return '<div style="min-width:140px;max-width:210px">' + esc(r.location || '위치 미확인') + hint((r.recordKind === 'source_claim' ? '원문 단계: ' : '') + (stages[r.stage] || r.stage)) + '</div>'; } },
      { key: 'reportedQty', label: '보고 / 확인 수량', align: 'right', render: function (r) { var l = lineById(r.lineId); return '<div style="min-width:115px">' + esc(qty(r.reportedQty, l && l.unit)) + hint('확인 ' + qty(r.verifiedQty, l && l.unit)) + '</div>'; } },
      { key: 'evidence', label: '연결된 증빙 / 원문 위치', width: '250px', render: function (r) { return '<div style="min-width:220px;max-width:320px;overflow-wrap:anywhere">' + evidenceHtml(r.evidence) + sourceReference(r.sourceRef) + '</div>'; } },
      { key: 'status', label: '검토 / 회차 배정', render: function (r) { return '<div style="min-width:200px;max-width:270px">' + badge(r.status) + hint(r.reviewNote || r.notes || '') + hint('회차 배정 수량 ' + qty(r.allocatedQty)) + hint([r.createdBy ? '기록자 #' + r.createdBy : '', r.createdAt].filter(Boolean).join(' · ')) + hint([r.reviewedBy ? '확인자 #' + r.reviewedBy : '', r.reviewedAt].filter(Boolean).join(' · ')) + reviewHistory(r) + '</div>'; } },
      { key: 'action', label: '', render: function (r) {
        if (packetMode || !manage()) return '';
        if (Number(r.allocatedQty) > 0) return hint('회차 배정됨');
        if (r.status === 'verified' || r.status === 'rejected') return button('검토 재개', "review(" + Number(r.id) + ",'reopen')");
        var b = r.recordKind === 'source_claim' ? '' : button('기록 수정', 'editRecord(' + Number(r.lineId) + ',' + Number(r.id) + ')') + ' ';
        if (r.recordKind === 'actual' && r.status !== 'rejected') b += button('수량 확인', "review(" + Number(r.id) + ",'verify')") + ' ';
        if (r.status !== 'rejected') b += button('반려', "review(" + Number(r.id) + ",'reject')", 'danger');
        return b;
      } }
    ] });
  }
  function detailView() {
    var l = lineById(state.lineId);
    if (!l) { state.lineId = null; return ledgerView(); }
    var u = ui();
    var title = l.description.split(' · ')[0];
    var lineRecords = (state.ledger.records || []).filter(function (r) { return Number(r.lineId) === Number(l.id); });
    var sourceNotes = lineRecords.filter(function (r) { return r.recordKind === 'source_claim' && r.notes; }).map(function (r) {
      return '<div style="padding:12px;border:1px solid var(--border-default);border-radius:10px;margin-bottom:12px"><strong style="font-size:12px">원본 청구 기록의 확인 사항</strong>' + hint(r.notes) + '</div>';
    }).join('');
    var weights = Object.keys(l.stageWeights || {}).map(function (s) { return (stages[s] || s) + ' ' + l.stageWeights[s] + '%'; }).join(' · ');
    return button('← 전체 계약 항목', 'openLine(null)') + '<div style="height:12px"></div>' +
      u.pageHeader('BOQ ' + l.lineNo + ' — ' + title, '보고수량은 현장의 주장, 확인수량은 검토 결과입니다. 원본 청구와 예상 작업을 실제 실적으로 바꾸지 않습니다.', manage() ? button('계약 조건 검토', 'editLine(' + Number(l.id) + ')') + u.primaryButton('현장 기록 연결', 'window.AdminClaimEvidence.editRecord(' + Number(l.id) + ')', 'plus') : '') +
      '<div style="padding:16px;border:1px solid var(--border-default);border-radius:12px;margin-bottom:16px">' + badge(l.status) +
      '<details style="margin-top:10px"><summary style="cursor:pointer;font-size:12px">계약 항목 전체 규격 보기</summary>' + hint(l.description) + '</details>' +
      '<p>계약수량 <strong>' + esc(qty(l.contractQty, l.unit)) + '</strong> · 단가 <strong>' + esc(money(l.unitPrice, 4)) + '</strong> · 계약금액 <strong>' + esc(money(l.contractAmount)) + '</strong></p>' +
      hint(l.recognitionBasis === 'milestone' ? '단계별 인정 조건: ' + weights : '시공 확인수량 × 계약단가로 산정합니다.') +
      hint('계약 근거: ' + [l.sourceDocumentId ? '문서 #' + l.sourceDocumentId : '', l.sourceLocator].filter(Boolean).join(' · ')) + (l.sourceDocumentId ? button(l.status === 'accepted' ? '계약 원문 열기' : '기준 문서 열기', 'openDocument(' + Number(l.sourceDocumentId) + ')') : '') + sourceReference(l.sourceRef) + hint(l.acceptanceNote || '계약 인정 조건을 확인해 주세요.') +
      hint([l.acceptedBy ? '확정자 ' + l.acceptedBy : '', l.acceptedAt].filter(Boolean).join(' · ')) + '</div>' +
      sourceNotes + issuePanel(l.id) + hint('표를 좌우로 이동하면 연결된 증빙과 검토 이력을 확인할 수 있습니다.') + '<div style="height:8px"></div>' + recordsTable(lineRecords);
  }
  function draw() {
    if (!state.ledger) return;
    paint(state.lineId ? detailView() : ledgerView());
    ui().bindSearch(state.lineId ? 'ce-records' : 'ce-lines');
  }
  function field(name, label, value, extra) { return Object.assign({ name: name, label: label, value: value === undefined || value === null ? '' : value }, extra || {}); }
  function editLine(id) {
    if (!manage()) return;
    var l = lineById(id) || {};
    var weights = l.stageWeights || {};
    ui().formModal({ title: id ? 'BOQ ' + l.lineNo + ' 계약 조건 검토' : '계약 BOQ 항목 등록', subtitle: '확정 계약 내역과 인정 조건을 확인하고 기록하세요. 확정 전에는 청구 초안에 반영되지 않습니다.', saveLabel: '계약 조건 저장', fields: [
      field('lineNo', 'BOQ 번호', l.lineNo, { required: true, group: '계약 항목' }),
      field('description', '항목명 / 규격', l.description, { required: true, group: '계약 항목' }),
      field('unit', '단위', l.unit, { required: true, group: '계약 항목' }),
      field('contractQty', '계약수량', l.contractQty, { required: true, group: '계약 항목' }),
      field('unitPrice', '계약단가', l.unitPrice, { required: true, group: '계약 항목' }),
      field('recognitionBasis', '기성 인정 방식', l.recognitionBasis || 'quantity', { required: true, type: 'select', group: '인정 조건', options: [{ value: 'quantity', label: '수량 기준' }, { value: 'milestone', label: '단계별 인정' }] }),
      field('fabricationWeight', '제작 인정 비중 (%)', weights.fabrication || '', { group: '인정 조건', hint: '단계별 인정일 때 입력합니다. 계약에 적힌 비중을 사용하세요.' }),
      field('storedWeight', '보관 자재 인정 비중 (%)', weights.stored || '', { group: '인정 조건' }),
      field('installationWeight', '설치 인정 비중 (%)', weights.installation || weights.installed || '', { group: '인정 조건', hint: '단계별 비중의 합계는 100%여야 합니다.' }),
      field('sourceDocumentId', '계약 근거 문서', l.sourceDocumentId, { type: 'select', options: docOptions(), group: '확정 근거' }),
      field('sourceRef', '계약 근거 식별', l.sourceRef, { group: '확정 근거', hint: '계약 번호·합의 문서 번호 등 확인 가능한 원본 식별을 적으세요.' }),
      field('sourceLocator', '문서 내 위치 / 개정번호', l.sourceLocator, { group: '확정 근거', hint: '예: 계약 내역서 3쪽, BOQ 15 / Rev.2' }),
      field('status', '계약 조건 상태', l.status || 'draft', { type: 'select', required: true, group: '확정 근거', options: [{ value: 'draft', label: '검토 중 — 청구에 사용하지 않음' }, { value: 'accepted', label: '계약 조건을 확인하고 확정' }] }),
      field('acceptanceNote', '확인 내용 / 판단 사유', l.acceptanceNote, { type: 'textarea', colSpan: 2, group: '확정 근거', hint: '확정할 때 어떤 계약의 수량·단가·인정 조건을 확인했는지 남겨 주세요.' })
    ], onSave: function (v) {
      if (id) v.id = id;
      v.projectContractId = state.contractId;
      v.stageWeights = {};
      if (v.recognitionBasis === 'milestone') {
        [['fabrication', 'fabricationWeight'], ['stored', 'storedWeight'], ['installation', 'installationWeight']].forEach(function (entry) { if (Number(v[entry[1]]) > 0) v.stageWeights[entry[0]] = Number(v[entry[1]]); });
      }
      delete v.fabricationWeight; delete v.storedWeight; delete v.installationWeight;
      if (v.status === 'accepted') {
        var errors = {};
        if (!v.acceptanceNote.trim()) errors.acceptanceNote = '확정 근거와 판단 사유를 남겨 주세요.';
        if (!v.sourceDocumentId) errors.sourceDocumentId = '계약 조건을 확인한 원본 문서를 선택해 주세요.';
        if (!v.sourceLocator.trim()) errors.sourceLocator = '계약 문서의 쪽수·항목·개정번호를 남겨 주세요.';
        if (Object.keys(errors).length) return { success: false, errors: errors };
      }
      return save('api_saveClaimLine', v, '계약 조건을 저장했습니다.');
    } });
  }
  function editRecord(lineId, id) {
    if (!manage()) return;
    var l = lineById(lineId), r = recordById(id) || {};
    if (!l || r.recordKind === 'source_claim') return;
    var sourceRef = r.sourceRef || 'field-ui:' + (global.crypto && global.crypto.randomUUID ? global.crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2));
    var availableStages = l.recognitionBasis === 'milestone' ? Object.keys(l.stageWeights || {}).map(function (s) { return { value: s, label: (stages[s] || s) + ' (' + l.stageWeights[s] + '%)' }; }) : [{ value: 'installed', label: '시공 완료' }];
    if (!availableStages.length) { ui().toast('먼저 계약 조건에서 단계별 인정 비중을 등록해 주세요.', 'error'); return; }
    var fields = [
      field('recordKind', '기록 구분', r.recordKind || 'actual', { required: true, type: 'select', options: [{ value: 'actual', label: '실제 작업 보고 — 검토 후 확인' }, { value: 'forecast', label: '예상 작업 — 기성 확인수량 제외' }].filter(function (kind) { return !id || kind.value === r.recordKind; }), hint: id ? '기록 구분은 변경하지 않습니다. 실제 작업은 별도 기록으로 연결하세요.' : '' }),
      field('workDate', '작업일', r.workDate || today(), { type: 'date', required: true, hint: '미래 날짜의 작업은 예상 작업으로 기록하세요.' }),
      field('location', '작업 위치', r.location, { required: true, hint: '구역·층·그리드 등 다시 찾아갈 수 있는 위치' }),
      field('stage', '작업 단계', r.stage || availableStages[0].value, { type: 'select', required: true, options: availableStages }),
      field('reportedQty', '이번 기록의 수량 (' + l.unit + ')', r.reportedQty, { required: true, hint: '이 기록의 작업분만 입력하세요. 누계나 같은 작업을 중복 입력하지 마세요.' }),
      field('notes', '작업 내용 / 확인할 사항', r.notes, { type: 'textarea', colSpan: 2 })
    ];
    var n = Math.max(2, (r.evidence || []).length);
    for (var i = 0; i < n; i++) {
      var e = (r.evidence || [])[i] || {};
      var opts = sourceOptions().slice();
      if (e.id && !opts.some(function (s) { return String(s.value) === e.type + ':' + e.id; })) opts.push({ value: e.type + ':' + e.id, label: sourceName(e) });
      fields.push(field('evidenceSource' + i, '근거 ' + (i + 1), e.id ? e.type + ':' + e.id : '', { type: 'select', options: opts, group: '기존 ERP 자료 연결', hint: i === 0 ? '문서함·현장 접수·공정 사진에서 같은 현장의 자료를 연결합니다.' : '' }));
      fields.push(field('evidenceLocator' + i, '근거 ' + (i + 1) + '의 정확한 위치', e.locator, { group: '기존 ERP 자료 연결', hint: '문서 쪽수, 도면 번호/개정번호, 사진 속 해당 위치' }));
    }
    ui().formModal({ title: 'BOQ ' + l.lineNo + ' — 현장 기록 연결', subtitle: '자료 연결은 수량 확인과 별개입니다. 사람이 검토하기 전까지 기성 초안에 반영되지 않습니다.', saveLabel: '검토 대기로 저장', fields: fields, onSave: function (v) {
      if (v.recordKind === 'actual' && v.workDate > today()) return { success: false, errors: { workDate: '미래 날짜는 예상 작업으로 기록해 주세요.' } };
      if (id) v.id = id;
      v.lineId = lineId; v.sourceRef = sourceRef; v.evidence = [];
      for (var j = 0; j < n; j++) {
        var selection = v['evidenceSource' + j];
        if (selection) {
          if (!v['evidenceLocator' + j].trim()) { var errs = {}; errs['evidenceLocator' + j] = '연결 근거에서 확인할 정확한 위치를 남겨 주세요.'; return { success: false, errors: errs }; }
          var parts = selection.split(':');
          v.evidence.push({ type: parts[0], id: Number(parts[1]), locator: v['evidenceLocator' + j], note: ((r.evidence || [])[j] || {}).note || '' });
        }
        delete v['evidenceSource' + j]; delete v['evidenceLocator' + j];
      }
      return save('api_saveClaimRecord', v, '현장 기록을 검토 대기로 저장했습니다.');
    } });
  }
  function review(id, action) {
    if (!manage()) return;
    var r = recordById(id); if (!r) return;
    var fields = [];
    if (action === 'verify') fields.push(field('verifiedQty', '실제로 확인한 수량', r.reportedQty, { required: true, hint: '보고수량 ' + qty(r.reportedQty) + ' 이하로 입력하세요. 일부만 확인했다면 차이 사유를 남기세요.' }));
    fields.push(field('reviewNote', action === 'verify' ? '검토 내용 / 수량 차이 사유' : '사유', '', { required: true, type: 'textarea', colSpan: 2 }));
    ui().formModal({ title: action === 'verify' ? '근거를 검토하고 수량 확인' : action === 'reopen' ? '검토 재개' : '작업 기록 반려', subtitle: action === 'verify' ? '연결된 원본과 작업 위치·날짜·단계를 대조한 결과를 기록합니다. 원청 승인이나 입금 처리는 아닙니다.' : '변경 사유와 담당자가 기록됩니다. 회차에 배정된 기록은 수정할 수 없습니다.', saveLabel: action === 'verify' ? '확인수량 기록' : action === 'reopen' ? '검토 대기로 되돌리기' : '반려 기록', fields: fields, onSave: function (v) { v.id = id; v.action = action; return save('api_reviewClaimRecord', v, '검토 결과를 기록했습니다.'); } });
  }
  function draft() {
    if (!manage()) return;
    ui().formModal({ title: '확인 근거로 기성 초안 만들기', subtitle: '계약 조건이 확정된 실제 작업 중, 검토 완료되고 다른 회차에 배정되지 않은 수량만 편입합니다. 예상 작업·미확인 원본 청구·선급금은 자동 편입하지 않습니다.', saveLabel: '초안 생성', fields: [field('periodEnd', '기성 마감일', today(), { type: 'date', required: true, hint: '마감일 이후 작업은 이번 초안에서 제외합니다.' })], onSave: function (v) {
      return call('api_draftClaimEvidence', [state.contractId, v.periodEnd]).then(function (r) {
        if (r.success === false) return { success: false, error: r.error || firstError(r.errors) };
        global.apiCache = {};
        ui().toast('근거를 연결한 기성 초안을 만들었습니다. 기성·수금 원장에서 검토한 뒤 제출하세요.');
        return refresh().then(function () { return { success: true }; });
      });
    } });
  }
  function importSource() {
    if (!manage()) return;
    ui().formModal({ title: '원본 청구 자료 가져오기', subtitle: '분석된 청구 JSON과 원본 문서를 연결합니다. 가져온 항목은 계약 조건 검토 중, 원본 청구수량은 미확인 상태로 저장됩니다. 기성 회차나 수금은 생성하지 않습니다.', saveLabel: '미확인 자료로 가져오기', fields: [
      field('file', '분석된 청구 자료 (JSON)', '', { type: 'file', required: true, accept: '.json,application/json', colSpan: 2 }),
      field('workbookDocId', '원본 청구서 문서', '', { type: 'select', required: true, options: docOptions(), hint: '먼저 기존 문서함에 원본 청구서를 등록해 주세요.' }),
      field('drawingDocId', '마크업 도면 문서', '', { type: 'select', required: true, options: docOptions() })
    ], onSave: function (v) {
      if (v.file.size > 5000000) return { success: false, errors: { file: '5MB 이하의 청구 분석 자료를 선택해 주세요.' } };
      return v.file.text().then(function (text) {
        var payload; try { payload = JSON.parse(text); } catch (e) { return { success: false, errors: { file: 'JSON 형식의 분석 자료를 선택해 주세요.' } }; }
        return call('api_importClaimSource', [state.contractId, payload, Number(v.workbookDocId), Number(v.drawingDocId)]).then(function (r) {
          if (r.success === false) return { success: false, error: r.error || firstError(r.errors) };
          global.apiCache = {}; ui().toast('원본 청구 자료를 미확인 상태로 가져왔습니다.');
          return refresh().then(function () { return { success: true }; });
        });
      });
    } });
  }
  function open(contractId) {
    state.contractId = Number(contractId); state.ledger = null; state.lineId = null; state.packet = null; state.filter = 'all';
    if (global.goToView) global.goToView('claim-evidence-admin'); else render();
  }
  function render() {
    if (!state.contractId) { paint(ui().pageHeader('기성 근거 대장', '먼저 기성·수금 원장에서 수주 계약을 선택하세요.', button('기성·수금 원장 열기', 'backToBilling()'))); return ''; }
    paint('<div role="status" style="padding:32px;text-align:center">계약 항목과 근거를 불러오는 중…</div>');
    refresh().catch(function (e) { paint(ui().notice(e.message || '기성 근거를 불러오지 못했습니다.', 'danger') + button('다시 시도', 'reload()') + ' ' + button('기성·수금 원장', 'backToBilling()')); });
    return '';
  }
  function backToBilling() {
    ++state.request;
    if (global.AdminBilling) global.AdminBilling._pendingContractId = state.contractId;
    if (global.goToView) global.goToView('billing-admin');
  }
  function openDocument(id) {
    var target = global.open('', '_blank');
    if (!target) { ui().toast('원문을 열려면 이 사이트의 팝업을 허용해 주세요.', 'error'); return; }
    target.opener = null;
    target.document.title = '원본 문서 확인';
    target.document.body.textContent = '원본 문서를 불러오는 중…';
    return call('api_getSourceDocument', [id]).then(requireSuccess).then(function (r) {
      var url = new URL(r.previewUrl, global.location.href);
      if (url.origin !== global.location.origin || !/^https?:$/.test(url.protocol)) throw new Error('원본 문서 주소를 확인할 수 없습니다.');
      target.location.replace(url.href);
    }).catch(function (e) { target.close(); fail(e); });
  }
  function packetHtml(p) {
    var app = p.application || {}, c = p.contract || {};
    var currency = app.currency || c.currency || 'USD';
    function val(value, precision) { return value === undefined || value === null ? '—' : currency + ' ' + Number(value).toLocaleString('en-US', { maximumFractionDigits: precision || 2 }); }
    var rows = (p.allocations || []).map(function (allocation) {
      var snap = allocation.snapshot || {}, l = snap.line || {}, r = snap.record || {};
      var weightDescription = Object.keys(l.stageWeights || {}).map(function (stage) { return (stages[stage] || stage) + ' ' + qty(l.stageWeights[stage]) + '%'; }).join(' · ');
      function evidenceRows(entries) { return (entries || []).map(function (e) {
        var anchor = '';
        if (e.url) {
          try {
            var evidenceUrl = new URL(e.url, global.location.href);
            if (evidenceUrl.origin === global.location.origin && /^https?:$/.test(evidenceUrl.protocol)) {
              if (e.type === 'photo' && e.originalFilePath) evidenceUrl.searchParams.set('original', '1');
              anchor = ' <a href="' + esc(evidenceUrl.href) + '" target="_blank" rel="noopener">' + (e.type === 'photo' && e.originalFilePath ? '사진 원본 열기' : '근거 열기') + '</a>';
            }
          } catch (ignore) { /* Preserve the recorded reference even when no valid link is available. */ }
        }
        var digest = e.verifiedFileSha256 || (e.type === 'photo' ? e.originalSha256 : e.sha256);
        return '<li><strong>' + esc(e.title || sourceName(e)) + '</strong> · ' + esc(e.locator) +
          (e.revision ? ' · 개정 ' + esc(e.revision) : '') + anchor + (e.note ? '<br>' + esc(e.note) : '') +
          (e.type === 'photo' ? '<br><small>' + esc(e.originalFilePath ? '업로드 원본 보존' : '업로드 원본 보존 정보 없음') + '</small>' : '') +
          (digest ? '<br><small>확인한 파일 식별값 ' + esc(digest) + '</small>' : '') +
          (e.fileCheckAt ? '<br><small>파일 확인 시각 ' + esc(e.fileCheckAt) + '</small>' : '') + '</li>';
      }).join(''); }
      var evidence = evidenceRows(snap.evidence);
      var contractEvidence = evidenceRows(snap.contractEvidence);
      return '<article><h2>BOQ ' + esc(l.lineNo) + ' · ' + esc(l.description) + '</h2>' +
        '<dl><dt>계약 조건</dt><dd>' + esc(qty(l.contractQty, l.unit)) + ' × ' + esc(val(l.unitPrice, 4)) + '</dd>' +
        '<dt>인정 방식</dt><dd>' + esc(l.recognitionBasis === 'milestone' ? '단계별 인정 / ' + weightDescription : '시공수량 기준') + '</dd>' +
        '<dt>이번 작업의 인정 단계</dt><dd>' + esc(stages[r.stage] || r.stage) + (l.recognitionBasis === 'milestone' ? ' · 계약 비중 ' + esc(qty((l.stageWeights || {})[r.stage])) + '%' : '') + '</dd>' +
        '<dt>작업일 / 위치</dt><dd>' + esc(r.workDate) + ' / ' + esc(r.location) + '</dd>' +
        '<dt>보고수량 → 확인수량</dt><dd>' + esc(qty(r.reportedQty, l.unit)) + ' → ' + esc(qty(r.verifiedQty, l.unit)) + '</dd>' +
        '<dt>이번 회차 배정</dt><dd>' + esc(qty(allocation.quantity, l.unit)) + ' / ' + esc(val(allocation.amount)) + '</dd>' +
        '<dt>계약 확인 근거</dt><dd>' + esc([l.sourceDocumentId ? '문서 #' + l.sourceDocumentId : '', l.sourceLocator, l.acceptanceNote].filter(Boolean).join(' · ')) + '</dd>' +
        '<dt>계약 조건 확정</dt><dd>' + esc(l.acceptedBy ? '사용자 #' + l.acceptedBy : '담당자 미기록') + ' / ' + esc(l.acceptedAt || '시각 미기록') + '</dd>' +
        '<dt>현장 기록</dt><dd>' + esc(r.createdBy ? '사용자 #' + r.createdBy : '담당자 미기록') + ' / ' + esc(r.createdAt || '시각 미기록') + '</dd>' +
        '<dt>수량 검토</dt><dd>' + esc(snap.reviewNote || r.reviewNote) + '</dd>' +
        '<dt>담당자 / 확인 시각</dt><dd>' + esc('사용자 #' + (snap.reviewedBy || r.reviewedBy || '미기록')) + ' / ' + esc(snap.reviewedAt || r.reviewedAt || '미기록') + '</dd>' +
        '<dt>회차 보관 시각</dt><dd>' + esc(snap.capturedAt) + '</dd></dl><h3>계약 조건의 원문 근거</h3><ul>' + (contractEvidence || '<li>별도 계약 원문 사본 정보 없음 · 위 계약 문서 참조를 확인하세요.</li>') + '</ul><h3>연결된 작업 근거</h3><ul>' + (evidence || '<li>연결된 증빙 없음</li>') + '</ul></article>';
    }).join('');
    return '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>기성 근거 묶음 — 회차 ' + esc(app.applicationNo) + '</title><style>body{font:14px/1.65 system-ui,sans-serif;color:#172333;max-width:1050px;margin:auto;padding:28px}h1{font-size:26px}h2{font-size:19px}h3{font-size:14px}small{overflow-wrap:anywhere;color:#596675}article{border-top:1px solid #b9c4d0;padding:18px 0;break-inside:avoid}dl{display:grid;grid-template-columns:180px 1fr;gap:7px 16px}dt{color:#536273}dd{margin:0;overflow-wrap:anywhere}li{margin-bottom:8px}button{padding:10px 18px;border-radius:8px;background:#162e45;color:white;border:0;cursor:pointer}.notice{padding:12px;border:1px solid #b9c4d0;border-radius:8px}.totals{font-size:16px;font-weight:600}@media(max-width:550px){body{padding:16px}dl{grid-template-columns:1fr;gap:3px}dd{margin-bottom:10px}}@media print{button{display:none}body{padding:0}article{break-inside:auto}h2{break-after:avoid}}</style></head><body><button type="button" onclick="window.print()">인쇄 / PDF 저장</button><h1>기성 근거 묶음 · 회차 #' + esc(app.applicationNo) + '</h1><p>' + esc(c.title) + ' · ' + esc(app.periodStart) + ' ~ ' + esc(app.periodEnd) + '</p><p class="notice">' + esc(p.immutable ? '제출 시점에 보관된 근거입니다. 아래 수량·조건·검토 기록은 회차에 저장된 내용을 보여줍니다.' : '검토 중인 초안의 근거입니다. 아직 제출되지 않았으며 초안을 다시 생성하면 갱신될 수 있습니다.') + '</p><p class="totals">근거 배정 합계 ' + esc(val(p.totals && p.totals.amount)) + ' · 금회 시공 ' + esc(val(app.thisPeriodAmount)) + ' · 유보 잔액 ' + esc(val(app.retainageHeld)) + ' · 순청구액 ' + esc(val(app.amountDue)) + '</p><p>내부 수량 확인과 원청 승인·입금은 별도입니다. 원문 파일은 ERP의 원본 자료에서 확인하세요.</p>' + (rows || '<p>이 회차에는 BOQ별 근거 배정이 없습니다.</p>') + '</body></html>';
  }
  function packet(id, asJson) {
    var target = asJson ? null : global.open('', '_blank');
    if (!asJson && !target) { ui().toast('근거 묶음을 열려면 이 사이트의 팝업을 허용해 주세요.', 'error'); return Promise.resolve(); }
    if (target) { target.opener = null; target.document.body.textContent = '회차에 보관된 근거를 불러오는 중…'; }
    return call('api_getClaimPacket', [id]).then(requireSuccess).then(function (r) {
      state.packet = r.packet || r;
      if (asJson) { download(state.packet, 'claim-evidence-' + Number(id) + '.json'); ui().toast('회차에 보관된 근거 묶음을 내려받았습니다.'); }
      else { target.document.open(); target.document.write(packetHtml(state.packet)); target.document.close(); }
    }).catch(function (e) { if (target) target.close(); fail(e); });
  }
  function download(data, filename) {
    var url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json;charset=utf-8' }));
    var a = document.createElement('a'); a.href = url; a.download = filename; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }
  global.AdminClaimEvidence = {
    render: render, open: open, backToBilling: backToBilling,
    reload: function () { return refresh().catch(fail); },
    openLine: function (id) { state.lineId = id; draw(); },
    filter: function (value) { state.filter = value; draw(); },
    editLine: editLine, editRecord: editRecord, review: review, draft: draft, importSource: importSource,
    packet: packet, openDocument: openDocument, _state: state
  };
})(window);
