/**
 * 출퇴근 기록 수정 — Filament AttendanceLogResource 를 SPA 로 옮긴 것.
 *
 * 이 표는 급여의 근거 자료다. 그래서 목록이 답해야 하는 질문은 "누가 언제 찍었나" 가
 * 아니라 "고쳐야 할 게 있나" 다. 대기중·반려 건과 손댄 적 있는 건을 먼저 눈에 띄게 한다.
 *
 * <b>한 사람의 하루가 한 줄</b>이다. 출근과 퇴근이 따로 올라오면 같은 사람의 두 끝이
 * 목록 여기저기에 흩어져, 「몇 시간 일했나」 를 눈으로 짝지어야 한다 — 그게 이 표를
 * 여는 이유인데도 그렇다. 나란히 놓으면 빠진 퇴근도 빈 칸으로 그대로 보인다.
 *
 * 시각은 <b>현장 시계</b>다. 현장 칸에 시계 이름(EDT 등)을 같이 적는다 — 서버 시계로
 * 보여 주던 때는 사바나 아침 7시 50분이 04:50 으로 떴고, 그걸 알아채는 데 하루가 걸렸다.
 *
 * 기본 기간은 최근 7일. 전체를 다 불러오면 수천 건이라 정작 오늘 문제를 못 찾는다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = { rows: [], options: null, filters: null, canManage: false, canDelete: false };

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
      sel('at-status', '상태', o.filterStatuses || o.statuses, f.status, '전체') +
      sel('at-site', '현장', o.sites, f.siteId, '전체') +
      '<button type="button" onclick="window.AdminAttendance.applyFilters()" style="padding:8px 16px;border-radius:8px;border:none;' +
      'background:var(--brand-primary);color:#fff;font-size:13px;font-weight:600;cursor:pointer">조회</button>' +
      '</div>';
  }

  /** 한 줄(하루) 안의 모든 기록 — 출근·퇴근·밀려난 것까지. */
  function eventsOf(row) {
    return [row.clockIn, row.clockOut].concat(row.extras || []).filter(Boolean);
  }

  function findEvent(id) {
    for (var i = 0; i < state.rows.length; i++) {
      var hit = eventsOf(state.rows[i]).filter(function (e) { return e.id === id; })[0];
      if (hit) return { row: state.rows[i], event: hit };
    }
    return null;
  }

  /** 출근/퇴근 칸 하나. 시각 + 그 기록의 상태. */
  function timeCell(row, e, what) {
    var u = ui();
    if (!e) {
      return '<span style="color:var(--text-tertiary)">—</span>' +
        (state.canManage
          ? '<div style="margin-top:3px">' + u.rowButton(what + ' 추가',
            'window.AdminAttendance.addFor(\'' + row.key + '\',\'' + (what === '출근' ? 'clock_in' : 'clock_out') + '\')') + '</div>'
          : '');
    }

    var time = '<span style="font-family:var(--font-mono,monospace);font-size:14px;font-weight:600' +
      (e.deleted ? ';text-decoration:line-through;color:var(--text-tertiary)' : '') + '">' + u.esc(e.time || '—') + '</span>';

    var note = '';
    if (e.deleted) {
      note = ' ' + u.badge('삭제됨', 'danger');
    } else if (e.status !== 'approved') {
      note = ' ' + u.badge(e.statusLabel, e.status === 'pending' ? 'warn' : 'danger');
    }

    var trail = '<div style="font-size:11px;color:var(--text-tertiary);margin-top:2px">' + u.esc(e.sourceLabel || '') +
      // 손댄 적 있는 건은 표시한다 — 급여 담당이 되짚을 단서다.
      (e.editCount ? ' · <a href="#" onclick="window.AdminAttendance.showHistory(' + e.id + ');return false" ' +
        'style="color:var(--text-tertiary);text-decoration:underline">수정 ' + e.editCount + '회</a>' : '') +
      '</div>';

    return time + note + trail;
  }

  /** 한 기록에 대해 할 수 있는 일들. */
  function eventActions(e, what) {
    var u = ui();
    if (!e || !state.canManage) return '';

    // 지워진 기록에서 할 수 있는 것은 되살리기뿐이다. 승인·수정 버튼을 같이 두면
    // 눌러 보고 거절당하게 된다.
    if (e.deleted) {
      return state.canDelete
        ? '<div style="margin-bottom:3px"><span style="font-size:11px;color:var(--text-tertiary);margin-right:5px">' + what + '</span>' +
          u.rowButton('되살리기', 'window.AdminAttendance.restore(' + e.id + ')') + '</div>'
        : '';
    }

    var out = '<span style="font-size:11px;color:var(--text-tertiary);margin-right:5px">' + what + '</span>';
    if (e.status !== 'approved') out += u.rowButton('승인', 'window.AdminAttendance.setStatus(' + e.id + ',"approved")') + ' ';
    out += u.rowButton('수정', 'window.AdminAttendance.openForm(' + e.id + ')') + ' ';
    if (e.status !== 'rejected') out += u.rowButton('반려', 'window.AdminAttendance.setStatus(' + e.id + ',"rejected")') + ' ';
    // 삭제는 관리자만. 급여 근거를 목록에서 빼는 일이다.
    if (state.canDelete) out += u.rowButton('삭제', 'window.AdminAttendance.remove(' + e.id + ')', 'danger');

    return '<div style="margin-bottom:3px">' + out + '</div>';
  }

  function render() {
    var u = ui();
    var rows = state.rows;
    var all = rows.reduce(function (acc, r) { return acc.concat(eventsOf(r)); }, []);
    var pending = all.filter(function (e) { return e.status === 'pending'; }).length;
    var missing = rows.filter(function (r) { return r.clockIn && !r.clockOut; }).length;

    var notes = [rows.length + '명·일', all.length + '건'];
    if (pending) notes.push(pending + '건 대기중');
    if (missing) notes.push(missing + '건 퇴근 미기록');

    var actions = state.canManage
      ? u.primaryButton('기록 추가', 'window.AdminAttendance.openForm()', 'plus')
      : '';

    return u.pageHeader(
      '출퇴근 기록',
      '급여의 근거가 되는 기록입니다. 한 사람의 하루가 한 줄이고, 시각은 현장 시계 기준입니다. ' +
      '고치면 누가 무엇을 바꿨는지 남습니다. — ' + notes.join(' · '),
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
        { key: 'date', label: '날짜', width: '105px' },
        {
          key: 'clockIn', label: '출근', width: '120px',
          render: function (r) { return timeCell(r, r.clockIn, '출근'); },
        },
        {
          key: 'clockOut', label: '퇴근', width: '120px',
          render: function (r) { return timeCell(r, r.clockOut, '퇴근'); },
        },
        {
          key: 'workedLabel', label: '근무 (급여)', width: '130px',
          render: function (r) {
            // 한쪽만 있는 날은 «0시간» 이 아니라 «모름» 이다 — 그 차이가 임금이다.
            if (!r.workedLabel) return '<span style="color:var(--text-tertiary)">—</span>';
            // 점심을 빼고도 말하지 않으면 «내 시간이 한 시간 없어졌다» 가 된다.
            var notes = [r.breakLabel, r.overtimeLabel].filter(Boolean).join(' · ');
            return '<span style="font-weight:600">' + u.esc(r.workedLabel) + '</span>' +
              (notes ? '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(notes) + '</div>' : '');
          },
        },
        {
          key: 'site', label: '현장', width: '150px',
          render: function (r) {
            return u.esc(r.site || '—') +
              (r.zone ? '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.zone) + ' 기준</div>' : '') +
              // 그 현장의 근무 규칙을 같이 적는다 — 「근무」 칸의 숫자가 어떻게 나온
              // 것인지 그 자리에서 알 수 있어야, 매번 묻지 않는다.
              (r.rulesLabel ? '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.rulesLabel) + '</div>' : '');
          },
        },
        {
          key: 'act', label: '', align: 'right', width: '300px',
          render: function (r) {
            var out = eventActions(r.clockIn, '출근') + eventActions(r.clockOut, '퇴근');
            (r.extras || []).forEach(function (e) {
              out += eventActions(e, e.eventTypeLabel + ' (중복)');
            });
            return out;
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
      state.canDelete = !!res.canDelete;
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

  function isManualSource(o, source) {
    return (o.sources || []).some(function (x) { return String(x.value) === String(source); });
  }

  /** 고를 수 있는 방식 + (고치는 중이면) 그 기록 자신의 방식. */
  function sourceOptions(o, ev) {
    var list = (o.sources || []).slice();
    if (ev && ev.source && !isManualSource(o, ev.source)) {
      list.unshift({ value: ev.source, label: ev.sourceLabel + ' (자동 기록)' });
    }
    return list;
  }

  /** 빠진 쪽(대개 퇴근)을 그 사람·그날로 바로 넣는다. */
  function addFor(key, eventType) {
    var row = state.rows.filter(function (r) { return r.key === key; })[0];
    if (!row) return;
    openForm(null, {
      employeeId: row.employeeId, siteId: row.siteId, eventType: eventType,
      date: row.date, employee: row.employee, zone: row.zone,
    });
  }

  function openForm(id, prefill) {
    var u = ui();
    var found = id ? findEvent(id) : null;
    var ev = found ? found.event : null;
    var row = found ? found.row : null;
    var p = prefill || {};

    loadOptions().then(function (o) {
      u.formModal({
        title: ev ? '출퇴근 기록 수정' : '출퇴근 기록 추가',
        subtitle: ev
          ? '급여 근거 자료입니다. 시각은 ' + (row && row.zone ? row.zone + ' (현장 시계)' : '현장 시계') +
            ' 기준입니다. 바뀐 내용은 이력에 남습니다.'
          : (p.date
            ? (p.employee || '') + ' · ' + p.date + ' 의 ' + (p.eventType === 'clock_in' ? '출근' : '퇴근') +
              ' 기록을 넣습니다. 시각은 ' + (p.zone ? p.zone + ' (현장 시계)' : '현장 시계') + ' 기준으로 적으세요.'
            : '누락된 기록을 직접 넣습니다. 시각은 현장 시계 기준이고, 기록 방식은 "수기 입력" 으로 남습니다.'),
        saveLabel: ev ? '수정' : '추가',
        fields: [
          { name: 'employeeId', label: '직원', type: 'select', required: true, group: '대상',
            options: o.employees, value: ev ? row.employeeId : (p.employeeId || ''), colSpan: 2 },
          { name: 'siteId', label: '현장', type: 'select', group: '대상',
            options: o.sites, value: ev ? ev.siteId : (p.siteId || ''),
            hint: '비우면 직원의 소속 현장을 씁니다. 시각은 이 현장의 시계로 읽습니다.' },
          { name: 'eventType', label: '구분', type: 'select', required: true, group: '대상',
            options: o.eventTypes, value: ev ? ev.eventType : (p.eventType || 'clock_in') },

          { name: 'eventAt', label: '기록 시각 (현장 시계)', type: 'datetime-local', required: true, group: '기록',
            // 없는 쪽을 넣을 때 시각을 미리 채우지 않는다. 채워 두면 그대로 저장되고,
            // 그건 아무도 찍지 않은 시각이 임금이 되는 일이다.
            value: ev ? String(ev.eventAt || '').replace(' ', 'T').slice(0, 16) : '',
            hint: '현장 시계로 적으세요. 날짜도 현장 시간대 기준으로 계산됩니다.' },
          { name: 'status', label: '상태', type: 'select', required: true, group: '기록',
            options: o.statuses, value: ev ? ev.status : 'approved' },
          { name: 'source', label: '기록 방식', type: 'select', group: '기록',
            // 자동으로 찍힌 기록(게이트·위치·자동마감)은 고를 수 없지만, 고치려고 연
            // 기록의 출처는 그대로 보여야 한다 — 안 보이면 저장할 때 바뀐 줄 안다.
            options: sourceOptions(o, ev), value: ev ? ev.source : 'manual', colSpan: 2,
            hint: ev && ev.source && !isManualSource(o, ev.source)
              ? '자동으로 찍힌 기록입니다. 기록 방식은 그대로 유지됩니다.' : '' },
          { name: 'notes', label: '비고', type: 'textarea', colSpan: 2, group: '기록',
            value: ev ? ev.notes : '',
            hint: '왜 고쳤는지 적어두면 급여 정산 때 다시 묻지 않아도 됩니다.' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          return call('api_saveAttendanceLog', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(ev ? '기록을 수정했습니다.' : '기록을 추가했습니다.');
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  /** 「누구의 무슨 기록인가」 — 확인창이 무엇을 건드리는지 말해 준다. */
  function describe(id) {
    var f = findEvent(id);
    if (!f) return '이 기록';
    return f.row.employee + ' · ' + f.row.date + ' ' + f.event.eventTypeLabel + ' ' + (f.event.time || '');
  }

  function setStatus(id, status) {
    var u = ui();
    var who = describe(id);
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

  function remove(id) {
    var u = ui();
    var who = describe(id);

    // 반려로 충분한 경우가 대부분이다. 반려는 급여에서 빠지면서도 "그날 왔었다" 는
    // 사실은 남긴다. 그래서 삭제 창에서 그 선택지를 먼저 말해 준다.
    u.confirmDanger({
      title: '이 기록을 삭제할까요?',
      body: who + ' 기록을 목록과 급여 계산에서 뺍니다. 잘못 찍힌 기록이라면 '
        + '"반려" 로 두는 편이 낫습니다 — 그날 왔었다는 사실은 남습니다. '
        + '삭제해도 기록 자체는 보관되며, 상태 필터의 "삭제됨" 에서 되살릴 수 있습니다.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteAttendanceLog', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('삭제했습니다. 상태 필터의 "삭제됨" 에서 되살릴 수 있습니다.');
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function restore(id) {
    var u = ui();
    call('api_restoreAttendanceLog', [id]).then(function (res) {
      if (res.success === false) { u.toast(res.error || '되살리지 못했습니다.', 'error'); return; }
      u.toast('되살렸습니다. 그날 근무시간도 함께 돌아옵니다.');
      return reload();
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
    addFor: addFor,
    setStatus: setStatus,
    remove: remove,
    restore: restore,
    showHistory: showHistory,
    _state: state,
  };
})(window);
