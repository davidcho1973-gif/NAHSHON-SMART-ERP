/**
 * 손님 링크 관리 — 공정 관리 화면의 ⚙ 메뉴에서 연다.
 *
 * 손님(발주처·방문객)에게 계정 없이 현장 공정 현황만 보여 주는 링크를 만들고 회수한다.
 * 링크는 전달되는 순간 복제되므로 "지우기"가 아니라 "회수"다 — 서버가 토큰을 죽이면
 * 이미 퍼진 링크도 그 자리에서 만료 화면이 된다.
 *
 * index.blade.php 가 아니라 별도 파일인 이유: 그 파일은 15,000 줄이고 한글이 깨진
 * 인코딩이라 큰 블록을 넣을수록 사고가 난다. (wbs-schedule.js 와 같은 이유.)
 */
(function () {
  'use strict';

  var UI = window.AdminUI || {};
  var esc = UI.esc || function (v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  function toast(msg, kind) {
    if (UI.toast) return UI.toast(msg, kind);
    alert(msg);
  }

  function gsRun(fn, args, dflt) {
    return window.gsRun ? window.gsRun(fn, args, dflt) : Promise.resolve(dflt);
  }

  function closeModal() {
    var m = document.getElementById('guest-link-modal');
    if (m) m.remove();
  }

  function openModal(innerHtml) {
    closeModal();
    var wrap = document.createElement('div');
    wrap.id = 'guest-link-modal';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.55);' +
      'display:flex;align-items:center;justify-content:center;padding:20px';
    wrap.innerHTML =
      '<div class="panel" style="max-width:640px;width:100%;max-height:86vh;overflow:auto">' +
        '<div class="panel-body padded">' + innerHtml + '</div>' +
      '</div>';
    wrap.addEventListener('click', function (e) { if (e.target === wrap) closeModal(); });
    document.body.appendChild(wrap);
    return wrap;
  }

  function siteOptionsHtml() {
    var names = window.SITE_NAMES || {};
    var current = (typeof window._siteId === 'function' ? window._siteId() : 'ALL');
    return Object.keys(names).filter(function (c) { return c !== 'ALL'; }).map(function (code) {
      return '<option value="' + esc(code) + '"' + (code === current ? ' selected' : '') + '>' +
        esc(names[code] || code) + '</option>';
    }).join('');
  }

  function copyText(text, btn) {
    var restore = btn ? btn.innerHTML : null;
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
      if (btn) { btn.innerHTML = '복사됨 ✓'; setTimeout(function () { btn.innerHTML = restore; }, 1500); }
    }).catch(function () { window.prompt('길게 눌러 복사하세요', text); });
  }

  function linkRow(l) {
    var state = l.revoked ? '회수됨' : (l.usable ? '' : '기간 만료');
    var dim = state ? 'opacity:.5;' : '';
    return '<div style="' + dim + 'display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border-default)">' +
      '<div style="flex:1;min-width:0">' +
        '<div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
          esc(l.siteName || l.siteCode || '') + (l.label ? ' · ' + esc(l.label) : '') +
          (state ? ' <span style="color:var(--status-danger);font-weight:700;font-size:11px">' + state + '</span>' : '') +
        '</div>' +
        '<div style="font-size:11px;color:var(--text-tertiary)">' +
          esc(l.createdAt || '') + ' 발급 · ' +
          (l.expiresAt ? esc(l.expiresAt) + ' 까지' : '무기한') +
          ' · 열람 ' + (l.viewCount || 0) + '회' +
        '</div>' +
      '</div>' +
      (l.usable
        ? '<button class="btn-secondary" style="padding:6px 10px;font-size:12px" onclick="window._guestLinkCopy(this,' + l.id + ')">복사</button>' +
          '<a class="btn-secondary" style="padding:6px 10px;font-size:12px;text-decoration:none" href="' + esc(l.qrUrl) + '" target="_blank" rel="noopener">QR</a>' +
          '<button class="btn-secondary" style="padding:6px 10px;font-size:12px;color:var(--status-danger)" onclick="window._guestLinkRevoke(' + l.id + ')">회수</button>'
        : '') +
      '</div>';
  }

  var _links = [];

  function render() {
    var listHtml = _links.length
      ? _links.map(linkRow).join('')
      : '<div style="padding:22px;text-align:center;color:var(--text-tertiary);font-size:12.5px">아직 만든 링크가 없습니다.</div>';

    openModal(
      '<div style="font-size:16px;font-weight:700;margin-bottom:4px"><i class="ph ph-link"></i> 손님 링크</div>' +
      '<div style="font-size:12px;color:var(--text-secondary);margin-bottom:16px;line-height:1.7">' +
        '손님(발주처·방문객)이 로그인 없이 그 현장의 <b>공정 현황만</b> 보는 링크입니다. ' +
        '돈·인원 정보는 나가지 않습니다. 잘못 퍼졌으면 <b>회수</b>를 누르세요 — 이미 전달된 링크도 그 자리에서 죽습니다.</div>' +

      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">' +
        '<select id="gl-site" class="search-inline" style="width:100%">' + siteOptionsHtml() + '</select>' +
        '<select id="gl-days" class="search-inline" style="width:100%">' +
          '<option value="7">7일</option><option value="30">30일</option>' +
          '<option value="90" selected>90일</option><option value="">무기한 (회수할 때까지)</option>' +
        '</select>' +
      '</div>' +
      '<div style="display:flex;gap:8px;margin-bottom:18px">' +
        '<input id="gl-label" class="search-inline" style="flex:1" placeholder="메모 — 누구에게 주는 링크인가요? (예: 발주처 GC)">' +
        '<button class="btn-primary" id="gl-create">링크 만들기</button>' +
      '</div>' +

      '<div style="font-size:12px;font-weight:700;color:var(--text-secondary);margin-bottom:2px">발급된 링크</div>' +
      listHtml +

      '<div class="action-row" style="justify-content:flex-end;margin-top:16px">' +
        '<button class="btn-secondary" onclick="window.closeGuestLinkModal()">닫기</button>' +
      '</div>'
    );

    var createBtn = document.getElementById('gl-create');
    createBtn.addEventListener('click', function () {
      var site = document.getElementById('gl-site').value;
      var label = document.getElementById('gl-label').value;
      var daysRaw = document.getElementById('gl-days').value;
      if (!site) { toast('현장을 선택하세요.', 'error'); return; }
      createBtn.disabled = true;
      gsRun('api_createGuestLink', [site, label || null, daysRaw === '' ? null : parseInt(daysRaw, 10)], { success: false })
        .then(function (res) {
          if (!res || !res.success) {
            toast((res && res.error) || '링크를 만들지 못했습니다.', 'error');
            createBtn.disabled = false;
            return;
          }
          toast('손님 링크를 만들었습니다. 복사해서 전달하세요.');
          load();
        });
    });
  }

  function load() {
    gsRun('api_getGuestLinks', ['ALL'], { success: false }).then(function (res) {
      if (!res || !res.success) {
        toast((res && res.error) || '손님 링크를 불러오지 못했습니다.', 'error');
        return;
      }
      _links = res.links || [];
      render();
    });
  }

  window.openGuestLinkModal = function () { load(); };
  window.closeGuestLinkModal = closeModal;

  window._guestLinkCopy = function (btn, id) {
    var l = _links.filter(function (x) { return x.id === id; })[0];
    if (l) copyText(l.url, btn);
  };

  window._guestLinkRevoke = function (id) {
    if (!window.confirm('이 링크를 회수할까요? 이미 전달된 링크도 즉시 열리지 않게 됩니다.')) return;
    gsRun('api_revokeGuestLink', [id], { success: false }).then(function (res) {
      if (!res || !res.success) {
        toast((res && res.error) || '회수하지 못했습니다.', 'error');
        return;
      }
      toast('회수했습니다.');
      load();
    });
  };
})();
