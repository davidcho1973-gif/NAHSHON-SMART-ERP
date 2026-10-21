(function (global) {
  'use strict';
  function call(name, args) {
    return global.gsRun(name, args, null).then(function (r) {
      if (!r || r.success === false) { var e = new Error(r && r.error || '처리하지 못했습니다.'); e.result = r; throw e; }
      return r;
    });
  }
  function open(contractId, siteId, sheets, options) {
    options = options || {};
    if (!document.getElementById('ce-style')) { var style = document.createElement('style'); style.id = 'ce-style'; style.textContent = '.ce-editor{font:14px/1.5 system-ui,sans-serif}.ce-editor input,.ce-editor select,.ce-editor textarea{box-sizing:border-box;border:1px solid var(--border-default,#cbd5e1);border-radius:7px;padding:8px;background:var(--bg-surface,#fff);color:var(--text-primary,#123);max-width:100%;font:inherit}.ce-editor button{border:1px solid var(--border-default,#cbd5e1);border-radius:7px;padding:8px 12px;background:var(--bg-surface,#fff);color:var(--text-primary,#123);font:inherit;cursor:pointer}.ce-editor button:hover{border-color:#0ea5e9}.ce-editor button:disabled{opacity:.5}.ce-editor aside{max-height:65vh;overflow:auto;position:sticky;top:0}.ce-editor [data-edit-body]>div{align-items:start}.ce-editor section{padding:12px;border:1px solid var(--border-default,#cbd5e1);border-radius:10px}.ce-editor label{display:block;margin:8px 0}.ce-editor label input,.ce-editor label select,.ce-editor label textarea{display:block;width:100%}.ce-editor [data-commit],.ce-editor [data-period-prepare]{background:#086b9b;color:white}.ce-editor [data-total]{padding:12px;background:#eaf5ff;color:#145075;border-radius:8px;font-weight:700}.ce-editor [data-save-state]{color:#167755;margin-bottom:8px}.ce-editor h3,.ce-editor h4{margin:12px 0}.ce-editor [data-drawing-preview]{margin-top:12px;background:#f1f5f9;border-radius:8px}.ce-editor [data-calcs] input{min-width:0}@media(max-width:850px){.ce-editor [data-edit-body]>div{grid-template-columns:1fr!important}.ce-editor aside{max-height:160px;overflow:auto}}'; document.head.appendChild(style); }
    var u = global.AdminUI, esc = u.esc, box, data, line, content, revision = 0, timer, saving = Promise.resolve(), closed = false;
    var undo = [], redo = [], dirty = false, newWork = false, searchTimer, searchVersion = 0, activeSheetId = null;
    var embedded = null, embeddedSheet = null, uploading = false, committing = false, modal, cadActive = false;
    var backup = null, backupOk = false, navigating = false, conflict = null, pendingFiles = [];
    var writer = new global.ClaimSaveSafety.Writer(function(request){ preserve(); return call('api_saveClaimEditDraft', [request]); });
    function preserve() {
      if (!backup || !line) return;
      try { backup.write({content:content, revision:revision, newWork:newWork, pending:writer.pending, pendingFiles:pendingFiles}); backupOk=true; }
      catch (_) { backupOk=false; state('기기 임시 저장 실패 · 서버 저장 전 창을 닫지 마세요'); }
    }
    function error(e) {
      if(e.result && e.result.success===false){writer.restore(null);preserve();}
      if (e.result?.code === 'draft_conflict') { conflict=e.result.currentDraft; writer.restore(null); preserve(); }
      if (dirty) state(conflict ? '다른 창과 충돌 · 내용 비교 필요' : '서버 저장 실패 · 입력 유지 · 다시 저장하세요');
      var button=box?.querySelector('[data-resolve]'); if(button)button.hidden=!conflict;
      u.toast(e.message || '처리하지 못했습니다.', 'error');
    }
    function unload(e) { if(dirty || uploading || committing || navigating || writer.pending || pendingFiles.length){preserve();e.preventDefault();e.returnValue='';} }
    function online() { if(dirty && !conflict && !closed)save().catch(error); }
    function visibility() { if(document.visibilityState==='hidden' && dirty)preserve(); }
    function money(v) { return Number(v || 0).toLocaleString('en-US', { style: 'currency', currency: data.contract.currency || 'USD' }); }
    function snapshot() { undo.push(JSON.stringify(content)); redo = []; }
    function total() {
      return content.calculations.length ? Math.round(content.calculations.reduce(function (s, r) { return s + Number(r.length) * Number(r.height) * Number(r.count) - Number(r.deduction); }, 0) * 10000) / 10000 : Number(content.quantity || 0);
    }
    function state(text) { if (box) box.querySelector('[data-save-state]').textContent = text; }
    function locked() { return !newWork && !!data?.drafts.find(function(d){return d.lineId===line?.id;})?.recordId; }
    function queueSave() {
      if (locked()) return;
      dirty = true; clearTimeout(timer); state('저장 대기');
      preserve();
      if(!conflict)timer = setTimeout(function () { save().catch(error); }, 1200);
    }
    function save() {
      clearTimeout(timer);
      saving = saving.catch(function () {}).then(async function () {
        if(conflict)throw new Error('두 초안을 비교한 뒤 저장하세요.');
        // Drain all edits, including keystrokes made while the previous request was in flight.
        while ((dirty || writer.pending) && line && !closed) {
          state('서버 저장 중…');
          var ack=await writer.run(function(){return {lineId:line.id,revision:revision,content:content,newWork:newWork};});
          var r=ack.response, sent=JSON.stringify(ack.sent.content), id=ack.sent.lineId;
          if (!line || line.id !== id) throw new Error('공정이 변경되었습니다. 저장 내용을 다시 확인하세요.');
          revision = r.draft.revision; newWork = false;
          var index = data.drafts.findIndex(function (d) { return d.lineId === id; });
          if (index < 0) data.drafts.push(r.draft); else data.drafts[index] = r.draft;
          dirty = JSON.stringify(content) !== sent;
          if (!dirty) content = JSON.parse(JSON.stringify(r.draft.content));
          else content.evidence.forEach(function (e) { var saved = r.draft.content.evidence.find(function (p) { return p.type === e.type && p.id === e.id; }); if (saved) e.sha256 = saved.sha256; });
          if(dirty || pendingFiles.length)preserve(); else if(backup){try{backup.clear();}catch(_){}}
          state(dirty ? '추가 입력 저장 중…' : '서버 저장 완료 · '+new Date(r.draft.savedAt || Date.now()).toLocaleTimeString()+' · 아직 검토 요청 전');
        }
      });
      return saving;
    }
    function choose(id) {
      if (uploading || committing || cadActive || navigating) return Promise.resolve();
      if (embedded && !embedded.beforeClose()) return Promise.resolve();
      if (embedded) { embedded.close(true); embedded = null; embeddedSheet = null; }
      navigating=true;box.inert=true;
      return save().then(function () {
        searchVersion++; clearTimeout(searchTimer);
        line = data.lines.find(function (l) { return l.id === Number(id); });
        var draft = data.drafts.find(function (d) { return d.lineId === line.id; });
        revision = draft ? draft.revision : 0;
        content = draft ? JSON.parse(JSON.stringify(draft.content)) : { workDate: data.contract.today, location: '', stage: line.recognitionBasis === 'milestone' ? (Object.keys(line.stageWeights || {}).includes('installation') ? 'installation' : Object.keys(line.stageWeights || {})[0]) : 'installed', quantity: 0, calculations: [], evidence: [], marks: [], notes: '' };
        var preferred = (sheets || []).find(function (s) { return (s.sectionIds || []).includes(line.workSectionId); }) || (sheets || [])[0]; activeSheetId = preferred ? preferred.id : null;
        undo = []; redo = []; dirty = false; newWork = false;
        conflict=null;pendingFiles=[];writer.restore(null);
        backup=null;
        // A submitted work record stays immutable. Further attachments start a new draft.
        if (options.fullscreen && draft && draft.recordId) {
          newWork = true;
          content = {workDate:data.contract.today,location:'',stage:content.stage,quantity:0,calculations:[],evidence:[],marks:[],notes:''};
        }
        try {
          backup=global.ClaimSaveSafety.recovery('editor:'+contractId+':'+line.id);
          var recovered=backup.latest();
          if(recovered && global.confirm('이 공정에 서버 저장을 마치지 못한 입력이 있습니다. 복구할까요?')){
            content=recovered.content;revision=recovered.revision;newWork=recovered.newWork;writer.restore(recovered.pending);pendingFiles=recovered.pendingFiles||[];dirty=true;preserve();
          }
        }catch(_){state('기기 복구본을 읽지 못했습니다. 서버 초안을 사용합니다.');}
        render();
        if(dirty)state('복구한 입력 · 확인 후 저장하세요'+(pendingFiles.length?' · 미완료 파일 '+pendingFiles.join(', ')+' 다시 선택 필요':''));
        if (draft && draft.recordId && !options.fullscreen) state('기록 완료 #' + draft.recordId);
        if(options.openCAD){options.openCAD=false;openCAD();}
      }).catch(error).finally(function(){navigating=false;box.inert=cadActive||committing;});
    }
    function resolveConflict() {
      if(!conflict)return;
      var latest=conflict;
      function summary(c){return '<p>'+esc(c.workDate)+' · '+esc(c.stage)+' · 수량 '+esc(c.quantity)+' · '+esc(c.location)+'</p><p>증빙 '+(c.evidence||[]).length+'개 · 산출 '+(c.calculations||[]).length+'행 · 표시 '+(c.marks||[]).length+'개</p><pre style="white-space:pre-wrap">'+esc(c.notes)+'</pre><details><summary>모든 입력·증빙 연결 상세 보기</summary><pre style="white-space:pre-wrap">'+esc(JSON.stringify(c,null,2))+'</pre></details>';}
      var compare=u.modal({title:'두 초안 비교',body:'<h3>현재 입력</h3>'+summary(content)+'<h3>서버의 최신 초안</h3>'+summary(latest.content),actions:[{label:'돌아가기',value:null},{label:'서버 내용 사용',value:'server'},{label:'내 입력으로 새 버전 저장',value:'mine'}]}); compare.result.then(function(action){
        if(action!=='server'&&action!=='mine')return;
        if(action==='mine'&&latest.recordId){u.toast('서버 초안은 이미 검토 요청됐습니다. 서버 내용을 열고 새 작업을 시작하세요.','error');return;}
        revision=latest.revision;newWork=false;writer.restore(null);conflict=null;
        var i=data.drafts.findIndex(function(d){return d.lineId===line.id;});if(i<0)data.drafts.push(latest);else data.drafts[i]=latest;
        if(action==='server'){content=JSON.parse(JSON.stringify(latest.content));dirty=false;render();state('서버 초안을 불러왔습니다. 이전 입력은 기기 복구본에 보관됩니다.');}
        else {dirty=true;preserve();save().then(render).catch(error);}
        box.querySelector('[data-resolve]').hidden=true;
      });
    }
    function render() {
      if (options.fullscreen) { renderEvidence(); return; }
      var stages = line.recognitionBasis === 'milestone' ? Object.keys(line.stageWeights || {}) : ['installed'];
      var records = data.records.filter(function (r) { return r.lineId === line.id; });
      if (options.fullscreen) box.querySelector('[data-record-title]').textContent = '#' + line.lineNo + ' ' + line.description;
      box.querySelector('[data-edit-body]').innerHTML = '<div style="display:grid;grid-template-columns:minmax(150px,1fr) minmax(250px,3fr) minmax(240px,2fr);gap:12px;overflow:auto">' +
        '<aside><input data-line-search placeholder="공정명·번호 검색" style="width:100%;position:sticky;top:0;margin-bottom:8px">' + (options.fullscreen ? data.lines.filter(function (l) { return l.id === line.id; }) : data.lines).map(function (l) { return '<button type="button" data-line="' + l.id + '" style="display:block;width:100%;padding:12px;text-align:left;margin-bottom:6px">#' + esc(l.lineNo) + ' ' + esc(l.description) + '<br><small>미배정 ' + money(l.availableAmount) + '<br>' + esc(l.nextAction || '') + '</small></button>'; }).join('') + '</aside>' +
        '<section data-panel="calculation"><select data-sheet style="width:100%">' + (sheets || []).map(function (s) { return '<option value="' + s.id + '">' + esc(s.sheetNo + ' · ' + s.title) + '</option>'; }).join('') + '</select><button type="button" data-drawing>도면 크게 열기 · 표시</button><button type="button" data-cad>CAD 편집 · 기성 증빙 연결</button><div data-drawing-preview style="height:300px;overflow:auto"></div>' +
        '<h4>산출표 · ' + esc(line.unit) + '</h4><div style="display:flex;gap:4px;color:#64748b;font-size:12px"><span style="width:18%">위치</span><span style="width:18%">길이</span><span style="width:18%">높이</span><span style="width:18%">개수</span><span style="width:18%">공제</span></div><div data-calcs></div><button type="button" data-add>+ 산출 행</button><p data-total></p>' +
        '<details><summary>AI에게 질문</summary><textarea data-question rows="3" placeholder="선택한 공정의 도면 근거와 부족한 자료를 설명해줘" style="width:100%"></textarea><button type="button" data-ask>질문하기</button><div data-answer></div></details></section>' +
        '<section data-panel="record"><h3>#' + esc(line.lineNo) + ' ' + esc(line.description) + '</h3><p>계약 ' + esc(line.contractQty) + ' ' + esc(line.unit) + '</p><p data-money>확인 금액 ' + money(line.verifiedAmount) + '<br>기존 회차 배정 ' + money(line.allocatedAmount) + '<br>미배정 확인액 ' + money(line.availableAmount) + '<br>잔여 계약액 ' + money(line.remainingContractAmount) + '</p>' +
        '<label>작업일<input data-field="workDate" type="date" value="' + esc(content.workDate) + '"></label><br><label>위치<input data-field="location" value="' + esc(content.location) + '"></label><br><label>단계<select data-field="stage">' + stages.map(function (s) { return '<option' + (s === content.stage ? ' selected' : '') + ' value="' + esc(s) + '">' + esc({ installed: '설치', installation: '설치', stored: '자재 반입' }[s] || s) + '</option>'; }).join('') + '</select></label><br><label>산출표가 없을 때 직접 수량<input data-field="quantity" type="number" min="0" step="any" value="' + esc(content.quantity) + '"></label>' +
        '<div data-panel="evidence"><label>증빙 바로 올리기<input data-upload type="file" multiple accept="image/*,.pdf,.docx,.xlsx,.csv"></label><div data-upload-state></div></div><p>' + esc((line.blockers || []).join(' · ')) + '</p><details><summary>공정 필수자료 설정</summary><div data-requirements></div><button type="button" data-rule-add>+ 필수자료</button><button type="button" data-rule-save>조건 저장</button></details><h4>기존 자료 연결</h4><input data-search placeholder="자료 검색"><div data-sources style="max-height:200px;overflow:auto"></div><div data-linked></div>' +
        '<div><button type="button" data-template>작업 보고서 양식 넣기</button><button type="button" data-paste-table>Excel 표 붙여넣기</button></div><label>작업 설명 · 보고서<textarea data-field="notes" rows="8">' + esc(content.notes) + '</textarea></label><p><button type="button" data-undo>되돌리기</button> <button type="button" data-redo>다시 실행</button></p><button type="button" data-commit>확인 대기로 기록</button><button type="button" data-review>기존 기록 · 확인</button><button type="button" data-new>새 작업</button>' +
        '<details><summary>초안 수정 이력</summary><div data-history></div></details><details data-record-history><summary>작업 이력 ' + records.length + '건</summary>' + records.map(function (r) { return '<p>' + (r.status === 'pending' && r.recordKind === 'actual' ? '<input type="checkbox" data-record-select value="' + r.id + '"> ' : '') + esc(r.workDate + ' · ' + r.location + ' · ' + r.reportedQty + ' ' + line.unit + ' · ' + r.status) + (r.status === 'pending' && r.recordKind === 'actual' ? ' <button data-edit-record="'+r.id+'">수정</button>' : '') + '</p>'; }).join('') + '<button type="button" data-bulk>선택 기록에 위 작업일·위치 적용</button></details></section></div>';
      box.querySelectorAll('[data-edit-record]').forEach(function(b){b.onclick=function(){var record=data.records.find(function(r){return r.id===Number(b.dataset.editRecord);});u.formModal({draftKey:'record:'+contractId+':'+line.id+':'+record.id,draftVersion:record.revision,title:'작업 기록 #'+record.id+' 수정',subtitle:'검토 대기 기록의 수정 이력이 남습니다.',fields:[{name:'workDate',label:'작업일',type:'date',value:record.workDate,required:true},{name:'location',label:'작업 위치',value:record.location,required:true},{name:'reportedQty',label:'보고 수량 ('+line.unit+')',type:'number',value:record.reportedQty,required:true},{name:'notes',label:'작업 설명',type:'textarea',value:record.notes||'',colSpan:2}],onSave:function(v){return call('api_saveClaimRecord',[Object.assign({id:record.id},v)]).then(function(){return call('api_getClaimEditor',[contractId]).then(function(r){data=r;render();}).catch(function(){u.toast('기록은 서버에 저장됐습니다. 목록을 다시 불러와 주세요.');}).then(function(){return {success:true};});});}});};});
      box.querySelector('[data-upload]').onchange = uploadFiles;
      if(box.querySelector('[data-cad]'))box.querySelector('[data-cad]').onclick=openCAD;
      box.querySelector('[data-template]').onclick=function(){snapshot();content.notes+=(content.notes?'\n\n':'')+'작업 내용\n• \n\n시공 위치 및 범위\n• \n\n자재 · 장비\n• \n\n검측 및 보완사항\n• \n\n다음 작업 계획\n• ';box.querySelector('[data-field="notes"]').value=content.notes;queueSave();};
      box.querySelector('[data-paste-table]').onclick=function(){u.formModal({title:'Excel 산출표 붙여넣기',subtitle:'머리글 없이 위치 / 길이 / 높이 / 개수 / 공제 순서의 5열을 복사하세요. 붙여넣은 행은 기존 표 뒤에 추가됩니다.',fields:[{name:'table',label:'복사한 셀',type:'textarea',required:true,colSpan:2}],onSave:function(v){var rows=String(v.table||'').trim().split(/\r?\n/).map(function(row){return row.split('\t');});if(rows.length+content.calculations.length>100||rows.some(function(r){return r.length!==5||r.slice(1).some(function(n){return n.trim()===''||!Number.isFinite(Number(n))||Number(n)<0;});}))return Promise.resolve({success:false,error:'5열의 숫자와 최대 100행을 확인하세요.'});snapshot();rows.forEach(function(r){content.calculations.push({label:r[0],length:Number(r[1]),height:Number(r[2]),count:Number(r[3]),deduction:Number(r[4])});});calculations();queueSave();return save().then(function(){return {success:true};});}});};
      box.querySelector('[data-line-search]').oninput = function () { var query = this.value.toLowerCase(); box.querySelectorAll('[data-line]').forEach(function (b) { b.hidden = !b.textContent.toLowerCase().includes(query); b.style.display = b.hidden ? 'none' : 'block'; }); };
      box.querySelectorAll('[data-line]').forEach(function (b) { b.onclick = function () { choose(b.dataset.line); }; });
      box.querySelectorAll('[data-field]').forEach(function (e) { e.oninput = function () { snapshot(); content[e.dataset.field] = e.value; calculations(); queueSave(); }; });
      box.querySelector('[data-add]').onclick = function () { snapshot(); content.calculations.push({ label: '', length: 0, height: 1, count: 1, deduction: 0 }); calculations(); queueSave(); };
      box.querySelector('[data-search]').oninput = function () { sources(); clearTimeout(searchTimer); var query = this.value, version = ++searchVersion; searchTimer = setTimeout(function () { call('api_getClaimEvidenceSources', [contractId, query]).then(function (r) { if (closed || version !== searchVersion) return; r.sourceOptions.forEach(function (source) { if (!data.sourceOptions.some(function (s) { return s.value === source.value; })) data.sourceOptions.push(source); }); sources(); }).catch(error); }, 350); };
      box.querySelector('[data-undo]').onclick = function () { if (!undo.length) return; redo.push(JSON.stringify(content)); content = JSON.parse(undo.pop()); render(); queueSave(); };
      box.querySelector('[data-redo]').onclick = function () { if (!redo.length) return; undo.push(JSON.stringify(content)); content = JSON.parse(redo.pop()); render(); queueSave(); };
      box.querySelector('[data-new]').onclick = function () { if(uploading || committing)return; if (!global.confirm('새 작업 초안을 시작할까요? 기존 시공 기록은 보존됩니다.')) return; newWork = true; snapshot(); content = { workDate: data.contract.today, location: '', stage: line.recognitionBasis === 'milestone' ? (Object.keys(line.stageWeights || {}).includes('installation') ? 'installation' : Object.keys(line.stageWeights || {})[0]) : 'installed', quantity: 0, calculations: [], evidence: [], marks: [], notes: '' }; render(); queueSave(); };
      box.querySelector('[data-review]').onclick = function () { if(options.fullscreen){var history=box.querySelector('[data-record-history]');history.open=true;history.scrollIntoView({block:'start'});return;} save().then(function () { if (embedded && !embedded.beforeClose()) return; modal.close(null); global.AdminClaimEvidence.open(contractId, line.id); }).catch(error); };
      box.querySelector('[data-commit]').onclick = function () { var b = this; if (committing || uploading) return; committing = true; box.inert = true; b.disabled = true; save().then(function () { if (!revision) { dirty = true; return save(); } }).then(function () { return call('api_commitClaimEditDraft', [{ lineId: line.id, revision: revision }]); }).then(function (r) { state('확인 대기 기록 #' + r.id); u.toast('기록했습니다. 담당자 확인 후 기성에 포함됩니다.'); return call('api_getClaimEditor', [contractId]).then(function (fresh) { data = fresh; render(); state('확인 대기 기록 #' + r.id); }); }).catch(error).finally(function () { committing = false; box.inert = false; b.disabled = false; }); };
      if (activeSheetId !== null) box.querySelector('[data-sheet]').value = String(activeSheetId);
      box.querySelector('[data-sheet]').onchange = function () { if (embedded && !embedded.beforeClose()) { this.value = String(activeSheetId); return; } if (embedded) { embedded.close(true); embedded = null; embeddedSheet = null; } activeSheetId = Number(this.value); preview(); };
      box.querySelector('[data-drawing]').onclick = function () {
        var s = selectedSheet(); if (!s) return;
        global.DrawingMarker.open({ siteId: siteId, sheetNo: s.sheetNo, mode: 'pick', captureSnapshot: true, initialShapes: content.marks.filter(function (m) { return m.sheetNo === s.sheetNo; }), line: line, note: content.notes, onProposal: function (p) { snapshot(); if (p.calculations.length) content.calculations = p.calculations; p.sources.forEach(addSource); content.marks = content.marks.concat(p.annotations || []); render(); queueSave(); }, onPick: function (g) { snapshot(); content.marks = content.marks.filter(function (m) { return m.sheetNo !== g.sheetNo; }).concat(g.shapes.map(function (m) { return Object.assign({}, m, { sheetNo: g.sheetNo, label: m.label || g.label || '' }); })); if (g.measuredTotal && !content.calculations.length && !Number(content.quantity)) content.quantity = g.measuredTotal; render(); queueSave(); return uploadMarked(g); } });
      };
      box.querySelector('[data-ask]').onclick = function () {
        var b = this, s = selectedSheet(), askedLineId = line.id; if (!s) return;
        b.disabled = true; var answer = box.querySelector('[data-answer]'); answer.textContent = '도면 근거 확인 중…';
        call('api_askClaimDrawing', [{ lineId: line.id, sheetId: s.id, question: box.querySelector('[data-question]').value }]).then(function (r) {
          if (line.id !== askedLineId || !answer.isConnected) return;
          var p = r.proposal;
          answer.innerHTML = '<p style="white-space:pre-wrap">' + esc(p.answer) + '</p><p>' + esc(p.sheetNo + ' p.' + p.page) + '</p><p>' + esc(p.caution) + '</p><p>제안 수량 ' + esc(p.quantity) + ' ' + esc(line.unit) + ' · 확인 전 초안 · 표시안 ' + (p.annotations || []).length + '개</p><button type="button" data-apply>산출표·증빙에 적용</button>';
          answer.querySelector('[data-apply]').onclick = function () { snapshot(); if (p.calculations.length) content.calculations = p.calculations; p.sources.forEach(function (key) { addSource(key); }); content.marks = content.marks.concat(p.annotations || []); render(); queueSave(); };
        }).catch(function (e) { answer.textContent = e.message; }).finally(function () { b.disabled = false; });
      };
      box.querySelector('[data-bulk]').onclick = function () { var ids = Array.from(box.querySelectorAll('[data-record-select]:checked')).map(function (e) { return Number(e.value); }); if (!ids.length) { u.toast('기록을 선택하세요.'); return; } call('api_bulkClaimEdit', [{contractId: contractId, ids: ids, changes: {workDate: content.workDate, location: content.location}}]).then(function (r) { u.toast(r.updated + '건 수정했습니다.'); return call('api_getClaimEditor', [contractId]); }).then(function (r) { data = r; render(); }).catch(error); };
      var rules = JSON.parse(JSON.stringify(line.evidenceRequirements || []));
      function renderRules() { var labels = {inspection:'검측서',material_receipt:'입고 송장',receipt:'영수증',photo:'시공 사진(WBS)',drawing:'도면',invoice:'Invoice',delivery_ticket:'입고내역서',daily_report:'일보',submittal:'승인자료'}; box.querySelector('[data-requirements]').innerHTML = rules.map(function (rule, i) { return '<p><select data-rule-type="'+i+'">'+Object.keys(labels).map(function (type) { return '<option value="'+type+'"'+(rule.type===type?' selected':'')+'>'+labels[type]+'</option>'; }).join('')+'</select><select data-rule-stage="'+i+'"><option value="">모든 단계</option>'+stages.map(function(stage){return '<option value="'+esc(stage)+'"'+(rule.stage===stage?' selected':'')+'>'+esc(stage)+'</option>';}).join('')+'</select><input aria-label="필수자료 건수" type="number" min="1" max="20" data-rule-count="'+i+'" value="'+rule.count+'" style="width:65px"><button type="button" data-rule-remove="'+i+'">×</button></p>'; }).join(''); ['type','stage','count'].forEach(function(k){box.querySelectorAll('[data-rule-'+k+']').forEach(function(e){e.onchange=function(){rules[Number(e.getAttribute('data-rule-'+k))][k]=k==='count'?Number(e.value):e.value;};});}); box.querySelectorAll('[data-rule-remove]').forEach(function(b){b.onclick=function(){rules.splice(Number(b.dataset.ruleRemove),1);renderRules();};}); }
      renderRules();
      box.querySelector('[data-rule-add]').onclick=function(){rules.push({type:'inspection',stage:'',count:1});renderRules();};
      box.querySelector('[data-rule-save]').onclick=function(){call('api_saveClaimEvidenceRequirements',[{lineId:line.id,requirements:rules}]).then(function(){return call('api_getClaimEditor',[contractId]);}).then(function(fresh){data=fresh;line=data.lines.find(function(l){return l.id===line.id;});render();u.toast('필수자료 조건을 저장했습니다.');}).catch(error);};
      var savedDraft = data.drafts.find(function (d) { return d.lineId === line.id; });
      box.querySelector('[data-history]').innerHTML = ((savedDraft && savedDraft.history) || []).slice().reverse().map(function (h) { return '<p>' + esc(h.at + ' · 버전 ' + h.revision) + (savedDraft.recordId ? '' : ' <button type="button" data-restore="' + h.revision + '">복원</button>') + '</p>'; }).join('') || '저장된 수정 이력이 없습니다.';
      box.querySelectorAll('[data-restore]').forEach(function (b) { b.onclick = function () { var h = savedDraft.history.find(function (x) { return x.revision === Number(b.dataset.restore); }); if (!h) return; snapshot(); content = JSON.parse(JSON.stringify(h.content)); render(); queueSave(); }; });
      calculations(); sources(); preview();
      if(locked()){
        box.querySelectorAll('[data-field],[data-upload],[data-add],[data-row],[data-delete-row],[data-source],[data-caption],[data-rotate],[data-up],[data-unlink],[data-template],[data-paste-table],[data-undo],[data-redo],[data-commit],[data-drawing],[data-ask],[data-restore],[data-cad]').forEach(function(e){e.disabled=true;});
        state('검토 요청 완료 · 기존 기록 수정 또는 새 작업을 선택하세요');
      }
    }
    // Drawing-side workspace is evidence + notes only; financial editing remains in the claim ledger.

    // CAD attachment increments the same draft revision. Freeze this editor until its
    // authoritative draft is reloaded so stale autosaves cannot overwrite evidence.
    function openCAD() {
      if(cadActive || uploading || committing || !line)return;
      if(locked()){u.toast('기록 완료된 작업은 새 작업을 시작한 뒤 CAD 증빙을 연결하세요.');return;}
      if(embedded && !embedded.beforeClose())return;
      const selectedLineId=line.id, contract=Number(contractId), site=Number(siteId);
      if(!site){u.toast('현장을 먼저 선택하세요.');return;}
      cadActive=true;box.inert=true;clearTimeout(timer);
      const flush=()=>save().then(()=>dirty?flush():undefined);
      flush().then(function(){
        if(!revision || newWork){dirty=true;return flush();}
      }).then(function(){
        if(closed || !line || line.id!==selectedLineId)throw Error('기성 줄이 변경되었습니다. 다시 여세요.');
        var frame,attached=false;
        const src='/cad?site_id='+site+'&claim_line_id='+selectedLineId+'&claim_contract_id='+contract+'&claim_draft_revision='+revision;
        const nested=u.modal({title:'CAD 편집 · 기성 #'+line.lineNo+' 증빙',subtitle:'기성 내용은 저장되어 있습니다. CAD의 저장한 개정을 연결하면 현재 작업 초안의 증빙에 추가됩니다. 수량은 자동 변경되지 않습니다.',width:1800,body:'<iframe data-claim-cad title="기성 CAD 도면 편집" src="'+esc(src)+'" style="width:100%;height:76vh;border:0"></iframe>',actions:[{label:'기성 편집으로 돌아가기',value:null}],beforeClose:function(){
          try{return !frame || !frame.contentWindow.CADClaimBridge || frame.contentWindow.CADClaimBridge.beforeClose();}catch(e){return true;}
        },onReady:function(el){frame=el.querySelector('[data-claim-cad]');}});
        function receive(event){
          if(!frame || event.origin!==location.origin || event.source!==frame.contentWindow || !event.data || Number(event.data.lineId)!==selectedLineId || Number(event.data.contractId)!==contract)return;
          if(event.data.type==='erp:cad-claim-attached'){attached=true;nested.close(null);}
          if(event.data.type==='erp:cad-claim-close')nested.close(null);
        }
        global.addEventListener('message',receive);
        return nested.result.then(function(){
          global.removeEventListener('message',receive);
          if(global.apiCache)delete global.apiCache['api_getClaimEditor'+JSON.stringify([contract])];
          return call('api_getClaimEditor',[contract]).then(function(fresh){
            if(closed || !line || line.id!==selectedLineId)return;
            var saved=fresh.drafts.find(function(d){return d.lineId===selectedLineId;});
            if(!saved)throw Error('기성 초안을 다시 불러오지 못했습니다. 편집기를 다시 여세요.');
            data=fresh;content=JSON.parse(JSON.stringify(saved.content));revision=saved.revision;newWork=false;dirty=false;undo=[];redo=[];
            render();state(attached?'CAD 개정이 기성 초안 증빙에 연결됨 · 수량 유지':'저장된 기성 초안으로 돌아왔습니다.');
          });
        });
      }).catch(error).finally(function(){cadActive=false;if(box)box.inert=false;});
    }

    function renderEvidence() {
      box.querySelector('[data-record-title]').textContent = '#' + line.lineNo + ' ' + line.description;
      box.querySelector('[data-edit-body]').innerHTML = '<section><p>자료는 초안으로 저장됩니다. 수량 입력 후 검토 요청을 완료하세요.</p><button type="button" data-next>다음: 수량 입력 · 검토 요청</button><button type="button" data-cad>CAD 편집 · 기성 증빙 연결</button><label>자료 올리기<input data-upload type="file" multiple accept="image/*,.pdf,.docx,.xlsx,.csv"></label><div data-upload-state role="status"></div><div data-linked></div><label>작업 설명<textarea data-field="notes" rows="12" maxlength="10000">' + esc(content.notes) + '</textarea></label></section>';
      var picker = box.querySelector('[data-sheet]');
      if (!picker) {
        picker = document.createElement('select'); picker.dataset.sheet = ''; picker.setAttribute('aria-label','도면 선택');
        picker.innerHTML = (sheets || []).map(function(s){return '<option value="'+s.id+'">'+esc(s.sheetNo+' · '+s.title)+'</option>';}).join('');
        box.querySelector('.ce-studio-toolbar').insertBefore(picker, box.querySelector('[data-save-state]'));
      }
      if (activeSheetId !== null) picker.value = String(activeSheetId);
      picker.onchange = function(){if(embedded && !embedded.beforeClose()){this.value=String(activeSheetId);return;}if(embedded){embedded.close(true);embedded=null;embeddedSheet=null;}activeSheetId=Number(this.value);preview();};
      box.querySelector('[data-upload]').onchange = uploadFiles;
      box.querySelector('[data-field="notes"]').oninput = function(){content.notes=this.value;queueSave();};
      box.querySelector('[data-cad]').onclick=openCAD;
      box.querySelector('[data-next]').onclick=function(){save().then(function(){var id=line.id;modal.close(null);if(!box.isConnected)open(contractId,siteId,sheets,{lineId:id,onClose:options.onClose});}).catch(error);};
      attachments(); preview();
    }

    function viewProof(index){
      var proof=content.evidence[index];if(!proof)return;
      var source=data.sourceOptions.find(function(o){return o.value===proof.type+':'+proof.id;})||{};
      if(proof.type==='cad_revision'){
        var drawingId=Number(source.drawingId||proof.drawingId),drawingRevision=Number(source.drawingRevision||proof.drawingRevision);
        if(Number.isSafeInteger(drawingId)&&drawingId>0&&Number.isSafeInteger(drawingRevision)&&drawingRevision>0){
          var src='/cad?drawing_id='+drawingId+'&revision='+drawingRevision;
          u.modal({title:'기성 증빙 CAD · 저장된 개정 '+drawingRevision,subtitle:'SHA256 '+(proof.sha256||source.sha256||'확인 필요'),width:1800,body:'<iframe title="저장된 CAD 증빙 개정" src="'+src+'" style="width:100%;height:76vh;border:0"></iframe>'});return;
        }
      }
      var url='/billing-export/contracts/'+contractId+'/daily-evidence/'+encodeURIComponent(proof.type)+'/'+Number(proof.id);
      u.modal({title:'증빙 원본 · '+proof.locator,width:1100,body:'<p><a target="_blank" rel="noopener" href="'+esc(url)+'">원본 열기 / 다운로드</a></p><iframe sandbox="allow-same-origin allow-downloads" title="증빙 원본" src="'+esc(url)+'" style="width:100%;height:65vh;border:0;background:white"></iframe>'});
    }

    function attachments() {
      box.querySelector('[data-linked]').innerHTML = content.evidence.map(function(e,i){var source=data.sourceOptions.find(function(o){return o.value===e.type+':'+e.id;});return '<div class="ce-attachment"><span>'+esc(source ? source.label : '연결 자료 #'+e.id)+'</span><button type="button" data-proof="'+i+'">보기</button><button type="button" data-unlink="'+i+'" aria-label="자료 연결 해제">×</button></div>';}).join('');
      box.querySelectorAll('[data-proof]').forEach(function(b){b.onclick=function(){viewProof(Number(b.dataset.proof));};});
      box.querySelectorAll('[data-unlink]').forEach(function(b){b.onclick=function(){content.evidence.splice(Number(b.dataset.unlink),1);attachments();queueSave();};});
    }
    function uploadMarked(g) {
      if (!g.blob) return Promise.resolve();
      return new Promise(function (resolve, reject) { var reader = new FileReader(); reader.onload = function () { resolve(reader.result); }; reader.onerror = function () { reject(new Error('표시 도면을 읽지 못했습니다.')); }; reader.readAsDataURL(g.blob); }).then(function (image) {
        return call('api_saveClaimDrawingImage', [{lineId: line.id, sheetId: g.sheetId, sheetNo: g.sheetNo, image: image}]);
      }).then(function (r) {
        var id = r.id, sourceId = r.sourceDocumentId;
        return call('api_getClaimEditor', [contractId]).then(function (fresh) { data.sourceOptions = fresh.sourceOptions; addSource('document:' + id); if (sourceId) addSource('document:' + sourceId); var proof = content.evidence.find(function (p) { return p.type === 'document' && p.id === id; }); proof.locator = g.sheetNo + ' · 표시 도면 · 실제 시공 확인 전'; render(); queueSave(); return save(); });
      });
    }
    function selectedSheet() { return (sheets || []).find(function (s) { return String(s.id) === box.querySelector('[data-sheet]').value; }); }
    function preview() {
      var s = selectedSheet(), p = box.querySelector('[data-drawing-preview]');
      if (p) p.innerHTML = s && s.thumbUrl ? '<img alt="도면 미리보기" src="' + esc(s.thumbUrl) + '" style="max-width:100%">' : '연결된 도면이 없습니다. 공정별 도면에서 도면을 지정하세요.';
      if (!options.fullscreen) return;
      var host = box.querySelector('[data-studio-drawing]');
      if (embedded && embeddedSheet === (s && s.id)) return;
      if (!s || !global.DrawingMarker) { host.textContent = '연결된 도면이 없습니다. 오른쪽에서 자료와 작업 설명을 저장할 수 있습니다.'; return; }
      host.textContent = ''; embeddedSheet = s.id;
      embedded = global.DrawingMarker.open({host:host, siteId:siteId, sheetNo:s.sheetNo, sectionId:line.workSectionId, line:line});
    }
    function calculations() {
      box.querySelector('[data-calcs]').innerHTML = content.calculations.map(function (r, i) { return '<div style="display:flex;gap:4px;margin:6px 0">' + ['label', 'length', 'height', 'count', 'deduction'].map(function (k) { return '<input aria-label="' + esc({label:'위치',length:'길이',height:'높이',count:'개수',deduction:'공제'}[k]) + '" title="' + esc({label:'위치',length:'길이',height:'높이',count:'개수',deduction:'공제'}[k]) + '" data-row="' + i + '" data-key="' + k + '" style="width:18%" type="' + (k === 'label' ? 'text' : 'number') + '" value="' + esc(r[k]) + '">'; }).join('') + '<button type="button" data-delete-row="' + i + '">×</button></div>'; }).join('');
      box.querySelectorAll('[data-row]').forEach(function (e) { e.oninput = function () { snapshot(); content.calculations[Number(e.dataset.row)][e.dataset.key] = e.dataset.key === 'label' ? e.value : Number(e.value); updateTotal(); queueSave(); }; });
      box.querySelectorAll('[data-delete-row]').forEach(function (e) { e.onclick = function () { snapshot(); content.calculations.splice(Number(e.dataset.deleteRow), 1); calculations(); queueSave(); }; });
      updateTotal();
    }
    function updateTotal() {
      var weight = line.recognitionBasis === 'milestone' ? Number((line.stageWeights || {})[content.stage] || 0) / 100 : 1;
      box.querySelector('[data-total]').textContent = '길이 × 높이 × 개수 − 공제 = ' + total() + ' ' + line.unit + (options.fullscreen ? '' : ' · 확인 시 예상 ' + money(total() * line.unitPrice * weight));
    }
    function addSource(key) { var p = key.split(':'); if (!content.evidence.some(function (e) { return e.type === p[0] && e.id === Number(p[1]); })) content.evidence.push({ type: p[0], id: Number(p[1]), locator: '전체' }); }
    function sources() {
      if (options.fullscreen) { attachments(); return; }
      var q = box.querySelector('[data-search]').value.toLowerCase();
      box.querySelector('[data-sources]').innerHTML = data.sourceOptions.filter(function (o) { return o.label.toLowerCase().includes(q); }).map(function (o) { return '<button draggable="true" type="button" data-source="' + esc(o.value) + '" style="display:block;text-align:left">+ ' + esc(o.label) + '</button>'; }).join('');
      box.querySelectorAll('[data-source]').forEach(function (e) { e.ondragstart = function (event) { event.dataTransfer.setData('text/plain', e.dataset.source); }; e.onclick = function () { snapshot(); addSource(e.dataset.source); sources(); queueSave(); }; });
      box.querySelector('[data-linked]').innerHTML = content.evidence.map(function (e, i) { var o = data.sourceOptions.find(function (o) { return o.value === e.type + ':' + e.id; }); return '<p>' + esc(o ? o.label : '자료 확인 필요') + (['document','photo','receipt','expense','cad_revision'].includes(e.type) ? ' <button type="button" data-proof="'+i+'">원본 보기</button>' : '') + ' <button type="button" data-rotate="' + i + '">↻</button><button type="button" data-up="' + i + '">↑</button><button type="button" data-unlink="' + i + '">×</button><input aria-label="증빙 설명" data-caption="' + i + '" value="' + esc(e.locator) + '"></p>'; }).join('');
      box.querySelectorAll('[data-proof]').forEach(function(b){b.onclick=function(){viewProof(Number(b.dataset.proof));};});
      var linked = box.querySelector('[data-linked]'); linked.ondragover = function (event) { event.preventDefault(); }; linked.ondrop = function (event) { event.preventDefault(); var key = event.dataTransfer.getData('text/plain'); if (!data.sourceOptions.some(function (o) { return o.value === key; })) return; snapshot(); addSource(key); sources(); queueSave(); };
      box.querySelectorAll('[data-caption]').forEach(function (e) { e.oninput = function () { content.evidence[Number(e.dataset.caption)].locator = e.value; queueSave(); }; });
      box.querySelectorAll('[data-rotate]').forEach(function (e) { e.onclick = function () { snapshot(); var p = content.evidence[Number(e.dataset.rotate)], match = (p.note || '').match(/presentationRotation:(90|180|270)/); var rotation = ((match ? Number(match[1]) : 0) + 90) % 360; p.note = 'presentationRotation:' + rotation; u.toast('제출용 사진 회전 ' + rotation + '° · 원본 유지'); queueSave(); }; });
      box.querySelectorAll('[data-up]').forEach(function (e) { e.onclick = function () { var i = Number(e.dataset.up); if (!i) return; snapshot(); var a = content.evidence[i]; content.evidence[i] = content.evidence[i-1]; content.evidence[i-1] = a; sources(); queueSave(); }; });
      box.querySelectorAll('[data-unlink]').forEach(function (e) { e.onclick = function () { snapshot(); content.evidence.splice(Number(e.dataset.unlink), 1); sources(); queueSave(); }; });
    }
    function preparePeriod() {
      var button = box.querySelector('[data-period-prepare]'), target = box.querySelector('[data-packet-state]');
      button.disabled = true;
      save().then(function () { return call('api_prepareClaimEditor', [contractId, box.querySelector('[data-period]').value]); }).then(function (r) {
        var missing = r.submission.requirements.filter(function (x) { return x.missing; });
        target.innerHTML = '<p>회차 #' + esc(r.draft.applicationNo) + ' · ' + money(r.draft.thisPeriodAmount) + '</p>' +
          (missing.length ? '<p>보완: ' + missing.map(function (x) { return esc(x.label || x.name || x.key); }).join(' · ') + '</p>' : '') +
          '<button type="button" data-package>원청 엑셀 + 증빙 묶음 생성</button>';
        target.querySelector('[data-package]').onclick = function () {
          var b = this; b.disabled = true;
          call('api_prepareClaimSubmission', [r.draft.id, true]).then(function (p) { target.innerHTML = '<a href="' + esc(p.submission.downloadUrl) + '">제출 묶음 다운로드 · 버전 ' + esc(p.submission.revision) + '</a>'; }).catch(error).finally(function () { b.disabled = false; });
        };
      }).catch(error).finally(function () { button.disabled = false; });
    }
    async function uploadFiles() {
      var files = Array.from(this.files || []), input = this, statusEl = box.querySelector('[data-upload-state]');
      if (!files.length || uploading || conflict) return;
      var uploaded=input.claimUploaded || (input.claimUploaded=new Map());
      if (files.filter(function(f){return !uploaded.has(f);}).length + content.evidence.length > 20) { u.toast('연결 증빙은 20개까지 가능합니다.', 'error'); return; }
      uploading = true; box.inert=true; pendingFiles=files.map(function(f){return f.name;});preserve();
      var failures=[];
      try {
        for(var file of files){
          try {
            statusEl.textContent = file.name + ' 원본 저장 및 공정 연결 중…';
            var id=uploaded.get(file);
            if(!id){
              var fd = new FormData(); fd.append('files[]', file); fd.append('purpose','claim_evidence'); fd.append('site_id',siteId);fd.append('project_contract_id',contractId);
              if (data.contract.projectId) fd.append('project_id',data.contract.projectId);
              if (data.contract.companyId) fd.append('company_id',data.contract.companyId);
              var response=await fetch('/document-hub/api/upload',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''},body:fd});
              var r=await response.json().catch(function(){return {};});
              id = r.documents?.[0]?.id || r.duplicates?.[0]?.documentId;
              if (!response.ok || !id) throw new Error(r.failed?.[0]?.reason || r.message || '업로드 실패 · 원본 파일을 보관하세요');
              uploaded.set(file,id);
            }
            if (!data.sourceOptions.some(function(x){return x.value==='document:'+id;})) data.sourceOptions.push({value:'document:'+id,label:file.name});
            snapshot(); addSource('document:'+id); sources(); queueSave(); await save();
            pendingFiles=pendingFiles.filter(function(name){return name!==file.name;});preserve();
          }catch(e){failures.push(file.name+': '+e.message);error(e);}
        }
        statusEl.textContent=failures.length ? '완료 '+(files.length-failures.length)+'/'+files.length+' · '+failures.join(' / ') : '원본 저장·공정 연결 완료 · 검토 요청 전';
        if(failures.length){var retry=document.createElement('button');retry.type='button';retry.textContent='미완료 파일 다시 시도';retry.onclick=function(){uploadFiles.call(input);};statusEl.appendChild(retry);}
        else {input.value='';uploaded.clear();if(!dirty && backup)backup.clear();}
      }finally{uploading=false;box.inert=false;}
    }
    function setupStudio() {
      box.classList.add('ce-studio');
      box.querySelector('[data-save-now]').onclick=function(){save().catch(error);};
      box.addEventListener('keydown',function(e){if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'&&!e.target.closest('.dm-wrap')){e.preventDefault();save().catch(error);}});
    }
    call('api_getClaimEditor', [contractId]).then(function (r) {
      data = r; if (!data.lines.length) throw new Error('계약 공정을 먼저 등록하세요.');
      var m = u.modal({ beforeClose: function () { var dialogs=Array.from(document.querySelectorAll('[role=dialog]')); if(dialogs.at(-1)!==box.querySelector('[role=dialog]')) return false; if (uploading || committing || cadActive || navigating || writer.pending) { u.toast('저장·업로드가 끝날 때까지 기다려 주세요.'); return false; } if (Array.from(document.querySelectorAll('.dm-wrap')).some(function(e){return !box.contains(e);})) return false; if (dirty) { u.toast('저장하고 닫기를 눌러주세요.'); return false; } if (embedded && !embedded.beforeClose()) return false; if (embedded) embedded.close(true); closed = true; global.removeEventListener('beforeunload',unload); global.removeEventListener('online',online); document.removeEventListener('visibilitychange',visibility); clearTimeout(timer); clearTimeout(searchTimer); if(options.onClose) options.onClose(); return true; }, title: options.fullscreen ? '도면 · 자료' : '기성 편집', width: 1400, body: (options.fullscreen ? '<div class="ce-studio-toolbar"><strong data-record-title></strong><span data-save-state></span><button data-resolve hidden>두 초안 비교</button><button data-save-now>저장 · Ctrl+S</button></div><div class="ce-studio-layout"><div data-studio-drawing></div><div data-edit-body></div></div>' : '<div data-save-state></div><button data-resolve hidden>두 초안 비교</button><button data-save-now>초안 저장 · 다시 시도</button><div style="display:flex;gap:8px;align-items:center;margin-bottom:12px"><label>회차 마감일 <input data-period type="date" value="' + esc(data.contract.today) + '"></label><button type="button" data-period-prepare>이번 회차 준비</button><div data-packet-state></div></div><div data-edit-body></div>'), actions: [{ label: '저장하고 닫기', value: 'keep' }], onReady: function (el) { box = el; box.classList.add('ce-editor'); if(options.fullscreen) setupStudio(); box.querySelector('[data-resolve]').onclick=resolveConflict; box.querySelector('[data-save-now]').onclick=function(){save().catch(error);}; global.addEventListener('beforeunload',unload); global.addEventListener('online',online); document.addEventListener('visibilitychange',visibility); choose(options.lineId || data.lines[0].id); if(box.querySelector('[data-period-prepare]')) box.querySelector('[data-period-prepare]').onclick = preparePeriod; }, onAction: function () { save().then(function () { m.close(null); }).catch(error); } });
      modal = m;
    }).catch(error);
  }
  global.AdminClaimEditor = { open: open };
})(window);
