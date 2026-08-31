/**
 * 일일 보고 — 아침 작업계획서와 저녁 마감보고서를 ERP 안에서 쓰고, 정해진 사람에게 보낸다.
 *
 * 이 화면의 유일한 설계 원칙: <b>빈 종이를 주지 않는다.</b>
 * 열면 ERP 가 아는 것(안전 작업카드·장비 대장·전날 마감·출역 인원)이 이미 채워져 있고,
 * 현장소장은 작업 내용·위험요인·특이사항만 손보면 된다. 빈 양식을 주면 아무도 안 쓴다.
 *
 * 자동으로 채운 칸은 초록 점으로 표시한다 — 사람이 쓴 것과 기계가 채운 것을 눈으로
 * 가릴 수 있어야 나중에 "이 숫자 누가 넣었냐" 를 따질 수 있다.
 *
 * 위험요인(PTP/JHA)만은 자동으로 채우지 않는다. 그날 그 작업을 보고 사람이 판단해야
 * 하는 것이고, 기계가 채운 위험요인은 아무도 읽지 않는다.
 */
(function (global) {
  'use strict';

  var A = null;
  function ui() { if (!A) A = global.AdminUI; return A; }

  var state = {
    tab: 'plan',          // plan | closing
    date: null,
    plan: null,           // api_getDailyPlan 응답
    closing: null,        // api_getDailyClosing 응답
    dispatches: null,
    dirty: false,
  };

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      if (res.success === false) throw new Error(res.error || '요청이 거부되었습니다.');
      return res;
    });
  }

  function paint(html) { document.getElementById('page-container').innerHTML = html; }

  function today() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  /* ══════════════════════ 진입 ══════════════════════ */

  function render() {
    if (!state.date) state.date = today();
    paint('<div style="padding:60px;text-align:center;color:var(--text-tertiary)">일일 보고를 불러오는 중…</div>');
    load().then(draw).catch(function (e) {
      paint('<div style="padding:60px;text-align:center;color:var(--status-danger,#dc2626)">' + ui().esc(e.message) + '</div>');
    });
  }

  function load() {
    return Promise.all([
      call('api_getDailyPlan', [state.date]),
      call('api_getReportDispatches', [state.date]),
    ]).then(function (r) {
      state.plan = r[0];
      state.dispatches = r[1];
      state.dirty = false;
    });
  }

  function setDate(v) {
    if (!v) return;
    if (state.dirty && !global.confirm('저장하지 않은 내용이 있습니다. 날짜를 바꾸면 사라집니다. 계속할까요?')) {
      draw();
      return;
    }
    state.date = v;
    render();
  }

  function setTab(t) {
    state.tab = t;
    if (t === 'closing' && !state.closing) { loadClosing().then(draw); return; }
    draw();
  }

  /* ══════════════════════ 그리기 ══════════════════════ */

  function draw() {
    var u = ui();
    var p = state.plan || {};
    var mailNote = (state.dispatches && state.dispatches.mailReady === false)
      ? state.dispatches.mailNote : null;

    var head = u.pageHeader(
      '일일 보고',
      (p.site && p.site.name ? p.site.name + ' · ' : '') + '아침 작업계획서와 저녁 마감보고서를 여기서 쓰고 원청에 보냅니다.',
      u.rowButton('수신처 관리', 'AdminDailyReport.openRecipients()') +
      u.rowButton('발송 이력', 'AdminDailyReport.openHistory()')
    );

    var bar =
      '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">' +
        '<input type="date" value="' + u.esc(state.date) + '" onchange="AdminDailyReport.setDate(this.value)" ' +
          'style="padding:8px 11px;border-radius:8px;border:1px solid var(--border-default);' +
          'background:var(--bg-base);color:var(--text-primary);font-size:13px;font-family:inherit">' +
        tabBtn('plan', '작업계획서', '아침') +
        tabBtn('closing', '마감보고서', '저녁') +
      '</div>';

    var note = mailNote
      ? '<div style="padding:10px 14px;margin-bottom:14px;border-radius:8px;background:var(--bg-panel);' +
        'border:1px solid var(--status-warning);color:var(--text-secondary);font-size:12px;line-height:1.6">' +
        '<b style="color:var(--status-warning)">메일 서버 미설정</b> — ' + u.esc(mailNote) +
        ' 지금은 [발송] 을 누르면 메일앱이 열리고 내용이 채워집니다.</div>'
      : '';

    paint(head + bar + note + (state.tab === 'plan' ? planBody() : closingBody()));

    if (state.tab === 'plan') bindDirty();
  }

  function tabBtn(key, label, sub) {
    var on = state.tab === key;
    return '<button type="button" onclick="AdminDailyReport.setTab(\'' + key + '\')" ' +
      'style="padding:8px 15px;border-radius:8px;cursor:pointer;font-size:13px;font-family:inherit;' +
      'border:1px solid ' + (on ? 'var(--brand-primary)' : 'var(--border-default)') + ';' +
      'background:' + (on ? 'var(--brand-primary)' : 'transparent') + ';' +
      'color:' + (on ? '#fff' : 'var(--text-secondary)') + ';font-weight:' + (on ? '600' : '400') + '">' +
      ui().esc(label) + ' <span style="opacity:.7;font-size:11px">' + ui().esc(sub) + '</span></button>';
  }

  /* ══════════════════════ 작업계획서 ══════════════════════ */

  function planBody() {
    var u = ui();
    var p = state.plan || {};
    var d = p.plan || {};
    var submitted = p.status === 'submitted';

    var status = submitted
      ? u.badge('제출 완료 ' + (p.submittedAt || ''), 'ok')
      : u.badge('작성 중 (초안)', 'warn');

    var actions =
      '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 8px">' +
        u.primaryButton('저장', 'AdminDailyReport.savePlan(false)', 'floppy-disk') +
        u.rowButton(submitted ? '다시 제출' : '제출', 'AdminDailyReport.savePlan(true)') +
        u.rowButton('미리보기', 'AdminDailyReport.preview(\'plan\')') +
        u.rowButton('원청에 발송', 'AdminDailyReport.send(\'plan\')') +
      '</div>';

    return '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">' + status +
      (p.isNew ? '<span style="font-size:12px;color:var(--text-tertiary)">ERP 가 아는 내용으로 초안을 채웠습니다 — 확인하고 저장하세요.</span>' : '') +
      '</div>' +

      card('기본 정보',
        grid([
          field('weather', '날씨', d.weather, 'text', '맑음 / 흐림 / 비'),
          field('temperature', '기온', d.temperature, 'text', '18~26°C'),
          field('tbmTime', 'TBM 시각', d.tbmTime, 'time', ''),
          field('tbmLeader', 'TBM 진행자', d.tbmLeader, 'text', '이름'),
          field('tbmHeadcount', 'TBM 참석(명)', d.tbmHeadcount, 'number', '', true),
        ])
      ) +

      card('금일 작업 개요',
        textarea('workScope', d.workScope, 4, '오늘 무엇을 어디까지 하는지. 전날 마감의 «내일 할 일» 을 가져와 채웠습니다.'),
        p.suggested && p.suggested.workScope ? true : false
      ) +

      card('공종별 투입 인원',
        editTable('crews', d.crews || []),
        (p.suggested && (p.suggested.crews || []).length) ? true : false
      ) +

      card('위험요인 및 대책 (PTP / JHA)',
        editTable('hazards', d.hazards || []) +
        '<p style="margin:8px 0 0;font-size:11px;color:var(--text-tertiary);line-height:1.6">' +
        '이 칸만은 자동으로 채우지 않습니다 — 그날 그 작업을 본 사람이 판단해야 하고, ' +
        '기계가 채운 위험요인은 아무도 읽지 않습니다.</p>'
      ) +

      card('작업허가서 (PTW)',
        editTable('permits', d.permits || []),
        (p.suggested && (p.suggested.permits || []).length) ? true : false
      ) +

      card('금일 사용 장비',
        editTable('equipment', d.equipment || []),
        (p.suggested && (p.suggested.equipment || []).length) ? true : false
      ) +

      card('특이사항 · 원청 요청', textarea('notes', d.notes, 3, '자재 반입, 협조 요청, 예정된 검사 등')) +

      actions;
  }

  /** 자동으로 채워진 구역에 초록 점을 단다 — 사람이 쓴 것과 구분되어야 한다. */
  function card(title, inner, autofilled) {
    var u = ui();
    return '<section style="background:var(--bg-surface);border:1px solid var(--border-default);' +
      'border-radius:12px;padding:16px 18px;margin-bottom:14px">' +
      '<h3 style="margin:0 0 12px;font-size:13px;font-weight:700;color:var(--text-primary);' +
      'display:flex;align-items:center;gap:7px">' +
      (autofilled ? '<span title="ERP 가 채운 칸입니다" style="width:7px;height:7px;border-radius:50%;' +
        'background:var(--status-success,#16a34a);display:inline-block"></span>' : '') +
      u.esc(title) + '</h3>' + inner + '</section>';
  }

  function grid(cells) {
    return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">' +
      cells.join('') + '</div>';
  }

  function field(name, label, value, type, placeholder) {
    var u = ui();
    return '<div><label style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:5px">' +
      u.esc(label) + '</label>' +
      '<input type="' + (type || 'text') + '" data-dr="' + u.esc(name) + '" value="' + u.esc(value == null ? '' : value) + '" ' +
      'placeholder="' + u.esc(placeholder || '') + '" ' +
      'style="width:100%;padding:8px 11px;border-radius:8px;border:1px solid var(--border-default);' +
      'background:var(--bg-base);color:var(--text-primary);font-size:13px;font-family:inherit"></div>';
  }

  function textarea(name, value, rows, placeholder) {
    var u = ui();
    return '<textarea data-dr="' + u.esc(name) + '" rows="' + (rows || 3) + '" placeholder="' + u.esc(placeholder || '') + '" ' +
      'style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border-default);' +
      'background:var(--bg-base);color:var(--text-primary);font-size:13px;font-family:inherit;line-height:1.6;' +
      'resize:vertical">' + u.esc(value == null ? '' : value) + '</textarea>';
  }

  /**
   * 줄을 더하고 지울 수 있는 표.
   *
   * 화면에서 값을 읽을 때 DOM 을 훑는다(상태를 이중으로 들고 있지 않는다). 입력 도중
   * 다시 그리지 않으므로 커서가 튀지 않고, 저장 순간의 화면이 곧 저장되는 내용이다.
   */
  function editTable(key, rows) {
    var u = ui();
    var cols = COLS[key];
    var widths = cols.map(function (c) { return c[2]; }).join(' ') + ' 34px';

    var header = '<div style="display:grid;grid-template-columns:' + widths + ';gap:6px;margin-bottom:5px">' +
      cols.map(function (c) {
        return '<div style="font-size:10px;letter-spacing:.04em;text-transform:uppercase;' +
          'color:var(--text-tertiary);font-weight:600">' + u.esc(c[1]) + '</div>';
      }).join('') + '<div></div></div>';

    var body = (rows || []).map(function (r) { return rowHtml(key, r); }).join('');

    return header +
      '<div id="dr-rows-' + key + '">' + body + '</div>' +
      '<button type="button" onclick="AdminDailyReport.addRow(\'' + key + '\')" ' +
      'style="margin-top:8px;padding:6px 12px;border-radius:6px;border:1px dashed var(--border-default);' +
      'background:transparent;color:var(--text-secondary);font-size:12px;cursor:pointer;font-family:inherit">' +
      '+ 줄 추가</button>';
  }

  function rowHtml(key, r) {
    var u = ui();
    var cols = COLS[key];
    var widths = cols.map(function (c) { return c[2]; }).join(' ') + ' 34px';
    return '<div class="dr-row" data-grp="' + key + '" style="display:grid;grid-template-columns:' + widths +
      ';gap:6px;margin-bottom:5px">' +
      cols.map(function (c) {
        var v = r && r[c[0]] != null ? r[c[0]] : '';
        return '<input data-col="' + u.esc(c[0]) + '" value="' + u.esc(v) + '" ' +
          'style="padding:7px 9px;border-radius:6px;border:1px solid var(--border-default);' +
          'background:var(--bg-base);color:var(--text-primary);font-size:12px;font-family:inherit;min-width:0">';
      }).join('') +
      '<button type="button" onclick="this.parentNode.remove()" title="이 줄 삭제" ' +
      'style="border:1px solid var(--border-default);background:transparent;color:var(--text-tertiary);' +
      'border-radius:6px;cursor:pointer;font-size:14px;line-height:1">×</button></div>';
  }

  var COLS = {
    crews: [['company', '업체', '1.4fr'], ['trade', '공종', '1fr'], ['headcount', '인원', '70px'],
            ['location', '작업 위치', '1.2fr'], ['work', '작업 내용', '1.6fr']],
    hazards: [['hazard', '위험요인', '1fr'], ['control', '안전 대책', '1.4fr']],
    permits: [['no', '번호', '140px'], ['type', '종류', '140px'], ['title', '작업', '1fr']],
    equipment: [['name', '장비', '1.2fr'], ['code', '번호', '150px'], ['use', '용도', '1.4fr']],
  };

  function addRow(key) {
    var host = document.getElementById('dr-rows-' + key);
    if (!host) return;
    host.insertAdjacentHTML('beforeend', rowHtml(key, {}));
    state.dirty = true;
  }

  // page-container 는 다시 그려도 요소 자체는 그대로라, 그릴 때마다 붙이면 리스너가
  // 쌓인다. 한 번만 붙이고 그 뒤로는 위임으로 받는다.
  var dirtyBound = false;
  function bindDirty() {
    if (dirtyBound) return;
    var host = document.getElementById('page-container');
    if (!host) return;
    host.addEventListener('input', function () { state.dirty = true; });
    dirtyBound = true;
  }

  /** 화면에서 값을 걷는다 — 저장 순간에 보이는 것이 그대로 저장된다. */
  function collectPlan() {
    var out = {};
    document.querySelectorAll('[data-dr]').forEach(function (el) {
      out[el.getAttribute('data-dr')] = el.value;
    });

    Object.keys(COLS).forEach(function (key) {
      var rows = [];
      var host = document.getElementById('dr-rows-' + key);
      if (host) {
        host.querySelectorAll('.dr-row').forEach(function (row) {
          var o = {};
          row.querySelectorAll('[data-col]').forEach(function (i) { o[i.getAttribute('data-col')] = i.value; });
          rows.push(o);
        });
      }
      out[key] = rows;
    });

    return out;
  }

  function savePlan(submit) {
    var u = ui();
    var payload = collectPlan();

    return call('api_saveDailyPlan', [payload, state.date, !!submit]).then(function (res) {
      state.dirty = false;
      u.toast(res.message || '저장했습니다.');
      return load().then(draw);
    }).catch(function (e) { u.toast(e.message, 'error'); });
  }

  /* ══════════════════════ 마감보고서 ══════════════════════ */

  function loadClosing() {
    return call('api_getDailyClosings', []).then(function (res) {
      var row = (res.reports || []).filter(function (r) { return r.date === state.date; })[0];
      if (!row) { state.closing = { missing: true }; return; }
      return call('api_getDailyClosing', [row.id]).then(function (full) { state.closing = full; });
    }).catch(function () { state.closing = { missing: true }; });
  }

  function closingBody() {
    var u = ui();
    var c = state.closing;

    if (!c || c.missing) {
      return '<section style="background:var(--bg-surface);border:1px solid var(--border-default);' +
        'border-radius:12px;padding:44px 18px;text-align:center">' +
        '<p style="margin:0 0 6px;font-size:14px;color:var(--text-primary);font-weight:600">' +
        state.date + ' 마감이 아직 없습니다.</p>' +
        '<p style="margin:0 0 18px;font-size:12px;color:var(--text-tertiary);line-height:1.7">' +
        '마감을 실행하면 그날 출역 인원 · 공정 · 자재 · 안전 · 사진을 모아 보고서를 만듭니다.<br>' +
        '숫자는 시스템이 세고, 문장은 AI 가 그 숫자를 근거로 씁니다.</p>' +
        u.primaryButton('일일 마감 실행', 'AdminDailyReport.runClosing()', 'play') + '</section>';
    }

    if (c.status === 'writing') {
      return '<section style="background:var(--bg-surface);border:1px solid var(--border-default);' +
        'border-radius:12px;padding:44px 18px;text-align:center;color:var(--text-secondary);font-size:13px">' +
        '보고서를 작성하는 중입니다… <button type="button" onclick="AdminDailyReport.refreshClosing()" ' +
        'style="margin-left:8px;padding:5px 10px;border-radius:6px;border:1px solid var(--border-default);' +
        'background:transparent;color:var(--text-secondary);font-size:12px;cursor:pointer">새로고침</button></section>';
    }

    var n = c.narrative || {};
    var m = c.metrics || {};
    var labor = m.labor || {};
    var f = c.field || {};

    var actions = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 8px">' +
      u.primaryButton('원청에 발송', 'AdminDailyReport.send(\'closing\')', 'paper-plane-tilt') +
      u.rowButton('미리보기', 'AdminDailyReport.preview(\'closing\')') +
      u.rowButton('다시 마감', 'AdminDailyReport.runClosing()') + '</div>';

    var out = '';

    if (n.headline) {
      out += '<div style="padding:14px 16px;margin-bottom:14px;border-radius:10px;background:var(--bg-panel);' +
        'border-left:3px solid var(--brand-primary);font-size:14px;font-weight:600;color:var(--text-primary);' +
        'line-height:1.6">' + u.esc(n.headline) + '</div>';
    }

    out += card('출역 인원', grid([
      stat('최종 확정', (labor.final || 0) + '명'),
      stat('현장 보고', (labor.reported || 0) + '명'),
      stat('QR 실적', (labor.actualQr || 0) + '명'),
      stat('차이', (labor.gap === 0 ? '없음' : (labor.gap > 0 ? '+' : '') + (labor.gap || 0) + '명'),
        labor.gap !== 0),
    ]), true);

    var eq = m.equipment || {};
    var sf = m.safety || {};
    var ph = m.photos || {};
    out += card('장비 · 안전 · 사진', grid([
      stat('가동 장비', (eq.count || 0) + ' / ' + (eq.onSite || 0) + '대'),
      stat('TBM', (sf.tbmDone || 0) + ' / ' + (sf.cards || 0) + '건'),
      stat('작업허가서', (sf.permits || 0) + '건'),
      stat('미해결 지적', (sf.issues || 0) + '건', (sf.issues || 0) > 0),
      stat('첨부 사진', (ph.count || 0) + '장'),
      stat('진도율', (f.progressRate || 0) + '%'),
    ]), true);

    [['done', '금일 작업 실적'], ['issues', '이슈 · 지연'], ['attention', '오늘 확인 필요'], ['tomorrow', '내일 계획']]
      .forEach(function (pair) {
        var list = (n[pair[0]] || []).filter(function (x) { return x && String(x).trim(); });
        if (pair[0] === 'done' && f.workToday) list.unshift(f.workToday);
        if (pair[0] === 'tomorrow' && f.workTomorrow) list.unshift(f.workTomorrow);
        if (!list.length) return;
        out += card(pair[1], '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.8;' +
          'color:var(--text-primary)">' +
          list.map(function (x) { return '<li>' + u.esc(x) + '</li>'; }).join('') + '</ul>');
      });

    if (n.summary) {
      out += card('종합 의견', '<p style="margin:0;font-size:13px;line-height:1.8;color:var(--text-primary)">' +
        u.esc(n.summary) + '</p>');
    }

    return out + actions;
  }

  function stat(label, value, warn) {
    var u = ui();
    return '<div style="padding:11px 13px;border:1px solid var(--border-default);border-radius:9px;background:var(--bg-base)">' +
      '<div style="font-size:10px;color:var(--text-tertiary);margin-bottom:3px;letter-spacing:.03em">' + u.esc(label) + '</div>' +
      '<div style="font-size:17px;font-weight:700;color:' + (warn ? 'var(--status-warning)' : 'var(--text-primary)') + '">' +
      u.esc(value) + '</div></div>';
  }

  function runClosing() {
    var u = ui();
    u.toast('마감을 시작했습니다. 잠시 걸립니다…');
    call('api_startDailyClosing', [state.date]).then(function () {
      state.closing = null;
      setTimeout(function () { refreshClosing(); }, 4000);
    }).catch(function (e) { u.toast(e.message, 'error'); });
  }

  function refreshClosing() {
    state.closing = null;
    loadClosing().then(draw);
  }

  /* ══════════════════════ 미리보기 · 발송 ══════════════════════ */

  function preview(kind) {
    var u = ui();
    call('api_getDailyReportPreview', [kind, state.date]).then(function (res) {
      var wrap = document.createElement('div');
      wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.55);display:flex;' +
        'align-items:center;justify-content:center;padding:20px';

      var to = (res.recipients || []).length
        ? '받는 사람: ' + res.recipients.join(', ')
        : '수신처가 아직 없습니다 — [수신처 관리] 에서 등록하세요.';

      wrap.innerHTML = '<div style="background:var(--bg-surface);border:1px solid var(--border-default);' +
        'border-radius:14px;width:min(880px,96vw);height:min(90vh,940px);display:flex;flex-direction:column;overflow:hidden">' +
        '<div style="padding:14px 18px;border-bottom:1px solid var(--border-default);flex-shrink:0">' +
        '<div style="font-size:13px;font-weight:600;color:var(--text-primary)">' + u.esc(res.subject) + '</div>' +
        '<div style="font-size:11px;color:var(--text-tertiary);margin-top:3px">' + u.esc(to) + '</div></div>' +
        '<iframe id="dr-preview" style="flex:1;border:0;background:#fff"></iframe>' +
        '<div style="padding:12px 18px;border-top:1px solid var(--border-default);display:flex;gap:8px;' +
        'justify-content:flex-end;flex-shrink:0">' +
        '<button type="button" id="dr-print" style="padding:8px 14px;border-radius:8px;border:1px solid var(--border-default);' +
        'background:transparent;color:var(--text-secondary);font-size:13px;cursor:pointer">인쇄</button>' +
        '<button type="button" id="dr-close" style="padding:8px 14px;border-radius:8px;border:none;' +
        'background:var(--brand-primary);color:#fff;font-size:13px;font-weight:600;cursor:pointer">닫기</button>' +
        '</div></div>';

      document.body.appendChild(wrap);

      // srcdoc 으로 넣는다 — 메일이 받을 HTML 을 그대로 보여 주기 위해서다.
      var frame = wrap.querySelector('#dr-preview');
      frame.srcdoc = '<!doctype html><meta charset="utf-8"><body style="margin:0">' + res.html + '</body>';

      wrap.querySelector('#dr-print').onclick = function () {
        frame.contentWindow.focus();
        frame.contentWindow.print();
      };
      wrap.querySelector('#dr-close').onclick = function () { wrap.remove(); };
      wrap.onclick = function (e) { if (e.target === wrap) wrap.remove(); };
    }).catch(function (e) { u.toast(e.message, 'error'); });
  }

  function send(kind) {
    var u = ui();
    var label = kind === 'plan' ? '작업계획서' : '마감보고서';

    if (state.dirty && kind === 'plan') {
      u.toast('저장하지 않은 내용이 있습니다. 먼저 저장해 주세요.', 'error');
      return;
    }
    if (!global.confirm(state.date + ' ' + label + '를 등록된 수신처로 발송할까요?')) return;

    call('api_sendDailyReport', [kind, state.date]).then(function (res) {
      if (res.mailto) {
        // 메일 서버가 없다. 보낸 척하지 않고 사장님 메일앱을 연다.
        u.toast(res.message);
        global.location.href = res.mailto;
      } else {
        u.toast(res.message || '발송했습니다.');
      }
      return call('api_getReportDispatches', [state.date]).then(function (h) {
        state.dispatches = h;
      });
    }).catch(function (e) {
      u.toast(e.message, 'error');
      if (String(e.message).indexOf('수신처') >= 0) openRecipients();
    });
  }

  /* ══════════════════════ 수신처 ══════════════════════ */

  function openRecipients() {
    var u = ui();
    call('api_getReportRecipients', []).then(function (res) {
      var wrap = document.createElement('div');
      wrap.id = 'dr-recip';
      wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.55);display:flex;' +
        'align-items:center;justify-content:center;padding:20px';

      var rows = (res.rows || []).map(function (r) {
        var what = (r.receives || []).map(function (x) { return x === 'plan' ? '계획서' : '보고서'; }).join(' · ');
        return '<tr style="border-bottom:1px solid var(--border-default)">' +
          '<td style="padding:9px 10px;font-size:13px;color:var(--text-primary)">' + u.esc(r.name) +
          (r.isCc ? ' <span style="font-size:10px;color:var(--text-tertiary)">(참조)</span>' : '') +
          '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.email) + '</div></td>' +
          '<td style="padding:9px 10px;font-size:12px;color:var(--text-secondary)">' + u.esc(r.org || '—') +
          '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.roleLabel) + '</div></td>' +
          '<td style="padding:9px 10px;font-size:12px;color:var(--text-secondary)">' + u.esc(r.siteName || '전 현장') + '</td>' +
          '<td style="padding:9px 10px;font-size:12px;color:var(--text-secondary)">' + u.esc(what) + '</td>' +
          '<td style="padding:9px 10px;text-align:right;white-space:nowrap">' +
          u.rowButton('수정', 'AdminDailyReport.editRecipient(' + r.id + ')') + ' ' +
          u.rowButton('삭제', 'AdminDailyReport.deleteRecipient(' + r.id + ')', 'danger') + '</td></tr>';
      }).join('');

      wrap.innerHTML = '<div style="background:var(--bg-surface);border:1px solid var(--border-default);' +
        'border-radius:14px;width:min(880px,96vw);max-height:90vh;display:flex;flex-direction:column;overflow:hidden">' +
        '<div style="padding:16px 18px;border-bottom:1px solid var(--border-default)">' +
        '<div style="font-size:15px;font-weight:700;color:var(--text-primary)">일일 보고 수신처</div>' +
        '<div style="font-size:12px;color:var(--text-tertiary);margin-top:4px;line-height:1.6">' +
        '원청·감리·본사는 받는 문서가 다릅니다. 사람마다 받을 것을 골라 두면 발송 버튼만 누르면 됩니다.<br>' +
        '현장을 «전 현장» 으로 두면 모든 현장의 보고를 받습니다(본사 공사부장 등).</div></div>' +
        '<div style="flex:1;overflow:auto"><table style="width:100%;border-collapse:collapse">' +
        (rows || '<tr><td style="padding:40px;text-align:center;color:var(--text-tertiary);font-size:13px">' +
          '등록된 수신처가 없습니다. 먼저 받는 사람을 추가하세요.</td></tr>') +
        '</table></div>' +
        '<div style="padding:12px 18px;border-top:1px solid var(--border-default);display:flex;gap:8px;justify-content:space-between">' +
        u.primaryButton('수신처 추가', 'AdminDailyReport.editRecipient(0)', 'plus') +
        '<button type="button" onclick="document.getElementById(\'dr-recip\').remove()" ' +
        'style="padding:8px 14px;border-radius:8px;border:1px solid var(--border-default);background:transparent;' +
        'color:var(--text-secondary);font-size:13px;cursor:pointer">닫기</button></div></div>';

      var old = document.getElementById('dr-recip');
      if (old) old.remove();
      document.body.appendChild(wrap);
      state._recipients = res;
    }).catch(function (e) { u.toast(e.message, 'error'); });
  }

  function editRecipient(id) {
    var u = ui();
    var res = state._recipients || { rows: [], sites: [], roles: {} };
    var row = (res.rows || []).filter(function (r) { return r.id === id; })[0] || {};

    var siteOptions = [{ value: '', label: '전 현장 (모든 현장의 보고를 받음)' }].concat(
      (res.sites || []).map(function (s) { return { value: s.id, label: s.name }; }));

    var roleOptions = Object.keys(res.roles || {}).map(function (k) {
      return { value: k, label: res.roles[k] };
    });

    u.formModal({
      title: id ? '수신처 수정' : '수신처 추가',
      subtitle: '여기 등록된 주소로 일일 보고가 나갑니다.',
      saveLabel: '저장',
      fields: [
        { name: 'name', label: '이름', required: true, group: '받는 사람', value: row.name || '' },
        { name: 'email', label: '이메일', required: true, group: '받는 사람', value: row.email || '' },
        { name: 'org', label: '회사 · 부서', group: '받는 사람', value: row.org || '' },
        { name: 'role', label: '구분', type: 'select', group: '받는 사람',
          options: roleOptions, value: row.role || 'owner' },
        { name: 'siteId', label: '현장', type: 'select', group: '받는 범위',
          options: siteOptions, value: row.siteId || '' },
        { name: 'wantPlan', label: '아침 작업계획서', type: 'checkbox', group: '받는 범위',
          checkboxLabel: '받는다', value: row.receives ? row.receives.indexOf('plan') >= 0 : true },
        { name: 'wantClosing', label: '저녁 마감보고서', type: 'checkbox', group: '받는 범위',
          checkboxLabel: '받는다', value: row.receives ? row.receives.indexOf('closing') >= 0 : true },
        { name: 'isCc', label: '참조(CC)', type: 'checkbox', group: '받는 범위',
          checkboxLabel: '참조로 받는다', value: !!row.isCc,
          hint: '참조는 한 통에 함께 실립니다 — 같은 메일을 여러 번 받지 않습니다.' },
      ],
      onSave: function (v) {
        var receives = [];
        if (v.wantPlan) receives.push('plan');
        if (v.wantClosing) receives.push('closing');

        return call('api_saveReportRecipient', [{
          id: id || null, name: v.name, email: v.email, org: v.org, role: v.role,
          siteId: v.siteId ? parseInt(v.siteId, 10) : null,
          receives: receives, isCc: !!v.isCc, active: true,
        }]).then(function (r) {
          u.toast(r.message || '저장했습니다.');
          openRecipients();
          return { success: true };
        }).catch(function (e) { return { success: false, error: e.message }; });
      },
    });
  }

  function deleteRecipient(id) {
    var u = ui();
    if (!global.confirm('이 수신처를 삭제할까요? 앞으로 이 사람에게는 보고가 가지 않습니다.')) return;
    call('api_deleteReportRecipient', [id]).then(function (r) {
      u.toast(r.message || '삭제했습니다.');
      openRecipients();
    }).catch(function (e) { u.toast(e.message, 'error'); });
  }

  /* ══════════════════════ 발송 이력 ══════════════════════ */

  function openHistory() {
    var u = ui();
    call('api_getReportDispatches', [state.date]).then(function (res) {
      var rows = (res.rows || []).map(function (r) {
        var tone = r.status === 'sent' ? 'ok' : (r.status === 'failed' ? 'danger' : 'warn');
        var word = r.status === 'sent' ? '발송' : (r.status === 'failed' ? '실패' : '메일앱');
        return '<tr style="border-bottom:1px solid var(--border-default)">' +
          '<td style="padding:9px 10px;font-size:12px;color:var(--text-secondary)">' + u.esc(r.sentAt || '') + '</td>' +
          '<td style="padding:9px 10px;font-size:12px;color:var(--text-primary)">' + u.esc(r.kind) + '</td>' +
          '<td style="padding:9px 10px;font-size:12px;color:var(--text-secondary)">' + u.esc(r.to) + '</td>' +
          '<td style="padding:9px 10px">' + u.badge(word, tone) +
          (r.error ? '<div style="font-size:11px;color:var(--text-tertiary);margin-top:3px">' +
            u.esc(String(r.error).slice(0, 90)) + '</div>' : '') + '</td></tr>';
      }).join('');

      var wrap = document.createElement('div');
      wrap.id = 'dr-hist';
      wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.55);display:flex;' +
        'align-items:center;justify-content:center;padding:20px';
      wrap.innerHTML = '<div style="background:var(--bg-surface);border:1px solid var(--border-default);' +
        'border-radius:14px;width:min(760px,96vw);max-height:86vh;display:flex;flex-direction:column;overflow:hidden">' +
        '<div style="padding:16px 18px;border-bottom:1px solid var(--border-default)">' +
        '<div style="font-size:15px;font-weight:700;color:var(--text-primary)">' + u.esc(state.date) + ' 발송 이력</div>' +
        '<div style="font-size:12px;color:var(--text-tertiary);margin-top:4px">' +
        '누구에게 언제 나갔는지가 남아야 나중에 «못 받았다» 는 말에 답할 수 있습니다.</div></div>' +
        '<div style="flex:1;overflow:auto"><table style="width:100%;border-collapse:collapse">' +
        (rows || '<tr><td style="padding:40px;text-align:center;color:var(--text-tertiary);font-size:13px">' +
          '이 날짜에 발송한 기록이 없습니다.</td></tr>') + '</table></div>' +
        '<div style="padding:12px 18px;border-top:1px solid var(--border-default);text-align:right">' +
        '<button type="button" onclick="document.getElementById(\'dr-hist\').remove()" ' +
        'style="padding:8px 14px;border-radius:8px;border:none;background:var(--brand-primary);color:#fff;' +
        'font-size:13px;font-weight:600;cursor:pointer">닫기</button></div></div>';

      var old = document.getElementById('dr-hist');
      if (old) old.remove();
      document.body.appendChild(wrap);
      wrap.onclick = function (e) { if (e.target === wrap) wrap.remove(); };
    }).catch(function (e) { u.toast(e.message, 'error'); });
  }

  global.AdminDailyReport = {
    render: render,
    setDate: setDate,
    setTab: setTab,
    addRow: addRow,
    savePlan: savePlan,
    runClosing: runClosing,
    refreshClosing: refreshClosing,
    preview: preview,
    send: send,
    openRecipients: openRecipients,
    editRecipient: editRecipient,
    deleteRecipient: deleteRecipient,
    openHistory: openHistory,
    _state: state,
  };
})(window);
