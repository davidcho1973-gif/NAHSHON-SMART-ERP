(function (w) {
  'use strict';
  const root = '/ops-api/meetings';
  const labels = { uploading: '업로드 대기', queued: '서버 처리 대기', transcribing: '두 엔진 받아쓰기', analyzing: 'ERP 대조·판단 중', review: '확인 필요', completed: '처리 완료', failed: '재시도 필요' };
  const kinds = { lookup: '자재·납기 조회', progress: '공정 진척', plan: '내일 작업·인원 계획', procurement: '조달 변경', labor: '실제 출역 보고', inspection: '검사 일정', request: '팀별 요청', issue: '이슈·선행조건', expense: '지출 확인', decision: '의사결정' };
  let generation = 0, poll, data, current, file, busy = false, recorder, stream, recordingAt, clock, actor = 0;
  let draft = {};
  function rememberForm() {
    ['date','title','people'].forEach(k => { const e = document.getElementById('meeting-' + k); if (e) draft[k] = e.value; });
  }
  const ui = () => w.AdminUI;
  const esc = v => ui().esc(v);
  const button = (label, action, arg = '') => '<button type="button" class="btn-secondary" data-meeting-action="' + action + '" data-id="' + esc(arg) + '">' + esc(label) + '</button>';
  const notice = (s, kind) => ui().notice(s, kind);
  async function api(path = '', method = 'GET', body) {
    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' };
    if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    const r = await fetch(root + path, { method, headers, credentials: 'same-origin', body: body instanceof FormData ? body : body ? JSON.stringify(body) : undefined });
    let d; try { d = await r.json(); } catch (_) { throw Error('서버 응답을 확인할 수 없습니다. 저장 여부를 조회한 후 다시 시도하세요.'); }
    if (!r.ok || d.success === false) throw Error(d.error || Object.values(d.errors || {}).flat().join(' ') || d.message || '처리하지 못했습니다.');
    return d;
  }
  function box(title, html) { return '<section class="panel" style="margin-bottom:16px"><div class="panel-header"><h3 class="panel-title">' + esc(title) + '</h3></div><div class="panel-body padded">' + html + '</div></section>'; }
  function host() { return document.getElementById('meeting-desk'); }
  function active() { return w._currentView === 'meetings' && host(); }
  function day() { const d = new Date(); return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-'); }
  async function localFile(mode, value) {
    if (!w.indexedDB || !actor) return null;
    return new Promise((resolve, reject) => {
      const o = indexedDB.open('erp-meeting-upload', 1);
      o.onupgradeneeded = () => o.result.createObjectStore('files');
      o.onerror = () => reject(Error('기기의 임시 저장 공간을 사용할 수 없습니다.'));
      o.onsuccess = () => {
        const db = o.result, tx = db.transaction('files', mode === 'get' ? 'readonly' : 'readwrite'), s = tx.objectStore('files');
        const r = mode === 'get' ? s.get(actor) : mode === 'delete' ? s.delete(actor) : s.put(value, actor);
        let answer; r.onsuccess = () => { answer = r.result; };
        tx.oncomplete = () => { db.close(); resolve(answer); }; tx.onerror = () => { db.close(); reject(Error('녹음 임시 저장에 실패했습니다. 파일을 내려받아 보관해 주세요.')); };
      };
    });
  }
  async function render() {
    const g = ++generation; clearTimeout(poll);
    const pc = document.getElementById('page-container');
    pc.innerHTML = '<div id="meeting-desk">불러오는 중…</div>';
    try {
      const sid = w.SITE_DB_IDS?.[w.currentSiteId] || (/^\d+$/.test(w.currentSiteId) ? w.currentSiteId : '');
      data = await api(sid ? '?site_id=' + sid : '');
      if (g !== generation || !active()) return;
      actor = data.actor_id;
      if (!file) { const saved = await localFile('get'); if (saved?.blob) file = saved.blob; }
      const params = new URL(location.href).searchParams;
      const id = params.get('detail') === 'meeting' ? Number(params.get('code')) : 0;
      if (id) return detail(id);
      home();
    } catch (e) { if (active()) host().innerHTML = notice(e.message, 'danger'); }
  }
  function home() {
    current = null;
    const options = data.sites.map(s => '<option value="' + s.id + '"' + (s.id === data.site_id ? ' selected' : '') + '>' + esc(s.name) + '</option>').join('');
    host().innerHTML = ui().pageHeader('공정미팅', '15–20분 회의 → 기존 자재·공정 대조 → 업무 연결 → 필요한 항목만 확인', button('상황실', 'ops') + button('새로고침', 'refresh')) +
      ((!data.providers.gemini || !data.providers.scribe) ? notice('API 연결 필요: ' + (!data.providers.gemini ? 'Gemini ' : '') + (!data.providers.scribe ? 'ElevenLabs ' : '') + '· 녹음은 보관할 수 있지만 두 엔진 분석은 키 연결 후 사용할 수 있습니다.', 'danger') : notice('키 설정 확인됨 · 실제 모델 접근·정확도는 녹음 분석으로 확인합니다.')) +
      box('1 · 회의 기록', '<div class="meeting-form"><label>현장<select id="meeting-site">' + options + '</select></label><label>회의일<input id="meeting-date" type="date" value="' + esc(draft.date || day()) + '"></label><label>회의 제목<input id="meeting-title" maxlength="160" value="' + esc(draft.title ?? '일일 공정미팅') + '"></label><label>참석자 · 팀<input id="meeting-people" value="' + esc(draft.people || '') + '" placeholder="이름 또는 팀명, 쉼표로 구분"></label></div>' +
        '<p class="meeting-muted">참석자에게 녹음을 알리고 시작해 주세요. 최대 30분·64MB. 녹음 중에는 이 화면을 유지하세요.</p><div class="meeting-buttons">' + button(recorder?.state === 'recording' ? '녹음 종료' : '녹음 시작', 'record') + '<span id="meeting-clock"></span><label class="btn-secondary">녹음 파일 선택<input id="meeting-file" type="file" accept="audio/*,.webm,.m4a" style="max-width:230px"></label>' + button('녹음 내려받기', 'download') + '</div><p id="meeting-file-info">' + (file ? '녹음 준비됨 · ' + (file.size / 1048576).toFixed(1) + 'MB' : '회의를 녹음하거나 기존 녹음 파일을 선택하세요.') + '</p>' +
        '<div class="meeting-buttons">' + button('업로드하고 분석 시작', 'upload') + '<span id="meeting-upload-status"></span></div><p class="meeting-muted">서버 저장 완료 표시 후에는 앱을 닫아도 계속 처리됩니다. 업로드가 끊기면 같은 파일로 이어 올립니다.</p>') +
      box('2 · 회의별 처리 현황', data.meetings.length ? data.meetings.map(m => '<div class="meeting-row"><div><strong>' + esc(m.title) + '</strong><div class="meeting-muted">' + esc(String(m.meeting_on).slice(0, 10)) + ' · ' + esc(labels[m.status] || m.status) + '</div></div>' + button('열기', 'open', m.id) + '</div>').join('') : '<p>등록된 회의가 없습니다.</p>') +
      box('다음 회의에서 확인할 미완료 업무', data.next_meeting_tasks.length ? data.next_meeting_tasks.map(t => '<div class="meeting-row"><div>' + (t.is_blocker ? '<strong>선행조건 · </strong>' : '') + esc(t.title) + '<div class="meeting-muted">' + esc(t.assignee || '담당자 미지정') + ' · ' + esc(t.due_on ? String(t.due_on).slice(0, 10) : '기한 미정') + '</div></div></div>').join('') : '<p>미완료 업무가 없습니다.</p>');
    document.getElementById('meeting-file').onchange = async e => {
      file = e.target.files[0]; if (!file) return;
      if (file.size > 64 * 1048576) { file = null; return ui().toast('녹음은 64MB 이내로 올려 주세요.', 'error'); }
      try { await localFile('put', { blob: file }); } catch (err) { ui().toast(err.message, 'error'); }
      document.getElementById('meeting-file-info').textContent = file.name + ' · ' + (file.size / 1048576).toFixed(1) + 'MB';
    };
    document.getElementById('meeting-site').onchange = async e => {
      rememberForm();
      try { data = await api('?site_id=' + e.target.value); if(active()) home(); } catch(err) { ui().toast(err.message, 'error'); }
    };
  }
  async function record() {
    rememberForm();
    if (recorder?.state === 'recording') { recorder.stop(); return; }
    if (!navigator.mediaDevices?.getUserMedia || !w.MediaRecorder) throw Error('이 브라우저에서는 녹음할 수 없습니다. 휴대폰 녹음 파일을 선택해 주세요.');
    stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true }, video: false });
    const mime = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus'].find(m => MediaRecorder.isTypeSupported(m));
    const chunks = []; recorder = new MediaRecorder(stream, mime ? { mimeType: mime, audioBitsPerSecond: 128000 } : {}); recordingAt = Date.now();
    recorder.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
    recorder.onstop = async () => {
      rememberForm();
      clearInterval(clock); stream.getTracks().forEach(t => t.stop());
      file = new Blob(chunks, { type: recorder.mimeType });
      try { await localFile('put', { blob: file }); } catch (e) { ui().toast(e.message, 'error'); }
      if (active()) home(); ui().toast('녹음이 준비되었습니다. 업로드하고 분석 시작을 누르세요.', 'success');
    };
    recorder.onerror = () => { ui().toast('녹음 장치에 오류가 발생했습니다. 저장된 분량을 확인하세요.', 'error'); if (recorder.state !== 'inactive') recorder.stop(); };
    recorder.start(10000); home();
    clock = setInterval(() => {
      const seconds = Math.floor((Date.now() - recordingAt) / 1000), el = document.getElementById('meeting-clock');
      if (el) el.textContent = '● 녹음 중 ' + Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
      if (seconds >= 1800 && recorder.state === 'recording') recorder.stop();
    }, 1000);
  }
  function mimeOf(f) {
    const type = (f.type || '').split(';')[0];
    if (type === 'video/webm') return 'audio/webm';
    if (type === 'audio/x-wav') return 'audio/wav';
    if (type === 'audio/x-m4a') return 'audio/m4a';
    if (type) return type;
    return /\.m4a$/i.test(f.name || '') ? 'audio/m4a' : /\.mp3$/i.test(f.name || '') ? 'audio/mpeg' : '';
  }
  async function upload() {
    if (busy) return; if (recorder?.state === 'recording') throw Error('녹음을 종료한 뒤 올려 주세요.');
    if (!file) throw Error('녹음 파일을 선택해 주세요.');
    const site = Number(document.getElementById('meeting-site').value), title = document.getElementById('meeting-title').value.trim(), date = document.getElementById('meeting-date').value, people = document.getElementById('meeting-people').value;
    if (!site || !title || !date) throw Error('현장·제목·회의일을 입력해 주세요.');
    busy = true; const status = text => { const e = document.getElementById('meeting-upload-status'); if (e) e.textContent = text; };
    try {
      status('녹음 확인 중…');
      const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
      const hash = Array.from(new Uint8Array(digest)).map(v => v.toString(16).padStart(2, '0')).join('');
      const key = 'meeting-upload:' + actor + ':' + site + ':' + date + ':' + hash;
      let token = localStorage.getItem(key); if (!token) { token = crypto.randomUUID(); localStorage.setItem(key, token); }
      const d = await api('', 'POST', { site_id: site, title, meeting_on: date, participants: people, upload_token: token, audio_hash: hash, audio_bytes: file.size, audio_mime: mimeOf(file) });
      const m = d.meeting;
      if (m.status === 'uploading') {
        for (let i = 0; i < m.part_count; i++) {
          if (!m.parts?.[i]) { const fd = new FormData(); fd.append('chunk', file.slice(i * 1048576, (i + 1) * 1048576), 'part.bin'); await api('/' + m.id + '/parts/' + i, 'POST', fd); }
          status('업로드 ' + Math.round((i + 1) / m.part_count * 100) + '%');
        }
        status('서버 원본 검증 중…'); await api('/' + m.id + '/finish', 'POST', {});
      }
      await localFile('delete'); file = null;
      ui().toast('서버 저장 완료. 앱을 닫아도 처리됩니다.', 'success'); w.ERPMeetingNavigate(m.id);
    } finally { busy = false; }
  }
  async function detail(id) {
    const g = generation; const d = await api('/' + id);
    if (g !== generation || !active()) return;
    current = d; const m = d.meeting;
    const pending = d.items.filter(i => ['pending', 'needs_input'].includes(i.status)), applied = d.items.filter(i => i.status === 'applied');
    host().innerHTML = ui().pageHeader(m.title, String(m.meeting_on).slice(0, 10) + ' · ' + (labels[m.status] || m.status), button('회의 목록', 'list') + button('새로고침', 'reload', m.id)) +
      (m.error ? notice(m.error, 'danger') : '') +
      (m.status === 'queued' ? notice('서버 처리 대기 중입니다. 오래 멈추면 관리자에게 회의 작업 프로세스 상태를 확인해 달라고 요청하세요.') : '') +
      (m.status === 'failed' ? button('실패 단계 재시도', 'retry', m.id) : '') +
      box('처리 요약', '<p style="white-space:pre-wrap">' + esc(m.analysis?.summary || '녹음을 읽고 기존 ERP 자료와 대조하고 있습니다.') + '</p><div class="meeting-buttons"><strong>반영 ' + applied.length + '건</strong><strong>확인 ' + pending.length + '건</strong><span>전체 ' + d.items.length + '건</span></div>') +
      (d.audio_url ? box('원음 · 근거 확인', '<audio id="meeting-audio" controls preload="metadata" style="width:100%" src="' + esc(d.audio_url) + '"></audio><p class="meeting-muted">근거 듣기로 해당 발언 위치를 재생합니다. 화자 번호는 직원 신원 확인 결과가 아닙니다.</p>') : '') +
      box('확인할 사항', pending.length ? pending.map(card).join('') : '<p>현재 확인할 항목이 없습니다.</p>') +
      box('반영·조회 결과', applied.length ? applied.map(card).join('') : '<p>아직 반영된 항목이 없습니다.</p>') +
      '<details class="panel" style="padding:16px"><summary>두 엔진 받아쓰기와 제외 항목</summary>' + ['gemini', 'scribe'].map(p => '<h4>' + esc(p) + ' · ' + esc(m.transcripts?.[p]?.status || '대기') + '</h4><p>' + esc(m.transcripts?.[p]?.error || '') + '</p><pre class="meeting-transcript">' + esc(m.transcripts?.[p]?.text || '') + '</pre>').join('') + d.items.filter(i => i.status === 'dismissed').map(card).join('') + '</details>';
    if (['queued', 'transcribing', 'analyzing'].includes(m.status)) poll = setTimeout(() => { if (active() && current?.meeting.id === id) detail(id).catch(e => ui().toast(e.message, 'error')); }, 7000);
  }
  function card(i) {
    const m = i.meeting_meta || {}, e = m.evidence || {}, r = m.result || {}, lookup = r.lookup;
    const canApply = !m.requires_approval && !['expense', 'decision'].includes(m.kind);
    return '<article class="meeting-card"><div class="meeting-muted">' + esc(kinds[m.kind] || m.kind) + ' · ' + esc(i.target_name || '대상 미지정') + '</div><h4>' + esc(i.summary) + '</h4>' +
      (i.question ? notice(i.question) : '') + (m.condition ? notice('선행조건: ' + m.condition) : '') +
      '<p>' + esc(i.result_note || '') + '</p>' + (lookup ? '<p>공급업체: ' + esc(lookup.vendor || '미등록') + ' · 예정일: ' + esc(lookup.eta || '미등록') + '</p><p>' + esc(r.impact || '') + '</p>' : '') +
      '<p class="meeting-muted">담당: ' + esc(m.assignee || '미지정') + ' · 기한: ' + esc(m.due_on || '미정') + '</p>' +
      (Object.keys(i.proposed || {}).length ? '<p>' + Object.entries(i.proposed).map(([k,v]) => esc(({progress:'진척률',planned_start:'시작',planned_end:'종료',crew_size:'계획 인원',eta:'입고 예정일',ordered_on:'발주일',status:'상태',planned_on:'검사 예정일'})[k] || k) + ': ' + esc(v)).join(' / ') + '</p>' : '') +
      '<details><summary>발언 근거·이력</summary><p>Scribe: ' + esc(e.quote_scribe || '') + '</p><p>Gemini: ' + esc(e.quote_gemini || '') + '</p><p>' + esc((m.history || []).map(h => h.event + ' · ' + h.at + (h.reason ? ' · ' + h.reason : '')).join('\n')) + '</p></details><div class="meeting-buttons">' +
      (e.start != null ? button('근거 듣기', 'listen', i.id) : '') +
      (['pending','needs_input'].includes(i.status) ? button('수정·대상 선택', 'edit', i.id) + (canApply ? button('확인 후 반영', 'apply', i.id) : '<span>원래 업무 화면에서 승인 필요</span>') + button('제외', 'dismiss', i.id) : '') +
      (i.status === 'applied' ? button('반영 취소', 'undo', i.id) : '') + '</div></article>';
  }
  async function edit(id) {
    const i = current.items.find(i => i.id === id), m = i.meeting_meta, ctx = await api('/' + current.meeting.id + '/targets');
    const prefix = {progress:'W:',plan:'W:',procurement:'P:',inspection:'S:'}[m.kind];
    const fields = [{ name:'title',label:'업무 내용',value:i.summary,required:true }, { name:'target_ref', label:'등록된 대상',type:'select',value:m.target_ref,options:[{value:'',label:'대상 미지정'},...Object.entries(ctx.targets).filter(([k]) => !prefix || k.startsWith(prefix)).map(([k,v]) => ({value:k,label:k + ' · ' + v.name}))] },
      {name:'assignee',label:'담당자·팀',value:m.assignee},{name:'due_on',label:'확인·조치 기한',type:'date',value:m.due_on}];
    const changeFields = {progress:['progress'],plan:['planned_start','planned_end','crew_size'],procurement:['eta','ordered_on','status'],inspection:['planned_on']}[m.kind] || [];
    const names = {progress:'진척률 (%)',planned_start:'작업 시작일',planned_end:'작업 종료일',crew_size:'계획 인원',eta:'납품 예정일',ordered_on:'발주일',status:'조달 상태',planned_on:'검사 예정일'};
    changeFields.forEach(k => fields.push({name:k,label:names[k],type:k === 'status' ? 'select' : ['progress','crew_size'].includes(k) ? 'number' : 'date',value:i.proposed?.[k],options:k === 'status' ? ['발주대기','발주완료','생산중','선적중','통관중','입고완료'] : undefined}));
    if (m.kind === 'labor') fields.push({name:'company',label:'출역 업체명',value:m.company},{name:'headcount',label:'실제 출역 인원',type:'number',value:m.headcount});
    const result = await ui().formModal({title:'회의 해석 수정',subtitle:'원음은 보존하고 해석·대상만 수정합니다. 저장 후 확인하여 반영하세요.',fields,onSave: async v => {
      const changes = {}; changeFields.forEach(k => { if(v[k] !== '') changes[k] = ['progress','crew_size'].includes(k) ? Number(v[k]) : v[k]; });
      return api('/' + current.meeting.id + '/items/' + id,'PATCH',{title:v.title,target_ref:v.target_ref,assignee:v.assignee,due_on:v.due_on || null,changes,company:v.company || '',headcount:Number(v.headcount || 0)});
    }}); if (result) await detail(current.meeting.id);
  }
  async function action(name, id) {
    if (name === 'ops') return w.loadView('opsroom');
    if (name === 'refresh') return render();
    if (name === 'list') return w.ERPMeetingNavigate(null);
    if (name === 'open') return w.ERPMeetingNavigate(id);
    if (name === 'reload') return detail(id);
    if (name === 'record') return record();
    if (name === 'upload') return upload();
    if (name === 'download') {
      if (!file) throw Error('준비된 녹음이 없습니다.'); const a = document.createElement('a'), u = URL.createObjectURL(file);
      a.href = u; a.download = file.name || ('meeting-' + day() + (mimeOf(file).includes('mp4') ? '.m4a' : '.webm')); a.click(); setTimeout(() => URL.revokeObjectURL(u), 1000); return;
    }
    if (name === 'retry') { await api('/' + id + '/retry','POST',{}); return detail(id); }
    if (name === 'edit') return edit(id);
    if (name === 'listen') { const i = current.items.find(i => i.id === id), audio = document.getElementById('meeting-audio'); audio.currentTime = Math.max(0,i.meeting_meta.evidence.start - 2); await audio.play(); return; }
    if (name === 'apply') {
      const item = current.items.find(i => i.id === id);
      const ok = await ui().formModal({title:'ERP 반영 확인',subtitle:item.summary,saveLabel:'확인하고 반영',fields:[{name:'confirmed',label:'근거 확인',type:'checkbox',checkboxLabel:'대상·수치·날짜·선행조건과 발언 근거를 확인했습니다.'}],onSave:v => v.confirmed ? api('/' + current.meeting.id + '/items/' + id + '/apply','POST',{confirmed:true}) : {success:false,error:'근거 확인 항목을 체크해 주세요.'}});
      if (ok) await detail(current.meeting.id); return;
    }
    if (['dismiss','undo'].includes(name)) {
      const ok = await ui().formModal({title:name === 'undo' ? '반영 취소' : '항목 제외',fields:[{name:'reason',label:'사유',required:true}],onSave:v => api('/' + current.meeting.id + '/items/' + id + '/' + name,'POST',v)});
      if (ok) await detail(current.meeting.id);
    }
  }
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-meeting-action]'); if (!b || b.disabled) return;
    b.disabled = true; Promise.resolve(action(b.dataset.meetingAction,Number(b.dataset.id))).catch(e => ui().toast(e.message,'error')).finally(() => { b.disabled = false; });
  });
  w.addEventListener('beforeunload', e => { if(busy || recorder?.state === 'recording') {e.preventDefault();e.returnValue='';} });
  const style = document.createElement('style'); style.textContent = '.meeting-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.meeting-form label{display:grid;gap:6px;font-size:13px}.meeting-form input,.meeting-form select{padding:10px;border:1px solid var(--border-subtle);border-radius:8px;background:var(--bg-base);color:var(--text-primary);min-width:0}.meeting-buttons{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 0}.meeting-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border-subtle)}.meeting-muted{font-size:12px;color:var(--text-secondary);line-height:1.6}.meeting-card{padding:16px 0;border-bottom:1px solid var(--border-subtle);overflow-wrap:anywhere}.meeting-card h4{margin:6px 0;font-size:16px}.meeting-card details{font-size:12px}.meeting-transcript{white-space:pre-wrap;overflow-wrap:anywhere;max-height:280px;overflow:auto;font:13px/1.7 inherit}@media(max-width:650px){.meeting-form{grid-template-columns:1fr}}'; document.head.appendChild(style);
  w.MeetingDesk = { render };
})(window);
