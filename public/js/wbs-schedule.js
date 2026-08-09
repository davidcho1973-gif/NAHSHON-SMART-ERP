/**
 * 공정표 엑셀 교체 — 화면에서.
 *
 * 지금까지는 서버 터미널에서만 됐다. 공정표는 현장에서 자주 바뀌는데 그때마다 서버에
 * 접속해야 한다면 결국 아무도 안 바꾸고, 화면의 공정표는 엑셀과 조용히 어긋난 채로 남는다.
 *
 * 교체는 되돌리기 어려우므로 두 단계로 나눈다. 먼저 저장 없이 읽어서 "몇 개가 읽혔고
 * 무엇이 지워지는지" 를 보여 주고, 그걸 보고 확인한 뒤에만 실제로 바꾼다.
 * 헤더 이름이 하나만 달라도 0 개로 읽히는데, 그 상태로 바로 교체하면 공정표가 통째로 날아간다.
 *
 * index.blade.php 가 아니라 별도 파일인 이유: 그 파일은 15,000 줄이고 한글이 깨진 인코딩이라
 * 큰 블록을 넣을수록 사고가 난다.
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

  function csrf() {
    var el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : '';
  }

  function post(url, formData) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: formData
    }).then(function (r) {
      return r.json().catch(function () {
        return { success: false, error: '서버 응답을 읽지 못했습니다 (HTTP ' + r.status + ').' };
      }).then(function (body) {
        if (!body || typeof body !== 'object') body = {};
        if (!('success' in body)) body.success = r.ok;
        if (!body.success && !body.error) {
          body.error = r.status === 403
            ? '공정표를 바꿀 권한이 없습니다.'
            : (body.message || '요청이 거부되었습니다 (HTTP ' + r.status + ').');
        }
        return body;
      });
    }).catch(function (e) {
      return { success: false, error: e.message || '네트워크 오류' };
    });
  }

  function closeModal() {
    var m = document.getElementById('wbs-schedule-modal');
    if (m) m.remove();
  }

  function openModal(innerHtml) {
    closeModal();
    var wrap = document.createElement('div');
    wrap.id = 'wbs-schedule-modal';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.55);' +
      'display:flex;align-items:center;justify-content:center;padding:20px';
    wrap.innerHTML =
      '<div class="panel" style="max-width:720px;width:100%;max-height:86vh;overflow:auto">' +
        '<div class="panel-body padded">' + innerHtml + '</div>' +
      '</div>';
    wrap.addEventListener('click', function (e) { if (e.target === wrap) closeModal(); });
    document.body.appendChild(wrap);
    return wrap;
  }

  function currentProject() {
    var sel = document.getElementById('wbs-project-select');
    return sel ? sel.value : (window.WBS_CURRENT_PROJECT || '');
  }

  function siteId() {
    return (typeof window._siteId === 'function' ? window._siteId() : null) || 'ALL';
  }

  // ── 1단계: 파일 고르기 ────────────────────────────────────────────────

  window.openWbsScheduleReplace = function () {
    var project = currentProject();
    if (!project) {
      toast('먼저 프로젝트를 선택하세요.', 'error');
      return;
    }

    openModal(
      '<div style="font-size:16px;font-weight:700;margin-bottom:4px">공정표 엑셀 교체</div>' +
      '<div style="font-size:12px;color:var(--text-secondary);margin-bottom:16px">' +
        esc(project) + ' 의 공정표를 새 엑셀로 바꿉니다. 먼저 읽어서 확인한 뒤에 교체합니다.</div>' +

      '<label for="wbs-sched-file" style="display:block;border:2px dashed var(--border-default);border-radius:10px;' +
        'padding:28px;text-align:center;cursor:pointer">' +
        '<i class="ph ph-microsoft-excel-logo" style="font-size:32px;color:#22c55e"></i>' +
        '<div style="font-size:13px;font-weight:600;margin-top:8px">엑셀 파일(.xlsx) 선택</div>' +
        '<div id="wbs-sched-name" style="font-size:12px;color:var(--text-tertiary);margin-top:4px">선택된 파일 없음</div>' +
      '</label>' +
      '<input type="file" id="wbs-sched-file" accept=".xlsx" style="display:none">' +

      '<div style="font-size:11px;color:var(--text-tertiary);margin-top:12px;line-height:1.7">' +
        '필요한 헤더: <b>ID</b> · <b>작업명</b> · <b>공기(일)</b> (그 밖에 ES/EF일자, 선행작업, 여유, CP, 공종, 투입조를 읽습니다)<br>' +
        '마일스톤 행은 액티비티 <b>아래</b>에 두세요 — 그 위에 있으면 아래 전체가 마일스톤으로 읽힙니다.' +
      '</div>' +

      '<div class="action-row" style="justify-content:flex-end;gap:8px;margin-top:18px">' +
        '<button class="btn-secondary" onclick="window.closeWbsScheduleModal()">취소</button>' +
        '<button class="btn-primary" id="wbs-sched-preview-btn" disabled>읽어보기</button>' +
      '</div>'
    );

    var input = document.getElementById('wbs-sched-file');
    var btn = document.getElementById('wbs-sched-preview-btn');
    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      document.getElementById('wbs-sched-name').textContent = f ? f.name : '선택된 파일 없음';
      btn.disabled = !f;
    });
    btn.addEventListener('click', function () { runPreview(input.files[0], project); });
  };

  window.closeWbsScheduleModal = closeModal;

  // ── 2단계: 읽은 결과 보여 주기 ────────────────────────────────────────

  function runPreview(file, project) {
    var btn = document.getElementById('wbs-sched-preview-btn');
    if (btn) { btn.disabled = true; btn.textContent = '읽는 중...'; }

    var fd = new FormData();
    fd.append('schedule', file);
    fd.append('project_code', project);
    fd.append('site_id', siteId());

    post('/wbs-api/schedule/preview', fd).then(function (res) {
      if (!res.success) {
        if (btn) { btn.disabled = false; btn.textContent = '읽어보기'; }
        toast(res.error || '읽지 못했습니다.', 'error');
        return;
      }
      showPreview(res, project);
    });
  }

  function showPreview(res, project) {
    var read = res.read || {};
    var del = res.willDelete || {};
    var blocked = res.blocked;

    var sample = (read.sample || []).map(function (r) {
      return '<tr>' +
        '<td style="padding:4px 8px;font-family:monospace;font-size:11px">' + esc(r.id) + '</td>' +
        '<td style="padding:4px 8px">' + esc(r.name) + '</td>' +
        '<td style="padding:4px 8px;color:var(--text-tertiary)">' + esc(r.trade || '') + '</td>' +
        '<td style="padding:4px 8px;color:var(--text-tertiary);font-size:11px">' + esc(r.start || '') + '</td>' +
      '</tr>';
    }).join('');

    var warnings = (read.warnings || []).slice(0, 6).map(function (w) {
      return '<li style="margin-bottom:2px">' + esc(w) + '</li>';
    }).join('');

    // 서명은 법적 기록이라 따로 세워 보여 준다. 숫자가 0 이 아니면 눈에 걸려야 한다.
    var signatureWarning = (del.signatures > 0)
      ? '<div style="border-left:3px solid var(--status-danger);padding:10px 12px;margin-top:12px;' +
          'background:rgba(239,68,68,.08);border-radius:0 8px 8px 0">' +
          '<div style="font-weight:700;font-size:13px">TBM 서명 ' + del.signatures + '건이 함께 지워집니다</div>' +
          '<div style="font-size:12px;color:var(--text-secondary);margin-top:3px">' +
            '서명은 법적 기록입니다. 이미 진행된 프로젝트라면 교체 전에 내보내 두세요.</div>' +
        '</div>'
      : '';

    openModal(
      '<div style="font-size:16px;font-weight:700;margin-bottom:4px">읽기 결과 확인</div>' +
      '<div style="font-size:12px;color:var(--text-secondary);margin-bottom:16px">' +
        esc(res.fileName || '') + ' · ' + esc(project) + '</div>' +

      (blocked
        ? '<div style="border-left:3px solid var(--status-danger);padding:12px;background:rgba(239,68,68,.08);' +
            'border-radius:0 8px 8px 0;margin-bottom:14px;font-size:13px">' + esc(blocked) + '</div>'
        : '') +

      '<div style="display:flex;gap:20px;margin-bottom:14px">' +
        '<div><div style="font-size:22px;font-weight:700">' + (read.activities || 0) + '</div>' +
          '<div style="font-size:11px;color:var(--text-secondary)">읽힌 액티비티</div></div>' +
        '<div><div style="font-size:22px;font-weight:700">' + (read.milestones || 0) + '</div>' +
          '<div style="font-size:11px;color:var(--text-secondary)">마일스톤 → 단계</div></div>' +
        '<div><div style="font-size:22px;font-weight:700;color:#22c55e">' + (del.kept || 0) + '</div>' +
          '<div style="font-size:11px;color:var(--text-secondary)">유지 (진행률 보존)</div></div>' +
        '<div><div style="font-size:22px;font-weight:700;color:var(--status-warning)">' + (del.wbsItems || 0) + '</div>' +
          '<div style="font-size:11px;color:var(--text-secondary)">사라질 작업</div></div>' +
        '<div><div style="font-size:22px;font-weight:700;color:var(--status-warning)">' + (del.safetyCards || 0) + '</div>' +
          '<div style="font-size:11px;color:var(--text-secondary)">함께 지워질 안전카드</div></div>' +
      '</div>' +

      signatureWarning +

      (sample
        ? '<div style="font-size:12px;font-weight:600;margin:16px 0 6px">읽힌 내용 (앞 8줄)</div>' +
          '<div style="overflow:auto;border:1px solid var(--border-default);border-radius:8px">' +
          '<table style="width:100%;border-collapse:collapse;font-size:12px">' + sample + '</table></div>'
        : '') +

      (warnings
        ? '<div style="font-size:12px;font-weight:600;margin:16px 0 6px">경고</div>' +
          '<ul style="font-size:12px;color:var(--text-secondary);padding-left:18px;margin:0">' + warnings + '</ul>'
        : '') +

      '<div class="action-row" style="justify-content:flex-end;gap:8px;margin-top:20px">' +
        '<button class="btn-secondary" onclick="window.closeWbsScheduleModal()">취소</button>' +
        (blocked
          ? ''
          : '<button class="btn-primary" id="wbs-sched-commit" style="background:var(--status-danger);border:none">' +
              '공정표 교체</button>') +
      '</div>'
    );

    var commit = document.getElementById('wbs-sched-commit');
    if (commit) {
      commit.addEventListener('click', function () {
        commit.disabled = true;
        commit.textContent = '교체 중...';
        runReplace(res.token, project, commit);
      });
    }
  }

  // ── 3단계: 교체 ──────────────────────────────────────────────────────

  function runReplace(token, project, btn) {
    var fd = new FormData();
    fd.append('token', token);
    fd.append('project_code', project);
    fd.append('site_id', siteId());
    fd.append('confirm', '1');

    post('/wbs-api/schedule/replace', fd).then(function (res) {
      if (!res.success) {
        if (btn) { btn.disabled = false; btn.textContent = '공정표 교체'; }
        toast(res.error || '교체하지 못했습니다.', 'error');
        return;
      }

      closeModal();
      var imp = res.imported || {};
      toast('교체 완료 — 단계 ' + (imp.stages || 0) + ' / 작업 ' + (imp.tasks || 0) +
        ' / 세부 ' + (imp.subtasks || 0), 'success');

      // 캐시를 비우지 않으면 방금 바꾼 공정표가 아니라 옛것이 다시 그려진다.
      if (window.apiCache) window.apiCache = {};
      if (typeof window.refreshWbs === 'function') window.refreshWbs();
    });
  }
})();

/**
 * 행 사이에 새 작업 끼워 넣기.
 *
 * 기존 "작업 추가"는 "현장 추가(비계획)" 라는 별도 구간의 맨 끝에만 붙었다. 계획된 공정
 * 흐름 중간에 빠진 작업을 넣을 수가 없어서, 실제로는 엑셀을 다시 만들어 통째로 갈아끼우는
 * 수밖에 없었다.
 *
 * 번호(1.2.3)는 다시 매기지 않는다 — 도면·서류·안전카드에 이미 적힌 번호와 어긋난다.
 * 대신 1.2.3 과 1.2.4 사이는 1.2.3a 가 된다. 순서와 이름은 다른 것이고, 바뀌면 안 되는 쪽은 이름이다.
 */
