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
        (d.canManage ? '「줄 추가」 로 적거나, 아래 비서에게 말로 맡기세요.' : '') + '</div>';

    return u.pageHeader(
      '이번 주 작업판',
      d.site + ' · 공종별로 이번 주 하는 일과 인원. 됐다/안 됐다만 누르면 됩니다. — ' + notes.join(' · '),
      actions
    ) + (d.canManage ? secretaryBar(u) : '') + body;
  }

  // ── AI 비서 — 타이핑 대신 말·사진·회의로 ────────────────────────────────
  //
  // 사장 말: 「이번 주 할 일을 따로 시간 내서 만들고 싶지 않다. 매일 공정 미팅과 토요일
  // 다음 주 미팅에서 오간 말을 AI 가 비서처럼 듣고 정리해서 처리하는 시스템」.
  // 그래서 입구가 넷이다 — 말로 적기 · 사진/녹음 올리기 · 회의에서 가져오기 · 메모.
  // 넷 다 같은 곳으로 간다: 서버가 「공종 | 하는 일 | 인원 | 메모」 초안을 돌려주고,
  // 사람이 그 초안을 보고 고친 뒤 저장한다. AI 가 들은 대로 바로 판이 되지는 않는다.

  var rec = { recorder: null, stream: null, timer: null, startedAt: 0 };

  function secretaryBar(u) {
    var recording = rec.recorder && rec.recorder.state === 'recording';
    var b = function (label, fn, icon) {
      return '<button type="button" onclick="window.AdminWeekBoard.' + fn + '()" ' +
        'style="padding:8px 12px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-surface);color:var(--text-primary);font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px">' +
        '<i class="ph ph-' + icon + '"></i>' + u.esc(label) + '</button>';
    };
    return '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 14px;margin-bottom:12px;border:1px dashed var(--border-default);border-radius:12px;background:var(--bg-base)">' +
      '<div style="font-size:12px;color:var(--text-secondary);margin-right:4px"><b>AI 비서</b> — 타이핑 대신:</div>' +
      (recording
        ? '<button type="button" onclick="window.AdminWeekBoard.record()" style="padding:8px 12px;border-radius:8px;border:none;background:var(--status-danger);color:#fff;font-size:13px;font-weight:600;cursor:pointer">■ 녹음 끝내기 <span id="wb-rec-clock">0:00</span></button>'
        : b('말로 적기', 'record', 'microphone')) +
      b('사진·녹음 올리기', 'pickFile', 'upload-simple') +
      b('회의에서 가져오기', 'fromMeeting', 'users-three') +
      b('메모 붙여넣기', 'fromText', 'note-pencil') +
      '<input type="file" id="wb-draft-file" accept="audio/*,image/*,.webm,.m4a,.jpg,.jpeg,.png,.heic" style="display:none" onchange="window.AdminWeekBoard.fileChosen(this)">' +
      '</div>';
  }

  function csrf() {
    var el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : '';
  }

  /** 파일 없는 초안 요청(글·회의). 파일이 있으면 AdminUI.uploadFile 을 쓴다. */
  function postDraft(fields) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { if (fields[k] !== null && fields[k] !== undefined) fd.append(k, fields[k]); });
    return fetch('/week-board-api/draft', {
      method: 'POST', credentials: 'same-origin', body: fd,
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    }).then(function (r) { return r.json().catch(function () { return { success: false, error: '응답을 읽지 못했습니다. (HTTP ' + r.status + ')' }; }); })
      .catch(function (e) { return { success: false, error: e.message || '요청에 실패했습니다.' }; });
  }

  /** 다음 주 월요일 — 토요일 「다음 주」 미팅은 다음 주 판에 적는다. */
  function nextWeekStart() {
    var b = new Date(state.data.weekStart + 'T00:00:00');
    b.setDate(b.getDate() + 7);
    return b.toISOString().slice(0, 10);
  }

  function busyToast(msg) {
    var u = ui();
    u.toast(msg || '비서가 정리하는 중…');
  }

  function handleDraft(res, weekLabel) {
    var u = ui();
    if (!res || res.success === false) { u.toast((res && res.error) || '정리하지 못했습니다.', 'error'); return; }
    if (!res.lines || !res.lines.length) {
      u.toast('할 일로 적을 만한 말을 못 찾았습니다.' + (res.heard ? ' 들은 내용: ' + res.heard.slice(0, 80) : ''), 'error');
      return;
    }
    reviewDraft(res, weekLabel);
  }

  /** 초안을 사람이 보고 고친 뒤 저장 — 한 줄에 한 일, 「공종 | 하는 일 | 인원 | 메모」. */
  function reviewDraft(res, weekLabel) {
    var u = ui();
    var d = state.data;
    var week = weekLabel === '다음 주' ? nextWeekStart() : d.weekStart;
    var text = res.lines.map(function (l) {
      return [l.trade, l.task, l.headcount !== null && l.headcount !== undefined ? l.headcount : '', l.note || ''].join(' | ').replace(/( \| )+$/, '');
    }).join('\n');

    u.formModal({
      title: '비서가 정리한 ' + weekLabel + ' 할 일 — 확인하고 저장',
      subtitle: (res.summary ? res.summary + ' · ' : '') + '출처: ' + (res.source || '') + ' · 틀린 줄은 고치고, 아닌 줄은 지우세요.',
      saveLabel: '이대로 작업판에 적기',
      fields: [
        { name: 'lines', label: '할 일 (한 줄에 하나: 공종 | 하는 일 | 인원 | 메모)', type: 'textarea', rows: Math.min(14, res.lines.length + 3),
          colSpan: 2, required: true, value: text, group: weekLabel + ' 작업판에 들어갈 줄',
          hint: '공종은 이 현장 낱말: ' + (res.trades || []).join(', ') },
        { name: 'heard', label: '들은 내용 (근거 — 저장되지 않습니다)', type: 'textarea', rows: 5, colSpan: 2,
          value: res.heard || '', group: '비서가 무엇을 듣고 적었나' },
      ],
      onSave: function (v) {
        var lines = String(v.lines || '').split('\n').map(function (row) {
          var p = row.split('|').map(function (s) { return s.trim(); });
          if (!p[0] && !p[1]) return null;
          return { siteId: d.siteId, trade: p[0] || '', task: p[1] || '', headcount: p[2] || '', note: p[3] || '' };
        }).filter(Boolean);
        if (!lines.length) return { success: false, errors: { lines: '적을 줄이 없습니다.' } };
        return call('api_saveWeekBoardLines', [lines, week, res.source || null]).then(function (r) {
          if (r.success === false && !r.saved) return { success: false, error: (r.errors || []).join(' / ') || r.error || '저장하지 못했습니다.' };
          u.toast(r.saved + '줄을 ' + weekLabel + ' 작업판에 적었습니다.' + (r.errors && r.errors.length ? ' 못 적은 줄: ' + r.errors.join(' / ') : ''), r.errors && r.errors.length ? 'error' : undefined);
          if (week !== d.weekStart) state.week = week;   // 다음 주에 적었으면 그 주를 보여 준다.
          return reload().then(function () { return { success: true }; });
        });
      },
    });
  }

  // 1. 말로 적기 — 상황실 음성 보고와 같은 MediaRecorder 경로.
  function record() {
    var u = ui();
    if (rec.recorder && rec.recorder.state === 'recording') { rec.recorder.stop(); return; }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !global.MediaRecorder) {
      u.toast('이 브라우저에서는 녹음할 수 없습니다. 휴대폰 녹음 파일을 올려 주세요.', 'error');
      return;
    }
    navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true }, video: false }).then(function (stream) {
      var mime = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus'].filter(function (m) { return MediaRecorder.isTypeSupported(m); })[0];
      var chunks = [];
      rec.stream = stream;
      rec.recorder = new MediaRecorder(stream, mime ? { mimeType: mime, audioBitsPerSecond: 96000 } : {});
      rec.startedAt = Date.now();
      rec.recorder.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
      rec.recorder.onstop = function () {
        clearInterval(rec.timer);
        stream.getTracks().forEach(function (t) { t.stop(); });
        var blob = new Blob(chunks, { type: rec.recorder.mimeType });
        rec.recorder = null;
        paint(render());
        sendFile(blob, (blob.type || 'audio/webm').split(';')[0]);
      };
      rec.recorder.onerror = function () { u.toast('녹음 장치에 오류가 났습니다.', 'error'); if (rec.recorder && rec.recorder.state !== 'inactive') rec.recorder.stop(); };
      rec.recorder.start(5000);
      paint(render());
      rec.timer = setInterval(function () {
        var s = Math.floor((Date.now() - rec.startedAt) / 1000);
        var el = document.getElementById('wb-rec-clock');
        if (el) el.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        if (s >= 180 && rec.recorder && rec.recorder.state === 'recording') rec.recorder.stop();   // 서버 상한과 같다.
      }, 1000);
    }).catch(function (e) { u.toast('마이크를 쓸 수 없습니다: ' + (e.message || e), 'error'); });
  }

  // 2. 사진·녹음 파일 올리기 — 수기 노트, 화이트보드, 폰 녹음.
  function pickFile() {
    var el = document.getElementById('wb-draft-file');
    if (el) { el.value = ''; el.click(); }
  }

  function fileChosen(input) {
    var f = input.files && input.files[0];
    if (!f) return;
    var type = (f.type || '').split(';')[0];
    if (type === 'video/webm') type = 'audio/webm';
    if (type === 'audio/x-m4a') type = 'audio/mp4';
    if (!type) type = /\.(m4a|mp4)$/i.test(f.name) ? 'audio/mp4' : /\.(jpe?g)$/i.test(f.name) ? 'image/jpeg' : /\.png$/i.test(f.name) ? 'image/png' : '';
    sendFile(f, type);
  }

  function sendFile(file, mime) {
    var u = ui();
    if (state.busy) return;
    state.busy = true;
    busyToast(mime.indexOf('image/') === 0 ? '사진을 읽는 중…' : '녹음을 듣고 정리하는 중…');
    u.uploadFile('/week-board-api/draft', file, { site_id: state.data.siteId, mime: mime, week_label: '이번 주' })
      .then(function (res) { handleDraft(res, '이번 주'); })
      .finally(function () { state.busy = false; });
  }

  // 3. 회의에서 가져오기 — 끝난 공정미팅(받아쓰기 완료)에서 다음 주(또는 이번 주) 할 일을.
  function fromMeeting() {
    var u = ui();
    var d = state.data;
    fetch('/week-board-api/meetings?site_id=' + encodeURIComponent(d.siteId), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var list = (res && res.meetings) || [];
        if (!list.length) { u.toast('받아쓰기가 끝난 회의가 아직 없습니다. 공정미팅에서 녹음을 올리면 여기 나옵니다.', 'error'); return; }
        return u.formModal({
          title: '회의에서 할 일 가져오기',
          subtitle: '회의 녹음을 비서가 이미 글로 옮겨 두었습니다. 그 글에서 할 일만 골라 초안을 만듭니다.',
          saveLabel: '비서에게 정리시키기',
          fields: [
            { name: 'meeting', label: '회의', type: 'select', required: true, colSpan: 2,
              options: list.map(function (m) { return { value: m.id, label: (m.on || '') + ' · ' + m.title }; }), value: list[0].id },
            { name: 'week', label: '어느 주 할 일인가', type: 'select', required: true, colSpan: 2,
              options: [{ value: '다음 주', label: '다음 주 (토요일 미팅)' }, { value: '이번 주', label: '이번 주 (아침 미팅)' }],
              value: '다음 주' },
          ],
          onSave: function (v) {
            busyToast('회의 내용을 읽는 중…');
            return postDraft({ site_id: d.siteId, meeting_id: v.meeting, week_label: v.week }).then(function (out) {
              if (!out || out.success === false) return { success: false, error: (out && out.error) || '정리하지 못했습니다.' };
              setTimeout(function () { handleDraft(out, v.week); }, 0);
              return { success: true };
            });
          },
        });
      })
      .catch(function (e) { u.toast(e.message || '회의 목록을 못 가져왔습니다.', 'error'); });
  }

  // 4. 메모 붙여넣기 — 카톡·문자에 적힌 지시를 그대로.
  function fromText() {
    var u = ui();
    var d = state.data;
    u.formModal({
      title: '메모를 비서에게',
      subtitle: '회의 메모, 카톡, 문자 — 그대로 붙여 넣으면 공종별 할 일로 정리합니다.',
      saveLabel: '비서에게 정리시키기',
      fields: [
        { name: 'text', label: '내용', type: 'textarea', rows: 8, colSpan: 2, required: true, value: '',
          hint: '예) 배관은 급탕 마무리하고 둘은 그리스트랩. 전기 후드 배선 셋. 덕트는 자재 오면 시작.' },
        { name: 'week', label: '어느 주 할 일인가', type: 'select', required: true,
          options: [{ value: '이번 주', label: '이번 주' }, { value: '다음 주', label: '다음 주' }], value: '이번 주' },
      ],
      onSave: function (v) {
        busyToast();
        return postDraft({ site_id: d.siteId, text: v.text, week_label: v.week }).then(function (out) {
          if (!out || out.success === false) return { success: false, error: (out && out.error) || '정리하지 못했습니다.' };
          setTimeout(function () { handleDraft(out, v.week); }, 0);
          return { success: true };
        });
      },
    });
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
    record: record,
    pickFile: pickFile,
    fileChosen: fileChosen,
    fromMeeting: fromMeeting,
    fromText: fromText,
    reviewDraft: reviewDraft,
    _state: state,
  };
})(window);
