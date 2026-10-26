/* A move retains its original geometry until completion. Preview, typed values,
 * copies and amendments all evaluate that same operation, never its last preview. */
(function (g) {
    'use strict';
    const C = g.CADCore;
    const sub = (a, b) => ({x: a.x - b.x, y: a.y - b.y});
    const add = (a, b) => ({x: a.x + b.x, y: a.y + b.y});
    const angle = v => Math.atan2(v.y, v.x) * 180 / Math.PI;
    const center = b => ({x: (b.minX + b.maxX) / 2, y: (b.minY + b.maxY) / 2});
    const valid = p => p && [p.x, p.y].every(n => Number.isFinite(n) && Math.abs(n) <= 1e9);
    const vertices = e => e.type === 'LINE' ? [e.p, e.q] : e.type === 'LWPOLYLINE' ? e.points : [];
    const elevation = e => Number(e.raw?.find(p=>p[0]===(e.type==='LWPOLYLINE'?38:30))?.[1]||0);
    const same = (a, b) => C.distance(a, b) < 1e-7;
    function signedLength(text, settings) {
        const value = String(text).trim(), negative = value.startsWith('-');
        const unsigned=value.replace(/^[+-]\s*/, '');
        if(/^(?:0+(?:\.0*)?|\.0+)\s*(?:mm|cm|m|in|ft|['"])?$/i.test(unsigned))return 0;
        return (negative ? -1 : 1) * C.parseLengthInput(unsigned, settings);
    }
    function target(text, anchor, cursor, settings) {
        const value = String(text).trim();
        let p;
        if (value.startsWith('[') || value.startsWith('<')) {
            const relative = value[0] === '<', close = relative ? '>' : ']';
            if (!value.endsWith(close)) throw Error('좌표는 [X,Y,0] 또는 <X,Y,0> 형식입니다.');
            const parts = value.slice(1, -1).split(/[,;]/);
            if (![2, 3].includes(parts.length) || parts.some(v => !v.trim())) throw Error('X,Y 좌표를 모두 입력하세요.');
            const ns = parts.map(v => signedLength(v, settings));
            if (ns.length === 3 && ns[2] !== 0) throw Error('2D 도면의 Z 이동은 0이어야 합니다.');
            p = {x: ns[0] + (relative ? anchor.x : 0), y: ns[1] + (relative ? anchor.y : 0)};
        } else {
            const polar = value.split('<');
            if (polar.length > 2) throw Error('거리 또는 거리<각도를 입력하세요.');
            let v = sub(cursor, anchor), length = Math.hypot(v.x, v.y);
            if (polar.length === 2) {
                const a = Number(polar[1].replace(/°|deg/gi, '').trim());
                if (!polar[1].trim() || !Number.isFinite(a)) throw Error('이동 각도를 확인하세요.');
                v = {x: Math.cos(a * Math.PI / 180), y: Math.sin(a * Math.PI / 180)}; length = 1;
            }
            if (length < 1e-10) throw Error('마우스로 이동 방향을 먼저 가리키세요.');
            const d = signedLength(polar[0], settings);
            p = {x: anchor.x + v.x / length * d, y: anchor.y + v.y / length * d};
        }
        if (!valid(p)) throw Error('이동 좌표 범위를 확인하세요.');
        return p;
    }
    function arrayInput(text) {
        const match = String(text).trim().match(/^(?:(\d+)\s*([x*/])|([x*/])\s*(\d+))$/i);
        if (!match) return null;
        const count = Number(match[1] || match[4]), kind = (match[2] || match[3]) === '/' ? 'divide' : 'repeat';
        if (!Number.isInteger(count) || count < 1 || count > 500) throw Error('배열 수는 1~500 사이의 정수입니다.');
        return {count, kind};
    }
    function copies(es, delta, spec = {count: 1, kind: 'repeat'}, rotation = null, alignment = null) {
        if(!Number.isInteger(spec.count)||spec.count<1||spec.count>500||!['repeat','divide'].includes(spec.kind)||![delta.x,delta.y].every(Number.isFinite))throw Error('배열 수와 이동 거리를 확인하세요.');
        if (es.length * spec.count > 50000) throw Error('추가 객체는 한 번에 50,000개까지입니다.');
        const result = [];
        for (let i = 1; i <= spec.count; i++) {
            const factor = spec.kind === 'divide' ? i / spec.count : i;
            const moved = es.map(e => C.transform(alignment?C.transform(e,alignment):e, rotation ? {...rotation, angle: rotation.angle * factor} : {dx: delta.x * factor, dy: delta.y * factor}));
            const b=C.bounds(moved);if(!valid({x:b.minX,y:b.minY})||!valid({x:b.maxX,y:b.maxY}))throw Error('배열 결과가 도면 좌표 범위를 벗어납니다.');
            result.push(...C.copyEntities(moved));
        }
        return result;
    }
    function stretchPlan(pool, selected, pick = null, force = false, groupContext = null) {
        const entries = selected.map(entity => ({entity, indices: null}));
        // Group/INSERT boundaries are independent. Connected raw edges stretch only
        // within their original group, never merely because two groups touch.
        if (pick && selected.length === 1 && (!selected[0].sketchGroup || force || selected[0].sketchGroup===groupContext)) {
            const e = selected[0], ps = vertices(e);
            if (ps.length && C.allows(e, 'resize')) {
                let best = Infinity, index = -1;
                ps.forEach((p, i) => {const d = C.distance(pick.point, p); if (d < best) {best = d; index = i;}});
                if (best < pick.tolerance) entries[0].indices = [index];
                else if (e.type === 'LWPOLYLINE') {
                    best = Infinity;
                    for (let i = 0; i < (e.closed ? ps.length : ps.length - 1); i++) {
                        const d = C.segmentDistance(pick.point, ps[i], ps[(i + 1) % ps.length]);
                        if (d < best) {best = d; entries[0].indices = [i, (i + 1) % ps.length];}
                    }
                }
            }
            if (force && !entries[0].indices && e.type !== 'LINE') throw Error('늘일 직선·폴리선의 끝점 또는 선분을 지정하세요.');
        }
        const nodes = new Map(), cell = p => [Math.floor(p.x / 1e-7), Math.floor(p.y / 1e-7)];
        const key = (e,x,y) => JSON.stringify([e.sketchGroup||'',elevation(e),x,y]);
        for (const entry of entries) {
            if (entry.entity.sketchGroup && entry.entity.sketchGroup!==groupContext && entry.indices === null) continue;
            vertices(entry.entity).forEach((p, i) => {if (entry.indices === null || entry.indices.includes(i)) {const [x,y]=cell(p),k=key(entry.entity,x,y);if(!nodes.has(k))nodes.set(k,[]);nodes.get(k).push(p);}});
        }
        const selectedIds = new Set(selected.map(e => e.id));
        for (const e of pool) {
            if (selectedIds.has(e.id)) continue;
            const indices = [];
            if(e.paper)continue;
            vertices(e).forEach((p, i) => {const [x,y]=cell(p);let hit=false;for(let dx=-1;dx<=1&&!hit;dx++)for(let dy=-1;dy<=1&&!hit;dy++)hit=(nodes.get(key(e,x+dx,y+dy))||[]).some(n=>same(n,p));if(hit)indices.push(i);});
            if (indices.length) entries.push({entity: e, indices});
        }
        return entries;
    }
    function stretch(entries, delta) {
        return entries.map(({entity: e, indices}) => {
            if (indices === null) return C.transform(e, {dx: delta.x, dy: delta.y});
            if (!C.allows(e, 'resize') || !['LINE', 'LWPOLYLINE'].includes(e.type)) throw Error('연결된 객체의 선 늘이기를 지원하지 않습니다.');
            const n = C.copyDocument({entities: [e]}).entities[0];
            const ps = vertices(e).map((p, i) => indices.includes(i) ? {...p, ...add(p, delta)} : {...p});
            if (ps.some(p => !valid(p))) throw Error('늘이기 좌표 범위를 확인하세요.');
            for (let i = 0; i < (e.closed ? ps.length : ps.length - 1); i++) if (C.distance(ps[i], ps[(i + 1) % ps.length]) < 1e-10) throw Error('늘이기로 선 길이를 0으로 만들 수 없습니다.');
            if (e.type === 'LINE') [n.p, n.q] = ps;
            else {n.points = ps; n.p = {...ps[0]};}
            delete n.displayGeometry; n.dirty = true;
            return n;
        });
    }
    g.CADMoveGeometry = {target, arrayInput, copies, stretchPlan, stretch, signedLength};

    g.createCADMoveTools = function (api) {
        const $ = id => document.getElementById(id), canvas = api.canvas;
        let session = null, last = null, mode = 'move', raw = null, point = null, mark = null;
        let reference = null, lock = null, shift = null, inference = null, hoverEntity = null, preview = [];
        let gripMode = 0, down = null, rotations = true, snapSetup = null, attach = null;
        let pendingControl = false, controlUsed = false, controlMode = 'move', shiftHeld = false;
        const isTool = () => ['move', 'copy', 'stretch', 'move-snap'].includes(api.tool());
        const chosen = () => api.document()?.entities.filter(e => api.selection().has(e.id)) || [];
        const groupLike = es => es.length && (es.every(e => e.sketchGroup && e.sketchGroup!==api.editingGroup() && e.sketchGroup === es[0].sketchGroup) || es.length === 1 && es[0].type === 'INSERT');
        const allSnaps = es => es.flatMap(e => (e.sketchSnaps || []).map(s => ({...s, entity: e})));
        const currentToken = () => ({doc: api.document(), version: api.version()});
        const unchanged = token => token?.doc === api.document() && token.version === api.version();
        const stop = event => {event.preventDefault(); event.stopImmediatePropagation();};
        function guard(es, operation) {
            if (!api.writable() || api.busy()) throw Error('읽기 전용이거나 저장 중입니다.');
            if (es.some(e => !api.editable(e) || e.hidden || e.paper || e.transient || api.document().layers[e.layer]?.visible === false || !C.allows(e, operation))) throw Error('잠금 또는 지원하지 않는 객체가 포함되어 있습니다. 아무 객체도 변경하지 않았습니다.');
        }
        function checkSession() {
            if (session && !unchanged(session.token)) {reset(); throw Error('도면이 변경되었습니다. 이동 기준점을 다시 지정하세요.');}
            if (last && !unchanged(last.token)) last = null;
        }
        function reset() {
            const id = down?.id; down = null;
            if (id !== undefined && canvas.hasPointerCapture(id)) canvas.releasePointerCapture(id);
            session = last = null; mode = api.tool() === 'copy' ? 'copy' : 'move';
            raw = point = mark = reference = lock = shift = inference = hoverEntity = null;
            preview = []; snapSetup = attach = null; pendingControl = controlUsed = shiftHeld = false;
        }
        function cancel() {reset(); $('sketch-value').value = ''; api.render();}
        function setMode(value) {
            if (session?.rotation && value === 'stamp') return;
            if (session && value === 'stamp') throw Error('연속복사는 첫 기준점을 클릭하기 전에 선택하세요.');
            mode = value; if (session) update(raw); else api.render();
        }
        function grips(es) {
            if (!es.length || !groupLike(es)) return [];
            const b = C.bounds(es), c = center(b);
            const corners = [{x:b.minX,y:b.minY},{x:b.maxX,y:b.minY},{x:b.maxX,y:b.maxY},{x:b.minX,y:b.maxY}];
            if (gripMode === 0) return corners;
            if (gripMode === 1) return corners.map((p,i) => center({minX:p.x,minY:p.y,maxX:corners[(i+1)%4].x,maxY:corners[(i+1)%4].y}));
            return [c];
        }
        function rotationGrips(es) {
            if (!rotations || !groupLike(es)) return [];
            const b = C.bounds(es), w = b.maxX - b.minX, h = b.maxY - b.minY;
            return [.25,.75].flatMap(x => [.25,.75].map(y => ({x:b.minX+w*x,y:b.minY+h*y})));
        }
        const nearby = (ps, p, tolerance = 9 / api.scale()) => ps.map(q => ({q,d:C.distance(q,p)})).filter(v => v.d < tolerance).sort((a,b) => a.d-b.d)[0]?.q;
        function solve(p) {
            const excluded = new Set(session ? mode==='move'?session.entries.map(v => v.entity.id):session.originals.map(e=>e.id) : []);
            const edges = api.edges(p).filter(e => !excluded.has(e.owner));
            const closeEdge = edges[0];
            if (closeEdge) reference = {edge:closeEdge,angle:angle(sub(closeEdge.q,closeEdge.p))};
            let q = api.snap({...p}, excluded); mark = api.lastSnap() ? {...q,kind:'맞춤점'} : null;
            let rank=mark?(api.lastSnap().kind==='guide-projection'?1:3):0;
            const offer = (candidate, kind, priority = 1) => {if (candidate && C.distance(candidate,p) < 12/api.scale() && (priority>rank || priority===rank&&C.distance(candidate,p)<C.distance(q,p))) {q={x:candidate.x,y:candidate.y};mark={...q,kind};rank=priority;}};
            // Include arbitrary edge points, midpoints and intersections. These are
            // geometric targets, not the tessellated display vertices of a curve.
            if (api.snapEnabled()) {
                for (const e of edges) {offer(C.segmentPoint(p,e.p,e.q),'선 위');offer(e.p,'끝점',3);offer(e.q,'끝점',3);offer(center({minX:e.p.x,minY:e.p.y,maxX:e.q.x,maxY:e.q.y}),'중점',3);}
                for (let i=0;i<edges.length;i++) for (let j=i+1;j<edges.length;j++) {
                    const a=edges[i],b=edges[j],u=sub(a.q,a.p),v=sub(b.q,b.p),den=u.x*v.y-u.y*v.x;
                    if (Math.abs(den)<1e-12) continue;
                    const d=sub(b.p,a.p),t=(d.x*v.y-d.y*v.x)/den,k=(d.x*u.y-d.y*u.x)/den;
                    if(t>=0&&t<=1&&k>=0&&k<=1)offer({x:a.p.x+u.x*t,y:a.p.y+u.y*t},'교차점',4);
                }
                const snap = nearby(allSnaps(api.visible().filter(e=>!excluded.has(e.id))),p);
                if (snap) offer(snap,'연결점',5);
            }
            if (!session) {
                const es = chosen().length ? chosen() : hoverEntity ? api.groupEntities(hoverEntity) : [];
                offer(nearby(grips(es),p),'기준점',5);
                return q;
            }
            const a=session.anchor;
            const locked = lock?.angle ?? shift;
            inference = C.inferDirection(a,q,{enabled:api.inferenceEnabled() || api.ortho(),lock:locked,reference:reference?.angle,thresholdDeg:api.ortho()?45:3});
            if ((locked !== null && locked !== undefined) || !mark) q=inference.point;
            else inference=null;
            // Axis locking preserves one degree of freedom while another entity's
            // point supplies its projected station (SketchUp's inference alignment).
            if (locked !== null && locked !== undefined && mark) mark={...q,kind:'정렬'};
            return q;
        }
        function transformFor(s, p, copyMode = mode !== 'move', allowAttach = true) {
            const delta = sub(p,s.anchor);
            if (s.rotation) {
                const degrees=angle(sub(p,s.rotation.center))-s.rotation.start;
                return {delta,rotation:{center:s.rotation.center,angle:degrees},entities:s.originals.map(e=>C.transform(e,{center:s.rotation.center,angle:degrees}))};
            }
            attach=null;
            if (allowAttach && s.snap && lock===null && shift===null) {
                const pool=(mode==='stamp'?api.visible():s.before).filter(e=>!e.hidden&&!e.paper&&api.document().layers[e.layer]?.visible!==false&&!s.originals.some(n=>n.id===e.id));
                const targetSnap=nearby(allSnaps(pool),p,12/api.scale());
                if(targetSnap){attach=targetSnap;const alignment={center:s.anchor,angle:targetSnap.angle+180-s.snap.angle};return {delta:sub(targetSnap,s.anchor),entities:s.originals.map(e=>C.transform(C.transform(e,alignment),{dx:targetSnap.x-s.anchor.x,dy:targetSnap.y-s.anchor.y})),alignment};}
            }
            if(s.alignment)return {delta,alignment:s.alignment,entities:s.originals.map(e=>C.transform(C.transform(e,s.alignment),{dx:delta.x,dy:delta.y}))};
            return {delta,entities:copyMode?s.originals.map(e=>C.transform(e,{dx:delta.x,dy:delta.y})):stretch(s.entries,delta)};
        }
        function update(p) {
            if (!p) return; checkSession(); raw={...p}; hoverEntity=api.hit(p); point=solve(p); preview=[];
            if(session) preview=transformFor(session,point).entities;
            api.render();
        }
        function begin(p, event) {
            last=null;
            const existing=chosen(),hit=api.hit(p),auto=!existing.length;
            let es=existing;
            if(!es.length){if(!hit)throw Error('이동할 객체 또는 선의 끝점을 클릭하세요.');es=api.groupEntities(hit);}
            else if(api.tool()!=='stretch')es=[...new Map(es.flatMap(e=>api.groupEntities(e)).map(e=>[e.id,e])).values()];
            guard(es,mode==='move'?'move':'copy');
            const rotate=mode!=='stamp'&&nearby(rotationGrips(es),p,7/api.scale());
            const pick=(auto||api.tool()==='stretch')&&!rotate?{point:p,tolerance:9/api.scale()}:null;
            const entries=stretchPlan(api.document().entities,es,pick,api.tool()==='stretch',api.editingGroup());
            if(mode==='move')for(const entry of entries)guard([entry.entity],entry.indices===null?'move':'resize');
            point=solve(p);
            const snap=nearby(allSnaps(es),p);
            if(snap)point={x:snap.x,y:snap.y};
            const b=C.bounds(es),c=center(b);
            session={originals:es,entries,anchor:rotate?c:{...point},before:api.document().entities,token:currentToken(),selection:es.map(e=>e.id),snap,
                rotation:rotate?{center:c,start:angle(sub(p,c))}:null};
            api.select(new Set(es.map(e=>e.id))); raw={...p};
            down={id:event.pointerId,x:event.clientX,y:event.clientY};canvas.setPointerCapture(event.pointerId);
            api.render();
        }
        function commitResult(s, p, spec = null, amend = false, exact = false) {
            const copying=amend?s.copying:mode!=='move';
            for(const entry of copying?s.originals.map(entity=>({entity,indices:null})):s.entries)guard([entry.entity],copying?'copy':entry.indices===null?'move':'resize');
            if(amend&&!unchanged(s.token))throw Error('다른 편집 후에는 이전 배열을 수정할 수 없습니다.');
            const result=transformFor(s,p,copying,!exact);
            if(!result.rotation&&Math.hypot(result.delta.x,result.delta.y)<1e-10)throw Error('시작점에서 떨어진 위치를 지정하세요.');
            if(result.entities.some(e=>C.geometry(e).some(p=>!valid(p))))throw Error('이동 좌표 범위를 확인하세요.');
            let next,selection;
            if(copying){
                const added=spec?copies(s.originals,result.delta,spec,result.rotation,result.alignment):C.copyEntities(result.entities);
                if(api.editingGroup())for(const e of added)e.sketchGroup=api.editingGroup();
                const base=mode==='stamp'&&!amend?api.document().entities:s.before;
                next=base.concat(added);selection=new Set(added.map(e=>e.id));
            }else{const map=new Map(result.entities.map(e=>[e.id,e]));next=s.before.map(e=>map.get(e.id)||e);selection=new Set(s.selection);}
            const token=api.applyMove(next,selection,amend?s.token:null);
            if(mode==='stamp'&&!amend){s.token=token;preview=[];api.status('복사본 배치됨 · 계속 클릭 · Esc: 연속복사 종료');}
            else {last={...s,token,point:result.alignment?add(s.anchor,result.delta):{...p},copying,spec,alignment:result.alignment};session=null;preview=[];mode=api.tool()==='copy'?'copy':'move';lock=shift=null;api.status(copying?'복사 완료 · 숫자: 거리 수정 · 5x: 반복 · 4/: 등분':'이동 완료 · 숫자 입력으로 거리 수정');}
            $('sketch-value').value='';api.render();
        }
        function numeric(value) {
            checkSession(); const spec=arrayInput(value),s=session||last;
            if(!s)throw Error('이동 기준점을 먼저 클릭하세요.');
            if(spec){if(session||!last.copying)throw Error('일반 복사본을 먼저 배치한 뒤 5x 또는 4/를 입력하세요.');commitResult(last,last.point,spec,true);return;}
            let p;
            if(s.rotation){const a=Number(value);if(!String(value).trim()||!Number.isFinite(a))throw Error('회전 각도를 입력하세요.');const radians=(s.rotation.start+a)*Math.PI/180,r=Math.max(1,C.distance(s.anchor,raw||s.point));p={x:s.anchor.x+r*Math.cos(radians),y:s.anchor.y+r*Math.sin(radians)};}
            else p=target(value,s.anchor,session?point:last.point,api.numericSettings());
            commitResult(s,p,session?null:last.spec,!session,true);
        }
        function setupSnap(p) {
            const es=chosen();guard(es,'style');if(!groupLike(es))throw Error('그룹 또는 블록을 선택하세요.');
            if(!snapSetup){snapSetup={point:solve(p),entity:es[0],token:currentToken()};api.status('연결 방향을 가리킨 뒤 클릭하세요.');return;}
            if(!unchanged(snapSetup.token))throw Error('도면이 변경되었습니다. 연결점을 다시 지정하세요.');
            const e=snapSetup.entity,ps=e.sketchSnaps||[];
            if(ps.length>=16)throw Error('한 객체에는 연결점을 16개까지 지정할 수 있습니다.');
            const value={...snapSetup.point,angle:angle(sub(p,snapSetup.point))};
            const next={...e,sketchSnaps:[...ps,value],dirty:true};
            api.applyMove(api.document().entities.map(n=>n.id===e.id?next:n),api.selection());
            snapSetup=null;api.setTool('move');api.status('연결점 추가됨 · M으로 연결점을 잡아 다른 연결점에 맞추세요.');
        }
        function pointerDown(event) {
            if(!isTool()||event.button!==0)return false;stop(event);canvas.focus();if(api.busy())return true;
            checkSession();const p=api.world(event);
            if(api.tool()==='move-snap'){setupSnap(p);api.render();return true;}
            if(session){point=solve(p);commitResult(session,point);}
            else begin(p,event);
            return true;
        }
        function pointerMove(event) {if(!isTool()||api.panning())return false;stop(event);if(!api.busy())update(api.world(event));return true;}
        function pointerUp(event) {
            if(!down||down.id!==event.pointerId)return false;stop(event);const d=down;down=null;
            if(canvas.hasPointerCapture(event.pointerId))canvas.releasePointerCapture(event.pointerId);
            if(session&&Math.hypot(event.clientX-d.x,event.clientY-d.y)>=4){point=solve(api.world(event));commitResult(session,point);}
            return true;
        }
        function keyDown(event) {
            if(!isTool())return false;
            if(event.key==='Control'&&!event.repeat){pendingControl=true;controlUsed=false;controlMode=mode;stop(event);setMode(session?(mode==='move'?'copy':'move'):mode==='move'?'copy':mode==='copy'?'stamp':'move');return true;}
            if(pendingControl&&!controlUsed){controlUsed=true;mode=controlMode;if(session)update(raw);else api.render();}
            if(event.ctrlKey||event.metaKey)return false;
            if(event.key==='Escape'){stop(event);cancel();api.status('이동 취소 · 기준점을 다시 클릭하세요.');return true;}
            if(event.key==='Shift'&&!event.repeat&&session){stop(event);shiftHeld=true;shift=inference?.angle??angle(sub(point,session.anchor));update(raw);return true;}
            if(event.key==='Alt'&&!event.repeat){stop(event);if(session?.snap){const candidates=allSnaps(api.visible().filter(e=>!session.originals.some(n=>n.id===e.id)));candidates.sort((a,b)=>C.distance(a,raw)-C.distance(b,raw));if(candidates[0])update(candidates[0]);}else if(!session){gripMode=(gripMode+1)%3;update(raw);}return true;}
            if(['ArrowRight','ArrowLeft','ArrowUp','ArrowDown'].includes(event.key)){
                stop(event);
                if(event.key==='ArrowUp'){api.status('2D 도면은 XY 평면입니다. →: X축 · ←: Y축 · ↓: 평행/직각');return true;}
                if(event.key==='ArrowDown'){
                    if(!reference){api.status('참조할 선 위에 마우스를 먼저 올리세요.');return true;}
                    const kind=lock?.kind==='parallel'?'perpendicular':lock?.kind==='perpendicular'?null:'parallel';
                    lock=kind?{kind,angle:reference.angle+(kind==='perpendicular'?90:0)}:null;
                }else{const kind=event.key==='ArrowRight'?'x':'y';lock=lock?.kind===kind?null:{kind,angle:kind==='x'?0:90};}
                update(raw);return true;
            }
            return false;
        }
        function keyUp(event) {
            if(event.key==='Control'&&pendingControl){pendingControl=false;return;}
            if(event.key==='Shift'&&shiftHeld){stop(event);shiftHeld=false;shift=null;if(isTool())update(raw);}
        }
        function contextItems() {
            const item=(label,action,key='',enabled=true)=>({label,action,key,disabled:!enabled});
            const items=[item('이동',()=>setMode('move'),'M'),item('이동 복사',()=>setMode('copy'),'Ctrl'),item('연속복사',()=>setMode('stamp'),'Ctrl 두 번',!session),null,
                item('X축 방향 고정',()=>{lock={kind:'x',angle:0};update(raw);},'→'),item('Y축 방향 고정',()=>{lock={kind:'y',angle:90};update(raw);},'←'),
                item('참조선과 평행',()=>{lock={kind:'parallel',angle:reference.angle};update(raw);},'↓',!!reference),item('참조선에 직각',()=>{lock={kind:'perpendicular',angle:reference.angle+90};update(raw);},'↓',!!reference),
                item('방향 고정 해제',()=>{lock=shift=null;update(raw);}),null];
            if(last?.copying&&unchanged(last.token))items.push(item('반복 배열',()=>{$('sketch-value').value='5x';$('sketch-value').focus();$('sketch-value').select();},'5x'),item('구간 등분 배열',()=>{$('sketch-value').value='4/';$('sketch-value').focus();$('sketch-value').select();},'4/'),null);
            if(session||snapSetup)items.push(item('현재 작업 취소 / 연속복사 종료',cancel,'Esc'));
            else items.push(item('기준점 전환',()=>{gripMode=(gripMode+1)%3;update(raw);},'Alt'),item(rotations?'회전 손잡이 숨기기':'회전 손잡이 표시',()=>{rotations=!rotations;api.render();}));
            items.push(null,item('실행 취소',()=>{$('undo').click();},'Ctrl+Z'),item('다시 실행',()=>{$('redo').click();},'Ctrl+Y'));
            return items;
        }
        function render(ctx) {
            if(!isTool())return;
            if(last&&!unchanged(last.token))last=null;
            const line=ps=>{ctx.beginPath();ps.forEach((p,i)=>{const q=api.screen(p);i?ctx.lineTo(q.x,q.y):ctx.moveTo(q.x,q.y);});ctx.stroke();};
            ctx.save();ctx.strokeStyle='#65d8ff';ctx.lineWidth=2;ctx.setLineDash([6,4]);
            for(const e of preview)for(const path of e.renderParts?.map(C.geometry)||e.preview||[C.geometry(e)])line(path);
            if(!session&&hoverEntity){ctx.setLineDash([]);for(const e of api.groupEntities(hoverEntity))line(C.geometry(e));}
            if(session&&point){ctx.strokeStyle=lock?.kind==='x'||inference?.kind==='x'?'#ff6868':lock?.kind==='y'||inference?.kind==='y'?'#65dc96':'#d88aff';line([session.anchor,point]);}
            if(reference&&(lock?.kind==='parallel'||lock?.kind==='perpendicular'||['parallel','perpendicular'].includes(inference?.kind))){ctx.strokeStyle='#d88aff';line([reference.edge.p,reference.edge.q]);}
            ctx.setLineDash([]);
            const es=chosen().length?chosen():hoverEntity?api.groupEntities(hoverEntity):[];
            if(!session){ctx.fillStyle='#83dfff';for(const p of grips(es)){const q=api.screen(p);ctx.fillRect(q.x-4,q.y-4,8,8);}ctx.strokeStyle='#ff6868';for(const p of rotationGrips(es)){const q=api.screen(p);ctx.beginPath();ctx.moveTo(q.x-5,q.y);ctx.lineTo(q.x+5,q.y);ctx.moveTo(q.x,q.y-5);ctx.lineTo(q.x,q.y+5);ctx.stroke();}}
            ctx.strokeStyle='#f6c965';for(const s of allSnaps(api.visible())){const q=api.screen(s),a=s.angle*Math.PI/180;ctx.beginPath();ctx.arc(q.x,q.y,5,0,Math.PI*2);ctx.moveTo(q.x,q.y);ctx.lineTo(q.x+13*Math.cos(a),q.y-13*Math.sin(a));ctx.stroke();}
            if(snapSetup&&point){ctx.strokeStyle='#f6c965';line([snapSetup.point,point]);}
            if(mark){const q=api.screen(mark);ctx.strokeStyle='#ffb347';ctx.strokeRect(q.x-5,q.y-5,10,10);}
            if(point){const q=api.screen(point);let text=session?(session.rotation?'회전 '+(angle(sub(point,session.anchor))-session.rotation.start).toFixed(1)+'°':api.format(C.distance(session.anchor,point))):mark?.kind||'';if(mode!=='move')text+=(mode==='stamp'?' · 연속복사':' · 복사');if(attach)text+=' · 연결점 정렬';if(text){ctx.font='12px sans-serif';const w=ctx.measureText(text).width;ctx.fillStyle='#142033ed';ctx.fillRect(q.x+12,q.y-28,w+12,22);ctx.fillStyle='#fff';ctx.fillText(text,q.x+18,q.y-13);}}
            ctx.restore();
        }
        return {isTool,reset,cancel,contextItems,render,pointerDown,pointerMove,pointerUp,keyDown,keyUp,numeric,setMode,
            lostCapture:()=>{if(down)cancel();},
            pending:()=>!!session||!!snapSetup,canType:()=>!!session||!!last,hasLast:()=>!!last,
            state:()=>session?.rotation?'회전':mode==='stamp'?'연속복사':mode==='copy'?'복사':'이동',
            hint:()=>api.tool()==='move-snap'?'연결점 위치 → 방향':session?mode==='stamp'?'클릭마다 복사 · Esc: 종료':'목적점 클릭 / 드래그 놓기 · 거리·좌표 입력 · Shift: 방향 유지 · Ctrl: 복사':last?'숫자: 거리 수정 · 복사 후 5x: 반복 / 4/: 등분 · 다음 기준점 클릭':'대상·기준점 클릭 → 목적점 · Ctrl: 복사 / 두 번: 연속복사 · Alt: 기준점 · 선택 없이 끝점·선분: 늘이기'};
    };
})(window);
