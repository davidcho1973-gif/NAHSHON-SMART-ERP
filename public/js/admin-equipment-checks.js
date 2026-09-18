/**
 * 장비 사용 점검 — 관리 화면.
 *
 * ── 이 화면이 답해야 하는 것은 셋뿐이다 ────────────────────────────────
 *   ① 지금 <b>못 쓰는 장비</b>가 있나. 있으면 오늘 공정이 걸린다 — 맨 위에 둔다.
 *   ② 오늘 누가 무엇을 점검했고, 무엇이 걸렸나.
 *   ③ 질문지를 고칠 수 있나.
 *
 * 가동률·점검 건수 같은 숫자는 일부러 안 넣었다. 현황판에 숫자를 늘리면 정작 ①이
 * 묻힌다 — 이 저장소에서 상황실 화면으로 한 번 겪은 일이다.
 *
 * ── 스티커를 안 붙이면 아무 일도 안 일어난다 ───────────────────────────
 * 점검표를 아무리 잘 만들어도 QR 이 장비에 안 붙어 있으면 아무도 안 찍는다.
 * 그래서 「한 번도 점검 안 된 장비」 수를 상단에 같이 띄우고, 그 옆에 스티커
 * 인쇄 버튼을 둔다. 이 숫자가 이 기능의 실제 보급률이다.
 */
