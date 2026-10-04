(function () {
  'use strict';
  const P=window.PurchaseRequests,config=window.purchaseRequestConfig || {},$=id=>document.getElementById(id);
  let lines=[],files=[],requestKey=P.uuid(),submittedId=null,recorder=null,stream=null,recordTimer=null,recordingActive=false,busy=false,analysisJobId=null,analyzedFile=null;
  const pendingKey='purchase-analysis-'+config.userId;
  const e=P.esc;
  function message(id,text,error) { const el=$(id);el.textContent=text || '';el.classList.toggle('pr-error',Boolean(error)); }
  function fileList() { $('pr-file-list').textContent=files.map(f=>f.name).join(' · ')+(files.length>1?' — 첫 파일 분석 · 전체 파일 첨부':''); }
  function renderLines() {
    $('pr-draft').hidden=false;
    $('pr-edit-lines').innerHTML=lines.map((l,i)=>'<div class="pr-edit-line" data-line="'+i+'"><div class="pr-edit-line-head"><strong>품목 '+(i+1)+'</strong><button type="button" data-remove="'+i+'">삭제</button></div><label>품목명<input data-field="name" required maxlength="240" value="'+e(l.name || '')+'"></label><div class="pr-pair"><label>수량<input data-field="quantity" type="number" min="0.001" step="any" placeholder="모르면 비워두세요" value="'+e(l.quantity ?? '')+'"></label><label>단위<input data-field="unit" maxlength="40" placeholder="개, 박스, ft" value="'+e(l.unit || '')+'"></label></div><label>규격 · 용도<input data-field="specification" maxlength="2000" value="'+e(l.specification || '')+'"></label><details><summary>제품 링크</summary><label><input data-field="product_url" type="url" value="'+e(l.product_url || '')+'"></label></details></div>').join('');
  }
  function readLines() { return Array.from($('pr-edit-lines').querySelectorAll('[data-line]')).map(row=>Object.fromEntries(Array.from(row.querySelectorAll('[data-field]')).map(input=>[input.dataset.field,input.dataset.field==='quantity'?(input.value.trim()===''?null:Number(input.value)):input.value.trim()]))); }
  function appendLine(line) { if(lines.length)lines=readLines();lines.push(line || {name:'',quantity:'',unit:'',specification:'',product_url:$('pr-product-link').value});renderLines(); }
  function adopt(result) {
    lines=(result.lines || []).map(l=>({name:l.name || '',quantity:l.quantity ?? '',unit:l.unit || '',specification:l.specification || '',product_url:l.product_url || ''}));
    if(!lines.length)lines=[{name:'',quantity:'',unit:'',specification:result.note || '',product_url:$('pr-product-link').value}];
    if(result.need_by)$('pr-need-by').value=result.need_by;
    $('pr-note').value=result.note || $('pr-input').value;
    $('pr-questions').innerHTML=(result.questions || []).map(q=>'<p>'+e(q)+'</p>').join('');$('pr-conversation').hidden=!(result.questions || []).length;
    renderLines();message('pr-analysis-message','정리된 내용을 확인하세요. 모르는 조건은 사무실에서 확인할 수 있습니다.');
  }
  function showCandidates(result) {
    const rows=result.candidates || [];const host=$('pr-candidates');host.hidden=false;
    host.innerHTML='<h3>제품 후보</h3>'+(rows.length?rows.map((c,i)=>'<div class="pr-request-card"><strong>'+e(c.product || c.name)+'</strong><p class="pr-help">'+e(c.maker || '')+' '+e(c.why || '')+'</p><div class="pr-actions">'+(P.safeUrl(c.url)?'<a href="'+e(P.safeUrl(c.url))+'" target="_blank" rel="noopener">제품 보기 ↗</a>':'')+'<button type="button" data-candidate="'+i+'">이 제품 선택</button></div></div>').join(''):'<p>확인된 후보가 없습니다. 규격이나 용도를 더 적어주세요.</p>');
    host.querySelectorAll('[data-candidate]').forEach(b=>b.onclick=()=>{const c=rows[Number(b.dataset.candidate)];appendLine({name:c.product || c.name,quantity:'',unit:'',specification:c.maker || '',product_url:P.safeUrl(c.url)});host.hidden=true;});
  }
  async function poll(job,mode,site) {
    for(let n=0;n<120;n++) {
      await new Promise(resolve=>setTimeout(resolve,2500));
      const r=await P.api('/analysis/'+job);
      if(r.status==='failed'){localStorage.removeItem(pendingKey);throw new Error(r.error || '분석하지 못했습니다. 직접 입력할 수 있습니다.');}
      if(r.done || ['completed','complete','done','succeeded'].includes(r.status)) {
        localStorage.removeItem(pendingKey);if(site)$('pr-request-site').value=String(site);
        if(mode==='search'){showCandidates(r.result || {});message('pr-analysis-message','후보를 확인하세요.');}else {analysisJobId=job;adopt(r.result || {});}return;
      }
    }
    message('pr-analysis-message','분석이 계속되고 있습니다. 다시 열면 이어서 확인합니다.');
  }
  async function analyze(mode) {
    if(recordingActive){message('pr-analysis-message','녹음 종료 후 보내주세요.',true);return;}
    if(busy)return;const site=$('pr-request-site').value;if(!site){message('pr-analysis-message','현장을 선택하세요.',true);return;}
    const text=[$('pr-input').value,$('pr-product-link').value].filter(Boolean).join('\n');
    if(!text.trim()&&!files.length){message('pr-analysis-message','내용을 적거나 파일을 선택하세요.',true);return;}
    busy=true;$('pr-analyze').disabled=true;$('pr-search').disabled=true;message('pr-analysis-message','분석 중…');
    try {
      const fd=new FormData();fd.append('site_id',site);fd.append('mode',mode);fd.append('text',text);fd.append('request_key',P.uuid());if(files[0])fd.append('file',files[0]);
      if(mode==='request')analyzedFile=files[0] || null;
      const start=await P.api('/analyze','POST',fd);
      if(start.result){mode==='search'?showCandidates(start.result):adopt(start.result);return;}
      localStorage.setItem(pendingKey,JSON.stringify({job:start.job_id,mode,site}));
      await poll(start.job_id,mode,site);
    }catch(err){message('pr-analysis-message',err.message,true);}finally{busy=false;$('pr-analyze').disabled=false;$('pr-search').disabled=false;}
  }
  async function load() {
    const res=await P.api('');
    const previous=$('pr-request-site').value;
    $('pr-request-site').innerHTML='<option value="">선택하세요</option>'+(res.sites || []).map(s=>'<option value="'+s.id+'">'+e(s.name || s.code)+'</option>').join('');
    if(previous)$('pr-request-site').value=previous;else if(res.sites?.length===1)$('pr-request-site').value=res.sites[0].id;
    $('pr-fields').disabled=!res.can_request || !res.sites?.length;
    $('pr-my-requests').innerHTML=(res.rows || []).length?(res.rows || []).map(r=>'<div class="pr-request-card"><button type="button" data-open="'+r.id+'"><span><strong>'+e(r.lines?.[0]?.name || '구매 요청')+(r.lines?.length>1?' 외 '+(r.lines.length-1)+'종':'')+'</strong><small>'+e(r.site_name)+' · 필요 '+e(r.need_by || '미정')+'</small><small>도착 '+e(r.eta || '미정')+'</small></span><span class="pr-status">'+e(r.status_label || P.labels[r.status])+'</span></button></div>').join(''):'<p class="pr-help">아직 요청한 품목이 없습니다.</p>';
    $('pr-my-requests').querySelectorAll('[data-open]').forEach(b=>b.onclick=()=>open(Number(b.dataset.open)));
  }
  async function open(id) {
    try {
      const r=(await P.api('/'+id)).request,host=$('pr-mobile-detail');host.hidden=false;
      host.innerHTML='<div class="pr-toolbar"><h2>'+e(r.status_label || P.labels[r.status])+'</h2><button type="button" id="pr-close-detail">닫기</button></div>'+P.details(r)+
        ((r.actions || []).includes('clarify')?'<form id="pr-clarify" class="pr-intake"><label>추가 내용<textarea name="note" placeholder="요청받은 규격 · 수량 등을 적어주세요."></textarea></label><label>사진 · 파일<input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp"></label><button type="submit" class="pr-primary">보완해서 보내기</button><p role="status" class="pr-error"></p></form>':'');
      $('pr-close-detail').onclick=()=>host.hidden=true;
      const clarifyKey=P.uuid();
      let clarificationPhotoAttached=false;
      if($('pr-clarify'))$('pr-clarify').onsubmit=async ev=>{
        ev.preventDefault();const form=ev.currentTarget,btn=form.querySelector('button');btn.disabled=true;
        try{let latest=(await P.api('/'+id)).request;const f=form.elements.file.files[0];if(f){const uploaded=await P.upload(id,f,'request');latest=uploaded.request || (await P.api('/'+id)).request;form.elements.file.value='';clarificationPhotoAttached=true;}
          const note=form.elements.note.value.trim() || (clarificationPhotoAttached?'자료를 첨부했습니다.':'');if(!note)throw new Error('추가 내용이나 파일을 넣어주세요.');
          await P.api('/'+id+'/action','POST',{action:'clarify',version:latest.version,note,request_key:clarifyKey});await load();await open(id);
        }catch(err){form.querySelector('[role="status"]').textContent=err.message;}finally{btn.disabled=false;}
      };
      host.scrollIntoView({behavior:'smooth',block:'start'});
    }catch(err){message('pr-save-message',err.message,true);}
  }
  async function submit(ev) {
    ev.preventDefault();if(recordingActive){message('pr-save-message','녹음 종료 후 보내주세요.',true);return;}if(busy)return;lines=readLines();if(!lines.length){message('pr-save-message','품목을 추가하세요.',true);return;}
    busy=true;$('pr-fields').disabled=true;message('pr-save-message','보내는 중…');
    try {
      if(!submittedId){const r=await P.api('','POST',{request_key:requestKey,analysis_job_id:analysisJobId,site_id:Number($('pr-request-site').value),need_by:$('pr-need-by').value || null,note:$('pr-note').value || $('pr-input').value,lines});submittedId=r.request?.id || r.id;if(!submittedId)throw new Error('요청 번호를 받지 못했습니다.');if(analysisJobId&&analyzedFile)files=files.filter(f=>f!==analyzedFile);}
      while(files.length){await P.upload(submittedId,files[0],'request');files.shift();fileList();}
      const id=submittedId;submittedId=null;requestKey=P.uuid();analysisJobId=null;analyzedFile=null;lines=[];$('pr-request-form').reset();$('pr-draft').hidden=true;$('pr-candidates').hidden=true;fileList();message('pr-analysis-message','');message('pr-save-message','요청을 보냈습니다.');await load();await open(id);
    }catch(err){message('pr-save-message',(submittedId?'요청은 저장되었습니다. 첨부 전송을 완료하려면 다시 보내세요. ':'')+err.message,true);}finally{busy=false;$('pr-fields').disabled=false;}
  }
  function stopTracks() { clearTimeout(recordTimer);recordTimer=null;stream?.getTracks().forEach(t=>t.stop());stream=null; }
  $('pr-record').onclick=async()=>{
    if(recorder?.state==='recording'){recorder.stop();return;}
    if(recordingActive)return;
    if(!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder){message('pr-analysis-message','이 브라우저는 녹음을 지원하지 않습니다. 녹음 파일을 첨부해 주세요.',true);return;}
    try {
      recordingActive=true;
      stream=await navigator.mediaDevices.getUserMedia({audio:true});recorder=new MediaRecorder(stream);const chunks=[];let bytes=0,recordingFailed=false;
      recorder.ondataavailable=ev=>{if(ev.data.size){bytes+=ev.data.size;if(bytes<=15*1024*1024)chunks.push(ev.data);else{recordingFailed=true;if(recorder.state==='recording')recorder.stop();}}};
      recorder.onerror=()=>{recordingFailed=true;recordingActive=false;stopTracks();$('pr-record').textContent='말로 입력';message('pr-analysis-message','녹음하지 못했습니다. 다시 시도해 주세요.',true);if(recorder.state==='recording')recorder.stop();};
      recorder.onstop=()=>{stopTracks();recordingActive=false;$('pr-record').textContent='말로 입력';if(recordingFailed||!bytes){message('pr-analysis-message',bytes>15*1024*1024?'녹음은 15MB까지 가능합니다. 짧게 다시 녹음해 주세요.':'녹음하지 못했습니다. 다시 시도해 주세요.',true);return;}const type=recorder.mimeType || 'audio/webm';files.push(new File(chunks,'voice-request.'+(type.includes('mp4')?'m4a':'webm'),{type}));fileList();message('pr-analysis-message','녹음되었습니다. 정리하기를 눌러주세요.');};
      recorder.start(1000);recordTimer=setTimeout(()=>{if(recorder?.state==='recording')recorder.stop();},5*60*1000);$('pr-record').textContent='녹음 종료';message('pr-analysis-message','녹음 중… 최대 5분');
    }catch(err){recordingActive=false;stopTracks();message('pr-analysis-message','마이크를 사용할 수 없습니다. 브라우저의 마이크 권한을 확인하세요.',true);}
  };
  window.addEventListener('pagehide',()=>{if(recorder?.state==='recording')recorder.stop();stopTracks();});
  $('pr-file-button').onclick=()=>$('pr-source-files').click();
  $('pr-source-files').onchange=ev=>{const added=Array.from(ev.target.files);files.push(...added);fileList();const photo=added.find(f=>f.type.startsWith('image/'));if(photo)window.PurchaseImageMarker.open(photo,$('pr-image-editor'),marked=>{files.unshift(marked);fileList();message('pr-analysis-message','표시 이미지로 분석합니다. 원본도 함께 첨부됩니다.');});ev.target.value='';};
  $('pr-link-button').onclick=()=>{$('pr-link-field').hidden=false;$('pr-product-link').focus();};
  $('pr-answer-send').onclick=()=>{const answer=$('pr-answer').value.trim();if(!answer)return;$('pr-input').value+='\n추가 답변: '+answer;$('pr-answer').value='';analyze('request');};
  $('pr-defer').onclick=()=>{$('pr-note').value+='\n담당자 확인 필요: '+$('pr-questions').textContent;$('pr-conversation').hidden=true;message('pr-analysis-message','미확인 조건을 사무실에 전달합니다. 요청을 보내세요.');};
  $('pr-analyze').onclick=()=>analyze('request');$('pr-search').onclick=()=>analyze('search');
  $('pr-manual').onclick=()=>{if(!lines.length)appendLine({name:$('pr-input').value.slice(0,240),specification:'',quantity:'',unit:'',product_url:$('pr-product-link').value});else $('pr-draft').hidden=false;};
  $('pr-add-line').onclick=()=>appendLine();
  $('pr-edit-lines').onclick=ev=>{const b=ev.target.closest('[data-remove]');if(b){lines=readLines();lines.splice(Number(b.dataset.remove),1);renderLines();}};
  $('pr-request-form').onsubmit=submit;
  $('pr-refresh').onclick=()=>load().catch(err=>message('pr-save-message',err.message,true));
  load().then(async()=>{
    let pending=null;try{pending=JSON.parse(localStorage.getItem(pendingKey));}catch(_){}
    if(pending?.job){message('pr-analysis-message','이전 분석을 확인 중…');try{await poll(pending.job,pending.mode,pending.site);}catch(err){message('pr-analysis-message',err.message,true);}}
    if(config.initialId)await open(config.initialId);
  }).catch(err=>{message('pr-save-message',err.message,true);$('pr-fields').disabled=true;});
})();
