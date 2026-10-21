/** BOQ detail owns its context; originals and work remain in the existing ledgers. */
(function (w) {
  'use strict';
  var context, data, generation = 0, page = 1;
  function ui() { return w.AdminUI; }
  function sameLine(c) { return context && context.contractId === c.contractId && context.line.id === c.line.id; }
  function esc(v) { return ui().esc(String(v == null ? '' : v)); }
  function button(label, action) { return ui().rowButton(label, 'window.AdminClaimLineWorkspace.' + action); }
  function url(value) { return w.AdminDrawingEvidence._safeUrl(value); }
  function call(method, args) {
    if (w.apiCache) delete w.apiCache[method + JSON.stringify(args)];
    return w.gsRun(method, args, null).then(function (r) { if (!r || r.success === false) throw new Error(r && r.error || '자료를 불러오지 못했습니다.'); return r; });
  }
  function gaps(c, d) {
    var records = (c.records || []).filter(function (r) { return Number(r.lineId) === Number(c.line.id) && r.recordKind === 'actual' && r.status !== 'rejected'; });
    var missing = [];
    if (!d.drawings.length) missing.push('연결된 도면 없음');
    if (!d.evidence.summary.linked && !records.some(function (r) { return (r.evidence || []).length; })) missing.push('연결된 증빙 없음 — 서류나 사진을 추가하세요.');
    if (!records.length) missing.push('실제 작업 기록 없음 — 작업일·위치·수량을 기록하세요.');
    records.forEach(function (r) { if (!(r.evidence || []).length) missing.push((r.workDate || '날짜 미입력') + ' · ' + (r.location || '위치 미입력') + ' 작업 기록의 근거 자료 없음'); });
    return missing;
  }
  function mount(c) { context = c; page = 1; data = null; return load(); }
  function load() {
    var el = document.getElementById('ce-line-workspace'), token = ++generation, c = context;
    if (!el || !c || !c.line) return Promise.resolve();
    el.innerHTML = '<p role="status">도면과 증빙을 불러오는 중…</p>';
    return call('api_getClaimLineWorkspace', [c.contractId, c.line.id, page]).then(function (r) {
      if (token !== generation || !el.isConnected) return;
      data = r; render(el);
    }).catch(function (e) { if (token === generation && el.isConnected) el.innerHTML = ui().notice(e.message, 'danger') + button('다시 불러오기', 'reload()'); });
  }
  function render(el) {
    var c = context, d = data, missing = gaps(c, d), p = d.evidence.pagination;
    var drawings = d.drawings.map(function (s, i) {
      var thumb = url(s.thumbUrl);
      return '<div style="max-width:220px;border:1px solid var(--border-default);border-radius:10px;padding:10px">' +
        (thumb ? '<img alt="' + esc(s.sheetNo) + ' 도면" src="' + esc(thumb) + '" style="width:100%;height:130px;object-fit:contain">' : '') +
        '<div><strong>' + esc(s.sheetNo) + '</strong></div><div>' + esc(s.title) + '</div>' + button('도면 보기 / 표시 편집', 'drawing(' + i + ')') + '</div>';
    }).join('');
    el.innerHTML = '<section style="border:1px solid var(--border-default);border-radius:12px;padding:16px;margin:16px 0">' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><h3 style="margin-right:auto">공정 도면 · 증빙서류</h3>' +
      (c.canManage ? button('CAD 편집 · 기성 증빙 연결', 'cad()') + button('이 공정에 서류 올리기', 'upload()') : '') + button('증빙 확인 대기 / 기존 자료 연결', 'inbox()') + button('새로고침', 'reloadAll()') + '</div>' +
      '<div style="margin:12px 0">' + (missing.length ? ui().notice('보완할 자료: ' + missing.join(' / '), 'warn') : ui().badge('기본 자료 연결됨', 'ok')) +
      '<p style="font-size:12px;color:var(--text-secondary)">현재 연결 상태 기준입니다. 계약에서 요구하는 납품서·검측서 등은 계약 조건과 아래 확인할 사항을 함께 검토하세요.</p></div>' +
      '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">' + (drawings || '<p>연결된 도면이 없습니다. 공정별 도면에서 도면을 선택하세요.</p>') + '</div>' +
      '<h4>공정에 연결된 서류 ' + Number(p.total) + '건</h4>' +
      ui().table({id:'ce-workspace-files',rows:d.evidence.rows,search:false,emptyText:'아직 이 공정에 연결된 서류가 없습니다. 작업 기록의 첨부자료는 아래 기록에도 표시됩니다.',columns:[
        {key:'title',label:'서류 / 사진',render:function(r){return esc(r.title) + '<br>' + button('미리보기', 'preview(' + Number(r.id) + ')');}},
        {key:'status',label:'상태 / 연결 근거',render:function(r){return esc(r.status === 'linked' ? '연결됨' : '확인 필요') + '<div>' + esc(r.reason) + '</div>';}},
        {key:'date',label:'자료 날짜',render:function(r){return esc(r.date || '미확인');}},
        {key:'actions',label:'',render:function(r){return c.canManage ? ui().rowButton('연결 / 분류 수정','window.AdminClaimEvidence.editInbox(' + Number(r.id) + ')') + (r.sourceType === 'document' ? button('문서 정보 수정 / 검토', 'editDocument(' + Number(r.id) + ')') : '') : '';}}
      ]}) + '<div style="margin-top:10px">' + (p.page > 1 ? button('이전', 'page(' + (p.page-1) + ')') : '') + ' ' + Number(p.page) + ' / ' + Number(p.lastPage) + ' ' +
      (p.page < p.lastPage ? button('다음', 'page(' + (p.page+1) + ')') : '') + '</div></section>';
  }
  function row(id) { return data && data.evidence.rows.find(function (r) { return Number(r.id) === Number(id); }); }
  function preview(id) {
    var r = row(id); if (!r) return;
    var source = url(r.url);
    ui().modal({title:r.title || '증빙 원본',width:1100,body:source ? '<p><a href="' + esc(source) + '" target="_blank" rel="noopener">원본 열기 / 다운로드 ↗</a></p><iframe sandbox="allow-same-origin allow-downloads" title="증빙 원본 미리보기" src="' + esc(source) + '" style="width:100%;height:65vh;border:0"></iframe>' : '<p>미리보기 주소가 없습니다. 자료 접근 권한을 확인하세요.</p>',actions:[{label:'닫기',value:null}]});
  }
  function drawing(i) {
    var s = data && data.drawings[i]; if (!s) return;
    w.DrawingMarker.open({siteId:data.siteId,sheetNo:s.sheetNo,sectionId:data.sectionId,line:context.line});
  }
  function editDocument(id) {
    var r=row(id);if(!r || r.sourceType!=='document' || !context.canManage)return;
    var c=context;
    var modal=ui().modal({title:'문서 정보 수정 · 원본 검토',width:1200,body:'<iframe title="문서 정보 편집" src="/document-hub?embed=1&document='+Number(r.sourceId)+'" style="width:100%;height:76vh;border:0"></iframe>',actions:[{label:'닫기 / 새로고침',value:null}]});
    modal.result.then(function(){if(sameLine(c)){w.apiCache={};context.reload();}});
  }
  function upload() {
    if (!context.canManage || !data) return;
    var c = context, scope = data, busy = false, pending = [];
    var m = ui().modal({title:'BOQ ' + c.line.lineNo + ' — 서류 올리기',width:700,
      body:'<p>' + esc(c.line.description) + '</p><input data-files type="file" multiple><p><label><input data-link type="checkbox" checked> 선택한 자료를 이 공정에 연결합니다.</label></p><p>여러 공정이 섞였거나 애매하면 체크를 해제해 확인 대기로 접수하세요. 원본을 보관하며 기성 수량은 변경하지 않습니다.</p><div data-result role="status" style="white-space:pre-wrap"></div>',
      beforeClose:function(){if(busy){ui().toast('파일 저장이 끝날 때까지 기다려 주세요.','error');return false;}return true;},
      actions:[{label:'닫기',value:null},{label:'업로드 / 실패 항목 재시도',value:'keep',kind:'primary'}],
      onAction:async function(_,el){
        if(busy)return;
        var input=el.querySelector('[data-files]'), result=el.querySelector('[data-result]');
        if(!pending.length) pending=Array.from(input.files).map(function(file){return {file:file,id:null,done:false};});
        if(!pending.length){result.textContent='파일을 선택하세요.';return;}
        if(pending.length>50){result.textContent='이 화면에서는 한 번에 50개까지 올릴 수 있습니다.';pending=[];return;}
        busy=true; input.disabled=true; var choice=el.querySelector('[data-link]'); choice.disabled=true;
        result.textContent='';
        for(var item of pending){
          if(item.done)continue;
          try {
            if(!item.id){
              var form=new w.FormData(); form.append('files[]',item.file); form.append('site_id',String(scope.siteId));
              form.append('purpose','claim_evidence');
              if(scope.projectId)form.append('project_id',String(scope.projectId));
              if(scope.companyId)form.append('company_id',String(scope.companyId));
              var csrf=document.querySelector('meta[name="csrf-token"]');
              var response=await w.fetch('/document-hub/api/upload',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:form});
              var res=await response.json(); if(!response.ok || res.success===false)throw new Error(res.message||res.error||'업로드 실패');
              item.id=((res.documents||[])[0]||{}).id || ((res.duplicates||[])[0]||{}).documentId;
              if(!item.id)throw new Error(((res.failed||[])[0]||{}).reason||'업로드 실패');
            }
            var linked=await call('api_attachClaimDocument',[{contractId:c.contractId,lineId:c.line.id,documentId:item.id,reviewOnly:!choice.checked}]);
            item.done=true; result.textContent+='✓ '+item.file.name+(linked.reviewOnly?' — 확인 대기 접수':' — 공정 연결됨')+(linked.message?' · '+linked.message:'')+'\n';
          }catch(e){result.textContent+='⚠ '+item.file.name+' — '+e.message+(item.id?' (원본 저장됨, 연결 재시도 가능)':'')+'\n';}
        }
        busy=false;
        if(pending.every(function(r){return r.done;})){pending=[];input.value='';input.disabled=false;choice.disabled=false;}
        if(w.apiCache)w.apiCache={};
        if(sameLine(c)) await context.reload();
      }
    });
    return m;
  }
  w.AdminClaimLineWorkspace={cad:function(){var c=context;if(!c||!c.canManage)return;w.AdminClaimEditor.open(c.contractId,c.siteId||data.siteId,data.drawings||[],{lineId:c.line.id,openCAD:true,onClose:function(){if(sameLine(c))context.reload();}});},mount:mount,row:row,preview:preview,drawing:drawing,editDocument:editDocument,upload:upload,reload:load,
    reloadAll:function(){w.apiCache={};return context.reload();},
    inbox:function(){w.AdminClaimEvidence.openInbox(context.line.id);},
    page:function(n){page=n;return load();},_gaps:gaps};
})(window);
