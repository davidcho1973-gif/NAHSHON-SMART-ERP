/**
 * 계정 · 권한 관리 화면 — Filament 의 Access Control 을 SPA 로 옮긴 것.
 *
 * 이 화면이 답해야 하는 질문은 하나다: "누가 무엇을 볼 수 있나."
 * 그래서 목록의 주인공은 이름이 아니라 역할 · 범위 · 상태 세 칸이고, 범위가 "지정 현장"
 * 인데 현장이 비어 있으면 그 사람은 아무것도 못 보므로 눈에 띄게 표시한다.
 *
 * 서버(UserAccessService)가 모든 권한을 다시 판단한다. 여기서 버튼을 숨기는 것은
 * 편의일 뿐 방어가 아니다.
 */
(function (global) {
  'use strict';

  var A = null;          // window.AdminUI — defer 순서상 실행 시점에 잡는다
  var state = { rows: [], options: null };

  function ui() {
    if (!A) A = global.AdminUI;
    return A;
  }

  function call(method, args) {
    // SPA 가 이미 쓰는 호출기. 실패하면 fallback 이 아니라 예외로 올려야
    // "저장됐다" 고 잘못 보여주지 않는다.
    return global.gsRun(method, args || [], null).then(function (res) {
      if (!res) throw new Error('서버 응답이 없습니다.');
      return res;
    });
  }

  /** 범위가 요구하는 대상이 비었는지 — 비면 그 계정은 아무것도 못 본다. */
  function scopeGap(row) {
    if (row.scope === 'site' && !row.siteId) return '현장 미지정';
    if (row.scope === 'company' && !row.companyId) return '회사 미지정';
    if (row.scope === 'team' && !row.teamId) return '팀 미지정';
    return null;
  }

  function scopeCell(row) {
    var u = ui();
    var gap = scopeGap(row);
    var target = row.site || row.company || row.team || '';
    var main = u.esc(row.scopeLabel || row.scope);
    if (gap) {
      return main + ' <span style="color:var(--status-warning);font-size:11px">· ' + u.esc(gap) + '</span>';
    }
    return main + (target ? ' <span style="color:var(--text-tertiary);font-size:12px">· ' + u.esc(target) + '</span>' : '');
  }

  function statusKind(s) {
    return s === 'active' ? 'ok' : s === 'pending' ? 'warn' : 'danger';
  }

  function render() {
    var u = ui();
    var rows = state.rows;

    var inactive = rows.filter(function (r) { return r.status !== 'active'; }).length;
    var gaps = rows.filter(scopeGap).length;

    var notes = [];
    notes.push(rows.length + '개 계정');
    if (inactive) notes.push(inactive + '개 비활성');
    if (gaps) notes.push(gaps + '개 범위 미지정');

    return u.pageHeader(
      '계정 · 권한 관리',
      '누가 어느 현장까지 볼 수 있는지 정합니다. — ' + notes.join(' · '),
      u.primaryButton('계정 추가', 'window.AdminAccess.openForm()', 'plus')
    ) + u.table({
      id: 'ua-tbl',
      searchPlaceholder: '이름 · 이메일 · 현장 검색',
      emptyText: '등록된 계정이 없습니다.',
      columns: [
        {
          key: 'name', label: '이름', width: '190px',
          render: function (r) {
            return '<div style="font-weight:600">' + u.esc(r.name) +
              (r.isSelf ? ' <span style="font-size:11px;color:var(--text-tertiary)">(나)</span>' : '') + '</div>' +
              (r.employeeNumber ? '<div style="font-size:11px;color:var(--text-tertiary)">' + u.esc(r.employeeNumber) + '</div>' : '');
          },
        },
        { key: 'email', label: '이메일' },
        {
          key: 'roleLabel', label: '역할',
          // 권한 세기로 색을 나눈다 — 계정이 수십 개가 되면 "누가 관리자인지" 를
          // 한눈에 못 찾는 것이 실제 문제다.
          render: function (r) {
            var kind = r.roleTier === 'high' ? 'danger' : r.roleTier === 'mid' ? 'warn'
              : r.roleTier === 'external' ? 'muted' : 'ok';
            return u.badge(r.roleLabel, kind);
          },
        },
        { key: 'scope', label: '범위', render: scopeCell },
        {
          key: 'status', label: '상태', width: '110px',
          render: function (r) { return u.badge(r.statusLabel, statusKind(r.status)); },
        },
        {
          key: 'act', label: '', align: 'right', width: '210px',
          render: function (r) {
            // 자기 계정은 스스로 잠그지 못하게 상태/삭제를 막는다(서버도 같이 막는다).
            if (r.isSelf) {
              return '<span style="font-size:11px;color:var(--text-tertiary)">본인 계정</span> ' +
                u.rowButton('수정', 'window.AdminAccess.openForm(' + r.id + ')');
            }
            var toggle = r.status === 'active'
              ? u.rowButton('정지', 'window.AdminAccess.setStatus(' + r.id + ',"suspended")')
              : u.rowButton('활성화', 'window.AdminAccess.setStatus(' + r.id + ',"active")');
            return toggle + ' ' +
              u.rowButton('수정', 'window.AdminAccess.openForm(' + r.id + ')') + ' ' +
              u.rowButton('삭제', 'window.AdminAccess.remove(' + r.id + ')', 'danger');
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
    return call('api_getUserAccessList').then(function (res) {
      if (res.success === false) {
        paint('<div style="padding:40px;text-align:center;color:var(--text-secondary)">' +
          ui().esc(res.error || '계정 목록을 불러오지 못했습니다.') + '</div>');
        return;
      }
      state.rows = res.rows || [];
      paint(render());
      ui().bindSearch('ua-tbl');
    });
  }

  function loadOptions() {
    if (state.options) return Promise.resolve(state.options);
    return call('api_getUserAccessOptions').then(function (res) {
      if (res.success === false) throw new Error(res.error || '선택지를 불러오지 못했습니다.');
      state.options = res;
      return res;
    });
  }

  function openForm(id) {
    var u = ui();
    var row = id ? state.rows.filter(function (r) { return r.id === id; })[0] : null;

    loadOptions().then(function (o) {
      var self = row && row.isSelf;
      u.formModal({
        title: row ? '계정 수정 — ' + row.name : '계정 추가',
        subtitle: self
          ? '본인 계정입니다. 역할과 상태는 다른 관리자만 바꿀 수 있습니다.'
          : '역할은 무엇을 할 수 있는지, 범위는 어느 현장까지 보이는지를 정합니다.',
        saveLabel: row ? '수정' : '추가',
        fields: [
          { name: 'name', label: '이름', required: true, group: '기본 정보', value: row ? row.name : '' },
          { name: 'email', label: '이메일', type: 'email', required: true, group: '기본 정보',
            value: row ? row.email : '', hint: '구글 로그인에 쓰는 주소여야 합니다.' },
          { name: 'employeeId', label: '연결할 직원', type: 'select', group: '기본 정보',
            options: o.employees, value: row ? row.employeeId : '',
            hint: '연결하면 출퇴근·급여가 이 계정과 이어집니다.', colSpan: 2 },

          { name: 'role', label: '역할', type: 'select', required: true, group: '권한',
            options: o.roles, value: row ? row.role : 'worker',
            hint: self ? '본인 계정이라 바꿀 수 없습니다.' : '자기보다 높은 역할은 부여할 수 없습니다.' },
          { name: 'status', label: '상태', type: 'select', required: true, group: '권한',
            options: o.statuses, value: row ? row.status : 'active',
            hint: self ? '본인 계정이라 바꿀 수 없습니다.' : '' },
          { name: 'scope', label: '범위', type: 'select', required: true, group: '권한',
            options: o.scopes, value: row ? row.scope : 'self',
            hint: '"지정 현장"을 고르면 아래 현장을 반드시 정해야 합니다.' },
          { name: 'siteId', label: '현장', type: 'select', group: '권한',
            options: o.sites, value: row ? row.siteId : '' },
          { name: 'companyId', label: '회사', type: 'select', group: '권한',
            options: o.companies, value: row ? row.companyId : '' },
          { name: 'teamId', label: '팀', type: 'select', group: '권한',
            options: o.teams, value: row ? row.teamId : '' },

          { name: 'notes', label: '메모', type: 'textarea', colSpan: 2, group: '권한',
            value: row ? row.notes : '', hint: '왜 이 권한을 줬는지 남겨두면 나중에 정리할 때 도움이 됩니다.' },
        ],
        onSave: function (v) {
          v.id = id || 0;
          return call('api_saveUserAccess', [v]).then(function (res) {
            if (res.success === false) return res;   // errors 는 공통 틀이 칸 밑에 붙인다
            u.toast(row ? '계정을 수정했습니다.' : '계정을 추가했습니다.');
            if (global.opsClearCache) global.opsClearCache();
            return reload().then(function () { return { success: true }; });
          });
        },
      });
    }).catch(function (e) {
      u.toast(e.message || '선택지를 불러오지 못했습니다.', 'error');
    });
  }

  function setStatus(id, status) {
    var u = ui();
    var row = state.rows.filter(function (r) { return r.id === id; })[0];
    var go = status === 'active'
      ? Promise.resolve(true)
      : u.confirmDanger({
          title: '계정을 정지할까요?',
          body: (row ? row.name + '(' + row.email + ')' : '이 계정') + ' 은(는) 정지되면 로그인할 수 없습니다. 다시 활성화할 수 있습니다.',
          confirmLabel: '정지',
        });

    go.then(function (ok) {
      if (!ok) return;
      return call('api_setUserAccessStatus', [id, status]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '상태를 바꾸지 못했습니다.', 'error'); return; }
        u.toast(status === 'active' ? '계정을 활성화했습니다.' : '계정을 정지했습니다.');
        if (global.opsClearCache) global.opsClearCache();
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  function remove(id) {
    var u = ui();
    var row = state.rows.filter(function (r) { return r.id === id; })[0];
    u.confirmDanger({
      title: '계정을 삭제할까요?',
      body: (row ? row.name + '(' + row.email + ')' : '이 계정') + ' 계정이 삭제됩니다. 되돌릴 수 없습니다. ' +
        '다시 쓸 가능성이 있으면 삭제 대신 "정지"를 쓰세요.',
      confirmLabel: '삭제',
    }).then(function (ok) {
      if (!ok) return;
      return call('api_deleteUserAccess', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error || '삭제하지 못했습니다.', 'error'); return; }
        u.toast('계정을 삭제했습니다.');
        if (global.opsClearCache) global.opsClearCache();
        return reload();
      });
    }).catch(function (e) { u.toast(e.message || '오류가 발생했습니다.', 'error'); });
  }

  /** SPA 라우터가 부르는 진입점. */
  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    reload().catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
        ui().esc(e.message || '계정 목록을 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminAccess = {
    render: renderScreen,
    openForm: openForm,
    setStatus: setStatus,
    remove: remove,
    _state: state,
    _scopeGap: scopeGap,
  };
})(window);
