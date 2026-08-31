/**
 * 서신 원장 — 우리가 무엇을 언제 누구에게 보냈는가.
 *
 * 이 화면의 값은 평소가 아니라 <b>다툼이 났을 때</b> 나온다. "8월 30일에 통보했습니다" 를
 * 말이 아니라 기록으로 대는 자리다. 그래서 성공만 보여주지 않는다 — 실패도, 메일 서버가
 * 없어 사람 메일앱으로 넘긴 것도 같은 무게로 센다. 성공만 기록하는 원장은 증거가 못 된다.
 *
 * 한 줄은 «실타래»(하나의 사안)이고, 열면 그 사안에 오간 봉투가 시간순으로 쌓인다.
 * 보낸 본문을 그대로 보관하므로 "그때 뭐라고 썼더라" 를 다시 열어 볼 수 있다.
 */
(function (global) {
  'use strict';

  var A = null;
  function ui() { if (!A) A = global.AdminUI; return A; }

  var state = { data: null, q: '', status: '' };

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      if (res.success === false) throw new Error(res.error || '요청이 거부되었습니다.');
      return res;
    });
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function render() {
    paint('<div style="padding:60px;text-align:center;color:var(--text-tertiary)">서신 원장을 불러오는 중…</div>');
    load().then(draw).catch(function (e) {
      paint('<div style="padding:60px;text-align:center;color:var(--status-danger,#dc2626)">' +
        ui().esc(e.message) + '</div>');
    });
  }

  function load() {
    return call('api_getCorrespondence', [{ q: state.q, status: state.status }])
      .then(function (res) { state.data = res; });
  }

  function setFilter(key, v) { state[key] = v; load().then(draw); }

  /* ══════════════════════ 목록 ══════════════════════ */

  function draw() {
    var u = ui();
    var d = state.data || {};
    var s = d.stats || {};

    var head = u.pageHeader(
      '서신 원장',
      '우리가 보낸 모든 메일이 여기 남습니다. 실패한 것과 메일앱으로 넘긴 것도 함께 셉니다.'
    );

    var chips =
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:1px;' +
        'background:var(--border-default);border:1px solid var(--border-default);margin-bottom:18px">' +
        stat('사안', s.threads, null) +
        stat('발송', s.sent, 'var(--status-success,#16a34a)') +
        stat('회신 대기', s.awaiting, null) +
        stat('메일앱으로', s.skipped, 'var(--status-warning,#f59e0b)') +
        stat('실패', s.failed, (s.failed || 0) > 0 ? 'var(--status-danger,#dc2626)' : null) +
      '</div>';

    var bar =
      '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">' +
        '<input type="search" value="' + u.esc(state.q) + '" placeholder="참조번호 · 제목 · 상대방으로 찾기" ' +
          'onchange="AdminCorrespondence.setFilter(\'q\', this.value)" ' +
          'style="flex:1;min-width:200px;padding:8px 12px;border-radius:8px;border:1px solid var(--border-default);' +
          'background:var(--bg-base);color:var(--text-primary);font-size:13px;font-family:inherit">' +
        select('status', state.status, [
          { v: '', l: '전체 상태' },
          { v: 'open', l: '진행' },
          { v: 'awaiting_reply', l: '회신 대기' },
          { v: 'closed', l: '종결' },
        ]) +
      '</div>';

    var rows = (d.rows || []).map(function (r) {
      var tone = r.lastStatus === 'failed' ? 'danger'
        : r.lastStatus === 'skipped' ? 'warn'
        : r.lastStatus === 'sent' ? 'ok' : null;

      return '<tr onclick="AdminCorrespondence.open(' + r.id + ')" ' +
        'style="border-bottom:1px solid var(--border-default);cursor:pointer">' +
        '<td style="padding:11px 12px;font-size:12px;font-family:var(--font-mono,monospace);' +
          'color:var(--text-secondary);white-space:nowrap">' + u.esc(r.refCode) + '</td>' +
        '<td style="padding:11px 12px;font-size:13px;color:var(--text-primary)">' + u.esc(r.subject) +
          '<div style="font-size:11px;color:var(--text-tertiary);margin-top:2px">' +
          u.esc(r.related) + (r.site ? ' · ' + u.esc(r.site) : '') + '</div></td>' +
        '<td style="padding:11px 12px;font-size:12.5px;color:var(--text-secondary)">' +
          u.esc(r.counterparty || '—') +
          (r.org ? '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.org) + '</div>' : '') + '</td>' +
        '<td style="padding:11px 12px;text-align:center;font-size:12px;color:var(--text-secondary)">' + r.count + '</td>' +
        '<td style="padding:11px 12px">' + (r.lastStatusLabel ? u.badge(r.lastStatusLabel, tone) : '') + '</td>' +
        '<td style="padding:11px 12px;font-size:12px;color:var(--text-tertiary);white-space:nowrap">' +
          u.esc(r.lastAt || '') + '</td></tr>';
    }).join('');

    var table =
      '<div style="background:var(--bg-surface);border:1px solid var(--border-default);border-radius:12px;overflow:hidden">' +
      '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">' +
      '<thead><tr>' +
      ['참조번호', '제목', '상대방', '통', '최근 결과', '최근'].map(function (h) {
        return '<th style="text-align:left;padding:10px 12px;font-size:11px;font-weight:600;' +
          'letter-spacing:.04em;text-transform:uppercase;color:var(--text-tertiary);' +
          'border-bottom:1px solid var(--border-default);white-space:nowrap">' + ui().esc(h) + '</th>';
      }).join('') + '</tr></thead><tbody>' +
      (rows || '<tr><td colspan="6" style="padding:48px 12px;text-align:center;color:var(--text-tertiary);' +
        'font-size:13px">아직 보낸 서신이 없습니다.<br>' +
        '<span style="font-size:12px">제출물의 [업체 요청]·[원청 전달], 일일 보고의 [발송] 이 여기에 쌓입니다.</span>' +
        '</td></tr>') +
      '</tbody></table></div></div>';

    paint(head + chips + bar + table);
  }

  function stat(label, v, color) {
    return '<div style="background:var(--bg-surface);padding:13px 15px">' +
      '<div style="font-size:10.5px;color:var(--text-tertiary);letter-spacing:.04em">' + ui().esc(label) + '</div>' +
      '<div style="font-size:22px;font-weight:700;margin-top:3px;font-variant-numeric:tabular-nums;' +
      (color ? 'color:' + color : 'color:var(--text-primary)') + '">' + (v == null ? '—' : v) + '</div></div>';
  }

  function select(key, val, opts) {
    return '<select onchange="AdminCorrespondence.setFilter(\'' + key + '\', this.value)" ' +
      'style="padding:8px 11px;border-radius:8px;border:1px solid var(--border-default);' +
      'background:var(--bg-base);color:var(--text-primary);font-size:13px;font-family:inherit">' +
      opts.map(function (o) {
        return '<option value="' + ui().esc(o.v) + '"' + (o.v === val ? ' selected' : '') + '>' +
          ui().esc(o.l) + '</option>';
      }).join('') + '</select>';
  }

  /* ══════════════════════ 실타래 열기 ══════════════════════ */

  function open(id) {
    var u = ui();
    call('api_getCorrespondenceThread', [id]).then(function (res) {
      var t = res.thread || {};
      var msgs = res.messages || [];

      var wrap = document.createElement('div');
      wrap.id = 'cor-modal';
      wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.55);display:flex;' +
        'align-items:center;justify-content:center;padding:20px';

      var body = msgs.map(function (m) {
        var tone = m.status === 'failed' ? 'danger' : m.status === 'skipped' ? 'warn' : 'ok';
        return '<div style="border:1px solid var(--border-default);border-radius:10px;margin-bottom:12px;' +
          'overflow:hidden;background:var(--bg-base)">' +
          '<div style="padding:11px 14px;border-bottom:1px solid var(--border-default);display:flex;' +
            'gap:10px;align-items:baseline;flex-wrap:wrap">' +
            u.badge(m.statusLabel, tone) +
            '<span style="font-size:12.5px;color:var(--text-primary);font-weight:600">' + u.esc(m.subject || '') + '</span>' +
            '<span style="margin-left:auto;font-size:11.5px;color:var(--text-tertiary);white-space:nowrap">' +
              u.esc(m.at || '') + (m.by ? ' · ' + u.esc(m.by) : '') + '</span>' +
          '</div>' +
          '<div style="padding:9px 14px;font-size:11.5px;color:var(--text-secondary);' +
            'border-bottom:1px solid var(--border-default);word-break:break-all">' +
            '받는 사람 ' + u.esc(m.to || '—') +
            (m.attachments && m.attachments.length
              ? ' · 첨부 ' + m.attachments.length + '건: ' +
                m.attachments.map(function (a) { return u.esc(a.name); }).join(', ')
              : '') +
            '<div style="color:var(--text-tertiary);margin-top:3px;font-family:var(--font-mono,monospace);font-size:10.5px">' +
              'Message-ID ' + u.esc(m.messageId || '—') + '</div>' +
          '</div>' +
          (m.error
            ? '<div style="padding:10px 14px;font-size:12px;color:var(--status-danger,#dc2626);' +
              'background:var(--bg-panel)">' + u.esc(m.error) + '</div>'
            : '') +
          // 보낸 본문을 그대로 보관한다 — "그때 뭐라고 썼더라" 가 이 화면의 존재 이유다.
          (m.html
            ? '<details><summary style="padding:9px 14px;cursor:pointer;font-size:12px;' +
              'color:var(--brand-primary)">보낸 내용 보기</summary>' +
              '<iframe sandbox="" style="width:100%;height:420px;border:0;border-top:1px solid var(--border-default);' +
              'background:#fff" srcdoc="' + u.esc(m.html) + '"></iframe></details>'
            : '') +
          '</div>';
      }).join('');

      wrap.innerHTML = '<div style="background:var(--bg-surface);border:1px solid var(--border-default);' +
        'border-radius:14px;width:min(880px,96vw);max-height:92vh;display:flex;flex-direction:column;overflow:hidden">' +
        '<div style="padding:16px 18px;border-bottom:1px solid var(--border-default)">' +
          '<div style="font-family:var(--font-mono,monospace);font-size:11.5px;color:var(--text-tertiary)">' +
            u.esc(t.refCode) + ' · ' + u.esc(t.related) + (t.site ? ' · ' + u.esc(t.site) : '') + '</div>' +
          '<div style="font-size:16px;font-weight:700;color:var(--text-primary);margin-top:3px">' +
            u.esc(t.subject || '') + '</div>' +
          '<div style="font-size:12px;color:var(--text-secondary);margin-top:5px">' +
            u.esc(t.counterparty || '') + (t.counterpartyEmail ? ' &lt;' + u.esc(t.counterpartyEmail) + '&gt;' : '') +
            ' · ' + t.count + '통' +
            (t.openedBy ? ' · 개설 ' + u.esc(t.openedBy) : '') + '</div>' +
        '</div>' +
        '<div style="flex:1;overflow:auto;padding:16px 18px">' + (body || '봉투가 없습니다.') + '</div>' +
        '<div style="padding:12px 18px;border-top:1px solid var(--border-default);text-align:right">' +
          '<button type="button" onclick="document.getElementById(\'cor-modal\').remove()" ' +
          'style="padding:8px 14px;border-radius:8px;border:none;background:var(--brand-primary);' +
          'color:#fff;font-size:13px;font-weight:600;cursor:pointer">닫기</button></div>' +
        '</div>';

      var old = document.getElementById('cor-modal');
      if (old) old.remove();
      document.body.appendChild(wrap);
      wrap.onclick = function (e) { if (e.target === wrap) wrap.remove(); };
    }).catch(function (e) { u.toast(e.message, 'error'); });
  }

  global.AdminCorrespondence = {
    render: render,
    setFilter: setFilter,
    open: open,
    _state: state,
  };
})(window);