(function () {
  'use strict';

  var UI = window.AdminUI || {};
  var esc = UI.esc || function (v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  function toast(m, k) { if (UI.toast) return UI.toast(m, k); alert(m); }

  window.openWbsInsertRow = function (afterWbsId) {
    var anchor = (window._wbsSubIndex || {})[afterWbsId] || {};
    var label = (anchor.activity_id ? anchor.activity_id + ' · ' : '') + (anchor.sub_name || afterWbsId);

    var wrap = document.createElement('div');
    wrap.id = 'wbs-insert-modal';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:9100;background:rgba(0,0,0,.55);' +
      'display:flex;align-items:center;justify-content:center;padding:20px';
    wrap.innerHTML =
      '<div class="panel" style="max-width:520px;width:100%">' +
      '<div class="panel-body padded">' +
        '<div style="font-size:16px;font-weight:700;margin-bottom:4px">이 행 아래에 작업 추가</div>' +
        '<div style="font-size:12px;color:var(--text-secondary);margin-bottom:16px">' +
          '기준: ' + esc(label) + '</div>' +

        '<label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">작업명 *</label>' +
        '<input id="wbs-ins-name" type="text" placeholder="예) 전선관 검사" ' +
          'style="width:100%;padding:9px 11px;border:1px solid var(--border-default);border-radius:8px;' +
          'background:var(--bg-base);color:var(--text-primary);font-size:13px;margin-bottom:12px">' +

        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">공종</label>' +
            '<input id="wbs-ins-trade" type="text" placeholder="' + esc(anchor.trade || '기준 행과 동일') + '" ' +
            'style="width:100%;padding:9px 11px;border:1px solid var(--border-default);border-radius:8px;' +
            'background:var(--bg-base);color:var(--text-primary);font-size:13px"></div>' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">공기(일)</label>' +
            '<input id="wbs-ins-days" type="number" min="0" placeholder="선택" ' +
            'style="width:100%;padding:9px 11px;border:1px solid var(--border-default);border-radius:8px;' +
            'background:var(--bg-base);color:var(--text-primary);font-size:13px"></div>' +
        '</div>' +

        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">시작일</label>' +
            '<input id="wbs-ins-start" type="date" ' +
            'style="width:100%;padding:9px 11px;border:1px solid var(--border-default);border-radius:8px;' +
            'background:var(--bg-base);color:var(--text-primary);font-size:13px"></div>' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">종료일</label>' +
            '<input id="wbs-ins-end" type="date" ' +
            'style="width:100%;padding:9px 11px;border:1px solid var(--border-default);border-radius:8px;' +
            'background:var(--bg-base);color:var(--text-primary);font-size:13px"></div>' +
        '</div>' +

        '<div style="font-size:11px;color:var(--text-tertiary);margin-top:10px;line-height:1.6">' +
          '날짜를 비우면 기준 행의 종료일에 붙습니다. 기존 번호는 바뀌지 않고, 새 행에는 ' +
          '기준 번호 뒤에 a·b·c 가 붙습니다.</div>' +

        '<div style="display:flex;gap:10px;margin-top:18px">' +
          '<button id="wbs-ins-cancel" class="btn-secondary" style="flex:1">취소</button>' +
          '<button id="wbs-ins-save" class="btn-primary" style="flex:1;background:#7c3aed">추가</button>' +
        '</div>' +
      '</div></div>';

    wrap.addEventListener('click', function (e) { if (e.target === wrap) wrap.remove(); });
    document.body.appendChild(wrap);
    document.getElementById('wbs-ins-name').focus();
    document.getElementById('wbs-ins-cancel').addEventListener('click', function () { wrap.remove(); });

    document.getElementById('wbs-ins-save').addEventListener('click', function () {
      var name = (document.getElementById('wbs-ins-name').value || '').trim();
      if (!name) { toast('작업명을 입력하세요.', 'error'); return; }

      var data = { name: name };
      var trade = (document.getElementById('wbs-ins-trade').value || '').trim();
      var days = (document.getElementById('wbs-ins-days').value || '').trim();
      var start = document.getElementById('wbs-ins-start').value;
      var end = document.getElementById('wbs-ins-end').value;
      if (trade) data.trade = trade;
      if (days) data.days = parseInt(days, 10);
      if (start) data.planned_start = start;
      if (end) data.planned_end = end;

      var btn = document.getElementById('wbs-ins-save');
      btn.disabled = true;
      btn.textContent = '추가 중...';

      window.gsRun('api_insertWbsRow', [afterWbsId, data], { success: false })
        .then(function (res) {
          if (!res || !res.success) {
            btn.disabled = false;
            btn.textContent = '추가';
            toast((res && res.error) || '추가하지 못했습니다.', 'error');
            return;
          }
          wrap.remove();
          toast('추가됨 — ' + (res.node_no || ''), 'success');
          // 캐시를 비우지 않으면 방금 넣은 행이 안 보인다.
          if (window.apiCache) window.apiCache = {};
          if (typeof window.refreshWbs === 'function') window.refreshWbs();
        });
    });
  };
})();
