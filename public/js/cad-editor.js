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
        canAnnotate = false,
        cap = {},
        busy = false;
    let activeIO=null,ioSequence=0,listSequence=0,uploadAllowed=false,capabilitiesReady=false;
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
        if(gesture||markup?.pending()){status('표시를 완성하거나 Esc로 취소한 뒤 연결하세요.');return;}
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
    let gesture = null, inspectorKey = "",hoverPoint=null,snapMark=null,textDraft=null;
    let measurements=[];
    let markup=null, printing=false, cadBaseline="";
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
        const message=e.message||String(e);status(message);
        if(activeIO||!doc)canvasState('error','도면을 열지 못했습니다',message+(doc?' 기존 도면과 저장하지 않은 변경은 유지됩니다.':''));
    }
    function canvasState(kind,title,message){
        const el=$('drawing-state');el.hidden=false;el.dataset.state=kind;
        $('drawing-state-title').textContent=title;$('drawing-state-message').textContent=message;
        $('drawing-state-dismiss').hidden=!doc||kind==='loading';
        $('viewport').setAttribute('aria-busy',String(kind==='loading'));
    }
    function ioControls(){
        $('sites').disabled=busy||inClaim||proofMode;$('drawings').disabled=busy||proofMode;
        $('upload').disabled=busy||!uploadAllowed||proofMode||!capabilitiesReady;
        $('save').disabled=busy||!canAnnotate||!record;$('reload').disabled=busy||!record;
        $('history').disabled=busy||proofMode||!record;$('print-markups').disabled=busy||!record?.canExport;inspector();markup?.refresh();
    }
    function startIO(kind,title){
        if(busy||gesture)return null;
        const token=++ioSequence;activeIO={token,kind};busy=true;ioControls();
        canvasState('loading',title,'파일 크기와 도면 복잡도에 따라 시간이 걸릴 수 있습니다. 완료될 때까지 기다려 주세요.');
        return token;
    }
    function finishIO(token){
        if(activeIO?.token!==token)return;activeIO=null;busy=false;ioControls();$('viewport').setAttribute('aria-busy','false');
    }
    $('drawing-state-dismiss').onclick=()=>{$('drawing-state').hidden=true;};
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
        const j = await r.json().catch(()=>null);
        if(!j)throw Error(r.status===413?'파일이 서버 업로드 한도를 넘었습니다. 파일 크기를 줄이거나 관리자에게 문의하세요.':r.status===419?'로그인 세션이 만료되었습니다. 편집 중인 도면은 DXF로 보관한 뒤 다시 로그인하세요.':'서버 응답을 읽지 못했습니다. 잠시 후 다시 시도하세요. (HTTP '+r.status+')');
        if (!r.ok||j.success===false)
            throw Error(
                r.status === 409
                    ? (path.endsWith("/claim-draft") ? "기성 초안이 다른 화면에서 변경되었습니다. CAD 편집은 유지됩니다. 기성으로 돌아가 최신 초안을 확인한 뒤 다시 연결하세요." : "다른 사용자가 저장했습니다. 현재 편집과 표시는 이 화면에 유지됩니다. 다시 열면 저장하지 않은 변경이 사라집니다. 현재 화면을 PDF로 보관하고 변경 내용을 확인하세요.")
                    : safeApiMessage(j,r.status),
            );
        return j.data || j;
    }
    function safeApiMessage(j,statusCode){
        const messages=[];
        for(const value of [j.message,j.error])if(typeof value==='string'&&!/<\/?(?:html|body|script|pre)\b/i.test(value)&&value.length<2000)messages.push(value);
        if(j.errors&&typeof j.errors==='object')for(const values of Object.values(j.errors))for(const value of Array.isArray(values)?values:[values])if(typeof value==='string'&&!/<[^>]+>/.test(value)&&value.length<500)messages.push(value);
        return [...new Set(messages)].join(' ')||'요청을 처리하지 못했습니다. (HTTP '+statusCode+')';
    }
    function guard() {
        if(markup?.pending()){status('표시를 완성하거나 Esc로 취소하세요.');return false;}
        if($('text-dialog').open){status('문자 입력창에서 확인하거나 취소한 뒤 진행하세요.');return false;}

        if(gesture){status("드래그를 끝내거나 Esc로 취소하세요.");return false;}
        if (busy) {
            status("저장이 끝날 때까지 기다려 주세요.");
            return false;
        }
        return (
            !dirty ||
            confirm(
                "저장하지 않은 변경을 버릴까요? 취소하면 편집을 계속하거나 저장할 수 있습니다.",
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
        if(e?.transient)return true;
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
        snapMark=null;
        if($('snap').checked||['measure','dimension'].includes(tool)){
            let best=12/scale;for(const e of entities()){if(exclude.has(e.id))continue;for(const q of C.snapPoints(e)){const distance=C.distance(p,q);if(distance<best){best=distance;snapMark={...q};}}}
            if(snapMark)p={...snapMark};
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
        $('selection-properties').textContent=first?(es.length===1?(first.type==='ERP_DIMENSION'?'거리 치수':first.type)+' · '+(editable(first)?'드래그 이동 / 손잡이 크기조절':'읽기 전용 또는 레이어 잠금'):es.length+'개 선택 · 첫 객체의 속성 표시'):'객체를 선택하면 속성이 표시됩니다. 새 객체 기본값은 왼쪽에서 설정하세요.';
        if(first){$('entity-color').value=entityColor(first);$('entity-width').value=first.lineWeight>=0?first.lineWeight/100:(first.lineWeight??-1);$('entity-linetype').value=first.lineType||'BYLAYER';}
        const text=es.find(e=>e.type==='TEXT'||e.type==='MTEXT');
        $('edit-text-dialog').disabled=!text||busy;
        if(text){$('text').value=text.text;$('height').value=text.height;$('text-color').value=entityColor(text);}
        $('text-properties').disabled=!text||!canManage||busy||!es.some(e=>['TEXT','MTEXT'].includes(e.type)&&editable(e));
        const shapeEnabled=canManage&&!busy&&es.some(e=>!['TEXT','MTEXT'].includes(e.type)&&editable(e));
        $('apply-style').disabled=!shapeEnabled;
        for(const id of ['entity-color','entity-width','entity-linetype'])$(id).disabled=!shapeEnabled;
    }
    function hitEntity(p){
        for(const e of [...entities(),...measurements].slice().reverse()){
            if(e.type==='ERP_DIMENSION'){for(const part of C.dimensionParts(e)){if(part.type==='LINE'&&C.segmentDistance(p,part.p,part.q)<=10/scale)return e;if(part.type==='TEXT'&&C.geometry(part).some(q=>C.distance(p,q)<=10/scale))return e;}continue;}
            if(['TEXT','MTEXT'].includes(e.type)){
                const box=C.textBox(e),dx=p.x-e.p.x,dy=p.y-e.p.y,x=dx*box.u.x+dy*box.u.y,y=dx*box.v.x+dy*box.v.y;
                if(x>=-5/scale&&x<=box.width+5/scale&&y>=(e.type==='MTEXT'?-box.height:-e.height*.2)-5/scale&&y<=(e.type==='MTEXT'?0:e.height)+5/scale)return e;
            }
            if(C.contains(e,p))return e;
            const ps=C.geometry(e);if(ps.length===1&&C.distance(p,ps[0])<=10/scale)return e;
            for(let i=1;i<ps.length;i++)if(C.segmentDistance(p,ps[i-1],ps[i])<=10/scale)return e;
        }return null;
    }
    function gripAt(p){
        if(!doc||busy)return null;
        let result=null,best=8/scale;for(const e of [...doc.entities,...measurements].filter(e=>selected.has(e.id)&&editable(e)&&(canManage||e.transient)))for(const grip of C.grips(e)){const d=C.distance(p,grip.point);if(d<best){best=d;result={entity:e,key:grip.key};}}
        return result;

    }
    function cancelGesture(){if(!gesture)return;const id=gesture.pointerId;gesture=null;if(canvas.hasPointerCapture(id))canvas.releasePointerCapture(id);render();}
    function standardWeight(value){const weights=[0,5,9,13,15,18,20,25,30,35,40,50,53,60,70,80,90,100,106,120,140,158,200,211];return weights.reduce((best,n)=>Math.abs(n-value*100)<Math.abs(best-value*100)?n:best,25);}


    function openTextEditor(entity=null,box=null){
        if(busy||gesture||(!entity&&!canManage))return;
        textDraft={entityId:entity?.id||null,p:box?.p||entity?.p,layer:entity?.layer||$('layer').value,angle:entity?.angle??(Number($('angle').value)||0)};
        const allowed=entity?canManage&&editable(entity):!doc.layers[textDraft.layer]?.locked;
        $('text-dialog-title').textContent=entity?'문자 편집':'새 문자 입력';
        $('text-dialog-content').value=entity?.text||'';
        $('text-dialog-height').value=entity?.height||Number($('default-text-height').value)||2.5;
        $('text-dialog-color').value=entity?entityColor(entity):$('default-text-color').value;
        $('text-dialog-width').value=box?.width??entity?.width??0;
        $('text-dialog-error').textContent=allowed?'':'이 문자는 읽기 전용입니다. 복잡한 서식 또는 레이어·접근 권한을 원래 CAD에서 확인하세요.';
        for(const id of ['text-dialog-content','text-dialog-height','text-dialog-width'])$(id).readOnly=!allowed||(id==='text-dialog-width'&&entity?.type==='TEXT');
        $('text-dialog-color').disabled=!allowed;$('text-dialog-confirm').disabled=!allowed;
        $('text-dialog').showModal();$('text-dialog-content').focus();
    }
    function commitText(){
        if(!textDraft||busy)return;
        const original=doc.entities.find(e=>e.id===textDraft.entityId),content=$('text-dialog-content').value,height=Number($('text-dialog-height').value),width=Number($('text-dialog-width').value),color=parseInt($('text-dialog-color').value.slice(1),16);
        if(!canManage||(original&&!editable(original))||doc.layers[textDraft.layer]?.locked)return;
        if(!content.trim()||!Number.isFinite(height)||height<=0||!Number.isFinite(width)||width<0){$('text-dialog-error').textContent='문자 내용과 양수 크기, 0 이상 박스 폭을 입력하세요.';return;}
        if(original?.type==='TEXT'&&content.includes('\n')){$('text-dialog-error').textContent='기존 한 줄 문자는 줄바꿈을 지원하지 않습니다. 여러 줄 문자는 새 문자로 작성하세요.';return;}
        snapshot();
        let entity;
        if(original){entity={...C.clone(original),text:content,height,color,dirty:true};if(entity.type==='MTEXT')entity.width=width;doc.entities[doc.entities.indexOf(original)]=entity;}
        else {entity=C.newEntity('MTEXT',{p:textDraft.p,text:content,height,width,color,angle:textDraft.angle,plainText:true},textDraft.layer);doc.entities.push(entity);}
        selected.clear();selected.add(entity.id);$('text-dialog').close();textDraft=null;setTool('select');render();
    }
    $('text-dialog-confirm').onclick=commitText;
    $('text-dialog-cancel').onclick=()=>{$('text-dialog').close();textDraft=null;};
    $('text-dialog').addEventListener('close',()=>{textDraft=null;render();});
    $('edit-text-dialog').onclick=()=>{const entity=doc?.entities.find(e=>selected.has(e.id)&&['TEXT','MTEXT'].includes(e.type));if(entity)openTextEditor(entity);};
    function annotationPreview(){
        if(!['measure','dimension'].includes(tool)||!points.length||!hoverPoint)return null;
        try{const a=points[0],b=points[1]||hoverPoint,p=points.length>1?hoverPoint:{x:(a.x+b.x)/2,y:(a.y+b.y)/2};return C.newDimension(a,b,p,{mode:$('dimension-mode').value,height:Number($('default-text-height').value)||2.5,color:0x67e8f9,layer:$('layer').value});}catch(_){return null;}
    }

    function render() {
        const r = canvas.getBoundingClientRect(),
            dpr = printing ? 3 : window.devicePixelRatio || 1;
        canvas.width = r.width * dpr;
        canvas.height = r.height * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, r.width, r.height);
        if(printing){ctx.fillStyle="#ffffff";ctx.fillRect(0,0,r.width,r.height);}
        const spacing = Number($("spacing").value);
        if (!printing && $("grid").checked && spacing > 0 && spacing * scale > 8) {
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
        const annotation=annotationPreview();
        const shown=[...entities(),...measurements.map(e=>gesture?.preview?.get(e.id)||e),...(annotation?[annotation]:[])];
        for (const e of shown.flatMap(e=>e.type==='ERP_DIMENSION'?C.dimensionParts(e).map(part=>({...part,id:e.id})):e)) {
            const ps = ['TEXT','MTEXT'].includes(e.type)?[]:C.geometry(e);
            if(['TEXT','MTEXT'].includes(e.type))ps.push(e.p);
            if (!ps.length) continue;
            ctx.strokeStyle = e.color !== undefined && e.color !== null
                  ? "#" + e.color.toString(16).padStart(6, "0")
                  : e.aci > 0 && e.aci < 256
                    ? C.aci(e.aci)
                    : doc.layers[e.layer]?.color || "#e2e8f0";
            if(printing)ctx.strokeStyle="#475569";
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
            if(!["TEXT","MTEXT"].includes(e.type))ctx.stroke();
            if (["TEXT", "MTEXT"].includes(e.type)) {
                const p = screen(e.p);
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate((-(e.angle || 0) * Math.PI) / 180);
                ctx.font = Math.max(1, e.height * scale) + "px sans-serif";
                ctx.textBaseline=e.type==='MTEXT'?'top':'alphabetic';
                C.textLines(e).forEach((line,i)=>ctx.fillText(line,0,i*e.height*scale*1.2));
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
            const a=screen(gesture.start),b=screen(gesture.end);if(['rectangle','text'].includes(gesture.tool))ctx.rect(a.x,a.y,b.x-a.x,b.y-a.y);else {ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);}ctx.stroke();ctx.setLineDash([]);
        }
        if(!printing&&tool==='select'&&!busy)for(const e of [...entities(),...measurements.map(e=>gesture?.preview?.get(e.id)||e)].filter(e=>selected.has(e.id)&&editable(e)&&(canManage||e.transient))){for(const grip of C.grips(e)){const p=screen(grip.point);ctx.fillStyle='#101827';ctx.strokeStyle='#67e8f9';ctx.lineWidth=1.5;ctx.fillRect(p.x-4,p.y-4,8,8);ctx.strokeRect(p.x-4,p.y-4,8,8);}}
        if(!printing&&snapMark){const marker=screen(snapMark);ctx.strokeStyle='#ffb347';ctx.lineWidth=2;ctx.setLineDash([]);ctx.strokeRect(marker.x-6,marker.y-6,12,12);ctx.beginPath();ctx.moveTo(marker.x-10,marker.y);ctx.lineTo(marker.x+10,marker.y);ctx.moveTo(marker.x,marker.y-10);ctx.lineTo(marker.x,marker.y+10);ctx.stroke();}
        canvas.dataset.snapActive=String(!!snapMark);if(snapMark){canvas.dataset.snapX=snapMark.x;canvas.dataset.snapY=snapMark.y;}
        const measured=annotation||measurements.find(e=>selected.has(e.id));
        $('annotation-readout').textContent=measured?'거리 '+C.dimensionLayout(measured).label+' 도면 단위 · '+(points.length===2?'치수 위치를 지정하세요':measured.transient?'화면 측정 · 저장되지 않음':'끝점/꼭지점 자동 스냅'):'';
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
                dimension: "시작점 → 끝점 → 치수선 위치 클릭",
                measure: "시작점 → 끝점 → 화면 표시 위치 클릭 (저장하지 않음)",
                text: "클릭 또는 박스 드래그 후 문자 입력",
                rotate: "각도 입력 후 기준점 지정",
                scale: "배율 입력 후 기준점 지정",
                mirror: "수직 대칭축 위 점 지정",
                move: "기준점 → 목적점",
                copy: "기준점 → 목적점",
            }[tool] || "점을 순서대로 지정 · Esc 취소");
        markup?.render(ctx,{paper:printing});
    }
    function fit() {
        if(gesture||pan||markup?.pending()){status('표시 또는 화면 이동을 끝낸 뒤 전체 보기를 사용하세요.');return;}
        if (!doc) return;
        const b = C.bounds(entities()),
            r = canvas.getBoundingClientRect();
        for(const item of doc.markups||[]){const m=window.CADMarkup.bounds(item);b.minX=Math.min(b.minX,m.minX);b.minY=Math.min(b.minY,m.minY);b.maxX=Math.max(b.maxX,m.maxX);b.maxY=Math.max(b.maxY,m.maxY);}
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
            measurements=measurements.filter(e=>!selected.has(e.id));
            if (!canManage){selected.clear();tool='select';render();return;}
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
        if(['dimension','measure'].includes(tool)){
            if(tool==='dimension'&&(!canManage||doc.layers[$('layer').value]?.locked)){status('읽기 전용 또는 레이어 잠금입니다.');return;}
            points.push(p);hoverPoint=p;
            if(points.length===3){try{const e=C.newDimension(points[0],points[1],points[2],{mode:$('dimension-mode').value,height:Number($('default-text-height').value)||2.5,color:parseInt($('default-color').value.slice(1),16),layer:$('layer').value});selected.clear();selected.add(e.id);if(tool==='measure'){e.transient=true;measurements.push(e);}else{snapshot();doc.entities.push(e);}points=[];setTool('select');}catch(error){points.pop();status(error.message);}}
            render();return;
        }
        if(tool==='text'){openTextEditor(null,{p,width:0});return;}
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
        const angle=Number($('angle').value),factor=Number($('factor').value);
        if((tool==='rotate'&&!Number.isFinite(angle))||(tool==='scale'&&(!Number.isFinite(factor)||factor<=0))){points=[];status('각도·배율 값을 확인하세요.');render();return;}
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
                    if(n.type==='ERP_DIMENSION'){n.groupId=C.uid();delete n.rawParts;}
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
        if(['line','rectangle'].includes(tool)){selected.clear();selected.add(doc.entities.at(-1).id);setTool('select');}
        render();
    }
    canvas.onpointerdown = e => {
        if(gesture||pan)return;canvas.focus();
        if(e.button===1||e.button===2){e.preventDefault();pan={pointerId:e.pointerId,x:e.clientX,y:e.clientY,ox:origin.x,oy:origin.y};canvas.setPointerCapture(e.pointerId);return;}
        if(e.button!==0||!doc||busy||$('text-dialog').open)return;
        const p=world(e);
        if(tool==='select'){
            const grip=!e.shiftKey&&gripAt(p),hit=grip?.entity||hitEntity(p);
            if(!grip){if(e.shiftKey){if(hit){selected.has(hit.id)?selected.delete(hit.id):selected.add(hit.id);}render();return;}
                if(!hit||!selected.has(hit.id))selected.clear();if(hit)selected.add(hit.id);}
            render();if(!hit||(!canManage&&!hit.transient)||!editable(hit))return;
            gesture={kind:grip?'resize':'move',key:grip?.key,entity:hit,start:p,end:p,pointerId:e.pointerId,sx:e.clientX,sy:e.clientY,moved:false,originals:grip?[C.clone(hit)]:[...doc.entities,...measurements].filter(e=>selected.has(e.id)&&editable(e)&&(canManage||e.transient)).map(C.clone),preview:new Map()};
        }else if(['line','rectangle','text'].includes(tool)&&!points.length){
            if(!canManage||doc.layers[$('layer').value]?.locked){status('읽기 전용 또는 작업 레이어 잠금입니다.');return;}
            gesture={kind:'draw',tool,start:snap(p),end:snap(p),pointerId:e.pointerId,sx:e.clientX,sy:e.clientY,moved:false};
        }else {point(snap(p),e.shiftKey);return;}
        canvas.setPointerCapture(e.pointerId);e.preventDefault();
    };
    canvas.onpointermove=e=>{
        if(pan&&pan.pointerId===e.pointerId){origin={x:pan.ox+e.clientX-pan.x,y:pan.oy+e.clientY-pan.y};render();return;}
        const excluded=new Set(gesture?.originals?.map(e=>e.id)||[]),p=snap(world(e),excluded);hoverPoint=p;
        $('x').value=p.x.toFixed(4);$('y').value=p.y.toFixed(4);
        if(!gesture||gesture.pointerId!==e.pointerId){if(['measure','dimension'].includes(tool))render();canvas.style.cursor=tool==='select'?(gripAt(world(e))?'nwse-resize':hitEntity(world(e))?'move':'default'):'crosshair';return;}
        if((!canManage&&!gesture.originals?.every(e=>e.transient))||busy){cancelGesture();return;}
        if(Math.hypot(e.clientX-gesture.sx,e.clientY-gesture.sy)<4&&!gesture.moved)return;
        gesture.moved=true;gesture.end=p;
        if(gesture.kind!=='draw'){
            try{
                if(gesture.originals.some(e=>!editable([...doc.entities,...measurements].find(n=>n.id===e.id)))){cancelGesture();return;}
                const preview=gesture.originals.map(e=>gesture.kind==='resize'?C.resize(e,gesture.key,p):C.transform(e,{dx:p.x-gesture.start.x,dy:p.y-gesture.start.y}));
                gesture.preview=new Map(preview.map(e=>[e.id,e]));gesture.invalid=false;
            }catch(error){gesture.invalid=true;gesture.preview=new Map();status(error.message);}
        }render();
    };
    canvas.onpointerup=e=>{
        if(pan?.pointerId===e.pointerId){pan=null;if(canvas.hasPointerCapture(e.pointerId))canvas.releasePointerCapture(e.pointerId);return;}
        if(!gesture||gesture.pointerId!==e.pointerId)return;
        const finished=gesture;gesture=null;if(canvas.hasPointerCapture(e.pointerId))canvas.releasePointerCapture(e.pointerId);
        if((!canManage&&!finished.originals?.every(e=>e.transient))||busy||finished.invalid){render();return;}
        if(finished.kind==='draw'){
            if(finished.tool==='text'){const a=finished.start,b=finished.end;openTextEditor(null,{p:finished.moved?{x:Math.min(a.x,b.x),y:Math.max(a.y,b.y)}:a,width:finished.moved?Math.abs(a.x-b.x):0});render();return;}
            if(!finished.moved){point(finished.start);return;}
            if(C.distance(finished.start,finished.end)<1e-10||(finished.tool==='rectangle'&&(Math.abs(finished.start.x-finished.end.x)<1e-10||Math.abs(finished.start.y-finished.end.y)<1e-10))){render();return;}
            point(finished.start);point(finished.end);
        }else if(finished.moved&&finished.preview.size){const changed=finished.originals.some(e=>JSON.stringify(C.geometry(e))!==JSON.stringify(C.geometry(finished.preview.get(e.id)||e)));if(changed){if(!finished.originals.every(e=>e.transient)){snapshot();doc.entities=doc.entities.map(e=>finished.preview.get(e.id)||e);}measurements=measurements.map(e=>finished.preview.get(e.id)||e);}render();}
    };
    canvas.onpointercancel=e=>{if(pan?.pointerId===e.pointerId)pan=null;if(gesture?.pointerId===e.pointerId)cancelGesture();};
    canvas.onlostpointercapture=e=>{if(pan?.pointerId===e.pointerId)pan=null;if(gesture?.pointerId===e.pointerId)cancelGesture();};
    canvas.onpointerleave=()=>{if(!gesture){snapMark=null;hoverPoint=null;render();}};
    canvas.ondblclick=e=>{if(tool!=='select'||busy||gesture)return;const hit=hitEntity(world(e));if(hit&&['TEXT','MTEXT'].includes(hit.type))openTextEditor(hit);};
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
        const site=$('sites').value,request=++listSequence;
        const j=await api('?site_id='+encodeURIComponent(site));
        if(request!==listSequence||$('sites').value!==site)return;
        const listCanManage=!!j.canManage;uploadAllowed=listCanManage;ioControls();
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
    async function loadDrawing(id,revision,token){
        const j = await api(
            "/" +
                encodeURIComponent(id) +
                (revision
                    ? "/history?revision=" + encodeURIComponent(revision)
                    : ""),
        );
        const parsed = C.parse(j.dxf);
        if(activeIO?.token!==token)return;
        doc = parsed;
        doc.markups = C.clone(j.markups || []);
        cadBaseline = C.exportDXF(parsed);
        markup?.reset();
        record = j;
        canManage = !!j.canManage && parsed.acadVersion >= 1015 && !proofMode;
        canAnnotate = !!j.canManage && !proofMode;
        dirty = false;
        undo = [];
        redo = [];
        selected.clear();measurements=[];hoverPoint=null;snapMark=null;
        points = [];
        $("dirty").textContent = "개정 " + j.revision;
        ioControls();
        if(!Array.from($("drawings").options).some(option=>option.value===String(id)))$("drawings").add(new Option(j.title||("도면 "+id),String(id)));
        $("drawings").value = String(id);
        $("warnings").textContent =
            (j.warnings || []).join(" ") +
            " 미지원/읽기 전용 객체 " +
            doc.entities.filter((e) => !e.editable).length +
            "개 (일부 미표시, 원본 보존). 레이어 설정은 DXF/DWG 저장에 반영됩니다. 특수 서식 문자·폭/곡선 폴리선은 읽기 전용입니다. R12 원본 도형은 최신 DXF로 변환한 뒤 편집하세요. 도면 위 표시는 별도로 저장됩니다.";
        const unsupported=doc.entities.filter(e=>!e.editable).length;
        $('drawing-fidelity').hidden=!unsupported;
        $('drawing-fidelity').textContent='읽기 전용 / 미지원 '+unsupported+'개 · 표시 누락 가능';
        $('drawing-fidelity').onclick=()=>{document.querySelector('.drawing-info').open=true;document.querySelector('.drawing-info').scrollIntoView({block:'nearest'});};
        layers();
        fit();
        status(j.title + " · " + doc.entities.length + "개 객체");
        if(doc.markups.length||entities().some(e=>C.geometry(e).length))$('drawing-state').hidden=true;
        else canvasState('notice','도면은 열렸지만 표시 가능한 2D 객체가 없습니다','원본 객체 '+doc.entities.length+'개가 보존되어 있습니다. 미지원 객체·외부 참조·숨김 레이어가 있는지 원래 CAD에서 확인하세요.');
    }
    async function open(id,discard=true,revision=null){
        if(!id){if(record)$('drawings').value=String(record.id);return;}
        if(busy||(discard&&!guard())){$('drawings').value=record?String(record.id):'';return;}
        const token=startIO('open','도면을 불러오는 중…');if(!token)return;
        try{await loadDrawing(id,revision,token);}
        catch(error){$('drawings').value=record?String(record.id):'';fail(error);}
        finally{finishIO(token);}
    }
    async function save() {
        if($('text-dialog').open){status('문자 입력창을 확인하거나 취소하세요.');return;}
        if(gesture||markup?.pending()){status('표시를 완성하거나 Esc로 취소한 뒤 저장하세요.');return;}
        if (!record || !dirty || !canAnnotate || busy) return;
        busy = true;
        $("save").disabled = true;
        markup?.refresh();
        inspector();
        try {
            const content = dxfContent(), markupContent=JSON.stringify(doc.markups || []),
                j = await api("/" + record.id, {
                    method: "PUT",
                    body: JSON.stringify({
                        revision: record.revision,
                        dxf: content,
                        markups: JSON.parse(markupContent),
                    }),
                });
            record.revision = j.revision;
            record.dxf = content;
            cadBaseline = C.exportDXF(doc);
            dirty = JSON.stringify(doc.markups || []) !== markupContent;
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
            $("save").disabled = !canAnnotate;
            markup?.refresh();
            inspector();
        }
    }
    function dxfContent() {
        const exported=C.exportDXF(doc);
        return exported===cadBaseline ? record.dxf : exported;
    }
    function download() {
        if (!doc) return;
        const a = document.createElement("a"),
            url = URL.createObjectURL(
                new Blob([dxfContent()], { type: "application/dxf" }),
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
    $('upload').onchange=async(e)=>{
        let token=null;
        try{
            const file=e.target.files[0];if(!file)return;
            if(!guard())return;
            const site=$('sites').value;
            if(!site)throw Error('도면을 등록할 현장을 먼저 선택하세요. 위의 현장 목록에서 현장을 선택한 뒤 다시 파일을 올려 주세요.');
            if(/\.dwg$/i.test(file.name)&&!cap.dwgRead)throw Error('서버의 DWG 읽기 엔진이 준비되지 않았습니다. 관리자에게 DWG 엔진 상태를 확인해 주세요.');
            token=startIO('upload',file.name+' · 업로드 및 변환 중…');if(!token)return;
            const form=new FormData();form.append('file',file);form.append('title',file.name);form.append('site_id',site);
            const created=await api('',{method:'POST',body:form});
            await loadDrawing(created.id,null,token);
            finishIO(token);token=null;
            list().catch(error=>status('도면은 열렸지만 목록 새로고침에 실패했습니다. '+error.message));
        }catch(error){
            if(!activeIO||activeIO.token===token){fail(error);canvasState('error','도면 업로드 또는 변환에 실패했습니다',error.message+(doc?' 기존 도면과 저장하지 않은 변경은 유지됩니다.':''));}
        }finally{
            e.target.value='';if(token)finishIO(token);
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
        if(busy||gesture||markup?.pending())return;
        if (undo.length) {
            redo.push(C.clone(doc));
            doc = undo.pop();
            markup?.changed();
            selected.clear();
            mark();
            layers();
            render();
        }
    };
    $("redo").onclick = () => {
        if(busy||gesture||markup?.pending())return;
        if (redo.length) {
            undo.push(C.clone(doc));
            doc = redo.pop();
            markup?.changed();
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
        if(textEdits.has('text')&&$('text').value.includes('\n')&&es.some(e=>e.type==='TEXT')){status('기존 한 줄 문자는 줄바꿈을 지원하지 않습니다. 새 여러 줄 문자로 작성하세요.');return;}
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
        if($('text-dialog').open){if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s')e.preventDefault();if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault();commitText();}return;}

        if(e.key==='Escape'&&gesture){e.preventDefault();cancelGesture();return;}
        if(gesture)return;
        if (["INPUT", "SELECT", "TEXTAREA"].includes(e.target.tagName) && !((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==="s")) return;
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
        if (dirty||$('text-dialog').open||markup?.pending()) {
            e.preventDefault();
            e.returnValue = "";
        }
    });
    markup=window.createCADMarkupTools({canvas,document:()=>doc,canManage:()=>canAnnotate,busy:()=>busy||!!gesture,
        scale:()=>scale,world,screen,snap,render,snapshot,status,clearSnap:()=>{snapMark=null;},
        boundary:p=>entities().filter(e=>e.type==='LWPOLYLINE'&&e.closed&&e.editable&&e.points.length<=512)
            .map(e=>e.points.map(q=>({x:q.x,y:q.y})))
            .filter(ps=>window.CADMarkup.validPolygon(ps)&&window.CADMarkup.inside(p,ps))
            .sort((a,b)=>Math.abs(window.CADMarkup.signedArea(a))-Math.abs(window.CADMarkup.signedArea(b)))[0],
        clearCAD:()=>{cancelGesture();selected.clear();points=[];hoverPoint=null;snapMark=null;setTool('select');},
        pan:(dx,dy)=>{origin.x+=dx;origin.y+=dy;render();}});
    $('print-markups').onclick=()=>{
        if(!doc||busy||gesture||markup.pending())return;
        if(!record.canExport){status('이 계정에는 문서 내보내기 권한이 없습니다.');return;}
        try{
            printing=true;render();
            $('cad-print-image').src=canvas.toDataURL('image/png');
            $('cad-print-title').textContent=record.title+' · 개정 '+record.revision+(dirty?' · 저장 전 작업':'');
            $('cad-print-note').textContent='현재 화면 · 도면 위 표시 포함 · 축척 인쇄용 아님'+(doc.entities.some(e=>!e.editable)?' · 미지원 CAD 객체는 화면에서 생략될 수 있습니다.':'');
            printing=false;render();window.print();
        }catch(error){printing=false;render();fail(error);}
    };
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
        }).finally(()=>{capabilitiesReady=true;ioControls();}),
        list().then(()=>{const drawingId=Number(query.get('drawing_id')),revision=Number(query.get('revision'));if(Number.isSafeInteger(drawingId)&&drawingId>0)return open(drawingId,false,Number.isSafeInteger(revision)&&revision>0?revision:null);}),
    ]).catch(fail);
})();
