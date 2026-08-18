/**
 * 원청 계약 · 서류 — Filament ProjectContractResource 를 SPA 로 옮긴 것.
 *
 * 계약 화면이 답해야 하는 질문은 "지금 유효한가" 와 "다음에 뭘 해야 하나" 다.
 * 종료일이 지났는데 상태가 유효로 남아 있거나 갱신 통보 기한을 놓치는 것이 실제 손해라서,
 * 목록 맨 앞에 만료·갱신 임박·다음 조치를 세운다.
 *
 * 서류는 계약을 눌러 들어가는 별도 화면으로 둔다. 계약 하나에 서류가 십수 건 붙으므로
 * 한 표에 섞으면 둘 다 못 읽는다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = {
    rows: [], options: null, canManage: false,
    filters: { status: '', siteId: '', direction: '' },
    view: 'list', contractId: null, contractTitle: '', docs: [], docsCanManage: false,
  };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  function money(v, cur) {
    if (v === null || v === undefined || v === '') return '';
    return (cur === 'KRW' ? '₩' : '$') + Number(v).toLocaleString('en-US', { maximumFractionDigits: 0 });
  }

  function alertsCell(r) {
    var u = ui();
    if (!r.alerts || !r.alerts.length) return '';
    return r.alerts.map(function (a) {
      return u.badge(a.label + ' ' + a.date, a.state === 'expired' ? 'danger' : 'warn');
    }).join(' ');
  }

  function filterBar() {
    var u = ui();
    var o = state.options || {};
    var f = state.filters;
    var sel = function (id, label, opts, val, blank) {
      return '<div><label for="' + id + '" style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px">' +
        u.esc(label) + '</label><select id="' + id + '" onchange="window.AdminContracts.applyFilters()" ' +
        'style="padding:7px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);' +
        'color:var(--text-primary);font-size:13px;min-width:170px">' +
        '<option value="">' + u.esc(blank) + '</option>' +
        (opts || []).map(function (x) {
          return '<option value="' + u.esc(x.value) + '"' + (String(x.value) === String(val) ? ' selected' : '') + '>' + u.esc(x.label) + '</option>';
        }).join('') + '</select></div>';
    };
    return '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:var(--bg-surface);' +
      'border:1px solid var(--border-default);border-radius:12px">' +
      sel('ct-status', '상태', o.statuses, f.status, '전체') +
      sel('ct-direction', '계약 방향', o.directions, f.direction, '전체') +
      sel('ct-site', '현장', o.sites, f.siteId, '전체') + '</div>';
  }

  function listView() {
    var u = ui();
    var rows = state.rows;
    var urgent = rows.filter(function (r) {
      return (r.alerts || []).some(function (a) { return a.state === 'expired'; });
    }).length;

    var notes = [rows.length + '건'];
    if (urgent) notes.push(urgent + '건 조치 필요');
    var total = rows.reduce(function (s, r) { return s + (r.currentAmount || 0); }, 0);
    if (total) notes.push('총 ' + money(total, 'USD'));

    return u.pageHeader(
      '원청 계약 · 서류',
      '계약이 지금 유효한지, 다음에 뭘 해야 하는지 봅니다. — ' + notes.join(' · '),
      state.canManage ? u.primaryButton('계약 등록', 'window.AdminContracts.openForm()', 'plus') : ''
    ) + filterBar() + u.table({
      id: 'ct-tbl',
      searchPlaceholder: '계약명 · 계약번호 · 상대방 검색',
      emptyText: '조건에 맞는 계약이 없습니다.',
      columns: [
        {
          key: 'title', label: '계약', width: '250px',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.title) + '</div>' +
              '<div style="font-size:11px;color:var(--text-tertiary)">' +
              u.esc(r.contractNumber || r.internalReference || '') +
              (r.project ? ' · ' + u.esc(r.project) : '') + '</div>';
          },
        },
        { key: 'counterparty', label: '상대방', width: '150px' },
        {
          key: 'directionLabel', label: '방향', width: '130px',
          render: function (r) {
            return u.badge(String(r.directionLabel || '').split(' (')[0], r.direction === 'receivable' ? 'ok' : 'warn');
          },
        },
        { key: 'site', label: '현장', width: '100px' },
        {
          key: 'currentAmount', label: '현재 금액', align: 'right', width: '120px',
          render: function (r) { return u.esc(money(r.currentAmount, r.currency)); },
        },
        {
          // 계약 대비 발주 누계 — 발주(payable) 계약에서만 뜻이 있다. 이 숫자가 없으면
          // 계약에 발주를 걸어도 "이 계약으로 얼마나 샀나" 를 아무도 모른다.
          key: 'poTotal', label: '발주 누계', align: 'right', width: '120px',
          render: function (r) {
            if (r.direction === 'receivable' || !r.poCount) return '<span style="color:var(--text-tertiary)">—</span>';
            var over = r.currentAmount !== null && r.poTotal > r.currentAmount;
            return '<div style="font-size:12px;' + (over ? 'color:#ef4444;font-weight:700' : '') + '">'
              + u.esc(money(r.poTotal, r.currency)) + (over ? ' ⚠' : '') + '</div>'
              + '<div style="font-size:10px;color:var(--text-tertiary)">' + r.poCount + '건</div>';
          },
        },
        {
          key: 'endsOn', label: '기간', width: '190px',
          render: function (r) {
            var period = (r.startsOn || '?') + ' ~ ' + (r.endsOn || '?');
            var al = alertsCell(r);
            return '<div style="font-size:12px">' + u.esc(period) + '</div>' + (al ? '<div style="margin-top:3px">' + al + '</div>' : '');
          },
        },
        {
          key: 'statusLabel', label: '상태', width: '110px',
          render: function (r) {
            var kind = r.status === 'active' ? 'ok'
              : (r.status === 'terminated' || r.status === 'expired') ? 'danger'
                : r.status === 'draft' ? 'muted' : 'warn';
            return u.badge(String(r.statusLabel || '').split(' / ')[1] || r.statusLabel, kind);
          },
        },
        {
          key: 'act', label: '', align: 'right', width: '190px',
          render: function (r) {
            var docs = u.rowButton('서류 ' + (r.documentCount || 0), 'window.AdminContracts.openDocs(' + r.id + ')');
            if (!state.canManage) return docs;
            return docs + ' ' + u.rowButton('수정', 'window.AdminContracts.openForm(' + r.id + ')') + ' ' +
              u.rowButton('삭제', 'window.AdminContracts.remove(' + r.id + ')', 'danger');
          },
        },
      ],
      rows: rows,
    });
  }

  function docsView() {
    var u = ui();
    var rows = state.docs;
    var expired = rows.filter(function (d) { return d.expired && d.isCurrent; }).length;

    var notes = [rows.length + '건'];
    if (expired) notes.push(expired + '건 만료');

    return '<button type="button" onclick="window.AdminContracts.backToList()" ' +
      'style="background:none;border:none;color:var(--text-secondary);font-size:13px;cursor:pointer;padding:0;margin-bottom:10px">' +
      '← 계약 목록</button>' +
      u.pageHeader(
        '서류 — ' + state.contractTitle,
        '같은 종류의 새 서류를 올리면 이전 것은 자동으로 "대체됨" 이 되고 최신본만 현재로 남습니다. — ' + notes.join(' · '),
        state.docsCanManage ? u.primaryButton('서류 올리기', 'window.AdminContracts.openDocForm()', 'upload-simple') : ''
      ) + u.table({
        id: 'cd-tbl',
        searchPlaceholder: '서류명 · 종류 검색',
        emptyText: '아직 올린 서류가 없습니다.',
        columns: [
          {
            key: 'title', label: '서류', width: '260px',
            render: function (d) {
              return '<div style="font-weight:600">' + u.esc(d.title) + '</div>' +
                '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(d.fileName || '') +
                (d.uploadedBy ? ' · ' + u.esc(d.uploadedBy) : '') + '</div>';
            },
          },
          {
            key: 'documentTypeLabel', label: '종류', width: '190px',
            render: function (d) { return u.esc(String(d.documentTypeLabel || '').split(' / ')[1] || d.documentTypeLabel); },
          },
          {
            key: 'version', label: '버전', align: 'right', width: '70px',
            render: function (d) {
              return d.isCurrent
                ? '<span style="font-weight:700">v' + d.version + '</span>'
                : '<span style="color:var(--text-tertiary)">v' + d.version + '</span>';
            },
          },
          {
            key: 'expiresOn', label: '만료', width: '130px',
            render: function (d) {
              if (!d.expiresOn) return '';
              return d.expired ? u.badge('만료 ' + d.expiresOn, 'danger') : u.esc(d.expiresOn);
            },
          },
          {
            key: 'statusLabel', label: '상태', width: '120px',
            render: function (d) {
              var kind = d.status === 'approved' ? 'ok' : d.status === 'rejected' ? 'danger'
                : d.status === 'superseded' ? 'muted' : 'warn';
              return u.badge(String(d.statusLabel || '').split(' / ')[1] || d.statusLabel, kind) +
                (d.isConfidential ? ' ' + u.badge('대외비', 'warn') : '');
            },
          },
          {
            key: 'act', label: '', align: 'right', width: '150px',
            render: function (d) {
              var open = '<a href="' + u.esc(d.downloadUrl) + '" target="_blank" rel="noopener" ' +
                'style="padding:5px 10px;border-radius:6px;border:1px solid var(--border-default);color:var(--text-secondary);' +
                'font-size:12px;text-decoration:none">열기</a>';
              if (!state.docsCanManage) return open;
              return open + ' ' + u.rowButton('삭제', 'window.AdminContracts.removeDoc(' + d.id + ')', 'danger');
            },
          },
        ],
        rows: rows,
      });
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function draw() {
    if (state.view === 'docs') {
      paint(docsView());
      ui().bindSearch('cd-tbl');
    } else {
      paint(listView());
      ui().bindSearch('ct-tbl');
    }
  }

  function reload() {
    return call('api_getContracts', [state.filters]).then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '계약을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.rows = res.rows || [];
      state.canManage = !!res.canManage;
      state.view = 'list';
      draw();
    });
  }

  function loadOptions() {
    if (state.options) return Promise.resolve(state.options);
    return call('api_getContractOptions').then(function (res) {
      if (res.success === false) throw new Error(res.error || '선택지를 불러오지 못했습니다.');
      state.options = res;
      return res;
    });
  }

  function applyFilters() {
    var g = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
    state.filters = { status: g('ct-status'), direction: g('ct-direction'), siteId: g('ct-site') };
    reload();
  }

  function openForm(id) {
    var u = ui();
    var r = id ? state.rows.filter(function (x) { return x.id === id; })[0] : null;

    loadOptions().then(function (o) {
      u.formModal({
        title: r ? '계약 수정 — ' + r.title : '계약 등록',
        subtitle: '계약명 · 자사 · 방향만 있으면 등록됩니다. 나머지는 나중에 채워도 됩니다.',
        saveLabel: r ? '수정' : '등록',
        fields: [
          { name: 'title', label: '계약명', required: true, group: '① 무슨 계약인가', value: r ? r.title : '', colSpan: 2 },
          { name: 'direction', label: '계약 방향', type: 'select', required: true, group: '① 무슨 계약인가',
            options: o.directions, value: r ? r.direction : 'receivable',
            hint: '받는 계약(수주)인지 주는 계약(발주)인지' },
          { name: 'contractType', label: '계약 종류', type: 'select', group: '① 무슨 계약인가',
            options: o.contractTypes, value: r ? r.contractType : '' },
          { name: 'contractNumber', label: '계약 번호', group: '① 무슨 계약인가', value: r ? r.contractNumber : '' },
          { name: 'internalReference', label: '내부 관리번호', group: '① 무슨 계약인가', value: r ? r.internalReference : '' },
          { name: 'status', label: '상태', type: 'select', required: true, group: '① 무슨 계약인가',
            options: o.statuses, value: r ? r.status : 'draft' },
          { name: 'riskLevel', label: '위험도', type: 'select', group: '① 무슨 계약인가',
            options: o.risks, value: r ? r.riskLevel : '' },

          { name: 'companyId', label: '자사 (계약 주체)', type: 'select', required: true, group: '② 누구와',
            options: o.companies, value: r ? r.companyId : '' },
          { name: 'counterpartyId', label: '상대방 회사', type: 'select', group: '② 누구와',
            options: o.companies, value: r ? r.counterpartyId : '' },
          { name: 'counterpartyRole', label: '상대방 역할', type: 'select', group: '② 누구와',
            options: o.counterpartyRoles, value: r ? r.counterpartyRole : '' },
          { name: 'managerEmployeeId', label: '담당자', type: 'select', group: '② 누구와',
            options: o.managers, value: r ? r.managerEmployeeId : '' },
          { name: 'contactName', label: '상대방 담당자', group: '② 누구와', value: r ? r.contactName : '' },
          { name: 'contactEmail', label: '상대방 이메일', type: 'email', group: '② 누구와', value: r ? r.contactEmail : '' },
          { name: 'contactPhone', label: '상대방 전화', type: 'tel', group: '② 누구와', value: r ? r.contactPhone : '' },
          { name: 'siteId', label: '현장', type: 'select', group: '② 누구와', options: o.sites, value: r ? r.siteId : '' },
          { name: 'projectId', label: '프로젝트', type: 'select', group: '② 누구와', options: o.projects, value: r ? r.projectId : '', colSpan: 2 },

          { name: 'originalAmount', label: '계약 금액', group: '③ 금액', value: r && r.originalAmount !== null ? r.originalAmount : '' },
          { name: 'approvedChangeAmount', label: '승인된 변경 금액', group: '③ 금액',
            value: r && r.approvedChangeAmount !== null ? r.approvedChangeAmount : '' },
          { name: 'currentAmount', label: '현재 금액', group: '③ 금액',
            value: r && r.currentAmount !== null ? r.currentAmount : '',
            hint: '비우면 계약 금액 + 변경 금액으로 자동 계산합니다.' },
          { name: 'currency', label: '통화', group: '③ 금액', value: r ? r.currency : 'USD' },
          { name: 'retainagePercent', label: '유보율 (%)', group: '③ 금액',
            value: r && r.retainagePercent !== null ? r.retainagePercent : '' },
          { name: 'paymentTerms', label: '지급 조건', group: '③ 금액', value: r ? r.paymentTerms : '',
            hint: 'Net 30 처럼' },

          { name: 'executedOn', label: '서명일', type: 'date', group: '④ 일정', value: r ? r.executedOn : '' },
          { name: 'effectiveOn', label: '효력 발생일', type: 'date', group: '④ 일정', value: r ? r.effectiveOn : '' },
          { name: 'startsOn', label: '계약 시작일', type: 'date', group: '④ 일정', value: r ? r.startsOn : '' },
          { name: 'endsOn', label: '계약 종료일', type: 'date', group: '④ 일정', value: r ? r.endsOn : '' },
          { name: 'noticeToProceedOn', label: 'NTP 발행일', type: 'date', group: '④ 일정', value: r ? r.noticeToProceedOn : '' },
          { name: 'renewalNoticeDays', label: '갱신 통보 기한 (일)', type: 'number', group: '④ 일정',
            value: r ? r.renewalNoticeDays : '',
            hint: '종료일에서 이만큼 앞이 통보 기한입니다. 지나면 목록에 경고가 뜹니다.' },
          { name: 'nextActionOn', label: '다음 조치일', type: 'date', group: '④ 일정', value: r ? r.nextActionOn : '' },
          { name: 'nextActionNotes', label: '다음 조치 내용', group: '④ 일정', value: r ? r.nextActionNotes : '' },

          { name: 'insuranceRequired', label: 'COI / 보험 필수', type: 'checkbox', group: '⑤ 요건',
            value: r ? r.insuranceRequired : false },
          { name: 'bondRequired', label: 'Bond / 보증서 필수', type: 'checkbox', group: '⑤ 요건',
            value: r ? r.bondRequired : false },
          { name: 'prevailingWageRequired', label: 'Prevailing Wage', type: 'checkbox', group: '⑤ 요건',
            value: r ? r.prevailingWageRequired : false },
          { name: 'certifiedPayrollRequired', label: 'Certified Payroll', type: 'checkbox', group: '⑤ 요건',
            value: r ? r.certifiedPayrollRequired : false },
          { name: 'scopeOfWork', label: '공사 범위', type: 'textarea', colSpan: 2, group: '⑤ 요건', value: r ? r.scopeOfWork : '' },
          { name: 'notes', label: '비고', type: 'textarea', colSpan: 2, group: '⑤ 요건', value: r ? r.notes : '' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          return call('api_saveContract', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(r ? '계약을 수정했습니다.' : '계약을 등록했습니다.');
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function remove(id) {
    var u = ui();
    var r = state.rows.filter(function (x) { return x.id === id; })[0];
    u.confirmDanger({
      title: '계약을 삭제할까요?',
      body: (r ? r.title : '이 계약') + ' 을(를) 삭제합니다. 되돌릴 수 없습니다. ' +
        '끝난 계약은 삭제 대신 상태를 "완료" 나 "해지" 로 두세요.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteContract', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('계약을 삭제했습니다.');
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  // ── 서류 ──────────────────────────────────────────────────────────────

  function openDocs(contractId) {
    state.contractId = contractId;
    var r = state.rows.filter(function (x) { return x.id === contractId; })[0];
    state.contractTitle = r ? r.title : '';
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    loadDocs();
  }

  function loadDocs() {
    return call('api_getContractDocuments', [state.contractId]).then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '서류를 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.docs = res.rows || [];
      state.docsCanManage = !!res.canManage;
      state.contractTitle = res.contractTitle || state.contractTitle;
      state.view = 'docs';
      draw();
    });
  }

  function backToList() { state.view = 'list'; draw(); }

  function openDocForm() {
    var u = ui();
    loadOptions().then(function (o) {
      u.formModal({
        title: '서류 올리기',
        subtitle: '같은 종류의 서류가 이미 있으면 이전 것이 "대체됨" 이 되고 버전이 하나 올라갑니다.',
        saveLabel: '올리기',
        fields: [
          { name: 'file', label: '파일', type: 'file', required: true, group: '파일', colSpan: 2,
            accept: '.pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.xls,.xlsx',
            hint: 'PDF · 이미지 · 오피스 문서. 32MB 이하.' },
          { name: 'documentType', label: '서류 종류', type: 'select', required: true, group: '분류',
            options: o.documentTypes, value: 'executed_contract' },
          { name: 'status', label: '상태', type: 'select', group: '분류',
            options: o.documentStatuses, value: 'draft' },
          { name: 'title', label: '서류명', group: '분류', value: '', colSpan: 2,
            hint: '비우면 파일 이름을 씁니다.' },
          { name: 'documentNumber', label: '문서 번호', group: '분류', value: '' },
          { name: 'issuedOn', label: '발행일', type: 'date', group: '기간', value: '' },
          { name: 'effectiveOn', label: '효력 발생일', type: 'date', group: '기간', value: '' },
          { name: 'expiresOn', label: '만료일', type: 'date', group: '기간', value: '',
            hint: '보험증서처럼 끊기면 안 되는 서류는 꼭 넣으세요. 만료되면 목록에 표시됩니다.' },
          { name: 'isRequired', label: '필수 서류', type: 'checkbox', group: '표시', value: false },
          { name: 'isConfidential', label: '대외비', type: 'checkbox', group: '표시', value: false },
          { name: 'notes', label: '비고', type: 'textarea', colSpan: 2, group: '표시', value: '' },
        ],
        onSave: function (v) {
          var file = v.file;
          if (!file) return { success: false, errors: { file: '파일을 올리세요.' } };
          var meta = {};
          Object.keys(v).forEach(function (k) {
            if (k === 'file') return;
            meta[k] = typeof v[k] === 'boolean' ? (v[k] ? '1' : '0') : v[k];
          });
          return u.uploadFile('/admin-api/contracts/' + state.contractId + '/documents', file, meta)
            .then(function (res) {
              if (res.success === false) return res;
              u.toast(res.replaced
                ? '올렸습니다. 이전 서류는 v' + (res.version - 1) + ' 로 내려가고 v' + res.version + ' 이 현재본이 되었습니다.'
                : '서류를 올렸습니다.');
              return loadDocs().then(function () { return { success: true }; });
            });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function removeDoc(id) {
    var u = ui();
    var d = state.docs.filter(function (x) { return x.id === id; })[0];
    u.confirmDanger({
      title: '서류를 삭제할까요?',
      body: (d ? d.title + ' (v' + d.version + ')' : '이 서류') + ' 을(를) 삭제하고 파일도 지웁니다. 되돌릴 수 없습니다.' +
        (d && d.isCurrent ? ' 최신본이라 바로 앞 버전이 다시 현재본이 됩니다.' : ''),
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteContractDocument', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('서류를 삭제했습니다.');
        return loadDocs();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    loadOptions().then(reload).catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
        ui().esc(e.message || '계약을 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminContracts = {
    render: renderScreen,
    applyFilters: applyFilters,
    openForm: openForm,
    remove: remove,
    openDocs: openDocs,
    backToList: backToList,
    openDocForm: openDocForm,
    removeDoc: removeDoc,
    _state: state,
  };
})(window);
