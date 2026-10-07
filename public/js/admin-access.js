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
  var state = { rows: [], options: null, newInvitations: [] };

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
    notes.push(rows.filter(function (r) { return r.erpAccess; }).length + '명 ERP 입장 허용');
    if (inactive) notes.push(inactive + '개 비활성');
    if (gaps) notes.push(gaps + '개 범위 미지정');

    return u.pageHeader(
      '계정 · 권한 관리',
      'ERP 본화면은 여기서 허용한 사람만 들어옵니다. 현장 인력은 전화번호 뒷 4자리로 작업자 앱을 씁니다. — ' + notes.join(' · '),
      (state.options && state.options.canIssueInvitations
        ? u.primaryButton('신규 관리자 초대', 'window.AdminAccess.inviteNew()', 'user-plus') + ' ' : '') +
      u.primaryButton('계정 추가', 'window.AdminAccess.openForm()', 'plus')
    ) + (state.options && state.options.purchasingReauthenticationRequired
      ? u.notice('구매 권한 변경은 이메일·비밀번호 또는 Google로 다시 로그인한 후 가능합니다.', 'warn') +
        '<div style="margin-bottom:14px">' + u.rowButton('ERP 로그인', 'PurchaseRequests.reauthenticate()') + '</div>'
      : '') + u.table({
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
        { key: 'purchaseAccess', label: '구매 권한', render: function (r) {
          if (r.role === 'super_admin') return u.badge('구매신청 · 구매처리 기본 허용', 'ok');
          return (r.purchaseRequestAccess ? u.badge('구매신청', 'ok') : '') +
            (r.purchaseBuyerAccess ? ' ' + u.badge('구매처리', 'warn') : '') || '—';
        } },
        {
          // 현장 인력은 전화번호 뒷 4자리로 작업자 앱에 들어오고, ERP 본화면은 승인된
          // 사람에게만 열린다. 그 «승인» 이 목록에서 보이지 않으면 아무도 관리할 수 없다.
          key: 'erpAccess', label: 'ERP 입장', width: '110px',
          render: function (r) {
            return r.erpAccess
              ? u.badge('허용됨', 'ok')
              : u.badge('작업자 앱만', 'muted');
          },
        },
        {
          key: 'status', label: '상태', width: '110px',
          render: function (r) { return u.badge(r.statusLabel, statusKind(r.status)); },
        },
        {
          key: 'act', label: '', align: 'right', width: '210px',
          render: function (r) {
            // 자기 계정은 스스로 잠그지 못하게 상태/삭제를 막는다(서버도 같이 막는다).
            if (r.isSelf) {
              return (state.options.jobCatalog ? u.rowButton('직책·세부권한', 'window.AdminAccess.openJob(' + r.id + ')') + ' ' : '') + '<span style="font-size:11px;color:var(--text-tertiary)">본인 계정</span> ' +
                u.rowButton('수정', 'window.AdminAccess.openForm(' + r.id + ')');
            }
            var toggle = r.status === 'active'
              ? u.rowButton('정지', 'window.AdminAccess.setStatus(' + r.id + ',"suspended")')
              : u.rowButton('활성화', 'window.AdminAccess.setStatus(' + r.id + ',"active")');
            // ERP 입장을 주고 빼는 것은 «역할을 바꾸는 일» 이다. 그래서 따로 만든 손잡이가
            // 아니라 역할 화면을 연다 — 손잡이가 둘이면 어느 쪽이 정본인지 아무도 모른다.
            var erp = r.erpAccess
              ? u.rowButton('ERP 입장 해제', 'window.AdminAccess.openForm(' + r.id + ')')
              : u.rowButton('ERP 입장 허용', 'window.AdminAccess.openForm(' + r.id + ')');
            if (r.canInvite && state.options && state.options.canIssueInvitations) {
              erp = u.rowButton(r.invitationPending ? '초대 재발급' : '관리자로 초대', 'window.AdminAccess.invite(' + r.id + ')');
            }
            if (r.invitationPending && state.options && state.options.canIssueInvitations) erp += ' ' + u.rowButton('초대 취소', 'window.AdminAccess.revokeInvite(' + r.id + ')');
            return (state.options.jobCatalog ? u.rowButton('직책·세부권한', 'window.AdminAccess.openJob(' + r.id + ')') + ' ' : '') + erp + ' ' + toggle + ' ' +
              u.rowButton('수정', 'window.AdminAccess.openForm(' + r.id + ')') + ' ' +
              u.rowButton('삭제', 'window.AdminAccess.remove(' + r.id + ')', 'danger');
          },
        },
      ],
      rows: rows,
    }) + renderNewInvitations();
  }

  function renderNewInvitations() {
    if (!state.options || !state.options.canIssueInvitations || !state.newInvitations.length) return '';
    var u = ui();
    return '<h3 style="margin-top:24px">등록 대기 중인 신규 관리자 초대</h3>' + u.table({
      id: 'ua-invites', rows: state.newInvitations,
      columns: [
        { key: 'label', label: '초대 메모' },
        { key: 'roleLabel', label: '역할' },
        { key: 'scopeLabel', label: '관리 범위' },
        { key: 'expiresAt', label: '유효기간', render: function (r) {
          return u.esc(r.expiresAt) + (r.expired ? ' · 만료' : '');
        } },
        { key: '_actions', label: '관리', render: function (r) {
          return u.rowButton('초대 재발급', 'window.AdminAccess.inviteNew(' + r.id + ')') + ' ' +
            u.rowButton('초대 취소', 'window.AdminAccess.revokeNewInvite(' + r.id + ')');
        } }
      ]
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
      state.newInvitations = res.newInvitations || [];
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
        subtitle: o.purchasingReauthenticationRequired
          ? '구매 권한 변경은 화면 상단의 다시 로그인 버튼을 이용하세요.'
          : row && row.role === 'super_admin'
          ? '수퍼관리자는 구매신청·구매처리가 기본 허용됩니다.'
          : self
          ? '본인 계정입니다. 역할과 상태는 다른 관리자만 바꿀 수 있습니다.'
          : '역할은 무엇을 할 수 있는지, 범위는 어느 현장까지 보이는지를 정합니다.',
        saveLabel: row ? '수정' : '추가',
        fields: [
          { name: 'name', label: '이름', required: true, group: '기본 정보', value: row ? row.name : '' },
          { name: 'email', label: '이메일', type: 'email', group: '기본 정보',
            value: row ? row.email : '', hint: '전화번호가 등록된 직원에 연결된 작업자·반장은 선택입니다. 다른 역할은 이메일이 필요합니다.' },
          { name: 'employeeId', label: '연결할 직원', type: 'select', group: '기본 정보',
            options: o.employees, value: row ? row.employeeId : '',
            hint: '연결하면 출퇴근·급여가 이 계정과 이어집니다.', colSpan: 2 },

          { name: 'role', label: '역할', type: 'select', required: true, group: '권한',
            options: o.roles, value: row ? row.role : 'worker',
            // 역할이 곧 «ERP 본화면에 들어갈 수 있는가» 다. 그 사실을 여기서 말해 주지
            // 않으면, 관리자는 작업자 역할을 준 사람이 왜 ERP 를 못 여는지 알 수 없다.
            hint: self
              ? '본인 계정이라 바꿀 수 없습니다.'
              : 'ERP 본화면은 관리자·인사담당·현장소장·안전관리자·급여·협력사관리자·원청·열람전용에게 열립니다. '
                + '작업자·작업반장은 전화번호 뒷 4자리로 작업자 앱만 씁니다. 자기보다 높은 역할은 부여할 수 없습니다.' },
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
        ].concat(o.canManagePurchasingGrants && (!row || row.role !== 'super_admin') ? [
          { name: 'purchaseRequestAccess', label: '개인앱 구매신청', type: 'checkbox', group: '구매 권한',
            value: Boolean(row && row.purchaseRequestAccess), checkboxLabel: '허용', hint: '관리자 계정에만 부여할 수 있습니다.' },
          { name: 'purchaseBuyerAccess', label: 'ERP 구매처리', type: 'checkbox', group: '구매 권한',
            value: Boolean(row && row.purchaseBuyerAccess), checkboxLabel: '구매 담당자로 지정' }
        ] : []),
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

  function openJob(id) {
    var row=state.rows.find(function(r){return r.id===id;});
    loadOptions().then(function(o){
      if(!o.jobCatalog || !row)return;
      var editor;
      ui().formModal({title:'직책·세부권한 — '+row.name, subtitle:'기존 직원·출퇴근 기록은 유지됩니다. 소속 회사와 담당 범위를 확인하세요.',saveLabel:'직책·권한 적용',
        fields:[
          {name:'jobRole',label:'직책',type:'select',required:true,value:row.jobRole||'site_manager',options:Object.entries(o.jobCatalog.jobs).map(function(e){return {value:e[0],label:e[1].label};})},
          {name:'scope',label:'담당 범위',type:'select',required:true,value:row.jobRole?row.scope:'site',options:o.scopes.filter(function(s){return s.value!=='all_sites';})},
          {name:'companyId',label:'소속 회사',type:'select',required:true,options:o.companies,value:row.companyId||''},
          {name:'teamId',label:'담당 팀 (팀 범위)',type:'select',options:o.teams,value:row.teamId||''}
        ],
        onReady:function(wrap){editor=global.JobPermissionEditor.attach(wrap,o.jobCatalog,o.sites,{permissions:row.jobPermissions,duties:row.jobDuties||[],siteIds:row.siteIds||[Number(row.siteId)].filter(Boolean)});},
        onSave:function(v){Object.assign(v,editor.read());return call('api_setJobAccess',[id,v]).then(function(res){if(res.success===false)return res;ui().toast('직책·업무 권한을 적용했습니다.');return reload().then(function(){return {success:true};});});}
      });
    });
  }

  function inviteNew(invitationId) {
    var previous = state.newInvitations.find(function (r) { return r.id === invitationId; });
    invite(null, previous);
  }

  function invite(id, previous) {
    var u = ui();
    var row = state.rows.find(function (r) { return r.id === id; });
    var isNew = id === null;
    if (!row && !isNew) return;
    row = row || { name: '새 입사자', siteId: previous && previous.enrollment.site_id,
      companyId: previous && previous.enrollment.company_id };
    loadOptions().then(function (o) {
      var jobEditor;
      u.formModal({
        title: isNew ? '신규 관리자 초대' : '관리자로 초대 — ' + row.name,
        subtitle: isNew ? '직원 사전 등록 없이 링크 하나를 전달합니다. 직원이 이름·전화번호·로그인 정보를 입력하면 등록이 완료됩니다. 7일·1회용입니다.'
          : '직원이 직접 로그인 정보를 등록합니다. 7일간 유효하며 재발급 시 이전 초대는 취소됩니다.',
        saveLabel: '초대 링크 · QR 만들기',
        fields: [
          { name: 'role', label: '역할', type: 'select', required: true, value: previous ? previous.grant.access_role : 'site_manager',
            hint: '관리자는 전체 현장 권한입니다. 담당 현장만 관리할 직원은 현장관리자를 선택하세요.',
            options: o.roles.filter(function (r) { return ['admin', 'site_manager'].indexOf(r.value) >= 0; }) },
          { name: 'scope', label: '관리 범위', type: 'select', required: true, value: previous ? previous.grant.access_scope : 'site',
            options: o.scopes.filter(function (r) { return ['site', 'company', 'all_sites'].indexOf(r.value) >= 0; }) },
          { name: 'siteId', label: '담당 현장', type: 'select', options: o.sites, value: row.siteId || '' },
          { name: 'companyId', label: isNew ? '소속 회사 · 회사 범위일 때 담당 회사' : '담당 회사', type: 'select', options: o.companies, value: row.companyId || '' }
        ].concat(o.jobCatalog ? [{name:'jobRole',label:'직책',type:'select',required:true,value:previous && previous.grant.job_role || 'site_manager',
          options:Object.entries(o.jobCatalog.jobs).map(function(e){return {value:e[0],label:e[1].label};})},
          {name:'teamId',label:'담당 팀 (팀 범위)',type:'select',options:o.teams,value:previous && previous.grant.allowed_team_id || ''}] : []).concat(isNew ? [{ name: 'recipientLabel', label: '초대 메모 (선택)', value: previous ? previous.label : '',
          hint: '예: 703K 새 소장. 직원 이름·이메일은 직원이 직접 입력합니다.' }] : []),
        onReady: function (form) {
          var role = form.querySelector('[name="role"]');
          var scope = form.querySelector('[name="scope"]');
          function syncScope() {
            if (role.value === 'admin') scope.value = 'all_sites';
            scope.disabled = role.value === 'admin';
          }
          if(o.jobCatalog){
            role.parentElement.style.display='none';
            form.querySelector('[name="siteId"]').parentElement.style.display='none';
            scope.disabled=false;
            scope.innerHTML=o.scopes.filter(function(s){return s.value!=='all_sites';}).map(function(s){return '<option value="'+u.esc(s.value)+'">'+u.esc(s.label)+'</option>';}).join('');
            scope.value=previous && previous.grant.access_scope || 'site';
            jobEditor=global.JobPermissionEditor.attach(form,o.jobCatalog,o.sites,{permissions:previous && previous.grant.job_permissions,duties:previous && previous.grant.job_duties || [],siteIds:previous && previous.grant.job_site_ids || [Number(row.siteId)].filter(Boolean)});
          }else{role.addEventListener('change', syncScope);syncScope();}
        },
        onSave: function (v) {
          v.kind = isNew ? 'new_employee' : 'existing_worker';
          if (isNew && previous) v.replaceInvitationId = previous.id;
          if (!isNew) v.id = id;
          if(jobEditor)Object.assign(v,jobEditor.read());
          else if (v.role === 'admin') v.scope = 'all_sites';
          return call('api_createManagerInvitation', [v]).then(function (res) {
            if (res.success === false) return res;
            // Wait until formModal closes before presenting the sharing dialog.
            setTimeout(function () { showInvitation(res); }, 0);
            reload();
            return { success: true };
          });
        }
      });
    }).catch(function (e) { u.toast(e.message, 'error'); });
  }

  function showInvitation(res) {
    var u = ui();
    var isNew = res.kind === 'new_employee';
    var message = isNew ? '관리자 입사 등록 초대입니다. 아래 링크에서 이름·전화번호를 입력하고 Google 또는 이메일·새 ERP 비밀번호로 등록해 주세요. 직원 등록과 관리자 설정이 함께 완료됩니다.\n' + res.url
      : res.name + ' 님, 관리자 등록을 완료해 주세요. 기존 등록 전화번호를 확인한 뒤 Google 또는 이메일·비밀번호를 설정하면 됩니다.\n' + res.url;
    u.modal({
      title: '카톡 · QR로 초대', width: 460,
      subtitle: isNew ? '한 명에게만 전달하세요. 등록 완료 전에는 직원·계정이 생성되지 않습니다.'
        : '초대받은 직원 본인에게만 전달하세요. 등록 완료 전까지 현재 작업자 권한이 유지됩니다.',
      body: '<div style="text-align:center"><img alt="관리자 등록 초대 QR" src="' + u.esc(res.qr) + '" style="width:240px;max-width:100%"></div>' +
        '<p>유효기간: ' + u.esc(res.expiresAt) + '</p>' +
        '<textarea aria-label="카톡으로 보낼 초대 내용" readonly style="box-sizing:border-box;width:100%;min-height:135px">' + u.esc(message) + '</textarea>',
      actions: [{ label: '카톡에 보낼 내용 복사', value: 'keep', action: 'copy', kind: 'primary' },
        { label: '공유하기', value: 'keep', action: 'share' }, { label: '닫기', value: null }],
      onAction: function (action, wrap) {
        if (action.action === 'share' && navigator.share) {
          navigator.share({ title: '관리자 초대', text: message }).catch(function (e) { if (e.name !== 'AbortError') u.toast('내용을 복사해 카톡으로 보내세요.', 'error'); });
          return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(message).then(function () { u.toast('복사했습니다. 카톡 대화에 붙여넣어 보내세요.'); })
            .catch(function () { wrap.querySelector('textarea').select(); u.toast('선택된 내용을 직접 복사하세요.'); });
        } else { wrap.querySelector('textarea').select(); u.toast('선택된 내용을 직접 복사하세요.'); }
      }
    });
  }

  function revokeInvite(id) {
    var u = ui();
    u.confirmDanger({ title: '초대를 취소할까요?', body: '전달한 링크와 QR로 더 이상 등록할 수 없습니다. 기존 직원 기록은 유지됩니다.', confirmLabel: '초대 취소' }).then(function (ok) {
      if (!ok) return;
      return call('api_revokeManagerInvitation', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error, 'error'); return; }
        u.toast('초대를 취소했습니다.'); return reload();
      });
    }).catch(function (e) { u.toast(e.message, 'error'); });
  }

  function revokeNewInvite(id) {
    var u = ui();
    u.confirmDanger({ title: '초대를 취소할까요?', body: '전달한 링크와 QR로 더 이상 등록할 수 없습니다.', confirmLabel: '초대 취소' }).then(function (ok) {
      if (!ok) return;
      return call('api_revokeNewManagerInvitation', [id]).then(function (res) {
        if (res.success === false) { u.toast(res.error, 'error'); return; }
        u.toast('초대를 취소했습니다.'); return reload();
      });
    }).catch(function (e) { u.toast(e.message, 'error'); });
  }

  /** SPA 라우터가 부르는 진입점. */
  function renderScreen() {
    paint('<div style="padding:40px;text-align:center;color:var(--text-tertiary)">불러오는 중…</div>');
    loadOptions().then(reload).catch(function (e) {
      paint('<div style="padding:40px;text-align:center;color:var(--status-danger)">' +
        ui().esc(e.message || '계정 목록을 불러오지 못했습니다.') + '</div>');
    });
    return '';
  }

  global.AdminAccess = {
    render: renderScreen,
    openForm: openForm,
    openJob: openJob,
    setStatus: setStatus,
    remove: remove,
    invite: invite,
    inviteNew: inviteNew,
    revokeNewInvite: revokeNewInvite,
    revokeInvite: revokeInvite,
    _state: state,
    _scopeGap: scopeGap,
  };
})(window);
