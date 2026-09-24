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
  // pushReady 는 «아직 모른다» 를 true 로 둔다 — 서버 답을 받기 전에 경고를 띄우면
  // 열쇠가 멀쩡한 배포에서도 화면이 깜빡이며 겁을 준다.
  var state = { rows: [], options: null, canManage: false, pushReady: true, filters: { status: 'active', siteId: '', employmentType: '' } };

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

    // 알림을 켤 수 있는 사람(계정이 있는 사람) 중 아직 안 켠 사람 — 쫓아갈 대상이다.
    var noPush = rows.filter(function (r) { return r.hasAccount && !r.pushDevices; }).length;

    var notes = [rows.length + '명'];
    if (expired) notes.push(expired + '명 자격 만료');
    notes.push(rows.filter(function(r){ return r.hrReviewPending; }).length + '명 인사 확인 필요');
    if (noW9) notes.push(noW9 + '명 W-9 미제출');
    notes.push(rows.filter(function(r){ return r.todayAttendance && r.todayAttendance.open; }).length + '명 근무 중');

    // 열쇠가 없으면 모두가 «꺼짐» 으로 보인다. 그건 사람들이 안 켠 게 아니라 서버가
    // 못 보내는 것이다 — 이 한 줄이 없으면 소장이 애먼 사람들을 쫓아다니게 된다.
    var pushWarning = state.pushReady === false
      ? u.notice('이 서버에는 알림 열쇠(VAPID)가 설정돼 있지 않아, 아래 «알림» 칸은 모두 꺼짐으로 보입니다. ' +
          '출근·퇴근·보고 알림이 지금 한 통도 나가지 않습니다. 관리자가 환경변수를 넣어야 켜집니다.', 'warn')
      : '';

    return u.pageHeader(
      '직원 등록 · 관리',
      '현장 QR 하나로 등록·출퇴근합니다. 신규 직원을 확인하고 서류 링크를 전달하세요. — ' + notes.join(' · '),
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
        { key: 'team', label: '팀', width: '110px' },
        { key: 'todayAttendance', label: '오늘 출퇴근', width: '150px', render: function(r) {
          var a = r.todayAttendance;
          return a ? u.badge(a.open ? '근무 중' : '퇴근', a.open ? 'ok' : 'muted') + '<div>' + u.esc(a.in || '') + ' → ' + u.esc(a.out || '—') + '</div>' : u.badge('기록 없음', 'muted');
        } },        {
          key: 'w9OnFile', label: 'W-9', width: '90px',
          render: function (r) {
            // W-9 가 없으면 1099 지급 전 24% backup withholding 대상이 된다.
            return r.w9OnFile
              ? u.badge('제출 완료', 'ok')
              : '<span style="font-size:11px;color:var(--status-warning)">미제출</span>';
          },
        },
        {
          key: 'statusLabel', label: '상태', width: '150px',
          render: function (r) {
            var kind = r.status === 'active' ? 'ok' : r.status === 'terminated' ? 'danger' : 'warn';
            var out = u.badge(r.statusLabel, kind);
            if (r.hrReviewPending) out += ' ' + u.badge('QR 신규 · 확인 필요', 'warn');
            var exp = expiryCell(r);
            return exp ? out + ' ' + exp : out;
          },
        },
        {
          key: 'act', label: '', align: 'right', width: '200px',
          render: function (r) {
            if (!state.canManage) return '';
            var html = '';
            // 현장 QR 로 들어온 사람은 «확인» 이 첫 단추다 — 회사·팀·(자사면) 시급 셋만 정하면
            // 딱지가 사라지고 급여 계산에 들어간다. 20칸짜리 폼을 열 이유가 없다.
            if (r.hrReviewPending) {
              html += u.rowButton('확인', 'window.AdminEmployees.confirmRegistration(' + r.id + ')') + ' ';
            }
            // 계정이 없으면 이 사람은 앱에 못 들어온다. 직원 정보를 그대로 써서
            // 여기서 바로 만들어 준다 — 계정 화면에서 이름·이메일을 또 치지 않는다.
            if (!r.hasAccount && !r.canQuickConnect && state.options && state.options.canGrantAccount) {
              html += u.rowButton('계정 만들기', 'window.AdminEmployees.grantAccount(' + r.id + ')') + ' ';
            }
            // 계정이 생긴 다음에야 앱에 들어올 수 있다. 그때부터 설치 카드를 뽑을 수 있게 한다 —
            // 카드의 핵심은 QR 이 아니라 "어느 구글 계정으로 로그인하는가" 이다.
            if (r.hasAccount || r.canQuickConnect) {
              // 보내기(문자·QR)와 인쇄 카드는 쓰임이 다르다. 대개 보내기를 먼저 쓴다.
              if (r.siteQrUrl) html += u.rowButton('현장 공용 QR', "window.open('" + u.esc(r.siteQrUrl) + "','_blank')") + ' ';
              // 구글 계정이 없는 현장 인력은 이 링크로 자기 번호를 정하고 폰을 기억시킨다.
              html += u.rowButton(r.hasPin ? 'PIN 재설정' : '출퇴근 연결',
                "window.AdminEmployees.pinLink(" + r.id + ",'" + (r.hasPin ? 'reset' : 'invite') + "')") + ' ';
            }
            if (r.onboardingRequestUrl) {
              html += u.rowButton('추가정보·W-9 링크', 'window.AdminEmployees.copyOnboardingLink(' + r.id + ')') + ' ';
            }
            // 버튼을 권한으로 감추지 않는다. 감추면 "왜 안 보이지" 를 아무도 답할 수 없다 —
            // 권한이 없으면 열린 화면이 이유를 말해 준다(조용히 사라지는 것보다 낫다).
            html += u.rowButton('작업자 화면 보기', "window.open('/attendance-app?as=" + r.id + "','_blank')") + ' ';
            // W-9 는 1099 지급의 전제조건이라 급여 담당이 수시로 찾는다. 제출 전이면
            // 아는 칸이 채워진 종이가, 제출 후면 보관용 사본이 나온다.
            html += u.rowButton('W-9 출력', "window.open('/w9/" + r.id + "/print','_blank')") + ' ';
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
      state.pushReady = res.pushReady !== false;
      paint(render());
      ui().bindSearch('em-tbl');
    });
  }

  function copyOnboardingLink(id) {
    var row = state.rows.filter(function (x) { return x.id === id; })[0];
    if (!row || !row.onboardingRequestUrl) {
      alert('이 직원의 추가정보 링크를 만들 수 없습니다. 등록 기록을 확인해 주세요.');
      return;
    }
    var message = row.name + ' 님, 아래 개인 보안 링크에서 추가 인사정보를 입력한 뒤 W-9을 작성해 주세요.\n' + row.onboardingRequestUrl;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(message).then(function () {
        alert('전송할 안내문과 링크를 복사했습니다. 문자에 붙여 넣어 보내세요. 링크는 14일 동안 유효합니다.');
      }).catch(function () { global.prompt('아래 내용을 복사해 보내세요.', message); });
      return;
    }
    global.prompt('아래 내용을 복사해 보내세요.', message);
  }

  function loadOptions() {
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

  /** 이 사람이 실제로 로그인하는 주소. 직원 이메일과 다르면 그 사실을 알린다. */
  function loginHint(r) {
    if (!r || !r.hasAccount) return '로그인 계정을 만들 때 기본값으로 쓰입니다. Gmail이 아니어도 됩니다.';
    if (!r.loginEmail) return '';
    var same = String(r.loginEmail).toLowerCase() === String(r.email || '').toLowerCase();
    if (same) return '로그인 계정도 이 주소입니다.';
    return '⚠ 로그인 계정은 ' + r.loginEmail + ' 입니다 — 이 주소와 다릅니다. '
      + '저장할 때 계정도 옮길지 물어봅니다.';
  }

  function openForm(id) {
    var u = ui();
    var r = id ? state.rows.filter(function (x) { return x.id === id; })[0] : null;

    loadOptions().then(function (o) {
      u.formModal({
        title: r ? '직원 수정 — ' + r.name : '직원 등록',
        onReady: function (form) {
          var company = form.querySelector('[name="companyId"]');
          var site = form.querySelector('[name="siteId"]');
          var team = form.querySelector('[name="teamId"]');
          function refreshTeams() {
            var selected = team.value;
            team.innerHTML = '<option value="">— 선택 안 함 —</option>' + o.teams.filter(function (t) {
              var existing = r && String(t.value) === String(r.teamId) && String(r.companyId || '') === company.value && String(r.siteId || '') === site.value;
              return existing || (String(t.companyId) === company.value && String(t.siteId) === site.value && t.status === 'active');
            }).map(function (t) {
              return '<option value="' + u.esc(t.value) + '"' + (String(t.value) === selected ? ' selected' : '') + '>' + u.esc(t.label) + '</option>';
            }).join('');
          }
          company.addEventListener('change', refreshTeams);
          site.addEventListener('change', refreshTeams);
          refreshTeams();
        },
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
          { name: 'email', label: '이메일', type: 'email', group: '① 누구인가', value: r ? r.email : '',
            // 로그인 계정은 이 칸과 다른 값이다. 다르면 여기서 말해 주지 않는 한
            // 아무도 모른다 — 보내기 화면은 계정 쪽을, 이 폼은 직원 쪽을 보여 준다.
            hint: loginHint(r) },
          // 앱 링크를 문자·왓츠앱으로 바로 보낼 때 쓴다. 간편등록은 이미 받고 있다.
          { name: 'phone', label: '전화번호', type: 'tel', group: '① 누구인가', value: r ? r.phone : '', placeholder: '480-555-0100' },
          { name: 'nationality', label: '국적', group: '① 누구인가', value: r ? r.nationality : '' },
          { name: 'language', label: '사용 언어', type: 'select', group: '① 누구인가',
            options: o.languages, value: r ? r.language : 'ko', colSpan: 2,
            hint: '출퇴근 화면과 안내문이 이 언어로 나옵니다.' },

          // 2. 어디 소속인가
          { name: 'companyId', label: '소속 회사', type: 'select', required: true, group: '② 어디 소속인가',
            options: o.companies, value: r ? r.companyId : (o.defaultCompanyId || '') },
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
          // 로그인 주소를 바꾸는 것은 "이 사람이 누구인가" 를 바꾸는 일이다. 잘못
          // 바꾸면 그 사람은 앱에 못 들어온다. 그래서 조용히 옮기지 않고 물어본다.
          // 다만 묻지 않으면 두 값이 계속 어긋난 채로 남으므로, 물어보기는 한다.
          var typed = String(v.email || '').trim().toLowerCase();
          if (r && r.hasAccount && r.loginEmail && typed && typed !== String(r.loginEmail).toLowerCase()) {
            v.syncAccountEmail = window.confirm(
              '로그인 계정 이메일도 ' + typed + ' 로 바꿀까요?\n\n'
              + '지금 로그인 계정: ' + r.loginEmail + '\n\n'
              + '바꾸면 이 사람은 새 주소로만 로그인할 수 있습니다.\n'
              + '취소하면 직원 정보만 바뀌고 로그인은 그대로입니다.'
            );
          }
          return call('api_saveEmployeeAdmin', [v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(res.accountEmailChanged
              ? '직원 정보와 로그인 계정을 모두 수정했습니다.'
              : (r ? '직원 정보를 수정했습니다.'
                   : '등록했습니다. 사번 ' + (res.employeeNumber || '') + ' 이(가) 발급되었습니다.'));
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  /**
   * 현장 QR 로 들어온 사람 확인 — 사람이 정할 것은 셋뿐이다.
   *
   * 예전에는 한 명마다 여덟 가지를 세 화면에서 했다(회사·고용형태·공정·팀·계정·
   * 추가정보 링크·임금률·20칸 폼 저장). 그중 사람만 아는 것은 소속 회사, 팀,
   * 그리고 자사일 때의 시급뿐이다. 고용형태는 회사에서 따라 나오고 계정은 등록이
   * 이미 만든다. 그래서 묻는 것도 셋으로 줄인다.
   */
  function confirmRegistration(id) {
    var u = ui();
    var r = state.rows.filter(function (x) { return x.id === id; })[0];
    if (!r) return;

    loadOptions().then(function (o) {
      var teamsFor = function (companyId) {
        return o.teams.filter(function (t) {
          return String(t.companyId) === String(companyId) && String(t.siteId) === String(r.siteId || '') && t.status === 'active';
        }).map(function (t) { return { value: String(t.id !== undefined ? t.id : t.value), label: t.label }; });
      };

      u.formModal({
        title: '확인 — ' + r.name,
        saveLabel: '확인',
        subtitle: '현장 QR 로 스스로 등록한 사람입니다. 소속 회사를 고르면 고용 형태가 따라 정해집니다. '
          + '확인 전까지 이 사람은 인원으로만 집계되고 급여 계산에는 들어가지 않습니다.',
        onReady: function (form) {
          var company = form.querySelector('[name="companyId"]');
          var team = form.querySelector('[name="teamId"]');
          var rateBox = form.querySelector('[name="baseRate"]');
          function refresh() {
            var picked = (o.companies || []).filter(function (c) { return String(c.value) === String(company.value); })[0];
            var list = teamsFor(company.value);
            team.innerHTML = '<option value="">— 팀 없음 —</option>' + list.map(function (t) {
              return '<option value="' + t.value + '">' + t.label + '</option>';
            }).join('');
            // 시급은 자사 직영일 때만 뜻이 있다 — 협력사 인원의 임금은 그 회사가 준다.
            var direct = picked && picked.employmentType === 'direct';
            if (rateBox) {
              rateBox.closest('div').style.display = direct ? '' : 'none';
            }
          }
          company.addEventListener('change', refresh);
          refresh();
        },
        fields: [
          { name: 'companyId', label: '소속 회사', type: 'select', required: true,
            options: o.companies, value: r.companyId || '',
            hint: '이 한 가지가 급여 방식을 정합니다. 자사면 시급 정산, 협력사면 출역 인원 집계입니다.' },
          { name: 'teamId', label: '팀', type: 'select', options: [], value: r.teamId || '',
            hint: '이 현장·이 회사의 활성 팀만 나옵니다.' },
          { name: 'role', label: '공정', value: r.role || '',
            hint: '이미 쓰는 이름으로 적어 주세요. 표기가 갈리면 공종별 인원이 나뉩니다.' },
          { name: 'baseRate', label: '시급 ($)', type: 'number',
            hint: '자사 직영만 해당합니다. 비워 두면 임금 프로필에서 나중에 넣을 수 있습니다.' },
        ],
        onSave: function (v) {
          return call('api_confirmSelfRegistration', [id, v]).then(function (res) {
            if (res.success === false) return res;
            u.toast(res.notice || '확인했습니다. 이제 급여 계산에 들어갑니다.');
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) { u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error'); });
  }

  // PIN 초대·재설정 — 관리자에게 나가는 것은 링크뿐이다. 번호는 본인 폰에서만 정해지고
  // 관리자는 영원히 모른다(그래야 출퇴근 기록이 급여의 근거로 남는다).
  function pinLink(id, purpose) {
    var u = ui();
    var r = state.rows.filter(function (x) { return x.id === id; })[0];
    if (!r) return;

    call('api_issuePinLink', [id, purpose || 'invite']).then(function (res) {
      if (res.success === false) { u.toast(res.error || '발급하지 못했습니다.', 'error'); return; }

      var isReset = res.purpose === 'reset';
      var body =
        '<p style="margin:0 0 12px;font-size:13.5px;line-height:1.6;color:var(--text-secondary)">' +
        '<b>' + (res.name || '') + '</b> 님께 아래 링크를 보내세요. 본인이 열어 <b>4자리 번호</b>를 직접 정합니다.<br>' +
        '유효 시간 <b>' + (res.expiresIn || '') + '</b> · 한 번 쓰면 사라집니다. 관리자는 그 번호를 알 수 없습니다.</p>' +
        '<input id="pin-link-box" readonly value="' + res.url + '" ' +
        'style="width:100%;padding:10px;font-size:12.5px;border:1px solid var(--border);border-radius:8px;background:var(--bg-secondary)">' +
        '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">' +
        '<button type="button" id="pin-copy" class="btn-primary" style="padding:8px 14px;font-size:13px">링크 복사</button>' +
        // 회사 이름은 배포마다 다르다. 문구에 박아 두면 다음 고객의 작업자에게
        // 남의 회사 이름으로 문자가 나간다.
        '<a href="sms:?&body=' + encodeURIComponent('[' + (window.ORG_NAME || 'ERP') + '] 출퇴근 앱 번호 설정: ' + res.url) + '" ' +
        'class="btn-secondary" style="padding:8px 14px;font-size:13px;text-decoration:none">문자로 보내기</a>' +
        '</div>';

      u.modal({ title: isReset ? 'PIN 재설정 링크' : 'PIN 초대 링크', body: body, width: 520 });

      setTimeout(function () {
        var btn = document.getElementById('pin-copy');
        var box = document.getElementById('pin-link-box');
        if (!btn || !box) return;
        btn.addEventListener('click', function () {
          box.select();
          try { document.execCommand('copy'); } catch (e) {}
          if (navigator.clipboard) { navigator.clipboard.writeText(box.value).catch(function () {}); }
          u.toast('링크를 복사했습니다.');
        });
      }, 60);
    }).catch(function (e) { u.toast(e.message || '발급하지 못했습니다.', 'error'); });
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
          { name: 'email', label: '이메일 (작업자·반장은 선택)', colSpan: 2, value: r.email || '',
            hint: '전화번호가 등록된 작업자·반장은 이메일 없이 PIN으로 연결할 수 있습니다. 계정 생성 후 개인 PIN 링크를 발급하세요. 관리자 계정은 이메일이 필요합니다.' },
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
        // 근무 기록까지 지워졌으면 그 사실을 그냥 넘기지 않는다 — 되돌릴 수 없는 일이다.
        u.toast(res.removedLogs
          ? '직원을 삭제했습니다. 출퇴근 기록 ' + res.removedLogs + '건도 함께 지워졌습니다.'
          : '직원을 삭제했습니다.');
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
    pinLink: pinLink,
    copyOnboardingLink: copyOnboardingLink,
    confirmRegistration: confirmRegistration,
    remove: remove,
    _state: state,
  };
})(window);
