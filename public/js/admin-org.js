/**
 * 조직 설정 — 이 배포가 누구의 것인가.
 *
 * 코드는 하나, 배포는 고객마다 하나. 그 방식이 성립하려면 "회사 이름을 바꾸는 일"이
 * 우리 일이 아니어야 한다. 고객이 열 곳이면 그 연락도 열 배가 되기 때문이다.
 *
 * 그래서 표 화면이 아니라 폼 하나다. 항목이 적고, 자주 열지 않고, 열었을 때는
 * 한 가지를 고치러 온다.
 *
 * 못 고치는 것도 같이 보여 준다. 앱 주소나 자동 마감 시각처럼 환경변수로만 바뀌는
 * 값들인데, 화면에 아예 없으면 사람들이 있을 거라 생각하고 한참 찾는다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = { fields: [], readOnly: [], logo: null, canManage: false, dirty: false, busy: false, mail: null };

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

  function field(f) {
    var u = ui();
    var input;
    var common = 'id="org-' + u.esc(f.key) + '" oninput="window.AdminOrg.touch()" ' +
      'style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border-default);' +
      'background:var(--bg-surface);color:var(--text-primary);font-size:14px;font-family:inherit">';

    if (f.type === 'color') {
      // 색은 글자로도 고칠 수 있게 둔다. 색 선택기만 두면 지정된 브랜드 색 코드를
      // 그대로 넣을 방법이 없다.
      input = '<div style="display:flex;gap:8px;align-items:center">' +
        '<input type="color" value="' + u.esc(f.value || '#0ea5e9') + '" ' +
        'oninput="window.AdminOrg.syncColor(this.value)" ' +
        'style="width:46px;height:40px;padding:2px;border-radius:8px;border:1px solid var(--border-default);' +
        'background:var(--bg-surface);cursor:pointer">' +
        '<input type="text" value="' + u.esc(f.value || '') + '" placeholder="#0ea5e9" ' + common +
        '</div>';
    } else {
      var t = f.type === 'email' ? 'email' : f.type === 'tel' ? 'tel' : 'text';
      input = '<input type="' + t + '" value="' + u.esc(f.value || '') + '" ' +
        (f.placeholder ? 'placeholder="' + u.esc(f.placeholder) + '" ' : '') + common;
    }

    return '<div style="margin-bottom:18px">' +
      '<label for="org-' + u.esc(f.key) + '" style="display:block;font-size:13px;font-weight:600;' +
      'color:var(--text-primary);margin-bottom:6px">' + u.esc(f.label) +
      (f.note ? ' <span style="font-weight:400;color:var(--text-tertiary);font-size:12px">· ' +
        u.esc(f.note) + '</span>' : '') +
      '</label>' + input +
      '<div id="org-err-' + u.esc(f.key) + '" style="display:none;font-size:12px;color:var(--status-danger);margin-top:5px"></div>' +
      (f.hint ? '<div style="font-size:12px;color:var(--text-tertiary);margin-top:5px;line-height:1.5">' +
        u.esc(f.hint) + '</div>' : '') +
      '</div>';
  }

  /**
   * 로고 칸.
   *
   * 글자 칸들과 함께 저장 버튼에 묶지 않는다. 파일은 고른 즉시 올라가고, 그 결과를
   * 바로 옆 미리보기에서 본다 — 로고는 "맞게 보이나" 를 눈으로 확인하는 일이라,
   * 저장을 누르고 새로고침해야 결과가 보이면 몇 번을 왔다 갔다 하게 된다.
   */
  function logoBlock() {
    var u = ui();
    var l = state.logo || {};
    var preview = l.has
      ? '<img src="' + u.esc(l.url) + '" alt="로고" ' +
        'style="max-width:100%;max-height:100%;object-fit:contain">'
      : '<span style="font-size:15px;font-weight:800;color:#fff;letter-spacing:.5px">' +
        u.esc(l.initials || '') + '</span>';

    return '<div style="margin-bottom:22px">' +
      '<label style="display:block;font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:6px">로고</label>' +
      '<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">' +
      '<div style="width:72px;height:72px;flex-shrink:0;display:flex;align-items:center;justify-content:center;' +
      'border-radius:12px;border:1px solid var(--border-default);padding:8px;' +
      (l.has ? 'background:var(--bg-surface)' : 'background:var(--brand-primary)') + '">' + preview + '</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap">' +
      '<input type="file" id="org-logo-file" accept="image/png,image/jpeg,image/webp,image/svg+xml" ' +
      'onchange="window.AdminOrg.uploadLogo(this)" style="display:none">' +
      u.rowButton(l.has ? '다른 그림으로' : '그림 올리기', "document.getElementById('org-logo-file').click()") +
      (l.has ? u.rowButton('지우기', 'window.AdminOrg.removeLogo()', 'danger') : '') +
      '</div></div>' +
      '<div style="font-size:12px;color:var(--text-tertiary);margin-top:8px;line-height:1.6">' +
      'PNG · JPG · WEBP · SVG, ' + u.esc(String(l.maxMb || 2)) + 'MB 까지. 배경이 뚫린 PNG 나 SVG 가 가장 깔끔합니다.<br>' +
      '작은 자리(사이드바 32px)에 들어가므로 글씨가 많은 로고는 잘 안 읽힙니다. ' +
      '올리지 않으면 회사 이름에서 뽑은 글자가 대신 들어갑니다.' +
      '</div></div>';
  }

  function uploadLogo(input) {
    var u = ui();
    var file = input && input.files && input.files[0];
    input.value = '';
    if (!file || state.busy) return;

    state.busy = true;
    u.toast('올리는 중…', 'info');
    u.uploadFile('/org-api/logo', file).then(function (res) {
      state.busy = false;
      if (!res || !res.success) { u.toast((res && res.error) || '올리지 못했습니다.', 'error'); return; }
      applyLoad(res);
      paint(render());
      u.toast('로고를 바꿨습니다. 사이드바는 새로고침 후 바뀝니다.', 'success');
    }).catch(function (e) {
      state.busy = false;
      u.toast(e.message || '올리지 못했습니다.', 'error');
    });
  }

  function removeLogo() {
    var u = ui();
    u.confirmDanger({
      title: '로고를 지울까요?',
      body: '지우면 회사 이름에서 뽑은 글자가 대신 들어갑니다. 그림 파일은 저장돼 있지 않으므로 다시 올려야 합니다.',
      confirmLabel: '지우기',
    }).then(function (ok) {
      if (!ok || state.busy) return;
      state.busy = true;
      var tokenEl = document.querySelector('meta[name="csrf-token"]');
      fetch('/org-api/logo', {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : '',
        },
      }).then(function (r) { return r.json(); }).then(function (res) {
        state.busy = false;
        if (!res || !res.success) { u.toast((res && res.error) || '지우지 못했습니다.', 'error'); return; }
        applyLoad(res);
        paint(render());
        u.toast('로고를 지웠습니다. 사이드바는 새로고침 후 바뀝니다.', 'success');
      }).catch(function (e) {
        state.busy = false;
        u.toast(e.message || '지우지 못했습니다.', 'error');
      });
    });
  }

  function readOnlyBlock() {
    var u = ui();
    if (!state.readOnly.length) return '';
    // 바탕색은 --bg-surface 로 둔다. 옛 별칭(--bg-subtle 등)은 밝은 테마에서
    // 어두운 값을 물고 있어서, 어두운 바탕에 어두운 글씨가 되어 안 보였다.
    return '<div style="margin-top:28px;padding:16px;border:1px solid var(--border-default);border-radius:12px;' +
      'background:var(--bg-surface)">' +
      '<div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:4px">여기서 못 고치는 값</div>' +
      '<div style="font-size:12px;color:var(--text-tertiary);margin-bottom:12px;line-height:1.6">' +
      '배포 설정(환경변수)에서만 바뀝니다. 매분 도는 자동 작업이 읽는 값이라 화면에서 바꾸면 ' +
      '데이터베이스를 매분 깨우게 됩니다.</div>' +
      state.readOnly.map(function (r) {
        return '<div style="display:flex;gap:10px;padding:8px 0;border-top:1px solid var(--border-default);' +
          'font-size:13px;flex-wrap:wrap">' +
          '<div style="min-width:120px;color:var(--text-secondary)">' + u.esc(r.label) + '</div>' +
          '<div style="font-family:var(--font-mono,monospace);color:var(--text-primary);word-break:break-all">' +
          u.esc(r.value || '—') + '</div>' +
          '<div style="flex:1 1 100%;font-size:11.5px;color:var(--text-tertiary)">' + u.esc(r.note || '') + '</div>' +
          '</div>';
      }).join('') + '</div>';
  }

  /**
   * 메일 진단 — 설정이 채워졌는지와, 진짜로 나가는지는 다른 문제다.
   *
   * 라라벨의 기본 메일러가 `log` 라서 설정이 없어도 발송이 예외 없이 "성공" 한다.
   * 화면에는 «발송했습니다» 가 뜨고 원청은 영원히 못 받는다. 그래서 여기서 한 통을
   * 진짜로 보내 보고 서버가 뱉은 오류를 그대로 읽는다 — 그것만이 답이 된다.
   */
  function mailBlock() {
    var u = ui();
    var m = state.mail;
    if (!m) return '';

    var tone = m.ready ? 'var(--status-success,#16a34a)' : 'var(--status-warning,#f59e0b)';

    var rows = (m.rows || []).map(function (r) {
      return '<div style="display:flex;gap:10px;padding:8px 0;border-top:1px solid var(--border-default);' +
        'font-size:13px;flex-wrap:wrap;align-items:baseline">' +
        '<div style="min-width:120px;color:var(--text-secondary)">' + u.esc(r.label) + '</div>' +
        '<div style="font-family:var(--font-mono,monospace);color:' +
        (r.ok ? 'var(--text-primary)' : 'var(--status-warning,#f59e0b)') + ';word-break:break-all">' +
        (r.ok ? '' : '⚠ ') + u.esc(r.value || '—') + '</div>' +
        (r.note ? '<div style="flex:1 1 100%;font-size:11.5px;color:var(--text-tertiary);line-height:1.6">' +
          u.esc(r.note) + '</div>' : '') +
        '</div>';
    }).join('');

    return '<div style="margin-top:28px;padding:16px;border:1px solid var(--border-default);border-radius:12px;' +
      'background:var(--bg-surface)">' +
      '<div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:4px">메일 진단</div>' +
      '<div style="padding:10px 12px;margin:10px 0 4px;border-radius:8px;border-left:3px solid ' + tone + ';' +
      'background:var(--bg-panel);font-size:12.5px;color:var(--text-primary);line-height:1.6">' +
      (m.ready ? '✓ ' : '⚠ ') + u.esc(m.message || '') + '</div>' +
      rows +
      '<div style="margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">' +
      u.primaryButton('나에게 테스트 메일 보내기', 'window.AdminOrg.sendTestMail()', 'paper-plane-tilt') +
      '<span style="font-size:11.5px;color:var(--text-tertiary)">' +
      (m.testTo ? u.esc(m.testTo) + ' 로 한 통 갑니다.' : '내 계정에 이메일 주소가 없습니다.') +
      ' 주소는 고를 수 없습니다 — 본인에게만 갑니다.</span></div>' +
      '<div id="org-mail-result"></div>' +
      '</div>';
  }

  function sendTestMail() {
    var u = ui();
    var box = document.getElementById('org-mail-result');
    if (box) {
      box.innerHTML = '<div style="margin-top:12px;font-size:12px;color:var(--text-tertiary)">보내는 중…</div>';
    }

    global.gsRun('api_sendTestMail', [], null).then(function (res) {
      res = res || {};
      if (!box) return;

      if (res.success) {
        u.toast('테스트 메일을 보냈습니다.', 'success');
        box.innerHTML = '<div style="margin-top:12px;padding:11px 13px;border-radius:8px;' +
          'background:var(--bg-panel);border-left:3px solid var(--status-success,#16a34a);' +
          'font-size:12.5px;color:var(--text-primary);line-height:1.7">✓ ' + u.esc(res.message) +
          '<br><span style="color:var(--text-tertiary);font-size:11.5px">' +
          '안 오면 스팸함을 보세요. 스팸으로 갔다면 발신 도메인 인증(SPF/DKIM)이 필요합니다.</span></div>';
        return;
      }

      u.toast(res.error || '보내지 못했습니다.', 'error');
      // 서버가 받은 오류 원문을 자르지 않고 보여준다 — 잘라 내면 원인을 못 짚는다.
      box.innerHTML = '<div style="margin-top:12px;padding:11px 13px;border-radius:8px;' +
        'background:var(--bg-panel);border-left:3px solid var(--status-danger,#dc2626);' +
        'font-size:12.5px;color:var(--text-primary);line-height:1.7">✕ ' + u.esc(res.error || '') +
        (res.hint ? '<div style="margin-top:7px;color:var(--status-warning,#f59e0b)">→ ' + u.esc(res.hint) + '</div>' : '') +
        (res.detail ? '<pre style="margin:8px 0 0;padding:9px;background:var(--bg-base);border-radius:6px;' +
          'font-size:11px;white-space:pre-wrap;word-break:break-all;color:var(--text-secondary)">' +
          u.esc(res.detail) + '</pre>' : '') +
        '</div>';
    }).catch(function (e) {
      u.toast(e.message || '보내지 못했습니다.', 'error');
    });
  }

  function render() {
    var u = ui();
    if (!state.canManage) {
      return u.pageHeader('조직 설정', '이 배포가 누구의 것인지 정하는 곳입니다.') +
        '<div style="padding:36px;text-align:center;color:var(--text-tertiary);font-size:14px">' +
        '조직 설정은 최고 관리자만 볼 수 있습니다.</div>';
    }

    return u.pageHeader(
      '조직 설정',
      '여기 넣은 이름이 화면 · 앱 이름 · 이메일 · 인쇄물에 그대로 나갑니다.',
      u.primaryButton('저장', 'window.AdminOrg.save()', 'floppy-disk')
    ) +
      '<div style="max-width:560px">' +
      logoBlock() +
      state.fields.map(field).join('') +
      readOnlyBlock() +
      mailBlock() +
      '</div>';
  }

  function touch() { state.dirty = true; }

  function syncColor(v) {
    var el = document.getElementById('org-color');
    if (el) { el.value = v; touch(); }
  }

  function collect() {
    var out = {};
    state.fields.forEach(function (f) {
      var el = document.getElementById('org-' + f.key);
      out[f.key] = el ? el.value : '';
    });
    return out;
  }

  function clearErrors() {
    state.fields.forEach(function (f) {
      var el = document.getElementById('org-err-' + f.key);
      if (el) { el.style.display = 'none'; el.textContent = ''; }
    });
  }

  function showErrors(errors) {
    Object.keys(errors || {}).forEach(function (k) {
      var el = document.getElementById('org-err-' + k);
      if (el) { el.textContent = errors[k]; el.style.display = 'block'; }
    });
  }

  function applyLoad(res) {
    if (!res) return;
    if (res.fields) state.fields = res.fields;
    if (res.readOnly) state.readOnly = res.readOnly;
    if (res.logo) state.logo = res.logo;
    if ('canManage' in res) state.canManage = !!res.canManage;
  }

  function save() {
    var u = ui();
    clearErrors();
    call('api_saveOrgSettings', [collect()]).then(function (res) {
      if (!res.success) {
        if (res.errors) { showErrors(res.errors); u.toast('입력을 확인해 주세요.', 'error'); }
        else u.toast(res.error || '저장하지 못했습니다.', 'error');
        return;
      }
      applyLoad(res);
      state.dirty = false;
      paint(render());
      // 이름을 바꾸면 화면 곳곳(제목·사이드바)이 아직 옛 이름이다. 서버가 그리는
      // 자리라 새로고침해야 맞다 — 안 알려 주면 저장이 안 된 줄 안다.
      u.toast('저장했습니다. 화면 이름은 새로고침 후 바뀝니다.', 'success');
    }).catch(function (e) {
      u.toast(e.message || '저장하지 못했습니다.', 'error');
    });
  }

  function reload() {
    // 메일 상태를 함께 부른다. 실패해도 조직 설정 화면은 떠야 하므로 조용히 넘긴다.
    return Promise.all([
      call('api_getOrgSettings', []),
      global.gsRun('api_getMailStatus', [], null).catch(function () { return null; }),
    ]).then(function (r) {
      applyLoad(r[0]);
      state.mail = (r[1] && r[1].success) ? r[1] : null;
      paint(render());
    });
  }

  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    reload().catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
        ui().esc(e.message || '설정을 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminOrg = {
    render: renderScreen,
    save: save,
    touch: touch,
    syncColor: syncColor,
    uploadLogo: uploadLogo,
    sendTestMail: sendTestMail,
    removeLogo: removeLogo,
    _state: state,
  };
})(window);
