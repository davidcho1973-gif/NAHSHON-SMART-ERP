/**
 * 이번 주 작업판 — 공종별로, 현장의 말로, 한 주.
 *
 * 정식 공정표(공정 관리)는 원청에 내는 문서다. 액티비티 80개에 코드와 선행·여유가 붙어
 * 있어 현장이 아침에 볼 물건이 아니다. 여기는 그 반대다: 소장이 월요일에 「배관 2명 —
 * 급탕 배관」 처럼 10~20줄을 적고, 날마다 됐다/안 됐다만 누른다.
 *
 * 화면이 지키는 것 셋:
 *  - 공종별로 묶는다(사장 지시). 공종 이름은 일일보고와 같은 낱말이다.
 *  - 상태는 버튼 한 번이다 — 진행중 / 완료 / 못함. 폼을 열지 않는다.
 *  - 지난주에 못 끝낸 줄은 「이월」 한 번으로 넘어온다. 같은 일이 계속 넘어오면 그게 보인다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = { data: null, week: null, busy: false };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function fmtWeek(d) {
    var s = new Date(d.weekStart + 'T00:00:00'), e = new Date(d.weekEnd + 'T00:00:00');
    var f = function (x) { return (x.getMonth() + 1) + '/' + x.getDate(); };
    return f(s) + ' – ' + f(e) + (d.isThisWeek ? ' (이번 주)' : '');
  }

  function shiftWeek(delta) {
    var base = new Date((state.data ? state.data.weekStart : new Date().toISOString().slice(0, 10)) + 'T00:00:00');
    base.setDate(base.getDate() + delta * 7);
    state.week = base.toISOString().slice(0, 10);
    reload();
  }

  function statusBadge(u, l) {
    var kind = l.status === 'done' ? 'ok' : l.status === 'blocked' ? 'danger' : l.status === 'doing' ? 'warn' : 'muted';
    return u.badge(l.statusLabel, kind);
  }

  /** 줄 하나 — 하는 일 · 인원 · 상태 · 버튼. 폰에서도 한 줄에 들어가야 한다. */
  function lineRow(u, l) {
    var canManage = state.data.canManage;
    var btn = function (label, status, kind) {
      if (l.status === status) return '';
      return u.rowButton(label, 'window.AdminWeekBoard.setStatus(' + l.id + ',\'' + status + '\')', kind);
    };

    var actions = '';
    if (canManage) {
      actions = btn('진행중', 'doing') + ' ' + btn('완료', 'done') + ' ' + btn('못함', 'blocked', 'danger') + ' ' +
        u.rowButton('수정', 'window.AdminWeekBoard.open(' + l.id + ')') + ' ' +
        u.rowButton('삭제', 'window.AdminWeekBoard.remove(' + l.id + ')', 'danger');
    }

    var sub = [];
    if (l.carried) sub.push('지난주에서 넘어옴');
    if (l.status === 'blocked' && l.reason) sub.push('못한 이유: ' + l.reason);
    if (l.note) sub.push(l.note);
    if (l.wbsCodes && l.wbsCodes.length) sub.push('공정표 ' + l.wbsCodes.join(', '));

    return '<div style="display:flex;align-items:flex-start;gap:12px;padding:10px 14px;border-top:1px solid var(--border-default);flex-wrap:wrap">' +
      '<div style="flex:1;min-width:220px">' +
        '<div style="font-size:15px;font-weight:600;' + (l.status === 'done' ? 'color:var(--text-tertiary);text-decoration:line-through' : '') + '">' +
          u.esc(l.task) + '</div>' +
        (sub.length ? '<div style="font-size:11px;color:var(--text-tertiary);margin-top:2px">' + u.esc(sub.join(' · ')) + '</div>' : '') +
      '</div>' +
      '<div style="width:60px;text-align:right;font-size:14px;font-weight:600">' + (l.headcount !== null ? u.esc(String(l.headcount)) + '명' : '<span style="color:var(--text-tertiary)">—</span>') + '</div>' +
      '<div style="width:70px">' + statusBadge(u, l) + '</div>' +
      '<div style="display:flex;gap:4px;flex-wrap:wrap">' + actions + '</div>' +
      '</div>';
  }

  /** 공종 한 묶음 — 머리에 «계획 인원 / 오늘 출근» 을 같이 둔다. 계획 옆에 현실. */
  function tradeGroup(u, g) {
    var head = '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--bg-base)">' +
      '<div style="font-size:14px;font-weight:700">' + u.esc(g.trade) + '</div>' +
      '<div style="font-size:12px;color:var(--text-secondary)">계획 ' + u.esc(String(g.plannedHeadcount)) + '명 · 오늘 출근 ' +
        '<b style="color:' + (g.presentToday ? 'var(--text-primary)' : 'var(--text-tertiary)') + '">' + g.presentToday + '명</b></div>' +
      '</div>';
    return '<div style="border:1px solid var(--border-default);border-radius:12px;overflow:hidden;margin-bottom:12px;background:var(--bg-surface)">' +
      head + g.lines.map(function (l) { return lineRow(u, l); }).join('') + '</div>';
  }

  function render() {
    var u = ui();
    var d = state.data;

    var siteSel = '';
    // 고를 현장이 둘 이상이거나, 아직 못 골라서 화면이 비어 있으면 선택기를 보여 준다.
    if (d.sites && (d.sites.length > 1 || (d.noSite && d.sites.length))) {
      siteSel = '<select onchange="window.AdminWeekBoard.pickSite(this.value)" style="padding:7px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px">' +
        (d.noSite ? '<option value="">— 현장 고르기 —</option>' : '') +
        d.sites.map(function (s) { return '<option value="' + u.esc(s.value) + '"' + (String(s.value) === String(d.siteId) ? ' selected' : '') + '>' + u.esc(s.label) + '</option>'; }).join('') + '</select>';
    }

    if (d.noSite) {
      return u.pageHeader('이번 주 작업판', '현장을 먼저 고르세요.', siteSel) +
        '<div style="padding:40px;text-align:center;color:var(--text-tertiary)">위에서 현장을 고르면 그 현장의 작업판이 나옵니다.</div>';
    }

    var notes = [d.total + '줄'];
    if (d.doneCount) notes.push(d.doneCount + '줄 완료');
    if (d.blockedCount) notes.push(d.blockedCount + '줄 못함');

    var actions =
      '<div style="display:flex;align-items:center;gap:6px">' +
        u.rowButton('◀', 'window.AdminWeekBoard.shiftWeek(-1)') +
        '<span style="font-size:13px;font-weight:700;min-width:130px;text-align:center">' + u.esc(fmtWeek(d)) + '</span>' +
        u.rowButton('▶', 'window.AdminWeekBoard.shiftWeek(1)') +
      '</div>' +
      siteSel +
      (d.canManage && d.leftoverCount
        ? u.rowButton('지난주 못한 ' + d.leftoverCount + '줄 이월', 'window.AdminWeekBoard.carryOver()')
        : '') +
      (d.canManage ? u.primaryButton('줄 추가', 'window.AdminWeekBoard.open()', 'plus') : '');

    var body = d.groups.length
      ? d.groups.map(function (g) { return tradeGroup(u, g); }).join('')
      : '<div style="padding:40px;text-align:center;color:var(--text-tertiary)">이번 주에 적힌 일이 없습니다. ' +
        (d.canManage ? '「줄 추가」 로 공종별로 이번 주 할 일을 적으세요.' : '') + '</div>';

    return u.pageHeader(
      '이번 주 작업판',
      d.site + ' · 공종별로 이번 주 하는 일과 인원. 됐다/안 됐다만 누르면 됩니다. — ' + notes.join(' · '),
      actions
    ) + body;
  }

  function reload() {
    return call('api_getWeekBoard', [state.week]).then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' + ui().esc(res.error || '작업판을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.data = res;
      paint(render());
    });
  }

  function pickSite(id) {
    // 위쪽 현장 선택기와 같은 값을 쓴다 — 화면마다 다른 현장을 보고 있으면 헷갈린다.
    if (!id) return;
    var code = null;
    Object.keys(global.SITE_DB_IDS || {}).forEach(function (k) { if (String(global.SITE_DB_IDS[k]) === String(id)) code = k; });
    if (code && typeof global.setProjectContext === 'function') { global.setProjectContext(code); }
    else if (code) { global.currentSiteId = code; }
    reload();
  }

  function findLine(id) {
    var out = null;
    (state.data.groups || []).forEach(function (g) { g.lines.forEach(function (l) { if (l.id === id) out = l; }); });
    return out;
  }

  function open(id) {
    var u = ui();
    var d = state.data;
    var line = id ? findLine(id) : null;
    var tradeOpts = (d.tradeOptions || []).map(function (t) { return { value: t, label: t }; });
    if (line && line.trade && d.tradeOptions.indexOf(line.trade) === -1) tradeOpts.unshift({ value: line.trade, label: line.trade });
    tradeOpts.push({ value: '__other__', label: '직접 적기…' });

    u.formModal({
      title: line ? '줄 수정' : '이번 주 할 일 추가',
      subtitle: fmtWeek(d) + ' · ' + d.site + ' — 현장에서 부르는 말 그대로 적으세요. 코드가 아닙니다.',
      saveLabel: line ? '수정' : '추가',
      fields: [
        { name: 'trade', label: '공종', type: 'select', required: true, group: '누가',
          options: tradeOpts, value: line ? line.trade : (tradeOpts.length > 1 ? tradeOpts[0].value : ''),
          hint: '직원 공종과 같은 이름을 씁니다. 목록에 없으면 「직접 적기」.' },
        { name: 'tradeOther', label: '공종 직접 적기', group: '누가', value: '',
          hint: '위에서 「직접 적기」 를 골랐을 때만.' },
        { name: 'task', label: '하는 일', required: true, colSpan: 2, group: '무엇을',
          value: line ? line.task : '', hint: '예) 급탕 배관 / 후드 배선 / 그리스 트랩 설치' },
        { name: 'headcount', label: '인원', type: 'number', group: '무엇을',
          value: line && line.headcount !== null ? line.headcount : '' },
        { name: 'note', label: '메모', group: '무엇을', value: line ? line.note : '' },
        { name: 'wbsCodes', label: '공정표 코드 (선택)', colSpan: 2, group: '정식 공정표에 붙이기',
          value: line ? (line.wbsCodes || []).join(', ') : '',
          hint: '비워도 됩니다. 적어 두면 이 줄이 완료될 때 정식 공정표의 그 작업도 완료로 넘어갑니다.' },
      ],
      onSave: function (v) {
        var trade = v.trade === '__other__' ? String(v.tradeOther || '').trim() : v.trade;
        if (!trade) return { success: false, errors: { trade: '공종을 고르거나 적으세요.' } };
        var payload = { id: id || 0, siteId: d.siteId, week: d.weekStart, trade: trade, task: v.task,
          headcount: v.headcount, note: v.note, wbsCodes: v.wbsCodes };
        return call('api_saveWeekBoardLine', [payload]).then(function (res) {
          if (res.success === false) return res;
          if (res.warning) u.toast(res.warning, 'error');
          else u.toast(line ? '고쳤습니다.' : '적었습니다.');
          return reload().then(function () { return { success: true }; });
        });
      },
    });
  }

  function setStatus(id, status) {
    var u = ui();
    var line = findLine(id);
    var go = status === 'blocked'
      ? u.formModal({
          title: '못한 이유',
          subtitle: (line ? line.task : '') + ' — 한 줄이면 됩니다. 다음 주 월요일에 이걸 읽습니다.',
          saveLabel: '못함으로 표시',
          fields: [{ name: 'reason', label: '이유', colSpan: 2, value: '', hint: '예) 자재 미입고 / 앞 공정 안 끝남 / 인원 부족' }],
        }).then(function (v) { return v ? { ok: true, reason: v.reason } : { ok: false }; })
      : Promise.resolve({ ok: true, reason: null });

    go.then(function (r) {
      if (!r.ok) return;
      return call('api_setWeekBoardStatus', [id, status, r.reason]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '바꾸지 못했습니다.', 'error'); return; }
        if (res.warning) u.toast(res.warning, 'error');
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function remove(id) {
    var u = ui();
    var line = findLine(id);
    u.confirmDanger({
      title: '이 줄을 지울까요?',
      body: (line ? line.task : '이 줄') + ' 을(를) 작업판에서 지웁니다. 못한 일이면 지우지 말고 「못함」 으로 두세요 — 다음 주에 이월됩니다.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteWeekBoardLine', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '지우지 못했습니다.', 'error'); return; }
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function carryOver() {
    var u = ui();
    call('api_carryOverWeekBoard', [state.data.weekStart]).then(function (res) {
      if (res.success === false) { u.toast(res.error || '이월하지 못했습니다.', 'error'); return; }
      u.toast(res.moved ? res.moved + '줄을 이번 주로 넘겼습니다.' : '넘길 줄이 없습니다.');
      return reload();
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    reload().catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' + ui().esc(e.message || '작업판을 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminWeekBoard = {
    render: renderScreen,
    open: open,
    setStatus: setStatus,
    remove: remove,
    carryOver: carryOver,
    shiftWeek: shiftWeek,
    pickSite: pickSite,
    _state: state,
  };
})(window);
