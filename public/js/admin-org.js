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
  var state = { fields: [], readOnly: [], canManage: false, dirty: false };

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
      state.fields.map(field).join('') +
      readOnlyBlock() +
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

  function save() {
    var u = ui();
    clearErrors();
    call('api_saveOrgSettings', [collect()]).then(function (res) {
      if (!res.success) {
        if (res.errors) { showErrors(res.errors); u.toast('입력을 확인해 주세요.', 'error'); }
        else u.toast(res.error || '저장하지 못했습니다.', 'error');
        return;
      }
      state.fields = res.fields || state.fields;
      state.readOnly = res.readOnly || state.readOnly;
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
    return call('api_getOrgSettings', []).then(function (res) {
      state.canManage = !!res.canManage;
      state.fields = res.fields || [];
      state.readOnly = res.readOnly || [];
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
    _state: state,
  };
})(window);
