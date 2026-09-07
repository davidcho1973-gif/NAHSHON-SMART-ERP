/* Company → site → team → people. Reuses the same company/team/employee masters. */
(function (global) {
  'use strict';
  var data = null, companyId = '', siteId = '', selectedTeam = '';
  function ui() { return global.AdminUI; }
  function call(method, input) {
    return global.gsRun(method, input ? [input] : [], null).then(function (r) {
      if (!r) throw new Error('서버 응답이 없습니다.');
      return r;
    });
  }
  function paint(html) { var h = document.getElementById('page-container'); if (h) h.innerHTML = html; }
  function same(a, b) { return String(a || '') === String(b || ''); }
  function options(rows, label) { return rows.map(function (r) { return { value: r.id, label: label ? label(r) : r.name }; }); }
  function inContext(e) { return same(e.companyId, companyId) && same(e.siteId, siteId); }
  function teams() { return data.teams.filter(inContext); }
  function select(id, label, rows, value) {
    return '<label style="display:grid;gap:7px;flex:1;min-width:200px">' + ui().esc(label) +
      '<select id="' + id + '" style="padding:11px;border:1px solid var(--border-default);border-radius:8px;background:var(--bg-base);color:var(--text-primary)">' +
      '<option value="">선택하세요</option>' + rows.map(function (r) {
        return '<option value="' + ui().esc(r.value) + '"' + (same(r.value, value) ? ' selected' : '') + '>' + ui().esc(r.label) + '</option>';
      }).join('') + '</select></label>';
  }
  function navigate(view) {
    var n = document.querySelector('[data-view="' + view + '"]');
    if (n) n.click();
  }
  function draw() {
    var u = ui(), list = teams(), people = data.employees.filter(inContext);
    var company = data.companies.find(function (c) { return same(c.id, companyId); });
    var current = list.find(function (t) { return same(t.id, selectedTeam); });
    if (!current) selectedTeam = '';
    var action = function (label, fn) { return u.rowButton(label, u.esc(fn)); };
    var companyButton = data.canManageCompanies ? action('회사 추가', 'AdminCrew.company()') : '';
    var steps = '<div style="display:flex;flex-wrap:wrap;gap:10px;margin:18px 0">' +
      ['1. 회사 선택', '2. 현장 선택', '3. 팀·반장 등록', '4. 작업자 배치·계정 확인'].map(function (s) {
        return '<span style="padding:9px 13px;background:var(--bg-subtle);border:1px solid var(--border-default);border-radius:8px">' + u.esc(s) + '</span>';
      }).join('') + '</div>';
    var html = u.pageHeader('회사·팀 등록', '소속을 먼저 정하고, 팀과 담당 반장을 연결합니다.', companyButton) + steps +
      '<div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:16px">' +
      select('crew-company', '소속 회사', options(data.companies, function (c) { return c.name + (c.status !== 'active' ? ' (비활성)' : ''); }), companyId) +
      select('crew-site', '현장', options(data.sites, function (s) { return s.code + ' — ' + s.name; }), siteId) + '</div>' +
      '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px">' +
      (company && data.canManageCompanies ? action('회사 정보·분류 수정', 'AdminCrew.company(' + company.id + ')') : '') +
      action('현장 등록·수정', 'AdminCrew.navigate("site-admin")') + action('직원 등록·관리', 'AdminCrew.navigate("employee-admin")') +
      action('계정·권한 관리', 'AdminCrew.navigate("access-control")') + '</div>';
    if (!companyId || !siteId) {
      paint(html + '<p>회사와 현장을 선택하면 팀과 등록 인원이 표시됩니다.</p>');
    } else {
      html += '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center"><h3>팀 · 담당 반장</h3>' +
        (data.canManage ? u.primaryButton('팀 등록', 'AdminCrew.team()', 'users-three') : '') + '</div>';
      html += u.table({id:'crew-teams', emptyText:'아직 팀이 없습니다. 팀 등록을 눌러 공종별 팀을 만드세요.', columns:[
        {key:'name',label:'팀'}, {key:'trade',label:'공종'}, {key:'foreman',label:'담당 반장',render:function(t){return u.esc(t.foreman || '미지정');}},
        {key:'members',label:'등록 인원'}, {key:'planned',label:'계획 인원'},
        {key:'status',label:'상태',render:function(t){return u.esc(t.status === 'active' ? '활성' : '비활성');}},
        {key:'actions',label:'',render:function(t){return action('인원 보기','AdminCrew.chooseTeam(' + t.id + ')') +
          (data.canManage ? ' ' + action('수정','AdminCrew.team(' + t.id + ')') : '');}}
      ], rows:list});
      html += '<div style="margin-top:28px;display:flex;align-items:center;justify-content:space-between"><h3>' +
        u.esc(current ? current.name + ' · 소속 인원' : '현장 등록 인원') + '</h3>' +
        (current ? action('전체 인원','AdminCrew.chooseTeam(0)') : '') + '</div>';
      var rows = current ? people.filter(function(e){return same(e.teamId, current.id);}) : people;
      html += '<p style="color:var(--text-secondary)">팀 배치와 앱 권한은 별도입니다. 반장 지정 후 QR 역할과 로그인 역할을 확인하세요.</p>';
      html += u.table({id:'crew-people',emptyText:'등록된 인원이 없습니다. 직원 등록·관리에서 먼저 등록하세요.',columns:[
        {key:'name',label:'이름'},
        {key:'teamId',label:'팀',render:function(e){var t=list.find(function(t){return same(t.id,e.teamId);});return u.esc(t?t.name:'미배치');}},
        {key:'accountRole',label:'앱 계정',render:function(e){
          var role = {worker:'작업자',foreman:'작업반장',admin:'관리자',super_admin:'슈퍼관리자',site_manager:'현장소장',hr_manager:'인사담당'}[e.accountRole] || e.accountRole;
          return u.esc(role ? role + (e.accountStatus !== 'active' ? ' · 비활성' : '') : '계정 필요');
        }},
        {key:'ready',label:'확인할 사항',render:function(e){
          var notes=[];
          if(!e.teamId) notes.push('팀 배치');
          if(!e.accountRole) notes.push('계정 만들기');
          if(e.accountScope==='team' && !same(e.accountTeamId,e.teamId)) notes.push('계정 팀 확인');
          if(list.some(function(t){return same(t.foremanId,e.id);}) && (e.accountRole!=='foreman' || e.qrRole!=='foreman')) notes.push('반장 권한 확인');
          return u.esc(notes.join(' · ') || '설정 연결됨');
        }},
        {key:'actions',label:'',render:function(e){return data.canManage?action('팀 배치','AdminCrew.assign(' + e.id + ')'):'';}}
      ],rows:rows});
      paint(html);
      u.bindSearch('crew-teams'); u.bindSearch('crew-people');
    }
    document.getElementById('crew-company').addEventListener('change',function(e){companyId=e.target.value;selectedTeam='';draw();});
    document.getElementById('crew-site').addEventListener('change',function(e){siteId=e.target.value;selectedTeam='';draw();});
  }
  function reload() {
    return call('api_getCrewSetup').then(function(r){
      if(r.success===false) throw new Error(r.error || '조회 권한이 없습니다.');
      data=r;
      if(!companyId) companyId=String(r.defaultCompanyId || '');
      if(!siteId && r.sites.length===1) siteId=String(r.sites[0].id);
      draw();
    });
  }
  function save(method,input,id) {
    if(id) input.id=id;
    return call(method,input).then(function(r){
      if(r.success!==false) { ui().toast('저장했습니다.'); return reload().then(function(){return r;}); }
      return r;
    });
  }
  function company(id) {
    var c=data.companies.find(function(x){return same(x.id,id);}) || {};
    ui().formModal({title:id?'회사 정보·분류 수정':'회사 추가',subtitle:'회사 분류는 신규 등록자의 고용 형태에 사용됩니다. 기존 직원의 급여 조건은 바뀌지 않습니다.',fields:[
      {name:'name',label:'회사명',required:true,value:c.name},
      {name:'code',label:'회사 코드',required:true,value:c.code,hint:'영문·숫자·밑줄. 등록 후 변경하지 않습니다.'},
      {name:'legal_name',label:'법인명',value:c.legal_name},
      {name:'company_type',label:'회사 구분',type:'select',required:true,value:c.company_type || 'partner',options:Object.keys(data.companyTypes).map(function(k){return {value:k,label:data.companyTypes[k]};})},
      {name:'status',label:'상태',type:'select',required:true,value:c.status || 'active',options:[{value:'active',label:'활성'},{value:'inactive',label:'비활성'}]}
    ],onSave:function(v){return save('api_saveCrewCompany',v,id).then(function(r){if(r.success!==false){companyId=String(r.id);draw();}return r;});}});
  }
  function team(id) {
    if(!companyId || !siteId) {ui().toast('회사와 현장을 먼저 선택하세요.','error');return;}
    var t=data.teams.find(function(x){return same(x.id,id);}) || {};
    var candidates=data.employees.filter(function(e){return inContext(e) && e.status==='active' && (!e.teamId || same(e.teamId,id));});
    ui().formModal({title:id?'팀·반장 수정':'팀 등록',subtitle:'선택한 회사·현장에 등록합니다. 반장은 직원 명단에서 선택하며 앱 권한은 별도로 승인합니다.',fields:[
      {name:'name',label:'팀명',required:true,value:t.name,hint:'예: 배관 1팀'},
      {name:'code',label:'팀 코드',required:true,value:t.code,hint:'예: 703K-PIPE-01'},
      {name:'trade',label:'공종',required:true,value:t.trade,hint:'예: 배관 / 전기 / 덕트 / 건축'},
      {name:'foremanId',label:'담당 반장',type:'select',value:t.foremanId,options:options(candidates),hint:(!t.foremanId && t.foreman ? '기존 기록: ' + t.foreman + '. 직원 명단에서 연결하세요. ' : '') + '명단에 없으면 먼저 직원 등록·관리에서 등록하세요.'},
      {name:'planned',label:'계획 인원',type:'number',required:true,value:t.planned || 0},
      {name:'status',label:'상태',type:'select',required:true,value:t.status || 'active',options:[{value:'active',label:'활성'},{value:'inactive',label:'비활성'}]}
    ],onSave:function(v){v.companyId=companyId;v.siteId=siteId;return save('api_saveCrewTeam',v,id);}});
  }
  function assign(id) {
    var e=data.employees.find(function(e){return same(e.id,id);});
    if(!e)return;
    ui().formModal({title:'작업자 팀 배치',subtitle:e.name,fields:[
      {name:'teamId',label:'소속 팀',type:'select',value:e.teamId,options:options(teams().filter(function(t){return t.status==='active';})),hint:'선택을 비우면 미배치로 돌립니다.'}
    ],onSave:function(v){v.employeeId=id;return save('api_assignCrewEmployee',v);}});
  }
  global.AdminCrew={render:function(){paint('불러오는 중…');reload().catch(function(e){paint(ui().esc(e.message));});return '';},company:company,team:team,assign:assign,navigate:navigate,chooseTeam:function(id){selectedTeam=id || '';draw();}};
})(window);
