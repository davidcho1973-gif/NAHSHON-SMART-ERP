(function (global) {
  'use strict';
  var data;
  var kinds = {clock_in:'출근',clock_out:'퇴근',daily_report:'일일보고'};
  var status = {accepted:'업체 접수 · 도착 미확인',delivered:'업체 발송 완료',sending:'접수 확인 중',failed:'실패',unknown:'결과 확인 필요',blocked:'발송 준비 미완료',skipped:'대상 제외'};
  var reasons = {already_clocked_in:'출근 처리됨',already_clocked_out:'퇴근 처리됨',no_attendance_today:'당일 출근 없음',no_report_slot:'공종·보고 담당 미지정',report_already_submitted:'해당 공종·부서 보고 제출됨',provider_not_ready:'발송 설정 확인 필요',recipient_inactive_or_moved:'수신 중지 또는 소속 변경',interrupted_attempt:'처리 중 중단 · 업체 내역 확인',check_provider_delivery_log:'최종 도착 여부는 업체 내역 확인',transport_or_response_error:'통신 결과 불명 · 자동 재발송 안 함',missing_acknowledgement:'접수 응답 불명 · 업체 내역 확인',provider_rejected:'업체 거절'};
  var prerequisites = {api_key:'API Key',api_secret:'API Secret',channel_id:'비즈니스 채널',https_app_url:'ERP HTTPS 주소',template_clock_in:'출근 승인 템플릿',template_clock_out:'퇴근 승인 템플릿',template_daily_report:'일일보고 승인 템플릿',confirmed_countries:'미국(+1) 알림톡 지원 확인'};
  function paint(html) { var el=document.getElementById('page-container'); if(el) el.innerHTML=html; }
  function load() {
    return global.gsRun('api_getKakaoReminders',[],null).then(function(r){
      if(!r || r.success===false) throw new Error(r && r.error || '조회하지 못했습니다.');
      data=r; draw();
    });
  }
  function draw() {
    var u=global.AdminUI, ready=data.readiness;
    var html=u.pageHeader('카카오 업무 알림 (Kakao)','출퇴근·일일보고 작성 링크를 직원별 현장 시간에 안내합니다.');
    html+='<div style="padding:18px;border:1px solid var(--border-default);border-radius:12px;margin-bottom:20px;line-height:1.8">'+
      '<strong>'+(!ready.enabled?'자동 발송 꺼짐':ready.configured?'자동 발송 켜짐':'발송 준비 미완료')+'</strong><br>'+
      '준비 항목: '+u.esc(ready.missing.map(function(k){return prerequisites[k] || k;}).join(' · ') || '서버 설정 완료')+'<br>'+
      '미국 번호는 +1과 10자리 번호를 입력하세요. 해당 번호로 가입한 카카오톡인지 확인해야 합니다.<br>'+
      '직원별 수신 설정과 서버 발송 설정이 모두 켜져야 발송합니다. 서버 비밀키·템플릿은 Laravel Cloud에서 설정합니다.<br>'+
      '10분 간격으로 확인하며 각 알림은 하루 1회입니다. 휴일에는 수신을 잠시 끄세요. 1시간 이상 지난 알림은 보내지 않습니다.<br>'+
      '링크는 로그인 후 업무 화면을 엽니다. 알림 수신만으로 출퇴근 기록이나 보고서가 생성되지는 않습니다.</div>';
    html+=u.table({id:'kakao-people',emptyText:'등록된 직원이 없습니다.',columns:[
      {key:'name',label:'직원'}, {key:'site',label:'현장'}, {key:'timezone',label:'현장 시간대'},
      {key:'phone',label:'카카오 번호',render:function(e){return u.esc(e.phone?e.phone.slice(0,2)+' •••• '+e.phone.slice(-4):'미등록');}},
      {key:'enabled',label:'설정',render:function(e){return e.siteChanged?'현장 변경 · 재설정 필요':!e.accountActive?'활성 계정 필요':e.enabled?'수신 예약 켜짐':'수신 꺼짐';}},
      {key:'clock_in',label:'출근'}, {key:'clock_out',label:'퇴근'}, {key:'daily_report',label:'보고'},
      {key:'actions',label:'',render:function(e){return u.rowButton('설정','AdminKakao.edit('+e.id+')');}}
    ],rows:data.employees});
    html+='<h3 style="margin-top:26px">최근 발송 처리 100건</h3><p>업체 접수는 도착 완료를 의미하지 않습니다. 결과 불명·실패 건은 자동 재발송하지 않습니다. '+
      '<a href="https://console.solapi.com" target="_blank" rel="noopener">SOLAPI 발송 내역 확인</a></p>';
    html+=u.table({id:'kakao-history',emptyText:'발송 처리 내역이 없습니다.',columns:[
      {key:'date',label:'현장 날짜'},{key:'name',label:'직원'},
      {key:'kind',label:'알림',render:function(r){return u.esc(kinds[r.kind] || r.kind);}},
      {key:'status',label:'결과',render:function(r){return u.esc(status[r.status] || r.status);}},
      {key:'reason',label:'확인 사항',render:function(r){return u.esc(reasons[r.reason] || r.reason || '');}},
      {key:'messageId',label:'업체 메시지 ID'},{key:'providerCode',label:'업체 코드'}
    ],rows:data.deliveries});
    paint(html);
    u.bindSearch('kakao-people'); u.bindSearch('kakao-history');
  }
  function edit(id) {
    var e=data.employees.find(function(x){return x.id===id;});
    global.AdminUI.formModal({title:e.name+' · 카카오 알림',subtitle:'시간은 '+(e.timezone || '현장 시간대 미설정')+' 기준입니다. 빈 시간은 발송하지 않습니다. 일일보고 제출 여부는 공종·부서 단위로 판단합니다.',fields:[
      {name:'phone',label:'카카오톡 가입 번호',type:'tel',required:true,value:e.phone,hint:'미국 예: +14805550123 (공백·하이픈 없이)'},
      {name:'weekdaysText',label:'발송 요일',required:true,value:e.weekdays.join(','),hint:'월=1, 화=2, 수=3, 목=4, 금=5, 토=6, 일=7. 예: 1,2,3,4,5'},
      {name:'clock_in',label:'출근 알림',type:'time',value:e.clock_in || ''},
      {name:'clock_out',label:'퇴근 알림',type:'time',value:e.clock_out || ''},
      {name:'daily_report',label:'일일보고 알림',type:'time',value:e.daily_report || ''},
      {name:'consented',label:'수신 확인',type:'checkbox',value:false,checkboxLabel:'본인 번호 및 업무 알림 수신 의사를 확인했습니다.'},
      {name:'enabled',label:'예약 사용',type:'checkbox',value:e.enabled,checkboxLabel:'이 직원의 알림 예약 사용'}
    ],onSave:function(v){
      v.employeeId=id; v.weekdays=v.weekdaysText.split(',').map(function(s){return Number(s.trim());}); delete v.weekdaysText;
      return global.gsRun('api_saveKakaoReminder',[v],null).then(function(r){if(r.success!==false){global.AdminUI.toast('저장했습니다.');return load().then(function(){return r;});}return r;});
    }});
  }
  global.AdminKakao={render:function(){paint('불러오는 중…');load().catch(function(e){paint(global.AdminUI.esc(e.message));});return '';},edit:edit};
})(window);
