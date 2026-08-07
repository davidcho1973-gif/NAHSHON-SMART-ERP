/**
 * SMART COMPANY — 관리자 화면 공통 틀.
 *
 * Filament 를 걷어내고 관리자 기능을 SPA 안으로 들여오면서, Filament 가 공짜로 주던 것들
 * (검색되는 표, 검증되는 폼, 저장 피드백, 삭제 확인)을 여기서 한 번만 만들어 여섯 화면이
 * 나눠 쓴다. 화면마다 표를 새로 짜면 열 정렬이나 빈 상태 문구가 화면마다 달라진다.
 *
 * index.blade.php 는 한글이 깨진 상태(mojibake)라 손대기 위험해서 이 파일로 분리했다.
 * 여기는 정상 UTF-8 이고 `node --check` 로 검증된다.
 *
 * 의존: window.gsRun(method, args, fallback) — SPA 가 이미 쓰는 API 호출기.
 */
(function (global) {
  'use strict';

  var TOKENS = {
    text: 'var(--text-primary)',
    dim: 'var(--text-secondary)',
    faint: 'var(--text-tertiary)',
    line: 'var(--border-default)',
    surface: 'var(--bg-surface)',
    panel: 'var(--bg-panel)',
    base: 'var(--bg-base)',
    danger: 'var(--status-danger)',
    ok: 'var(--status-success)',
    warn: 'var(--status-warning)',
    brand: 'var(--brand-primary)',
    radius: 'var(--radius-md)',
  };

  /**
   * 한국어 조사 선택 — 앞말의 받침 유무로 을/를, 이/가, 은/는 을 고른다.
   * "이름 을(를) 입력하세요" 처럼 괄호로 도망가면 화면이 번역기처럼 보인다.
   * 한글이 아닌 말(영문 라벨, 숫자로 끝나는 코드)은 받침을 알 수 없으므로 받침 있는 쪽으로 둔다.
   */
  function particle(word, withBatchim, withoutBatchim) {
    var s = String(word || '').replace(/[)\]\s]+$/, '');
    var last = s.charCodeAt(s.length - 1);
    if (isNaN(last)) return withBatchim;
    if (last >= 0xac00 && last <= 0xd7a3) {
      return (last - 0xac00) % 28 !== 0 ? withBatchim : withoutBatchim;
    }
    // 숫자로 끝나면 읽는 소리로 판단한다(3=삼 받침O, 2=이 받침X …).
    if (last >= 0x30 && last <= 0x39) {
      return '0136780'.indexOf(String.fromCharCode(last)) !== -1 ? withBatchim : withoutBatchim;
    }
    return withBatchim;
  }

  function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* ── 피드백 ──────────────────────────────────────────────────────────────
   * 저장이 됐는지 안 됐는지 모르는 게 관리자 화면에서 가장 답답한 부분이다.
   * 성공은 조용히 사라지고, 실패는 사용자가 닫을 때까지 남는다.
   */
  function toast(message, kind) {
    var host = document.getElementById('admin-toast-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'admin-toast-host';
      host.style.cssText =
        'position:fixed;right:20px;bottom:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;align-items:flex-end';
      document.body.appendChild(host);
    }
    var bad = kind === 'error';
    var el = document.createElement('div');
    el.setAttribute('role', 'status');
    el.style.cssText =
      'max-width:380px;padding:12px 14px;border-radius:' + TOKENS.radius + ';font-size:13px;line-height:1.5;' +
      'box-shadow:0 8px 24px rgba(0,0,0,.18);border:1px solid ' + (bad ? TOKENS.danger : TOKENS.line) + ';' +
      'background:' + (bad ? 'rgba(220,38,38,.10)' : TOKENS.surface) + ';color:' + TOKENS.text + ';' +
      'display:flex;gap:10px;align-items:flex-start';
    el.innerHTML =
      '<i class="ph ph-' + (bad ? 'warning-circle' : 'check-circle') + '" style="color:' +
      (bad ? TOKENS.danger : TOKENS.ok) + ';font-size:18px;flex-shrink:0"></i>' +
      '<span style="flex:1">' + esc(message) + '</span>';

    if (bad) {
      // 실패는 사라지면 안 된다 — 무엇이 저장 안 됐는지 읽을 시간을 줘야 한다.
      var x = document.createElement('button');
      x.type = 'button';
      x.textContent = '×';
      x.setAttribute('aria-label', '닫기');
      x.style.cssText =
        'background:none;border:none;color:' + TOKENS.dim + ';cursor:pointer;font-size:18px;line-height:1;padding:0';
      x.onclick = function () { el.remove(); };
      el.appendChild(x);
    } else {
      setTimeout(function () { el.remove(); }, 2600);
    }
    host.appendChild(el);
  }

  /* ── 확인 ────────────────────────────────────────────────────────────────
   * 삭제는 되돌릴 수 없으므로 무엇이 지워지는지 이름으로 보여준다.
   * "정말 삭제하시겠습니까?" 만 띄우면 무엇을 지우는지 모른 채 누르게 된다.
   */
  function confirmDanger(opts) {
    return new Promise(function (resolve) {
      var wrap = document.createElement('div');
      wrap.style.cssText =
        'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:20px';
      wrap.innerHTML =
        '<div role="dialog" aria-modal="true" style="background:' + TOKENS.surface + ';border:1px solid ' + TOKENS.line +
        ';border-radius:14px;max-width:420px;width:100%;padding:22px">' +
        '<div style="font-size:16px;font-weight:700;color:' + TOKENS.text + ';margin-bottom:8px">' + esc(opts.title || '삭제할까요?') + '</div>' +
        '<div style="font-size:13px;color:' + TOKENS.dim + ';line-height:1.6;margin-bottom:18px">' + esc(opts.body || '') + '</div>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end">' +
        '<button type="button" data-x="no" style="padding:9px 16px;border-radius:8px;border:1px solid ' + TOKENS.line +
        ';background:' + TOKENS.base + ';color:' + TOKENS.text + ';font-size:13px;cursor:pointer">취소</button>' +
        '<button type="button" data-x="yes" style="padding:9px 16px;border-radius:8px;border:none;background:' +
        TOKENS.danger + ';color:#fff;font-size:13px;font-weight:600;cursor:pointer">' + esc(opts.confirmLabel || '삭제') + '</button>' +
        '</div></div>';

      function done(v) { wrap.remove(); document.removeEventListener('keydown', onKey); resolve(v); }
      function onKey(e) { if (e.key === 'Escape') done(false); }

      wrap.addEventListener('click', function (e) {
        var a = e.target.getAttribute && e.target.getAttribute('data-x');
        if (a === 'yes') done(true);
        else if (a === 'no' || e.target === wrap) done(false);
      });
      document.addEventListener('keydown', onKey);
      document.body.appendChild(wrap);
      var yes = wrap.querySelector('[data-x="yes"]');
      if (yes) yes.focus();
    });
  }

  /* ── 표 ──────────────────────────────────────────────────────────────────
   * columns: [{ key, label, width, align, render(row) }]
   * 검색은 클라이언트에서 건다 — 관리자 목록은 수백 건 규모라 서버 왕복이 오히려 느리다.
   */
  function table(opts) {
    var cols = opts.columns || [];
    var rows = opts.rows || [];
    var id = opts.id || 'admin-table';

    var head = cols.map(function (c) {
      return '<th style="text-align:' + (c.align || 'left') + ';padding:10px 12px;font-size:11px;font-weight:600;' +
        'letter-spacing:.04em;text-transform:uppercase;color:' + TOKENS.faint + ';border-bottom:1px solid ' + TOKENS.line +
        ';white-space:nowrap' + (c.width ? ';width:' + c.width : '') + '">' + esc(c.label) + '</th>';
    }).join('');

    var body = rows.length
      ? rows.map(function (r, i) {
          return '<tr data-i="' + i + '" style="border-bottom:1px solid ' + TOKENS.line + '">' +
            cols.map(function (c) {
              var v = c.render ? c.render(r) : esc(r[c.key]);
              return '<td style="padding:11px 12px;font-size:13px;color:' + TOKENS.text +
                ';text-align:' + (c.align || 'left') + ';vertical-align:middle">' + (v === '' || v === null || v === undefined ? '<span style="color:' + TOKENS.faint + '">—</span>' : v) + '</td>';
            }).join('') + '</tr>';
        }).join('')
      : '<tr><td colspan="' + cols.length + '" style="padding:44px 12px;text-align:center;color:' + TOKENS.faint +
        ';font-size:13px">' + esc(opts.emptyText || '등록된 항목이 없습니다.') + '</td></tr>';

    return '' +
      '<div style="background:' + TOKENS.surface + ';border:1px solid ' + TOKENS.line + ';border-radius:12px;overflow:hidden">' +
        (opts.search === false ? '' :
          '<div style="padding:12px;border-bottom:1px solid ' + TOKENS.line + '">' +
          '<input type="search" id="' + id + '-q" placeholder="' + esc(opts.searchPlaceholder || '검색') + '" ' +
          'style="width:100%;max-width:320px;padding:8px 12px;border-radius:8px;border:1px solid ' + TOKENS.line +
          ';background:' + TOKENS.base + ';color:' + TOKENS.text + ';font-size:13px">' +
          '<span id="' + id + '-count" style="margin-left:10px;font-size:12px;color:' + TOKENS.faint + '">' + rows.length + '건</span>' +
          '</div>') +
        '<div style="overflow-x:auto"><table id="' + id + '" style="width:100%;border-collapse:collapse">' +
        '<thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>' +
      '</div>';
  }

  /** 표를 그린 뒤 호출 — 검색창을 실제로 동작시킨다. */
  function bindSearch(id) {
    var q = document.getElementById(id + '-q');
    var t = document.getElementById(id);
    var c = document.getElementById(id + '-count');
    if (!q || !t) return;
    q.addEventListener('input', function () {
      var term = q.value.trim().toLowerCase();
      var shown = 0;
      Array.prototype.forEach.call(t.tBodies[0].rows, function (tr) {
        if (!tr.hasAttribute('data-i')) return; // 빈 상태 행
        var hit = tr.innerText.toLowerCase().indexOf(term) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) shown++;
      });
      if (c) c.textContent = shown + '건';
    });
  }

  /* ── 폼 ──────────────────────────────────────────────────────────────────
   * fields: [{ name, label, type, required, options, hint, value, colSpan, group }]
   * type: text | email | tel | number | date | select | textarea | checkbox
   *
   * group 으로 묶으면 제목이 붙은 구역으로 나뉜다. "등록 폼이 불편하다" 는 대개 필드가
   * 20개씩 한 줄로 늘어서서 어디까지가 한 덩어리인지 안 보이기 때문이다.
   */
  function formModal(opts) {
    return new Promise(function (resolve) {
      var fields = opts.fields || [];
      var groups = [];
      fields.forEach(function (f) {
        var name = f.group || '';
        var g = groups.filter(function (x) { return x.name === name; })[0];
        if (!g) { g = { name: name, fields: [] }; groups.push(g); }
        g.fields.push(f);
      });

      function control(f) {
        var v = f.value === undefined || f.value === null ? '' : f.value;
        var common = 'id="af-' + esc(f.name) + '" name="' + esc(f.name) + '" ' +
          'style="width:100%;padding:9px 11px;border-radius:8px;border:1px solid ' + TOKENS.line +
          ';background:' + TOKENS.base + ';color:' + TOKENS.text + ';font-size:13px;font-family:inherit"';
        if (f.type === 'select') {
          return '<select ' + common + '>' +
            (f.required ? '' : '<option value="">— 선택 안 함 —</option>') +
            (f.options || []).map(function (o) {
              var ov = o.value !== undefined ? o.value : o;
              var ol = o.label !== undefined ? o.label : o;
              return '<option value="' + esc(ov) + '"' + (String(ov) === String(v) ? ' selected' : '') + '>' + esc(ol) + '</option>';
            }).join('') + '</select>';
        }
        if (f.type === 'textarea') {
          return '<textarea ' + common + ' rows="' + (f.rows || 3) + '">' + esc(v) + '</textarea>';
        }
        if (f.type === 'checkbox') {
          return '<label style="display:flex;gap:8px;align-items:center;font-size:13px;color:' + TOKENS.text + ';cursor:pointer">' +
            '<input type="checkbox" id="af-' + esc(f.name) + '" name="' + esc(f.name) + '"' + (v ? ' checked' : '') +
            ' style="width:16px;height:16px;cursor:pointer">' + esc(f.checkboxLabel || '예') + '</label>';
        }
        return '<input type="' + esc(f.type || 'text') + '" ' + common + ' value="' + esc(v) + '">';
      }

      var bodyHtml = groups.map(function (g) {
        return (g.name
          ? '<div style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:' +
            TOKENS.faint + ';margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid ' + TOKENS.line + '">' +
            esc(g.name) + '</div>'
          : '') +
          '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px">' +
          g.fields.map(function (f) {
            return '<div style="grid-column:span ' + (f.colSpan === 2 ? 2 : 1) + '">' +
              '<label for="af-' + esc(f.name) + '" style="display:block;font-size:12px;font-weight:600;color:' +
              TOKENS.dim + ';margin-bottom:5px">' + esc(f.label) +
              (f.required ? '<span style="color:' + TOKENS.danger + ';margin-left:3px">*</span>' : '') + '</label>' +
              control(f) +
              (f.hint ? '<div style="font-size:11px;color:' + TOKENS.faint + ';margin-top:4px;line-height:1.5">' + esc(f.hint) + '</div>' : '') +
              '<div data-err="' + esc(f.name) + '" style="display:none;font-size:11px;color:' + TOKENS.danger + ';margin-top:4px"></div>' +
              '</div>';
          }).join('') + '</div>';
      }).join('');

      var wrap = document.createElement('div');
      wrap.style.cssText =
        'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:20px';
      wrap.innerHTML =
        '<div role="dialog" aria-modal="true" aria-label="' + esc(opts.title || '') + '" style="background:' + TOKENS.surface +
        ';border:1px solid ' + TOKENS.line + ';border-radius:14px;max-width:720px;width:100%;max-height:88vh;display:flex;flex-direction:column">' +
        '<div style="padding:18px 22px;border-bottom:1px solid ' + TOKENS.line + ';display:flex;align-items:center;justify-content:space-between">' +
        '<div><div style="font-size:16px;font-weight:700;color:' + TOKENS.text + '">' + esc(opts.title || '') + '</div>' +
        (opts.subtitle ? '<div style="font-size:12px;color:' + TOKENS.dim + ';margin-top:3px">' + esc(opts.subtitle) + '</div>' : '') + '</div>' +
        '<button type="button" data-x="no" aria-label="닫기" style="background:none;border:none;color:' + TOKENS.dim +
        ';font-size:22px;cursor:pointer;line-height:1">×</button></div>' +
        '<div style="padding:6px 22px 22px;overflow-y:auto;flex:1">' + bodyHtml + '</div>' +
        '<div style="padding:14px 22px;border-top:1px solid ' + TOKENS.line + ';display:flex;gap:8px;justify-content:flex-end">' +
        '<button type="button" data-x="no" style="padding:9px 16px;border-radius:8px;border:1px solid ' + TOKENS.line +
        ';background:' + TOKENS.base + ';color:' + TOKENS.text + ';font-size:13px;cursor:pointer">취소</button>' +
        '<button type="button" data-x="save" style="padding:9px 18px;border-radius:8px;border:none;background:' + TOKENS.brand +
        ';color:#fff;font-size:13px;font-weight:600;cursor:pointer">' + esc(opts.saveLabel || '저장') + '</button>' +
        '</div></div>';

      function collect() {
        var out = {};
        fields.forEach(function (f) {
          var el = wrap.querySelector('#af-' + CSS.escape(f.name));
          if (!el) return;
          out[f.name] = f.type === 'checkbox' ? el.checked : el.value;
        });
        return out;
      }

      function showErrors(errs) {
        wrap.querySelectorAll('[data-err]').forEach(function (d) { d.style.display = 'none'; d.textContent = ''; });
        var first = null;
        Object.keys(errs || {}).forEach(function (k) {
          var d = wrap.querySelector('[data-err="' + CSS.escape(k) + '"]');
          if (!d) return;
          d.textContent = errs[k];
          d.style.display = 'block';
          if (!first) first = wrap.querySelector('#af-' + CSS.escape(k));
        });
        if (first) first.focus();
      }

      function close(v) { wrap.remove(); document.removeEventListener('keydown', onKey); resolve(v); }
      function onKey(e) { if (e.key === 'Escape') close(null); }

      wrap.addEventListener('click', function (e) {
        var a = e.target.getAttribute && e.target.getAttribute('data-x');
        if (a === 'no' || e.target === wrap) return close(null);
        if (a !== 'save') return;

        var values = collect();
        // 필수값은 서버에 보내기 전에 여기서 먼저 잡는다 — 왕복 한 번을 아끼고,
        // 어느 칸이 비었는지 그 자리에서 보여준다.
        var errs = {};
        fields.forEach(function (f) {
          if (f.required && f.type !== 'checkbox' && String(values[f.name] || '').trim() === '') {
            var verb = f.type === 'select' ? '선택하세요.' : '입력하세요.';
            errs[f.name] = f.label + particle(f.label, '을 ', '를 ') + verb;
          }
        });
        if (Object.keys(errs).length) { showErrors(errs); return; }

        var btn = e.target;
        btn.disabled = true;
        btn.textContent = '저장 중...';

        Promise.resolve(opts.onSave ? opts.onSave(values) : { success: true })
          .then(function (res) {
            if (res && res.success === false) {
              btn.disabled = false;
              btn.textContent = opts.saveLabel || '저장';
              if (res.errors) showErrors(res.errors);
              else toast(res.error || '저장하지 못했습니다.', 'error');
              return;
            }
            close(values);
          })
          .catch(function (err) {
            btn.disabled = false;
            btn.textContent = opts.saveLabel || '저장';
            toast((err && err.message) || '저장 중 오류가 발생했습니다.', 'error');
          });
      });

      document.addEventListener('keydown', onKey);
      document.body.appendChild(wrap);
      var firstInput = wrap.querySelector('input,select,textarea');
      if (firstInput) firstInput.focus();
    });
  }

  /** 화면 상단 제목 + 우측 버튼 한 줄. 여섯 화면이 같은 모양을 갖도록. */
  function pageHeader(title, description, actionsHtml) {
    return '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px;flex-wrap:wrap">' +
      '<div><h2 style="margin:0;font-size:20px;font-weight:700;color:' + TOKENS.text + '">' + esc(title) + '</h2>' +
      (description ? '<p style="margin:5px 0 0;font-size:13px;color:' + TOKENS.dim + ';line-height:1.6">' + esc(description) + '</p>' : '') +
      '</div><div style="display:flex;gap:8px;flex-wrap:wrap">' + (actionsHtml || '') + '</div></div>';
  }

  function primaryButton(label, onclickAttr, icon) {
    return '<button type="button" onclick="' + onclickAttr + '" style="padding:9px 16px;border-radius:8px;border:none;background:' +
      TOKENS.brand + ';color:#fff;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px">' +
      (icon ? '<i class="ph ph-' + esc(icon) + '"></i>' : '') + esc(label) + '</button>';
  }

  function rowButton(label, onclickAttr, kind) {
    var danger = kind === 'danger';
    return '<button type="button" onclick="' + onclickAttr + '" style="padding:5px 10px;border-radius:6px;border:1px solid ' +
      (danger ? TOKENS.danger : TOKENS.line) + ';background:transparent;color:' + (danger ? TOKENS.danger : TOKENS.dim) +
      ';font-size:12px;cursor:pointer">' + esc(label) + '</button>';
  }

  function badge(text, kind) {
    var color = kind === 'ok' ? TOKENS.ok : kind === 'warn' ? TOKENS.warn : kind === 'danger' ? TOKENS.danger : TOKENS.faint;
    return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;' +
      'border:1px solid ' + color + ';color:' + color + '">' + esc(text) + '</span>';
  }

  global.AdminUI = {
    esc: esc,
    particle: particle,
    toast: toast,
    confirmDanger: confirmDanger,
    table: table,
    bindSearch: bindSearch,
    formModal: formModal,
    pageHeader: pageHeader,
    primaryButton: primaryButton,
    rowButton: rowButton,
    badge: badge,
  };
})(window);
