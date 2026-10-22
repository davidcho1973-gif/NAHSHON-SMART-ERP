(function(global){
  'use strict';
  const C=global.DrawingStudioCore, E=C.esc;
  const TYPES={select:['↖','선택·수정'],hand:['✋','화면 이동'],dimension:['↔','길이 재기'],angle:['∠','각도'],line:['╱','직선'],polyline:['⌁','연속선'],polygon:['⬡','면적 재기'],rect:['▭','사각형'],ellipse:['○','타원'],cloud:['☁','구름'],arrow:['↗','화살표'],text:['T','문자'],pen:['✎','펜'],highlight:['▰','강조']};
  const id=()=>global.crypto.randomUUID();
  TYPES.arc=['⌒','세 점 호'];TYPES.symbol=['⊕','개수 기호'];
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
    let recovery=null,saveTimer,savePromise=null,cutoutId=null,vertexIndex=null;
    const focusStage=()=>stage.focus({preventScroll:true});
    stage.tabIndex=0;
    try{recovery=global.ClaimSaveSafety?.recovery('drawing-overlay:'+host.sheetId);}catch(_){host.hint('기기 복구 저장을 사용할 수 없습니다. 서버 저장을 확인하세요.');}
    const writer=global.ClaimSaveSafety?new global.ClaimSaveSafety.Writer(request=>{preserve();return api(base,'PUT',request);}):null;
    function preserve(){if(!recovery||!doc)return false;try{recovery.write({document:doc,revision,pending:writer?.pending,draft,tool,cutoutId});return true;}catch(_){host.hint('기기 저장 공간 부족 · 서버 저장 전 창을 닫지 마세요.');return false;}}
    function scheduleSave(){preserve();clearTimeout(saveTimer);if(!conflict)saveTimer=setTimeout(save,1200);}
    let style={color:'#ef4444',fill:'none',width:1.5,opacity:1,dash:'solid',fontSize:12},snapLabel='';
    const panelSections={};
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.classList.add('ds-overlay');svg.style.pointerEvents='none';world.appendChild(svg);
    const panel=document.createElement('aside');panel.className='ds-panel';panel.style.display='none';panel.setAttribute('aria-label','도면 편집 및 공유');wrap.querySelector('.dm-body').appendChild(panel);
    const toolbar=document.createElement('div');toolbar.className='ds-toolbar';toolbar.style.display='none';stage.appendChild(toolbar);
    const footer=document.createElement('div');footer.className='ds-footer';footer.style.display='none';stage.appendChild(footer);
    const $=s=>panel.querySelector(s),v=()=>host.view(),objects=()=>doc?.entities||[],layers=()=>doc?.layers||[];
    const shown=()=>objects().filter(e=>layers().find(l=>l.id===e.layer)?.visible);
    const editable=e=>canManage&&!layers().find(l=>l.id===e.layer)?.locked;
    const chosen=()=>objects().filter(e=>selected.includes(e.id));
    function status(text,error=false){const el=$('[data-status]');if(el){el.textContent=text;el.classList.toggle('error',error);}}
    function changed(before){if(before){undo.push(before);if(undo.length>60)undo.shift();redo=[];}dirty=true;preview=null;sent=false;sendId=null;scheduleSave();render();renderPanel();}
    function mutate(fn){if(!canManage||conflict)return;const before=C.clone(doc),selection=[...selected],pending={draft:C.clone(draft),tool,cutoutId,layer};try{fn();const entities=[...doc.entities,...(doc.blocks||[]).flatMap(b=>b.entities)];if(doc.entities.length>2000||entities.reduce((sum,e)=>sum+e.points.length+(e.holes||[]).reduce((n,h)=>n+h.length,0),0)>30000)throw Error('도형 2,000개 또는 점 30,000개 한도를 초과합니다.');const invalid=entities.map(geometryError).find(Boolean);if(invalid)throw Error(invalid);}catch(e){doc=before;selected=selection;draft=pending.draft;tool=pending.tool;cutoutId=pending.cutoutId;layer=pending.layer;render();renderPanel();renderToolbar();preserve();status(e.message,true);host.hint(e.message);return;}if(JSON.stringify(before)!==JSON.stringify(doc))changed(before);}
    function history(back){if(!canManage||conflict)return;const from=back?undo:redo,to=back?redo:undo;if(!from.length)return;to.push(C.clone(doc));doc=from.pop();selected=[];draft=[];dirty=true;preview=null;scheduleSave();render();renderPanel();}
    function setTool(t){if(draft.length&&!global.confirm('그리는 중인 도형을 취소하고 도구를 바꿀까요?'))return false;if(tool!==t)newText='';tool=t;draft=[];cutoutId=null;hover=null;gesture=null;if(!['select','hand'].includes(t))selected=[];renderToolbar();renderPanel();render();focusStage();if(['dimension','polygon'].includes(t)&&!v().fpp)host.hint('축척 미설정 · 도형은 보관되지만 실제 수량은 축척 설정 후 계산합니다.');return true;}
    function activate(next='edit'){if(sending)return;if(!doc){host.hint('편집 자료를 불러오는 중입니다.');return;}active=true;wrap.classList.add('ds-active');mode=next;panel.style.display='block';toolbar.style.display=next==='edit'?'grid':'none';footer.style.display=next==='edit'?'block':'none';
      host.side.style.display='none';host.tools.style.display='none';host.setHand();renderToolbar();renderPanel();render();focusStage();}
    function deactivate(){if(sending)return;if(draft.length)preserve();active=false;wrap.classList.remove('ds-active');panel.style.display='none';toolbar.style.display='none';footer.style.display='none';host.side.style.display='';host.tools.style.display=host.canManage?'':'none';svg.style.pointerEvents='none';render();}
    function renderToolbar(){const button=key=>{const t=TYPES[key];return `<button class="dm-btn ${key===tool?'on':''}" data-tool="${key}" title="${t[1]}" ${!canManage&&!['select','hand'].includes(key)?'disabled':''}><b>${t[0]}</b>${t[1]}</button>`;};
      toolbar.innerHTML='<span class="ds-tool-group">선택 / 이동</span>'+['select','hand'].map(button).join('')+'<span class="ds-tool-group">측정 / 작도</span>'+['dimension','polygon','line','rect','arrow','text'].map(button).join('')+
        `<details class="ds-more-tools" ${['angle','polyline','ellipse','cloud','pen','highlight'].includes(tool)?'open':''}><summary>도구 더보기</summary><div>${['angle','polyline','ellipse','cloud','pen','highlight'].map(button).join('')}</div></details>`+
        `<button class="dm-btn ds-wide" data-finish>그리기 완료 ↵</button><button class="dm-btn ds-wide" data-cancel>취소 Esc</button>`;
      toolbar.querySelectorAll('[data-tool]').forEach(b=>b.onclick=()=>setTool(b.dataset.tool));toolbar.querySelector('[data-finish]').onclick=()=>{finish();focusStage();};toolbar.querySelector('[data-cancel]').onclick=()=>{draft=[];cutoutId=null;preserve();render();focusStage();};}
    function summary(e){const m=C.measure(e,v().wPt,v().hPt,v().fpp);if(e.type==='angle')return m.angle===null?'각도 미정':m.angle.toFixed(doc.precision)+'°';
      if(['rect','polygon','ellipse','cloud'].includes(e.type))return C.format(m.area,doc.unit,doc.precision,true)+(e.holes?.length?' (공제 후)':'')+' · 둘레 '+C.format(m.length,doc.unit,doc.precision);
      if(e.type==='symbol')return '1 EA · '+(e.text||e.symbol||'기호');
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
      panel.querySelectorAll('[data-panel-section]').forEach(el=>panelSections[el.dataset.panelSection]=el.open);
      const picked=chosen(),e=picked.length===1?picked[0]:null,s=e||style;
      panel.innerHTML=`<div class="ds-row"><h3 style="flex:1">도면 스튜디오</h3><button class="dm-btn" data-exit title="편집 패널 접기">✕</button></div>
        <div class="ds-row"><button class="dm-btn primary" data-save ${!dirty||saving||!canManage||conflict?'disabled':''}>${saving?'저장 중…':'저장'}</button><button class="dm-btn" data-undo title="실행 취소 (Ctrl+Z)" ${!undo.length?'disabled':''}>↶</button><button class="dm-btn" data-redo title="다시 실행 (Ctrl+Shift+Z)" ${!redo.length?'disabled':''}>↷</button><button class="dm-btn" data-share>공유 ↗</button></div>
        <div class="ds-status ${conflict?'error':''}" data-status>${conflict?'저장 충돌: 작업 JSON을 보관하고 다시 여세요.':dirty?'저장하지 않은 변경 있음':`저장됨 · 버전 ${revision}`}</div>
        ${canManage?`<p class="ds-muted"><a href="/cad?sheet_id=${Number(host.sheetId)}" target="_blank" rel="noopener">이 원본 PDF로 새 CAD 편집본 열기 ↗</a><br>면적 합치기·빼기·확대 상세도·ERP 연결을 사용합니다. 기존 스튜디오 표시는 이곳에 유지됩니다.</p>`:''}
        <div class="ds-calibration">
          <strong>${v().fpp?'측정 기준 설정됨':'먼저 도면 치수로 축척을 맞추세요'}</strong>
          <div class="ds-muted">${v().fpp?E(host.scaleLabel()||'실제 치수 기준'):'도면의 치수선 양 끝을 찍고 실제 길이를 입력합니다.'}</div>
          <button class="dm-btn primary" data-reference-scale ${!canManage?'disabled':''}>📐 두 점 찍고 실제 길이 입력</button>
          <button class="dm-btn" data-read-scale ${!canManage?'disabled':''}>도면의 치수 숫자 읽기</button>
          ${e&&['line','dimension','arrow'].includes(e.type)?`<button class="dm-btn" data-selected-scale ${!canManage?'disabled':''}>선택한 선을 기준 치수로 사용</button>`:''}
        </div>
        <div class="ds-grid"><label>측정 단위<select data-unit>${['ft','ft-in','m','mm'].map(u=>`<option ${doc.unit===u?'selected':''}>${u}</option>`).join('')}</select></label><label>소수 자릿수<select data-precision>${[0,1,2,3,4].map(n=>`<option ${doc.precision===n?'selected':''}>${n}</option>`).join('')}</select></label></div>
        <div class="ds-row"><button class="dm-btn ${snap?'on':''}" data-snap>스냅</button><button class="dm-btn ${ortho?'on':''}" data-ortho>직교</button><button class="dm-btn ${grid?'on':''}" data-grid>격자</button></div>
        <div class="ds-muted">스냅은 작도한 도형의 끝점·중점·선 위에 맞춥니다.</div>
        <h4>${picked.length?picked.length+'개 선택':'새 도형 스타일'}</h4>
        ${e?`<div class="ds-status ds-measurement">${E(summary(e))}</div>`:'<div class="ds-muted">도형을 선택하면 치수를 수정할 수 있습니다.</div>'}
        <div class="ds-grid"><label>선 길이 (${doc.unit==='ft-in'?'ft / in':doc.unit})<input data-length type="text" placeholder="예: 12&#39;-6&quot;"></label><label>회전 (도)<input data-rotate type="number" value="0" step="15"></label></div>
        ${e&&['rect','ellipse','cloud'].includes(e.type)?`<div class="ds-grid"><label>가로 (${doc.unit})<input data-size-width type="text" placeholder="${E(C.format(Math.abs(e.points[1][0]-e.points[0][0])*v().wPt*(v().fpp||0),doc.unit,doc.precision))}"></label><label>세로 (${doc.unit})<input data-size-height type="text" placeholder="${E(C.format(Math.abs(e.points[1][1]-e.points[0][1])*v().hPt*(v().fpp||0),doc.unit,doc.precision))}"></label></div>`:''}
        <div class="ds-muted">숫자는 선택 단위로, 12′-6″ · 300 mm처럼 단위를 붙여도 입력할 수 있습니다.</div>
        <div class="ds-row"><button class="dm-btn primary" data-transform ${!picked.length?'disabled':''}>치수 적용</button><button class="dm-btn" data-delete ${!picked.length?'disabled':''}>삭제</button><button class="dm-btn" data-duplicate ${!picked.length?'disabled':''}>복제</button></div>
        <label>문자 / 도형 이름<input data-text maxlength="2000" placeholder="문자 도구를 고르고 도면을 클릭하세요" value="${E(e?.text??newText)}"></label>
        <details data-panel-section="style" ${panelSections.style?'open':''}><summary>색상 · 선 · 정렬</summary>
        <div class="ds-grid"><label>선 색<input data-style="color" type="color" value="${E(s.color)}"></label><label>채우기 색<input data-style="fill" type="color" value="${s.fill==='none'?'#ffffff':E(s.fill)}"></label><label>선 굵기 (pt)<input data-style="width" type="number" min=".25" max="20" step=".25" value="${s.width}"></label><label>문자 크기 (pt)<input data-style="fontSize" type="number" min="6" max="72" value="${s.fontSize}"></label><label>불투명도<input data-style="opacity" type="range" min=".05" max="1" step=".05" value="${s.opacity}"></label><label>선 종류<select data-style="dash">${[['solid','실선'],['dash','파선'],['dot','점선']].map(([k,t])=>`<option value="${k}" ${s.dash===k?'selected':''}>${t}</option>`).join('')}</select></label></div>
        <div class="ds-row"><button class="dm-btn" data-clear-fill>채우기 없음</button></div>
        <div class="ds-row"><button class="dm-btn" data-front>맨 앞으로</button><button class="dm-btn" data-back>맨 뒤로</button><button class="dm-btn" data-align>왼쪽 정렬</button></div>
        </details><details data-panel-section="layers" ${panelSections.layers?'open':''}><summary>레이어</summary><div data-layers>${layers().map(l=>`<div class="ds-row ds-layer ${layer===l.id?'active':''}" data-layer="${E(l.id)}"><input type="radio" name="ds-layer" ${layer===l.id?'checked':''} style="width:auto" data-active><input type="text" value="${E(l.name)}" data-name maxlength="80"><button class="dm-btn" data-visible title="표시/숨기기">${l.visible?'◉':'○'}</button><button class="dm-btn" data-lock title="잠금/해제">${l.locked?'🔒':'🔓'}</button></div>`).join('')}</div><button class="dm-btn" data-add-layer>＋ 레이어</button></details>
        <details><summary>도형 목록 (${objects().length})</summary><div class="ds-object-list">${objects().map(x=>`<button class="dm-btn ${selected.includes(x.id)?'on':''}" data-object="${E(x.id)}">${E(TYPES[x.type]?.[1]||x.type)} · ${E(x.text||summary(x))}</button>`).join('')}</div></details>
        <details><summary>저장 이력 / 작업 보관</summary><div class="ds-row"><button class="dm-btn" data-history>수정 이력</button><button class="dm-btn" data-json>작업 JSON</button></div><div class="ds-history" data-history-list></div><div class="ds-muted">이전 버전 복원도 새로운 버전으로 저장됩니다. 변경 이력은 유지됩니다.</div></details>`;
      $('[data-exit]').onclick=deactivate;$('[data-save]').onclick=save;$('[data-undo]').onclick=()=>history(true);$('[data-redo]').onclick=()=>history(false);$('[data-share]').onclick=()=>activate('share');
      $('[data-reference-scale]').onclick=()=>host.calibrate();
      $('[data-read-scale]').onclick=()=>host.readDimension();
      if($('[data-selected-scale]'))$('[data-selected-scale]').onclick=()=>host.calibrate(C.clone(e.points));
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
      render2dPanel();
      if(!canManage)panel.querySelectorAll('input,select,[data-style],[data-add-layer],[data-transform]').forEach(el=>el.disabled=true);
    }
    function calibration(){return {feetPerPoint:v().fpp,widthPt:v().wPt,heightPt:v().hPt,label:String(host.scaleLabel()||'직접 설정').slice(0,200)};}
    function geometryError(e){
      if(e.points.some(p=>p.some(n=>!Number.isFinite(n)||n<0||n>1)))return '도형이 도면 밖으로 나갑니다.';
      if(e.type==='polygon'){const error=C.polygonError(e.points);if(error)return error;}
      const prior=[];for(const hole of e.holes||[]){const error=C.holeError({...e,holes:prior},hole);if(error)return error;prior.push(hole);}return '';
    }
    function render2dPanel(){
      const a=chosen(),one=a.length===1?a[0]:null,enabled=canManage&&!conflict;
      const extra=document.createElement('div');extra.className='ds-advanced';
      extra.innerHTML=`<details data-panel-section="precision" ${panelSections.precision?'open':''}><summary>정밀 2D 도구</summary>
        <div class="ds-row"><button class="dm-btn" data-arc>⌒ 세 점 호</button><button class="dm-btn" data-symbol-tool>⊕ 개수 기호</button><select data-symbol aria-label="기호 종류"><option value="●">개수 ●</option><option value="L">조명 L</option><option value="S">스위치 S</option><option value="R">콘센트 R</option><option value="D">문 D</option></select></div>
        <div class="ds-grid"><label>정확한 다음 선 길이<input data-next-length placeholder="예: 10 ft"></label><label>방향 (도)<input data-next-angle type="number" value="0"></label></div><button class="dm-btn" data-next-point>첫 점에서 지정 길이·방향으로 추가</button>
        <div class="ds-muted">선·연속선·면적: 첫 점을 찍고 숫자로 다음 점을 추가할 수 있습니다. 방향 0°=오른쪽, 90°=아래.</div>
        <label>간격 복사 / 오프셋<input data-offset-value placeholder="예: 6 in · 음수는 안쪽"></label><button class="dm-btn" data-offset ${!one?'disabled':''}>선택 도형 간격 복사</button>
        <div class="ds-grid"><label>추가 복사 개수<input data-array-count type="number" value="1" min="1" max="100"></label><label>가로 간격<input data-array-x placeholder="10 ft"></label><label>세로 간격<input data-array-y placeholder="0"></label></div><button class="dm-btn" data-array ${!a.length?'disabled':''}>일정 간격 반복 복사</button>
        <div class="ds-row"><button class="dm-btn" data-mirror="x">좌우 반전</button><button class="dm-btn" data-mirror="y">상하 반전</button></div><div class="ds-row"><button class="dm-btn" data-group ${a.length<2?'disabled':''}>그룹 묶기</button><button class="dm-btn" data-ungroup ${!a.length?'disabled':''}>그룹 풀기</button></div>
        <label>선택한 도형을 옮길 레이어<select data-move-layer>${layers().map(l=>`<option value="${E(l.id)}" ${one?.layer===l.id?'selected':''}>${E(l.name)}</option>`).join('')}</select></label><button class="dm-btn" data-assign-layer ${!a.length?'disabled':''}>선택 도형에 레이어 적용</button>
        ${one&&['polygon','polyline'].includes(one.type)?`<label>꼭짓점 번호<select data-vertex-index>${one.points.map((p,i)=>`<option value="${i}" ${vertexIndex===i?'selected':''}>${i+1}</option>`).join('')}</select></label><div class="ds-row"><button class="dm-btn" data-insert-vertex>다음 변에 점 추가</button><button class="dm-btn" data-remove-vertex>이 점 삭제</button></div>`:''}
        ${one&&['polygon','rect','ellipse'].includes(one.type)?`<button class="dm-btn" data-hole>내부 영역 그려서 공제</button>${(one.holes||[]).map((h,i)=>`<button class="dm-btn" data-remove-hole="${i}">공제 ${i+1} 삭제</button>`).join('')}`:''}
        ${one?.type==='dimension'?`<label>치수선 간격 (도면 pt)<input data-dimension-offset type="number" min="-500" max="500" value="${one.dimensionOffset??18}"></label>`:''}
        ${a.length?'<button class="dm-btn" data-recalibrate>선택 측정에 현재 축척 적용</button>':''}
        <p class="ds-muted">측정은 작성 당시 축척을 보관합니다. 축척 변경 후 재계산은 선택하여 적용하세요.</p></details>
        <details data-panel-section="library" ${panelSections.library?'open':''}><summary>2D 반복 부품 · 저장한 화면</summary><label>이름<input data-library-name maxlength="80" placeholder="전기 기호 / 1층 동측"></label><div class="ds-row"><button class="dm-btn" data-save-block ${!a.length?'disabled':''}>선택을 부품으로 보관</button><button class="dm-btn" data-save-view>현재 화면 보관</button></div>
        ${(doc.blocks||[]).map(b=>`<div class="ds-row"><button class="dm-btn" data-insert-block="${E(b.id)}">＋ ${E(b.name)}</button><button class="dm-btn" data-delete-block="${E(b.id)}" aria-label="부품 삭제">×</button></div>`).join('')}
        ${(doc.views||[]).map(b=>`<div class="ds-row"><button class="dm-btn" data-open-view="${E(b.id)}">▣ ${E(b.name)}</button><button class="dm-btn" data-delete-view="${E(b.id)}" aria-label="저장 화면 삭제">×</button></div>`).join('')}</details>
        <details data-panel-section="files" ${panelSections.files?'open':''}><summary>파일 · 수량 내보내기</summary><div class="ds-row"><button class="dm-btn" data-csv>수량표 CSV</button><button class="dm-btn" data-svg>표시 SVG</button><button class="dm-btn" data-dxf>표시 DXF</button></div><label>작업 JSON 복구<input data-import type="file" accept=".json,application/json"></label><p class="ds-muted">SVG·DXF는 편집한 2D 표시를 내보냅니다. 원본 도면을 포함한 검토본은 공유에서 PDF·PNG로 저장합니다. 호는 연속선으로 저장됩니다.</p></details>
        ${host.onTakeoff?`<div class="ds-takeoff"><strong>공정 #${E(host.line?.lineNo||'')} · ${E(host.line?.unit||'')}</strong><p class="ds-muted">선택한 측정을 저장한 뒤 기성 작성 수량에 가져옵니다. 승인 전 작성 자료입니다.</p><button class="dm-btn primary" data-takeoff ${!a.length?'disabled':''}>선택 수량을 공정에 가져오기</button><div data-takeoff-status role="status"></div></div>`:''}`;
      panel.appendChild(extra);const takeoff=extra.querySelector('.ds-takeoff');if(takeoff)panel.insertBefore(takeoff,panel.querySelector('.ds-calibration'));
      function action(selector,fn){extra.querySelectorAll(selector).forEach(el=>el.onclick=async()=>{if(!enabled)return;try{await fn(el);focusStage();}catch(e){status(e.message,true);host.hint(e.message);}});}
      action('[data-arc]',()=>setTool('arc'));action('[data-symbol-tool]',()=>setTool('symbol'));
      action('[data-next-point]',()=>{if(!['line','dimension','polyline','polygon','arrow'].includes(tool)||!draft.length||!v().fpp)throw Error('축척을 설정하고 선 또는 면적의 첫 점을 찍으세요.');const ft=host.parseDistance($('[data-next-length]').value,doc.unit),angle=Number($('[data-next-angle]').value)*Math.PI/180;if(!(ft>0&&Number.isFinite(angle)))throw Error('양수 길이와 방향을 입력하세요.');const p=draft.at(-1),q=[p[0]+ft*Math.cos(angle)/v().fpp/v().wPt,p[1]+ft*Math.sin(angle)/v().fpp/v().hPt];if(q.some(n=>n<0||n>1))throw Error('다음 점이 도면 밖입니다.');draft.push(q);hover=null;preserve();if(['line','dimension','arrow'].includes(tool))finish();render();});
      action('[data-offset]',()=>{if(!one||!editable(one))return;const raw=$('[data-offset-value]').value.trim(),negative=raw.startsWith('-'),ft=host.parseDistance(raw.replace(/^-/,'').trim(),doc.unit);if(!(ft>0))throw Error('간격을 입력하세요.');const n=C.offset(one,negative?-ft:ft,v());n.id=id();delete n.group;mutate(()=>{doc.entities.push(n);selected=[n.id];});});
      action('[data-array]',()=>{if(!v().fpp)throw Error('축척을 설정하세요.');const count=Number($('[data-array-count]').value),rawX=$('[data-array-x]').value.trim(),rawY=$('[data-array-y]').value.trim(),distance=t=>!t||Number(t)===0?0:(t.startsWith('-')?-1:1)*host.parseDistance(t.replace(/^-/,'').trim(),doc.unit),dx=distance(rawX)/v().fpp/v().wPt,dy=distance(rawY)/v().fpp/v().hPt;if(!Number.isInteger(count)||count<1||count>100||!Number.isFinite(dx+dy)||(!dx&&!dy))throw Error('복사 개수와 간격을 확인하세요.');const copies=[];for(let i=1;i<=count;i++){const groups=new Map();a.filter(editable).forEach(e=>{const n=C.mapPoints(e,p=>[p[0]+dx*i,p[1]+dy*i]);n.id=id();if(n.group){if(!groups.has(n.group))groups.set(n.group,id());n.group=groups.get(n.group);}const error=geometryError(n);if(error)throw Error(error);copies.push(n);});}if(objects().length+copies.length>2000)throw Error('도형은 2,000개까지 보관할 수 있습니다.');mutate(()=>{doc.entities.push(...copies);selected=copies.map(e=>e.id);});});
      action('[data-mirror]',el=>{const items=a.filter(editable);if(!items.length)return;const b=C.bounds(items.flatMap(e=>e.points));mutate(()=>items.forEach(e=>Object.assign(e,C.mapPoints(e,p=>el.dataset.mirror==='x'?[b.x+b.right-p[0],p[1]]:[p[0],b.y+b.bottom-p[1]]))));});
      action('[data-group]',()=>{const group=id();mutate(()=>a.filter(editable).forEach(e=>e.group=group));});action('[data-ungroup]',()=>mutate(()=>a.filter(editable).forEach(e=>delete e.group)));
      action('[data-assign-layer]',()=>{const target=layers().find(l=>l.id===$('[data-move-layer]').value);if(target.locked)throw Error('잠기지 않은 레이어를 선택하세요.');mutate(()=>a.filter(editable).forEach(e=>e.layer=target.id));});
      action('[data-insert-vertex]',()=>{if(!editable(one))return;const i=Number($('[data-vertex-index]').value),j=(i+1)%one.points.length;if(one.type==='polyline'&&j===0)throw Error('마지막 점 앞의 변을 선택하세요.');mutate(()=>one.points.splice(i+1,0,[(one.points[i][0]+one.points[j][0])/2,(one.points[i][1]+one.points[j][1])/2]));});
      action('[data-remove-vertex]',()=>{if(!editable(one))return;const n=C.clone(one);n.points.splice(Number($('[data-vertex-index]').value),1);if(n.points.length<(n.type==='polygon'?3:2))throw Error('도형을 유지할 최소 점이 필요합니다.');const error=geometryError(n);if(error)throw Error(error);mutate(()=>Object.assign(one,n));});
      action('[data-hole]',()=>{if(!editable(one)||!setTool('polygon'))return;cutoutId=one.id;host.hint('공제할 내부 모서리를 찍고 Enter 또는 완료를 누르세요.');});
      action('[data-remove-hole]',el=>mutate(()=>{if(editable(one))one.holes.splice(Number(el.dataset.removeHole),1);}));
      const dimension=$('[data-dimension-offset]');if(dimension)dimension.onchange=()=>{if(dimension.checkValidity()&&editable(one))mutate(()=>one.dimensionOffset=Number(dimension.value));};
      action('[data-recalibrate]',()=>{if(!v().fpp)throw Error('축척이 없습니다.');if(global.confirm('선택한 도형의 측정 기준을 현재 축척으로 바꿀까요? 기존 저장 버전은 보존됩니다.'))mutate(()=>a.filter(editable).forEach(e=>e.calibration=calibration()));});
      action('[data-save-block]',()=>{const name=$('[data-library-name]').value.trim();if(!name||a.length>100||(doc.blocks||[]).length>=30)throw Error('이름을 입력하세요. 부품은 30개, 부품당 도형은 100개까지 가능합니다.');mutate(()=>{(doc.blocks||(doc.blocks=[])).push({id:id(),name,entities:C.clone(a)});});});
      action('[data-insert-block]',el=>{const block=doc.blocks.find(b=>b.id===el.dataset.insertBlock),group=id(),center=host.captureView(),b=C.bounds(block.entities.flatMap(e=>e.points)),copies=C.translate(block.entities,center.x-(b.x+b.right)/2,center.y-(b.y+b.bottom)/2).map(e=>({...e,id:id(),group,layer}));if(layers().find(l=>l.id===layer)?.locked)throw Error('잠기지 않은 레이어를 선택하세요.');mutate(()=>{doc.entities.push(...copies);selected=copies.map(e=>e.id);});});
      action('[data-delete-block]',el=>mutate(()=>doc.blocks=doc.blocks.filter(b=>b.id!==el.dataset.deleteBlock)));
      action('[data-save-view]',()=>{const name=$('[data-library-name]').value.trim(),view=host.captureView();if(view.x< -2||view.x>3||view.y< -2||view.y>3||view.zoom<.05||view.zoom>30)throw Error('도면이 보이는 위치와 배율에서 화면을 보관하세요.');if(!name||(doc.views||[]).length>=30)throw Error('이름을 입력하세요. 화면은 30개까지 가능합니다.');mutate(()=>{(doc.views||(doc.views=[])).push({id:id(),name,...view,layers:layers().filter(l=>l.visible).map(l=>l.id)});});});
      action('[data-open-view]',el=>{const view=doc.views.find(b=>b.id===el.dataset.openView);mutate(()=>layers().forEach(l=>l.visible=view.layers.includes(l.id)));host.restoreView(view);});
      action('[data-delete-view]',el=>mutate(()=>doc.views=doc.views.filter(b=>b.id!==el.dataset.deleteView)));
      action('[data-csv]',exportCsv);action('[data-svg]',()=>download(new Blob([`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${v().W} ${v().H}">${shown().map(e=>C.render(e,v(),doc)).join('')}</svg>`],{type:'image/svg+xml'}),host.sheetNo+'-annotations.svg'));action('[data-dxf]',exportDxf);
      $('[data-import]').onchange=async ev=>{try{const f=ev.target.files[0];if(!f)return;if(f.size>5*1024*1024)throw Error('작업 파일은 5 MB 이하만 복구할 수 있습니다.');const data=JSON.parse(await f.text());if(data.sheetId!==host.sheetId||!data.document)throw Error('현재 도면에서 내보낸 작업 파일을 선택하세요.');const checked=await api(base+'/validate','POST',{document:data.document});if(global.confirm('현재 편집을 이 작업 파일로 바꿀까요? 실행 취소와 서버 이력이 유지됩니다.'))mutate(()=>{doc=checked.document;selected=[];draft=[];layer=doc.layers[0].id;});}catch(e){status(e.message,true);}};
      const takeoffButton=$('[data-takeoff]');if(takeoffButton)takeoffButton.disabled=!enabled||!a.length;if(takeoffButton)takeoffButton.onclick=async()=>{if(!enabled||sending)return;const wasInert=wrap.inert;try{if(draft.length)throw Error('그리는 중인 도형을 먼저 완료하세요.');const result=C.takeoff(chosen(),host.line.unit,v()),entities=C.clone(chosen());sending=true;wrap.inert=true;takeoffButton.disabled=true;await flush();const savedRevision=revision,canvas=await snapshot('full'),blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));if(!blob)throw Error('측정 근거 이미지를 만들지 못했습니다.');await host.onTakeoff({...result,sheetId:host.sheetId,sheetNo:host.sheetNo,revision:savedRevision,entities,blob});const target=$('[data-takeoff-status]');if(target)target.textContent='기성 작성 수량과 근거 저장 완료 · 검토 요청 전';}catch(e){status(e.message,true);host.hint(e.message);}finally{sending=false;wrap.inert=wasInert;takeoffButton.disabled=false;focusStage();}};
      if(!enabled)extra.querySelectorAll('button,input,select').forEach(el=>el.disabled=true);
    }
    function exportCsv(){const escape=value=>'"'+String(value??'').replace(/^[=+@-]/,"'$&").replaceAll('"','""')+'"';const rows=[['도면','버전','도형','설명','길이(ft)','순면적(SF)','공제면적(SF)','축척(ft/pt)']];shown().forEach(e=>{const m=C.measure(e,v().wPt,v().hPt,v().fpp);rows.push([host.sheetNo,revision,e.type,e.text,m.length,m.area,m.gross===null?'':m.gross-m.area,e.calibration?.feetPerPoint||v().fpp]);});download(new Blob(['\ufeff'+rows.map(r=>r.map(escape).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}),host.sheetNo+'-takeoff.csv');}
    function exportDxf(){if(!v().fpp)throw Error('축척을 먼저 설정하세요.');const out=['0','SECTION','2','HEADER','9','$INSUNITS','70','2','0','ENDSEC','0','SECTION','2','ENTITIES'];const pair=(code,value)=>out.push(String(code),String(value).replace(/[\r\n\0]/g,' '));shown().forEach(e=>{const vv={widthPt:v().wPt,heightPt:v().hPt,feetPerPoint:v().fpp},point=p=>[p[0]*vv.widthPt*vv.feetPerPoint,(1-p[1])*vv.heightPt*vv.feetPerPoint];if(e.type==='text'||e.type==='symbol'){const p=point(e.points[0]);pair(0,'TEXT');pair(8,e.layer);pair(10,p[0]);pair(20,p[1]);pair(40,e.fontSize*vv.feetPerPoint);pair(1,(e.text||e.symbol||'').replace(/[\r\n]/g,' '));return;}[C.outline(e),...(e.holes||[])].forEach((ring,i)=>{pair(0,'POLYLINE');pair(8,e.layer);pair(66,1);pair(70,i||['polygon','rect','ellipse','cloud'].includes(e.type)?1:0);ring.forEach(q=>{const p=point(q);pair(0,'VERTEX');pair(8,e.layer);pair(10,p[0]);pair(20,p[1]);pair(30,0);});pair(0,'SEQEND');});});out.push('0','ENDSEC','0','EOF');download(new Blob([out.join('\r\n')],{type:'application/dxf'}),host.sheetNo+'-annotations-ft.dxf');}
    function exportJson(){download(new Blob([JSON.stringify({sheetId:host.sheetId,sheetNo:host.sheetNo,revision,document:doc},null,2)],{type:'application/json'}),host.sheetNo+'-edit.json');}
    async function loadHistory(){try{const r=await api(base+'/history');$('[data-history-list]').innerHTML=r.rows.map(row=>`<button class="dm-btn" data-revision="${row.revision}">v${row.revision} · ${E(row.created_at)}</button>`).join('')||'저장 이력이 없습니다.';
      panel.querySelectorAll('[data-revision]').forEach(b=>b.onclick=async()=>{if(!canManage)return;try{const r=await api(base+'/history?revision='+b.dataset.revision);mutate(()=>{doc=r.document;selected=[];layer=doc.layers[0].id;});}catch(e){status(e.message,true);}});}catch(e){status(e.message,true);}}
    function transform(){const a=chosen().filter(editable);if(!a.length)return;const angle=Number($('[data-rotate]').value),raw=$('[data-length]').value.trim();
      const widthRaw=$('[data-size-width]')?.value.trim()||'',heightRaw=$('[data-size-height]')?.value.trim()||'';
      const length=raw?host.parseDistance(raw,doc.unit):null,width=widthRaw?host.parseDistance(widthRaw,doc.unit):null,height=heightRaw?host.parseDistance(heightRaw,doc.unit):null;
      if(!Number.isFinite(angle)||[[raw,length],[widthRaw,width],[heightRaw,height]].some(([text,n])=>text&&!(Number.isFinite(n)&&n>0))){status('0보다 큰 실제 치수를 입력하세요. 예: 12′-6″ 또는 300 mm',true);return;}
      let transformed=C.clone(a),view=v();if(a.length===1&&a[0].calibration)view={...view,fpp:a[0].calibration.feetPerPoint,wPt:a[0].calibration.widthPt,hPt:a[0].calibration.heightPt};
      if(length>0){if(a.length!==1||!['line','dimension','arrow'].includes(a[0].type)||!view.fpp){status('축척을 설정하고 직선·거리·화살표 하나를 선택하세요.',true);return;}const e=transformed[0],ft=length,p=e.points[0],q=e.points[1],t=Math.atan2((q[1]-p[1])*view.hPt,(q[0]-p[0])*view.wPt);e.points[1]=[p[0]+ft/view.fpp*Math.cos(t)/view.wPt,p[1]+ft/view.fpp*Math.sin(t)/view.hPt];}
      if(widthRaw||heightRaw){if(a.length!==1||!['rect','ellipse','cloud'].includes(a[0].type)||!view.fpp){status('축척을 설정하고 사각형·타원·구름 하나를 선택하세요.',true);return;}
        const e=transformed[0],p=e.points[0],q=e.points[1],old=[...q];
        if(widthRaw)q[0]=p[0]+(q[0]<p[0]?-1:1)*width/view.fpp/view.wPt;
        if(heightRaw)q[1]=p[1]+(q[1]<p[1]?-1:1)*height/view.fpp/view.hPt;
        if(e.holes)e.holes=e.holes.map(h=>h.map(r=>[p[0]+(r[0]-p[0])*(q[0]-p[0])/(old[0]-p[0]),p[1]+(r[1]-p[1])*(q[1]-p[1])/(old[1]-p[1])]));
      }
      if(angle){transformed=transformed.map(e=>e.type==='rect'?{...e,type:'polygon',points:C.vertices(e)}:e);if(transformed.some(x=>['ellipse','cloud','text'].includes(x.type))){status('타원·구름·문자의 회전은 지원하지 않습니다. 선이나 다각형을 선택하세요.',true);return;}const b=C.bounds(transformed.flatMap(e=>e.points)),cx=(b.x+b.right)/2,cy=(b.y+b.bottom)/2,t=angle*Math.PI/180;
        transformed=transformed.map(e=>C.mapPoints(e,p=>{const x=(p[0]-cx)*view.wPt,y=(p[1]-cy)*view.hPt;return[cx+(x*Math.cos(t)-y*Math.sin(t))/view.wPt,cy+(x*Math.sin(t)+y*Math.cos(t))/view.hPt];}));}
      if(transformed.some(e=>e.points.some(p=>p.some(n=>!Number.isFinite(n)||n<0||n>1)))){status('변경하면 도형이 도면 밖으로 나갑니다.',true);return;}mutate(()=>transformed.forEach(e=>Object.assign(objects().find(x=>x.id===e.id),e)));}
    function remove(){mutate(()=>{doc.entities=objects().filter(e=>!selected.includes(e.id)||!editable(e));selected=[];});}
    function duplicate(){mutate(()=>{const groups=new Map();const copies=C.translate(chosen().filter(editable),.012,.012).map(e=>{e.id=id();if(e.group){if(!groups.has(e.group))groups.set(e.group,id());e.group=groups.get(e.group);}return e;});doc.entities.push(...copies);selected=copies.map(e=>e.id);});}
    function save(){if(savePromise)return savePromise;savePromise=performSave().finally(()=>savePromise=null);return savePromise;}
    async function flush(){if(draft.length)throw Error('그리는 중인 도형을 완료하거나 취소한 뒤 이동하세요.');await save();if(dirty||conflict||saving)throw Error('도면 저장을 완료하지 못했습니다. 입력을 유지합니다.');}
    async function performSave(){if(!dirty||saving||conflict||!canManage||dead)return;clearTimeout(saveTimer);saving=true;let message='',failed=false;
      if(mode==='edit'){const button=$('[data-save]');if(button){button.disabled=true;button.textContent='저장 중…';}status('서버에 저장 중…');}
      try{while(dirty&&!dead){const request=()=>({revision,document:C.clone(doc)}),ack=writer?await writer.run(request):{sent:request()};
        const r=writer?ack.response:await api(base,'PUT',ack.sent);revision=r.revision;dirty=JSON.stringify(doc)!==JSON.stringify(ack.sent.document);
        if(dirty||draft.length)preserve();else if(recovery)recovery.clear();}
        message='서버 저장 완료 · 버전 '+revision;}
      catch(e){conflict=e.status===409;if(e.status&&e.status<500)writer?.restore(null);preserve();message='도면 입력은 유지됩니다. '+e.message;failed=true;host.hint(message);}
      finally{saving=false;if(mode==='edit'){const button=$('[data-save]');if(button){button.disabled=!dirty||!canManage||conflict;button.textContent='저장';}status(message,failed);}} }
    function point(ev){const p=host.toWorld(ev.clientX,ev.clientY),view=v();p[0]=Math.max(0,Math.min(1,p[0]));p[1]=Math.max(0,Math.min(1,p[1]));snapLabel='';
      let q=C.drawingPoint(tool,draft.at(-1),p,view.W,view.H,ortho,ev.shiftKey);
      if(snap&&!ev.altKey&&!['pen','highlight'].includes(tool)){const hit=C.snap(q,shown().filter(e=>!selected.includes(e.id)),view.W,view.H,view.k);if(hit){q=hit.point;snapLabel=hit.label;}}
      return q.map(n=>Math.max(0,Math.min(1,n)));}
    function intercept(ev){return active&&mode==='edit'&&tool!=='hand'&&!ev.target.closest('.ds-toolbar,.dm-zoom,.dm-form,.dm-pop,.dm-tools');}
    function down(ev){if(!intercept(ev)||ev.button!==0)return;ev.preventDefault();ev.stopImmediatePropagation();focusStage();if(gesture){gesture=null;render();return;}svg.setPointerCapture(ev.pointerId);if(tool!=='select'&&!draft.length&&selected.length){selected=[];renderPanel();}const p=point(ev);hover=null;
      if(tool==='select'){const vertex=ev.target.closest('[data-vertex]'),hit=ev.target.closest('[data-entity]');
        if(vertex&&selected.length===1&&editable(chosen()[0])){vertexIndex=Number(vertex.dataset.vertex);gesture={kind:'vertex',index:vertexIndex,start:p,before:C.clone(doc),id:ev.pointerId};return;}
        if(hit){const key=hit.dataset.entity,group=objects().find(e=>e.id===key)?.group,ids=group&&!ev.altKey?objects().filter(e=>e.group===group).map(e=>e.id):[key];if(ev.ctrlKey||ev.metaKey)selected=selected.includes(key)?selected.filter(k=>!ids.includes(k)):[...new Set([...selected,...ids])];else if(!selected.includes(key))selected=ids;gesture={kind:'move',start:p,before:C.clone(doc),items:C.clone(chosen().filter(editable)),id:ev.pointerId};}
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
      if(g.kind==='move'||g.kind==='vertex'){const invalid=chosen().map(geometryError).find(Boolean);if(invalid){doc=g.before;host.hint(invalid);}else if(JSON.stringify(doc)!==JSON.stringify(g.before))changed(g.before);renderPanel();}
      else if(g.kind==='marquee'){const b=C.bounds([g.start,g.now]);shown().forEach(e=>{const a=C.bounds(e.points);if(a.x>=b.x&&a.right<=b.right&&a.y>=b.y&&a.bottom<=b.bottom&&!selected.includes(e.id))selected.push(e.id);});renderPanel();}
      else if(g.kind==='pen'){draft=C.simplify(draft,.5/v().k,v().W,v().H);finish();}
      else if(g.kind==='click'){const p=point(ev);if(tool==='polygon'&&draft.length>=3&&C.dist(draft[0],p,v().W,v().H)*v().k<12){finish();return;}if(draft.length&&C.dist(draft.at(-1),p,v().W,v().H)*v().k<2)return;draft.push(p);hover=null;preserve();
        if(['text','symbol'].includes(tool)||(['dimension','line','rect','ellipse','cloud','arrow'].includes(tool)&&draft.length===2)||(['angle','arc'].includes(tool)&&draft.length===3))finish();}
      render();
    }
    function finish(){const min=['text','symbol'].includes(tool)?1:['polygon','angle','arc'].includes(tool)?3:2;if(draft.length<min){host.hint('완료하려면 점을 '+min+'개 이상 찍으세요. 현재 '+draft.length+'개입니다.');return;}const l=layers().find(l=>l.id===layer);if(!l||l.locked||!l.visible){host.hint('보이고 잠기지 않은 레이어를 선택하세요.');return;}
      if(tool==='polygon'){const invalid=C.polygonError(draft);if(invalid){host.hint(invalid);return;}}
      if(cutoutId){const target=objects().find(e=>e.id===cutoutId);if(!target||!editable(target))return;const invalid=C.holeError(target,draft);if(invalid){host.hint(invalid);return;}mutate(()=>{(target.holes||(target.holes=[])).push(C.clone(draft));selected=[target.id];draft=[];cutoutId=null;tool='select';});renderToolbar();return;}
      if(['rect','ellipse','cloud'].includes(tool)){const b=C.bounds(draft);if((b.right-b.x)*v().wPt<.5||(b.bottom-b.y)*v().hPt<.5){draft=draft.slice(0,1);hover=null;host.hint('폭과 높이가 있도록 반대쪽 모서리를 찍으세요.');render();return;}}
      let text=$('[data-text]')?.value||newText||'';if(tool==='text'&&!text.trim()){host.hint('오른쪽 문자 칸에 내용을 입력하고 Enter를 누르세요.');$('[data-text]')?.focus();return;}
      const item={...style,id:id(),type:tool,points:C.clone(draft),text,layer};if(tool==='highlight'){item.width=Math.max(item.width,12);item.opacity=.35;}
      if(tool==='arc'){try{item.points=C.arcPoints(draft,v().wPt,v().hPt);item.type='polyline';if(item.points.some(p=>p.some(n=>n<0||n>1)))throw Error('호가 도면 밖으로 나갑니다.');}catch(e){host.hint(e.message);return;}}
      if(tool==='symbol')item.symbol=$('[data-symbol]')?.value||'●';
      if(v().fpp)item.calibration=calibration();
      mutate(()=>{doc.entities.push(item);selected=[item.id];draft=[];hover=null;tool='select';});
      renderToolbar();
    }
    function key(ev){if(sending||!active||(host.scopedKeys&&!wrap.contains(ev.target)))return;const input=ev.target.closest('input,textarea,select,[contenteditable=true]');
      if((ev.ctrlKey||ev.metaKey)&&ev.key.toLowerCase()==='s'){ev.preventDefault();ev.stopImmediatePropagation();if(mode==='edit'&&input?.matches('[data-text],[data-style],[data-name],[data-unit],[data-precision]'))input.dispatchEvent(new Event('change',{bubbles:true}));save();return;}
      if(input){if(ev.key==='Enter'&&ev.target.matches('[data-length],[data-size-width],[data-size-height],[data-rotate]')){ev.preventDefault();ev.stopImmediatePropagation();transform();}
        else if(ev.key==='Enter'&&ev.target.matches('[data-text]')&&draft.length){ev.preventDefault();ev.stopImmediatePropagation();finish();}else ev.stopPropagation();return;}
      if(ev.key==='Escape'){ev.preventDefault();ev.stopImmediatePropagation();if(draft.length){draft=[];cutoutId=null;hover=null;preserve();render();}else if(selected.length){selected=[];render();renderPanel();}else deactivate();return;}
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
    const unload=ev=>{if(dirty||draft.length||sending||saving){preserve();ev.preventDefault();ev.returnValue='';}};global.addEventListener('beforeunload',unload);
    const online=()=>{if(dirty&&!conflict)save();};global.addEventListener('online',online);
    const ready=api(base).then(r=>{if(dead)return;doc=r.document;revision=r.revision;canManage=r.canManage;
      try{const restored=recovery?.latest();if(canManage&&restored&&global.confirm('서버 저장을 마치지 못한 도면 표시가 있습니다. 복구할까요?')){doc=restored.document;revision=restored.revision;writer?.restore(restored.pending);draft=restored.draft||[];tool=restored.tool||'select';cutoutId=restored.cutoutId||null;dirty=true;preserve();host.hint('도면 표시와 그리는 중인 점을 복구했습니다. 확인 후 저장하세요.');}}catch(_){host.hint('도면 복구본을 읽지 못했습니다. 서버 내용을 표시합니다.');}
      layer=doc.layers[0].id;render();}).catch(e=>host.hint('편집 자료 로드 실패: '+e.message));
    return {ready,activate,render,save,flush,useFeet:()=>mutate(()=>doc.unit='ft'),pauseForCalibration:deactivate,beforeClose:()=>!sending&&!saving&&(!(dirty||draft.length)||(preserve()&&global.confirm('서버 저장을 마치지 못한 도면 표시가 있습니다. 기기 복구본을 남기고 닫을까요?'))),
      exportSvg:()=>doc?shown().map(e=>C.render(e,v(),doc)).join(''):'',destroy(){if(dirty||draft.length)preserve();dead=true;clearTimeout(saveTimer);if(previewUrl)URL.revokeObjectURL(previewUrl);stage.removeEventListener('pointerdown',down,true);stage.removeEventListener('pointermove',move,true);stage.removeEventListener('pointerup',up,true);stage.removeEventListener('pointercancel',up,true);document.removeEventListener('keydown',key,true);global.removeEventListener('beforeunload',unload);global.removeEventListener('online',online);}};
  }
  global.DrawingStudio={mount,pdfBlob};
})(window);
