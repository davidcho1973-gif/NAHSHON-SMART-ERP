/**
 * 직원 등록 · 수정 — Filament EmployeeResource 를 SPA 로 옮긴 것.
 *
 * 폼 순서를 다시 짰다. 예전 폼은 필드 23개가 구역 없이 한 줄로 늘어서 있었고
 * 순서가 사번 → NFC → 배지사진 → 배지번호 → 이름 이었다. 사람을 등록하는데
 * 배지부터 물어보니 불편할 수밖에 없었다.
 *
 * 지금은 실제로 묻는 순서다: 누구인가 → 어디 소속인가 → 출입증 → QR → 자격 만료.
 * 사번은 비워도 자동으로 발급되고, 이름도 성·이름만 넣으면 합쳐진다.
 */
(function (global) {
  'use strict';

  var A = null;
  var state = { rows: [], options: null, canManage: false, filters: { status: 'active', siteId: '', employmentType: '' } };

  function ui() { if (!A) A = global.AdminUI; return A; }

  function call(method, args) {
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  function filterBar() {
    var u = ui();
    var o = state.options || {};
    var f = state.filters;
    var sel = function (id, label, opts, val, blank) {
      return '<div><label for="' + id + '" style="display:block;font-size:11px;color:var(--text-tertiary);margin-bottom:4px">' +
        u.esc(label) + '</label><select id="' + id + '" onchange="window.AdminEmployees.applyFilters()" ' +
        'style="padding:7px 10px;border-radius:8px;border:1px solid var(--border-default);background:var(--bg-base);' +
        'color:var(--text-primary);font-size:13px;min-width:140px">' +
        '<option value="">' + u.esc(blank) + '</option>' +
        (opts || []).map(function (x) {
          return '<option value="' + u.esc(x.value) + '"' + (String(x.value) === String(val) ? ' selected' : '') + '>' + u.esc(x.label) + '</option>';
        }).join('') + '</select></div>';
    };
    return '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:var(--bg-surface);' +
      'border:1px solid var(--border-default);border-radius:12px">' +
      sel('em-status', '재직 상태', o.statuses, f.status, '전체') +
      sel('em-type', '고용 형태', o.employmentTypes, f.employmentType, '전체') +
      sel('em-site', '현장', o.sites, f.siteId, '전체') +
      '</div>';
  }

  function expiryCell(r) {
    var u = ui();
    if (!r.expiring || !r.expiring.length) return '';
    return r.expiring.map(function (x) {
      var kind = x.state === 'expired' ? 'danger' : 'warn';
      return u.badge(x.label + (x.state === 'expired' ? ' 만료' : ' ' + x.date), kind);
    }).join(' ');
  }

  function render() {
    var u = ui();
    var rows = state.rows;
    var expired = rows.filter(function (r) {
      return (r.expiring || []).some(function (x) { return x.state === 'expired'; });
    }).length;
    var noBadge = rows.filter(function (r) { return !r.badgeNumber; }).length;
    var noW9 = rows.filter(function (r) { return !r.w9OnFile; }).length;

    var notes = [rows.length + '명'];
    if (expired) notes.push(expired + '명 자격 만료');
    if (noBadge) notes.push(noBadge + '명 NFC 미등록');
    if (noW9) notes.push(noW9 + '명 W-9 미제출');

    return u.pageHeader(
      '직원 등록 · 관리',
      '현장에 들어가는 사람을 등록합니다. 비자·안전교육이 끊긴 사람은 목록에 표시됩니다. — ' + notes.join(' · '),
      state.canManage ? u.primaryButton('직원 등록', 'window.AdminEmployees.openForm()', 'user-plus') : ''
    ) + filterBar() + u.table({
      id: 'em-tbl',
      searchPlaceholder: '이름 · 사번 · NFC · 직종 검색',
      emptyText: '조건에 맞는 직원이 없습니다.',
      columns: [
        {
          key: 'name', label: '이름', width: '180px',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.name) + '</div>' +
              '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.employeeNumber || '') +
              (r.languageLabel ? ' · ' + u.esc(r.languageLabel) : '') + '</div>';
          },
        },
        { key: 'company', label: '회사', width: '130px' },
        { key: 'site', label: '현장', width: '100px' },
        { key: 'role', label: '직종', width: '110px' },
        {
          key: 'employmentTypeLabel', label: '고용 형태', width: '130px',
          render: function (r) {
            var kind = r.employmentType === 'direct' ? 'ok' : r.employmentType === 'indirect' ? 'warn' : 'muted';
            return u.badge(r.employmentTypeLabel, kind);
          },
        },
        {
          key: 'badgeNumber', label: 'NFC', width: '110px',
          render: function (r) {
            // NFC 가 없으면 게이트에서 태그를 못 찍는다 — 등록이 덜 끝난 상태다.
            return r.badgeNumber
              ? '<span style="font-family:var(--font-mono,monospace);font-size:12px">' + u.esc(r.badgeNumber) + '</span>'
              : '<span style="font-size:11px;color:var(--status-warning)">미등록</span>';
          },
        },
        {
          key: 'w9OnFile', label: 'W-9', width: '90px',
          render: function (r) {
            // W-9 가 없으면 1099 지급 전 24% backup withholding 대상이 된다.
            return r.w9OnFile
              ? u.badge('···' + (r.w9TinLast4 || ''), 'ok')
              : '<span style="font-size:11px;color:var(--status-warning)">미제출</span>';
          },
        },
        {
          key: 'statusLabel', label: '상태', width: '150px',
          render: function (r) {
            var kind = r.status === 'active' ? 'ok' : r.status === 'terminated' ? 'danger' : 'warn';
            var out = u.badge(r.statusLabel, kind);
            var exp = expiryCell(r);
            return exp ? out + ' ' + exp : out;
          },
        },
        {
          key: 'act', label: '', align: 'right', width: '200px',
          render: function (r) {
            if (!state.canManage) return '';
            var html = '';
            // 계정이 없으면 이 사람은 앱에 못 들어온다. 직원 정보를 그대로 써서
            // 여기서 바로 만들어 준다 — 계정 화면에서 이름·이메일을 또 치지 않는다.
            if (!r.hasAccount && state.options && state.options.canGrantAccount) {
              html += u.rowButton('계정 만들기', 'window.AdminEmployees.grantAccount(' + r.id + ')') + ' ';
            }
            // 계정이 생긴 다음에야 앱에 들어올 수 있다. 그때부터 설치 카드를 뽑을 수 있게 한다 —
            // 카드의 핵심은 QR 이 아니라 "어느 구글 계정으로 로그인하는가" 이다.
            if (r.hasAccount) {
              html += u.rowButton('앱 설치 카드', "window.open('/attendance-app/employee/" + r.id + "/install-card','_blank')") + ' ';
            }
            // 버튼을 권한으로 감추지 않는다. 감추면 "왜 안 보이지" 를 아무도 답할 수 없다 —
            // 권한이 없으면 열린 화면이 이유를 말해 준다(조용히 사라지는 것보다 낫다).
            html += u.rowButton('작업자 화면 보기', "window.open('/attendance-app?as=" + r.id + "','_blank')") + ' ';
            html += u.rowButton('수정', 'window.AdminEmployees.openForm(' + r.id + ')') + ' ' +
              u.rowButton('삭제', 'window.AdminEmployees.remove(' + r.id + ')', 'danger');
            return html;
          },
        },
      ],
      rows: rows,
    });
  }

  function paint(html) {
    var host = document.getElementById('page-container');
    if (host) host.innerHTML = html;
  }

  function reload() {
    return call('api_getEmployeeAdminList', [state.filters]).then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '직원 목록을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.rows = res.rows || [];
      state.canManage = !!res.canManage;
      paint(render());
      ui().bindSearch('em-tbl');
    });
  }

  function loadOptions() {
    if (state.options) return Promise.resolve(state.options);
    return call('api_getEmployeeAdminOptions').then(function (res) {
      if (res.success === false) throw new Error(res.error || '선택지를 불러오지 못했습니다.');
      state.options = res;
      return res;
    });
  }

  function applyFilters() {
    var g = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
    state.filters = { status: g('em-status'), employmentType: g('em-type'), siteId: g('em-site') };
    reload();
  }

  function openForm(id) {
    var u = ui();
    var r = id ? state.rows.filter(function (x) { return x.id === id; })[0] : null;

    loadOptions().then(function (o) {
      u.formModal({
        title: r ? '직원 수정 — ' + r.name : '직원 등록',
        subtitle: r
          ? '사번 ' + (r.employeeNumber || '') + (r.hasBadgePhoto ? ' · 배지 사진 있음' : '')
          : '이름과 소속 회사만 있으면 등록됩니다. 사번은 자동으로 발급됩니다.',
        saveLabel: r ? '수정' : '등록',
        fields: [
          // 1. 누구인가 — 사람을 등록하는데 배지부터 묻지 않는다.
          { name: 'name', label: '이름', required: true, group: '① 누구인가', value: r ? r.name : '', colSpan: 2,
            hint: '여권·신분증 표기와 맞추면 나중에 서류 대조가 편합니다.' },
          { name: 'firstName', label: '영문 이름 (First)', group: '① 누구인가', value: r ? r.firstName : '' },
          { name: 'lastName', label: '영문 성 (Last)', group: '① 누구인가', value: r ? r.lastName : '' },
          { name: 'email', label: '이메일', type: 'email', group: '① 누구인가', value: r ? r.email : '' },
          { name: 'nationality', label: '국적', group: '① 누구인가', value: r ? r.nationality : '' },
          { name: 'language', label: '사용 언어', type: 'select', group: '① 누구인가',
            options: o.languages, value: r ? r.language : 'ko', colSpan: 2,
            hint: '출퇴근 화면과 안내문이 이 언어로 나옵니다.' },

          // 2. 어디 소속인가
          { name: 'companyId', label: '소속 회사', type: 'select', required: true, group: '② 어디 소속인가',
            options: o.companies, value: r ? r.companyId : '' },
          { name: 'employmentType', label: '고용 형태', type: 'select', required: true, group: '② 어디 소속인가',
            options: o.employmentTypes, value: r ? r.employmentType : 'direct',
            hint: '직접고용은 시급 정산, 간접고용은 출역 인원으로 집계됩니다.' },
          { name: 'siteId', label: '현장', type: 'select', group: '② 어디 소속인가',
            options: o.sites, value: r ? r.siteId : '' },
          { name: 'teamId', label: '팀', type: 'select', group: '② 어디 소속인가',
            options: o.teams, value: r ? r.teamId : '' },
          { name: 'role', label: '직종', group: '② 어디 소속인가', value: r ? r.role : '',
            hint: '전기 · 배관 · 용접 처럼' },
          { name: 'startDate', label: '입사일', type: 'date', group: '② 어디 소속인가', value: r ? r.startDate : '' },
          { name: 'status', label: '재직 상태', type: 'select', required: true, group: '② 어디 소속인가',
            options: o.statuses, value: r ? r.status : 'active', colSpan: 2 },

          // 3. 출입증
          { name: 'employeeNumber', label: '사번', group: '③ 출입증 · 배지', value: r ? r.employeeNumber : '',
            hint: '비우면 자동으로 발급됩니다.' },
          { name: 'badgeNumber', label: 'NFC ID', group: '③ 출입증 · 배지', value: r ? r.badgeNumber : '',
            hint: '게이트에서 태그를 찍는 번호입니다. 겹치면 남의 출퇴근이 찍힙니다.' },
          { name: 'badgePrintedNumber', label: '배지 인쇄번호', group: '③ 출입증 · 배지', value: r ? r.badgePrintedNumber : '' },
          { name: 'badgeCompanyName', label: '배지 회사명', group: '③ 출입증 · 배지', value: r ? r.badgeCompanyName : '' },
          { name: 'badgeIssuedOn', label: '배지 발급일', type: 'date', group: '③ 출입증 · 배지',
            value: r ? r.badgeIssuedOn : '', colSpan: 2,
            hint: '입사일을 비워두면 이 날짜를 입사일로 씁니다.' },

          // 4. QR 출퇴근
          { name: 'qrRole', label: 'QR 역할', type: 'select', group: '④ QR 출퇴근',
            options: o.qrRoles, value: r ? r.qrRole : 'worker' },
          { name: 'qrScope', label: 'QR 범위', type: 'select', group: '④ QR 출퇴근',
            options: o.qrScopes, value: r ? r.qrScope : 'self',
            hint: '작업자 역할은 본인만 찍을 수 있습니다.' },

          // 5. 자격 만료 — 끊기면 현장에 못 들어간다.
          { name: 'visaExpiresOn', label: '비자 만료일', type: 'date', group: '⑤ 자격 만료',
            value: r ? r.visaExpiresOn : '' },
          { name: 'safetyExpiresOn', label: '안전교육 만료일', type: 'date', group: '⑤ 자격 만료',
            value: r ? r.safetyExpiresOn : '',
            hint: '만료 30일 전부터 목록에 표시됩니다.' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          return call('api_saveEmployeeAdmin', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(r ? '직원 정보를 수정했습니다.'
              : '등록했습니다. 사번 ' + (res.employeeNumber || '') + ' 이(가) 발급되었습니다.');
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function grantAccount(id) {
    var u = ui();
    var r = state.rows.filter(function (x) { return x.id === id; })[0];
    if (!r) return;

    loadOptions().then(function (o) {
      u.formModal({
        title: '로그인 계정 만들기',
        subtitle: r.name + ' 님이 앱에 들어올 수 있게 합니다. 이름과 소속은 직원 정보를 그대로 씁니다.',
        saveLabel: '만들기',
        fields: [
          { name: 'email', label: '이메일', required: true, colSpan: 2, value: r.email || '',
            hint: '구글 로그인에 쓰는 주소입니다. 직원 정보의 이메일이 기본값입니다.' },
          { name: 'role', label: '역할', type: 'select', required: true,
            options: o.accountRoles, value: 'worker' },
          { name: 'scope', label: '볼 수 있는 범위', type: 'select', required: true,
            options: o.accountScopes, value: 'self',
            hint: '"본인" 이면 자기 출퇴근만 봅니다. 현장·팀은 직원 정보의 소속을 따라갑니다.' },
        ],
        onSave: function (v) {
          return call('api_grantEmployeeAccount', [id, v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(r.name + ' 님의 계정을 만들었습니다.');
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  function remove(id) {
    var u = ui();
    var r = state.rows.filter(function (x) { return x.id === id; })[0];
    u.confirmDanger({
      title: '직원을 삭제할까요?',
      body: (r ? r.name + '(' + (r.employeeNumber || '') + ')' : '이 직원') + ' 을(를) 삭제합니다. 되돌릴 수 없습니다. ' +
        '퇴사한 사람은 삭제 대신 상태를 "퇴사" 로 두세요 — 과거 근무 기록이 남습니다.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteEmployeeAdmin', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('직원을 삭제했습니다.');
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    loadOptions().then(reload).catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
        ui().esc(e.message || '직원 목록을 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminEmployees = {
    render: renderScreen,
    applyFilters: applyFilters,
    openForm: openForm,
    grantAccount: grantAccount,
    remove: remove,
    _state: state,
  };
})(window);
