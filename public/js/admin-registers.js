/**
 * 제출물 대장(submittals) + 물량/BOQ(boq) — 시방·도면에서 뽑은 계약 요구 추적.
 *
 * 두 화면은 같은 뼈대다: 프로젝트를 고르고, 시방·도면에서 임포트된 행들을
 * 필터로 좁혀 보고, 현장이 고칠 수 있는 것(상태·담당·날짜 / 수량·단가)만 고친다.
 * 행의 "내용"(조항 원문·산출 근거)은 임포트가 정본이라 화면에서 편집하지 않는다 —
 * 근거가 지워진 대장은 감리 앞에서 힘이 없다.
 *
 * 게이트(★) 행은 시방에 금지·실격·입회 같은 강제 조항이 걸린 항목이다.
 * 이 표에서 제일 먼저 눈에 띄어야 해서 배지로 세고 행에도 표시한다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = {
    // submittals
    sub: null, subProjectId: null, subF: { csi: '', category: '', status: '', gateOnly: false },
    // boq
    boq: null, boqProjectId: null, boqTab: '', boqReviewOnly: false,
  };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      if (res.success === false) throw new Error(res.error || '요청이 거부되었습니다.');
      return res;
    });
  }

  function paint(html) { document.getElementById('page-container').innerHTML = html; }

  function money(v) {
    return '$' + Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
  }

  function chip(label, value, tone) {
    var color = tone === 'danger' ? 'var(--danger,#dc2626)' : tone === 'ok' ? 'var(--success,#16a34a)' : 'var(--text-secondary)';
    return '<div style="padding:10px 14px;border:1px solid var(--border-default);border-radius:10px;background:var(--bg-surface)">' +
      '<div style="font-size:11px;color:var(--text-tertiary)">' + ui().esc(label) + '</div>' +
      '<div style="font-size:18px;font-weight:700;color:' + color + '">' + value + '</div></div>';
  }

  function projectSelect(list, current, onchangeAttr) {
    var u = ui();
    if (!list || list.length <= 1) return '';
    return '<select onchange="' + onchangeAttr + '" style="padding:8px 12px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px">' +
      list.map(function (p) {
        return '<option value="' + p.id + '"' + (p.id === current ? ' selected' : '') + '>' + u.esc(p.label) + '</option>';
      }).join('') + '</select>';
  }

  function filterSelect(id, label, values, current, onchangeAttr) {
    var u = ui();
    return '<select id="' + id + '" onchange="' + onchangeAttr + '" ' +
      'style="padding:7px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:12px">' +
      '<option value="">' + u.esc(label) + ' 전체</option>' +
      values.map(function (v) {
        return '<option value="' + u.esc(v) + '"' + (v === current ? ' selected' : '') + '>' + u.esc(v) + '</option>';
      }).join('') + '</select>';
  }

  /* ══════════════════════ 제출물 대장 ══════════════════════ */

  function catBadge(cat) {
    var u = ui();
    var kind = cat === 'Action 제출물' ? 'warn' : cat === 'Closeout 제출물' ? 'ok' : cat === '시험·검사' ? 'danger' : '';
    return u.badge(cat, kind);
  }

  function statusBadgeKind(s) {
    if (s === '승인') return 'ok';
    if (s === '반려') return 'danger';
    if (s === '제출' || s === '재제출' || s === '조건부승인') return 'warn';
    return '';
  }

  function subRows() {
    var f = state.subF;
    return (state.sub.rows || []).filter(function (r) {
      if (f.csi && r.csi !== f.csi) return false;
      if (f.category && r.category !== f.category) return false;
      if (f.status && r.status !== f.status) return false;
      if (f.gateOnly && !r.gate) return false;
      return true;
    });
  }

  function drawSubmittals() {
    var u = ui();
    var d = state.sub;
    var rows = subRows();
    var st = d.stats || { total: 0, gate: 0, byStatus: {} };
    var canManage = !!d.canManage;

    var uniq = function (key) {
      var seen = {};
      (d.rows || []).forEach(function (r) { seen[r[key]] = true; });
      return Object.keys(seen);
    };

    var statusCell = function (r) {
      if (!canManage) return u.badge(r.status, statusBadgeKind(r.status));
      return '<select onchange="window.AdminRegisters.quickStatus(' + r.id + ', this.value)" ' +
        'style="padding:5px 8px;border-radius:6px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:12px">' +
        (d.statuses || []).map(function (s) {
          return '<option value="' + u.esc(s) + '"' + (s === r.status ? ' selected' : '') + '>' + u.esc(s) + '</option>';
        }).join('') + '</select>';
    };

    var html =
      u.pageHeader('제출물 대장', '시방서 15개 공종 + 도면 노트에서 전수 추출한 제출물·QA·시험 요구. ★는 시방 명문 정지·실격 조항(우선관리).') +
      '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">' +
        chip('전체', st.total) +
        chip('게이트 ★', st.gate, 'danger') +
        chip('승인', (st.byStatus && st.byStatus['승인']) || 0, 'ok') +
        chip('제출·재제출', ((st.byStatus && st.byStatus['제출']) || 0) + ((st.byStatus && st.byStatus['재제출']) || 0)) +
        chip('반려', (st.byStatus && st.byStatus['반려']) || 0, 'danger') +
      '</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">' +
        projectSelect(d.projects, d.projectId, 'window.AdminRegisters.setSubProject(this.value)') +
        filterSelect('sub-f-csi', '공종', uniq('csi'), state.subF.csi, 'window.AdminRegisters.setSubFilter(\'csi\', this.value)') +
        filterSelect('sub-f-cat', '구분', uniq('category'), state.subF.category, 'window.AdminRegisters.setSubFilter(\'category\', this.value)') +
        filterSelect('sub-f-st', '상태', d.statuses || [], state.subF.status, 'window.AdminRegisters.setSubFilter(\'status\', this.value)') +
        '<label style="display:flex;gap:6px;align-items:center;font-size:12px;color:var(--text-secondary);cursor:pointer">' +
          '<input type="checkbox"' + (state.subF.gateOnly ? ' checked' : '') + ' onchange="window.AdminRegisters.setSubFilter(\'gateOnly\', this.checked)">게이트만' +
        '</label>' +
      '</div>' +
      u.table({
        id: 'sub-tbl',
        searchPlaceholder: '조항·공종·담당 검색',
        emptyText: '조건에 맞는 항목이 없습니다.',
        columns: [
          { key: 'seq', label: '번호', width: '52px', align: 'center' },
          { key: 'csi', label: '공종', width: '110px', render: function (r) {
              return '<div style="font-family:ui-monospace,monospace;font-size:12px">' + u.esc(r.csi) + '</div>' +
                '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.section) + '</div>';
            } },
          { key: 'category', label: '구분', width: '130px', render: function (r) { return catBadge(r.category); } },
          { key: 'title', label: '제출물 · 요구사항', render: function (r) {
              return '<div style="font-size:12.5px;line-height:1.55;white-space:normal;min-width:340px">' +
                (r.gate ? '<span style="color:var(--danger,#dc2626);font-weight:700">★ </span>' : '') + u.esc(r.title) + '</div>';
            } },
          { key: 'status', label: '상태', width: '120px', render: statusCell },
          { key: 'assignee', label: '담당 · 일정', width: '150px', render: function (r) {
              var lines = [];
              if (r.assignee) lines.push(u.esc(r.assignee));
              if (r.plannedOn) lines.push('계획 ' + r.plannedOn);
              if (r.submittedOn) lines.push('제출 ' + r.submittedOn);
              if (r.approvedOn) lines.push('승인 ' + r.approvedOn);
              return lines.length ? '<div style="font-size:11px;color:var(--text-secondary);line-height:1.6">' + lines.join('<br>') + '</div>' : '';
            } },
          { key: '_act', label: '', width: '150px', align: 'right', render: function (r) {
              if (!canManage) return '';
              // 조항을 읽고 업체에 보낼 요청서를 매번 손으로 쓰던 일 — 그 편지를 대신 쓴다.
              return u.rowButton('📨 자료요청', 'window.AdminRegisters.requestVendorData(' + r.id + ')') + ' ' +
                u.rowButton('기록', 'window.AdminRegisters.openSubmittal(' + r.id + ')');
            } },
        ],
        rows: rows,
      });

    paint(html);
    u.bindSearch('sub-tbl');
  }

  /**
   * 조항 → 업체 자료 요청서 → 문서함 편철.
   * 업체명은 선택이다 — 아직 업체가 안 정해졌어도 요청서 틀은 미리 만들어 둘 수 있다.
   */
  function requestVendorData(id) {
    var u = ui();
    var row = (state.sub.rows || []).filter(function (r) { return r.id === id; })[0];
    if (!row) return;

    u.formModal({
      title: '업체 자료 요청서 만들기',
      subtitle: (row.csi ? '[' + row.csi + '] ' : '') + (row.section || '') +
        ' — 이 조항이 요구하는 자료를 낱개로 정리해 요청서를 쓰고 문서함에 넣습니다.',
      saveLabel: '만들기',
      fields: [
        { name: 'vendor', label: '수신 업체 (선택)', colSpan: 2, value: '',
          hint: '비워 두면 "(업체명)" 으로 두고 나중에 채울 수 있습니다.' },
      ],
      onSave: function (v) {
        return call('api_requestVendorData', [id, v.vendor || null]).then(function (res) {
          if (res.success === false) return res;
          u.toast(res.message || '요청서를 만들었습니다.');
          return { success: true };
        });
      },
    });
  }

  function reloadSubmittals() {
    return call('api_getSubmittals', [state.subProjectId]).then(function (d) {
      state.sub = d;
      state.subProjectId = d.projectId;
    });
  }

  function renderSubmittals() {
    paint('<div style="padding:60px;text-align:center;color:var(--text-tertiary)">제출물 대장을 불러오는 중…</div>');
    reloadSubmittals().then(drawSubmittals).catch(function (e) {
      paint('<div style="padding:60px;text-align:center;color:var(--danger,#dc2626)">' + ui().esc(e.message) + '</div>');
    });
  }

  function setSubProject(v) { state.subProjectId = parseInt(v, 10) || null; renderSubmittals(); }

  function setSubFilter(key, value) { state.subF[key] = value; drawSubmittals(); }

  function quickStatus(id, status) {
    var u = ui();
    call('api_saveSubmittal', [{ id: id, status: status }]).then(function () {
      (state.sub.rows || []).forEach(function (r) { if (r.id === id) r.status = status; });
      // 상태만 바꿨을 때 표 전체를 다시 그리면 스크롤·검색이 초기화되므로 통계만 다시 그린다.
      var st = { total: 0, gate: 0, byStatus: {} };
      (state.sub.rows || []).forEach(function (r) {
        st.total++; if (r.gate) st.gate++;
        st.byStatus[r.status] = (st.byStatus[r.status] || 0) + 1;
      });
      state.sub.stats = st;
      u.toast('상태를 "' + status + '" 로 기록했습니다.');
    }).catch(function (e) { u.toast(e.message, 'error'); drawSubmittals(); });
  }

  function openSubmittal(id) {
    var u = ui();
    var row = (state.sub.rows || []).filter(function (r) { return r.id === id; })[0];
    if (!row) return;

    u.formModal({
      title: '제출물 기록 — #' + row.seq,
      subtitle: row.title.slice(0, 120),
      saveLabel: '기록',
      fields: [
        { name: 'status', label: '상태', type: 'select', required: true, group: '진행',
          options: state.sub.statuses || [], value: row.status },
        { name: 'assignee', label: '담당', group: '진행', value: row.assignee || '' },
        { name: 'plannedOn', label: '계획일', type: 'date', group: '일정', value: row.plannedOn || '' },
        { name: 'submittedOn', label: '제출일', type: 'date', group: '일정', value: row.submittedOn || '' },
        { name: 'approvedOn', label: '승인일', type: 'date', group: '일정', value: row.approvedOn || '' },
        { name: 'notes', label: '메모', type: 'textarea', colSpan: 2, group: '일정', value: row.notes || '' },
      ],
      onSave: function (v) {
        v.id = id;
        return call('api_saveSubmittal', [v]).then(function () {
          u.toast('기록했습니다.');
          return reloadSubmittals().then(function () { drawSubmittals(); return { success: true }; });
        }).catch(function (e) { return { success: false, error: e.message }; });
      },
    });
  }

  /* ══════════════════════ 물량 / BOQ ══════════════════════ */

  function basisBadge(basis) {
    var u = ui();
    var kind = basis === '문서확정' ? 'ok' : basis === '미확정' ? 'danger' : basis === '개산추정' ? 'warn' : '';
    return u.badge(basis, kind);
  }

  function boqTabs() {
    var u = ui();
    var t = state.boq.totals || { byDiscipline: [] };
    var btn = function (code, label, amount) {
      var on = state.boqTab === code;
      return '<button type="button" onclick="window.AdminRegisters.setBoqTab(\'' + code + '\')" ' +
        'style="padding:8px 14px;border:none;background:none;font-size:13px;cursor:pointer;white-space:nowrap;' +
        'border-bottom:2px solid ' + (on ? 'var(--brand-primary)' : 'transparent') + ';' +
        'color:' + (on ? 'var(--text-primary)' : 'var(--text-secondary)') + ';font-weight:' + (on ? '700' : '500') + '">' +
        u.esc(label) + ' <span style="color:var(--text-tertiary);font-weight:400;font-size:11px">' + money(amount) + '</span></button>';
    };
    return '<div style="display:flex;gap:2px;border-bottom:1px solid var(--border-default);margin-bottom:14px;overflow-x:auto">' +
      btn('', '전체', t.grand || 0) +
      (t.byDiscipline || []).map(function (g) { return btn(g.code, g.code + ' ' + g.name, g.amount); }).join('') +
      '</div>';
  }

  function boqRows() {
    return (state.boq.rows || []).filter(function (r) {
      if (state.boqReviewOnly && !r.needsReview) return false;
      return !state.boqTab || r.disciplineCode === state.boqTab;
    });
  }

  /** 도면 판독이 자신 없어 한 줄만 걸러 본다 — 사람이 볼 곳은 여기뿐이다. */
  function toggleReviewOnly() {
    state.boqReviewOnly = !state.boqReviewOnly;
    drawBoq();
  }

  function drawBoq() {
    var u = ui();
    var d = state.boq;
    var rows = boqRows();
    var t = d.totals || { grand: 0, unresolved: 0, flagged: 0 };
    var canManage = !!d.canManage;
    var shown = rows.reduce(function (a, r) { return a + (r.amount || 0); }, 0);

    var html =
      u.pageHeader('물량 / BOQ', '공정별 물량·단가 대장 (직접공사비 기준). 수량근거 "미확정" 은 도면 실측 후 채우는 LS 배정액이고, ⚑ 는 단가 편차가 커서 검토가 필요한 행입니다.') +
      '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">' +
        chip('직접비 합계', money(t.grand)) +
        chip('표시 범위 합계', money(shown)) +
        chip('실측 필요(LS)', t.unresolved || 0, 'danger') +
        chip('검토 플래그 ⚑', t.flagged || 0, 'danger') +
        // 도면에서 뽑은 줄은 바로 대장에 들어간다. 그중 확신이 낮았던 것만 여기 모인다.
        (t.needsReview ? '<button type="button" onclick="window.AdminRegisters.toggleReviewOnly()" ' +
          'style="border:1px solid ' + (state.boqReviewOnly ? 'var(--brand-primary)' : 'var(--border-default)') +
          ';background:' + (state.boqReviewOnly ? 'var(--brand-primary)' : 'transparent') +
          ';color:' + (state.boqReviewOnly ? '#fff' : 'var(--text-primary)') +
          ';border-radius:999px;padding:6px 14px;font-size:12.5px;font-weight:700;cursor:pointer">' +
          '🔍 확인 필요 ' + t.needsReview + '</button>' : '') +
      '</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px">' +
        projectSelect(d.projects, d.projectId, 'window.AdminRegisters.setBoqProject(this.value)') +
      '</div>' +
      boqTabs() +
      u.table({
        id: 'boq-tbl',
        searchPlaceholder: '품명·규격·근거 검색',
        emptyText: '항목이 없습니다.',
        columns: [
          { key: 'seq', label: '번호', width: '52px', align: 'center' },
          { key: 'nameKr', label: '품명', render: function (r) {
              return '<div style="font-size:12.5px;white-space:normal;min-width:220px">' +
                (r.flagged ? '<span style="color:var(--danger,#dc2626)">⚑ </span>' : '') + u.esc(r.nameKr) + '</div>' +
                (r.nameEn ? '<div style="font-size:11px;color:var(--text-tertiary);white-space:normal">' + u.esc(r.nameEn) + '</div>' : '') +
                // 왜 확인해야 하는지를 그 줄에 바로 적는다 — 다시 물어볼 일이 없게.
                (r.needsReview ? '<div style="font-size:11px;color:var(--danger,#dc2626);white-space:normal;margin-top:3px">🔍 ' +
                  u.esc(r.reviewReason || '확인 필요') + '</div>' : '') +
                (r.extractedBy ? '<div style="font-size:10.5px;color:var(--text-tertiary);margin-top:2px">' +
                  u.esc(r.extractedBy) + ' 판독' + (r.confidence != null ? ' · 확신도 ' + r.confidence : '') + '</div>' : '');
            } },
          { key: 'spec', label: '규격 · 사양', render: function (r) {
              return r.spec ? '<div style="font-size:11.5px;color:var(--text-secondary);white-space:normal;min-width:180px;line-height:1.5">' + u.esc(r.spec) + '</div>' : '';
            } },
          { key: 'unit', label: '단위', width: '58px', align: 'center' },
          { key: 'qty', label: '수량', width: '110px', align: 'right', render: function (r) {
              return '<div style="font-variant-numeric:tabular-nums">' + Number(r.qty).toLocaleString('en-US', { maximumFractionDigits: 2 }) + '</div>' +
                '<div style="margin-top:3px">' + basisBadge(r.qtyBasis) + '</div>';
            } },
          { key: 'unitPrice', label: '단가', width: '110px', align: 'right', render: function (r) {
              return '<span style="font-variant-numeric:tabular-nums">$' + Number(r.unitPrice).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span>';
            } },
          { key: 'amount', label: '금액', width: '110px', align: 'right', render: function (r) {
              return '<strong style="font-variant-numeric:tabular-nums">' + money(r.amount) + '</strong>';
            } },
          { key: 'source', label: '산출근거', width: '150px', render: function (r) {
              return (r.wbsActivityId ? '<div style="margin-bottom:3px">' + u.badge('WBS ' + r.wbsActivityId, 'ok') + '</div>' : '') +
                (r.source ? '<div style="font-size:11px;color:var(--text-tertiary);white-space:normal">' + u.esc(r.source) + '</div>' : '');
            } },
          { key: '_act', label: '', width: '70px', align: 'right', render: function (r) {
              return canManage ? u.rowButton('수정', 'window.AdminRegisters.openBoqItem(' + r.id + ')') : '';
            } },
        ],
        rows: rows,
      });

    paint(html);
    u.bindSearch('boq-tbl');
  }

  function reloadBoq() {
    return call('api_getBoq', [state.boqProjectId]).then(function (d) {
      state.boq = d;
      state.boqProjectId = d.projectId;
    });
  }

  function renderBoq() {
    paint('<div style="padding:60px;text-align:center;color:var(--text-tertiary)">물량 대장을 불러오는 중…</div>');
    reloadBoq().then(drawBoq).catch(function (e) {
      paint('<div style="padding:60px;text-align:center;color:var(--danger,#dc2626)">' + ui().esc(e.message) + '</div>');
    });
  }

  function setBoqProject(v) { state.boqProjectId = parseInt(v, 10) || null; renderBoq(); }

  function setBoqTab(code) { state.boqTab = code; drawBoq(); }

  function openBoqItem(id) {
    var u = ui();
    var row = (state.boq.rows || []).filter(function (r) { return r.id === id; })[0];
    if (!row) return;

    u.formModal({
      title: '물량 수정 — #' + row.seq + ' ' + row.nameKr.slice(0, 40),
      subtitle: row.source ? '산출근거: ' + row.source : '',
      saveLabel: '저장',
      fields: [
        { name: 'qty', label: '수량 (' + row.unit + ')', type: 'number', required: true, group: '수량 · 단가', value: row.qty },
        { name: 'qtyBasis', label: '수량근거', type: 'select', required: true, group: '수량 · 단가',
          options: ['문서확정', '도면판독', '개산추정', '미확정'], value: row.qtyBasis,
          hint: '실측으로 채웠으면 "도면판독" 이상으로 올리세요.' },
        { name: 'unitPrice', label: '단가 (USD)', type: 'number', required: true, group: '수량 · 단가', value: row.unitPrice },
        { name: 'wbsActivityId', label: 'WBS 액티비티', group: '수량 · 단가', value: row.wbsActivityId || '',
          hint: '공정관리의 액티비티 ID (예: S020) — 이 라인의 돈이 어느 작업 몫인지. 기성 SOV 분해의 근거가 됩니다.' },
        { name: 'note', label: '메모', type: 'textarea', colSpan: 2, group: '수량 · 단가', value: row.note || '' },
      ],
      onSave: function (v) {
        v.id = id;
        return call('api_saveBoqItem', [v]).then(function () {
          u.toast('저장했습니다. 금액은 자동 재계산됩니다.');
          return reloadBoq().then(function () { drawBoq(); return { success: true }; });
        }).catch(function (e) { return { success: false, error: e.message }; });
      },
    });
  }

  global.AdminRegisters = {
    renderSubmittals: renderSubmittals,
    renderBoq: renderBoq,
    setSubProject: setSubProject,
    setSubFilter: setSubFilter,
    quickStatus: quickStatus,
    openSubmittal: openSubmittal,
    requestVendorData: requestVendorData,
    setBoqProject: setBoqProject,
    toggleReviewOnly: toggleReviewOnly,
    setBoqTab: setBoqTab,
    openBoqItem: openBoqItem,
    _state: state,
  };
})(window);
