/**
 * 제출물 대장(submittals) + 물량/BOQ(boq) — 시방·도면에서 뽑은 계약 요구 추적.
 *
 * 두 화면은 같은 뼈대다: 프로젝트를 고르고, 시방·도면에서 임포트된 행들을
 * 필터로 좁혀 보고, 현장이 고칠 수 있는 것(상태·담당·날짜 / 수량·단가)만 고친다.
 * 행의 "내용"(조항 원문·산출 근거)은 임포트가 정본이라 화면에서 편집하지 않는다 —
 * 근거가 지워진 대장은 감리 앞에서 힘이 없다.
 *
 * 게이트(★) 행은 시방에 금지·실격·입회 같은 강제 조항이 걸린 항목이다.
 * 이 표에서 제일 먼저 눈에 띄어야 해서 배지로 세고 행에도 표시한다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = {
    // submittals
    sub: null, subProjectId: null, subF: { csi: '', category: '', status: '', gateOnly: false },
    // boq
    boq: null, boqProjectId: null, boqTab: '', boqReviewOnly: false,
  };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      if (res.success === false) throw new Error(res.error || '요청이 거부되었습니다.');
      return res;
    });
  }

  function paint(html) { document.getElementById('page-container').innerHTML = html; }

  function money(v) {
    return '$' + Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
  }

  function chip(label, value, tone) {
    var color = tone === 'danger' ? 'var(--danger,#dc2626)' : tone === 'ok' ? 'var(--success,#16a34a)' : 'var(--text-secondary)';
    return '<div style="padding:10px 14px;border:1px solid var(--border-default);border-radius:10px;background:var(--bg-surface)">' +
      '<div style="font-size:11px;color:var(--text-tertiary)">' + ui().esc(label) + '</div>' +
      '<div style="font-size:18px;font-weight:700;color:' + color + '">' + value + '</div></div>';
  }

  function projectSelect(list, current, onchangeAttr) {
    var u = ui();
    if (!list || list.length <= 1) return '';
    return '<select onchange="' + onchangeAttr + '" style="padding:8px 12px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:13px">' +
      list.map(function (p) {
        return '<option value="' + p.id + '"' + (p.id === current ? ' selected' : '') + '>' + u.esc(p.label) + '</option>';
      }).join('') + '</select>';
  }

  function filterSelect(id, label, values, current, onchangeAttr) {
    var u = ui();
    return '<select id="' + id + '" onchange="' + onchangeAttr + '" ' +
      'style="padding:7px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:12px">' +
      '<option value="">' + u.esc(label) + ' 전체</option>' +
      values.map(function (v) {
        return '<option value="' + u.esc(v) + '"' + (v === current ? ' selected' : '') + '>' + u.esc(v) + '</option>';
      }).join('') + '</select>';
  }

  /* ══════════════════════ 제출물 대장 ══════════════════════ */

  function catBadge(row) {
    var u = ui();
    var cat = row.category;
    var kind = cat === 'Action 제출물' ? 'warn' : cat === 'Closeout 제출물' ? 'ok' : cat === '시험·검사' ? 'danger' : '';
    var badge = u.badge(cat, kind);

    // 근거가 있는 줄은 구분 배지가 곧 원문으로 가는 문이다. 추출할 때 AI 가 어느
    // 문서의 어느 문장인지 적어 두었으므로, 여기서 되찾을 필요 없이 바로 연다.
    if (!row.sourceDocumentId) return badge;
    return '<button type="button" title="시방 원문 보기" ' +
      'onclick="window.AdminRegisters.openSource(' + row.sourceDocumentId + ', \'submittal\', ' + row.id + ')" ' +
      'style="border:none;background:none;padding:0;cursor:pointer;text-align:left;display:block">' +
      badge + '<span style="display:block;font-size:10.5px;color:var(--brand-primary);margin-top:3px">🔗 원문 보기</span></button>';
  }

  function statusBadgeKind(s) {
    if (s === '승인') return 'ok';
    if (s === '반려') return 'danger';
    if (s === '제출' || s === '재제출' || s === '조건부승인') return 'warn';
    return '';
  }

  function subRows() {
    var f = state.subF;
    return (state.sub.rows || []).filter(function (r) {
      if (f.csi && r.csi !== f.csi) return false;
      if (f.category && r.category !== f.category) return false;
      if (f.status && r.status !== f.status) return false;
      if (f.gateOnly && !r.gate) return false;
      return true;
    });
  }

  function drawSubmittals() {
    var u = ui();
    var d = state.sub;
    var rows = subRows();
    var st = d.stats || { total: 0, gate: 0, byStatus: {} };
    var canManage = !!d.canManage;

    var uniq = function (key) {
      var seen = {};
      (d.rows || []).forEach(function (r) { seen[r[key]] = true; });
      return Object.keys(seen);
    };

    var statusCell = function (r) {
      if (!canManage) return u.badge(r.status, statusBadgeKind(r.status));
      return '<select onchange="window.AdminRegisters.quickStatus(' + r.id + ', this.value)" ' +
        'style="padding:5px 8px;border-radius:6px;border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:12px">' +
        (d.statuses || []).map(function (s) {
          return '<option value="' + u.esc(s) + '"' + (s === r.status ? ' selected' : '') + '>' + u.esc(s) + '</option>';
        }).join('') + '</select>';
    };

    var html =
      u.pageHeader('제출물 대장', '시방서 15개 공종 + 도면 노트에서 전수 추출한 제출물·QA·시험 요구. ★는 시방 명문 정지·실격 조항(우선관리).') +
      '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">' +
        chip('전체', st.total) +
        chip('게이트 ★', st.gate, 'danger') +
        chip('승인', (st.byStatus && st.byStatus['승인']) || 0, 'ok') +
        chip('제출·재제출', ((st.byStatus && st.byStatus['제출']) || 0) + ((st.byStatus && st.byStatus['재제출']) || 0)) +
        chip('반려', (st.byStatus && st.byStatus['반려']) || 0, 'danger') +
      '</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">' +
        projectSelect(d.projects, d.projectId, 'window.AdminRegisters.setSubProject(this.value)') +
        filterSelect('sub-f-csi', '공종', uniq('csi'), state.subF.csi, 'window.AdminRegisters.setSubFilter(\'csi\', this.value)') +
        filterSelect('sub-f-cat', '구분', uniq('category'), state.subF.category, 'window.AdminRegisters.setSubFilter(\'category\', this.value)') +
        filterSelect('sub-f-st', '상태', d.statuses || [], state.subF.status, 'window.AdminRegisters.setSubFilter(\'status\', this.value)') +
        '<label style="display:flex;gap:6px;align-items:center;font-size:12px;color:var(--text-secondary);cursor:pointer">' +
          '<input type="checkbox"' + (state.subF.gateOnly ? ' checked' : '') + ' onchange="window.AdminRegisters.setSubFilter(\'gateOnly\', this.checked)">게이트만' +
        '</label>' +
      '</div>' +
      u.table({
        id: 'sub-tbl',
        searchPlaceholder: '조항·공종·담당 검색',
        emptyText: '조건에 맞는 항목이 없습니다.',
        columns: [
          { key: 'seq', label: '번호', width: '52px', align: 'center' },
          { key: 'csi', label: '공종', width: '110px', render: function (r) {
              return '<div style="font-family:ui-monospace,monospace;font-size:12px">' + u.esc(r.csi) + '</div>' +
                '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.section) + '</div>';
            } },
          { key: 'category', label: '구분', width: '130px', render: catBadge },
          { key: 'title', label: '제출물 · 요구사항', render: function (r) {
              return '<div style="font-size:12.5px;line-height:1.55;white-space:normal;min-width:340px">' +
                (r.gate ? '<span style="color:var(--danger,#dc2626);font-weight:700">★ </span>' : '') + u.esc(r.title) + '</div>' +
                // 근거가 어느 문서인지 그 줄에 적는다 — 눌러서 확인할 것인지 사람이 바로 판단한다.
                (r.sourceDocument ? '<div style="font-size:10.5px;color:var(--text-tertiary);margin-top:4px;white-space:normal">📄 ' +
                  u.esc(r.sourceDocument) + (r.extractedBy ? ' · ' + u.esc(r.extractedBy) + ' 판독' : '') +
                  (r.confidence != null ? ' · 확신도 ' + r.confidence : '') + '</div>' : '') +
                (r.needsReview ? '<div style="font-size:11px;color:var(--danger,#dc2626);white-space:normal;margin-top:3px">🔍 ' +
                  u.esc(r.reviewReason || '확인 필요') + '</div>' : '') +
                // 이 조항을 채우려고 받아 둔 자료 — 대장이 곧 서류철이다.
                ((r.documents || []).length ? '<div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:4px">' +
                  r.documents.map(function (doc) {
                    return '<button type="button" onclick="window.AdminRegisters.openSource(' + doc.id + ')" ' +
                      'style="border:1px solid var(--border-default);background:var(--bg-base);border-radius:999px;' +
                      'padding:3px 10px;font-size:10.5px;color:var(--text-primary);cursor:pointer;max-width:260px;' +
                      'overflow:hidden;text-overflow:ellipsis;white-space:nowrap">📎 ' + u.esc(doc.label) + '</button>';
                  }).join('') + '</div>' : '');
            } },
          { key: 'status', label: '상태', width: '120px', render: statusCell },
          { key: 'assignee', label: '담당 · 일정', width: '170px', render: function (r) {
              var lines = [];
              if (r.vendorName || r.vendorEmail) lines.push('업체 ' + u.esc(r.vendorName || r.vendorEmail));
              if (r.recipientName || r.recipientEmail) lines.push('수신 ' + u.esc(r.recipientName || r.recipientEmail));
              if (r.assignee) lines.push(u.esc(r.assignee));
              if (r.plannedOn) lines.push('계획 ' + r.plannedOn);
              if (r.submittedOn) lines.push('제출 ' + r.submittedOn);
              if (r.approvedOn) lines.push('승인 ' + r.approvedOn);
              // 마지막 소통 — 목록만 봐도 어디까지 왔는지 보인다.
              if (r.lastComm) lines.push('<span style="color:var(--brand-primary)">' + u.esc(r.lastComm) + '</span>');
              return lines.length ? '<div style="font-size:11px;color:var(--text-secondary);line-height:1.6">' + lines.join('<br>') + '</div>' : '';
            } },
          { key: '_act', label: '', width: '170px', align: 'right', render: function (r) {
              if (!canManage) return '';
              // 제품 자료는 제조사가 웹에 공개해 둔다 — AI 가 찾고, 사람이 고르고, 편철된다.
              return '<div style="display:flex;flex-wrap:wrap;gap:4px;justify-content:flex-end">' +
                u.rowButton('📮 소통', 'window.AdminRegisters.openComms(' + r.id + ')') +
                u.rowButton('🌐 AI 조사', 'window.AdminRegisters.researchSubmittal(' + r.id + ')') +
                // 조항을 읽고 업체에 보낼 요청서를 매번 손으로 쓰던 일 — 그 편지를 대신 쓴다.
                u.rowButton('📨 자료요청', 'window.AdminRegisters.requestVendorData(' + r.id + ')') +
                u.rowButton('기록', 'window.AdminRegisters.openSubmittal(' + r.id + ')') +
                '</div>';
            } },
        ],
        rows: rows,
      });

    paint(html);
    u.bindSearch('sub-tbl');
  }

  /**
   * 조항 → 업체 자료 요청서 → 문서함 편철.
   * 업체명은 선택이다 — 아직 업체가 안 정해졌어도 요청서 틀은 미리 만들어 둘 수 있다.
   */
  function requestVendorData(id) {
    var u = ui();
    var row = (state.sub.rows || []).filter(function (r) { return r.id === id; })[0];
    if (!row) return;

    u.formModal({
      title: '업체 자료 요청서 만들기',
      subtitle: (row.csi ? '[' + row.csi + '] ' : '') + (row.section || '') +
        ' — 이 조항이 요구하는 자료를 낱개로 정리해 요청서를 쓰고 문서함에 넣습니다.',
      saveLabel: '만들기',
      fields: [
        { name: 'vendor', label: '수신 업체 (선택)', colSpan: 2, value: '',
          hint: '비워 두면 "(업체명)" 으로 두고 나중에 채울 수 있습니다.' },
      ],
      onSave: function (v) {
        return call('api_requestVendorData', [id, v.vendor || null]).then(function (res) {
          if (res.success === false) return res;
          u.toast(res.message || '요청서를 만들었습니다.');
          return { success: true };
        });
      },
    });
  }

  /**
   * 제품 자료 AI 웹 조사 — 후보를 보여 주고, 사람이 고른 것만 받아 편철한다.
   * AI 가 찾은 것은 후보다: 틀린 모델의 스팩을 제출하면 반려로 끝나지 않는다.
   */
  function researchSubmittal(id) {
    var u = ui();
    var row = (state.sub.rows || []).filter(function (r) { return r.id === id; })[0];
    if (!row) return;

    var wrap = document.createElement('div');
    wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.5);display:flex;' +
      'align-items:center;justify-content:center;padding:20px';
    wrap.innerHTML = '<div style="background:var(--bg-surface);border:1px solid var(--border-default);border-radius:14px;' +
      'width:min(860px,95vw);max-height:86vh;display:flex;flex-direction:column;overflow:hidden">' +
      '<div style="padding:16px 18px;border-bottom:1px solid var(--border-default)">' +
        '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">' +
          '<div style="min-width:0">' +
            '<div style="font-size:15px;font-weight:700;color:var(--text-primary)">🌐 AI 자료 조사</div>' +
            '<div style="font-size:11.5px;color:var(--text-tertiary);margin-top:3px;word-break:keep-all">' +
              u.esc((row.csi ? '[' + row.csi + '] ' : '') + row.title.slice(0, 140)) + '</div>' +
          '</div>' +
          '<button type="button" data-x="close" style="padding:7px 13px;border-radius:8px;border:1px solid var(--border-default);' +
            'background:var(--bg-base);color:var(--text-primary);font-size:12.5px;cursor:pointer;flex-shrink:0">닫기</button>' +
        '</div>' +
      '</div>' +
      '<div id="reg-research-body" style="flex:1;overflow:auto;padding:16px 18px">' +
        '<div style="text-align:center;padding:44px 16px;color:var(--text-tertiary);font-size:13px;line-height:1.8">' +
          'AI 가 웹에서 제조사 자료를 찾고 있습니다…<br>규격(ASTM·Type)이 맞는 제품만 고르느라 30초쯤 걸립니다.</div>' +
      '</div></div>';

    function close() { wrap.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    wrap.addEventListener('click', function (e) {
      if (e.target === wrap || (e.target.getAttribute && e.target.getAttribute('data-x') === 'close')) close();
    });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(wrap);

    call('api_researchSubmittal', [id]).then(function (res) {
      var body = wrap.querySelector('#reg-research-body');
      if (!body) return;
      var list = res.candidates || [];
      if (!list.length) {
        body.innerHTML = '<div style="text-align:center;padding:40px 16px;color:var(--text-tertiary);font-size:13px;line-height:1.8">' +
          '규격이 맞는 자료를 찾지 못했습니다.<br>「📨 자료요청」으로 업체에 직접 요청하는 것이 빠릅니다.</div>';
        return;
      }
      body.innerHTML =
        '<div style="font-size:11.5px;color:var(--text-tertiary);margin-bottom:12px;word-break:keep-all">' +
          u.esc(res.engine || 'AI') + ' 가 찾은 후보 ' + list.length + '개 — <b>규격이 조항과 맞는지 확인한 뒤</b> 받으세요. ' +
          'PDF 직링크는 바로 편철되고, 웹페이지는 열어서 직접 받아야 합니다.</div>' +
        list.map(function (c, i) {
          return '<div style="border:1px solid var(--border-default);border-radius:10px;padding:12px 14px;margin-bottom:9px">' +
            '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">' +
              '<div style="min-width:0">' +
                '<div style="font-size:13px;font-weight:700;color:var(--text-primary);word-break:keep-all">' +
                  u.esc(c.maker || '제조사 미상') + ' — ' + u.esc(c.product || '') + '</div>' +
                (c.why ? '<div style="font-size:11.5px;color:var(--text-secondary);margin-top:4px;line-height:1.6;word-break:keep-all">' + u.esc(c.why) + '</div>' : '') +
                '<div style="font-size:10.5px;color:var(--text-tertiary);margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:520px">' + u.esc(c.url) + '</div>' +
              '</div>' +
              '<div style="display:flex;gap:6px;flex-shrink:0;flex-direction:column">' +
                '<a href="' + u.esc(c.url) + '" target="_blank" rel="noopener noreferrer" style="padding:6px 12px;border-radius:7px;' +
                  'border:1px solid var(--border-default);background:var(--bg-base);color:var(--text-primary);font-size:11.5px;text-decoration:none;text-align:center">페이지 열기</a>' +
                (c.file === 'pdf'
                  ? '<button type="button" data-i="' + i + '" style="padding:6px 12px;border-radius:7px;border:none;background:var(--brand-primary);' +
                    'color:#fff;font-size:11.5px;font-weight:600;cursor:pointer">받아서 편철</button>'
                  : '') +
              '</div>' +
            '</div></div>';
        }).join('');

      body.querySelectorAll('button[data-i]').forEach(function (btn) {
        btn.onclick = function () {
          btn.disabled = true; btn.textContent = '받는 중…';
          call('api_fileSubmittalResearch', [id, parseInt(btn.getAttribute('data-i'), 10)]).then(function (r2) {
            if (r2.success === false) { u.toast(r2.error || '편철 실패', 'error'); btn.disabled = false; btn.textContent = '받아서 편철'; return; }
            u.toast(r2.message || '편철했습니다.');
            btn.textContent = '✓ 편철됨';
            reloadSubmittals().then(drawSubmittals);
          }).catch(function (e) { u.toast(e.message, 'error'); btn.disabled = false; btn.textContent = '받아서 편철'; });
        };
      });
    }).catch(function (e) {
      var body = wrap.querySelector('#reg-research-body');
      if (body) body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--danger,#dc2626);font-size:13px">' + u.esc(e.message) + '</div>';
    });
  }

  /**
   * 소통 창 — 담당자·요청·받기·전달·승인이 한 화면에.
   * 원칙: 단계가 상태를 움직인다(요청→작성중, 전달→제출, 승인본→승인).
   * 화면에서 상태만 바꾸는 것도 되지만, 소통으로 움직인 상태는 기록이 함께 남는다.
   */
  function openComms(id) {
    var u = ui();
    var row = (state.sub.rows || []).filter(function (r) { return r.id === id; })[0];
    if (!row) return;

    var wrap = document.createElement('div');
    wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.5);display:flex;' +
      'align-items:center;justify-content:center;padding:20px';
    wrap.innerHTML = '<div style="background:var(--bg-surface);border:1px solid var(--border-default);border-radius:14px;' +
      'width:min(880px,95vw);max-height:88vh;display:flex;flex-direction:column;overflow:hidden">' +
      '<div style="padding:15px 18px;border-bottom:1px solid var(--border-default);display:flex;justify-content:space-between;gap:12px;align-items:flex-start">' +
        '<div style="min-width:0">' +
          '<div style="font-size:15px;font-weight:700;color:var(--text-primary)">📮 제출물 소통 — #' + row.seq + '</div>' +
          '<div style="font-size:11.5px;color:var(--text-tertiary);margin-top:3px;word-break:keep-all">' +
            u.esc((row.csi ? '[' + row.csi + '] ' : '') + row.title.slice(0, 120)) + '</div>' +
        '</div>' +
        '<button type="button" data-x="close" style="padding:7px 13px;border-radius:8px;border:1px solid var(--border-default);' +
          'background:var(--bg-base);color:var(--text-primary);font-size:12.5px;cursor:pointer;flex-shrink:0">닫기</button>' +
      '</div>' +
      '<div id="reg-comms-body" style="flex:1;overflow:auto;padding:16px 18px">' +
        '<div style="text-align:center;padding:30px;color:var(--text-tertiary)">불러오는 중…</div>' +
      '</div></div>';

    function close() { wrap.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    wrap.addEventListener('click', function (e) {
      if (e.target === wrap || (e.target.getAttribute && e.target.getAttribute('data-x') === 'close')) close();
    });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(wrap);

    var inputStyle = 'width:100%;padding:8px 10px;border-radius:8px;border:1px solid var(--border-default);' +
      'background:var(--bg-base);color:var(--text-primary);font-size:12.5px;box-sizing:border-box';
    var btnStyle = 'padding:8px 14px;border-radius:8px;border:none;background:var(--brand-primary);color:#fff;' +
      'font-size:12.5px;font-weight:600;cursor:pointer';
    var btn2Style = 'padding:8px 14px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);' +
      'color:var(--text-primary);font-size:12.5px;cursor:pointer';

    function act(action, args, btn) {
      if (btn) btn.disabled = true;
      return call('api_submittalComms', [action, id, args || {}]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '실패했습니다.', 'error'); return null; }
        if (res.message) u.toast(res.message);
        // 메일 서버가 없으면 사장님 메일앱으로 — 보낼 내용은 이미 채워져 있다.
        if (res.mailto) window.location.href = res.mailto;
        reloadSubmittals().then(drawSubmittals);
        return res;
      }).catch(function (e) { u.toast(e.message, 'error'); return null; })
        .finally(function () { if (btn) btn.disabled = false; });
    }

    function render(d) {
      var body = wrap.querySelector('#reg-comms-body');
      if (!body) return;
      var c = d.contacts || {};
      var field = function (label, name, value, type) {
        return '<div><label style="display:block;font-size:10.5px;font-weight:700;color:var(--text-tertiary);margin-bottom:4px">' + label + '</label>' +
          '<input data-c="' + name + '" type="' + (type || 'text') + '" value="' + u.esc(value || '') + '" style="' + inputStyle + '"></div>';
      };
      var mailNote = d.mailReady ? '' :
        '<div style="font-size:11px;color:var(--warning,#b45309);margin-top:6px;word-break:keep-all">' +
        '메일 서버가 아직 없어, 보내기를 누르면 <b>사장님 메일앱</b>이 내용이 채워진 채 열립니다. 환경변수에 MAIL 설정을 넣으면 ERP 가 직접 보냅니다.</div>';

      body.innerHTML =
        // ── 담당자 ──
        '<div style="border:1px solid var(--border-default);border-radius:10px;padding:14px;margin-bottom:12px">' +
          '<div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:10px">담당자</div>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">' +
            field('자료 제공 업체', 'vendorName', c.vendorName) +
            field('업체 이메일', 'vendorEmail', c.vendorEmail, 'email') +
            field('업체 전화', 'vendorPhone', c.vendorPhone) +
            field('최종 수신 (원청·감리)', 'recipientName', c.recipientName) +
            field('수신 이메일', 'recipientEmail', c.recipientEmail, 'email') +
            '<div style="display:flex;align-items:flex-end;gap:8px">' +
              '<label style="display:flex;gap:5px;align-items:center;font-size:11px;color:var(--text-secondary);cursor:pointer;white-space:nowrap">' +
                '<input type="checkbox" id="comms-apply-csi">같은 공종 전체 적용</label>' +
              '<button type="button" id="comms-save" style="' + btnStyle + '">저장</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
        // ── 진행 단계 ──
        '<div style="border:1px solid var(--border-default);border-radius:10px;padding:14px;margin-bottom:12px">' +
          '<div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:10px">진행 — 현재 상태: ' +
            '<span style="color:var(--brand-primary)">' + u.esc(d.status || '') + '</span></div>' +
          '<div style="display:flex;flex-wrap:wrap;gap:8px">' +
            '<button type="button" id="comms-request" style="' + btnStyle + '"' + (c.vendorEmail ? '' : ' disabled title="업체 이메일을 먼저 넣으세요"') + '>① 업체에 요청 메일</button>' +
            '<button type="button" id="comms-link-received" style="' + btn2Style + '">② 받은 자료 연결</button>' +
            '<button type="button" id="comms-transmit" style="' + btnStyle + '"' + (c.recipientEmail ? '' : ' disabled title="수신 이메일을 먼저 넣으세요"') + '>③ 원청에 전달 (자료 첨부)</button>' +
            '<button type="button" id="comms-link-approval" style="' + btn2Style + '">④ 승인본 연결</button>' +
          '</div>' + mailNote +
        '</div>' +
        // ── 연결 후보 (기본 접힘) ──
        '<div id="comms-picker" style="display:none;border:1px solid var(--brand-primary);border-radius:10px;padding:14px;margin-bottom:12px">' +
          '<div style="font-size:12.5px;font-weight:700;color:var(--text-primary);margin-bottom:8px" id="comms-picker-title"></div>' +
          ((d.linkable || []).length
            ? (d.linkable || []).map(function (doc) {
                return '<label style="display:flex;gap:7px;align-items:center;font-size:12px;color:var(--text-primary);padding:5px 2px;cursor:pointer">' +
                  '<input type="checkbox" class="comms-pick" value="' + doc.id + '">' +
                  '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + u.esc(doc.label) + '</span>' +
                  '<span style="color:var(--text-tertiary);font-size:10.5px;flex-shrink:0">' + u.esc(doc.receivedAt || '') + '</span></label>';
              }).join('')
            : '<div style="font-size:11.5px;color:var(--text-tertiary)">이 프로젝트에 연결할 만한 최근 문서가 없습니다. 업체가 보낸 파일을 먼저 문서함에 올려 주세요.</div>') +
          '<div style="margin-top:10px"><button type="button" id="comms-picker-go" style="' + btnStyle + '">연결</button></div>' +
        '</div>' +
        // ── 연결된 자료 ──
        ((d.documents || []).length
          ? '<div style="margin-bottom:12px;display:flex;flex-wrap:wrap;gap:5px">' + d.documents.map(function (doc) {
              return '<button type="button" onclick="window.AdminRegisters.openSource(' + doc.id + ')" ' +
                'style="border:1px solid var(--border-default);background:var(--bg-base);border-radius:999px;padding:4px 11px;' +
                'font-size:11px;color:var(--text-primary);cursor:pointer">' + (doc.kind === 'approval' ? '✅ ' : '📎 ') + u.esc(doc.label) + '</button>';
            }).join('') + '</div>'
          : '') +
        // ── 타임라인 ──
        '<div style="border:1px solid var(--border-default);border-radius:10px;padding:14px">' +
          '<div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:8px">소통 이력</div>' +
          ((d.events || []).length
            ? d.events.map(function (ev) {
                return '<div style="display:flex;gap:9px;font-size:11.5px;padding:5px 0;border-bottom:1px dashed var(--border-default)">' +
                  '<span style="color:var(--text-tertiary);flex-shrink:0">' + u.esc(ev.at || '') + '</span>' +
                  '<span style="font-weight:700;color:var(--text-primary);flex-shrink:0">' + u.esc(ev.label) + '</span>' +
                  '<span style="color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                    u.esc(ev.to || ev.document || ev.subject || '') +
                    (ev.channel === 'mailto' ? ' (메일앱에서 작성)' : '') + '</span></div>';
              }).join('')
            : '<div style="font-size:11.5px;color:var(--text-tertiary)">아직 소통 기록이 없습니다. 담당자를 넣고 ① 부터 시작하세요.</div>') +
        '</div>';

      // ── 동작 연결 ──
      var pickerKind = 'received';
      body.querySelector('#comms-save').onclick = function () {
        var data = {};
        body.querySelectorAll('input[data-c]').forEach(function (el) { data[el.getAttribute('data-c')] = el.value; });
        act('contacts', { data: data, applyToCsi: body.querySelector('#comms-apply-csi').checked }, this)
          .then(function (res) { if (res) { u.toast('담당자를 저장했습니다' + (res.applied > 1 ? ' — 같은 공종 ' + res.applied + '줄' : '') + '.'); refresh(); } });
      };
      body.querySelector('#comms-request').onclick = function () {
        act('request', {}, this).then(function (res) { if (res) refresh(); });
      };
      body.querySelector('#comms-transmit').onclick = function () {
        act('transmit', {}, this).then(function (res) { if (res) refresh(); });
      };
      function showPicker(kind, title) {
        pickerKind = kind;
        var p = body.querySelector('#comms-picker');
        p.style.display = 'block';
        body.querySelector('#comms-picker-title').textContent = title;
        p.scrollIntoView({ block: 'nearest' });
      }
      body.querySelector('#comms-link-received').onclick = function () {
        showPicker('received', '받은 자료로 연결할 문서를 고르세요 (문서함 최근 문서)');
      };
      body.querySelector('#comms-link-approval').onclick = function () {
        showPicker('approval', '승인본으로 연결할 문서를 고르세요 — 연결하면 상태가 「승인」이 됩니다');
      };
      body.querySelector('#comms-picker-go').onclick = function () {
        var ids = Array.from(body.querySelectorAll('.comms-pick:checked')).map(function (x) { return parseInt(x.value, 10); });
        if (!ids.length) { u.toast('문서를 먼저 고르세요.', 'error'); return; }
        act('link', { documentIds: ids, kind: pickerKind }, this)
          .then(function (res) { if (res) { u.toast('자료 ' + res.linked + '건을 연결했습니다.'); refresh(); } });
      };
    }

    function refresh() {
      call('api_submittalComms', ['overview', id, {}]).then(render).catch(function (e) {
        var body = wrap.querySelector('#reg-comms-body');
        if (body) body.innerHTML = '<div style="text-align:center;padding:30px;color:var(--danger,#dc2626)">' + u.esc(e.message) + '</div>';
      });
    }

    refresh();
  }

  function reloadSubmittals() {
    return call('api_getSubmittals', [state.subProjectId]).then(function (d) {
      state.sub = d;
      state.subProjectId = d.projectId;
    });
  }

  function renderSubmittals() {
    paint('<div style="padding:60px;text-align:center;color:var(--text-tertiary)">제출물 대장을 불러오는 중…</div>');
    reloadSubmittals().then(drawSubmittals).catch(function (e) {
      paint('<div style="padding:60px;text-align:center;color:var(--danger,#dc2626)">' + ui().esc(e.message) + '</div>');
    });
  }

  function setSubProject(v) { state.subProjectId = parseInt(v, 10) || null; renderSubmittals(); }

  function setSubFilter(key, value) { state.subF[key] = value; drawSubmittals(); }

  function quickStatus(id, status) {
    var u = ui();
    call('api_saveSubmittal', [{ id: id, status: status }]).then(function () {
      (state.sub.rows || []).forEach(function (r) { if (r.id === id) r.status = status; });
      // 상태만 바꿨을 때 표 전체를 다시 그리면 스크롤·검색이 초기화되므로 통계만 다시 그린다.
      var st = { total: 0, gate: 0, byStatus: {} };
      (state.sub.rows || []).forEach(function (r) {
        st.total++; if (r.gate) st.gate++;
        st.byStatus[r.status] = (st.byStatus[r.status] || 0) + 1;
      });
      state.sub.stats = st;
      u.toast('상태를 "' + status + '" 로 기록했습니다.');
    }).catch(function (e) { u.toast(e.message, 'error'); drawSubmittals(); });
  }

  function openSubmittal(id) {
    var u = ui();
    var row = (state.sub.rows || []).filter(function (r) { return r.id === id; })[0];
    if (!row) return;

    u.formModal({
      title: '제출물 기록 — #' + row.seq,
      subtitle: row.title.slice(0, 120),
      saveLabel: '기록',
      fields: [
        { name: 'status', label: '상태', type: 'select', required: true, group: '진행',
          options: state.sub.statuses || [], value: row.status },
        { name: 'assignee', label: '담당', group: '진행', value: row.assignee || '' },
        { name: 'plannedOn', label: '계획일', type: 'date', group: '일정', value: row.plannedOn || '' },
        { name: 'submittedOn', label: '제출일', type: 'date', group: '일정', value: row.submittedOn || '' },
        { name: 'approvedOn', label: '승인일', type: 'date', group: '일정', value: row.approvedOn || '' },
        { name: 'notes', label: '메모', type: 'textarea', colSpan: 2, group: '일정', value: row.notes || '' },
      ],
      onSave: function (v) {
        v.id = id;
        return call('api_saveSubmittal', [v]).then(function () {
          u.toast('기록했습니다.');
          return reloadSubmittals().then(function () { drawSubmittals(); return { success: true }; });
        }).catch(function (e) { return { success: false, error: e.message }; });
      },
    });
  }

  /* ══════════════════════ 근거 원문 보기 ══════════════════════
   * 대장의 한 줄과 그 줄이 나온 시방·도면을 같은 화면에 둔다.
   * ERP 밖으로 나가지 않는다 — 문서함을 새 창으로 열어 파일명을 다시 찾게 하면
   * 대장과 근거가 두 화면으로 갈라지고, 사람은 둘을 눈으로 맞춰야 한다.
   */

  function sourceRow(kind, id) {
    var list = kind === 'boq' ? (state.boq.rows || []) : (state.sub.rows || []);
    return list.filter(function (r) { return r.id === id; })[0] || null;
  }

  function openSource(documentId, kind, rowId) {
    var u = ui();
    var row = sourceRow(kind, rowId);
    var wrap = document.createElement('div');
    wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.55);display:flex;' +
      'align-items:center;justify-content:center;padding:20px';
    wrap.innerHTML = '<div style="background:var(--bg-surface);border:1px solid var(--border-default);border-radius:14px;' +
      'width:min(1180px,96vw);height:min(88vh,900px);display:flex;flex-direction:column;overflow:hidden">' +
      '<div id="reg-src-head" style="padding:16px 18px;border-bottom:1px solid var(--border-default);flex-shrink:0">' +
      '<div style="color:var(--text-tertiary);font-size:13px">원문을 여는 중…</div></div>' +
      '<div id="reg-src-body" style="flex:1;overflow:auto;background:var(--bg-base)"></div></div>';

    function close() { wrap.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    wrap.addEventListener('click', function (e) {
      if (e.target === wrap || (e.target.getAttribute && e.target.getAttribute('data-x') === 'close')) close();
    });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(wrap);

    call('api_getSourceDocument', [documentId]).then(function (d) {
      var head = wrap.querySelector('#reg-src-head');
      var body = wrap.querySelector('#reg-src-body');
      head.innerHTML =
        '<div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start">' +
          '<div style="min-width:0">' +
            '<div style="font-size:15px;font-weight:700;color:var(--text-primary);word-break:keep-all">' + u.esc(d.title) + '</div>' +
            '<div style="font-size:11.5px;color:var(--text-tertiary);margin-top:3px">' + u.esc(d.fileName || '') +
              (d.documentNumber ? ' · No. ' + u.esc(d.documentNumber) : '') +
              (d.revision ? ' · Rev ' + u.esc(d.revision) : '') + '</div>' +
          '</div>' +
          '<div style="display:flex;gap:6px;flex-shrink:0">' +
            '<a href="' + u.esc(d.downloadUrl) + '" style="padding:7px 13px;border-radius:8px;border:1px solid var(--border-default);' +
              'background:var(--bg-base);color:var(--text-primary);font-size:12.5px;text-decoration:none">원본 다운로드</a>' +
            '<button type="button" data-x="close" style="padding:7px 13px;border-radius:8px;border:1px solid var(--border-default);' +
              'background:var(--bg-base);color:var(--text-primary);font-size:12.5px;cursor:pointer">닫기</button>' +
          '</div>' +
        '</div>' +
        // 대장의 그 줄이 어느 문장에서 나왔는지 — 원문 옆에 나란히 둔다.
        (row && row.sourceExcerpt
          ? '<div style="margin-top:12px;padding:10px 12px;border-left:3px solid var(--brand-primary);background:var(--bg-base);' +
            'border-radius:0 8px 8px 0;font-size:12px;line-height:1.65;color:var(--text-secondary);white-space:pre-wrap;' +
            'max-height:132px;overflow:auto">' + u.esc(row.sourceExcerpt) + '</div>'
          : '');
      body.innerHTML = '<iframe src="' + u.esc(d.previewUrl) + '" title="' + u.esc(d.title) +
        '" style="width:100%;height:100%;border:0;background:#fff"></iframe>';
    }).catch(function (e) {
      wrap.querySelector('#reg-src-head').innerHTML =
        '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center">' +
        '<div style="color:var(--danger,#dc2626);font-size:13px">' + u.esc(e.message) + '</div>' +
        '<button type="button" data-x="close" style="padding:7px 13px;border-radius:8px;border:1px solid var(--border-default);' +
        'background:var(--bg-base);color:var(--text-primary);font-size:12.5px;cursor:pointer">닫기</button></div>';
    });
  }

  /* ══════════════════════ 물량 / BOQ ══════════════════════ */

  function basisBadge(basis) {
    var u = ui();
    var kind = basis === '문서확정' ? 'ok' : basis === '미확정' ? 'danger' : basis === '개산추정' ? 'warn' : '';
    return u.badge(basis, kind);
  }

  function boqTabs() {
    var u = ui();
    var t = state.boq.totals || { byDiscipline: [] };
    var btn = function (code, label, amount) {
      var on = state.boqTab === code;
      return '<button type="button" onclick="window.AdminRegisters.setBoqTab(\'' + code + '\')" ' +
        'style="padding:8px 14px;border:none;background:none;font-size:13px;cursor:pointer;white-space:nowrap;' +
        'border-bottom:2px solid ' + (on ? 'var(--brand-primary)' : 'transparent') + ';' +
        'color:' + (on ? 'var(--text-primary)' : 'var(--text-secondary)') + ';font-weight:' + (on ? '700' : '500') + '">' +
        u.esc(label) + ' <span style="color:var(--text-tertiary);font-weight:400;font-size:11px">' + money(amount) + '</span></button>';
    };
    return '<div style="display:flex;gap:2px;border-bottom:1px solid var(--border-default);margin-bottom:14px;overflow-x:auto">' +
      btn('', '전체', t.grand || 0) +
      (t.byDiscipline || []).map(function (g) { return btn(g.code, g.code + ' ' + g.name, g.amount); }).join('') +
      '</div>';
  }

  function boqRows() {
    return (state.boq.rows || []).filter(function (r) {
      if (state.boqReviewOnly && !r.needsReview) return false;
      return !state.boqTab || r.disciplineCode === state.boqTab;
    });
  }

  /** 도면 판독이 자신 없어 한 줄만 걸러 본다 — 사람이 볼 곳은 여기뿐이다. */
  function toggleReviewOnly() {
    state.boqReviewOnly = !state.boqReviewOnly;
    drawBoq();
  }

  function drawBoq() {
    var u = ui();
    var d = state.boq;
    var rows = boqRows();
    var t = d.totals || { grand: 0, unresolved: 0, flagged: 0 };
    var canManage = !!d.canManage;
    var shown = rows.reduce(function (a, r) { return a + (r.amount || 0); }, 0);

    var html =
      u.pageHeader('물량 / BOQ', '공정별 물량·단가 대장 (직접공사비 기준). 수량근거 "미확정" 은 도면 실측 후 채우는 LS 배정액이고, ⚑ 는 단가 편차가 커서 검토가 필요한 행입니다.') +
      '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">' +
        chip('직접비 합계', money(t.grand)) +
        chip('표시 범위 합계', money(shown)) +
        chip('실측 필요(LS)', t.unresolved || 0, 'danger') +
        chip('검토 플래그 ⚑', t.flagged || 0, 'danger') +
        // 도면에서 뽑은 줄은 바로 대장에 들어간다. 그중 확신이 낮았던 것만 여기 모인다.
        (t.needsReview ? '<button type="button" onclick="window.AdminRegisters.toggleReviewOnly()" ' +
          'style="border:1px solid ' + (state.boqReviewOnly ? 'var(--brand-primary)' : 'var(--border-default)') +
          ';background:' + (state.boqReviewOnly ? 'var(--brand-primary)' : 'transparent') +
          ';color:' + (state.boqReviewOnly ? '#fff' : 'var(--text-primary)') +
          ';border-radius:999px;padding:6px 14px;font-size:12.5px;font-weight:700;cursor:pointer">' +
          '🔍 확인 필요 ' + t.needsReview + '</button>' : '') +
      '</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px">' +
        projectSelect(d.projects, d.projectId, 'window.AdminRegisters.setBoqProject(this.value)') +
      '</div>' +
      boqTabs() +
      u.table({
        id: 'boq-tbl',
        searchPlaceholder: '품명·규격·근거 검색',
        emptyText: '항목이 없습니다.',
        columns: [
          { key: 'seq', label: '번호', width: '52px', align: 'center' },
          { key: 'nameKr', label: '품명', render: function (r) {
              return '<div style="font-size:12.5px;white-space:normal;min-width:220px">' +
                (r.flagged ? '<span style="color:var(--danger,#dc2626)">⚑ </span>' : '') + u.esc(r.nameKr) + '</div>' +
                (r.nameEn ? '<div style="font-size:11px;color:var(--text-tertiary);white-space:normal">' + u.esc(r.nameEn) + '</div>' : '') +
                // 왜 확인해야 하는지를 그 줄에 바로 적는다 — 다시 물어볼 일이 없게.
                (r.needsReview ? '<div style="font-size:11px;color:var(--danger,#dc2626);white-space:normal;margin-top:3px">🔍 ' +
                  u.esc(r.reviewReason || '확인 필요') + '</div>' : '') +
                (r.extractedBy ? '<div style="font-size:10.5px;color:var(--text-tertiary);margin-top:2px">' +
                  u.esc(r.extractedBy) + ' 판독' + (r.confidence != null ? ' · 확신도 ' + r.confidence : '') +
                  // 판독의 근거 도면을 그 자리에서 연다 — 수량이 미심쩍으면 원문으로 확인한다.
                  (r.sourceDocumentId ? ' · <button type="button" onclick="window.AdminRegisters.openSource(' +
                    r.sourceDocumentId + ', \'boq\', ' + r.id + ')" style="border:none;background:none;padding:0;cursor:pointer;' +
                    'color:var(--brand-primary);font-size:10.5px">🔗 원문 보기</button>' : '') + '</div>' : '');
            } },
          { key: 'spec', label: '규격 · 사양', render: function (r) {
              return r.spec ? '<div style="font-size:11.5px;color:var(--text-secondary);white-space:normal;min-width:180px;line-height:1.5">' + u.esc(r.spec) + '</div>' : '';
            } },
          { key: 'unit', label: '단위', width: '58px', align: 'center' },
          { key: 'qty', label: '수량', width: '110px', align: 'right', render: function (r) {
              return '<div style="font-variant-numeric:tabular-nums">' + Number(r.qty).toLocaleString('en-US', { maximumFractionDigits: 2 }) + '</div>' +
                '<div style="margin-top:3px">' + basisBadge(r.qtyBasis) + '</div>';
            } },
          { key: 'unitPrice', label: '단가', width: '110px', align: 'right', render: function (r) {
              return '<span style="font-variant-numeric:tabular-nums">$' + Number(r.unitPrice).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span>';
            } },
          { key: 'amount', label: '금액', width: '110px', align: 'right', render: function (r) {
              return '<strong style="font-variant-numeric:tabular-nums">' + money(r.amount) + '</strong>';
            } },
          { key: 'source', label: '산출근거', width: '150px', render: function (r) {
              return (r.wbsActivityId ? '<div style="margin-bottom:3px">' + u.badge('WBS ' + r.wbsActivityId, 'ok') + '</div>' : '') +
                (r.source ? '<div style="font-size:11px;color:var(--text-tertiary);white-space:normal">' + u.esc(r.source) + '</div>' : '');
            } },
          { key: '_act', label: '', width: '70px', align: 'right', render: function (r) {
              return canManage ? u.rowButton('수정', 'window.AdminRegisters.openBoqItem(' + r.id + ')') : '';
            } },
        ],
        rows: rows,
      });

    paint(html);
    u.bindSearch('boq-tbl');
  }

  function reloadBoq() {
    return call('api_getBoq', [state.boqProjectId]).then(function (d) {
      state.boq = d;
      state.boqProjectId = d.projectId;
    });
  }

  function renderBoq() {
    paint('<div style="padding:60px;text-align:center;color:var(--text-tertiary)">물량 대장을 불러오는 중…</div>');
    reloadBoq().then(drawBoq).catch(function (e) {
      paint('<div style="padding:60px;text-align:center;color:var(--danger,#dc2626)">' + ui().esc(e.message) + '</div>');
    });
  }

  function setBoqProject(v) { state.boqProjectId = parseInt(v, 10) || null; renderBoq(); }

  function setBoqTab(code) { state.boqTab = code; drawBoq(); }

  function openBoqItem(id) {
    var u = ui();
    var row = (state.boq.rows || []).filter(function (r) { return r.id === id; })[0];
    if (!row) return;

    u.formModal({
      title: '물량 수정 — #' + row.seq + ' ' + row.nameKr.slice(0, 40),
      subtitle: row.source ? '산출근거: ' + row.source : '',
      saveLabel: '저장',
      fields: [
        { name: 'qty', label: '수량 (' + row.unit + ')', type: 'number', required: true, group: '수량 · 단가', value: row.qty },
        { name: 'qtyBasis', label: '수량근거', type: 'select', required: true, group: '수량 · 단가',
          options: ['문서확정', '도면판독', '개산추정', '미확정'], value: row.qtyBasis,
          hint: '실측으로 채웠으면 "도면판독" 이상으로 올리세요.' },
        { name: 'unitPrice', label: '단가 (USD)', type: 'number', required: true, group: '수량 · 단가', value: row.unitPrice },
        { name: 'wbsActivityId', label: 'WBS 액티비티', group: '수량 · 단가', value: row.wbsActivityId || '',
          hint: '공정관리의 액티비티 ID (예: S020) — 이 라인의 돈이 어느 작업 몫인지. 기성 SOV 분해의 근거가 됩니다.' },
        { name: 'note', label: '메모', type: 'textarea', colSpan: 2, group: '수량 · 단가', value: row.note || '' },
      ],
      onSave: function (v) {
        v.id = id;
        return call('api_saveBoqItem', [v]).then(function () {
          u.toast('저장했습니다. 금액은 자동 재계산됩니다.');
          return reloadBoq().then(function () { drawBoq(); return { success: true }; });
        }).catch(function (e) { return { success: false, error: e.message }; });
      },
    });
  }

  global.AdminRegisters = {
    renderSubmittals: renderSubmittals,
    renderBoq: renderBoq,
    setSubProject: setSubProject,
    setSubFilter: setSubFilter,
    quickStatus: quickStatus,
    openSubmittal: openSubmittal,
    requestVendorData: requestVendorData,
    researchSubmittal: researchSubmittal,
    openComms: openComms,
    openSource: openSource,
    setBoqProject: setBoqProject,
    toggleReviewOnly: toggleReviewOnly,
    setBoqTab: setBoqTab,
    openBoqItem: openBoqItem,
    _state: state,
  };
})(window);
