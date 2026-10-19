/** One evidence original, shared by the claim inbox and every drawing of its work section. */
(function (global) {
  'use strict';
  var currentUpload = null;
  function safeUrl(value) {
    if (typeof value !== 'string' || !value.trim()) return '';
    try { var u = new URL(value, global.location.href); return u.origin === global.location.origin && /^https?:$/.test(u.protocol) ? u.href : ''; }
    catch (_) { return ''; }
  }
  function upload(siteId, contractId) {
    var ui = global.AdminUI;
    var url = '/document-hub?embed=1&evidence=1&site_id=' + Number(siteId) + (contractId ? '&contract_id=' + Number(contractId) : '');
    var modal = ui.modal({ title: '증빙 한 번에 올리기', width: 1200,
      subtitle: '공정이 분명한 자료는 자동 연결 · 애매한 자료는 확인 대기 · 수량과 기성은 별도 확인',
      body: '<iframe title="기성 증빙 일괄 접수" src="' + ui.esc(url) + '" style="width:100%;height:72vh;border:0"></iframe>',
      beforeClose: function (el) {
        var workflow;
        try { workflow = el.querySelector('iframe').contentWindow.documentEvidenceWorkflow; }
        catch (_) { return true; } // An expired session may navigate the frame to a sign-in provider.
        if (workflow && workflow.busy) { ui.toast('파일 전송이 끝날 때까지 기다려 주세요.','error'); return false; }
        return !workflow || !workflow.files.some(function(r){return r.state !== 'stored';}) || global.confirm('아직 저장하지 않은 파일이 있습니다. 닫을까요?');
      },
      actions: [{ label: '닫기', value: null }] });
    currentUpload = modal;
    modal.result.then(function () { if(currentUpload===modal) currentUpload=null; global.apiCache = {}; if (global.AdminSectionDrawings) global.AdminSectionDrawings.reload(); });
  }
  function review(contractId) {
    if(currentUpload) { currentUpload.close(); if(document.body.contains(currentUpload.el)) return; currentUpload=null; }
    global.AdminClaimEvidence.openEvidence(Number(contractId));
  }
  function list(contractId, sectionId, sheetNo, page) {
    var ui = global.AdminUI;
    var filters = {status:'linked', page:page || 1, perPage:25};
    if (sectionId) filters.sectionId = Number(sectionId);
    if (sheetNo) filters.sheetNo = sheetNo;
    var args = [Number(contractId), filters];
    // Queue completion and source access can change without a mutation in this tab.
    if (global.apiCache) delete global.apiCache['api_getClaimEvidenceInbox' + JSON.stringify(args)];
    return global.gsRun('api_getClaimEvidenceInbox', args, null).then(function (data) {
      if (!data || data.success === false) throw new Error(data && data.error || '증빙을 불러오지 못했습니다.');
      var modal = ui.modal({ title:'공정 · 도면 연결 증빙', width:900,
        subtitle:'도면 위치는 지정하지 않은 자료입니다. 증빙 연결은 시공 완료나 기성 금액 확정이 아닙니다.',
        body: ui.table({id:'drawing-evidence',rows:data.rows || [],search:false,emptyText:'연결된 증빙이 없습니다. 애매한 자료는 확인 대기에서 연결하세요.',columns:[
          {key:'title',label:'원본',render:function(r){var url=safeUrl(r.url);return ui.esc(r.title)+(url?' <a href="'+ui.esc(url)+'" target="_blank" rel="noopener">원본 열기 ↗</a>':'');}},
          {key:'lineLabels',label:'공정',render:function(r){return ui.esc((r.lineLabels || []).join(' · '));}},
          {key:'reason',label:'연결 근거',render:function(r){return ui.esc((r.status==='review'?'다시 확인 필요 · ':'')+(r.reason || ''));}}
        ]}),
        actions:[{label:'닫기',value:null},{label:'확인 대기 / 연결 수정',value:'review'}]
          .concat(data.pagination.page>1?[{label:'이전',value:'prev'}]:[])
          .concat(data.pagination.page<data.pagination.lastPage?[{label:'다음',value:'next'}]:[]) });
      return modal.result.then(function(action){
        if(action==='review') review(contractId);
        if(action==='prev'||action==='next') return list(contractId,sectionId,sheetNo,data.pagination.page+(action==='next'?1:-1));
      });
    }).catch(function(e){ui.toast(e.message,'error');});
  }
  function sheetHtml(groups) {
    var ui=global.AdminUI;
    return (groups || []).map(function(g){
      var rows=g.rows || [];
      return '<section style="border-top:1px solid #334155;margin-top:16px;padding-top:12px"><strong style="color:#cbd5e1;font-size:12px">공정 연결 증빙 '+Number(g.pagination.total)+'건</strong>'+
        '<p style="font-size:11px;color:#94a3b8">공정 기준 연결 · 도면 위치 미지정 · 기성 확정 아님</p>'+rows.map(function(r){
          var url=safeUrl(r.url);
          return '<div style="padding:8px 0;font-size:12px;color:#cbd5e1">'+(url?'<a style="color:#38bdf8" href="'+ui.esc(url)+'" target="_blank" rel="noopener">'+ui.esc(r.title)+'</a>':ui.esc(r.title))+
            '<div style="font-size:11px">'+ui.esc((r.lineLabels || []).join(' · '))+'</div>'+(r.status==='review'?'<span style="color:#fbbf24">다시 확인 필요</span>':'')+'</div>';
        }).join('')+'<button type="button" data-evidence-contract="'+Number(g.contractId)+'">전체 증빙 / 확인 대기</button></section>';
    }).join('');
  }
  global.AdminDrawingEvidence={upload:upload,review:review,list:list,sheetHtml:sheetHtml,_safeUrl:safeUrl};
})(window);
