(function () {
    'use strict';
    window.createCADConversions = function ({request, openDrawing, status, siteId}) {
        const panel = document.getElementById('cad-conversions');
        const body = document.getElementById('cad-conversion-list');
        const toggle = document.getElementById('cad-conversion-toggle');
        let enabled = false, pending = false, timer = null, disposed = false;
        toggle.onclick = () => { panel.hidden = !panel.hidden; toggle.setAttribute('aria-expanded', String(!panel.hidden)); if (!panel.hidden) refresh(); };
        function button(label, action) {
            const b = document.createElement('button'); b.type = 'button'; b.textContent = label;
            b.onclick = async () => { b.disabled = true; try { await action(); } catch (e) { status(e.message); } finally { b.disabled = false; } }; return b;
        }
        function render(tasks) {
            body.replaceChildren();
            const visible = tasks.filter(t => !t.siteId || !siteId() || Number(t.siteId) === Number(siteId()));
            if (!visible.length) { body.textContent = '아직 변환 작업이 없습니다.'; return; }
            for (const task of visible) {
                const item = document.createElement('article'); item.className = 'cad-conversion-item';
                const name = document.createElement('strong'); name.textContent = task.title; item.append(name);
                const state = document.createElement('p'); state.textContent = ({queued:'대기 중 · 원본 보관 완료',processing:'전용 서버에서 변환·검증 중',completed:'변환 완료',failed:'변환 실패 · 원본 보존'})[task.status] || task.status;
                item.append(state);
                if (task.error) { const error = document.createElement('p'); error.textContent = task.error; item.append(error); }
                if (task.notice) { const notice = document.createElement('p'); notice.textContent = task.notice; item.append(notice); }
                for (const d of task.result?.drawings || []) item.append(button(d.title+' 열기',()=>openDrawing(d.id)));
                if (task.result?.downloadUrl) {
                    const link = document.createElement('a'); link.className = 'button'; link.href = task.result.downloadUrl; link.textContent = 'DWG 내려받기'; item.append(link);
                }
                if (task.status === 'failed' && task.attempt < 3) item.append(button('보관된 원본으로 재시도',async()=>{await request('/conversions/'+task.id+'/retry',{method:'POST'}); await refresh();}));
                const original = document.createElement('a'); original.className = 'button'; original.textContent = task.operation === 'export' ? '변환 전 DXF' : '업로드 원본'; original.href = task.originalUrl; item.append(original);
                if (task.result?.warnings?.length) { const details = document.createElement('details'); const summary = document.createElement('summary'); summary.textContent = '작업본 안내·제외 항목'; details.append(summary); const text = document.createElement('p'); text.textContent = task.result.warnings.join(' '); details.append(text); item.append(details); }
                body.append(item);
            }
        }
        async function refresh() {
            if (!enabled || pending || disposed) return;
            pending = true; clearTimeout(timer);
            try {
                const result = await request('/conversions'); const tasks = result.conversions || [];
                render(tasks); const active = tasks.filter(t=>['queued','processing'].includes(t.status)).length;
                toggle.textContent = active ? '변환 작업 ('+active+')' : '변환 작업';
                timer = setTimeout(refresh, active ? 4000 : 30000);
            } catch (error) { body.textContent = '작업 상태를 확인하지 못했습니다. 잠시 후 다시 확인합니다. '+error.message; timer=setTimeout(refresh,15000); }
            finally { pending = false; }
        }
        async function submit(form) {
            const task = await request('/conversions',{method:'POST',body:form});
            panel.hidden=false; toggle.setAttribute('aria-expanded','true'); await refresh();
            status('원본을 보관했습니다. 전용 서버에서 2D 작업본을 준비합니다. 다른 도면을 편집하거나 창을 닫아도 변환은 계속됩니다.');
            return task;
        }
        async function exportDwg(drawing) {
            await request('/'+drawing.id+'/export-dwg',{method:'POST',body:JSON.stringify({revision:drawing.revision,requestKey:crypto.randomUUID()})});
            panel.hidden=false; toggle.setAttribute('aria-expanded','true'); await refresh(); status('저장된 개정을 전용 서버에서 DWG로 변환합니다. 완료 후 변환 작업에서 내려받으세요.');
        }
        window.addEventListener('pagehide',()=>{disposed=true;clearTimeout(timer);});
        window.addEventListener('pageshow',()=>{if(disposed){disposed=false;refresh();}});
        return {submit,exportDwg,enable(value){enabled=!!value;toggle.hidden=!enabled;if(enabled)refresh();},refresh};
    };
})();
