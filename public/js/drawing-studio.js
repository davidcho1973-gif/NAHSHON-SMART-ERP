(function(global){
  'use strict';
  const C=global.DrawingStudioCore, E=C.esc;
  const TYPES={select:['↖','선택'],hand:['✋','이동'],dimension:['↔','거리'],angle:['∠','각도'],line:['╱','직선'],polyline:['⌁','연속선'],polygon:['⬡','면적'],rect:['▭','사각형'],ellipse:['○','타원'],cloud:['☁','구름'],arrow:['↗','화살표'],text:['T','문자'],pen:['✎','펜'],highlight:['▰','강조']};
  const id=()=>global.crypto.randomUUID();
  function download(blob,name){const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(a.href),3000);}
  async function api(url,method='GET',body){const headers={Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''};
    if(body&&!(body instanceof FormData))headers['Content-Type']='application/json';
    const r=await fetch(url,{method,credentials:'same-origin',headers,body:body?(body instanceof FormData?body:JSON.stringify(body)):undefined});
    const data=await r.json().catch(()=>({message:'서버 응답을 읽지 못했습니다.'}));if(!r.ok){const error=new Error(Object.values(data.errors||{}).flat()[0]||data.message||'요청에 실패했습니다.');error.status=r.status;throw error;}return data;}
  // Raster PDF export keeps the exact preview, including measurements and annotations.
  function pdfBlob(canvas){const binary=atob(canvas.toDataURL('image/jpeg',.94).split(',')[1]),jpg=Uint8Array.from(binary,c=>c.charCodeAt(0)),enc=new TextEncoder(),chunks=[],offsets=[0];let size=0;
    const add=x=>{const b=typeof x==='string'?enc.encode(x):x;chunks.push(b);size+=b.length;};
    add('%PDF-1.4\n');const w=842,h=842*canvas.height/canvas.width;
    function obj(n,s){offsets[n]=size;add(n+' 0 obj\n'+s+'\nendobj\n');}
    obj(1,'<< /Type /Catalog /Pages 2 0 R >>');obj(2,'<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
    obj(3,`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${w} ${h}] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>`);
    offsets[4]=size;add(`4 0 obj\n<< /Type /XObject /Subtype /Image /Width ${canvas.width} /Height ${canvas.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpg.length} >>\nstream\n`);add(jpg);add('\nendstream\nendobj\n');
    const stream=`q ${w} 0 0 ${h} 0 0 cm /Im0 Do Q`;obj(5,`<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`);
    const xref=size;add('xref\n0 6\n0000000000 65535 f \n');for(let i=1;i<=5;i++)add(String(offsets[i]).padStart(10,'0')+' 00000 n \n');add(`trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`);return new Blob(chunks,{type:'application/pdf'});
  }
  function mount(host){
    const {wrap,world,stage}=host,base='/admin-api/drawing-sheets/'+host.sheetId+'/studio';
    let doc=null,revision=0,canManage=false,active=false,mode='edit',tool='select',layer='default',selected=[],draft=[],hover=null,gesture=null,dirty=false,saving=false,dead=false,undo=[],redo=[],snap=true,ortho=false,grid=false,preview=null,previewUrl=null,options=null,sendId=null,sent=false,sending=false,conflict=false,newText='';
    let style={color:'#ef4444',fill:'none',width:1.5,opacity:1,dash:'solid',fontSize:12},snapLabel='';
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.classList.add('ds-overlay');svg.style.pointerEvents='none';world.appendChild(svg);
    const panel=document.createElement('aside');panel.className='ds-panel';panel.style.display='none';panel.setAttribute('aria-label','도면 편집 및 공유');wrap.querySelector('.dm-body').appendChild(panel);
    const toolbar=document.createElement('div');toolbar.className='ds-toolbar';toolbar.style.display='none';stage.appendChild(toolbar);
    const footer=document.createElement('div');footer.className='ds-footer';footer.style.display='none';stage.appendChild(footer);
    const $=s=>panel.querySelector(s),v=()=>host.view(),objects=()=>doc?.entities||[],layers=()=>doc?.layers||[];
    const shown=()=>objects().filter(e=>layers().find(l=>l.id===e.layer)?.visible);
    const editable=e=>canManage&&!layers().find(l=>l.id===e.layer)?.locked;
    const chosen=()=>objects().filter(e=>selected.includes(e.id));
    function status(text,error=false){const el=$('[data-status]');if(el){el.textContent=text;el.classList.toggle('error',error);}}
    function changed(before){if(before){undo.push(before);if(undo.length>60)undo.shift();redo=[];}dirty=true;preview=null;sent=false;sendId=null;render();renderPanel();}
    function mutate(fn){if(!canManage||conflict)return;const before=C.clone(doc);fn();if(JSON.stringify(before)!==JSON.stringify(doc))changed(before);}
    function history(back){if(!canManage||conflict)return;const from=back?undo:redo,to=back?redo:undo;if(!from.length)return;to.push(C.clone(doc));doc=from.pop();selected=[];draft=[];dirty=true;preview=null;render();renderPanel();}
    function setTool(t){tool=t;draft=[];hover=null;gesture=null;if(!['select','hand'].includes(t))selected=[];renderToolbar();renderPanel();render();if(['dimension','polygon'].includes(t)&&!v().fpp)host.hint('먼저 위의 축척 맞추기로 실제 치수를 설정하세요.');}
    function activate(next='edit'){if(sending)return;if(!doc){host.hint('편집 자료를 불러오는 중입니다.');return;}active=true;mode=next;panel.style.display='block';toolbar.style.display=next==='edit'?'grid':'none';footer.style.display=next==='edit'?'block':'none';
      host.side.style.display='none';host.tools.style.display='none';host.setHand();renderToolbar();renderPanel();render();}
    function deactivate(){if(sending)return;active=false;panel.style.display='none';toolbar.style.display='none';footer.style.display='none';host.side.style.display='';host.tools.style.display=host.canManage?'':'none';svg.style.pointerEvents='none';draft=[];render();}
    function renderToolbar(){toolbar.innerHTML=Object.entries(TYPES).map(([key,t])=>`<button class="dm-btn ${key===tool?'on':''}" data-tool="${key}" title="${t[1]}" ${!canManage&&!['select','hand'].includes(key)?'disabled':''}><b>${t[0]}</b>${t[1]}</button>`).join('')+`<button class="dm-btn ds-wide" data-finish>완료 ↵</button><button class="dm-btn ds-wide" data-cancel>취소 Esc</button>`;
      toolbar.querySelectorAll('[data-tool]').forEach(b=>b.onclick=()=>setTool(b.dataset.tool));toolbar.querySelector('[data-finish]').onclick=finish;toolbar.querySelector('[data-cancel]').onclick=()=>{draft=[];render();};}
    function summary(e){const m=C.measure(e,v().wPt,v().hPt,v().fpp);if(e.type==='angle')return m.angle===null?'각도 미정':m.angle.toFixed(doc.precision)+'°';
      if(['rect','polygon','ellipse','cloud'].includes(e.type))return C.format(m.area,doc.unit,doc.precision,true)+' · 둘레 '+C.format(m.length,doc.unit,doc.precision);
      return e.type==='text'?e.text:C.format(m.length,doc.unit,doc.precision);}
    function render(){if(!doc||dead)return;if(active)host.tools.style.display='none';const view=v();svg.setAttribute('width',view.W);svg.setAttribute('height',view.H);svg.setAttribute('viewBox',`0 0 ${view.W} ${view.H}`);svg.style.pointerEvents=active&&mode==='edit'&&tool!=='hand'?'auto':'none';
      let html=shown().map(e=>`<g data-entity="${E(e.id)}" class="ds-entity">${C.render(e,view,doc)}</g>`).join('');
      if(active&&mode==='edit'){
        if(grid){const size=12*view.W/view.wPt;html=`<defs><pattern id="ds-grid" width="${size}" height="${size}" patternUnits="userSpaceOnUse"><path d="M ${size} 0 L 0 0 0 ${size}" fill="none" stroke="#38bdf8" stroke-opacity=".22" stroke-width="${.6/view.k}"/></pattern></defs><rect width="100%" height="100%" fill="url(#ds-grid)" pointer-events="none"/>`+html;}
        chosen().forEach(e=>{const b=C.bounds(C.vertices(e)),k=view.k;html+=`<rect class="ds-selection" x="${b.x*view.W-5/k}" y="${b.y*view.H-5/k}" width="${(b.right-b.x)*view.W+10/k}" height="${(b.bottom-b.y)*view.H+10/k}" fill="none" stroke="#0ea5e9" stroke-width="${1.5/k}" stroke-dasharray="${4/k} ${3/k}"/>`;
          if(selected.length===1&&editable(e))e.points.forEach((p,i)=>{html+=`<circle data-vertex="${i}" cx="${p[0]*view.W}" cy="${p[1]*view.H}" r="${5/k}" fill="white" stroke="#0284c7" stroke-width="${2/k}"/>`;});});
        if(draft.length){const points=hover?[...draft,hover]:draft;const draftType=['select','hand'].includes(tool)?'line':tool;const min=draftType==='text'?1:2;
          if(points.length>=min)html+=`<g pointer-events="none" opacity=".8">${C.render({...style,id:'draft',type:draftType,points,text:''},view,doc)}</g>`;
          draft.forEach(p=>{html+=`<circle pointer-events="none" cx="${p[0]*view.W}" cy="${p[1]*view.H}" r="${4/view.k}" fill="#38bdf8"/>`;});}
        if(hover&&snapLabel)html+=`<rect pointer-events="none" x="${hover[0]*view.W-6/view.k}" y="${hover[1]*view.H-6/view.k}" width="${12/view.k}" height="${12/view.k}" fill="none" stroke="#10b981" stroke-width="${2/view.k}"/>`;
        if(gesture?.kind==='marquee'){const b=C.bounds([gesture.start,gesture.now]);html+=`<rect pointer-events="none" x="${b.x*view.W}" y="${b.y*view.H}" width="${(b.right-b.x)*view.W}" height="${(b.bottom-b.y)*view.H}" fill="#38bdf8" fill-opacity=".1" stroke="#38bdf8" stroke-width="${1/view.k}"/>`;}
        const measuring=draft.length>0?{type:tool,points:hover?[...draft,hover]:draft}:null;
        footer.textContent=(measuring&&measuring.points.length>=2?summary(measuring)+' · ':'')+(snapLabel?snapLabel+' · ':'')+(v().fpp?'축척: '+host.scaleLabel():'축척 미설정')+' · Shift: 15° / Ctrl+클릭: 다중 선택 / Enter: 완료 / Esc: 취소';
      }
      svg.innerHTML=html;
    }
    function renderPanel(){if(!active||!doc)return;if(mode==='share'){renderShare();return;}
      const picked=chosen(),e=picked.length===1?picked[0]:null,s=e||style;
      panel.innerHTML=`<div class="ds-row"><h3 style="flex:1">도면 스튜디오</h3><button class="dm-btn" data-exit title="편집 패널 접기">✕</button></div>
        <div class="ds-row"><button class="dm-btn primary" data-save ${!dirty||saving||!canManage||conflict?'disabled':''}>${saving?'저장 중…':'저장'}</button><button class="dm-btn" data-undo title="실행 취소 (Ctrl+Z)" ${!undo.length?'disabled':''}>↶</button><button class="dm-btn" data-redo title="다시 실행 (Ctrl+Shift+Z)" ${!redo.length?'disabled':''}>↷</button><button class="dm-btn" data-share>공유 ↗</button></div>
        <div class="ds-status ${conflict?'error':''}" data-status>${conflict?'저장 충돌: 작업 JSON을 보관하고 다시 여세요.':dirty?'저장하지 않은 변경 있음':`저장됨 · 버전 ${revision}`}</div>
        <div class="ds-grid"><label>측정 단위<select data-unit>${['mm','m','ft','ft-in'].map(u=>`<option ${doc.unit===u?'selected':''}>${u}</option>`).join('')}</select></label><label>소수 자릿수<select data-precision>${[0,1,2,3,4].map(n=>`<option ${doc.precision===n?'selected':''}>${n}</option>`).join('')}</select></label></div>
        <div class="ds-row"><button class="dm-btn ${snap?'on':''}" data-snap>스냅</button><button class="dm-btn ${ortho?'on':''}" data-ortho>직교</button><button class="dm-btn ${grid?'on':''}" data-grid>격자</button></div>
        <div class="ds-muted">스냅은 작도한 도형의 끝점·중점·선 위에 맞춥니다.</div>
        <h4>${picked.length?picked.length+'개 선택':'새 도형 스타일'}</h4>
        ${e?`<div class="ds-status">${E(summary(e))}</div>`:''}
        <div class="ds-grid"><label>선 색<input data-style="color" type="color" value="${E(s.color)}"></label><label>채우기 색<input data-style="fill" type="color" value="${s.fill==='none'?'#ffffff':E(s.fill)}"></label><label>선 굵기 (pt)<input data-style="width" type="number" min=".25" max="20" step=".25" value="${s.width}"></label><label>문자 크기 (pt)<input data-style="fontSize" type="number" min="6" max="72" value="${s.fontSize}"></label><label>불투명도<input data-style="opacity" type="range" min=".05" max="1" step=".05" value="${s.opacity}"></label><label>선 종류<select data-style="dash">${[['solid','실선'],['dash','파선'],['dot','점선']].map(([k,t])=>`<option value="${k}" ${s.dash===k?'selected':''}>${t}</option>`).join('')}</select></label></div>
        <div class="ds-row"><button class="dm-btn" data-clear-fill>채우기 없음</button><button class="dm-btn" data-delete ${!picked.length?'disabled':''}>삭제</button><button class="dm-btn" data-duplicate ${!picked.length?'disabled':''}>복제</button></div>
        <label>문자 / 도형 이름<input data-text maxlength="2000" placeholder="문자 도구를 고르고 도면을 클릭하세요" value="${E(e?.text??newText)}"></label>
        <div class="ds-row"><button class="dm-btn" data-front>맨 앞으로</button><button class="dm-btn" data-back>맨 뒤로</button><button class="dm-btn" data-align>왼쪽 정렬</button></div>
        <div class="ds-grid"><label>회전 (도)<input data-rotate type="number" value="0" step="15"></label><label>직선 길이 (${doc.unit==='ft-in'?'ft':doc.unit})<input data-length type="number" min="0" step=".01" placeholder="선 선택 후 입력"></label></div>
        <div class="ds-row"><button class="dm-btn" data-transform>선택 도형에 적용</button></div>
        <h4>레이어</h4><div data-layers>${layers().map(l=>`<div class="ds-row ds-layer ${layer===l.id?'active':''}" data-layer="${E(l.id)}"><input type="radio" name="ds-layer" ${layer===l.id?'checked':''} style="width:auto" data-active><input type="text" value="${E(l.name)}" data-name maxlength="80"><button class="dm-btn" data-visible title="표시/숨기기">${l.visible?'◉':'○'}</button><button class="dm-btn" data-lock title="잠금/해제">${l.locked?'🔒':'🔓'}</button></div>`).join('')}</div><button class="dm-btn" data-add-layer>＋ 레이어</button>
        <details><summary>도형 목록 (${objects().length})</summary><div class="ds-object-list">${objects().map(x=>`<button class="dm-btn ${selected.includes(x.id)?'on':''}" data-object="${E(x.id)}">${E(TYPES[x.type]?.[1]||x.type)} · ${E(x.text||summary(x))}</button>`).join('')}</div></details>
        <details><summary>저장 이력 / 작업 보관</summary><div class="ds-row"><button class="dm-btn" data-history>수정 이력</button><button class="dm-btn" data-json>작업 JSON</button></div><div class="ds-history" data-history-list></div><div class="ds-muted">이전 버전 복원도 새로운 버전으로 저장됩니다. 변경 이력은 유지됩니다.</div></details>`;
      $('[data-exit]').onclick=deactivate;$('[data-save]').onclick=save;$('[data-undo]').onclick=()=>history(true);$('[data-redo]').onclick=()=>history(false);$('[data-share]').onclick=()=>activate('share');
      $('[data-unit]').onchange=ev=>mutate(()=>doc.unit=ev.target.value);$('[data-precision]').onchange=ev=>mutate(()=>doc.precision=Number(ev.target.value));
      $('[data-snap]').onclick=()=>{snap=!snap;renderPanel();};$('[data-ortho]').onclick=()=>{ortho=!ortho;renderPanel();};$('[data-grid]').onclick=()=>{grid=!grid;renderPanel();render();};
      panel.querySelectorAll('[data-style]').forEach(el=>el.onchange=()=>{let value=['width','opacity','fontSize'].includes(el.dataset.style)?Number(el.value):el.value;if(!el.checkValidity())return;style[el.dataset.style]=value;if(picked.length)mutate(()=>picked.filter(editable).forEach(x=>x[el.dataset.style]=value));});
      $('[data-clear-fill]').onclick=()=>{style.fill='none';mutate(()=>picked.filter(editable).forEach(x=>x.fill='none'));};
      $('[data-text]').onchange=ev=>{const text=ev.target.value;if(e&&editable(e)){if(e.type==='text'&&!text.trim()){status('문자 내용은 비워 둘 수 없습니다.',true);return;}mutate(()=>e.text=text);}else newText=text;};
      $('[data-delete]').onclick=remove;$('[data-duplicate]').onclick=duplicate;
      $('[data-front]').onclick=()=>mutate(()=>{const a=picked.filter(editable);doc.entities=objects().filter(x=>!a.includes(x)).concat(a);});
      $('[data-back]').onclick=()=>mutate(()=>{const a=picked.filter(editable);doc.entities=a.concat(objects().filter(x=>!a.includes(x)));});
      $('[data-align]').onclick=()=>mutate(()=>{const a=picked.filter(editable);if(!a.length)return;const left=Math.min(...a.map(x=>C.bounds(x.points).x));a.forEach(x=>x.points=C.translate([x],left-C.bounds(x.points).x,0)[0].points);});
      $('[data-transform]').onclick=transform;
      panel.querySelectorAll('[data-layer]').forEach(el=>{const l=layers().find(x=>x.id===el.dataset.layer);el.querySelector('[data-active]').onchange=()=>{layer=l.id;renderPanel();};el.querySelector('[data-name]').onchange=ev=>{if(ev.target.value.trim())mutate(()=>l.name=ev.target.value.trim());};el.querySelector('[data-visible]').onclick=()=>mutate(()=>{l.visible=!l.visible;selected=[];});el.querySelector('[data-lock]').onclick=()=>mutate(()=>l.locked=!l.locked);});
      $('[data-add-layer]').onclick=()=>{if(layers().length>=30)return;mutate(()=>{layer=id();doc.layers.push({id:layer,name:'레이어 '+(layers().length+1),visible:true,locked:false});});};
      panel.querySelectorAll('[data-object]').forEach(b=>b.onclick=()=>{selected=[b.dataset.object];tool='select';renderToolbar();renderPanel();render();});
      $('[data-json]').onclick=exportJson;$('[data-history]').onclick=loadHistory;
      if(!canManage)panel.querySelectorAll('input,select,[data-style],[data-add-layer],[data-transform]').forEach(el=>el.disabled=true);
    }
    function exportJson(){download(new Blob([JSON.stringify({sheetId:host.sheetId,sheetNo:host.sheetNo,revision,document:doc},null,2)],{type:'application/json'}),host.sheetNo+'-edit.json');}
    async function loadHistory(){try{const r=await api(base+'/history');$('[data-history-list]').innerHTML=r.rows.map(row=>`<button class="dm-btn" data-revision="${row.revision}">v${row.revision} · ${E(row.created_at)}</button>`).join('')||'저장 이력이 없습니다.';
      panel.querySelectorAll('[data-revision]').forEach(b=>b.onclick=async()=>{if(!canManage)return;try{const r=await api(base+'/history?revision='+b.dataset.revision);mutate(()=>{doc=r.document;selected=[];layer=doc.layers[0].id;});}catch(e){status(e.message,true);}});}catch(e){status(e.message,true);}}
    function transform(){const a=chosen().filter(editable);if(!a.length)return;const angle=Number($('[data-rotate]').value),length=Number($('[data-length]').value);let transformed=C.clone(a),view=v();
      if(length>0){if(a.length!==1||!['line','dimension','arrow'].includes(a[0].type)||!view.fpp){status('축척을 설정하고 직선·거리·화살표 하나를 선택하세요.',true);return;}const e=transformed[0],ft=length/(doc.unit==='mm'?304.8:doc.unit==='m'?.3048:1),p=e.points[0],q=e.points[1],t=Math.atan2((q[1]-p[1])*view.hPt,(q[0]-p[0])*view.wPt);e.points[1]=[p[0]+ft/view.fpp*Math.cos(t)/view.wPt,p[1]+ft/view.fpp*Math.sin(t)/view.hPt];}
      if(angle){if(transformed.some(x=>['rect','ellipse','cloud','text'].includes(x.type))){status('사각형·타원·구름·문자의 회전은 지원하지 않습니다. 선이나 다각형을 선택하세요.',true);return;}const b=C.bounds(transformed.flatMap(e=>e.points)),cx=(b.x+b.right)/2,cy=(b.y+b.bottom)/2,t=angle*Math.PI/180;
        transformed.forEach(e=>e.points=e.points.map(p=>{const x=(p[0]-cx)*view.wPt,y=(p[1]-cy)*view.hPt;return[cx+(x*Math.cos(t)-y*Math.sin(t))/view.wPt,cy+(x*Math.sin(t)+y*Math.cos(t))/view.hPt];}));}
      if(transformed.some(e=>e.points.some(p=>p.some(n=>!Number.isFinite(n)||n<0||n>1)))){status('변경하면 도형이 도면 밖으로 나갑니다.',true);return;}mutate(()=>transformed.forEach(e=>Object.assign(objects().find(x=>x.id===e.id),e)));}
    function remove(){mutate(()=>{doc.entities=objects().filter(e=>!selected.includes(e.id)||!editable(e));selected=[];});}
    function duplicate(){mutate(()=>{const copies=C.translate(chosen().filter(editable),.012,.012).map(e=>({...e,id:id()}));doc.entities.push(...copies);selected=copies.map(e=>e.id);});}
    async function save(){if(!dirty||saving||conflict||!canManage)return;saving=true;const snapshot=C.clone(doc),token=JSON.stringify(snapshot);renderPanel();
      try{const r=await api(base,'PUT',{revision,document:snapshot});revision=r.revision;dirty=JSON.stringify(doc)!==token;saving=false;if(mode==='edit')renderPanel();if(mode==='edit')status(dirty?'새 변경이 남아 있습니다.':'저장됨 · 버전 '+revision);}
      catch(e){saving=false;conflict=e.status===409;if(mode==='edit'){renderPanel();status(e.message,true);}} }
    function point(ev){const p=host.toWorld(ev.clientX,ev.clientY),view=v();p[0]=Math.max(0,Math.min(1,p[0]));p[1]=Math.max(0,Math.min(1,p[1]));snapLabel='';
      let q=p;const prev=draft.at(-1);if(prev&&(ortho||ev.shiftKey))q=C.constrain(prev,q,view.W,view.H,ortho?Math.PI/2:Math.PI/12);
      if(snap&&!ev.altKey&&!['pen','highlight'].includes(tool)){const hit=C.snap(q,shown().filter(e=>!selected.includes(e.id)),view.W,view.H,view.k);if(hit){q=hit.point;snapLabel=hit.label;}}
      return q.map(n=>Math.max(0,Math.min(1,n)));}
    function intercept(ev){return active&&mode==='edit'&&tool!=='hand'&&!ev.target.closest('.ds-toolbar,.dm-zoom,.dm-form,.dm-pop,.dm-tools');}
    function down(ev){if(!intercept(ev)||ev.button!==0)return;ev.preventDefault();ev.stopImmediatePropagation();if(gesture){gesture=null;draft=[];render();return;}svg.setPointerCapture(ev.pointerId);if(tool!=='select'&&!draft.length&&selected.length){selected=[];renderPanel();}const p=point(ev);hover=null;
      if(tool==='select'){const vertex=ev.target.closest('[data-vertex]'),hit=ev.target.closest('[data-entity]');
        if(vertex&&selected.length===1&&editable(chosen()[0])){gesture={kind:'vertex',index:Number(vertex.dataset.vertex),start:p,before:C.clone(doc),id:ev.pointerId};return;}
        if(hit){const key=hit.dataset.entity;if(ev.ctrlKey||ev.metaKey)selected=selected.includes(key)?selected.filter(k=>k!==key):[...selected,key];else if(!selected.includes(key))selected=[key];gesture={kind:'move',start:p,before:C.clone(doc),items:C.clone(chosen().filter(editable)),id:ev.pointerId};}
        else{if(!ev.ctrlKey&&!ev.metaKey)selected=[];gesture={kind:'marquee',start:p,now:p,id:ev.pointerId};}render();renderPanel();return;}
      if(!canManage||conflict)return;
      if(['pen','highlight'].includes(tool)){draft=[p];gesture={kind:'pen',id:ev.pointerId};render();return;}
      gesture={kind:'click',start:p,id:ev.pointerId};
    }
    function move(ev){if(!intercept(ev))return;ev.stopImmediatePropagation();const p=point(ev);hover=p;
      if(gesture?.id===ev.pointerId){if(gesture.kind==='move'){const moved=C.translate(gesture.items,p[0]-gesture.start[0],p[1]-gesture.start[1]);moved.forEach(e=>Object.assign(objects().find(x=>x.id===e.id),e));}
        else if(gesture.kind==='vertex')chosen()[0].points[gesture.index]=p;
        else if(gesture.kind==='marquee')gesture.now=p;
        else if(gesture.kind==='pen'&&draft.length<1000&&C.dist(draft.at(-1),p,v().W,v().H)*v().k>2)draft.push(p);}
      render();
    }
    function up(ev){if(!intercept(ev))return;ev.stopImmediatePropagation();if(!gesture||gesture.id!==ev.pointerId)return;const g=gesture;gesture=null;
      if(ev.type==='pointercancel'){if(g.before)doc=g.before;draft=[];hover=null;render();return;}
      if(g.kind==='move'||g.kind==='vertex'){if(JSON.stringify(doc)!==JSON.stringify(g.before))changed(g.before);}
      else if(g.kind==='marquee'){const b=C.bounds([g.start,g.now]);shown().forEach(e=>{const a=C.bounds(e.points);if(a.x>=b.x&&a.right<=b.right&&a.y>=b.y&&a.bottom<=b.bottom&&!selected.includes(e.id))selected.push(e.id);});renderPanel();}
      else if(g.kind==='pen'){draft=C.simplify(draft,.5/v().k,v().W,v().H);finish();}
      else if(g.kind==='click'){const p=point(ev);if(draft.length&&C.dist(draft.at(-1),p,v().W,v().H)*v().k<2)return;draft.push(p);hover=null;
        if(tool==='text'||(['dimension','line','rect','ellipse','cloud','arrow'].includes(tool)&&draft.length===2)||(tool==='angle'&&draft.length===3))finish();}
      render();
    }
    function finish(){const min=tool==='text'?1:['polygon','angle'].includes(tool)?3:2;if(draft.length<min)return;const l=layers().find(l=>l.id===layer);if(!l||l.locked||!l.visible){host.hint('보이고 잠기지 않은 레이어를 선택하세요.');return;}
      let text=$('[data-text]')?.value||newText||'';if(tool==='text'&&!text.trim()){host.hint('오른쪽 문자 칸에 내용을 입력하고 Enter를 누르세요.');$('[data-text]')?.focus();return;}
      const item={...style,id:id(),type:tool,points:C.clone(draft),text,layer};if(tool==='highlight'){item.width=Math.max(item.width,12);item.opacity=.35;}
      mutate(()=>{doc.entities.push(item);selected=[item.id];draft=[];hover=null;});
    }
    function key(ev){if(!active)return;const input=ev.target.closest('input,textarea,select,[contenteditable=true]');
      if((ev.ctrlKey||ev.metaKey)&&ev.key.toLowerCase()==='s'){ev.preventDefault();ev.stopImmediatePropagation();if(mode==='edit'&&input?.matches('[data-text],[data-style],[data-name],[data-unit],[data-precision]'))input.dispatchEvent(new Event('change',{bubbles:true}));save();return;}
      if(input){if(ev.key==='Enter'&&ev.target.matches('[data-text]')&&draft.length){ev.preventDefault();ev.stopImmediatePropagation();finish();}else ev.stopPropagation();return;}
      if(ev.key==='Escape'){ev.preventDefault();ev.stopImmediatePropagation();if(draft.length){draft=[];hover=null;render();}else if(selected.length){selected=[];render();renderPanel();}else deactivate();return;}
      if(mode!=='edit')return;
      if((ev.ctrlKey||ev.metaKey)&&['z','y','d','a'].includes(ev.key.toLowerCase())){ev.preventDefault();ev.stopImmediatePropagation();const k=ev.key.toLowerCase();if(k==='a'){selected=shown().map(e=>e.id);render();renderPanel();}else if(k==='d')duplicate();else history(k==='z'&&!ev.shiftKey);return;}
      if(ev.key==='Enter'){ev.preventDefault();ev.stopImmediatePropagation();finish();}if(ev.key==='Delete'||ev.key==='Backspace'){ev.preventDefault();ev.stopImmediatePropagation();remove();}
    }
    async function snapshot(scope){const view=v(),full=await host.snapshot();let b={x:0,y:0,right:1,bottom:1};
      if(scope==='selection'){const a=chosen();if(!a.length)throw new Error('먼저 도형을 선택하세요.');b=C.bounds(a.flatMap(e=>C.vertices(e)));const pad=.03;b={x:Math.max(0,b.x-pad),y:Math.max(0,b.y-pad),right:Math.min(1,b.right+pad),bottom:Math.min(1,b.bottom+pad)};}
      if(scope==='viewport'){const r=stage.getBoundingClientRect(),a=host.toWorld(r.left,r.top),z=host.toWorld(r.right,r.bottom);b={x:Math.max(0,a[0]),y:Math.max(0,a[1]),right:Math.min(1,z[0]),bottom:Math.min(1,z[1])};}
      const w=Math.round((b.right-b.x)*view.W),h=Math.round((b.bottom-b.y)*view.H);if(w<2||h<2)throw new Error('도면이 보이는 영역을 선택하세요.');const output=document.createElement('canvas');output.width=w;output.height=h+70;const ctx=output.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,w,h+70);ctx.drawImage(full,b.x*view.W,b.y*view.H,w,h,0,70,w,h);ctx.fillStyle='#0f172a';ctx.font='bold 18px Arial';ctx.fillText(host.sheetNo+' · '+(dirty?'편집 중':'v'+revision),14,26,w-28);ctx.font='13px Arial';ctx.fillText('Scale: '+(host.scaleLabel()||'Uncalibrated')+' | '+new Date().toLocaleString(),14,50,w-28);return output;}
    function renderShare(){if(previewUrl)URL.revokeObjectURL(previewUrl);preview=null;previewUrl=null;sendId=null;sent=false;panel.innerHTML=`<div class="ds-row"><h3 style="flex:1">도면 바로 보내기</h3><button class="dm-btn" data-edit>편집</button><button class="dm-btn" data-exit>✕</button></div>
      <label>첨부 범위<select data-scope><option value="viewport">현재 보이는 화면</option><option value="full">전체 도면</option><option value="selection">선택한 도형 주변</option></select></label>
      <div class="ds-row"><label>파일 형식<select data-format><option value="png">PNG 이미지</option><option value="pdf">PDF</option></select></label><button class="dm-btn" data-preview>미리보기 만들기</button></div>
      <div data-preview-area></div><div class="ds-row"><button class="dm-btn" data-download disabled>파일 저장</button><button class="dm-btn" data-native disabled>다른 앱 공유</button></div>
      <h4>보낼 곳</h4><label>전송 방식<select data-channel><option value="chat">ERP 메신저</option><option value="mail">메일</option></select></label>
      <div data-chat-fields><label>대화방<select data-room><option value="">대화방을 선택하세요</option></select></label><div class="ds-row"><button class="dm-btn" data-messages>대화 새로고침</button><a data-room-link target="_blank" rel="noopener">대화방 열기</a></div><div class="ds-chat" data-chat>대화방을 선택하면 최근 대화를 볼 수 있습니다.</div></div>
      <div data-mail-fields style="display:none"><label>수신 이메일<input data-to type="email" placeholder="name@example.com"></label><p class="ds-muted" data-from></p></div>
      <label>제목<input data-subject maxlength="200" value="${E(host.sheetNo+' 도면 검토')}"></label><label>메시지<textarea data-body rows="4" maxlength="3500" placeholder="작업 내용과 요청 사항을 입력하세요"></textarea></label>
      <div class="ds-status" data-status>첨부 미리보기와 수신처를 확인한 뒤 보내세요.</div><button class="dm-btn primary" data-send disabled>첨부와 함께 보내기</button><p class="ds-muted">메일은 ERP 발신 계정으로 발송됩니다. 다른 앱 공유는 기기에서 지원하는 앱을 선택합니다.</p>`;
      $('[data-edit]').onclick=()=>activate('edit');$('[data-exit]').onclick=deactivate;
      $('[data-preview]').onclick=preparePreview;$('[data-download]').onclick=()=>{if(preview)download(preview.blob,preview.name);};
      $('[data-native]').onclick=async()=>{if(!preview)return;const file=new File([preview.blob],preview.name,{type:preview.blob.type});try{await navigator.share({files:[file],title:$('[data-subject]').value,text:$('[data-body]').value});status('공유 앱으로 전달했습니다. 앱에서 전송 결과를 확인하세요.');}catch(e){if(e.name!=='AbortError')status('이 환경에서는 파일 저장 후 메신저에 첨부하세요.',true);}};
      $('[data-channel]').onchange=()=>{$('[data-chat-fields]').style.display=$('[data-channel]').value==='chat'?'':'none';$('[data-mail-fields]').style.display=$('[data-channel]').value==='mail'?'':'none';};
      $('[data-scope]').onchange=clearPreview;$('[data-format]').onchange=clearPreview;$('[data-send]').onclick=send;$('[data-room]').onchange=loadMessages;$('[data-messages]').onclick=loadMessages;
      if(!options)api(base+'/share-options').then(r=>{options=r;fillOptions();}).catch(e=>status(e.message,true));else fillOptions();
    }
    function fillOptions(){if(mode!=='share'||!options||!$('[data-room]'))return;$('[data-room]').innerHTML='<option value="">대화방을 선택하세요</option>'+options.rooms.map(r=>`<option value="${r.id}">${E(r.name)}</option>`).join('');$('[data-from]').textContent=options.mailReady?'발신: '+options.mailFrom:'메일 발송 설정이 필요합니다.';}
    function clearPreview(){preview=null;sendId=null;sent=false;if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl=null;$('[data-preview-area]').innerHTML='';$('[data-send]').disabled=true;$('[data-download]').disabled=true;$('[data-native]').disabled=true;}
    async function preparePreview(){if(sending)return;clearPreview();$('[data-preview]').disabled=true;const scope=$('[data-scope]').value,fmt=$('[data-format]').value;$('[data-scope]').disabled=true;$('[data-format]').disabled=true;try{const c=await snapshot(scope),blob=fmt==='pdf'?pdfBlob(c):await new Promise(resolve=>c.toBlob(resolve,'image/png'));if(dead||!active||mode!=='share')return;if(!blob)throw new Error('이미지를 만들지 못했습니다.');preview={blob,name:host.sheetNo+'.'+fmt};previewUrl=URL.createObjectURL(await new Promise(resolve=>c.toBlob(resolve,'image/png')));$('[data-preview-area]').innerHTML=`<img class="ds-preview" src="${previewUrl}" alt="전송할 도면 미리보기"><p class="ds-muted">${E(preview.name)} · ${(blob.size/1048576).toFixed(2)} MB</p>`;$('[data-download]').disabled=false;$('[data-send]').disabled=!canManage;$('[data-native]').disabled=!(navigator.canShare&&navigator.canShare({files:[new File([blob],preview.name,{type:blob.type})]}));status('이 미리보기 파일을 전송합니다.');}catch(e){status(e.message,true);}finally{if($('[data-preview]')){$('[data-preview]').disabled=false;$('[data-scope]').disabled=false;$('[data-format]').disabled=false;}}}
    async function loadMessages(){const room=$('[data-room]').value;if(!room)return;try{const r=await api(base+'/messages?room='+encodeURIComponent(room));if(!active||mode!=='share'||$('[data-room]').value!==room)return;$('[data-chat]').innerHTML=r.messages.map(m=>`<p>${E(m.body)}<br><small class="ds-muted">${E(m.created_at)}</small></p>`).join('')||'아직 메시지가 없습니다.';$('[data-room-link]').href=r.url;$('[data-chat]').scrollTop=$('[data-chat]').scrollHeight;}catch(e){status(e.message,true);}}
    async function send(){if(!preview||sent||sending||!canManage)return;const channel=$('[data-channel]').value,to=$('[data-to]').value,room=$('[data-room]').value,subject=$('[data-subject]').value.trim();
      if(!subject||channel==='chat'&&!room||channel==='mail'&&(!$('[data-to]').checkValidity()||!to)){status('제목과 수신처를 확인하세요.',true);return;}
      if(channel==='mail'&&(!options?.mailReady||preview.blob.size>options.mailBudget)){status('메일 설정 또는 첨부 용량 한도를 확인하세요.',true);return;}
      const f=new FormData();sendId=sendId||id();f.set('requestId',sendId);f.set('channel',channel);if(channel==='mail')f.set('to',to);else f.set('room',room);f.set('subject',subject);f.set('body',$('[data-body]').value);f.set('attachment',preview.blob,preview.name);
      sending=true;panel.querySelectorAll('input,select,textarea,button').forEach(el=>el.disabled=true);status('전송 중…');try{const r=await api(base+'/share','POST',f);sent=true;status(channel==='chat'?'메신저에 전송했습니다.':'메일 서버에 발송을 요청했습니다.');if(channel==='chat')loadMessages();}catch(e){status(e.message+' 전송 결과를 확인하고 미리보기를 다시 만드세요.',true);}finally{sending=false;panel.querySelectorAll('input,select,textarea,button').forEach(el=>el.disabled=false);if($('[data-send]'))$('[data-send]').disabled=true;if($('[data-native]'))$('[data-native]').disabled=!(navigator.canShare&&preview&&navigator.canShare({files:[new File([preview.blob],preview.name,{type:preview.blob.type})]}));}}
    stage.addEventListener('pointerdown',down,true);stage.addEventListener('pointermove',move,true);stage.addEventListener('pointerup',up,true);stage.addEventListener('pointercancel',up,true);document.addEventListener('keydown',key,true);
    const unload=ev=>{if(dirty||sending){ev.preventDefault();ev.returnValue='';}};global.addEventListener('beforeunload',unload);
    api(base).then(r=>{if(dead)return;doc=r.document;revision=r.revision;canManage=r.canManage;layer=doc.layers[0].id;render();}).catch(e=>host.hint('편집 자료 로드 실패: '+e.message));
    return {activate,render,pauseForCalibration:deactivate,beforeClose:()=>!sending&&(!dirty||global.confirm('저장하지 않은 편집 내용이 있습니다. 닫을까요?')),
      exportSvg:()=>doc?shown().map(e=>C.render(e,v(),doc)).join(''):'',destroy(){dead=true;if(previewUrl)URL.revokeObjectURL(previewUrl);stage.removeEventListener('pointerdown',down,true);stage.removeEventListener('pointermove',move,true);stage.removeEventListener('pointerup',up,true);stage.removeEventListener('pointercancel',up,true);document.removeEventListener('keydown',key,true);global.removeEventListener('beforeunload',unload);}};
  }
  global.DrawingStudio={mount,pdfBlob};
})(window);
