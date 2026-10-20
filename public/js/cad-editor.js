(function () {
    "use strict";
    const C = window.CADCore,
        $ = (id) => document.getElementById(id),
        canvas = $("canvas"),
        ctx = canvas.getContext("2d");
    let doc = null,
        record = null,
        selected = new Set(),
        tool = "select",
        points = [],
        undo = [],
        redo = [],
        dirty = false,
        scale = 1,
        origin = { x: 0, y: 0 },
        pan = null,
        canManage = false,
        cap = {},
        busy = false;
    const query = new URLSearchParams(location.search);
    const claimContext = {
        lineId: Number(query.get('claim_line_id')),
        contractId: Number(query.get('claim_contract_id')),
        siteId: Number(query.get('site_id')),
        draftRevision: Number(query.get('claim_draft_revision')),
    };
    const inClaim = [claimContext.lineId,claimContext.contractId,claimContext.siteId,claimContext.draftRevision].every(n=>Number.isSafeInteger(n)&&n>0);
    const proofMode = query.has('drawing_id') && query.has('revision') && !inClaim;
    let claimValid = false;
    const claimParent = window.parent !== window ? window.parent : null;
    window.CADClaimBridge = {beforeClose: guard};
    function notifyClaim(type) {
        if(claimParent)claimParent.postMessage({type,lineId:claimContext.lineId,contractId:claimContext.contractId},location.origin);
    }
    if(inClaim){
        document.body.classList.add('claim-linked');
        $('claim-context').hidden=false;
        $('claim-attach').disabled=true;
        $('claim-context-label').textContent='기성 계약 #'+claimContext.contractId+' · 공정 줄 #'+claimContext.lineId+' · 현장 고정';
        $('sites').disabled=true;
        document.querySelector('header a').hidden=true;
        $('claim-return').onclick=()=>{if(claimParent)notifyClaim('erp:cad-claim-close');else if(guard())history.back();};
        $('claim-attach').onclick=attachClaim;
    }
    async function attachClaim(){
        if(gesture){status('드래그를 끝내거나 Esc로 취소한 뒤 연결하세요.');return;}
        if(!inClaim||!claimValid||!record||busy)return;
        if(dirty){status('CAD 도면을 먼저 저장한 뒤 저장한 개정을 연결하세요.');return;}
        if(Number(record.site_id)!==claimContext.siteId){status('이 기성 공정의 현장과 같은 현장 도면을 선택하세요.');return;}
        const drawingId=record.id,drawingRevision=record.revision;
        busy=true;$('claim-attach').disabled=true;
        try{
            const result=await api('/'+drawingId+'/claim-draft',{method:'POST',body:JSON.stringify({lineId:claimContext.lineId,drawingRevision,draftRevision:claimContext.draftRevision})});
            claimContext.draftRevision=result.draft.revision;
            $('claim-result').textContent='CAD 개정 '+drawingRevision+' 연결됨 · SHA256 '+String(result.evidence.sha256||'').slice(0,16)+' · 기성 수량 유지';
            busy=false;
            notifyClaim('erp:cad-claim-attached');
        }catch(e){fail(e);}finally{busy=false;$('claim-attach').disabled=false;}
    }
    if(proofMode){$('sites').disabled=true;$('drawings').disabled=true;$('history').disabled=true;}
    let gesture = null, inspectorKey = "";
    const styleEdits=new Set(),textEdits=new Set();
    const labels = {
        select: "선택",
        line: "선",
        polyline: "연속선",
        rectangle: "사각형",
        circle: "원",
        arc: "원호",
        text: "문자",
        dimension: "거리 치수",
        measure: "거리 측정",
        move: "이동",
        copy: "복사",
        rotate: "회전",
        scale: "축척",
        mirror: "좌우 대칭",
        delete: "삭제",
    };
    function status(s) {
        $("status").textContent = s;
    }
    function fail(e) {
        status(e.message || String(e));
    }
    async function api(path = "", options = {}) {
        const r = await fetch("/admin-api/cad" + path, {
            credentials: "same-origin",
            ...options,
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": document.querySelector("meta[name=csrf-token]")
                    .content,
                ...(options.body instanceof FormData
                    ? {}
                    : { "Content-Type": "application/json" }),
                ...options.headers,
            },
        });
        const j = await r.json();
        if (!r.ok)
            throw Error(
                r.status === 409
                    ? (path.endsWith("/claim-draft") ? "기성 초안이 다른 화면에서 변경되었습니다. CAD 편집은 유지됩니다. 기성으로 돌아가 최신 초안을 확인한 뒤 다시 연결하세요." : "다른 사용자가 저장했습니다. 현재 편집은 유지됩니다. DXF를 내려받아 보관한 뒤 다시 열어 병합하세요.")
                    : j.message || j.error || "요청 실패 " + r.status,
            );
        return j.data || j;
    }
    function guard() {
        if(gesture){status("드래그를 끝내거나 Esc로 취소하세요.");return false;}
        if (busy) {
            status("저장이 끝날 때까지 기다려 주세요.");
            return false;
        }
        return (
            !dirty ||
            confirm(
                "저장하지 않은 변경을 버릴까요? 취소하면 DXF로 내려받거나 저장할 수 있습니다.",
            )
        );
    }
    function mark() {
        dirty = true;
        $("dirty").textContent = "● 저장 안 됨";
    }
    function snapshot() {
        undo.push(C.clone(doc));
        if (undo.length > 60) undo.shift();
        redo = [];
        mark();
    }
    function editable(e) {
        return e.editable && !doc.layers[e.layer]?.locked;
    }
    function entities() {
        return doc
            ? doc.entities.map(e=>gesture?.preview?.get(e.id)||e).filter(
                  (e) =>
                      !e.hidden &&
                      !e.paper &&
                      doc.layers[e.layer]?.visible !== false,
              )
            : [];
    }
    function world(e) {
        const r = canvas.getBoundingClientRect();
        return {
            x: (e.clientX - r.left - origin.x) / scale,
            y: -(e.clientY - r.top - origin.y) / scale,
        };
    }
    function screen(p) {
        return { x: origin.x + p.x * scale, y: origin.y - p.y * scale };
    }
    function snap(p, exclude = new Set()) {
        if ($("snap").checked) {
            let best = 12 / scale,
                out = p;
            for (const e of entities()) {
                if(exclude.has(e.id))continue;
                const ps =
                    e.type === "LINE"
                        ? [
                              e.p,
                              e.q,
                              {
                                  x: (e.p.x + e.q.x) / 2,
                                  y: (e.p.y + e.q.y) / 2,
                              },
                          ]
                        : e.type === "LWPOLYLINE"
                          ? e.points
                          : [e.p];
                for (const q of ps) {
                    const d = C.distance(p, q);
                    if (d < best) {
                        best = d;
                        out = q;
                    }
                }
            }
            p = { ...out };
        }
        const orthoAnchor=points.length&&['line','polyline','move','copy'].includes(tool)?points.at(-1):gesture?.kind==='draw'&&gesture.tool==='line'?gesture.start:gesture?.kind==='move'?gesture.start:null;
        if($('ortho').checked&&orthoAnchor){if(Math.abs(p.x-orthoAnchor.x)>Math.abs(p.y-orthoAnchor.y))p.y=orthoAnchor.y;else p.x=orthoAnchor.x;}
        return p;
    }

    function entityColor(e){return e.color!==undefined&&e.color!==null?'#'+e.color.toString(16).padStart(6,'0'):e.aci>0&&e.aci<256?C.aci(e.aci):doc.layers[e.layer]?.color||'#e2e8f0';}
    function inspector(){
        const es=doc?doc.entities.filter(e=>selected.has(e.id)):[],first=es[0];
        const key=JSON.stringify([canManage,busy,...es.map(e=>[e.id,e.color,e.aci,e.lineWeight,e.lineType,e.text,e.height,e.angle,doc.layers[e.layer]?.locked,doc.layers[e.layer]?.color,doc.layers[e.layer]?.lineWeight])]);
        if(key===inspectorKey)return;inspectorKey=key;styleEdits.clear();textEdits.clear();
        $('selection-properties').textContent=first?(es.length===1?first.type+' · '+(editable(first)?'드래그 이동 / 손잡이 크기조절':'읽기 전용 또는 레이어 잠금'):es.length+'개 선택 · 첫 객체의 속성 표시'):'객체를 선택하면 속성이 표시됩니다. 새 객체 기본값은 왼쪽에서 설정하세요.';
        if(first){$('entity-color').value=entityColor(first);$('entity-width').value=first.lineWeight>=0?first.lineWeight/100:(first.lineWeight??-1);$('entity-linetype').value=first.lineType||'BYLAYER';}
        const text=es.find(e=>e.type==='TEXT'||e.type==='MTEXT');
        if(text){$('text').value=text.text;$('height').value=text.height;$('text-color').value=entityColor(text);}
        $('text-properties').disabled=!text||!canManage||busy||!es.some(e=>e.type==='TEXT'&&editable(e));
        const shapeEnabled=canManage&&!busy&&es.some(e=>!['TEXT','MTEXT'].includes(e.type)&&editable(e));
        $('apply-style').disabled=!shapeEnabled;
        for(const id of ['entity-color','entity-width','entity-linetype'])$(id).disabled=!shapeEnabled;
    }
    function hitEntity(p){
        for(const e of entities().slice().reverse()){
            if(['TEXT','MTEXT'].includes(e.type)){
                const a=-(e.angle||0)*Math.PI/180,dx=p.x-e.p.x,dy=p.y-e.p.y,x=dx*Math.cos(a)-dy*Math.sin(a),y=dx*Math.sin(a)+dy*Math.cos(a);
                ctx.save();ctx.font=Math.max(1,e.height*scale)+'px sans-serif';const width=ctx.measureText(e.text.replace(/\\P/g,' ')).width/scale;ctx.restore();
                if(x>=-5/scale&&x<=width+5/scale&&y>=-e.height*.3-5/scale&&y<=e.height+5/scale)return e;
            }
            if(C.contains(e,p))return e;
            const ps=C.geometry(e);if(ps.length===1&&C.distance(p,ps[0])<=10/scale)return e;
            for(let i=1;i<ps.length;i++)if(C.segmentDistance(p,ps[i-1],ps[i])<=10/scale)return e;
        }return null;
    }
    function gripAt(p){
        if(!doc||!canManage||busy)return null;
        for(const e of doc.entities.filter(e=>selected.has(e.id)&&editable(e)))for(const grip of C.grips(e))if(C.distance(p,grip.point)<=8/scale)return {entity:e,key:grip.key};
        return null;
    }
    function cancelGesture(){if(!gesture)return;const id=gesture.pointerId;gesture=null;if(canvas.hasPointerCapture(id))canvas.releasePointerCapture(id);render();}
    function standardWeight(value){const weights=[0,5,9,13,15,18,20,25,30,35,40,50,53,60,70,80,90,100,106,120,140,158,200,211];return weights.reduce((best,n)=>Math.abs(n-value*100)<Math.abs(best-value*100)?n:best,25);}

    function render() {
        const r = canvas.getBoundingClientRect(),
            dpr = window.devicePixelRatio || 1;
        canvas.width = r.width * dpr;
        canvas.height = r.height * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, r.width, r.height);
        const spacing = Number($("spacing").value);
        if ($("grid").checked && spacing > 0 && spacing * scale > 8) {
            ctx.strokeStyle = "#202c40";
            ctx.lineWidth = 1;
            const step = spacing * scale;
            ctx.beginPath();
            for (
                let x = ((origin.x % step) + step) % step;
                x < r.width;
                x += step
            ) {
                ctx.moveTo(x, 0);
                ctx.lineTo(x, r.height);
            }
            for (
                let y = ((origin.y % step) + step) % step;
                y < r.height;
                y += step
            ) {
                ctx.moveTo(0, y);
                ctx.lineTo(r.width, y);
            }
            ctx.stroke();
        }
        for (const e of entities()) {
            const ps = C.geometry(e);
            if (!ps.length) continue;
            ctx.strokeStyle = e.color !== undefined && e.color !== null
                  ? "#" + e.color.toString(16).padStart(6, "0")
                  : e.aci > 0 && e.aci < 256
                    ? C.aci(e.aci)
                    : doc.layers[e.layer]?.color || "#e2e8f0";
            ctx.fillStyle = ctx.strokeStyle;
            ctx.setLineDash(
                (
                    doc.lineTypes?.[
                        e.lineType === "BYLAYER"
                            ? doc.layers[e.layer]?.lineType || "CONTINUOUS"
                            : e.lineType
                    ] || []
                ).map((n) => Math.max(1, Math.abs(n) * scale)),
            );
            ctx.lineWidth = selected.has(e.id)
                ? Math.max(2,(e.lineWeight>0?e.lineWeight/100:0)*3.78)
                : Math.max(
                      1,
                      (e.lineWeight > 0 ? e.lineWeight / 100 : 0) * 3.78,
                  );
            ctx.beginPath();
            if (e.type === "INSERT") {
                for (const path of e.preview || [])
                    path.forEach((p, i) => {
                        const s = screen(p);
                        i ? ctx.lineTo(s.x, s.y) : ctx.moveTo(s.x, s.y);
                    });
                ctx.stroke();
                continue;
            }
            ps.forEach((p, i) => {
                const s = screen(p);
                i ? ctx.lineTo(s.x, s.y) : ctx.moveTo(s.x, s.y);
            });
            ctx.stroke();
            if (["TEXT", "MTEXT"].includes(e.type)) {
                const p = screen(e.p);
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate((-(e.angle || 0) * Math.PI) / 180);
                ctx.font = Math.max(1, e.height * scale) + "px sans-serif";
                ctx.fillText(e.text.replace(/\\P/g, " "), 0, 0);
                ctx.restore();
            }
            if (e.type === "POINT") {
                const p = screen(e.p);
                ctx.fillRect(p.x - 2, p.y - 2, 4, 4);
            }
        }
        ctx.setLineDash([]);
        if(gesture?.kind==='draw'&&gesture.moved){
            ctx.strokeStyle=$('default-color').value;ctx.lineWidth=2;ctx.setLineDash([6,4]);ctx.beginPath();
            const a=screen(gesture.start),b=screen(gesture.end);if(gesture.tool==='rectangle')ctx.rect(a.x,a.y,b.x-a.x,b.y-a.y);else {ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);}ctx.stroke();ctx.setLineDash([]);
        }
        if(tool==='select'&&canManage&&!busy)for(const e of entities().filter(e=>selected.has(e.id)&&editable(e))){for(const grip of C.grips(e)){const p=screen(grip.point);ctx.fillStyle='#101827';ctx.strokeStyle='#67e8f9';ctx.lineWidth=1.5;ctx.fillRect(p.x-4,p.y-4,8,8);ctx.strokeRect(p.x-4,p.y-4,8,8);}}
        inspector();
        ctx.fillStyle = "#5eead4";
        points.forEach((p) => {
            const s = screen(p);
            ctx.fillRect(s.x - 3, s.y - 3, 6, 6);
        });
        $("selection").textContent = "선택 " + selected.size + "개";
        $("hint").textContent =
            labels[tool] +
            ": " +
            ({
                select: "객체 드래그 이동 · 손잡이 드래그 크기조절 · Shift 추가 선택",
                line: "드래그하여 선 그리기 · 클릭 두 번 / 좌표 입력 가능",
                rectangle: "드래그하여 사각형 그리기 · 클릭 두 번 / 좌표 입력 가능",
                polyline: "점을 차례로 지정 · Enter 완료",
                arc: "중심 → 시작점 → 끝점",
                rotate: "각도 입력 후 기준점 지정",
                scale: "배율 입력 후 기준점 지정",
                mirror: "수직 대칭축 위 점 지정",
                move: "기준점 → 목적점",
                copy: "기준점 → 목적점",
            }[tool] || "점을 순서대로 지정 · Esc 취소");
    }
    function fit() {
        if(gesture||pan){status('드래그 또는 화면 이동을 끝낸 뒤 전체 보기를 사용하세요.');return;}
        if (!doc) return;
        const b = C.bounds(entities()),
            r = canvas.getBoundingClientRect();
        scale =
            Math.min(
                r.width / Math.max(1, b.maxX - b.minX),
                r.height / Math.max(1, b.maxY - b.minY),
            ) * 0.85;
        origin = {
            x: r.width / 2 - ((b.minX + b.maxX) / 2) * scale,
            y: r.height / 2 + ((b.minY + b.maxY) / 2) * scale,
        };
        render();
    }
    function setTool(t) {
        if (!doc || busy) return;
        cancelGesture();
        tool = t;
        points = [];
        document
            .querySelectorAll("[data-tool]")
            .forEach((b) => b.classList.toggle("active", b.dataset.tool === t));
        if (t === "delete") {
            if (!canManage) return;
            const ids = doc.entities
                .filter((e) => selected.has(e.id) && editable(e))
                .map((e) => e.id);
            if (ids.length) {
                snapshot();
                doc.entities = doc.entities.filter((e) => !ids.includes(e.id));
                selected.clear();
            }
            tool = "select";
        }
        render();
    }
    Object.entries(labels).forEach(([k, l]) => {
        const b = document.createElement("button");
        b.textContent = l;
        b.dataset.tool = k;
        b.onclick = () => setTool(k);
        $("tool-buttons").append(b);
    });
    function add(type, data) {
        const text=type==='TEXT'||type==='MTEXT';
        const color=parseInt($(text?'default-text-color':'default-color').value.slice(1),16),width=Number($('default-width').value);
        const e=C.newEntity(type,{...data,color,lineWeight:standardWeight(Number.isFinite(width)?width:.25)},$('layer').value||'0');doc.entities.push(e);return e;
    }
    function finishPolyline() {
        if(!doc||!canManage||busy||gesture||doc.layers[$("layer").value]?.locked)return;
        if (tool === "polyline" && points.length > 1) {
            snapshot();
            add("LWPOLYLINE", {
                p: points[0],
                points: C.clone(points),
                closed: false,
            });
            points = [];
            render();
        }
    }
    function point(p, shift = false) {
        if (!doc || busy || gesture) return;
        if (tool === "select") {
            const hit=hitEntity(p);
            if(!shift)selected.clear();
            if(hit){if(shift&&selected.has(hit.id))selected.delete(hit.id);else selected.add(hit.id);}
            render();
            return;
        }
        if (tool !== "measure" && !canManage) {
            status("읽기 전용 권한입니다.");
            return;
        }
        if (doc.layers[$("layer").value]?.locked) {
            status("작업 레이어가 잠겨 있습니다.");
            return;
        }
        if (
            ["move", "copy", "rotate", "scale", "mirror"].includes(tool) &&
            !selected.size
        ) {
            status("선택 도구로 객체를 먼저 선택하세요.");
            return;
        }
        points.push(p);
        const needed = {
            line: 2,
            rectangle: 2,
            circle: 2,
            arc: 3,
            text: 1,
            dimension: 2,
            measure: 2,
            move: 2,
            copy: 2,
            rotate: 1,
            scale: 1,
            mirror: 1,
        }[tool];
        if (!needed || points.length < needed) {
            render();
            return;
        }
        const [a, b, c] = points;
        if (tool === "measure") {
            status(
                "거리 " +
                    C.distance(a, b).toFixed(6) +
                    " 도면 단위 · ΔX " +
                    (b.x - a.x).toFixed(6) +
                    " · ΔY " +
                    (b.y - a.y).toFixed(6),
            );
            points = [];
            render();
            return;
        }
        const h = Number($("default-text-height").value),
            angle = Number($("angle").value),
            factor = Number($("factor").value);
        if((['text','dimension'].includes(tool)&&(!Number.isFinite(h)||h<=0))||(['rotate','text'].includes(tool)&&!Number.isFinite(angle))||(tool==='scale'&&(!Number.isFinite(factor)||factor<=0))){points=[];status('글자 크기·각도·배율 값을 확인하세요.');render();return;}
        if(['line','rectangle'].includes(tool)&&(C.distance(a,b)<1e-10||(tool==='rectangle'&&(Math.abs(a.x-b.x)<1e-10||Math.abs(a.y-b.y)<1e-10)))){points=[];status('길이·폭·높이는 0보다 커야 합니다.');render();return;}
        snapshot();
        if (tool === "line") add("LINE", { p: a, q: b });
        if (tool === "rectangle")
            add("LWPOLYLINE", {
                p: a,
                points: [a, { x: b.x, y: a.y }, b, { x: a.x, y: b.y }],
                closed: true,
            });
        if (tool === "circle")
            add("CIRCLE", { p: a, radius: C.distance(a, b) });
        if (tool === "arc")
            add("ARC", {
                p: a,
                radius: C.distance(a, b),
                start: (Math.atan2(b.y - a.y, b.x - a.x) * 180) / Math.PI,
                end: (Math.atan2(c.y - a.y, c.x - a.x) * 180) / Math.PI,
            });
        if (tool === "text")
            add("TEXT", { p: a, text: $("default-text").value, height: h, angle });
        if (tool === "dimension") {
            add("LINE", { p: a, q: b });
            add("TEXT", {
                p: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 },
                text: C.distance(a, b).toFixed(3),
                height: h,
                angle: (Math.atan2(b.y - a.y, b.x - a.x) * 180) / Math.PI,
            });
            status(
                "거리 치수는 선과 문자로 작성되었습니다. 자동 연동 치수는 아닙니다.",
            );
        }
        if (["move", "copy", "rotate", "scale", "mirror"].includes(tool)) {
            const o =
                tool === "move" || tool === "copy"
                    ? { dx: b.x - a.x, dy: b.y - a.y }
                    : tool === "rotate"
                      ? { angle, center: a }
                      : tool === "scale"
                        ? { scale: factor, center: a }
                        : { mirror: "y", center: a };
            const copies = [];
            doc.entities = doc.entities.map((e) => {
                if (!selected.has(e.id) || !editable(e)) return e;
                const n = C.transform(e, o);
                if (tool === "copy") {
                    n.id = "copy" + Math.random();
                    if (n.raw)
                        n.raw = n.raw.filter(
                            (p) => ![5, 330, 360].includes(p[0]),
                        );
                    copies.push(n);
                    return e;
                }
                return n;
            });
            doc.entities.push(...copies);
        }
        points = [];
        if(['line','rectangle','text'].includes(tool)){selected.clear();selected.add(doc.entities.at(-1).id);setTool('select');}
        render();
    }
    canvas.onpointerdown = e => {
        if(gesture||pan)return;canvas.focus();
        if(e.button===1||e.button===2){e.preventDefault();pan={pointerId:e.pointerId,x:e.clientX,y:e.clientY,ox:origin.x,oy:origin.y};canvas.setPointerCapture(e.pointerId);return;}
        if(e.button!==0||!doc||busy)return;
        const p=world(e);
        if(tool==='select'){
            const grip=!e.shiftKey&&gripAt(p),hit=grip?.entity||hitEntity(p);
            if(!grip){if(e.shiftKey){if(hit){selected.has(hit.id)?selected.delete(hit.id):selected.add(hit.id);}render();return;}
                if(!hit||!selected.has(hit.id))selected.clear();if(hit)selected.add(hit.id);}
            render();if(!hit||!canManage||!editable(hit))return;
            gesture={kind:grip?'resize':'move',key:grip?.key,entity:hit,start:p,end:p,pointerId:e.pointerId,sx:e.clientX,sy:e.clientY,moved:false,originals:grip?[C.clone(hit)]:doc.entities.filter(e=>selected.has(e.id)&&editable(e)).map(C.clone),preview:new Map()};
        }else if(['line','rectangle'].includes(tool)&&!points.length){
            if(!canManage||doc.layers[$('layer').value]?.locked){status('읽기 전용 또는 작업 레이어 잠금입니다.');return;}
            gesture={kind:'draw',tool,start:snap(p),end:snap(p),pointerId:e.pointerId,sx:e.clientX,sy:e.clientY,moved:false};
        }else {point(snap(p),e.shiftKey);return;}
        canvas.setPointerCapture(e.pointerId);e.preventDefault();
    };
    canvas.onpointermove=e=>{
        if(pan&&pan.pointerId===e.pointerId){origin={x:pan.ox+e.clientX-pan.x,y:pan.oy+e.clientY-pan.y};render();return;}
        const excluded=new Set(gesture?.originals?.map(e=>e.id)||[]),p=snap(world(e),excluded);
        $('x').value=p.x.toFixed(4);$('y').value=p.y.toFixed(4);
        if(!gesture||gesture.pointerId!==e.pointerId){canvas.style.cursor=tool==='select'?(gripAt(world(e))?'nwse-resize':hitEntity(world(e))?'move':'default'):'crosshair';return;}
        if(!canManage||busy){cancelGesture();return;}
        if(Math.hypot(e.clientX-gesture.sx,e.clientY-gesture.sy)<4&&!gesture.moved)return;
        gesture.moved=true;gesture.end=p;
        if(gesture.kind!=='draw'){
            try{
                if(gesture.originals.some(e=>!editable(doc.entities.find(n=>n.id===e.id)))){cancelGesture();return;}
                const preview=gesture.originals.map(e=>gesture.kind==='resize'?C.resize(e,gesture.key,p):C.transform(e,{dx:p.x-gesture.start.x,dy:p.y-gesture.start.y}));
                gesture.preview=new Map(preview.map(e=>[e.id,e]));gesture.invalid=false;
            }catch(error){gesture.invalid=true;gesture.preview=new Map();status(error.message);}
        }render();
    };
    canvas.onpointerup=e=>{
        if(pan?.pointerId===e.pointerId){pan=null;if(canvas.hasPointerCapture(e.pointerId))canvas.releasePointerCapture(e.pointerId);return;}
        if(!gesture||gesture.pointerId!==e.pointerId)return;
        const finished=gesture;gesture=null;if(canvas.hasPointerCapture(e.pointerId))canvas.releasePointerCapture(e.pointerId);
        if(!canManage||busy||finished.invalid){render();return;}
        if(finished.kind==='draw'){
            if(!finished.moved){point(finished.start);return;}
            if(C.distance(finished.start,finished.end)<1e-10||(finished.tool==='rectangle'&&(Math.abs(finished.start.x-finished.end.x)<1e-10||Math.abs(finished.start.y-finished.end.y)<1e-10))){render();return;}
            point(finished.start);point(finished.end);
        }else if(finished.moved&&finished.preview.size){const changed=finished.originals.some(e=>JSON.stringify(C.geometry(e))!==JSON.stringify(C.geometry(finished.preview.get(e.id)||e)));if(changed){snapshot();doc.entities=doc.entities.map(e=>finished.preview.get(e.id)||e);}render();}
    };
    canvas.onpointercancel=e=>{if(pan?.pointerId===e.pointerId)pan=null;if(gesture?.pointerId===e.pointerId)cancelGesture();};
    canvas.onlostpointercapture=e=>{if(pan?.pointerId===e.pointerId)pan=null;if(gesture?.pointerId===e.pointerId)cancelGesture();};
    canvas.oncontextmenu = (e) => e.preventDefault();
    canvas.addEventListener(
        "wheel",
        (e) => {
            e.preventDefault();
            if(gesture||pan)return;
            const p = world(e),
                r = canvas.getBoundingClientRect();
            scale = Math.min(
                1e8,
                Math.max(1e-8, scale * Math.exp(-e.deltaY * 0.001)),
            );
            origin = {
                x: e.clientX - r.left - p.x * scale,
                y: e.clientY - r.top + p.y * scale,
            };
            render();
        },
        { passive: false },
    );
    function layers() {
        if (doc) {
            const previous = $("entity-linetype").value;
            $("entity-linetype").replaceChildren();
            Object.keys(
                doc.lineTypes || { BYLAYER: [], CONTINUOUS: [] },
            ).forEach((n) => $("entity-linetype").add(new Option(n, n)));
            $("entity-linetype").value = previous || "BYLAYER";
        }
        const current = $("layer").value;
        $("layer").replaceChildren();
        $("layers").replaceChildren();
        if (!doc) return;
        Object.entries(doc.layers).forEach(([name, l]) => {
            const op = document.createElement("option");
            op.value = name;
            op.textContent = name;
            $("layer").append(op);
            const row = document.createElement("label"),
                visible = document.createElement("input"),
                lock = document.createElement("input"),
                color = document.createElement("input"),
                span = document.createElement("span");
            visible.type = lock.type = "checkbox";
            visible.checked = l.visible;
            lock.checked = l.locked;
            visible.title = "레이어 표시";
            lock.title = "레이어 잠금";
            color.type = "color";
            color.value = l.color;
            span.textContent = name;
            visible.disabled = lock.disabled = color.disabled = !canManage;
            visible.onchange = () => {
                if(busy||gesture){visible.checked=l.visible;return;}
                snapshot();
                doc.layersDirty = true;
                l.visible = visible.checked;
                render();
            };
            lock.onchange = () => {
                if(busy||gesture){lock.checked=l.locked;return;}
                snapshot();
                doc.layersDirty = true;
                l.locked = lock.checked;
                render();
            };
            color.onchange = () => {
                if(busy||gesture){color.value=l.color;return;}
                snapshot();
                doc.layersDirty = true;
                l.color = color.value;
                render();
            };
            row.append(visible, lock, color, span);
            $("layers").append(row);
        });
        if (doc.layers[current]) $("layer").value = current;
    }
    async function list() {
        const j = await api("?site_id=" + encodeURIComponent($("sites").value));
        const listCanManage = !!j.canManage;
        $("upload").disabled = !listCanManage || proofMode;
        if (!record) canManage = listCanManage;
        const current = $("drawings").value;
        $("drawings").replaceChildren(new Option("도면 선택", ""));
        (j.drawings || []).forEach((d) =>
            $("drawings").add(
                new Option(d.title || d.original_name || "도면 " + d.id, d.id),
            ),
        );
        $("drawings").value = current;
        if (!$("sites").options.length) {
            $("sites").add(new Option("전체 현장", ""));
            (j.sites || []).forEach((s) =>
                $("sites").add(new Option(s.name || s.label, s.id || s.value)),
            );
            const requested = new URLSearchParams(location.search).get(
                "site_id",
            );
            if (requested) {
                $("sites").value = requested;
                return list();
            }
        }
    }
    async function open(id, discard = true, revision = null) {
        if (!id) return;
        if (discard && !guard()) {
            $("drawings").value = record ? String(record.id) : "";
            return;
        }
        const j = await api(
            "/" +
                encodeURIComponent(id) +
                (revision
                    ? "/history?revision=" + encodeURIComponent(revision)
                    : ""),
        );
        const parsed = C.parse(j.dxf);
        doc = parsed;
        record = j;
        canManage = !!j.canManage && parsed.acadVersion >= 1015 && !proofMode;
        dirty = false;
        undo = [];
        redo = [];
        selected.clear();
        points = [];
        $("dirty").textContent = "개정 " + j.revision;
        $("save").disabled = !canManage;
        $("drawings").value = String(id);
        $("warnings").textContent =
            (j.warnings || []).join(" ") +
            " 미지원/읽기 전용 객체 " +
            doc.entities.filter((e) => !e.editable).length +
            "개 (일부 미표시, 원본 보존). 레이어 설정은 DXF/DWG 저장에 반영됩니다. 정렬 TEXT·MTEXT·폭/특수 폴리선은 읽기 전용입니다. R12는 최신 DXF로 변환한 뒤 편집하세요.";
        layers();
        fit();
        status(j.title + " · " + doc.entities.length + "개 객체");
    }
    async function save() {
        if(gesture){status('드래그를 끝내거나 Esc로 취소한 뒤 저장하세요.');return;}
        if (!record || !dirty || !canManage || busy) return;
        busy = true;
        $("save").disabled = true;
        inspector();
        try {
            const content = C.exportDXF(doc),
                j = await api("/" + record.id, {
                    method: "PUT",
                    body: JSON.stringify({
                        revision: record.revision,
                        dxf: content,
                    }),
                });
            record.revision = j.revision;
            dirty = C.exportDXF(doc) !== content;
            $("dirty").textContent = dirty
                ? "● 저장 후 추가 변경 있음"
                : "개정 " + j.revision + " 저장됨";
            status(
                dirty
                    ? "저장 후 추가된 변경은 다시 저장하세요."
                    : "저장했습니다.",
            );
        } finally {
            busy = false;
            $("save").disabled = !canManage;
            inspector();
        }
    }
    function download() {
        if (!doc) return;
        const a = document.createElement("a"),
            url = URL.createObjectURL(
                new Blob([C.exportDXF(doc)], { type: "application/dxf" }),
            );
        a.href = url;
        a.download = (record?.title || "drawing") + ".dxf";
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    $("dxf").onclick = download;
    $("original").onclick = () => {
        if (record)
            location.href =
                "/admin-api/cad/" + record.id + "/download?format=original";
    };
    $("dwg").onclick = async () => {
        if (!record) return;
        if (dirty) {
            status(
                "DWG를 내려받기 전에 저장하세요. 현재 편집은 DXF로 내려받을 수 있습니다.",
            );
            return;
        }
        try {
            const r = await fetch(
                "/admin-api/cad/" +
                    record.id +
                    "/download?format=dwg&revision=" +
                    record.revision,
                {
                    credentials: "same-origin",
                    headers: { Accept: "application/octet-stream" },
                },
            );
            if (!r.ok) {
                const j = await r.json();
                throw Error(j.message || j.error || "DWG 변환 실패");
            }
            const warnings = r.headers.get("X-CAD-Warnings");
            if (warnings) {
                try {
                    $("warnings").textContent = JSON.parse(
                        new TextDecoder().decode(
                            Uint8Array.from(atob(warnings), (c) =>
                                c.charCodeAt(0),
                            ),
                        ),
                    ).join(" ");
                } catch (_) {
                    $("warnings").textContent =
                        "변환 경고가 있습니다. 원래 CAD에서 도면을 검증하세요.";
                }
            }
            const url = URL.createObjectURL(await r.blob()),
                a = document.createElement("a");
            a.href = url;
            a.download = record.title + ".dwg";
            a.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (e) {
            fail(e);
        }
    };
    $("save").onclick = () => save().catch(fail);
    $("reload").onclick = () => record && open(record.id,true,proofMode?Number(query.get("revision")):null).catch(fail);
    $("drawings").onchange = (e) => open(e.target.value).catch(fail);
    $("sites").onchange = () => list().catch(fail);
    $("upload").onchange = async (e) => {
        const f = e.target.files[0];
        if (!f || !guard()) return;
        if (/\.dwg$/i.test(f.name) && !cap.dwgRead) {
            status(
                "서버의 DWG 읽기 엔진이 준비되지 않았습니다. DXF 파일을 사용하거나 관리자에게 엔진 설정을 요청하세요.",
            );
            e.target.value = "";
            return;
        }
        const fd = new FormData();
        fd.append("file", f);
        fd.append("title", f.name);
        fd.append("site_id", $("sites").value);
        try {
            status("도면 업로드·변환 중…");
            const j = await api("", { method: "POST", body: fd });
            await list();
            await open(j.id, false);
        } catch (err) {
            fail(err);
        } finally {
            e.target.value = "";
        }
    };
    $("history").onclick = async () => {
        if (!record) return;
        try {
            const j = await api("/" + record.id + "/history");
            $("revisions").replaceChildren();
            (j.revisions || j.history || j || []).forEach((v) => {
                const a = document.createElement("a");
                a.textContent =
                    "개정 " + v.revision + " · " + (v.created_at || "");
                a.href =
                    "/admin-api/cad/" +
                    record.id +
                    "/download?format=dxf&revision=" +
                    v.revision;
                const preview = document.createElement("button");
                preview.textContent = "개정 " + v.revision + " 읽기 전용 보기";
                preview.onclick = () =>
                    open(record.id, true, v.revision).catch(fail);
                $("revisions").append(preview, a);
            });
        } catch (e) {
            fail(e);
        }
    };
    $("coordinate").onclick = () => {
        const p = { x: Number($("x").value), y: Number($("y").value) };
        if (Number.isFinite(p.x) && Number.isFinite(p.y)) point(p);
    };
    $("fit").onclick = fit;
    $("undo").onclick = () => {
        if(busy||gesture)return;
        if (undo.length) {
            redo.push(C.clone(doc));
            doc = undo.pop();
            selected.clear();
            mark();
            layers();
            render();
        }
    };
    $("redo").onclick = () => {
        if(busy||gesture)return;
        if (redo.length) {
            undo.push(C.clone(doc));
            doc = redo.pop();
            selected.clear();
            mark();
            layers();
            render();
        }
    };
    $("apply-text").onclick = () => {
        if (!canManage || !doc || busy || gesture) return;
        const es = doc.entities.filter(
                (e) =>
                    selected.has(e.id) &&
                    editable(e) &&
                    ["TEXT", "MTEXT"].includes(e.type),
            ),
            h = Number($("height").value);
        if(!textEdits.size){status('문자 내용·크기·색 중 변경할 값을 입력하세요.');return;}
        if (es.length && (!textEdits.has('height')||(Number.isFinite(h)&&h>0))) {
            snapshot();
            es.forEach((e) => {
                if(textEdits.has('text'))e.text=$('text').value;
                if(textEdits.has('height'))e.height=h;
                if(textEdits.has('text-color'))e.color=parseInt($('text-color').value.slice(1),16);
                e.dirty = true;
            });
            render();
        }
    };
    $("new-layer").onclick = () => {
        if (!doc || !canManage || busy || gesture) return;
        const name = prompt("레이어 이름 (DXF 저장 시 새 객체에 적용)");
        if (
            !name ||
            ["__proto__", "constructor", "prototype"].includes(name) ||
            /[<>/\\":;?*|=\r\n]/.test(name) ||
            doc.layers[name]
        )
            return;
        snapshot();
        doc.layersDirty = true;
        doc.layers[name] = { color: "#e2e8f0", visible: true, locked: false };
        layers();
        $("layer").value = name;
    };
    ["grid", "spacing", "ortho", "snap"].forEach(
        (id) => ($(id).onchange = render),
    );
    document.addEventListener("keydown", (e) => {
        if(e.key==='Escape'&&gesture){e.preventDefault();cancelGesture();return;}
        if(gesture)return;
        if (e.ctrlKey || e.metaKey) {
            if (e.key.toLowerCase() === "s") {
                e.preventDefault();
                save().catch(fail);
            }
            if (e.key.toLowerCase() === "z") {
                e.preventDefault();
                $(e.shiftKey ? "redo" : "undo").click();
            }
            if (e.key.toLowerCase() === "y") {
                e.preventDefault();
                $("redo").click();
            }
            return;
        }
        if (["INPUT", "SELECT", "TEXTAREA"].includes(e.target.tagName)) return;
        if (e.key === "Escape") {
            points = [];
            setTool("select");
        }
        if (e.key === "Enter") finishPolyline();
        if (e.key === "Delete") setTool("delete");
        if (e.key.toLowerCase() === "f") fit();
    });
    function chosen() {
        return doc
            ? Array.from(selected)
                  .map((id) => doc.entities.find((e) => e.id === id))
                  .filter((e) => e && editable(e))
            : [];
    }
    function operation(fn) {
        if (!doc || !canManage || busy || gesture) return;
        try {
            const es = chosen();
            if (!es.length) throw Error("편집 가능한 객체를 선택하세요.");
            const result = fn(es);
            snapshot();
            result();
            render();
        } catch (e) {
            fail(e);
        }
    }
    $("offset").onclick = () =>
        operation((es) => {
            const added = es.map((e) =>
                C.offset(e, Number($("offset-distance").value)),
            );
            return () => doc.entities.push(...added);
        });
    for (const kind of ["trim", "extend"])
        $(kind).onclick = () =>
            operation((es) => {
                if (es.length !== 2)
                    throw Error("대상 선 → 기준 선 순서로 2개 선택하세요.");
                const n = C.trim(
                    es[0],
                    es[1],
                    { x: Number($("x").value), y: Number($("y").value) },
                    kind === "extend",
                );
                return () => {
                    doc.entities[
                        doc.entities.findIndex((e) => e.id === es[0].id)
                    ] = n;
                };
            });
    $("array").onclick = () =>
        operation((es) => {
            const added = C.array(
                es,
                Number($("rows").value),
                Number($("cols").value),
                Number($("array-x").value),
                Number($("array-y").value),
            );
            return () => doc.entities.push(...added);
        });
    $("assign-layer").onclick = () =>
        operation(
            (es) => () =>
                es.forEach((e) => {
                    e.layer = $("layer").value;
                    e.dirty = true;
                }),
        );
    $("apply-style").onclick = () =>
        operation((selectedEntities) => {
            const es=selectedEntities.filter(e=>!['TEXT','MTEXT'].includes(e.type));
            if(!es.length)throw Error('선 또는 도형을 선택하세요.');
            if(!styleEdits.size)throw Error("선·도형의 색 또는 굵기를 먼저 변경하세요.");
            const w = Number($("entity-width").value);
            if (styleEdits.has("entity-width")&&(!Number.isFinite(w)||(w<0&&![-1,-2,-3].includes(w))||w>2.11))
                throw Error("선 굵기는 0~2.11mm입니다.");
            return () =>
                es.forEach((e) => {
                    if(styleEdits.has("entity-color"))e.color=parseInt($("entity-color").value.slice(1),16);
                    if(styleEdits.has("entity-linetype"))e.lineType=$("entity-linetype").value;
                    const weights = [
                        0, 5, 9, 13, 15, 18, 20, 25, 30, 35, 40, 50, 53, 60, 70,
                        80, 90, 100, 106, 120, 140, 158, 200, 211,
                    ];
                    if(styleEdits.has("entity-width"))e.lineWeight = w<0?w:weights.reduce(
                        (best, n) =>
                            Math.abs(n - w * 100) < Math.abs(best - w * 100)
                                ? n
                                : best,
                        25,
                    );
                    e.dirty = true;
                });
        });
    $("rename-layer").onclick = () => {
        if (!doc || !canManage || busy || gesture) return;
        const old = $("layer").value;
        const name = prompt("새 레이어 이름", old);
        if (!name) return;
        try {
            const renamed = C.renameLayer(doc, old, name);
            snapshot();
            doc = renamed;
            layers();
            $("layer").value = name;
            render();
        } catch (error) {
            fail(error);
        }
    };
    for(const id of ['entity-color','entity-width','entity-linetype'])for(const event of ['input','change'])$(id).addEventListener(event,()=>styleEdits.add(id));
    for(const id of ['text','height','text-color'])for(const event of ['input','change'])$(id).addEventListener(event,()=>textEdits.add(id));
    window.addEventListener("beforeunload", (e) => {
        if (dirty) {
            e.preventDefault();
            e.returnValue = "";
        }
    });
    new ResizeObserver(render).observe($("viewport"));
    Promise.all([
        inClaim ? api('/claim-context?lineId='+claimContext.lineId).then(context=>{
            claimValid=!!context.canManage&&!context.recordId&&Number(context.line.contractId)===claimContext.contractId&&Number(context.line.siteId)===claimContext.siteId&&Number(context.draftRevision)===claimContext.draftRevision;
            $('claim-attach').disabled=!claimValid;
            $('claim-context-label').textContent='기성 · '+context.line.description+' · 현장 고정';
            if(!claimValid)$('claim-result').textContent='기성 초안 또는 권한이 변경되었습니다. 기성으로 돌아가 최신 초안을 확인하세요.';
        }) : Promise.resolve(),
        api("/capabilities").then((j) => {
            cap = j;
            $("capability").textContent =
                j.message ||
                "DWG 읽기: " +
                    (j.dwgRead ? "가능" : "엔진 설정 필요") +
                    " / 저장: " +
                    (j.dwgWrite ? "가능" : "엔진 설정 필요");
            $("dwg").disabled = !j.dwgWrite;
        }),
        list().then(()=>{const drawingId=Number(query.get('drawing_id')),revision=Number(query.get('revision'));if(Number.isSafeInteger(drawingId)&&drawingId>0)return open(drawingId,false,Number.isSafeInteger(revision)&&revision>0?revision:null);}),
    ]).catch(fail);
})();
