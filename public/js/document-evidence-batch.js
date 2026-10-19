(function (root) {
    'use strict';
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const uuid = () => root.crypto.randomUUID();
    const active = batch => ['running', 'matching'].includes(batch?.status);
    const states = {draft:'처리 전',running:'자료 분석 중',matching:'공정 연결 중',done:'처리 완료',failed:'확인 필요',waiting:'처리 대기',uploaded:'처리 전',queued:'순서 대기',analyzing:'분석 중',ready:'정리 완료',review_required:'확인 필요',duplicate:'기존 자료 사용'};
    const claimStates = {linked:'공정 연결됨',review:'공정 확인 필요',common:'공통비',ignored:'기성 제외'};
    const bytes = n => n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.ceil(n / 1024) + ' KB';

    // The browser only transfers originals. Classification belongs to the explicit Start request.
    class Workflow {
        constructor({request, send, maxBytes, changed = () => {}, makeKey = uuid}) {
            Object.assign(this, {request, send, maxBytes, changed, makeKey});
            this.files = []; this.batch = null; this.busy = false; this.sessionKey = makeKey();
        }
        reset() {
            if (this.busy) return false;
            this.files = []; this.batch = null; this.sessionKey = this.makeKey(); this.changed(); return true;
        }
        select(files) {
            if (this.busy || (this.batch && this.batch.status !== 'draft')) return;
            const seen = new Set(this.files.map(row => row.identity));
            for (const file of files) {
                const identity = [file.webkitRelativePath || file.name, file.size, file.lastModified].join('|');
                if (seen.has(identity)) continue;
                const pending = this.files.filter(row => row.state !== 'stored').length;
                if ((this.batch?.total || 0) + pending >= 1000) throw new Error('한 번에 최대 1,000개입니다. 남은 파일은 새로 접수해 주세요.');
                const error = file.size > this.maxBytes ? '파일당 크기 제한을 넘었습니다.' : file.size === 0 ? '빈 파일입니다.' : '';
                this.files.push({file, identity, key:this.makeKey(), state:error?'invalid':'selected', error, sent:0});
                seen.add(identity);
            }
            this.changed();
        }
        async upload(scope) {
            if (this.busy || (this.batch && this.batch.status !== 'draft')) return;
            if (!this.files.some(row => ['selected','failed'].includes(row.state))) return;
            if (!scope.project_id && !this.batch) throw new Error('현장 · 프로젝트를 선택해 주세요.');
            this.busy = true; this.changed();
            try {
                if (!this.batch) this.batch = (await this.request('', {method:'POST',body:{...scope,upload_session_key:this.sessionKey}})).batch;
                this.changed();
                for (const row of this.files) {
                    if (!['selected','failed'].includes(row.state)) continue;
                    row.state = 'uploading'; row.error = ''; row.sent = 0; this.changed();
                    try {
                        const result = await this.send(this.batch.id, row.file, row.key, sent => {row.sent = sent; this.changed();});
                        this.batch = result.batch; row.state = 'stored'; row.sent = row.file.size;
                    } catch (error) {
                        row.state = 'failed'; row.error = error.message;
                    }
                    this.changed();
                }
            } finally {this.busy = false; this.changed();}
        }
        async start(options = {}) {
            if (this.busy || !this.batch || active(this.batch) || !this.batch.total) return false;
            if (this.files.some(row => ['selected','uploading'].includes(row.state))) throw new Error('선택한 파일의 업로드를 먼저 마쳐 주세요.');
            this.busy = true; this.changed();
            try {
                const result = await this.request('/' + this.batch.id + '/start', {method:'POST',body:options});
                this.batch = result.batch; return true;
            } finally {this.busy = false; this.changed();}
        }
    }

    function mount(config) {
        const $ = id => root.document.getElementById('evidence-' + id);
        const dialog = $('dialog'); if (!dialog) return;
        let projects = [], history = [], page = 1, lastPage = 1, poll = null, requestGeneration = 0, loading = false;
        const report = (message, bad = false) => { $('feedback').textContent = message || ''; $('feedback').className = bad ? 'evidence-error' : ''; };
        async function request(path, options = {}) {
            const response = await root.fetch(config.url + path, {credentials:'same-origin',method:options.method || 'GET',headers:{Accept:'application/json','X-CSRF-TOKEN':config.csrf,...(options.body ? {'Content-Type':'application/json'} : {})},...(options.body ? {body:JSON.stringify(options.body)} : {})});
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success === false) throw new Error(data.message || data.error || (response.status === 401 ? '다시 로그인해 주세요.' : '요청을 처리하지 못했습니다. 다시 시도해 주세요.'));
            return data;
        }
        function send(batch, file, key, progress) {
            return new Promise((resolve,reject) => {
                const form = new root.FormData(); form.append('file',file); form.append('upload_key',key);
                const xhr = new root.XMLHttpRequest(); xhr.open('POST',config.url + '/' + batch + '/files');
                xhr.setRequestHeader('Accept','application/json'); xhr.setRequestHeader('X-CSRF-TOKEN',config.csrf); xhr.timeout = 180000;
                xhr.upload.onprogress = event => { if (event.lengthComputable) progress(file.size * event.loaded / event.total); };
                xhr.onload = () => {
                    let data = {}; try { data = JSON.parse(xhr.responseText); } catch (_) { /* Gateways may return HTML. */ }
                    if (xhr.status >= 200 && xhr.status < 300 && data.success !== false && data.batch) resolve(data);
                    else reject(new Error(data.message || data.error || ({413:'파일이 너무 큽니다.',419:'로그인이 만료되었습니다. 새로고침 후 다시 선택하세요.',401:'다시 로그인해 주세요.',403:'이 자료를 올릴 권한이 없습니다.'}[xhr.status] || '업로드 실패 · 다시 시도할 수 있습니다.')));
                };
                xhr.onerror = xhr.ontimeout = () => reject(new Error('연결이 끊겼습니다. 업로드를 다시 누르면 저장 여부를 확인합니다.'));
                xhr.send(form);
            });
        }
        const workflow = new Workflow({request,send,maxBytes:config.maxBytes,changed:render});
        function scope() { return {project_id:Number($('project').value),contract_id:$('contract').value ? Number($('contract').value) : null}; }
        function render() {
            const b = workflow.batch, locked = workflow.busy || loading || !!(b && b.status !== 'draft');
            $('project').disabled = workflow.busy || loading || !!b;
            $('contract').disabled = workflow.busy || loading || !!(b && (b.contractId || !['draft','done','failed'].includes(b.status)));
            ['pick','folder'].forEach(id => {$(id).disabled = locked;});
            $('drop').hidden = !!(b && b.status !== 'draft');
            $('new').disabled = workflow.busy || loading;
            $('close').disabled = workflow.busy;
            $('upload').hidden = !!(b && b.status !== 'draft');
            $('upload').disabled = workflow.busy || loading || !workflow.files.some(r => ['selected','failed'].includes(r.state));
            const failed = workflow.files.filter(r => ['failed','invalid'].includes(r.state));
            const stored = workflow.files.filter(r => r.state === 'stored');
            $('upload').textContent = workflow.busy ? '진행 중…' : failed.length ? '업로드 재시도' : '업로드';
            $('start').hidden = !b || b.status !== 'draft' || !b.total;
            $('start').disabled = workflow.busy || workflow.files.some(r => ['selected','uploading'].includes(r.state));
            $('start').textContent = failed.length ? `저장된 ${b?.total || 0}개 처리 시작` : '처리 시작';
            $('local').hidden = workflow.files.length === 0;
            $('selected').textContent = `선택 ${workflow.files.length}개 · 저장 ${stored.length}개` + (failed.length ? ` · 미저장 ${failed.length}개` : '');
            const totalBytes = workflow.files.reduce((sum,r)=>sum+r.file.size,0);
            $('progress').value = totalBytes ? workflow.files.reduce((sum,r)=>sum+(r.state==='stored'?r.file.size:r.sent || 0),0)/totalBytes*100 : 0;
            // Bounded list: a folder may contain thousands of pages, but never a thousand image decodes.
            $('selected-rows').innerHTML = workflow.files.slice(0,100).map(r=>`<div class="queue-row"><span class="q-name">${esc(r.file.webkitRelativePath || r.file.name)}</span><span class="q-state ${['invalid','failed'].includes(r.state)?'bad':''}">${esc(r.error || ({stored:'저장됨',selected:'선택됨',uploading:'업로드 중'}[r.state] || r.state))}</span><span class="q-size">${bytes(r.file.size)}</span></div>`).join('') + (workflow.files.length>100?'<p class="doc-sub">처음 100개 표시 · 전체 파일은 순서대로 업로드합니다.</p>':'');
            $('help').textContent = workflow.busy ? '업로드·접수 중에는 이 화면을 유지하세요.' : b && active(b) ? '창을 닫아도 계속 처리합니다. 지난 접수에서 결과를 확인하세요.' : b?.status==='draft' && b.total ? '서버 저장 완료. 처리 시작을 눌러 주세요.' : b?.status==='done' ? '원본은 보관되었습니다. 확인이 필요한 자료만 검토하세요.' : '업로드 중에는 이 화면을 유지하세요. 처리 시작 후에는 닫아도 됩니다.';
            $('result').hidden = !b;
            if (b) {
                $('state').textContent = `접수 #${b.id} · ${states[b.status] || b.status}`;
                $('counts').innerHTML = Object.entries(b.counts || {}).filter(([,n])=>n>0).map(([s,n])=>`<span class="badge ${esc(s)}">${esc(states[s] || s)} ${Number(n)}</span>`).join('') + Object.entries(b.claimCounts || {}).filter(([,n])=>n>0).map(([s,n])=>`<span class="badge ${s==='review'?'review_required':'ready'}">${esc(claimStates[s] || s)} ${Number(n)}</span>`).join('');
                $('retry').hidden = active(b) || !(b.counts?.failed>0 || b.status==='failed');
                $('retry').disabled = workflow.busy;
                $('rematch').hidden = b.status!=='done' || (!b.contractId && !$('contract').value);
                $('rematch').textContent = b.contractId ? '공정 다시 연결' : '공정 연결';
                $('rematch').disabled = workflow.busy;
                $('claim').hidden = !b.contractId;
                $('claim').href = config.claimUrl + (config.claimUrl.includes('?')?'&':'?') + 'contract_id=' + Number(b.contractId);
            }
        }
        function updateContract() {
            const project = projects.find(p => String(p.id)===$('project').value), contracts = project?.contracts || [];
            $('contract').innerHTML = (contracts.length !== 1 ? '<option value="">나중에 연결</option>' : '') + contracts.map(c=>`<option value="${Number(c.id)}">${esc(c.label)}</option>`).join('');
            $('contract-field').hidden = contracts.length<=1 && (!workflow.batch || !!workflow.batch.contractId);
            $('scope-note').textContent = contracts.length===1 ? '공정 기준: ' + contracts[0].label : contracts.length>1 ? '자료를 연결할 기성 계약을 선택하세요.' : project ? '자료는 보관됩니다. 기성 공정을 등록한 뒤 연결해 주세요.' : '';
        }
        async function loadIndex() {
            loading = true; render();
            try {
                const data = await request(''); projects = data.projects || []; history = data.batches || [];
                const contractProject = projects.find(p => p.contracts.some(c => Number(c.id) === Number(config.contractId)));
                const prior = workflow.batch?.projectId || $('project').value || contractProject?.id || config.projectId;
                $('project').innerHTML = '<option value="">현장 · 프로젝트 선택</option>' + projects.map(p=>`<option value="${Number(p.id)}">${esc([p.siteName,p.name].filter(Boolean).join(' · '))}</option>`).join('');
                if (projects.some(p=>String(p.id)===String(prior))) $('project').value = String(prior);
                else if (projects.length===1) $('project').value=String(projects[0].id);
                updateContract();
                if (!workflow.batch && contractProject && String(contractProject.id) === $('project').value) $('contract').value = String(config.contractId);
                if (workflow.batch?.contractId) $('contract').value = String(workflow.batch.contractId);
                $('batches').innerHTML = history.map(b=>`<button type="button" class="evidence-history-row" data-batch="${Number(b.id)}"><span>접수 #${Number(b.id)} · ${esc(projects.find(p=>Number(p.id)===Number(b.projectId))?.name || '프로젝트')}<small>${esc(String(b.createdAt || '').slice(0,16).replace('T',' '))} · ${Number(b.total)}개</small></span><span class="badge">${esc(states[b.status] || b.status)}</span></button>`).join('') || '<p class="doc-sub">지난 접수가 없습니다.</p>';
            } finally {loading=false; render();}
        }
        function stopPoll() { root.clearTimeout(poll); poll=null; }
        async function refresh() {
            stopPoll(); if (!dialog.open || !workflow.batch || workflow.busy) return;
            const id = workflow.batch.id, generation = ++requestGeneration;
            try {
                const data = await request('/'+id+'?page='+page+'&review_only='+($('review-only').checked?'1':'0'));
                if (generation!==requestGeneration || workflow.batch?.id!==id || !dialog.open) return;
                workflow.batch=data.batch;
                lastPage=Number(data.pagination?.lastPage)||1; page=Number(data.pagination?.page)||1;
                $('rows').innerHTML=(data.rows || []).map(r=>`<div class="evidence-file-row"><button type="button" class="doc-open" data-document="${Number(r.documentId)}"><span><span class="doc-title">${esc(r.originalName)}</span><span class="doc-sub">${esc(r.error || r.claimReason || '')}</span>${r.processNames?.length?`<span class="doc-sub">공정: ${r.processNames.map(esc).join(' · ')}</span>`:''}</span></button><span><span class="badge ${esc(r.status)}">${esc(states[r.status] || r.status)}</span>${r.claimStatus?` <span class="badge ${r.claimStatus==='review'?'review_required':'ready'}">${esc(claimStates[r.claimStatus] || r.claimStatus)}</span>`:''}</span></div>`).join('') || '<p class="doc-sub">표시할 자료가 없습니다.</p>';
                $('page-label').textContent=`${page} / ${lastPage} · ${Number(data.pagination?.total || 0)}개`;
                $('prev').disabled=page<=1; $('next').disabled=page>=lastPage;
                render(); if (data.batch.error) report(data.batch.error,true);
            } catch (error) { if(generation===requestGeneration) report(error.message,true); }
            finally {if(dialog.open && workflow.batch?.id===id && active(workflow.batch)) poll=root.setTimeout(refresh,5000);}
        }
        function canLeave() {
            if (workflow.busy) return false;
            return !workflow.files.some(r=>r.state!=='stored') || root.confirm('아직 저장하지 못한 파일이 있습니다. 선택을 지우고 이동할까요?');
        }
        async function run(fn) {report(''); try {await fn();} catch(error) {report(error.message,true);} }
        $('open').onclick=()=>run(async()=>{dialog.showModal(); await loadIndex(); if(workflow.batch) await refresh();});
        $('close').onclick=()=>{if(workflow.busy)return;dialog.close();};
        dialog.addEventListener('cancel',event=>{if(workflow.busy)event.preventDefault();});
        dialog.addEventListener('close',()=>{stopPoll();++requestGeneration;config.onChanged?.();});
        root.addEventListener('beforeunload',event=>{if(workflow.busy){event.preventDefault();event.returnValue='';}});
        $('project').onchange=updateContract; $('contract').onchange=render;
        $('pick').onclick=()=>$('files').click(); $('folder').onclick=()=>$('directory').click();
        ['files','directory'].forEach(id=>{$(id).onchange=()=>run(async()=>{try{workflow.select(Array.from($(id).files));}finally{$(id).value='';render();}});});
        $('drop').ondragover=event=>{event.preventDefault();$('drop').classList.add('drag');};
        $('drop').ondragleave=()=>$('drop').classList.remove('drag');
        $('drop').ondrop=event=>{event.preventDefault();$('drop').classList.remove('drag');run(async()=>{if(workflow.busy)return;const items=Array.from(event.dataTransfer.items || []);if(items.some(i=>i.webkitGetAsEntry?.()?.isDirectory))throw new Error('폴더는 폴더 선택 버튼으로 올려 주세요.');workflow.select(Array.from(event.dataTransfer.files));});};
        $('upload').onclick=()=>run(async()=>{await workflow.upload(scope());if(workflow.batch)await refresh();});
        $('start').onclick=()=>run(async()=>{await workflow.start({contract_id:workflow.batch?.contractId || Number($('contract').value) || null});await refresh();});
        $('retry').onclick=()=>run(async()=>{await workflow.start({retry_failed:true,contract_id:workflow.batch?.contractId || Number($('contract').value) || null});await refresh();});
        $('rematch').onclick=()=>run(async()=>{await workflow.start({rematch:true,contract_id:workflow.batch?.contractId || Number($('contract').value) || null});await refresh();});
        $('new').onclick=()=>run(async()=>{if(!canLeave())return;stopPoll();++requestGeneration;workflow.reset();page=1;$('rows').innerHTML='';await loadIndex();});
        $('review-only').onchange=()=>{page=1;run(refresh);};
        $('prev').onclick=()=>{if(page>1){page--;run(refresh);}}; $('next').onclick=()=>{if(page<lastPage){page++;run(refresh);}};
        $('batches').onclick=event=>run(async()=>{const button=event.target.closest('[data-batch]');if(!button || !canLeave())return;stopPoll();++requestGeneration;workflow.reset();workflow.batch=history.find(b=>b.id===Number(button.dataset.batch));page=1;await loadIndex();await refresh();});
        $('rows').onclick=event=>{const button=event.target.closest('[data-document]');if(button && !workflow.busy){dialog.close();config.openDocument(Number(button.dataset.document));}};
        $('claim').onclick=event=>{if(workflow.batch?.contractId && config.openClaim?.(workflow.batch.contractId)){event.preventDefault();dialog.close();}};
        render(); return workflow;
    }
    root.DocumentEvidenceBatch = {Workflow,mount,esc,active};
})(typeof window !== 'undefined' ? window : globalThis);
