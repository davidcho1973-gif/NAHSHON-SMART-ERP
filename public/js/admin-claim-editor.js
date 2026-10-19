(function (global) {
  'use strict';
  function call(name, args) {
    return global.gsRun(name, args, null).then(function (r) {
      if (!r || r.success === false) throw new Error(r && r.error || '처리하지 못했습니다.');
      return r;
    });
  }
  function open(contractId, siteId, sheets) {
    if (!document.getElementById('ce-style')) { var style = document.createElement('style'); style.id = 'ce-style'; style.textContent = '.ce-editor{font:14px/1.5 system-ui,sans-serif}.ce-editor input,.ce-editor select,.ce-editor textarea{box-sizing:border-box;border:1px solid var(--border-default,#cbd5e1);border-radius:7px;padding:8px;background:var(--bg-surface,#fff);color:var(--text-primary,#123);max-width:100%;font:inherit}.ce-editor button{border:1px solid var(--border-default,#cbd5e1);border-radius:7px;padding:8px 12px;background:var(--bg-surface,#fff);color:var(--text-primary,#123);font:inherit;cursor:pointer}.ce-editor button:hover{border-color:#0ea5e9}.ce-editor button:disabled{opacity:.5}.ce-editor aside{max-height:65vh;overflow:auto;position:sticky;top:0}.ce-editor [data-edit-body]>div{align-items:start}.ce-editor section{padding:12px;border:1px solid var(--border-default,#cbd5e1);border-radius:10px}.ce-editor label{display:block;margin:8px 0}.ce-editor label input,.ce-editor label select,.ce-editor label textarea{display:block;width:100%}.ce-editor [data-commit],.ce-editor [data-period-prepare]{background:#086b9b;color:white}.ce-editor [data-total]{padding:12px;background:#eaf5ff;color:#145075;border-radius:8px;font-weight:700}.ce-editor [data-save-state]{color:#167755;margin-bottom:8px}.ce-editor h3,.ce-editor h4{margin:12px 0}.ce-editor [data-drawing-preview]{margin-top:12px;background:#f1f5f9;border-radius:8px}.ce-editor [data-calcs] input{min-width:0}@media(max-width:850px){.ce-editor [data-edit-body]>div{grid-template-columns:1fr!important}.ce-editor aside{max-height:160px;overflow:auto}}'; document.head.appendChild(style); }
    var u = global.AdminUI, esc = u.esc, box, data, line, content, revision = 0, timer, saving = Promise.resolve(), closed = false;
    var undo = [], redo = [], dirty = false, newWork = false, searchTimer, searchVersion = 0, activeSheetId = null;
    function error(e) { if (dirty) state('저장되지 않음 · 다시 시도하세요'); u.toast(e.message || '처리하지 못했습니다.', 'error'); }
    function money(v) { return Number(v || 0).toLocaleString('en-US', { style: 'currency', currency: data.contract.currency || 'USD' }); }
    function snapshot() { undo.push(JSON.stringify(content)); redo = []; }
    function total() {
      return content.calculations.length ? Math.round(content.calculations.reduce(function (s, r) { return s + Number(r.length) * Number(r.height) * Number(r.count) - Number(r.deduction); }, 0) * 10000) / 10000 : Number(content.quantity || 0);
    }
    function state(text) { if (box) box.querySelector('[data-save-state]').textContent = text; }
    function queueSave() {
      dirty = true; clearTimeout(timer); state('저장 대기');
      timer = setTimeout(function () { save().catch(error); }, 1200);
    }
    function save() {
      clearTimeout(timer);
      saving = saving.catch(function () {}).then(function () {
        if (!dirty || !line || closed) return;
        var sent = JSON.stringify(content), id = line.id;
        state('저장 중…');
        return call('api_saveClaimEditDraft', [{ lineId: id, revision: revision, content: JSON.parse(sent), newWork: newWork }]).then(function (r) {
          if (!line || line.id !== id) return;
          revision = r.draft.revision; newWork = false;
          var index = data.drafts.findIndex(function (d) { return d.lineId === id; });
          if (index < 0) data.drafts.push(r.draft); else data.drafts[index] = r.draft;
          dirty = JSON.stringify(content) !== sent;
          if (!dirty) content = JSON.parse(JSON.stringify(r.draft.content));
          else content.evidence.forEach(function (e) { var saved = r.draft.content.evidence.find(function (p) { return p.type === e.type && p.id === e.id; }); if (saved) e.sha256 = saved.sha256; });
          state(dirty ? '저장 대기' : '저장됨');
        });
      });
      return saving;
    }
    function choose(id) {
      return save().then(function () { if (dirty) return save(); }).then(function () {
        searchVersion++; clearTimeout(searchTimer);
        line = data.lines.find(function (l) { return l.id === Number(id); });
        var draft = data.drafts.find(function (d) { return d.lineId === line.id; });
        revision = draft ? draft.revision : 0;
        content = draft ? JSON.parse(JSON.stringify(draft.content)) : { workDate: data.contract.today, location: '', stage: line.recognitionBasis === 'milestone' ? Object.keys(line.stageWeights || {})[0] : 'installed', quantity: 0, calculations: [], evidence: [], marks: [], notes: '' };
        var preferred = (sheets || []).find(function (s) { return (s.sectionIds || []).includes(line.workSectionId); }) || (sheets || [])[0]; activeSheetId = preferred ? preferred.id : null;
        undo = []; redo = []; dirty = false; newWork = false; render();
        if (draft && draft.recordId) state('기록 완료 #' + draft.recordId);
      }).catch(error);
    }
    function render() {
      var stages = line.recognitionBasis === 'milestone' ? Object.keys(line.stageWeights || {}) : ['installed'];
      var records = data.records.filter(function (r) { return r.lineId === line.id; });
      box.querySelector('[data-edit-body]').innerHTML = '<div style="display:grid;grid-template-columns:minmax(150px,1fr) minmax(250px,3fr) minmax(240px,2fr);gap:12px;overflow:auto">' +
        '<aside><input data-line-search placeholder="공정명·번호 검색" style="width:100%;position:sticky;top:0;margin-bottom:8px">' + data.lines.map(function (l) { return '<button type="button" data-line="' + l.id + '" style="display:block;width:100%;padding:12px;text-align:left;margin-bottom:6px">#' + esc(l.lineNo) + ' ' + esc(l.description) + '<br><small>미배정 ' + money(l.availableAmount) + '<br>' + esc(l.nextAction || '') + '</small></button>'; }).join('') + '</aside>' +
        '<section><select data-sheet style="width:100%">' + (sheets || []).map(function (s) { return '<option value="' + s.id + '">' + esc(s.sheetNo + ' · ' + s.title) + '</option>'; }).join('') + '</select><button type="button" data-drawing>도면 크게 열기 · 표시</button><div data-drawing-preview style="height:300px;overflow:auto"></div>' +
        '<h4>산출표 · ' + esc(line.unit) + '</h4><div style="display:flex;gap:4px;color:#64748b;font-size:12px"><span style="width:18%">위치</span><span style="width:18%">길이</span><span style="width:18%">높이</span><span style="width:18%">개수</span><span style="width:18%">공제</span></div><div data-calcs></div><button type="button" data-add>+ 산출 행</button><p data-total></p>' +
        '<details><summary>AI에게 질문</summary><textarea data-question rows="3" placeholder="선택한 공정의 도면 근거와 부족한 자료를 설명해줘" style="width:100%"></textarea><button type="button" data-ask>질문하기</button><div data-answer></div></details></section>' +
        '<section><h3>#' + esc(line.lineNo) + ' ' + esc(line.description) + '</h3><p>계약 ' + esc(line.contractQty) + ' ' + esc(line.unit) + '</p><p>확인 금액 ' + money(line.verifiedAmount) + '<br>기존 회차 배정 ' + money(line.allocatedAmount) + '<br>미배정 확인액 ' + money(line.availableAmount) + '<br>잔여 계약액 ' + money(line.remainingContractAmount) + '</p>' +
        '<label>작업일<input data-field="workDate" type="date" value="' + esc(content.workDate) + '"></label><br><label>위치<input data-field="location" value="' + esc(content.location) + '"></label><br><label>단계<select data-field="stage">' + stages.map(function (s) { return '<option' + (s === content.stage ? ' selected' : '') + ' value="' + esc(s) + '">' + esc({ installed: '설치', installation: '설치', stored: '자재 반입' }[s] || s) + '</option>'; }).join('') + '</select></label><br><label>산출표가 없을 때 직접 수량<input data-field="quantity" type="number" min="0" step="any" value="' + esc(content.quantity) + '"></label>' +
        '<p>' + esc((line.blockers || []).join(' · ')) + '</p><details><summary>공정 필수자료 설정</summary><div data-requirements></div><button type="button" data-rule-add>+ 필수자료</button><button type="button" data-rule-save>조건 저장</button></details><h4>기존 자료 연결</h4><input data-search placeholder="자료 검색"><div data-sources style="max-height:200px;overflow:auto"></div><div data-linked></div>' +
        '<label>메모<textarea data-field="notes" rows="3">' + esc(content.notes) + '</textarea></label><p><button type="button" data-undo>되돌리기</button> <button type="button" data-redo>다시 실행</button></p><button type="button" data-commit>확인 대기로 기록</button><button type="button" data-review>기존 기록 · 확인</button><button type="button" data-new>새 작업</button>' +
        '<details><summary>초안 수정 이력</summary><div data-history></div></details><details><summary>작업 이력 ' + records.length + '건</summary>' + records.map(function (r) { return '<p>' + (r.status === 'pending' && r.recordKind === 'actual' ? '<input type="checkbox" data-record-select value="' + r.id + '"> ' : '') + esc(r.workDate + ' · ' + r.location + ' · ' + r.reportedQty + ' ' + line.unit + ' · ' + r.status) + '</p>'; }).join('') + '<button type="button" data-bulk>선택 기록에 위 작업일·위치 적용</button></details></section></div>';
      box.querySelector('[data-line-search]').oninput = function () { var query = this.value.toLowerCase(); box.querySelectorAll('[data-line]').forEach(function (b) { b.hidden = !b.textContent.toLowerCase().includes(query); b.style.display = b.hidden ? 'none' : 'block'; }); };
      box.querySelectorAll('[data-line]').forEach(function (b) { b.onclick = function () { choose(b.dataset.line); }; });
      box.querySelectorAll('[data-field]').forEach(function (e) { e.oninput = function () { snapshot(); content[e.dataset.field] = e.value; calculations(); queueSave(); }; });
      box.querySelector('[data-add]').onclick = function () { snapshot(); content.calculations.push({ label: '', length: 0, height: 1, count: 1, deduction: 0 }); calculations(); queueSave(); };
      box.querySelector('[data-search]').oninput = function () { sources(); clearTimeout(searchTimer); var query = this.value, version = ++searchVersion; searchTimer = setTimeout(function () { call('api_getClaimEvidenceSources', [contractId, query]).then(function (r) { if (closed || version !== searchVersion) return; r.sourceOptions.forEach(function (source) { if (!data.sourceOptions.some(function (s) { return s.value === source.value; })) data.sourceOptions.push(source); }); sources(); }).catch(error); }, 350); };
      box.querySelector('[data-undo]').onclick = function () { if (!undo.length) return; redo.push(JSON.stringify(content)); content = JSON.parse(undo.pop()); render(); queueSave(); };
      box.querySelector('[data-redo]').onclick = function () { if (!redo.length) return; undo.push(JSON.stringify(content)); content = JSON.parse(redo.pop()); render(); queueSave(); };
      box.querySelector('[data-new]').onclick = function () { if (!global.confirm('새 작업 초안을 시작할까요? 기존 시공 기록은 보존됩니다.')) return; newWork = true; snapshot(); content = { workDate: data.contract.today, location: '', stage: line.recognitionBasis === 'milestone' ? (Object.keys(line.stageWeights || {}).includes('installation') ? 'installation' : Object.keys(line.stageWeights || {})[0]) : 'installed', quantity: 0, calculations: [], evidence: [], marks: [], notes: '' }; render(); queueSave(); };
      box.querySelector('[data-review]').onclick = function () { save().then(function () { global.AdminClaimEvidence.open(contractId, line.id); }).catch(error); };
      box.querySelector('[data-commit]').onclick = function () { var b = this; b.disabled = true; save().then(function () { return call('api_commitClaimEditDraft', [{ lineId: line.id, revision: revision }]); }).then(function (r) { state('확인 대기 기록 #' + r.id); u.toast('기록했습니다. 담당자 확인 후 기성에 포함됩니다.'); return call('api_getClaimEditor', [contractId]).then(function (fresh) { data = fresh; render(); state('확인 대기 기록 #' + r.id); }); }).catch(error).finally(function () { b.disabled = false; }); };
      if (activeSheetId !== null) box.querySelector('[data-sheet]').value = String(activeSheetId);
      box.querySelector('[data-sheet]').onchange = function () { activeSheetId = Number(this.value); preview(); };
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
    function preview() { var s = selectedSheet(), p = box.querySelector('[data-drawing-preview]'); p.innerHTML = s && s.thumbUrl ? '<img alt="도면 미리보기" src="' + esc(s.thumbUrl) + '" style="max-width:100%">' : '도면을 선택하세요.'; }
    function calculations() {
      box.querySelector('[data-calcs]').innerHTML = content.calculations.map(function (r, i) { return '<div style="display:flex;gap:4px;margin:6px 0">' + ['label', 'length', 'height', 'count', 'deduction'].map(function (k) { return '<input aria-label="' + esc({label:'위치',length:'길이',height:'높이',count:'개수',deduction:'공제'}[k]) + '" title="' + esc({label:'위치',length:'길이',height:'높이',count:'개수',deduction:'공제'}[k]) + '" data-row="' + i + '" data-key="' + k + '" style="width:18%" type="' + (k === 'label' ? 'text' : 'number') + '" value="' + esc(r[k]) + '">'; }).join('') + '<button type="button" data-delete-row="' + i + '">×</button></div>'; }).join('');
      box.querySelectorAll('[data-row]').forEach(function (e) { e.oninput = function () { snapshot(); content.calculations[Number(e.dataset.row)][e.dataset.key] = e.dataset.key === 'label' ? e.value : Number(e.value); updateTotal(); queueSave(); }; });
      box.querySelectorAll('[data-delete-row]').forEach(function (e) { e.onclick = function () { snapshot(); content.calculations.splice(Number(e.dataset.deleteRow), 1); calculations(); queueSave(); }; });
      updateTotal();
    }
    function updateTotal() {
      var weight = line.recognitionBasis === 'milestone' ? Number((line.stageWeights || {})[content.stage] || 0) / 100 : 1;
      box.querySelector('[data-total]').textContent = '길이 × 높이 × 개수 − 공제 = ' + total() + ' ' + line.unit + ' · 확인 시 예상 ' + money(total() * line.unitPrice * weight);
    }
    function addSource(key) { var p = key.split(':'); if (!content.evidence.some(function (e) { return e.type === p[0] && e.id === Number(p[1]); })) content.evidence.push({ type: p[0], id: Number(p[1]), locator: '전체' }); }
    function sources() {
      var q = box.querySelector('[data-search]').value.toLowerCase();
      box.querySelector('[data-sources]').innerHTML = data.sourceOptions.filter(function (o) { return o.label.toLowerCase().includes(q); }).map(function (o) { return '<button draggable="true" type="button" data-source="' + esc(o.value) + '" style="display:block;text-align:left">+ ' + esc(o.label) + '</button>'; }).join('');
      box.querySelectorAll('[data-source]').forEach(function (e) { e.ondragstart = function (event) { event.dataTransfer.setData('text/plain', e.dataset.source); }; e.onclick = function () { snapshot(); addSource(e.dataset.source); sources(); queueSave(); }; });
      box.querySelector('[data-linked]').innerHTML = content.evidence.map(function (e, i) { var o = data.sourceOptions.find(function (o) { return o.value === e.type + ':' + e.id; }); return '<p>' + esc(o ? o.label : '자료 확인 필요') + ' <button type="button" data-rotate="' + i + '">↻</button><button type="button" data-up="' + i + '">↑</button><button type="button" data-unlink="' + i + '">×</button><input aria-label="증빙 설명" data-caption="' + i + '" value="' + esc(e.locator) + '"></p>'; }).join('');
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
    call('api_getClaimEditor', [contractId]).then(function (r) {
      data = r; if (!data.lines.length) throw new Error('계약 공정을 먼저 등록하세요.');
      var m = u.modal({ beforeClose: function () { if (document.querySelector('.dm-wrap')) return false; if (dirty) { u.toast('저장하고 닫기를 눌러주세요.'); return false; } closed = true; clearTimeout(timer); return true; }, title: '기성 편집', width: 1400, body: '<div data-save-state></div><div style="display:flex;gap:8px;align-items:center;margin-bottom:12px"><label>회차 마감일 <input data-period type="date" value="' + esc(data.contract.today) + '"></label><button type="button" data-period-prepare>이번 회차 준비</button><div data-packet-state></div></div><div data-edit-body></div>', actions: [{ label: '저장하고 닫기', value: 'keep' }], onReady: function (el) { box = el; box.classList.add('ce-editor'); choose(data.lines[0].id); box.querySelector('[data-period-prepare]').onclick = preparePeriod; }, onAction: function () { save().then(function () { closed = true; clearTimeout(timer); m.close(null); }).catch(error); } });
    }).catch(error);
  }
  global.AdminClaimEditor = { open: open };
})(window);