(function (global) {
  'use strict';

  var A = null;
  function ui() { if (!A) A = global.AdminUI; return A; }

  var state = { tab: 'board', board: null, templates: null, days: 14 };

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      if (res.success === false) throw new Error(res.error || '요청이 거부되었습니다.');
      return res;
    });
  }

  function paint(html) { document.getElementById('page-container').innerHTML = html; }

  function render() {
    paint('<div style="padding:60px;text-align:center;color:var(--text-tertiary)">장비 점검 기록을 불러오는 중…</div>');
    load().then(draw).catch(function (e) {
      paint('<div style="padding:60px;text-align:center;color:#dc2626">' + ui().esc(e.message) + '</div>');
    });
  }

  function load() {
    return call('api_getEquipmentChecks', [state.days]).then(function (r) { state.board = r; });
  }

  /* ══════════════════════ 그리기 ══════════════════════ */

  function draw() {
    var u = ui();
    var b = state.board || {};
    var html = u.pageHeader(
      '장비 사용 점검',
      '작업자가 장비에 붙은 QR 을 휴대폰 카메라로 찍으면 점검표가 열립니다. 치명 항목이 걸리면 그 장비는 자동으로 사용 금지가 됩니다.',
      '<a class="btn btn-primary" href="' + u.esc(b.stickerUrl || '/equipment-stickers') + '" target="_blank" rel="noopener">QR 스티커 인쇄</a>'
    );

    html += '<div class="tabs" style="margin:16px 0">' +
      tab('board', '점검 현황') + tab('templates', '점검표 편집') + '</div>';

    html += state.tab === 'templates' ? drawTemplates() : drawBoard();
    paint(html);

    // 표를 그린 뒤에 불러야 검색창이 실제로 동작한다. 안 부르면 칸은 있고 아무 일도
    // 안 일어나서, 쓰는 사람은 화면이 멈춘 줄 안다.
    if (state.tab !== 'templates') u.bindSearch('eq-check-log');
  }

  function tab(id, label) {
    var on = state.tab === id;
    return '<button type="button" class="btn ' + (on ? 'btn-primary' : '') + '" ' +
      'style="margin-right:8px" onclick="AdminEquipmentChecks.setTab(\'' + id + '\')">' + label + '</button>';
  }

  function drawBoard() {
    var u = ui();
    var b = state.board || {};
    var html = '';

    /* ── ① 못 쓰는 장비 ── */
    var blocked = b.blocked || [];
    if (blocked.length) {
      html += '<section style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:16px;margin-bottom:16px">' +
        '<h3 style="margin:0 0 4px;color:#dc2626">⛔ 지금 쓸 수 없는 장비 ' + blocked.length + '대</h3>' +
        '<p style="margin:0 0 12px;font-size:13.5px;color:#7f1d1d">' +
        '점검에서 치명 항목이 걸려 세워 둔 장비입니다. 고치거나 확인한 뒤 해제해야 다시 쓸 수 있습니다.</p>';
      html += blocked.map(function (e) {
        return '<div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:12px;margin-bottom:8px">' +
          '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">' +
          '<div><b>' + u.esc(e.code) + '</b> ' + u.esc(e.name || '') +
          (e.site ? ' <span style="color:#6b7280">· ' + u.esc(e.site) + '</span>' : '') +
          '<div style="font-size:13px;color:#6b7280;margin-top:2px">' +
          u.esc(e.since || '') + (e.by ? ' · ' + u.esc(e.by) : '') + '</div>' +
          (e.reasons && e.reasons.length
            ? '<ul style="margin:6px 0 0;padding-left:18px;font-size:13.5px;color:#b91c1c">' +
              e.reasons.map(function (r) { return '<li>' + u.esc(r) + '</li>'; }).join('') + '</ul>'
            : '') +
          '</div>' +
          (b.canManage
            ? '<button type="button" class="btn" onclick="AdminEquipmentChecks.clearBlock(' + e.id + ')">사용 금지 해제</button>'
            : '') +
          '</div></div>';
      }).join('');
      html += '</section>';
    } else {
      html += '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:14px;margin-bottom:16px;color:#047857">' +
        '✅ 사용 금지 상태인 장비가 없습니다.</div>';
    }

    /* ── 보급률 ── */
    if (b.neverChecked > 0) {
      html += '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:14px;margin-bottom:16px;color:#b45309">' +
        '한 번도 점검 기록이 없는 장비가 <b>' + b.neverChecked + '대</b> 있습니다. ' +
        'QR 스티커를 아직 안 붙였을 가능성이 큽니다 — 붙이지 않으면 아무도 찍지 않습니다.</div>';
    }

    /* ── ② 점검 기록 ── */
    var logs = b.logs || [];
    html += '<div style="margin-bottom:10px">최근 ' +
      '<select onchange="AdminEquipmentChecks.setDays(this.value)" style="padding:6px 10px">' +
      [7, 14, 30, 90].map(function (d) {
        return '<option value="' + d + '"' + (state.days === d ? ' selected' : '') + '>' + d + '일</option>';
      }).join('') + '</select> 점검 기록 ' + logs.length + '건</div>';

    if (!logs.length) {
      html += '<div style="padding:40px;text-align:center;color:var(--text-tertiary)">아직 점검 기록이 없습니다.</div>';
      return html;
    }

    // render() 로 내려보낸다 — AdminUI.table 은 render 가 없는 칸을 전부 이스케이프한다.
    // 그것이 맞다(남이 쓴 메모가 표에 들어오는 화면이다). 그림과 색이 필요한 두 칸만
    // 여기서 직접 만든다.
    html += u.table({
      id: 'eq-check-log',
      searchPlaceholder: '장비·점검자·이상 내용 검색',
      columns: [
        { key: 'at', label: '시각', width: '150px' },
        { key: 'equipment', label: '장비' },
        { key: 'stage', label: '구분', width: '80px' },
        { key: 'by', label: '점검자', width: '110px' },
        { key: 'result', label: '결과', width: '100px', render: function (r) {
          var color = r.resultCode === 'blocked' ? '#dc2626' : (r.resultCode === 'fail' ? '#b45309' : '#047857');
          return '<b style="color:' + color + '">' + u.esc(r.result) + '</b>';
        } },
        { key: 'detail', label: '이상 내용', render: function (r) {
          var out = (r.reasons || []).map(function (x) {
            return '<div style="color:#b91c1c">• ' + u.esc(x) + '</div>';
          }).join('');
          out += (r.photos || []).map(function (p) {
            return '<a href="' + u.esc(p) + '" target="_blank" rel="noopener" style="display:inline-block;margin:4px 4px 0 0">' +
              '<img src="' + u.esc(p) + '" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb"></a>';
          }).join('');
          return out;
        } },
      ],
      rows: logs.map(function (l) {
        return {
          at: l.at, equipment: l.equipment, stage: l.stage, by: l.by || '',
          result: l.result, resultCode: l.resultCode,
          reasons: l.reasons || [], photos: l.photos || [],
        };
      }),
    });

    return html;
  }

  /* ── ③ 점검표 편집 ── */

  function drawTemplates() {
    var u = ui();
    var t = state.templates;
    if (!t) {
      call('api_getEquipmentChecklistTemplates').then(function (r) {
        state.templates = r;
        draw();
      }).catch(function (e) { u.toast(e.message, 'error'); });
      return '<div style="padding:40px;text-align:center;color:var(--text-tertiary)">점검표를 불러오는 중…</div>';
    }

    var html = '<p style="color:var(--text-secondary);font-size:14px;margin:0 0 14px">' +
      '「필수」 항목은 <b>걸리면 그 장비를 못 쓰게 세웁니다.</b> 사람이 다치는 항목만 필수로 두세요 — ' +
      '필수가 많으면 현장이 멈추고, 멈추면 작업자들이 전부 「맞다」 만 누르게 됩니다.</p>';

    html += (t.templates || []).map(function (tpl) {
      return '<section style="background:#fff;border:1px solid var(--border,#e5e7eb);border-radius:12px;padding:16px;margin-bottom:12px">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">' +
        '<div><b>' + u.esc(tpl.name) + '</b> ' +
        '<span style="color:#6b7280;font-size:13px">· ' + u.esc(tpl.scopeLabel) + ' · ' + u.esc(tpl.stage) + '</span>' +
        (tpl.isDefault ? ' <span style="font-size:11.5px;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:2px 8px">기본표</span>' : '') +
        '</div>' +
        (t.canManage
          ? '<button type="button" class="btn" onclick="AdminEquipmentChecks.editItem(' + tpl.id + ',0)">항목 추가</button>'
          : '') +
        '</div>' +
        '<ul style="margin:12px 0 0;padding-left:0;list-style:none">' +
        (tpl.items || []).map(function (i) {
          return '<li style="padding:8px 0;border-top:1px solid #f3f4f6;display:flex;justify-content:space-between;gap:12px' +
            (i.status === 'archived' ? ';opacity:.45' : '') + '">' +
            '<span>' + (i.critical ? '<b style="color:#dc2626">[필수]</b> ' : '') + u.esc(i.ko) +
            '<div style="color:#6b7280;font-size:12.5px">' + u.esc(i.en) + ' / ' + u.esc(i.es) + '</div></span>' +
            (t.canManage
              ? '<button type="button" class="btn btn-sm" onclick="AdminEquipmentChecks.editItem(' + tpl.id + ',' + i.id + ')">고치기</button>'
              : '') +
            '</li>';
        }).join('') +
        '</ul></section>';
    }).join('');

    return html;
  }

  function editItem(templateId, itemId) {
    var u = ui();
    var tpl = (state.templates.templates || []).filter(function (x) { return x.id === templateId; })[0];
    if (!tpl) return;
    var item = (tpl.items || []).filter(function (x) { return x.id === itemId; })[0] || {};

    u.formModal({
      title: itemId ? '점검 항목 고치기' : '점검 항목 추가',
      // 세 언어를 한 화면에서 같이 받는다. 나눠 받으면 한 언어만 채워진 항목이 생기고,
      // 그 언어를 쓰는 작업자에게는 그 줄이 통째로 안 보인다.
      subtitle: '「그래야 하는 상태」 를 평서문으로 적으세요. 예: “브레이크가 정상으로 듣는다”. ' +
        '질문형으로 적으면 「맞다」 가 무슨 뜻인지 사람마다 다르게 읽습니다.',
      fields: [
        { name: 'ko', label: '한국어', type: 'text', required: true, value: item.ko || '' },
        { name: 'en', label: 'English', type: 'text', value: item.en || '', hint: '비우면 한국어가 들어갑니다' },
        { name: 'es', label: 'Español', type: 'text', value: item.es || '', hint: '비우면 한국어가 들어갑니다' },
        { name: 'severity', label: '중요도', type: 'select', value: item.severity || 'normal',
          options: [
            { value: 'normal', label: '일반 — 기록하고 반장이 봅니다' },
            { value: 'critical', label: '필수 — 걸리면 그 장비를 세웁니다' },
          ] },
        { name: 'status', label: '사용 여부', type: 'select', value: item.status || 'active',
          options: [
            { value: 'active', label: '사용' },
            { value: 'archived', label: '숨김 — 과거 기록은 그대로 남습니다' },
          ] },
      ],
      onSave: function (v) {
        v.id = itemId || 0;
        return call('api_saveEquipmentChecklistItem', [templateId, v]).then(function () {
          state.templates = null;
          draw();
          return { success: true };
        }).catch(function (e) { return { success: false, error: e.message }; });
      },
    });
  }

  function clearBlock(equipmentId) {
    var u = ui();
    u.formModal({
      title: '사용 금지 해제',
      subtitle: '고쳤거나 확인했다는 뜻입니다. 해제하면 그 장비를 다시 쓸 수 있게 됩니다.',
      fields: [{ name: 'note', label: '무엇을 확인했습니까?', type: 'textarea', required: true }],
      onSave: function (v) {
        return call('api_clearEquipmentBlock', [equipmentId, v.note]).then(function () {
          return load().then(function () { draw(); return { success: true }; });
        }).catch(function (e) { return { success: false, error: e.message }; });
      },
    });
  }

  function setTab(t) { state.tab = t; draw(); }

  function setDays(d) {
    state.days = parseInt(d, 10) || 14;
    render();
  }

  global.AdminEquipmentChecks = {
    render: render,
    setTab: setTab,
    setDays: setDays,
    editItem: editItem,
    clearBlock: clearBlock,
    _state: state,
  };
})(window);
