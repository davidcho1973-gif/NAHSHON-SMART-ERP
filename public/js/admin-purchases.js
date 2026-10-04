(function (global) {
  'use strict';
  const P = () => global.PurchaseRequests, A = () => global.AdminUI;
  const state = {rows:[],sites:[],status:'',site:'',reasons:{},loading:false,operationKeys:new Map()};
  const actionLabels = {review:'검토 시작',needs_info:'정보 요청',hold:'보류',out_of_stock:'품절',order:'구매완료',eta:'배송일',receive:'입고 연결',cancel:'취소',resolve:'품목·수량 확정',contact:'업체 연락 기록'};
  const title = r => (r.lines?.[0]?.name || '구매 요청') + (r.lines?.length > 1 ? ' 외 '+(r.lines.length-1)+'종' : '');
  const money = r => r.amount == null ? '—' : Number(r.amount).toLocaleString(undefined,{style:'currency',currency:r.currency || 'USD'});
  const button = (label,fn) => A().rowButton(label,fn);
  const reauthenticationHtml = () => A().notice('구매처리를 계속하려면 이메일·비밀번호 또는 Google로 다시 로그인하세요.','warn')+button('ERP 로그인','PurchaseRequests.reauthenticate()');
  function errorMessage(error) {
    if(error.code==='purchase_reauthentication_required'&&!state.reauthenticationShown){
      state.reauthenticationShown=true;
      A().modal({title:'다시 로그인',body:reauthenticationHtml(),actions:[{label:'닫기',value:null}]});
    }
    return error.message;
  }
  const fail = e => A().toast(errorMessage(e),'error');
  function paint(html) { const host=document.getElementById('page-container'); if(host)host.innerHTML=html; }
  async function load() {
    if(state.loading)return; state.loading=true;
    try {
      const res=await P().api('?desk=1'+(state.site?'&site_id='+encodeURIComponent(state.site):'')+(state.status?'&status='+encodeURIComponent(state.status):''));
      state.rows=res.rows || [];state.sites=res.sites || [];state.reasons=res.reasons || {};state.canRequest=res.can_request;
      const e=P().esc;
      paint(A().pageHeader('구매 요청','',button('새로고침','AdminPurchases.reload()')+(res.can_request?button('내 구매신청','location.href="/attendance-app/purchase-requests"'):''))+
        '<div class="pr-toolbar"><select id="pr-site" aria-label="현장"><option value="">전체 현장</option>'+state.sites.map(s=>'<option value="'+s.id+'" '+(String(s.id)===state.site?'selected':'')+'>'+e(s.name || s.code)+'</option>').join('')+'</select><select id="pr-status" aria-label="상태"><option value="">전체 상태</option>'+Object.entries(res.statuses || P().labels).map(([v,label])=>'<option value="'+e(v)+'" '+(v===state.status?'selected':'')+'>'+e(label)+'</option>').join('')+'</select></div>'+
        A().table({id:'purchase-desk-table',searchPlaceholder:'품목 · 요청자 검색',emptyText:'구매 요청이 없습니다.',rows:state.rows,columns:[
          {key:'item',label:'품목 · 요청자',render:r=>'<button type="button" style="border:0;background:none;color:inherit;text-align:left;cursor:pointer;font:inherit" onclick="AdminPurchases.open('+r.id+')"><strong>'+e(title(r))+'</strong><div style="font-size:12px;opacity:.7;margin-top:5px">'+e(r.requester_name)+' · '+e(r.site_name)+'</div></button>'},
          {key:'quantity',label:'수량',render:r=>r.lines?.length===1?e(r.lines[0].quantity == null ? '수량 확인 필요' : r.lines[0].quantity)+' '+e(r.lines[0].unit || ''):e(r.lines?.length || 0)+'품목'},
          {key:'need_by',label:'필요일'},
          {key:'amount',label:'금액',render:r=>e(money(r))},
          {key:'status',label:'상태',render:r=>A().badge(r.status_label || P().labels[r.status],['ordered','received'].includes(r.status)?'ok':['needs_info','on_hold','out_of_stock'].includes(r.status)?'warn':'muted')+(r.eta?'<div style="font-size:11px;margin-top:4px">도착 '+e(r.eta)+'</div>':'')},
          {key:'next',label:'다음 할 일',render:r=>e(['received','cancelled'].includes(r.status)?'완료':r.lines?.some(l=>l.quantity==null || !l.unit)?'품목·수량 확정':r.status==='needs_info'?'현장 답변 확인':['ordered','partially_ordered','partial'].includes(r.status)?'배송·입고 확인':'업체·제품 확인')},
          {key:'action',label:'',render:r=>button('처리','AdminPurchases.open('+r.id+')')}
        ]}));
      A().bindSearch('purchase-desk-table');
      document.getElementById('pr-site').onchange=e=>{state.site=e.target.value;load();};
      document.getElementById('pr-status').onchange=e=>{state.status=e.target.value;load();};
    }catch(e){paint(e.code==='purchase_reauthentication_required'?A().pageHeader('구매 요청','', '')+reauthenticationHtml():A().notice(e.message,'danger'));}finally{state.loading=false;}
  }
  async function open(id) {
    try {
      const {request:r}=await P().api('/'+id);
      const controls=(r.actions || []).filter(a=>actionLabels[a]).map(a=>button(actionLabels[a],'AdminPurchases.action('+r.id+','+JSON.stringify(a)+')')).join(' ');
      const m=A().modal({width:1120,title:title(r),subtitle:(r.status_label || P().labels[r.status])+' · '+(r.requester_name || ''),body:'<div class="pr-workspace"><section class="pr-detail">'+P().details(r)+'</section><section class="pr-processing"><h3>구매 처리</h3>'+workspace(r)+'<div class="pr-actions">'+controls+'</div></section></div>'});
      state.detail=m;state.current=r;bindWorkspace(m,r);
    }catch(e){fail(e);}
  }
  async function apply(r,action,data) {
    const signature=JSON.stringify({id:r.id,version:r.version,action,data});
    if(!state.operationKeys.has(signature))state.operationKeys.set(signature,P().uuid());
    const response=await P().api('/'+r.id+'/action','POST',{action,version:r.version,request_key:state.operationKeys.get(signature),...data});
    state.operationKeys.delete(signature);
    A().toast(response.changed===false?'변경사항이 없습니다.':'저장했습니다.');
    await load();return response;
  }
  async function chooseReason(r,action) {
    let choice=''; const e=P().esc;let reasons=state.reasons[action] || {};
    if(action==='cancel'&&(r.orders || []).length)reasons={supplier_cancelled:reasons.supplier_cancelled || '주문 취소 확인'};
    const m=A().modal({title:actionLabels[action],subtitle:title(r),body:'<div class="pr-choice">'+Object.entries(reasons).map(([key,label])=>'<button type="button" data-reason="'+e(key)+'" aria-pressed="false" style="padding:11px;border:1px solid var(--border-default);border-radius:8px;cursor:pointer">'+e(label)+'</button>').join('')+'</div><details><summary>자동 안내</summary><p>선택한 내용이 요청자에게 표시됩니다.</p></details><p class="pr-error" role="alert"></p>',actions:[{label:'닫기',value:null},{label:'적용',value:'keep',kind:'primary'}],onReady:el=>{el.querySelectorAll('[data-reason]').forEach(b=>b.onclick=()=>{choice=b.dataset.reason;el.querySelectorAll('[data-reason]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));});},onAction:async(_,el)=>{
      const error=el.querySelector('.pr-error');if(!choice){error.textContent='사유를 선택하세요.';return;}
      const save=el.querySelector('[data-m="1"]');save.disabled=true;
      try{await apply(r,action,{reason:choice});m.close();}catch(err){error.textContent=errorMessage(err);}finally{save.disabled=false;}
    }});
  }
  async function order(r) {
    let file=null, draft={};
    const m=A().modal({title:'주문 확인',subtitle:title(r),body:'<div class="pr-intake"><label>주문서 · 영수증<input id="pr-order-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx"></label><div class="pr-actions"><button type="button" id="pr-read-order">서류 읽기</button></div><p id="pr-order-feedback" role="status"></p></div>',actions:[{label:'닫기',value:null},{label:'주문정보 확인',value:'keep',kind:'primary'}],onReady:el=>{
      el.querySelector('#pr-order-file').onchange=ev=>{file=ev.target.files[0] || null;draft={};};
      el.querySelector('#pr-read-order').onclick=async ev=>{
        const out=el.querySelector('#pr-order-feedback');if(!file){out.textContent='파일을 선택하세요.';return;}ev.target.disabled=true;
        try{const fd=new FormData();fd.append('file',file);fd.append('mode','order');fd.append('site_id',r.site_id);draft=await P().analyze(fd,msg=>out.textContent=msg);out.textContent='읽었습니다. 주문정보를 확인하세요.';}catch(err){out.textContent=errorMessage(err);}finally{ev.target.disabled=false;}
      };
    },onAction:async(_,el)=>{
      const next=el.querySelector('[data-m="1"]');next.disabled=true;
      try{
        if(file&&!draft.analysis_job_id)await P().upload(r.id,file,'order');
        const fresh=(await P().api('/'+r.id)).request;
        if((fresh.orders || []).length&&!file&&!draft.analysis_job_id)throw new Error('이번 주문의 주문서 또는 영수증을 새로 첨부하세요.');
        if(!draft.analysis_job_id&&!(fresh.attachments || []).some(a=>a.purpose==='order'))throw new Error('주문서 또는 영수증을 첨부하세요.');
        m.close();
        const outstanding=(fresh.lines || []).filter(l=>Number(l.remaining_to_order ?? (Number(l.quantity)-Number(l.ordered_quantity || 0)))>0);
        const orderFields=outstanding.map(l=>{
          const remain=Number(l.remaining_to_order ?? (Number(l.quantity)-Number(l.ordered_quantity || 0)));
          const matches=(draft.lines || []).filter(x=>(x.name || '').trim().toLowerCase()===(l.name || '').trim().toLowerCase());
          return {name:'order_qty_'+l.id,label:l.name+' · 잔량 '+remain+' '+l.unit,type:'number',required:true,group:'이번 주문 수량',value:draft.analysis_job_id?(matches.length===1?matches[0].quantity:''):remain,hint:'주문하지 않은 품목은 0'};
        });
        A().formModal({title:'구매완료',subtitle:title(r),saveLabel:'구매완료 · 자동안내',fields:[
          {name:'vendor',label:'구매처',required:true,value:draft.vendor || fresh.vendor || ''},
          {name:'order_number',label:'주문번호',required:true,value:draft.order_number || draft.po_no || ''},
          {name:'amount',label:'이번 주문금액',type:'number',value:draft.amount ?? ''},
          {name:'currency',label:'통화',type:'select',required:true,value:draft.currency || fresh.currency || 'USD',options:['USD','KRW','EUR','CAD']},
          {name:'eta',label:'도착 예정일',type:'date',value:draft.eta || fresh.eta || '',hint:'미정이면 비워두세요.'}
        ].concat(orderFields),onSave:async v=>{try{
          const quantityErrors={};outstanding.forEach(l=>{const qty=Number(v['order_qty_'+l.id]);const remain=Number(l.remaining_to_order ?? (Number(l.quantity)-Number(l.ordered_quantity || 0)));if(!Number.isFinite(qty)||qty<0||qty>remain)quantityErrors['order_qty_'+l.id]='0 이상 잔량 이하로 입력하세요.';});
          if(Object.keys(quantityErrors).length)return {success:false,errors:quantityErrors};
          const order_lines=outstanding.map(l=>({request_line_id:l.id,quantity:Number(v['order_qty_'+l.id] || 0)})).filter(l=>l.quantity>0);
          if(!order_lines.length)return {success:false,error:'이번에 주문한 품목의 수량을 입력하세요.'};
          await apply(fresh,'order',{vendor:v.vendor,order_number:v.order_number,currency:v.currency,order_lines,analysis_job_id:draft.analysis_job_id || null,amount:v.amount===''?null:Number(v.amount),eta:v.eta || null});return {success:true};
        }catch(err){return {success:false,error:errorMessage(err)};}}});
      }catch(err){el.querySelector('#pr-order-feedback').textContent=errorMessage(err);}finally{next.disabled=false;}
    }});
  }
  async function receive(r) {
    const data=await P().api('/'+r.id+'/receipts'); const receipts=data.receipts || [],e=P().esc;
    if(!receipts.length){A().toast('확정된 입고 기록이 없습니다. 자재 입고에서 먼저 실제 수량을 확인하세요.','error');return;}
    let receipt=receipts[0];
    const allocHtml=()=>'<p style="font-size:12px">실제 입고된 수량만 연결하세요.</p>'+(r.lines || []).map(l=>'<div class="pr-receive" data-request-line="'+l.id+'"><strong>'+e(l.name)+' · 요청 '+e(l.quantity)+' '+e(l.unit)+'</strong><select aria-label="입고 품목"><option value="">연결하지 않음</option>'+receipt.lines.map(x=>'<option value="'+x.id+'">'+e(x.name)+' · 잔여 '+e(x.available_quantity)+' '+e(x.unit)+'</option>').join('')+'</select><input type="number" aria-label="연결 수량" min="0" step="any" placeholder="수량"></div>').join('');
    const m=A().modal({title:'입고 연결',subtitle:title(r),body:'<div class="pr-toolbar"><select id="pr-receipt" aria-label="입고 기록">'+receipts.map(x=>'<option value="'+x.id+'">'+e(x.received_on)+' · '+e(x.vendor || '')+' #'+x.id+'</option>').join('')+'</select></div><div id="pr-allocation">'+allocHtml()+'</div><p class="pr-error" role="alert"></p>',actions:[{label:'닫기',value:null},{label:'입고 연결',value:'keep',kind:'primary'}],onReady:el=>{el.querySelector('#pr-receipt').onchange=ev=>{receipt=receipts.find(x=>String(x.id)===ev.target.value);el.querySelector('#pr-allocation').innerHTML=allocHtml();};},onAction:async(_,el)=>{
      const allocations=Array.from(el.querySelectorAll('[data-request-line]')).filter(row=>row.querySelector('select').value).map(row=>({request_line_id:Number(row.dataset.requestLine),receipt_line_id:Number(row.querySelector('select').value),quantity:Number(row.querySelector('input').value)}));
      const save=el.querySelector('[data-m="1"]');save.disabled=true;
      try{if(!allocations.length || allocations.some(x=>x.quantity<=0))throw new Error('품목과 실제 수량을 선택하세요.');await apply(r,'receive',{receipt_id:receipt.id,allocations});m.close();}catch(err){el.querySelector('.pr-error').textContent=errorMessage(err);}finally{save.disabled=false;}
    }});
  }
  async function action(id,kind) {
    try{
      const r=(await P().api('/'+id)).request;
      if(!(r.actions || []).includes(kind))throw new Error('현재 상태에서 처리할 수 없습니다. 새로고침해 주세요.');
      if(state.detail){state.detail.close();state.detail=null;}
      if(['hold','out_of_stock','needs_info','cancel'].includes(kind))return chooseReason(r,kind);
      if(kind==='resolve')return resolve(r);
      if(kind==='contact')return A().formModal({title:'업체 연락 결과',fields:[{name:'note',label:'업체 · 가격 · 공급 가능 여부 · 다음 연락',type:'textarea',required:true}],onSave:async v=>{try{await apply(r,'contact',{note:v.note});return {success:true};}catch(err){return {success:false,error:errorMessage(err)};}}});
      if(kind==='order')return order(r);
      if(kind==='receive')return receive(r);
      if(kind==='eta')return A().formModal({title:'배송일',saveLabel:'적용 · 자동안내',fields:[{name:'eta',label:'도착 예정일',type:'date',value:r.eta || '',hint:'미정이면 비워두세요.'}],onSave:async v=>{try{await apply(r,kind,{eta:v.eta || null});return {success:true};}catch(err){return {success:false,error:errorMessage(err)};}}});
      await apply(r,kind,{});
    }catch(e){fail(e);}
  }

  function workspace(r) {
    const e=P().esc, query=(r.lines || []).map(l=>[l.name,l.specification].filter(Boolean).join(' ')).join(' ');
    return '<label>제품·업체 검색<input id="pr-desk-query" value="'+e(query)+'"></label><div class="pr-actions"><button type="button" id="pr-desk-search">제품 후보 찾기</button><a target="_blank" rel="noopener" href="https://www.google.com/search?q='+encodeURIComponent(query)+'">웹 검색 ↗</a><a target="_blank" rel="noopener" href="https://www.google.com/maps/search/'+encodeURIComponent(query+' '+r.site_name)+'">주변 업체 검색 ↗</a></div><p id="pr-desk-feedback" role="status"></p><div id="pr-desk-results"></div><h4>등록 거래 업체 · 공급 가능 여부 미확인</h4>'+(r.context?.vendors || []).map(v=>'<div class="pr-vendor"><strong>'+e(v.name)+'</strong><small>'+e(v.trade || '')+' '+e(v.address || '')+'</small><div class="pr-actions">'+(v.phone?'<a href="tel:'+e(v.phone.replace(/[^+0-9]/g,''))+'">전화</a>':'')+(v.email?'<button type="button" data-vendor-email="'+e(v.email)+'">이메일</button>':'')+'</div></div>').join('')+'<button type="button" id="pr-desk-email">문의 이메일 작성</button><p class="pr-help">구매는 판매처에서 진행하고 주문서·영수증을 구매완료에 연결하세요. 검색 결과는 납품 가능 여부를 보장하지 않습니다.</p>';
  }
  function bindWorkspace(m,r) {
    const host=document.getElementById('pr-desk-query')?.closest('.pr-processing');if(!host)return;
    host.querySelector('#pr-desk-email').onclick=()=>email(r,'');
    host.querySelectorAll('[data-vendor-email]').forEach(b=>b.onclick=()=>email(r,b.dataset.vendorEmail));
    host.querySelector('#pr-desk-query').oninput=ev=>{const links=host.querySelectorAll('.pr-actions a');if(links[0])links[0].href='https://www.google.com/search?q='+encodeURIComponent(ev.target.value);if(links[1])links[1].href='https://www.google.com/maps/search/'+encodeURIComponent(ev.target.value+' '+r.site_name);};
    host.querySelector('#pr-desk-search').onclick=async ev=>{const out=host.querySelector('#pr-desk-feedback');ev.target.disabled=true;try{const fd=new FormData();fd.append('mode','search');fd.append('site_id',r.site_id);fd.append('text',host.querySelector('#pr-desk-query').value);const result=await P().analyze(fd,msg=>out.textContent=msg);host.querySelector('#pr-desk-results').innerHTML=(result.candidates || []).map(c=>'<div class="pr-vendor"><strong>'+P().esc(c.product || c.name)+'</strong><p>'+P().esc(c.why || '')+'</p>'+(P().safeUrl(c.url)?'<a target="_blank" rel="noopener" href="'+P().esc(P().safeUrl(c.url))+'">판매처에서 확인·구매 ↗</a>':'')+'</div>').join('');out.textContent='규격·구성품·가격·배송일을 판매처에서 확인하세요.';}catch(err){out.textContent=errorMessage(err);}finally{ev.target.disabled=false;}};
  }
  function email(r,to) {
    const e=P().esc,key=P().uuid(),body='안녕하세요. 아래 요청의 공급 가능 여부, 견적과 납품 일정을 확인 부탁드립니다.\n\n현장: '+r.site_name+'\n필요일: '+(r.need_by || '확인 필요')+'\n'+(r.lines || []).map(l=>l.name+' / '+(l.quantity ?? '수량 확인 필요')+' '+(l.unit || '')+' / '+(l.specification || '')).join('\n')+'\n\n'+(r.note || '');
    const m=A().modal({title:'업체 문의 이메일',body:'<div class="pr-intake"><label>수신처<input id="pr-mail-to" type="email" value="'+e(to)+'"></label><label>제목<input id="pr-mail-subject" value="'+e('[구매 문의 #'+r.id+'] '+title(r))+'"></label><label>내용<textarea id="pr-mail-body" rows="9">'+e(body)+'</textarea></label><strong>보낼 자료 선택</strong>'+(r.attachments || []).map(a=>'<label><span><input type="checkbox" data-attachment="'+a.id+'" style="width:auto"> '+e(a.name)+'</span></label>').join('')+'<p class="pr-help">선택한 자료만 발송합니다. 합계 15MB까지 가능합니다.</p><p class="pr-error"></p></div>',actions:[{label:'닫기',value:null},{label:'확인 후 발송',value:'keep',kind:'primary'}],onAction:async(_,el)=>{const btn=el.querySelector('[data-m="1"]');btn.disabled=true;try{const result=await P().api('/'+r.id+'/email','POST',{to:el.querySelector('#pr-mail-to').value,subject:el.querySelector('#pr-mail-subject').value,body:el.querySelector('#pr-mail-body').value,attachment_ids:Array.from(el.querySelectorAll('[data-attachment]:checked')).map(x=>Number(x.dataset.attachment)),request_key:key});A().toast(result.delivery==='sent'?'전송 완료':'전송 상태는 처리 이력에서 확인하세요.');m.close();}catch(err){el.querySelector('.pr-error').textContent=errorMessage(err);}finally{btn.disabled=false;}}});
  }
  function resolve(r) {
    const fields=(r.lines || []).flatMap(l=>[{name:'name_'+l.id,label:'품목명',value:l.name,required:true,group:l.name},{name:'quantity_'+l.id,label:'확정 수량',type:'number',value:l.quantity ?? '',required:true},{name:'unit_'+l.id,label:'단위',value:l.unit || '',required:true},{name:'spec_'+l.id,label:'규격·용도',value:l.specification || ''}]);
    A().formModal({title:'구매 조건 확정',fields,onSave:async v=>{try{await apply(r,'resolve',{lines:r.lines.map(l=>({name:v['name_'+l.id],quantity:Number(v['quantity_'+l.id]),unit:v['unit_'+l.id],specification:v['spec_'+l.id],product_url:l.product_url || ''}))});return {success:true};}catch(err){return {success:false,error:errorMessage(err)};}}});
  }

  global.AdminPurchases={render:()=>{paint('<p role="status">불러오는 중…</p>');load();return '';},reload:load,open,action};
})(window);
