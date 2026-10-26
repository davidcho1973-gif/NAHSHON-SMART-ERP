/* SketchUp-style 2D interactions use the editor's document, history and DXF writer. */
(function(g){
    'use strict';
    const C=g.CADCore,dist=(a,b)=>C.distance(a,b),sub=(a,b)=>({x:a.x-b.x,y:a.y-b.y}),cross=(a,b)=>a.x*b.y-a.y*b.x;
    const center=b=>({x:(b.minX+b.maxX)/2,y:(b.minY+b.maxY)/2});
    function arc(a,b,h){
        const u=sub(b,a),v=sub(h,a),den=2*cross(u,v);
        if(Math.abs(den)<1e-10)throw Error('호의 높이가 0입니다. 기준선에서 떨어진 점을 지정하세요.');
        const uu=u.x*u.x+u.y*u.y,vv=v.x*v.x+v.y*v.y,p={x:a.x+(uu*v.y-vv*u.y)/den,y:a.y+(u.x*vv-v.x*uu)/den};
        const angle=q=>(Math.atan2(q.y-p.y,q.x-p.x)*180/Math.PI+360)%360;
        let start=angle(a),end=angle(b);if((angle(h)-start+360)%360>(end-start+360)%360)[start,end]=[end,start];
        return {p,radius:dist(a,p),start,end};
    }
    function intersection(a,b,c,d){const u=sub(b,a),v=sub(d,c),den=cross(u,v);if(Math.abs(den)<1e-12)return false;const t=cross(sub(c,a),v)/den,s=cross(sub(c,a),u)/den;return t>=0&&t<=1&&s>=0&&s<=1;}
    const inside=(p,ps)=>{let yes=false;for(let i=0,j=ps.length-1;i<ps.length;j=i++){const a=ps[i],b=ps[j];if(C.segmentDistance(p,a,b)<1e-9)return true;if((a.y>p.y)!==(b.y>p.y)&&p.x<(b.x-a.x)*(p.y-a.y)/(b.y-a.y)+a.x)yes=!yes;}return yes;};
    function regionHit(e,region,contained){
        const paths=e.renderParts?.map(C.geometry)||e.preview||[C.geometry(e)],all=paths.flat();if(!all.length)return false;
        if(contained)return all.every(p=>inside(p,region));
        return all.some(p=>inside(p,region))||paths.some(path=>path.some((p,i)=>i&&region.some((a,j)=>intersection(path[i-1],p,a,region[(j+1)%region.length]))))||region.some(p=>C.contains(e,p));
    }
    g.CADSketchGeometry={arc,inside,regionHit};
    g.createCADSketchTools=function(api){
        const $=id=>document.getElementById(id),canvas=api.canvas;
        let stage=[],hover=null,drag=null,preview=[],copy=false,axis=null,clipboard=null,lastDoc=null,menuPoint=null,numberText='',move=null,editGroup=null;
        const definitions=[
            ['search','도구 검색','Shift+S','이름 또는 단축키로 도구를 찾으세요.'],
            ['select','선택','Space','클릭 · 왼쪽→오른쪽 드래그: 완전 포함 · 오른쪽→왼쪽: 걸친 객체 · Ctrl: 추가 · Shift: 전환'],
            ['lasso','올가미 선택','Shift+Space','드래그로 둘러싸세요. 시계 방향: 걸친 객체 · 반시계 방향: 완전 포함'],
            ['eraser','지우개','E','클릭하거나 드래그해 삭제 · Shift: 숨기기 · Esc: 취소'],
            ['line','선','L','시작점 → 끝점 · 길이 입력 · 방향키: 축 고정'],
            ['polyline','연속선','','점을 연결 · 시작점 클릭: 닫기 · Enter: 완료'],
            ['freehand','자유형','','마우스를 누른 채 그리세요. 시작점 근처에서 놓으면 닫힙니다.'],
            ['arc2','2점 호','A','시작점 → 끝점 → 호 높이 · 숫자: 호 높이'],
            ['arc','중심 호','','중심 → 시작점 → 끝점'],
            ['rectangle','사각형','R','대각선의 두 점 · 가로,세로 입력'],
            ['circle','원','C','중심 → 반지름 · 숫자 입력'],
            ['polygon','다각형','','중심 → 꼭짓점 · 변 수 설정 · 숫자: 반지름'],
            ['offset','오프셋','F','테두리 클릭 → 안쪽/바깥쪽 클릭 · 숫자: 간격'],
            ['move','이동','M','기준점 → 목적점 · Ctrl: 복사 / 두 번: 연속복사 · 거리·좌표 · 복사 후 5x / 4/'],
            ['copy','이동 복사','','기준점 → 복사 위치 · 5x: 반복 배열 · 4/: 구간 등분'],
            ['stamp','연속복사','','기준점 → 클릭마다 복사 · Esc: 종료'],
            ['stretch','선 늘이기','','끝점 또는 선분 → 목적점 · 연결된 선도 함께 늘어납니다.'],
            ['rotate','회전','Q','중심 → 기준 방향 → 회전 방향 · Ctrl: 복사 · 숫자: 각도 · Shift: 15°'],
            ['scale','크기 조정','S','초록 모서리 클릭 → 크기 지정 · 비율 유지 · Ctrl: 중심 기준 · 숫자: 배율'],
            ['flip','뒤집기','','선택 영역의 세로선: 좌우 · 가로선: 상하 · Ctrl: 복사'],
            ['tape','줄자','T','선 위에 올려 강조 → 클릭 → 평행 이동·간격 측정 · 숫자: 안내선 간격 · 선 밖: 두 점 거리'],
            ['paint','페인트 통','B','닫힌 도형: 채움 · 선: 색 변경 · Alt: 색 추출'],
            ['pan','화면 이동','H','왼쪽 버튼 드래그 · 모든 도구에서 마우스 휠 버튼 드래그'],
            ['zoom','확대/축소','Z','위로 드래그: 확대 · 아래로: 축소 · 마우스 휠'],
            ['fit','전체 보기','Shift+Z','모든 도면이 화면에 들어오도록 맞춥니다.'],
        ];
        const commands=new Map(definitions.map(([id,label,key,hint])=>[id,{id,label,key,hint}]));
        const toolbar=document.createElement('section');toolbar.id='sketch-toolbar';toolbar.setAttribute('aria-label','스케치업 방식 2D 도구');
        const title=document.createElement('span');title.className='sketch-caption';title.textContent='Getting Started · 2D';toolbar.append(title);
        const row=document.createElement('div');row.className='sketch-tool-row';row.setAttribute('role','toolbar');row.setAttribute('aria-label','2D 그리기 및 편집');toolbar.append(row);
        const iconPaths={search:'<circle cx="13" cy="12" r="8"/><path d="m19 18 9 10"/>',select:'<path d="m9 5 15 12-8 1 3 8-4 1-3-8-4 5z" fill="#397ca1"/>',lasso:'<path d="M10 25C-3 12 9 3 21 6s13 19 1 20S8 19 14 17c7-2 8 5 5 11" stroke-dasharray="3 2"/>',eraser:'<path d="m6 20 14-14 8 7-14 14z" fill="#b7def0"/><path d="m6 20 8 7 6-6-8-7zM5 28h22"/>',line:'<path d="m7 26 2-7L24 4l4 4-15 15z" stroke="#ee5057" fill="#ffd4cd"/><path d="m7 26 2-7 4 4z" fill="#426476"/>',polyline:'<path d="m3 26 7-19 11 18 8-19" stroke="#ed5057"/>',freehand:'<path d="M4 25C29 11 1 10 16 5s17 16 4 16c-5 0-9 8-5 9" stroke="#ee5057"/>',arc2:'<path d="M5 25Q24 26 26 6"/><circle cx="5" cy="25" r="2" fill="#ee5057"/><circle cx="26" cy="6" r="2" fill="#ee5057"/>',rectangle:'<path d="M6 8h21v19H6z" fill="#c5e5f4"/><path d="m6 27 21-19"/><circle cx="27" cy="8" r="2" fill="#ee5057"/>',circle:'<circle cx="16" cy="16" r="11" fill="#d6ecf8"/><path d="m16 16 8-6" stroke="#ee5057"/>',polygon:'<path d="m16 4 12 7v13-1l-12 6-12-7V10z" fill="#c5e5f4"/>',offset:'<path d="M4 7h23v22H4zM10 13h11v10H10z"/><path d="m17 14 9-9m-6 0h6v6" stroke="#ee5057"/>',move:'<path d="M16 3v26M3 16h26m-13-26-4 5m4-5 4 5m-4 21-4-5m4 5 4-5M3 16l5-4m-5 4 5 4m21-4-5-4m5 4-5 4" stroke="#ee5057"/>',rotate:'<path d="M25 11A10 10 0 0 0 7 10M7 22a10 10 0 0 0 18 0m-2-18 3 8-8-1M9 28l-3-7 8 1" stroke="#ee5057"/>',scale:'<path d="M5 7h21v21H5z" fill="#c5e5f4"/><path d="m13 20 13-14M19 6h7v7" stroke="#ee5057" stroke-width="3"/>',flip:'<path d="M16 3v27" stroke="#ee5057" stroke-dasharray="2 2"/><path d="m12 8-9 19h9zm8 0 9 19h-9z" fill="#c5e5f4"/>',tape:'<path d="m9 24-3-9 9-9 10 3 2 10-9 6z" fill="#eaf4fa"/><circle cx="17" cy="14" r="4" fill="#ee5057"/><path d="m9 24-6 4h12"/>',paint:'<path d="m8 10 9-6 10 12-11 10L5 16z" fill="#c5e5f4"/><path d="m8 10 11 10 8-4M12 8C8-4 1 14 9 19"/><path d="M26 22c-6 7 5 8 0 0" fill="#397ca1"/>',pan:'<path d="M11 28 4 18c-2-4 2-5 5-1V8c0-4 4-4 4 0V5c0-4 4-4 4 0v3c0-4 4-4 4 0v3c0-4 4-4 4 0v10l-4 7z" fill="#e2f1f8"/>',zoom:'<circle cx="14" cy="13" r="8"/><path d="m8 20-6 9"/>',fit:'<circle cx="16" cy="16" r="6"/><path d="m4 4 6 2-4 4zm24 0-6 2 4 4zM4 28l6-2-4-4zm24 0-6-2 4-4z" fill="#ee5057" stroke="#ee5057"/>'};iconPaths.arc=iconPaths.arc2;
        const button=id=>{const c=commands.get(id),b=document.createElement('button');b.type='button';b.dataset.sketchCommand=id;b.setAttribute('aria-label',c.label+(c.key?' ('+c.key+')':''));b.title=c.label+(c.key?' · '+c.key:'')+' — '+c.hint;b.innerHTML='<svg viewBox="0 0 32 32" aria-hidden="true" fill="none" stroke="#397ca1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'+iconPaths[id]+'</svg>';b.onclick=()=>run(id);return b;};
        for(const ids of [['search'],['select','lasso'],['eraser'],['line','polyline','freehand'],['arc2','arc'],['rectangle','circle','polygon'],['offset'],['move','copy','stamp','stretch'],['rotate'],['scale'],['flip'],['tape'],['paint'],['pan'],['zoom'],['fit']]){const wrap=document.createElement('div');wrap.className='sketch-tool-family';wrap.append(button(ids[0]));if(ids.length>1){const more=document.createElement('button');more.className='sketch-more';more.textContent='▾';more.title=commands.get(ids[0]).label+' 관련 도구';more.setAttribute('aria-label',more.title);more.onclick=()=>showMenu(ids.map(id=>({label:commands.get(id).label,key:commands.get(id).key,action:()=>run(id)})),more.getBoundingClientRect().left,more.getBoundingClientRect().bottom);wrap.append(more);}row.append(wrap);}
        const note=document.createElement('span');note.className='sketch-mode-note';note.textContent='CAD 원본 편집';row.append(note);
        document.querySelector('main').before(toolbar);
        const menu=document.createElement('div');menu.id='sketch-menu';menu.hidden=true;menu.setAttribute('role','menu');document.body.append(menu);
        const inputBar=document.createElement('div');inputBar.id='sketch-input-bar';inputBar.className='cad-only';inputBar.hidden=true;
        inputBar.innerHTML='<label id="sketch-value-label" for="sketch-value">값</label><input id="sketch-value" inputmode="decimal" autocomplete="off"><label id="sketch-sides-label" hidden>변 수 <input id="sketch-sides" type="number" min="3" max="120" value="6"></label><span id="sketch-copy-state"></span>';
        $('viewport').append(inputBar);
        const search=document.createElement('dialog');search.id='sketch-search';search.innerHTML='<form method="dialog"><strong>2D 도구 검색</strong><button aria-label="닫기">×</button></form><input id="sketch-search-query" placeholder="도구 이름 또는 단축키" aria-label="도구 검색"><div id="sketch-search-results"></div><p>스케치업 Windows 기본 단축키 · CAD 정밀 편집에서 사용</p>';document.body.append(search);
        const searchResults=()=>{const q=$('sketch-search-query').value.toLowerCase();$('sketch-search-results').replaceChildren();for(const c of commands.values())if(c.id!=='search'&&(c.label.toLowerCase().includes(q)||c.key.toLowerCase().includes(q)||c.id.includes(q))){const b=document.createElement('button');b.textContent=c.label+(c.key?' · '+c.key:'');b.onclick=()=>{search.close();run(c.id);};$('sketch-search-results').append(b);}};$('sketch-search-query').oninput=searchResults;
        const selected=()=>api.document()?.entities.filter(e=>api.selection().has(e.id))||[];
        const active=()=>api.active()&&!!api.document();
        const allIds=es=>{const groups=new Set(es.map(e=>e.sketchGroup).filter(id=>id&&id!==editGroup));return api.visible().filter(e=>(!editGroup||e.sketchGroup===editGroup)&&(es.includes(e)||groups.has(e.sketchGroup))).map(e=>e.id);};
        const choose=(es,event={})=>{const ids=allIds(es),next=new Set(event.ctrlKey||event.shiftKey?api.selection():[]);for(const id of ids){if(event.ctrlKey&&event.shiftKey)next.delete(id);else if(event.shiftKey&&next.has(id))next.delete(id);else next.add(id);}api.select(next);};
        const check=(es,kind,unlock=false)=>{if(!api.writable()||api.busy())throw Error('읽기 전용이거나 저장 중입니다.');if(editGroup&&kind!=='copy'&&es.some(e=>e.sketchGroup!==editGroup))throw Error('그룹 편집을 끝낸 뒤 그룹 밖의 객체를 편집하세요.');if(es.some(e=>!e.editable||e.transient||(!unlock&&(e.sketchLocked||e.hidden))||api.document().layers[e.layer]?.locked||api.document().layers[e.layer]?.visible===false||!C.allows(e,kind)))throw Error('선택에 잠금 또는 지원하지 않는 객체가 있습니다. 아무 객체도 변경하지 않았습니다.');};
        function commit(es,kind,fn,unlock=false){check(es,kind,unlock);api.commit(fn);}
        function safe(fn){try{fn();}catch(e){preview=[];api.status(e.message);api.render();}}
        function reset(){move?.reset();const pointerId=drag?.id;stage=[];hover=null;preview=[];drag=null;if(pointerId!==undefined&&canvas.hasPointerCapture(pointerId))canvas.releasePointerCapture(pointerId);copy=false;axis=null;numberText='';$('sketch-value').value='';menu.hidden=true;}
        function run(id){
            menu.hidden=true;
            if(id==='search'){searchResults();search.showModal();$('sketch-search-query').focus();return;}
            if(!api.document())return api.status('먼저 도면을 선택하거나 업로드하세요.');
            if(api.busy())return;
            if(!api.active())document.querySelector('.mode-switch [data-workspace="cad"]').click();
            if(id==='fit'){reset();api.fit();return;}
            api.setTool(id==='stamp'?'move':id);if(id==='stamp')move?.setMode('stamp');canvas.focus();api.status(commands.get(id)?.hint||'');
        }
        const fresh=es=>C.copyEntities(es).map(e=>editGroup?{...e,sketchGroup:editGroup}:e);
        function replace(es,next,asCopy=false){commit(es,asCopy?'copy':api.tool()==='flip'?'mirror':api.tool(),()=>{if(asCopy){next=fresh(next);api.document().entities.push(...next);}else{const map=new Map(next.map(e=>[e.id,e]));api.document().entities=api.document().entities.map(e=>map.get(e.id)||e);}api.select(new Set(next.map(e=>e.id)));});stage=[];preview=[];$('sketch-value').value='';api.render();}
        function remove(es,hide=false){commit(es,'delete',()=>{const ids=new Set(es.map(e=>e.id));if(hide)api.document().entities=api.document().entities.map(e=>ids.has(e.id)?{...e,hidden:true,dirty:true}:e);else api.document().entities=api.document().entities.filter(e=>!ids.has(e.id));api.select(new Set());});}
        function metadata(changes,es=selected(),unlock=false){if(!es.length)return;commit(es,'style',()=>{const ids=new Set(es.map(e=>e.id));api.document().entities=api.document().entities.map(e=>ids.has(e.id)?{...e,...changes,dirty:true}:e);},unlock);}
        function copySelection(cut=false){const es=selected();if(!es.length)return;check(es,'copy');clipboard={record:api.recordId(),entities:C.copyDocument({entities:es}).entities,anchor:center(C.bounds(es))};if(cut)remove(es);api.status(es.length+'개 복사됨 · Ctrl+V로 위치 지정');}
        function paste(inPlace=false){if(!clipboard||clipboard.record!==api.recordId())throw Error('이 도면에서 먼저 객체를 복사하세요.');check(clipboard.entities,'copy');if(inPlace){const added=fresh(clipboard.entities);commit([],'copy',()=>{api.document().entities.push(...added);api.select(new Set(added.map(e=>e.id)));});}else{api.setTool('paste');stage=[clipboard.anchor];api.status('붙여넣을 위치를 클릭하세요.');}}
        function paint(p,sample=false){const hit=api.hit(p);if(!hit)return;if(sample){if(hit.color!=null){$('default-color').value='#'+hit.color.toString(16).padStart(6,'0');$('native-color').value=$('default-color').value;}api.status('색상을 가져왔습니다.');return;}check([hit],'style');const color=parseInt($('default-color').value.slice(1),16);if(hit.type==='LWPOLYLINE'&&hit.closed||hit.type==='CIRCLE'){const boundary=hit.type==='CIRCLE'?C.newEntity('LWPOLYLINE',{closed:true,points:Array.from({length:4},(_,i)=>({x:hit.p.x+hit.radius*Math.cos(i*Math.PI/2),y:hit.p.y+hit.radius*Math.sin(i*Math.PI/2),bulge:Math.tan(Math.PI/8)})),raw:[[38,String(Number(hit.raw?.find(p=>p[0]===30)?.[1]||0))]]},hit.layer):hit;const existing=C.findPolylineFill(api.document(),boundary);if(existing)check([existing],'style');const fill=existing?C.editHatch(existing,{color}):C.hatchForPolyline(boundary,{color,opacity:.65});commit([hit,...(existing?[existing]:[])],'style',()=>{if(existing)api.document().entities=api.document().entities.map(e=>e.id===existing.id?fill:e);else api.document().entities.push(fill);});}else{const next=hit.type==='HATCH'?C.editHatch(hit,{color}):{...hit,color,dirty:true};commit([hit],'style',()=>{api.document().entities=api.document().entities.map(e=>e.id===hit.id?next:e);});}}
        move=g.createCADMoveTools({...api,editingGroup:()=>editGroup,
            hit:p=>{const hit=api.hit(p);return !editGroup||hit?.sketchGroup===editGroup?hit:null;},
            groupEntities:e=>e.sketchGroup&&e.sketchGroup!==editGroup?api.document().entities.filter(n=>n.sketchGroup===e.sketchGroup):[e]});
        function showMenu(items,x,y){menu.replaceChildren();for(const item of items){if(!item){menu.append(document.createElement('hr'));continue;}const b=document.createElement('button');b.setAttribute('role','menuitem');b.setAttribute('aria-label',item.label);b.textContent=item.label;const key=document.createElement('kbd');key.textContent=item.key||'';b.append(key);b.disabled=!!item.disabled;b.onclick=()=>{menu.hidden=true;safe(item.action);if(menu.contains(document.activeElement)||document.activeElement===document.body)canvas.focus();};menu.append(b);}menu.hidden=false;menu.style.left=Math.max(4,Math.min(x,innerWidth-menu.offsetWidth-8))+'px';menu.style.top=Math.max(4,Math.min(y,innerHeight-menu.offsetHeight-8))+'px';menu.querySelector('button:not(:disabled)')?.focus();}
        function context(event){if(!active())return;event.preventDefault();event.stopImmediatePropagation();if(api.busy())return;if(move?.isTool()&&(move.pending()||move.hasLast())){showMenu(move.contextItems(),event.clientX,event.clientY);return;}reset();menuPoint=api.world(event);const hit=api.hit(menuPoint);if(hit&&!api.selection().has(hit.id))choose([hit]);const es=selected(),has=!!es.length,editable=has&&api.writable()&&es.every(e=>api.editable(e)),item=(label,action,key='',enabled=true)=>({label,action,key,disabled:!enabled});
            showMenu([
                item('개체 정보',()=>{$('selection').scrollIntoView({block:'center'});api.status(es.map(e=>e.type+' · '+e.layer+(e.sketchLocked?' · 잠금':'')).join(' / ')||'선택한 객체가 없습니다.');},'',has),
                item('지우기',()=>remove(es),'Delete',editable),item('잘라내기',()=>copySelection(true),'Ctrl+X',editable),item('복사',()=>copySelection(),'Ctrl+C',editable),item('붙여넣기',()=>paste(),'Ctrl+V',!!clipboard&&api.writable()),item('제자리 붙여넣기',()=>paste(true),'Ctrl+Shift+V',!!clipboard&&api.writable()),null,
                item('전체 선택',()=>choose(api.visible()),'Ctrl+A'),item('선택 해제',()=>api.select(new Set())),item('연결된 선 선택',()=>selectConnected(),'',has),null,
                item('그룹 만들기',()=>metadata({sketchGroup:C.uid()}),'',editable&&!editGroup),item('그룹 해제 / 분해',()=>{if(es.some(e=>e.sketchGroup))metadata({sketchGroup:undefined});else $('explode-selected').click();},'',editable&&!editGroup&&es.some(e=>e.sketchGroup||e.type==='INSERT')),
                item('그룹 편집',()=>{editGroup=es[0].sketchGroup;api.select(new Set());run('select');},'',editable&&!editGroup&&es.every(e=>e.sketchGroup&&e.sketchGroup===es[0].sketchGroup)),
                item('그룹 편집 끝내기',()=>{editGroup=null;api.select(new Set());run('select');},'Esc',!!editGroup),
                item(es.some(e=>e.sketchLocked)?'잠금 해제':'잠금',()=>metadata({sketchLocked:!es.some(e=>e.sketchLocked)},es,true),'',has&&api.writable()),item('숨기기',()=>metadata({hidden:true}),'',editable),item('숨김 모두 표시',()=>metadata({hidden:false},api.document().entities.filter(e=>e.hidden&&e.editable&&!api.document().layers[e.layer]?.locked),true),'',api.writable()),null,
                item('이동',()=>run('move'),'M',editable),item('이동 복사',()=>run('copy'),'Ctrl',editable),item('연속복사',()=>run('stamp'),'Ctrl 두 번',editable),item('선 늘이기',()=>run('stretch'),'',editable&&es.length===1&&['LINE','LWPOLYLINE'].includes(es[0].type)),
                item('연결점 추가',()=>run('move-snap'),'',editable&&(es.every(e=>e.sketchGroup&&e.sketchGroup===es[0].sketchGroup)||es.length===1&&es[0].type==='INSERT')),
                item('연결점 삭제',()=>metadata({sketchSnaps:[]}),'',editable&&es.some(e=>e.sketchSnaps?.length)),
                item('회전',()=>run('rotate'),'Q',editable),item('크기 조정',()=>run('scale'),'S',editable),item('좌우 뒤집기',()=>flip('y'),'',editable),item('상하 뒤집기',()=>flip('x'),'',editable),item('오프셋',()=>run('offset'),'F',editable),null,
                item('선택 확대',()=>api.focus(C.bounds(es)),'',has),item('전체 보기',()=>run('fit'),'Shift+Z'),item('실행 취소',()=>{$('undo').click();},'Ctrl+Z'),item('다시 실행',()=>{$('redo').click();},'Ctrl+Y'),item('안내선 삭제',()=>{$('guide-clear').click();},'',api.writable()),
            ],event.clientX,event.clientY);
        }
        function selectConnected(){const found=new Set(api.selection()),pool=api.visible();if(pool.length>5000)throw Error('연결 선택은 한 번에 5,000개 객체까지 지원합니다. 레이어를 숨겨 범위를 줄이세요.');const keys=new Map(),key=p=>Math.round(p.x*1e6)+','+Math.round(p.y*1e6);for(const e of pool)for(const p of C.snapPoints(e)){const k=key(p);if(!keys.has(k))keys.set(k,[]);keys.get(k).push(e);}const queue=selected();for(let i=0;i<queue.length;i++)for(const p of C.snapPoints(queue[i]))for(const e of keys.get(key(p))||[])if(!found.has(e.id)){found.add(e.id);queue.push(e);}choose(queue);}
        function flip(axisName){const es=selected();if(!es.length)return;const next=es.map(e=>C.transform(e,{mirror:axisName,center:center(C.bounds(es))}));commit(es,copy?'copy':'mirror',()=>{const n=copy?fresh(next):next;if(copy)api.document().entities.push(...n);else{const map=new Map(n.map(e=>[e.id,e]));api.document().entities=api.document().entities.map(e=>map.get(e.id)||e);}api.select(new Set(n.map(e=>e.id)));});}
        function offsetDistance(e,p){if(['CIRCLE','ARC'].includes(e.type))return dist(p,e.p)-e.radius;const ps=e.type==='LINE'?[e.p,e.q]:e.points;let best=Infinity,result=0;for(let i=0;i<(e.closed?ps.length:ps.length-1);i++){const a=ps[i],b=ps[(i+1)%ps.length],d=C.segmentDistance(p,a,b);if(d<best){best=d;result=cross(sub(b,a),sub(p,a))/dist(a,b);}}if(e.closed)return inside(p,ps)?-best:best;return result;}
        function constrain(p,event={}){if(!stage.length)return p;const a=stage[0];if(axis==='x')return {x:p.x,y:a.y};if(axis==='y')return {x:a.x,y:p.y};return p;}
        function transformAt(p,event={},value=null){const es=selected(),t=api.tool(),a=stage[0];if(!a)return [];let options;
            if(t==='rotate'){if(!stage[1])return [];let angle=(Math.atan2(p.y-a.y,p.x-a.x)-Math.atan2(stage[1].y-a.y,stage[1].x-a.x))*180/Math.PI;if(event.shiftKey)angle=Math.round(angle/15)*15;if(value!==null)angle=Number(value);if(!Number.isFinite(angle))throw Error('회전 각도를 입력하세요.');options={angle,center:a};}
            if(t==='scale'){if(!stage[1])return [];const c=event.ctrlKey?center(C.bounds(es)):a;let factor=dist(p,c)/dist(stage[1],c);if(value!==null)factor=Number(value);if(!Number.isFinite(factor)||factor<=0)throw Error('0보다 큰 배율을 입력하세요.');options={scale:factor,center:c};}
            if(t==='offset'){const e=es[0];if(es.length!==1)throw Error('오프셋 대상 하나를 선택하세요.');let d=offsetDistance(e,p);if(value!==null)d=Math.sign(d||1)*C.parseLengthInput(value,api.numericSettings());return [C.offset(e,d)];}
            if(t==='paste')return clipboard.entities.map(e=>C.transform(e,{dx:p.x-a.x,dy:p.y-a.y}));
            return options?es.map(e=>C.transform(e,options)):[];
        }
        function drawShape(t,p,value=null){if(!stage.length)return null;const a=stage[0];if(t==='polygon'){const n=Number($('sketch-sides').value);if(!Number.isInteger(n)||n<3||n>120)throw Error('변 수는 3~120 사이의 정수입니다.');const r=value===null?dist(a,p):C.parseLengthInput(value,api.numericSettings());if(r<=0)throw Error('반지름은 0보다 커야 합니다.');const angle=Math.atan2(p.y-a.y,p.x-a.x),ps=Array.from({length:n},(_,i)=>({x:a.x+r*Math.cos(angle+i*2*Math.PI/n),y:a.y+r*Math.sin(angle+i*2*Math.PI/n)}));return {type:'LWPOLYLINE',data:{p:ps[0],points:ps,closed:true}};}
            if(t==='arc2'&&stage[1]){let h=p;if(value!==null){const b=stage[1],m={x:(a.x+b.x)/2,y:(a.y+b.y)/2},v=sub(b,a),len=dist(a,b),d=C.parseLengthInput(value,api.numericSettings())*(cross(v,sub(p,a))<0?-1:1);h={x:m.x-v.y/len*d,y:m.y+v.x/len*d};}return {type:'ARC',data:arc(a,stage[1],h)};}return null;
        }
        function addShape(shape){if(!shape)return;check([],'copy');if(api.document().layers[$('layer').value]?.locked)throw Error('작업 레이어가 잠겨 있습니다.');api.commit(()=>{const e=api.add(shape.type,shape.data);api.select(new Set([e.id]));});stage=[];preview=[];api.render();}
        function finish(p,event={},value=null){const t=api.tool();if(['polygon','arc2'].includes(t)){addShape(drawShape(t,p,value));return;}const next=transformAt(p,event,value);if(!next.length)return;if(t==='paste'){commit([],'copy',()=>{const added=fresh(next);api.document().entities.push(...added);api.select(new Set(added.map(e=>e.id)));});api.setTool('select');return;}if(t==='offset'){commit(selected(),'copy',()=>{api.document().entities.push(...next);api.select(new Set(next.map(e=>e.id)));});stage=[];preview=[];api.render();return;}replace(selected(),next,copy||t==='copy');}
        const custom=new Set(['lasso','eraser','freehand','arc2','polygon','rotate','scale','flip','offset','paint','pan','zoom','paste']);
        const stop=e=>{e.preventDefault();e.stopImmediatePropagation();};
        canvas.addEventListener('contextmenu',context,true);
        canvas.addEventListener('dblclick',event=>{if(!active()||api.busy()||api.tool()!=='select'||editGroup)return;const hit=api.hit(api.world(event));if(hit?.sketchGroup&&api.editable(hit)){stop(event);editGroup=hit.sketchGroup;api.select(new Set());api.render();}},true);
        canvas.addEventListener('pointerdown',event=>{
            if(!active())return;if(event.button===2){stop(event);return;}if(event.button!==0)return;
            if(move?.isTool()){menu.hidden=true;safe(()=>move.pointerDown(event));return;}
            const t=api.tool(),raw=api.world(event),hit=api.hit(raw),box=t==='select'&&!hit;
            if(!custom.has(t)&&!box)return;stop(event);canvas.focus();menu.hidden=true;if(api.busy())return;
            const p=constrain(api.snap(raw,new Set(selected().map(e=>e.id))),event);hover=p;
            safe(()=>{
                if(box||['lasso','freehand','pan','zoom','eraser'].includes(t)){drag={kind:box?'box':t,id:event.pointerId,start:raw,end:raw,points:[raw],sx:event.clientX,sy:event.clientY,lastX:event.clientX,lastY:event.clientY,event,ids:new Set()};if(t==='eraser'&&hit)allIds([hit]).forEach(id=>drag.ids.add(id));canvas.setPointerCapture(event.pointerId);api.render();return;}
                if(t==='paint'){paint(raw,event.altKey);return;}
                if(['move','copy','rotate','scale','flip','offset'].includes(t)&&!selected().length){if(!hit){api.status('먼저 편집할 객체를 선택하세요.');return;}choose([hit]);}
                if(['move','copy','rotate','scale','flip','offset'].includes(t))check(selected(),copy?'copy':t==='flip'?'mirror':t==='offset'?'copy':t);
                if(t==='flip'){const c=api.screen(center(C.bounds(selected()))),q=api.screen(raw);flip(Math.abs(q.x-c.x)<Math.abs(q.y-c.y)?'y':'x');return;}
                if(t==='scale'&&!stage.length){const b=C.bounds(selected()),corners=[{x:b.minX,y:b.minY},{x:b.maxX,y:b.minY},{x:b.maxX,y:b.maxY},{x:b.minX,y:b.maxY}];corners.sort((a,b)=>dist(a,raw)-dist(b,raw));const q=corners[0];stage=[{x:b.minX+b.maxX-q.x,y:b.minY+b.maxY-q.y},q];if(dist(stage[0],q)<1e-10)throw Error('크기가 있는 객체를 선택하세요.');api.render();return;}
                if(t==='offset'&&!stage.length){if(hit&&!api.selection().has(hit.id))choose([hit]);stage=[p];api.render();return;}
                if(!stage.length){stage=[p];api.render();return;}
                if((t==='rotate'||t==='arc2')&&stage.length===1){if(dist(stage[0],p)<1e-10)throw Error('서로 다른 점을 지정하세요.');stage.push(p);api.render();return;}
                finish(p,event);
            });
        },true);
        canvas.addEventListener('pointermove',event=>{
            if(active()&&move?.isTool()){safe(()=>move.pointerMove(event));return;}
            if(!active()||api.panning()||(!custom.has(api.tool())&&!drag))return;stop(event);if(api.busy())return;
            const raw=api.world(event),p=constrain(api.snap(raw,new Set(selected().map(e=>e.id))),event);hover=p;
            if(drag&&drag.id===event.pointerId){drag.end=raw;if(drag.kind==='pan'){api.pan(event.clientX-drag.lastX,event.clientY-drag.lastY);}else if(drag.kind==='zoom')api.zoom(drag.start,Math.exp((drag.lastY-event.clientY)*.015));else if(['lasso','freehand'].includes(drag.kind)&&dist(drag.points.at(-1),raw)*api.scale()>3&&drag.points.length<2000)drag.points.push(raw);else if(drag.kind==='eraser'){const hit=api.hit(raw);if(hit)allIds([hit]).forEach(id=>drag.ids.add(id));}drag.lastX=event.clientX;drag.lastY=event.clientY;api.render();return;}
            preview=[];if(stage.length)safe(()=>{const shape=drawShape(api.tool(),p);preview=shape?[C.newEntity(shape.type,shape.data)]:transformAt(p,event);});api.render();
        },true);
        canvas.addEventListener('pointerup',event=>{
            if(move?.isTool()){safe(()=>move.pointerUp(event));return;}
            if(!drag||drag.id!==event.pointerId)return;stop(event);const done=drag;drag=null;if(canvas.hasPointerCapture(event.pointerId))canvas.releasePointerCapture(event.pointerId);
            safe(()=>{if(done.kind==='eraser'){const es=api.document().entities.filter(e=>done.ids.has(e.id));if(es.length)remove(es,done.event.shiftKey);}
                if(done.kind==='box'||done.kind==='lasso'){let region=done.points,contained=false;if(done.kind==='box'){const a=done.start,b=done.end;region=[a,{x:b.x,y:a.y},b,{x:a.x,y:b.y}];contained=b.x>=a.x;}else contained=region.reduce((sum,p,i)=>sum+cross(p,region[(i+1)%region.length]),0)>0;choose(region.length>2?api.visible().filter(e=>regionHit(e,region,contained)):[],done.event);}
                if(done.kind==='freehand'&&done.points.length>1){const ps=done.points,closed=ps.length>3&&dist(ps[0],ps.at(-1))*api.scale()<10;if(closed)ps.pop();addShape({type:'LWPOLYLINE',data:{p:ps[0],points:ps,closed}});}
            });api.render();
        },true);
        canvas.addEventListener('pointercancel',()=>{if(move?.pending())move.cancel();},true);
        canvas.addEventListener('lostpointercapture',()=>move?.lostCapture(),true);
        for(const eventName of ['pointercancel','lostpointercapture'])canvas.addEventListener(eventName,()=>{if(drag){drag=null;preview=[];api.render();}},true);
        $('sketch-value').addEventListener('input',()=>{numberText=$('sketch-value').value;});
        $('sketch-value').addEventListener('keydown',event=>{if(event.isComposing)return;if(event.key==='Enter'){stop(event);safe(()=>{if(move?.isTool())move.numeric($('sketch-value').value);else{if(!hover)throw Error('마우스로 방향을 지정하세요.');finish(hover,{},$('sketch-value').value);}$('sketch-value').value='';canvas.focus();});}if(event.key==='Escape'){stop(event);if(move?.isTool()){move.cancel();canvas.focus();}else run('select');}if((event.ctrlKey||event.metaKey)&&['z','y'].includes(event.key.toLowerCase())){stop(event);$(event.key.toLowerCase()==='y'||event.shiftKey?'redo':'undo').click();canvas.focus();}});
        document.addEventListener('pointerdown',e=>{if(!menu.contains(e.target)&&!e.target.closest('.sketch-more'))menu.hidden=true;},true);
        menu.addEventListener('keydown',e=>{if(e.key==='Escape'){stop(e);menu.hidden=true;canvas.focus();}if(['ArrowDown','ArrowUp','Home','End'].includes(e.key)){stop(e);const buttons=[...menu.querySelectorAll('button:not(:disabled)')],i=buttons.indexOf(document.activeElement),next=e.key==='Home'?0:e.key==='End'?buttons.length-1:(i+(e.key==='ArrowDown'?1:-1)+buttons.length)%buttons.length;buttons[next]?.focus();}});
        document.addEventListener('keydown',event=>{
            if(event.isComposing||event.keyCode===229||event.target.isContentEditable||event.target.closest('input,textarea,select,dialog')||document.querySelector('dialog[open]')||!active())return;
            const key=event.key.toLowerCase(),mod=event.ctrlKey||event.metaKey;
            if(event.target.closest('#sketch-menu'))return;
            let handled=false;safe(()=>{handled=!!move?.keyDown(event);});if(handled)return;
            if(move?.isTool()&&move.canType()&&!mod&&!event.altKey&&/^[0-9.\-+\[<]$/.test(event.key)){stop(event);$('sketch-value').value=event.key;$('sketch-value').focus();return;}
            if(key==='escape'){stop(event);if(editGroup&&api.tool()==='select'){editGroup=null;api.select(new Set());}run('select');return;}
            if(key==='control'&&!event.repeat&&['rotate','flip'].includes(api.tool())){stop(event);copy=!copy;api.render();return;}
            if(stage.length&&['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)){stop(event);axis=event.key==='ArrowRight'?'x':event.key==='ArrowDown'?null:'y';return;}
            if(stage.length&&!mod&&!event.altKey&&/^[0-9.\-]$/.test(event.key)&&!inputBar.hidden){stop(event);$('sketch-value').value=event.key;$('sketch-value').focus();return;}
            if(mod){const actions={c:()=>copySelection(),x:()=>copySelection(true),v:()=>paste(event.shiftKey),a:()=>choose(api.visible())};if(actions[key]){stop(event);safe(actions[key]);return;}if(['z','y','s'].includes(key)&&stage.length){reset();api.render();}return;}
            if(event.altKey)return;const shortcut=event.shiftKey?'Shift+'+(key===' '?'Space':key.toUpperCase()):key===' '?'Space':key.toUpperCase();const c=[...commands.values()].find(c=>c.key===shortcut);if(c){stop(event);run(c.id);return;}
            if(key==='delete'||key==='backspace'){stop(event);safe(()=>remove(selected()));}
        },true);
        document.addEventListener('keyup',event=>{if(active())safe(()=>move?.keyUp(event));},true);
        function render(ctx){
            if(api.document()!==lastDoc){lastDoc=api.document();editGroup=null;reset();}const on=active(),t=api.tool(),c=commands.get(t);
            for(const b of row.querySelectorAll('[data-sketch-command]')){b.classList.toggle('active',on&&b.dataset.sketchCommand===t);b.setAttribute('aria-pressed',String(on&&b.dataset.sketchCommand===t));}
            note.textContent=on?editGroup?'그룹 편집 중 · 선택 도구에서 Esc: 종료':'CAD 원본 편집':'도구 클릭 → CAD 정밀 편집';
            const valueLabels={move:'거리·좌표',copy:'거리·좌표',stretch:'늘일 거리',rotate:'각도 (°)',scale:'배율',offset:'간격',arc2:'호 높이',polygon:'반지름'};
            inputBar.hidden=!on||!valueLabels[t];$('sketch-value-label').textContent=valueLabels[t]||'값';$('sketch-value').placeholder=t==='rotate'?'예: 90':t==='scale'?'예: 1.5':'예: 300mm';$('sketch-sides-label').hidden=t!=='polygon';$('sketch-copy-state').textContent=copy||t==='copy'?'복사 ON':'';
            if(!on)return;if(c)$('hint').textContent=c.label+' · '+c.hint+(copy?' · 복사 ON':'');
            if(move?.isTool()){$('hint').textContent=move.state()+' · '+move.hint();$('sketch-copy-state').textContent=move.state();$('sketch-value').placeholder='500mm · <100,50> · 5x · 4/';}
            canvas.style.cursor=['pan'].includes(t)?'grab':t==='eraser'?'not-allowed':t==='select'?'default':'crosshair';
            ctx.save();ctx.strokeStyle='#65d8ff';ctx.lineWidth=1.5;ctx.setLineDash([6,4]);
            const path=ps=>{ctx.beginPath();ps.forEach((p,i)=>{const q=api.screen(p);i?ctx.lineTo(q.x,q.y):ctx.moveTo(q.x,q.y);});ctx.stroke();};
            for(const e of preview){for(const ps of e.renderParts?.map(C.geometry)||e.preview||[C.geometry(e)])path(ps);}
            if(stage.length&&hover){path([stage[0],hover]);if(stage[1])path([stage[0],stage[1]]);}
            if(drag){if(drag.kind==='box'){const a=drag.start,b=drag.end;path([a,{x:b.x,y:a.y},b,{x:a.x,y:b.y},a]);}if(['lasso','freehand'].includes(drag.kind))path(drag.kind==='lasso'?[...drag.points,drag.points[0]]:drag.points);if(drag.kind==='eraser')for(const e of api.document().entities.filter(e=>drag.ids.has(e.id)))path(C.geometry(e));}
            if(['scale','flip'].includes(t)&&selected().length){const b=C.bounds(selected()),ps=[{x:b.minX,y:b.minY},{x:b.maxX,y:b.minY},{x:b.maxX,y:b.maxY},{x:b.minX,y:b.maxY}],c=center(b);ctx.strokeStyle='#65dc96';path([...ps,ps[0]]);if(t==='scale'){ctx.fillStyle='#65dc96';for(const p of ps){const q=api.screen(p);ctx.fillRect(q.x-5,q.y-5,10,10);}}else{ctx.strokeStyle='#ff6868';path([{x:c.x,y:b.minY},{x:c.x,y:b.maxY}]);ctx.strokeStyle='#65dc96';path([{x:b.minX,y:c.y},{x:b.maxX,y:c.y}]);}}
            ctx.restore();move?.render(ctx);
        }
        return {render,reset,choose,run,editingGroup:()=>editGroup,pending:()=>!!drag||!!stage.length||!!move?.pending(),cancelPendingMove:()=>{if(!move?.pending())return false;move.cancel();return true;}};
    };
})(window);
