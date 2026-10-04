/* Shared request data, not permissions: every endpoint authorizes again on the server. */
(function (global) {
  'use strict';
  const base = '/purchase-requests';
  const labels = {submitted:'요청됨',needs_info:'정보 필요',reviewing:'검토 중',on_hold:'보류',out_of_stock:'품절',partially_ordered:'일부 구매',ordered:'구매완료',supplier_confirmed:'업체 납품 확정',partial:'일부 입고',received:'입고완료',cancelled:'취소'};
  const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function errorText(data) { return Object.values(data.errors || {}).flat().join(' ') || data.error || data.message || '처리하지 못했습니다. 다시 시도해 주세요.'; }
  async function api(path, method, body) {
    const options = {method:method || 'GET',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''}};
    if (body instanceof FormData) options.body = body;
    else if (body !== undefined) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(body); }
    const response = await fetch(base + path, options);
    const data = await response.json().catch(() => ({error:'서버 응답을 확인하지 못했습니다. 새로고침해 주세요.'}));
    if (!response.ok || data.success === false) {
      const error = new Error(errorText(data));
      error.code = data.code || null;
      throw error;
    }
    return data;
  }
  function reauthenticate() {
    global.location.href = '/login?erp=1';
  }
  function safeUrl(value) {
    if (typeof value !== 'string' || !value.trim()) return '';
    try { const u = new URL(value, location.origin); return ['http:','https:'].includes(u.protocol) ? u.href : ''; } catch (_) { return ''; }
  }
  function attachment(a) {
    const url = safeUrl(a.url || a.download_url || '');
    return url ? '<a href="'+esc(url)+'" target="_blank" rel="noopener">'+esc(a.name || a.original_name || '첨부파일')+'</a>' : esc(a.name || '첨부파일');
  }
  function linesHtml(lines) {
    return (lines || []).map(l => '<div class="pr-line"><strong>'+esc(l.name)+'</strong><span>'+esc(l.quantity == null ? '수량 확인 필요' : l.quantity)+' '+esc(l.unit || '단위 확인 필요')+'</span>'+
      (l.specification ? '<small>'+esc(l.specification)+'</small>' : '')+
      (l.ordered_quantity ? '<small>주문 '+esc(l.ordered_quantity)+' '+esc(l.unit)+'</small>' : '')+
      (l.received_quantity ? '<small>입고 '+esc(l.received_quantity)+' '+esc(l.unit)+'</small>' : '')+
      (safeUrl(l.product_url) ? '<a href="'+esc(safeUrl(l.product_url))+'" target="_blank" rel="noopener">제품 보기 ↗</a>' : '')+'</div>').join('');
  }
  function details(r) {
    const events = r.events || [], files = r.attachments || [],orders=r.orders || [];
    return '<div class="pr-summary"><span>'+esc(r.site_name)+'</span><span>필요일 '+esc(r.need_by || '미정')+'</span><span>도착 '+esc(r.eta || '미정')+'</span></div>'+linesHtml(r.lines)+
      (r.note ? '<p class="pr-note">'+esc(r.note)+'</p>' : '')+
      (orders.length ? '<details><summary>주문정보 '+orders.length+'건</summary>'+orders.map(o=>'<div class="pr-event"><strong>'+esc(o.vendor)+' · '+esc(o.order_number)+'</strong>'+((o.amount!=null)?'<p>'+esc(o.currency)+' '+esc(Number(o.amount).toLocaleString())+'</p>':'')+(o.lines || []).map(l=>{const source=(r.lines || []).find(x=>x.id===l.request_line_id);return '<p>'+esc(source?.name || '품목')+' · '+esc(l.quantity)+' '+esc(source?.unit || '')+'</p>';}).join('')+'</div>').join('')+'</details>' : '')+
      (r.context ? '<details><summary>기존 자재 · 요청 조회</summary><p class="pr-event">'+esc(r.context.match_basis)+'</p>'+
        (r.context.items || []).map(i=>'<p class="pr-event">품목 대장 · '+esc(i.name)+' · '+esc(i.unit)+'</p>').join('')+
        (r.context.stock_records || []).map(i=>'<p class="pr-event">재고 대장 · '+esc(i.name)+' · '+esc(i.quantity)+' · '+esc(i.status)+'</p>').join('')+
        (r.context.related_requests || []).map(i=>'<p class="pr-event">기존 요청 #'+esc(i.id)+' · '+esc(i.title)+' · '+esc(i.site_name)+' · '+esc(i.need_by || '필요일 미정')+'</p>').join('')+'</details>' : '')+
      (files.length ? '<details><summary>첨부파일 '+files.length+'개</summary><div class="pr-files">'+files.map(attachment).join('')+'</div></details>' : '')+
      (events.length ? '<details><summary>처리 이력</summary>'+events.map(e => '<p class="pr-event">'+esc(e.message || e.note || e.status_label || labels[e.status] || '')+'<small>'+esc(e.created_at || '')+'</small></p>').join('')+'</details>' : '');
  }
  async function upload(id, file, purpose) {
    const fd = new FormData(); fd.append('file',file); fd.append('purpose',purpose || 'request');
    return api('/'+id+'/attachments','POST',fd);
  }
  const pause = ms => new Promise(resolve => setTimeout(resolve,ms));
  async function analyze(data, progress) {
    if (!data.has('request_key')) data.append('request_key',crypto.randomUUID());
    const first = await api('/analyze','POST',data);
    if (first.result) return first.result;
    const id = first.job_id || first.job?.id;
    if (!id) throw new Error('분석 작업 번호를 받지 못했습니다.');
    for (let n=0;n<120;n++) {
      if (progress) progress('분석 중…');
      await pause(2500);
      const result = await api('/analysis/'+id);
      if (['failed','error'].includes(result.status)) throw new Error(result.error || '분석하지 못했습니다. 직접 입력하거나 다시 시도해 주세요.');
      if (result.result && (result.done || ['completed','complete','done','succeeded'].includes(result.status))) return {...result.result,analysis_job_id:id};
    }
    throw new Error('분석이 계속되고 있습니다. 잠시 후 다시 확인해 주세요. 작업번호 '+id);
  }
  global.PurchaseRequests = {api,esc,safeUrl,attachment,labels,details,linesHtml,upload,analyze,reauthenticate,uuid:()=>crypto.randomUUID()};
})(window);
