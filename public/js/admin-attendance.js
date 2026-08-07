/**
 * 출퇴근 기록 수정 — Filament AttendanceLogResource 를 SPA 로 옮긴 것.
 *
 * 이 표는 급여의 근거 자료다. 그래서 목록이 답해야 하는 질문은 "누가 언제 찍었나" 가
 * 아니라 "고쳐야 할 게 있나" 다. 대기중·반려 건과 손댄 적 있는 건을 먼저 눈에 띄게 한다.
 *
 * 기본 기간은 최근 7일. 전체를 다 불러오면 수천 건이라 정작 오늘 문제를 못 찾는다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = { rows: [], options: null, filters: null, canManage: false };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  function ymd(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function defaultFilters() {
    var until = new Date();
    var from = new Date();
    from.setDate(from.getDate() - 6);
    return { from: ymd(from), until: ymd(until), status: '', siteId: '' };
  }

  function filterBar() {
    var u = ui();
    var f = state.filters;
    var o = state.options || {};
    var sel = function (id, label, opts, val, blank) {
      return '<div><label for="' + id + '" style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px">' +
        u.esc(label) + '</label><select id="' + id + '" style="padding:7px 10px;border-radius:8px;border:1px solid var(--border-default);' +
        'background:var(--bg-base);color:var(--text-primary);font-size:13px;min-width:130px">' +
        '<option value="">' + u.esc(blank) + '</option>' +
        (opts || []).map(function (x) {
          return '<option value="' + u.esc(x.value) + '"' + (String(x.value) === String(val) ? ' selected' : '') + '>' + u.esc(x.label) + '</option>';
        }).join('') + '</select></div>';
    };
    var date = function (id, label, val) {
      return '<div><label for="' + id + '" style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px">' +
        u.esc(label) + '</label><input type="date" id="' + id + '" value="' + u.esc(val) + '" ' +
        'style="padding:7px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px"></div>';
    };

    return '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;padding:12px;' +
      'background:var(--bg-surface);border:1px solid var(--border-default);border-radius:12px">' +
      date('at-from', '시작일', f.from) + date('at-until', '종료일', f.until) +
      sel('at-status', '상태', o.statuses, f.status, '전체') +
      sel('at-site', '현장', o.sites, f.siteId, '전체') +
      '<button type="button" onclick="window.AdminAttendance.applyFilters()" style="padding:8px 16px;border-radius:8px;border:none;' +
      'background:var(--brand-primary);color:#fff;font-size:13px;font-weight:600;cursor:pointer">조회</button>' +
      '</div>';
  }

  function render() {
    var u = ui();
    var rows = state.rows;
    var pending = rows.filter(function (r) { return r.status === 'pending'; }).length;
    var edited = rows.filter(function (r) { return r.editCount > 0; }).length;

    var notes = [rows.length + '건'];
    if (pending) notes.push(pending + '건 대기중');
    if (edited) notes.push(edited + '건 수정됨');

    var actions = state.canManage
      ? u.primaryButton('기록 추가', 'window.AdminAttendance.openForm()', 'plus')
      : '';

    return u.pageHeader(
      '출퇴근 기록',
      '급여의 근거가 되는 기록입니다. 고치면 누가 무엇을 바꿨는지 남습니다. — ' + notes.join(' · '),
      actions
    ) + filterBar() + u.table({
      id: 'at-tbl',
      searchPlaceholder: '직원 이름 · 사번 검색',
      emptyText: '이 기간에 기록이 없습니다.',
      columns: [
        {
          key: 'employee', label: '직원', width: '170px',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.employee || '—') + '</div>' +
              (r.employeeNumber ? '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.employeeNumber) + '</div>' : '');
          },
        },
        { key: 'date', label: '날짜', width: '110px' },
        {
          key: 'eventTime', label: '시각', width: '90px',
          render: function (r) { return '<span style="font-family:var(--font-mono,monospace)">' + u.esc(r.eventTime || '—') + '</span>'; },
        },
        {
          key: 'eventTypeLabel', label: '구분', width: '80px',
          render: function (r) { return u.badge(r.eventTypeLabel, r.eventType === 'clock_in' ? 'ok' : 'warn'); },
        },
        { key: 'sourceLabel', label: '방식', width: '90px' },
        { key: 'site', label: '현장', width: '110px' },
        {
          key: 'statusLabel', label: '상태', width: '100px',
          render: function (r) {
            var kind = r.status === 'approved' ? 'ok' : r.status === 'pending' ? 'warn' : 'danger';
            return u.badge(r.statusLabel, kind) +
              // 손댄 적 있는 건은 표시한다 — 급여 담당이 되짚을 단서다.
              (r.editCount ? ' <a href="#" onclick="window.AdminAttendance.showHistory(' + r.id + ');return false" ' +
                'style="font-size:11px;color:var(--text-tertiary);text-decoration:underline">수정 ' + r.editCount + '회</a>' : '');
          },
        },
        {
          key: 'act', label: '', align: 'right', width: '190px',
          render: function (r) {
            if (!state.canManage) return '';
            var out = '';
            if (r.status !== 'approved') out += u.rowButton('승인', 'window.AdminAttendance.setStatus(' + r.id + ',"approved")') + ' ';
            if (r.status !== 'rejected') out += u.rowButton('반려', 'window.AdminAttendance.setStatus(' + r.id + ',"rejected")') + ' ';
            return out + u.rowButton('수정', 'window.AdminAttendance.openForm(' + r.id + ')');
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

  function reload() {
    return call('api_getAttendanceLogs', [state.filters]).then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '기록을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.rows = res.rows || [];
      state.canManage = !!res.canManage;
      paint(render());
      ui().bindSearch('at-tbl');
    });
  }

  function loadOptions() {
    if (state.options) return Promise.resolve(state.options);
    return call('api_getAttendanceLogOptions').then(function (res) {
      if (res.success === false) throw new Error(res.error || '선택지를 불러오지 못했습니다.');
      state.options = res;
      return res;
    });
  }

  function applyFilters() {
    var g = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
    state.filters = { from: g('at-from'), until: g('at-until'), status: g('at-status'), siteId: g('at-site') };
    reload();
  }

  function openForm(id) {
    var u = ui();
    var row = id ? state.rows.filter(function (r) { return r.id === id; })[0] : null;

    loadOptions().then(function (o) {
      u.formModal({
        title: row ? '출퇴근 기록 수정' : '출퇴근 기록 추가',
        subtitle: row
          ? '급여 근거 자료입니다. 바뀐 내용은 이력에 남습니다.'
          : '누락된 기록을 직접 넣습니다. 기록 방식은 "수기 입력" 으로 남습니다.',
        saveLabel: row ? '수정' : '추가',
        fields: [
          { name: 'employeeId', label: '직원', type: 'select', required: true, group: '대상',
            options: o.employees, value: row ? row.employeeId : '', colSpan: 2 },
          { name: 'siteId', label: '현장', type: 'select', group: '대상',
            options: o.sites, value: row ? row.siteId : '',
            hint: '비우면 직원의 소속 현장을 씁니다.' },
          { name: 'eventType', label: '구분', type: 'select', required: true, group: '대상',
            options: o.eventTypes, value: row ? row.eventType : 'clock_in' },

          { name: 'eventAt', label: '기록 시각', type: 'datetime-local', required: true, group: '기록',
            value: row ? String(row.eventAt || '').replace(' ', 'T').slice(0, 16) : '',
            hint: '날짜는 현장 시간대 기준으로 자동 계산됩니다.' },
          { name: 'status', label: '상태', type: 'select', required: true, group: '기록',
            options: o.statuses, value: row ? row.status : 'approved' },
          { name: 'source', label: '기록 방식', type: 'select', group: '기록',
            options: o.sources, value: row ? row.source : 'manual', colSpan: 2 },
          { name: 'notes', label: '비고', type: 'textarea', colSpan: 2, group: '기록',
            value: row ? row.notes : '',
            hint: '왜 고쳤는지 적어두면 급여 정산 때 다시 묻지 않아도 됩니다.' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          return call('api_saveAttendanceLog', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(row ? '기록을 수정했습니다.' : '기록을 추가했습니다.');
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function setStatus(id, status) {
    var u = ui();
    var row = state.rows.filter(function (r) { return r.id === id; })[0];
    var who = row ? row.employee + ' · ' + row.date + ' ' + row.eventTime : '이 기록';
    var go = status === 'approved'
      ? Promise.resolve(true)
      : u.confirmDanger({
          title: '기록을 반려할까요?',
          body: who + ' 기록을 반려합니다. 반려된 기록은 급여 계산에서 빠집니다.',
          confirmLabel: '반려',
        });

    go.then(function (ok) {
      if (!ok) return;
      return call('api_setAttendanceLogStatus', [id, status]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '상태를 바꾸지 못했습니다.', 'error'); return; }
        u.toast(status === 'approved' ? '승인했습니다.' : '반려했습니다.');
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  var FIELD_KO = {
    event_at: '시각', event_type: '구분', status: '상태', attendance_date: '날짜',
    employee_id: '직원', site_id: '현장', notes: '비고',
  };

  function showHistory(id) {
    var u = ui();
    call('api_getAttendanceLogHistory', [id]).then(function (res) {
      if (res.success === false) { u.toast(res.error || '이력을 불러오지 못했습니다.', 'error'); return; }
      var items = (res.edits || []).map(function (e) {
        var ch = Object.keys(e.changes || {}).map(function (k) {
          var c = e.changes[k];
          if (c && typeof c === 'object' && 'from' in c) {
            return '<div style="font-size:12px;color:var(--text-secondary);margin-top:2px">' +
              u.esc(FIELD_KO[k] || k) + ': <s style="color:var(--text-tertiary)">' + u.esc(c.from) + '</s> → <b>' + u.esc(c.to) + '</b></div>';
          }
          return '';
        }).join('');
        return '<div style="padding:10px 0;border-bottom:1px solid var(--border-default)">' +
          '<div style="font-size:12px;color:var(--text-tertiary)">' + u.esc(e.at) + ' · ' + u.esc(e.by || '알 수 없음') + '</div>' +
          (ch || '<div style="font-size:12px;color:var(--text-tertiary);margin-top:2px">기록 생성</div>') + '</div>';
      }).join('');

      var wrap = document.createElement('div');
      wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:20px';
      wrap.innerHTML = '<div role="dialog" aria-modal="true" style="background:var(--bg-surface);border:1px solid var(--border-default);' +
        'border-radius:14px;max-width:560px;width:100%;max-height:80vh;display:flex;flex-direction:column">' +
        '<div style="padding:18px 22px;border-bottom:1px solid var(--border-default);display:flex;justify-content:space-between;align-items:center">' +
        '<div style="font-size:16px;font-weight:700;color:var(--text-primary)">수정 이력</div>' +
        '<button type="button" data-x="close" aria-label="닫기" style="background:none;border:none;color:var(--text-secondary);font-size:22px;cursor:pointer">×</button></div>' +
        '<div style="padding:8px 22px 22px;overflow-y:auto">' + (items || '<div style="padding:20px 0;color:var(--text-tertiary);font-size:13px">이력이 없습니다.</div>') + '</div></div>';
      wrap.addEventListener('click', function (e) {
        if (e.target === wrap || (e.target.getAttribute && e.target.getAttribute('data-x') === 'close')) wrap.remove();
      });
      document.body.appendChild(wrap);
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function renderScreen() {
    if (!state.filters) state.filters = defaultFilters();
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    loadOptions()
      .then(reload)
      .catch(function (e) {
        paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
          ui().esc(e.message || '기록을 불러오지 못했습니다.') + '</div>');
      });
    return '';
  }

  global.AdminAttendance = {
    render: renderScreen,
    applyFilters: applyFilters,
    openForm: openForm,
    setStatus: setStatus,
    showHistory: showHistory,
    _state: state,
  };
})(window);
