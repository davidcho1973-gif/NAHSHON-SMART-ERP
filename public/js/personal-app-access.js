(function(global){
  'use strict';
  const state={users:[],host:null,loading:false,issuing:false,qrTimer:null,modal:null};
  const ui=()=>global.AdminUI,esc=value=>ui().esc(value);
  const date=value=>{const d=new Date(value);return value&&!Number.isNaN(d.valueOf())?d.toLocaleString():'—';};
  function connectionUrl(value){
    try{const u=new URL(value,location.origin);return u.origin===location.origin&&/^\/app\/connect\/[A-Za-z0-9_-]+$/.test(u.pathname)&&!u.search&&!u.hash?u.href:null;}catch(_){return null;}
  }
  async function api(path,body){
    const headers={Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''};
    const options={method:body===undefined?'GET':'POST',credentials:'same-origin',headers};
    if(body!==undefined){headers['Content-Type']='application/json';options.body=JSON.stringify(body);}
    const response=await fetch('/personal-app-access'+path,options);
    const data=await response.json().catch(()=>({error:'응답을 확인하지 못했습니다. 새로고침해 주세요.'}));
    if(!response.ok||data.success===false){const error=new Error(data.error||data.message||'처리하지 못했습니다.');error.code=data.code;throw error;}
    return data;
  }
  function loginAgain(){
    global.location.href='/login?erp=1';
  }
  function paintError(error){
    if(!state.host)return;
    state.host.innerHTML=ui().pageHeader('개인앱 연결 QR','', '')+ui().notice(error.message,'danger')+
      (error.code==='personal_app_reauthentication_required'?ui().primaryButton('ERP 로그인','PersonalAppAccess.loginAgain()'):'');
  }
  function userCards(){
    const target=state.host?.querySelector('#pa-users');if(!target)return;
    const query=(state.host.querySelector('#pa-search')?.value||'').trim().toLowerCase();
    const users=state.users.filter(user=>[user.name,user.email,user.role_label,user.scope_label].join(' ').toLowerCase().includes(query));
    target.innerHTML=users.length?users.map(user=>{
      const devices=user.devices||[],active=devices.filter(d=>!d.revoked_at&&new Date(d.expires_at)>new Date());
      return '<article class="pa-user"><div class="pa-user-top"><div><h3>'+esc(user.name)+'</h3><p class="pa-muted">'+esc(user.role_label||user.role)+' · '+esc(user.scope_label||'')+'</p></div>'+ui().primaryButton('QR 발급','PersonalAppAccess.issue('+Number(user.id)+')','qr-code')+'</div>'+
        '<div class="pa-badges">'+(user.purchase_request_enabled||user.role==='super_admin'?ui().badge('구매신청','ok'):'')+(user.purchase_buy_enabled||user.role==='super_admin'?ui().badge('ERP 구매처리','warn'):'')+'</div>'+
        '<details><summary>연결된 휴대폰 '+active.length+'대</summary>'+(devices.length?devices.map(device=>'<div class="pa-device"><div>'+esc(device.label||'휴대폰')+'<div class="pa-muted">'+(device.revoked_at?'연결 해제됨':new Date(device.expires_at)<=new Date()?'만료됨':'최근 사용 '+date(device.last_used_at))+'</div></div>'+(!device.revoked_at&&new Date(device.expires_at)>new Date()?ui().rowButton('연결 해제','PersonalAppAccess.revoke('+Number(device.id)+')','danger'):'')+'</div>').join(''):'<p class="pa-muted">연결된 휴대폰이 없습니다.</p>')+'</details></article>';
    }).join(''):'<p class="pa-muted">표시할 관리자가 없습니다.</p>';
  }
  async function reload(){
    if(state.loading)return;state.loading=true;
    try{
      const data=await api('/users');state.users=data.users||[];
      if(!state.host?.isConnected)return;
      state.host.innerHTML=ui().pageHeader('개인앱 연결 QR','관리자를 선택해 본인 휴대폰으로 QR을 찍으세요.',ui().rowButton('새로고침','PersonalAppAccess.reload()'))+
        '<div class="pa-tools"><input class="pa-search" id="pa-search" type="search" placeholder="관리자 이름 검색" aria-label="관리자 이름 검색"><a class="pa-help" href="/help#personal-app-qr">사용 방법</a></div><div id="pa-users" class="pa-list"></div>';
      state.host.querySelector('#pa-search').addEventListener('input',userCards);userCards();
    }catch(error){paintError(error);}finally{state.loading=false;}
  }
  function closeQr(){if(state.qrTimer)clearInterval(state.qrTimer);state.qrTimer=null;state.modal?.close();state.modal=null;}
  async function issue(id){
    if(state.issuing)return;state.issuing=true;
    try{
      const result=await api('/users/'+id+'/qr',{}),url=connectionUrl(result.url);
      if(!url||typeof result.qr_svg!=='string'||!result.qr_svg.includes('<svg'))throw new Error('QR 정보를 확인하지 못했습니다. 다시 발급하세요.');
      closeQr();const expiry=new Date(result.expires_at).valueOf();
      if(!Number.isFinite(expiry)||expiry<=Date.now())throw new Error('QR 유효시간이 지났습니다. 다시 발급하세요.');
      const src='data:image/svg+xml;charset=utf-8,'+encodeURIComponent(result.qr_svg);
      const m=ui().modal({title:(result.name||'관리자')+' · 개인 QR',body:'<div class="pa-qr"><div id="pa-live-qr"><img alt="개인앱 연결 QR" src="'+esc(src)+'"><p class="pa-muted">본인 휴대폰의 기본 카메라로 찍으세요.<br>한 번 연결하면 다음부터는 개인앱을 바로 엽니다.</p></div><p id="pa-qr-time" role="status"></p><div class="pa-qr-actions"><button type="button" class="pa-secondary" id="pa-copy-link">링크 복사</button><button type="button" class="pa-secondary" id="pa-print">인쇄</button></div><p class="pa-muted">1회 사용 · 발급 후 15분 유효</p></div>',actions:[{label:'닫기',value:null}],onReady:el=>{
        const current=()=>el.isConnected&&Date.now()<expiry;
        const copy=el.querySelector('#pa-copy-link');copy.onclick=async()=>{if(!current())return;try{await navigator.clipboard.writeText(url);ui().toast('링크를 복사했습니다.');}catch(_){ui().toast('링크를 복사하지 못했습니다. QR을 직접 찍어 주세요.','error');}};
        el.querySelector('#pa-print').onclick=()=>{
          if(!current())return;
          const sheet=document.createElement('div');sheet.className='pa-print-sheet';sheet.innerHTML='<h1>'+esc(result.name||'관리자')+' · 개인앱 연결</h1><img alt="개인앱 연결 QR" src="'+esc(src)+'"><p>휴대폰 기본 카메라로 QR을 찍으세요.</p><p>1회 사용 · '+esc(date(result.expires_at))+'까지 유효</p>';
          document.body.appendChild(sheet);document.body.classList.add('pa-print-qr');
          try{global.print();}finally{document.body.classList.remove('pa-print-qr');sheet.remove();}
        };
        function tick(){
          if(!el.isConnected){clearInterval(state.qrTimer);state.qrTimer=null;return;}
          const left=Math.max(0,Math.ceil((expiry-Date.now())/1000));
          el.querySelector('#pa-qr-time').textContent=left?'남은 시간 '+Math.floor(left/60)+':'+String(left%60).padStart(2,'0'):'유효시간이 지났습니다. 새 QR을 발급하세요.';
          if(!left){el.querySelector('#pa-live-qr').replaceChildren();el.querySelectorAll('.pa-qr-actions button').forEach(b=>b.disabled=true);clearInterval(state.qrTimer);state.qrTimer=null;}
        }
        tick();state.qrTimer=setInterval(tick,1000);
      }});state.modal=m;m.result.then(()=>{if(state.modal===m){state.modal=null;if(state.qrTimer)clearInterval(state.qrTimer);state.qrTimer=null;}});
    }catch(error){if(error.code==='personal_app_reauthentication_required')paintError(error);else ui().toast(error.message,'error');}finally{state.issuing=false;}
  }
  async function revoke(id){
    const ok=await ui().confirmDanger({title:'이 휴대폰의 연결을 해제할까요?',body:'이 휴대폰에서 다시 사용하려면 새 개인 QR이 필요합니다.',confirmLabel:'연결 해제'});
    if(!ok)return;
    try{await api('/devices/'+id+'/revoke',{});ui().toast('연결을 해제했습니다.');await reload();}catch(error){if(error.code==='personal_app_reauthentication_required')paintError(error);else ui().toast(error.message,'error');}
  }
  function render(){closeQr();const page=document.getElementById('page-container');if(page){page.innerHTML='<div id="personal-app-access-root"><p role="status">불러오는 중…</p></div>';state.host=page.querySelector('#personal-app-access-root');reload();}return '';}
  global.PersonalAppAccess={render,reload,issue,revoke,loginAgain,connectionUrl};
  const mobile=document.getElementById('manager-access-page');if(mobile){state.host=mobile;reload();}
  global.addEventListener('pagehide',closeQr);
})(window);
