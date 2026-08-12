/**
 * 임금 프로필 — Filament EmployeePayrollProfileResource 를 SPA 로 옮긴 것.
 *
 * 시간은 출퇴근에서 자동으로 오지만 "얼마" 는 사람이 여기서 정한다. 단가가 비어 있으면
 * 아무리 일해도 급여가 0 으로 계산되므로, 목록 맨 위에 미입력 건수를 먼저 보여 주고
 * 해당 행은 빨갛게 칠한다 — 급여 마감 날 발견하면 늦다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = { rows: [], options: null, canManage: false, missing: 0, onlyMissing: false };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  function money(v, cur) {
    if (v === null || v === undefined || v === '') return '';
    var n = Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return (cur === 'KRW' ? '₩' : '$') + n;
  }

  function visibleRows() {
    if (!state.onlyMissing) return state.rows;
    return state.rows.filter(function (r) { return r.rateMissing; });
  }

  function table() {
    var u = ui();
    return u.table({
      id: 'pp-tbl',
      searchPlaceholder: '이름 · 사번 · 직종 검색',
      emptyText: state.onlyMissing ? '단가 미입력인 직원이 없습니다.' : '등록된 임금 프로필이 없습니다.',
      columns: [
        {
          key: 'employee', label: '직원',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.employee) + '</div>' +
              (r.employeeNumber
                ? '<div style="font-size:11px;color:var(--text-tertiary);font-family:var(--font-mono,monospace)">' +
                  u.esc(r.employeeNumber) + '</div>'
                : '');
          },
        },
        {
          key: 'payTypeLabel', label: '임금 형태', width: '120px',
          render: function (r) { return u.badge(r.payTypeLabel, 'muted'); },
        },
        {
          key: 'baseRate', label: '기준 임금', align: 'right', width: '130px',
          render: function (r) {
            if (r.rateMissing) {
              return '<span style="color:var(--status-danger);font-weight:700">미입력</span>';
            }
            return '<span style="font-weight:600">' + u.esc(money(r.baseRate, r.currency)) + '</span>';
          },
        },
        {
          key: 'overtimeMultiplier', label: '연장 배수', align: 'right', width: '90px',
          render: function (r) { return r.overtimeMultiplier ? '×' + r.overtimeMultiplier : ''; },
        },
        {
          key: 'perDiem', label: '일비', align: 'right', width: '100px',
          render: function (r) { return u.esc(money(r.perDiem, r.currency)); },
        },
        { key: 'trade', label: '직종', width: '110px' },
        { key: 'division', label: '직군', width: '90px' },
        {
          key: 'site', label: '현장', width: '90px',
          render: function (r) { return r.site ? u.badge(r.site, 'muted') : ''; },
        },
        {
          key: 'act', label: '', align: 'right', width: '120px',
          render: function (r) {
            if (!state.canManage) return '';
            return u.rowButton('수정', 'window.AdminPayProfiles.open(' + r.id + ')') + ' ' +
              u.rowButton('삭제', 'window.AdminPayProfiles.remove(' + r.id + ')', 'danger');
          },
        },
      ],
      rows: visibleRows(),
    });
  }

  function missingBanner() {
    var u = ui();
    if (!state.missing) return '';
    return '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;' +
      'margin-bottom:14px;border-radius:10px;border:1px solid var(--status-danger);' +
      'background:color-mix(in srgb, var(--status-danger) 8%, transparent)">' +
      '<div style="font-size:13px;color:var(--text-primary)">' +
      '<b>' + state.missing + '명</b> 의 기준 임금이 비어 있습니다. 이대로 급여를 마감하면 0 원으로 계산됩니다.</div>' +
      '<button type="button" onclick="window.AdminPayProfiles.toggleMissing()" ' +
      'style="padding:7px 14px;border-radius:8px;border:1px solid var(--border-strong);background:var(--bg-elevated);' +
      'color:var(--text-primary);font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">' +
      (state.onlyMissing ? '전체 보기' : '미입력만 보기') + '</button></div>';
  }

  function render() {
    var u = ui();
    var notes = [state.rows.length + '명'];
    if (state.missing) notes.push(state.missing + '명 단가 미입력');

    return u.pageHeader(
      '임금 프로필',
      // 프로필은 직원을 등록할 때 자동으로 생긴다. 여기서 하는 일은 단가를 채우는 것뿐이다.
      '직원을 등록하면 프로필이 자동으로 생깁니다. 여기서는 단가만 채우면 됩니다. — ' + notes.join(' · '),
      state.canManage ? u.primaryButton('프로필 추가', 'window.AdminPayProfiles.open()', 'plus') : ''
    ) + missingBanner() + table();
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function draw() {
    paint(render());
    ui().bindSearch('pp-tbl');
  }

  function reload() {
    return call('api_getPayProfiles').then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '목록을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.rows = res.profiles || [];
      state.canManage = !!res.canManage;
      state.missing = res.missingRates || 0;
      draw();
    });
  }

  function loadOptions(force) {
    if (state.options && !force) return Promise.resolve(state.options);
    return call('api_getPayProfileOptions').then(function (res) {
      if (res.success === false) throw new Error(res.error || '선택지를 불러오지 못했습니다.');
      state.options = res;
      return res;
    });
  }

  function toggleMissing() { state.onlyMissing = !state.onlyMissing; draw(); }

  function open(id) {
    var u = ui();
    var row = id ? state.rows.filter(function (r) { return r.id === id; })[0] : null;

    // 새로 만들 때만 선택지를 다시 받는다 — 이미 프로필이 있는 직원은 목록에서 빠지므로
    // 저장 뒤 캐시가 남으면 같은 사람이 또 뜬다.
    loadOptions(!row).then(function (o) {
      var fields = [];

      if (row) {
        fields.push({ name: '_who', label: '직원', value: row.employee + (row.employeeNumber ? ' (' + row.employeeNumber + ')' : ''),
          group: '대상', colSpan: 2, hint: '직원은 바꿀 수 없습니다. 다른 사람이면 그 사람의 프로필을 여세요.' });
      } else {
        if (!(o.employees || []).length) {
          u.toast('프로필이 없는 활성 직원이 없습니다. 직원 등록에서 먼저 추가해 주세요.', 'error');
          return;
        }
        fields.push({ name: 'employee_id', label: '직원', type: 'select', required: true, group: '대상',
          options: o.employees, colSpan: 2, hint: '이미 프로필이 있는 직원은 목록에 없습니다.' });
      }

      fields.push(
        { name: 'pay_type', label: '임금 형태', type: 'select', required: true, group: '단가',
          options: o.payTypes, value: row ? row.payType : 'hourly' },
        { name: 'pay_currency', label: '통화', type: 'select', group: '단가',
          options: o.currencies, value: row ? row.currency : 'USD' },
        { name: 'base_rate', label: '기준 임금', type: 'number', group: '단가',
          value: row && row.baseRate !== null ? row.baseRate : '',
          hint: '시급이면 시간당, 일급이면 하루, 연봉이면 연 단위 금액입니다.' },
        { name: 'overtime_multiplier', label: '연장 배수', type: 'number', group: '단가',
          value: row && row.overtimeMultiplier !== null ? row.overtimeMultiplier : 1.5,
          hint: '주 40시간을 넘긴 시간에 곱합니다. 미국 기준 1.5.' },
        { name: 'per_diem_rate', label: '일비 (Per Diem)', type: 'number', group: '단가',
          value: row && row.perDiem !== null ? row.perDiem : '',
          hint: '출장·숙박 수당. 없으면 비워 두세요.' },

        { name: 'trade', label: '직종', group: '분류', value: row ? row.trade : '',
          hint: '배관 · 전기 · 용접처럼 인증임금(WH-347) 에 찍히는 이름' },
        { name: 'worker_division', label: '직군', type: 'select', group: '분류',
          options: o.divisions, value: row ? row.division : '' },
        { name: 'visa_type', label: '비자 유형', group: '분류', value: row ? row.visaType : '',
          hint: 'E-2 · H-1B 등. 파견 인원 관리에 씁니다.' },
        { name: 'site_id', label: '현장', type: 'select', group: '분류',
          options: o.sites, value: row ? row.siteId : '', hint: '비우면 소속 현장을 따라갑니다.' },

        { name: 'is_exempt', label: '연장수당 면제 (Exempt)', type: 'checkbox', group: '구분',
          value: row ? row.isExempt : false, checkboxLabel: '면제 대상',
          hint: '켜면 40시간을 넘겨도 연장수당이 붙지 않습니다. 관리직에만 쓰세요.' },
        { name: 'is_dispatched', label: '파견 인원', type: 'checkbox', group: '구분',
          value: row ? row.isDispatched : false, checkboxLabel: '한국 파견' },
        { name: 'effective_from', label: '적용 시작일', type: 'date', group: '구분',
          value: row ? row.effectiveFrom : '', colSpan: 2,
          hint: '단가를 올린 날. 비워도 됩니다.' }
      );

      u.formModal({
        title: row ? '임금 프로필 수정' : '임금 프로필 추가',
        subtitle: row && row.rateMissing
          ? '이 직원은 기준 임금이 비어 있습니다. 지금 채우면 다음 급여부터 반영됩니다.'
          : '급여 계산이 이 값을 그대로 씁니다.',
        saveLabel: row ? '수정' : '추가',
        fields: fields,
        onSave: function (v) {
          v.id = id || 0;
          delete v._who;
          return call('api_savePayProfile', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(row ? '임금 프로필을 수정했습니다.' : '임금 프로필을 추가했습니다.');
            state.options = null;
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function remove(id) {
    var u = ui();
    var row = state.rows.filter(function (r) { return r.id === id; })[0];
    u.confirmDanger({
      title: '임금 프로필을 삭제할까요?',
      body: (row ? row.employee : '이 직원') + ' 의 임금 프로필을 삭제합니다. ' +
        '급여 계산이 단가를 못 찾아 0 으로 나갑니다. 계속 쓰지 않을 직원이면 삭제 대신 인원관리에서 퇴사 처리하세요.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deletePayProfile', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('임금 프로필을 삭제했습니다.');
        state.options = null;
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    reload().catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
        ui().esc(e.message || '목록을 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminPayProfiles = {
    render: renderScreen,
    open: open,
    remove: remove,
    toggleMissing: toggleMissing,
    _state: state,
  };
})(window);
