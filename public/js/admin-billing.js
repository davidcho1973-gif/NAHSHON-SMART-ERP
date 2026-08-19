/**
 * 기성 청구 · 수금 — 수주 계약(receivable)의 청구 원장 SPA 화면.
 *
 * 재무 대시보드의 '기성 수금액' 카드가 직원 경비를 보여주다 제거된 근본 원인은
 * 수금 원장 부재였다. 이 화면이 기성 회차(pay application) → 수금(receipt) →
 * 미수금(AR)·유보금 잔액을 한 곳에서 답하게 한다.
 *
 * 원칙:
 *  - AdminUI 위에 조립한다 — 표·폼·모달을 새로 짜지 않는다 (AGENTS.md 규칙).
 *  - 산식은 서버(BillingCalculator)가 정본이다. 이 파일은 D·G·held·순청구를
 *    계산하지 않고, api_saveBilling / api_setBillingStatus 가 돌려주는 computed 를
 *    그대로 보여준다. 프론트에서 재계산하면 정본이 둘이 된다.
 *  - canManage 는 버튼 노출 제어일 뿐이다. 방어는 항상 서버 가드다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = {
    view: 'list',            // list | detail
    rows: [], options: null, canManage: false,
    contractId: null,
    detail: null,            // api_getBillings 응답 전체 {contract, rows, unassignedReceipts}
    expandedId: null,        // 입금 서브테이블을 펼친 회차 id
  };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  // 청구 원장은 센트 단위가 유의미하다 — 정수면 정수로, 소수가 있으면 2자리로.
  function money(v, cur) {
    if (v === null || v === undefined || v === '') return '';
    return (cur === 'KRW' ? '₩' : '$') + Number(v).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  function koLabel(label) {
    // 옵션 라벨은 "English / 한국어" 형식 — 화면엔 한국어만 (admin-contracts 관례)
    return String(label || '').split(' / ')[1] || label || '';
  }

  function cur() {
    return (state.detail && state.detail.contract && state.detail.contract.currency) || 'USD';
  }

  // 서버가 계산해 내려보낸 배지를 그리기만 한다 — soon(주황)/warn(주황)/expired(빨강)
  function alertBadges(alerts) {
    var u = ui();
    if (!alerts || !alerts.length) return '';
    return alerts.map(function (a) {
      var text = a.label + (a.date ? ' ' + a.date : '');
      return u.badge(text, a.state === 'expired' ? 'danger' : 'warn');
    }).join(' ');
  }

  function statusBadge(status, statusLabel) {
    var u = ui();
    var kind = status === 'paid' ? 'ok'
      : status === 'approved' ? 'ok'
        : status === 'submitted' ? 'warn' : 'muted';
    return u.badge(koLabel(statusLabel) || status, kind);
  }

  // 저장·제출 응답의 computed 를 사람 말로 — 프론트 재계산 없이 서버 값을 그대로 전달
  function computedSummary(computed, currency) {
    if (!computed) return '';
    return '청구 누계(G) ' + money(computed.G, currency) +
      ' · 유보 잔액 ' + money(computed.held, currency) +
      ' · 순청구액 ' + money(computed.due, currency);
  }

  function notifyAlerts(alerts) {
    var u = ui();
    (alerts || []).forEach(function (a) {
      u.toast(a.label + (a.detail ? ' — ' + a.detail : ''), 'error');
    });
  }

  // ── 화면 1: 계약 목록 ─────────────────────────────────────────────────

  function listView() {
    var u = ui();
    var rows = state.rows;

    var totalOutstanding = rows.reduce(function (s, r) { return s + (r.outstanding || 0); }, 0);
    var unassigned = rows.reduce(function (s, r) { return s + (r.unassignedCount || 0); }, 0);
    var notes = [rows.length + '건'];
    if (totalOutstanding) notes.push('미수금 ' + money(totalOutstanding, 'USD'));
    if (unassigned) notes.push('매칭 대기 입금 ' + unassigned + '건');

    return u.pageHeader(
      '기성 청구 · 수금',
      '수주 계약별로 얼마를 청구했고 얼마가 들어왔는지 봅니다. 계약을 누르면 회차·수금 원장이 열립니다. — ' + notes.join(' · '),
      ''
    ) + u.table({
      id: 'bl-tbl',
      searchPlaceholder: '계약명 · 계약번호 · 상대방 검색',
      emptyText: '수주(receivable) 계약이 없습니다. 계약 관리에서 수주 계약을 먼저 등록하세요.',
      columns: [
        {
          key: 'title', label: '계약', width: '230px',
          render: function (r) {
            return '<div onclick="window.AdminBilling.openDetail(' + r.id + ')" style="cursor:pointer">' +
              '<div style="font-weight:600">' + u.esc(r.title) + '</div>' +
              '<div style="font-size:11px;color:var(--text-tertiary)">' +
              u.esc(r.contractNumber || r.internalReference || '') +
              (r.counterparty ? ' · ' + u.esc(r.counterparty) : '') +
              (r.site ? ' · ' + u.esc(r.site) : '') + '</div></div>';
          },
        },
        {
          key: 'currentAmount', label: '계약액', align: 'right', width: '110px',
          render: function (r) { return u.esc(money(r.currentAmount, r.currency)); },
        },
        {
          key: 'cumulative', label: '청구 누계 (G)', align: 'right', width: '120px',
          render: function (r) {
            if (!r.applicationCount) return '<span style="color:var(--text-tertiary)">—</span>';
            var pct = (r.currentAmount && r.cumulative) ? Math.round(r.cumulative / r.currentAmount * 100) : null;
            return '<div>' + u.esc(money(r.cumulative, r.currency)) + '</div>' +
              (pct !== null ? '<div style="font-size:10px;color:var(--text-tertiary)">' + pct + '%</div>' : '');
          },
        },
        {
          key: 'receivedTotal', label: '수금 누계', align: 'right', width: '110px',
          render: function (r) { return r.receivedTotal ? u.esc(money(r.receivedTotal, r.currency)) : '<span style="color:var(--text-tertiary)">—</span>'; },
        },
        {
          key: 'outstanding', label: '미수금', align: 'right', width: '110px',
          render: function (r) {
            if (!r.applicationCount) return '<span style="color:var(--text-tertiary)">—</span>';
            var over = r.outstanding > 0;
            return '<span style="' + (over ? 'color:var(--status-warning);font-weight:700' : '') + '">' +
              u.esc(money(r.outstanding, r.currency)) + '</span>';
          },
        },
        {
          key: 'retainageHeld', label: '유보금', align: 'right', width: '100px',
          render: function (r) { return r.retainageHeld ? u.esc(money(r.retainageHeld, r.currency)) : '<span style="color:var(--text-tertiary)">—</span>'; },
        },
        {
          key: 'balanceToFinish', label: 'Balance to Finish', align: 'right', width: '120px',
          render: function (r) { return r.balanceToFinish === null ? '<span style="color:var(--text-tertiary)">—</span>' : u.esc(money(r.balanceToFinish, r.currency)); },
        },
        {
          key: 'latestStatus', label: '최근 회차', width: '130px',
          render: function (r) {
            if (!r.applicationCount) return '<span style="color:var(--text-tertiary)">회차 없음</span>';
            return '<div style="font-size:12px">#' + r.latestApplicationNo + ' ' + statusBadge(r.latestStatus, r.latestStatusLabel) + '</div>' +
              (r.disputedDeductions ? '<div style="margin-top:3px">' + ui().badge('분쟁 ' + money(r.disputedDeductions, r.currency), 'warn') + '</div>' : '');
          },
        },
        {
          key: 'unassignedCount', label: '매칭 대기', align: 'right', width: '90px',
          render: function (r) {
            return r.unassignedCount ? ui().badge('입금 ' + r.unassignedCount + '건', 'warn') : '<span style="color:var(--text-tertiary)">—</span>';
          },
        },
        {
          key: 'act', label: '', align: 'right', width: '90px',
          render: function (r) { return u.rowButton('원장 열기', 'window.AdminBilling.openDetail(' + r.id + ')'); },
        },
      ],
      rows: rows,
    });
  }

  // ── 화면 2: 계약 상세 (회차 · 수금 원장) ──────────────────────────────

  function summaryStrip(c) {
    var u = ui();
    var pct = (c.currentAmount && c.cumulative) ? Math.round(c.cumulative / c.currentAmount * 100) : null;
    var cell = function (label, value, sub, color) {
      return '<div style="flex:1;min-width:130px;padding:12px 14px;background:var(--bg-surface);border:1px solid var(--border-default);border-radius:12px">' +
        '<div style="font-size:11px;color:var(--text-tertiary);margin-bottom:4px">' + u.esc(label) + '</div>' +
        '<div style="font-size:17px;font-weight:700;color:' + (color || 'var(--text-primary)') + '">' + u.esc(value) + '</div>' +
        (sub ? '<div style="font-size:10px;color:var(--text-tertiary);margin-top:3px">' + u.esc(sub) + '</div>' : '') + '</div>';
    };
    return '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">' +
      cell('계약액', money(c.currentAmount, c.currency) || '—', c.retainagePercent !== null ? '유보율 ' + c.retainagePercent + '%' + (c.paymentTerms ? ' · ' + c.paymentTerms : '') : c.paymentTerms || '') +
      cell('청구 누계 (G)', money(c.cumulative, c.currency) || '—', pct !== null ? '계약 대비 ' + pct + '%' + (c.submittedPending ? ' · 제출 대기 ' + money(c.submittedPending, c.currency) : '') : (c.submittedPending ? '제출 대기 ' + money(c.submittedPending, c.currency) : '')) +
      cell('수금 누계', money(c.receivedTotal, c.currency) || '—', '', 'var(--status-success)') +
      cell('미수금 (AR)', money(c.arOutstanding, c.currency) || '—', c.disputedDeductions ? '분쟁 차감 ' + money(c.disputedDeductions, c.currency) : '', c.arOutstanding > 0 ? 'var(--status-warning)' : 'var(--text-primary)') +
      cell('유보금 잔액', money(c.retainageHeld, c.currency) || '—', '최신 확정 회차 누계 기준') +
      cell('Balance to Finish', c.balanceToFinish === null ? '—' : money(c.balanceToFinish, c.currency), '') +
      '</div>';
  }

  function approvedCell(r) {
    var u = ui();
    if (r.status === 'draft' || r.status === 'submitted') return '<span style="color:var(--text-tertiary)">—</span>';
    if (r.approvedAmount === null || r.approvedAmount === r.amountDue) {
      return u.esc(money(r.expectedAmount, cur()));
    }
    // GC 삭감 — 승인액을 앞세우고 청구 원본은 취소선으로 병기 (이력 보존의 화면 표현)
    return '<div style="font-weight:600">' + u.esc(money(r.approvedAmount, cur())) + '</div>' +
      '<div style="font-size:11px;color:var(--text-tertiary);text-decoration:line-through">' + u.esc(money(r.amountDue, cur())) + '</div>';
  }

  function actionsCell(r) {
    var u = ui();
    if (!state.canManage) {
      return r.receipts && r.receipts.length ? u.rowButton('입금 ' + r.receipts.length, 'window.AdminBilling.toggleReceipts(' + r.id + ')') : '';
    }
    var b = [];
    if (r.receipts && r.receipts.length) {
      b.push(u.rowButton('입금 ' + r.receipts.length, 'window.AdminBilling.toggleReceipts(' + r.id + ')'));
    }
    if (r.status === 'draft') {
      b.push(u.rowButton('수정', 'window.AdminBilling.openForm(' + r.id + ')'));
      b.push(u.rowButton('제출', 'window.AdminBilling.submitApp(' + r.id + ')'));
      b.push(u.rowButton('삭제', 'window.AdminBilling.removeApp(' + r.id + ')', 'danger'));
    } else if (r.status === 'submitted') {
      b.push(u.rowButton('승인 기록', 'window.AdminBilling.openApprove(' + r.id + ')'));
      if (r.isLatest) b.push(u.rowButton('회수', 'window.AdminBilling.withdrawApp(' + r.id + ')', 'danger'));
    } else if (r.status === 'approved') {
      b.push(u.rowButton('수금 입력', 'window.AdminBilling.openReceipt(' + r.id + ')'));
      if (r.outstanding !== null && r.outstanding > 0) b.push(u.rowButton('수동 종결', 'window.AdminBilling.openClose(' + r.id + ')'));
      if (r.isLatest) b.push(u.rowButton('승인 취소', 'window.AdminBilling.unapproveApp(' + r.id + ')', 'danger'));
    } else if (r.status === 'paid') {
      if (r.closedManually) b.push(u.rowButton('재개', 'window.AdminBilling.reopenApp(' + r.id + ')'));
      else b.push(u.rowButton('수금 입력', 'window.AdminBilling.openReceipt(' + r.id + ')'));
    }
    return b.join(' ');
  }

  function applicationsTable() {
    var u = ui();
    return u.table({
      id: 'bd-tbl',
      searchPlaceholder: '회차 번호 · 기간 검색',
      emptyText: '아직 기성 회차가 없습니다. "새 회차" 로 이번 달 청구를 시작하세요.',
      columns: [
        {
          key: 'applicationNo', label: '#', width: '70px',
          render: function (r) {
            return '<div style="font-weight:700">#' + r.applicationNo + '</div>' +
              '<div style="font-size:10px;color:var(--text-tertiary)">' + u.esc(koLabel(r.typeLabel)) + '</div>';
          },
        },
        {
          key: 'periodEnd', label: '기간 / 기일', width: '150px',
          render: function (r) {
            var period = (r.periodStart || '') + (r.periodStart ? ' ~ ' : '') + (r.periodEnd || '');
            return '<div style="font-size:12px">' + u.esc(period) + '</div>' +
              (r.dueOn ? '<div style="font-size:10px;color:var(--text-tertiary)">지급 기일 ' + u.esc(r.dueOn) + '</div>' : '');
          },
        },
        {
          key: 'thisPeriodAmount', label: '금회 시공 (E)', align: 'right', width: '110px',
          render: function (r) { return u.esc(money(r.thisPeriodAmount, cur())); },
        },
        {
          key: 'storedMaterialsAmount', label: '보관 자재 (F)', align: 'right', width: '110px',
          render: function (r) { return r.storedMaterialsAmount ? u.esc(money(r.storedMaterialsAmount, cur())) : '<span style="color:var(--text-tertiary)">—</span>'; },
        },
        {
          key: 'retainageHeld', label: '유보 잔액', align: 'right', width: '100px',
          render: function (r) {
            return '<div>' + u.esc(money(r.retainageHeld, cur())) + '</div>' +
              (r.retainageReleased ? '<div style="font-size:10px;color:var(--status-success)">해제 ' + u.esc(money(r.retainageReleased, cur())) + '</div>' : '');
          },
        },
        {
          key: 'amountDue', label: '순청구액', align: 'right', width: '110px',
          render: function (r) { return '<span style="font-weight:600">' + u.esc(money(r.amountDue, cur())) + '</span>'; },
        },
        { key: 'approvedAmount', label: '승인액', align: 'right', width: '110px', render: approvedCell },
        {
          key: 'receivedTotal', label: '수금액', align: 'right', width: '100px',
          render: function (r) { return r.receivedTotal ? u.esc(money(r.receivedTotal, cur())) : '<span style="color:var(--text-tertiary)">—</span>'; },
        },
        {
          key: 'outstanding', label: '잔액', align: 'right', width: '100px',
          render: function (r) {
            if (r.outstanding === null) return '<span style="color:var(--text-tertiary)">—</span>';
            var style = r.outstanding > 0 ? 'color:var(--status-warning);font-weight:700'
              : r.outstanding < 0 ? 'color:var(--status-danger);font-weight:700' : 'color:var(--status-success)';
            return '<span style="' + style + '">' + u.esc(money(r.outstanding, cur())) + '</span>';
          },
        },
        {
          key: 'status', label: '상태', width: '150px',
          render: function (r) {
            var al = alertBadges(r.alerts);
            return statusBadge(r.status, r.statusLabel) + (al ? '<div style="margin-top:4px;display:flex;gap:3px;flex-wrap:wrap">' + al + '</div>' : '');
          },
        },
        { key: 'act', label: '', align: 'right', width: '210px', render: actionsCell },
      ],
      rows: state.detail.rows,
    });
  }

  function decisionBadge(r) {
    var u = ui();
    if (!r.deductionAmount) return '';
    if (r.deductionAccepted === true) return u.badge('인정', 'ok');
    if (r.deductionAccepted === false) return u.badge('불인정 · 분쟁', 'danger');
    return u.badge('미판단', 'warn');
  }

  function receiptColumns(showAssign) {
    var u = ui();
    return [
      { key: 'receivedOn', label: '입금일', width: '100px' },
      {
        key: 'amount', label: '금액', align: 'right', width: '110px',
        render: function (r) { return '<span style="font-weight:600">' + u.esc(money(r.amount, cur())) + '</span>'; },
      },
      {
        key: 'method', label: '수단 / 번호', width: '140px',
        render: function (r) {
          return '<div style="font-size:12px">' + u.esc(koLabel(r.methodLabel)) + '</div>' +
            (r.reference ? '<div style="font-size:10px;color:var(--text-tertiary)">' + u.esc(r.reference) + '</div>' : '');
        },
      },
      {
        key: 'deductionAmount', label: '차감', align: 'right', width: '150px',
        render: function (r) {
          if (!r.deductionAmount) return '<span style="color:var(--text-tertiary)">—</span>';
          return '<div>' + u.esc(money(r.deductionAmount, cur())) +
            (r.deductionReasonLabel ? ' <span style="font-size:10px;color:var(--text-tertiary)">' + u.esc(koLabel(r.deductionReasonLabel)) + '</span>' : '') +
            '</div><div style="margin-top:3px">' + decisionBadge(r) + '</div>';
        },
      },
      {
        key: 'recordedBy', label: '기록자 / 메모', width: '150px',
        render: function (r) {
          return '<div style="font-size:12px">' + u.esc(r.recordedBy || '') + '</div>' +
            (r.memo ? '<div style="font-size:10px;color:var(--text-tertiary)">' + u.esc(r.memo) + '</div>' : '');
        },
      },
      {
        key: 'act', label: '', align: 'right', width: showAssign ? '150px' : '130px',
        render: function (r) {
          if (!state.canManage) return '';
          return u.rowButton(showAssign ? '회차 배정' : '재배정', 'window.AdminBilling.openAssign(' + r.id + ')') + ' ' +
            u.rowButton('삭제', 'window.AdminBilling.removeReceipt(' + r.id + ')', 'danger');
        },
      },
    ];
  }

  function receiptsPanel() {
    var u = ui();
    var app = (state.detail.rows || []).filter(function (r) { return r.id === state.expandedId; })[0];
    if (!app || !app.receipts || !app.receipts.length) return '';
    return '<div style="margin:14px 0">' +
      '<div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:8px">' +
      '수금 내역 — 회차 #' + app.applicationNo + ' <span style="font-weight:400;color:var(--text-tertiary)">(' + app.receipts.length + '건)</span></div>' +
      u.table({ id: 'br-tbl', search: false, emptyText: '수금 기록이 없습니다.', columns: receiptColumns(false), rows: app.receipts }) +
      '</div>';
  }

  function unassignedPanel() {
    var u = ui();
    var rows = state.detail.unassignedReceipts || [];
    if (!rows.length) return '';
    // 입금이 먼저 오고 매칭은 나중인 현실 — 여기 남아 있는 돈은 아직 어느 회차의
    // 잔액도 줄이지 못했다. 배정해야 원장이 맞는다.
    return '<div style="margin:18px 0">' +
      '<div style="font-size:13px;font-weight:700;color:var(--status-warning);margin-bottom:4px">매칭 대기 입금 (' + rows.length + '건)</div>' +
      '<div style="font-size:11px;color:var(--text-tertiary);margin-bottom:8px">회차에 배정되지 않은 입금입니다. 배정 전에는 회차 잔액에 반영되지 않습니다.</div>' +
      u.table({ id: 'bu-tbl', search: false, emptyText: '', columns: receiptColumns(true), rows: rows }) +
      '</div>';
  }

  function detailView() {
    var u = ui();
    var c = state.detail.contract;
    var actions = '';
    if (state.canManage) {
      actions = u.primaryButton('새 회차', 'window.AdminBilling.openForm()', 'plus') +
        u.rowButton('수금 입력 (미배정 포함)', 'window.AdminBilling.openReceipt()');
    }

    return '<button type="button" onclick="window.AdminBilling.backToList()" ' +
      'style="background:none;border:none;color:var(--text-secondary);font-size:13px;cursor:pointer;padding:0;margin-bottom:10px">' +
      '← 계약 목록</button>' +
      u.pageHeader(
        '기성 원장 — ' + c.title,
        (c.counterparty ? c.counterparty + ' · ' : '') + (c.contractNumber || c.internalReference || '') +
        ' · 월말에 회차를 만들어 제출하고, GC 승인·입금을 그대로 기록하면 잔액은 서버가 맞춥니다.',
        actions
      ) +
      summaryStrip(c) +
      applicationsTable() +
      receiptsPanel() +
      unassignedPanel();
  }

  // ── 그리기 · 로딩 ─────────────────────────────────────────────────────

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function draw() {
    var u = ui();
    if (state.view === 'detail' && state.detail) {
      paint(detailView());
      u.bindSearch('bd-tbl');
    } else {
      paint(listView());
      u.bindSearch('bl-tbl');
    }
  }

  function reloadList() {
    return call('api_getBillingContracts', [{}]).then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '기성 현황을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.rows = res.rows || [];
      state.canManage = !!res.canManage;
      state.view = 'list';
      draw();
    });
  }

  function reloadDetail() {
    return call('api_getBillings', [state.contractId]).then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '기성 원장을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.detail = res;
      state.canManage = !!res.canManage;
      state.view = 'detail';
      // 펼쳐 둔 회차가 재조회 후에도 남아 있으면 유지한다
      if (state.expandedId && !(res.rows || []).some(function (r) { return r.id === state.expandedId; })) {
        state.expandedId = null;
      }
      draw();
    });
  }

  function loadOptions(force) {
    if (state.options && !force) return Promise.resolve(state.options);
    return call('api_getBillingOptions').then(function (res) {
      if (res.success === false) throw new Error(res.error || '선택지를 불러오지 못했습니다.');
      state.options = res;
      return res;
    });
  }

  function openDetail(contractId) {
    state.contractId = contractId;
    state.expandedId = null;
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    reloadDetail();
  }

  function backToList() {
    state.view = 'list';
    state.detail = null;
    state.expandedId = null;
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    reloadList();
  }

  function toggleReceipts(appId) {
    state.expandedId = state.expandedId === appId ? null : appId;
    draw();
  }

  // 쓰기 성공 후 공통 마무리 — 조회 캐시를 비우고 상세를 다시 그린다.
  // gsRun 도 쓰기 성공 시 캐시를 비우지만, 규약을 화면 쪽에서도 명시해 둔다.
  function afterWrite() {
    global.apiCache = {};
    return state.view === 'detail' ? reloadDetail() : reloadList();
  }

  function findApp(id) {
    return ((state.detail && state.detail.rows) || []).filter(function (r) { return r.id === id; })[0];
  }

  function assignableAppOptions() {
    // 수금은 승인된(approved·paid) 회차에만 배정할 수 있다 — 서버 가드와 같은 목록
    return ((state.detail && state.detail.rows) || [])
      .filter(function (r) { return r.status === 'approved' || r.status === 'paid'; })
      .map(function (r) {
        var label = '#' + r.applicationNo + ' · ' + koLabel(r.typeLabel) +
          (r.outstanding !== null ? ' · 잔액 ' + money(r.outstanding, cur()) : '');
        return { value: String(r.id), label: label };
      });
  }

  // ── 회차 모달 · 액션 ──────────────────────────────────────────────────

  function openForm(id) {
    var u = ui();
    var r = id ? findApp(id) : null;
    var c = state.detail && state.detail.contract;
    if (!c) return;

    loadOptions().then(function (o) {
      u.formModal({
        title: r ? '회차 수정 — #' + r.applicationNo : '새 기성 회차 — ' + c.title,
        subtitle: '실제로 적는 건 기간과 E·F 뿐입니다. 전회 기성(D)·누계(G)·유보·순청구액은 서버가 계산해 저장 직후 보여줍니다.',
        saveLabel: r ? '수정' : '저장 (draft)',
        fields: [
          { name: 'type', label: '회차 유형', type: 'select', required: true, group: '① 어떤 청구인가',
            options: o.types, value: r ? r.type : 'progress',
            hint: '평상시엔 기성 청구. 유보금을 돌려받는 회차만 유보 해제/최종을 고르세요.' },
          { name: 'periodStart', label: '기간 시작일', type: 'date', group: '① 어떤 청구인가', value: r ? r.periodStart : '' },
          { name: 'periodEnd', label: '기간 말일', type: 'date', required: true, group: '① 어떤 청구인가', value: r ? r.periodEnd : '' },

          { name: 'thisPeriodAmount', label: '금회 시공분 (E)', group: '② 금액', value: r ? r.thisPeriodAmount : '',
            hint: '이번 기간에 시공한 금액. 보관 자재를 이번에 시공했으면 여기에 포함하세요.' },
          { name: 'storedMaterialsAmount', label: '보관 자재 (F)', group: '② 금액', value: r ? r.storedMaterialsAmount : '',
            hint: '지금 현장·창고에 보관 중인 자재만. 매회 다시 적습니다 — 지난달 값에 더하지 마세요.' },
          { name: 'retainagePercent', label: '유보율 (%)', group: '② 금액',
            value: r ? (r.retainagePercent !== null ? r.retainagePercent : '') : (c.retainagePercent !== null ? c.retainagePercent : ''),
            hint: '비우면 계약의 유보율을 씁니다. 바꾸면 이 회차부터 누계 전체가 새 율로 재계산됩니다.' },
          { name: 'retainageReleased', label: '유보 해제액', group: '② 금액', value: r ? r.retainageReleased : '',
            hint: '유보 해제·최종 회차에서만. 직전 승인 회차의 유보 잔액을 넘을 수 없습니다.' },

          { name: 'dueOn', label: '지급 기일', type: 'date', group: '③ 일정 · 서류', value: r ? r.dueOn : '',
            hint: '비우면 제출일 + ' + (c.paymentTerms ? '"' + c.paymentTerms + '" 파싱값' : '45일') + '으로 자동 채웁니다.' },
          { name: 'conditionalWaiverOn', label: 'Conditional Waiver 발행일', type: 'date', group: '③ 일정 · 서류',
            value: r ? r.conditionalWaiverOn : '', hint: '기록용입니다. 파일은 계약 서류함에 편철하세요.' },
          { name: 'unconditionalWaiverOn', label: 'Unconditional Waiver 발행일', type: 'date', group: '③ 일정 · 서류',
            value: r ? r.unconditionalWaiverOn : '' },
          { name: 'notes', label: '메모', type: 'textarea', colSpan: 2, group: '③ 일정 · 서류', value: r ? r.notes : '' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          v.projectContractId = c.id;
          return call('api_saveBilling', [v]).then(function (res) {
            if (res.success === false) return res;
            // 파생 금액 표시 — 서버 computed 를 그대로 보여준다 (프론트 재계산 없음)
            u.toast((r ? '회차를 수정했습니다. ' : '회차 #' + res.applicationNo + ' 을(를) 저장했습니다. ') + computedSummary(res.computed, c.currency));
            notifyAlerts(res.alerts);
            return afterWrite().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function submitApp(id) {
    var u = ui();
    var r = findApp(id);
    u.confirmDanger({
      title: '회차를 제출할까요?',
      body: '회차 #' + (r ? r.applicationNo : id) + ' 을(를) 제출합니다. 제출 시점에 전회 누계(D)·순청구액이 최종 확정되고, ' +
        '이후 금액 수정은 다음 회차 조정으로만 가능합니다.',
      confirmLabel: '제출',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_setBillingStatus', [{ id: id, action: 'submit' }]).then(function (res) {
        if (res.success === false) {
          u.toast(res.error || (res.errors && Object.values(res.errors)[0]) || '제출하지 못했습니다.', 'error');
          return;
        }
        u.toast('제출했습니다. ' + computedSummary(res.computed, cur()));
        notifyAlerts(res.alerts);
        return afterWrite();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function withdrawApp(id) {
    var u = ui();
    u.confirmDanger({
      title: '제출을 회수할까요?',
      body: '회차가 작성 중(draft)으로 돌아가고 제출일이 지워집니다. 수금이 없는 최신 회차만 가능합니다.',
      confirmLabel: '회수',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_setBillingStatus', [{ id: id, action: 'withdraw' }]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '회수하지 못했습니다.', 'error'); return; }
        u.toast('작성 중(draft)으로 되돌렸습니다.');
        return afterWrite();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function openApprove(id) {
    var u = ui();
    var r = findApp(id);
    if (!r) return;
    u.formModal({
      title: '승인 기록 — 회차 #' + r.applicationNo,
      subtitle: '청구액 ' + money(r.amountDue, cur()) + '. GC 가 깎았으면 승인액만 적으세요 — 청구 원본은 협상 기록으로 그대로 남습니다.',
      saveLabel: '승인 기록',
      fields: [
        { name: 'approvedOn', label: '승인일', type: 'date', required: true, value: new Date().toISOString().slice(0, 10) },
        { name: 'approvedAmount', label: '승인액 (선택)', value: '',
          hint: '비우면 청구액 그대로 승인한 것으로 봅니다. 삭감됐을 때만 입력하세요.' },
      ],
      onSave: function (v) {
        return call('api_setBillingStatus', [{ id: id, action: 'approve', approvedOn: v.approvedOn, approvedAmount: v.approvedAmount }]).then(function (res) {
          if (res.success === false) return res;
          u.toast(res.status === 'paid' ? '승인을 기록했습니다. 잔액이 없어 수금 완료로 처리됐습니다.' : '승인을 기록했습니다.');
          return afterWrite().then(function () { return { success: true }; });
        });
      },
    });
  }

  function unapproveApp(id) {
    var u = ui();
    u.confirmDanger({
      title: '승인을 취소할까요?',
      body: '회차가 제출됨(submitted)으로 돌아가고 승인일·승인액이 지워집니다. 수금이 없는 최신 회차만 가능합니다.',
      confirmLabel: '승인 취소',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_setBillingStatus', [{ id: id, action: 'unapprove' }]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '승인을 취소하지 못했습니다.', 'error'); return; }
        u.toast('승인을 취소했습니다.');
        return afterWrite();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function openClose(id) {
    var u = ui();
    var r = findApp(id);
    if (!r) return;
    u.formModal({
      title: '수동 종결 (회수 포기) — 회차 #' + r.applicationNo,
      subtitle: '남은 잔액 ' + money(r.outstanding, cur()) + ' 을(를) 회수 포기하고 종결합니다. 사업 판단이므로 사유가 반드시 남습니다.',
      saveLabel: '종결',
      fields: [
        { name: 'memo', label: '종결 사유', type: 'textarea', required: true, colSpan: 2, value: '',
          hint: '예: 합의 종결, 소액 잔액 포기, 상계 합의 등 — 왜 포기했는지를 적으세요.' },
      ],
      onSave: function (v) {
        return call('api_setBillingStatus', [{ id: id, action: 'close', memo: v.memo }]).then(function (res) {
          if (res.success === false) return res;
          u.toast('수동 종결했습니다.');
          return afterWrite().then(function () { return { success: true }; });
        });
      },
    });
  }

  function reopenApp(id) {
    var u = ui();
    u.confirmDanger({
      title: '종결을 재개할까요?',
      body: '수동 종결을 취소하고 승인됨(approved)으로 되돌립니다. 잔액이 다시 미수금으로 잡힙니다.',
      confirmLabel: '재개',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_setBillingStatus', [{ id: id, action: 'reopen' }]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '재개하지 못했습니다.', 'error'); return; }
        u.toast('승인됨(approved)으로 되돌렸습니다.');
        return afterWrite();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function removeApp(id) {
    var u = ui();
    var r = findApp(id);
    u.confirmDanger({
      title: '회차를 삭제할까요?',
      body: '회차 #' + (r ? r.applicationNo : id) + ' 을(를) 삭제합니다. 작성 중(draft)이면서 수금이 없는 최신 회차만 삭제됩니다.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteBilling', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('회차를 삭제했습니다.');
        return afterWrite();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  // ── 수금 모달 · 액션 ──────────────────────────────────────────────────

  function openReceipt(appId) {
    var u = ui();
    var c = state.detail && state.detail.contract;
    if (!c) return;

    loadOptions().then(function (o) {
      u.formModal({
        title: '수금 입력 — ' + c.title,
        subtitle: '입금은 사실 기록입니다 — 수정 대신 삭제 후 재입력합니다. 회차를 비우면 매칭 대기(계약 직속)로 남습니다.',
        saveLabel: '기록',
        fields: [
          { name: 'payApplicationId', label: '배정 회차', type: 'select', group: '① 어디 돈인가',
            options: assignableAppOptions(), value: appId ? String(appId) : '',
            hint: '승인된 회차에만 배정할 수 있습니다. 어느 회차 돈인지 아직 모르면 비워 두세요.' },
          { name: 'receivedOn', label: '입금일', type: 'date', required: true, group: '① 어디 돈인가',
            value: new Date().toISOString().slice(0, 10) },
          { name: 'amount', label: '입금액', required: true, group: '① 어디 돈인가', value: '' },
          { name: 'method', label: '입금 수단', type: 'select', group: '① 어디 돈인가',
            options: o.methods, value: 'check' },
          { name: 'reference', label: 'Check # / ACH 번호', group: '① 어디 돈인가', value: '' },

          { name: 'deductionAmount', label: '차감액 (상계)', group: '② GC 가 깎았다면', value: '',
            hint: '입금과 함께 GC 가 상계한 금액. 없으면 비워 두세요.' },
          { name: 'deductionReason', label: '차감 사유', type: 'select', group: '② GC 가 깎았다면',
            options: o.deductionReasons, value: '' },
          { name: 'deductionAccepted', label: '차감 인정 여부', type: 'select', group: '② GC 가 깎았다면',
            options: o.deductionDecisions, value: '',
            hint: '인정한 차감만 잔액에서 빠집니다. 미판단·불인정은 잔액에 남고, 불인정은 분쟁으로 집계됩니다.' },
          { name: 'memo', label: '메모', type: 'textarea', colSpan: 2, group: '② GC 가 깎았다면', value: '' },
        ],
        onSave: function (v) {
          v.projectContractId = c.id;
          return call('api_saveBillingReceipt', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(res.applicationStatus === 'paid'
              ? '수금을 기록했습니다. 잔액이 없어 회차가 수금 완료(paid) 처리됐습니다.'
              : (v.payApplicationId ? '수금을 기록했습니다.' : '수금을 기록했습니다. 회차 미배정 — 매칭 대기 목록에 있습니다.'));
            return afterWrite().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function findReceipt(id) {
    var rows = (state.detail && state.detail.rows) || [];
    for (var i = 0; i < rows.length; i++) {
      var hit = (rows[i].receipts || []).filter(function (x) { return x.id === id; })[0];
      if (hit) return hit;
    }
    return ((state.detail && state.detail.unassignedReceipts) || []).filter(function (x) { return x.id === id; })[0];
  }

  function openAssign(receiptId) {
    var u = ui();
    var receipt = findReceipt(receiptId);
    if (!receipt) return;

    loadOptions().then(function (o) {
      var fields = [
        { name: 'payApplicationId', label: '배정 회차', type: 'select',
          options: assignableAppOptions(), value: receipt.payApplicationId ? String(receipt.payApplicationId) : '',
          hint: '비우면 매칭 대기로 돌아갑니다. 떠난 회차·새 회차 모두 잔액이 다시 계산됩니다.' },
      ];
      // 차감이 있는 입금만 판단(3상태)을 바꿀 수 있다 — 없는데 보내면 서버가 소거한다
      if (receipt.deductionAmount) {
        fields.push({ name: 'deductionAccepted', label: '차감 인정 여부', type: 'select',
          options: o.deductionDecisions,
          value: receipt.deductionAccepted === true ? '1' : receipt.deductionAccepted === false ? '0' : '' });
      }
      u.formModal({
        title: '수금 배정 — ' + (receipt.receivedOn || '') + ' · ' + money(receipt.amount, cur()),
        subtitle: '승인된 회차에만 배정할 수 있습니다. 배정하면 그 회차 잔액에서 이 입금이 빠집니다.',
        saveLabel: '적용',
        fields: fields,
        onSave: function (v) {
          // api_assignBillingReceipt 는 "키가 있으면 그 항목을 바꾼다" 계약이다 —
          // 판단 셀렉트를 안 띄운 입금에는 deductionAccepted 키 자체를 보내지 않는다
          var payload = { id: receiptId, payApplicationId: v.payApplicationId };
          if (receipt.deductionAmount) payload.deductionAccepted = v.deductionAccepted;
          return call('api_assignBillingReceipt', [payload]).then(function (res) {
            if (res.success === false) return res;
            u.toast(res.applicationStatus === 'paid' ? '배정했습니다. 회차가 수금 완료(paid) 처리됐습니다.' : '적용했습니다.');
            return afterWrite().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function removeReceipt(id) {
    var u = ui();
    var r = findReceipt(id);
    u.confirmDanger({
      title: '수금 기록을 삭제할까요?',
      body: (r ? r.receivedOn + ' 입금 ' + money(r.amount, cur()) : '이 입금') + ' 을(를) 삭제합니다. ' +
        '입금은 수정이 없습니다 — 틀렸으면 삭제 후 다시 입력하세요. 수금 완료(paid)였던 회차는 승인됨으로 되돌아갑니다.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteBillingReceipt', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('수금 기록을 삭제했습니다.');
        return afterWrite();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  // ── 진입점 ────────────────────────────────────────────────────────────

  function renderScreen() {
    state.view = 'list';
    state.detail = null;
    state.expandedId = null;
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    reloadList().catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
        ui().esc(e.message || '기성 현황을 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminBilling = {
    render: renderScreen,
    openDetail: openDetail,
    backToList: backToList,
    toggleReceipts: toggleReceipts,
    openForm: openForm,
    submitApp: submitApp,
    withdrawApp: withdrawApp,
    openApprove: openApprove,
    unapproveApp: unapproveApp,
    openClose: openClose,
    reopenApp: reopenApp,
    removeApp: removeApp,
    openReceipt: openReceipt,
    openAssign: openAssign,
    removeReceipt: removeReceipt,
    _state: state,
  };
})(window);
