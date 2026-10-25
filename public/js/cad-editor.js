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
    let aiVersion=0,aiHashCache=null,aiSession=null,aiTimer=null,aiBusy=false,aiConfig=null,aiPreview=null;
    const aiCommands=new Map(),aiProcessed=new Map(),aiApplying=new Set();
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
    let markup=null, recovery=null, reviewWorkbench=null, workflow=null, packets=null, printing=false, cadBaseline="";
    const backgroundKey = 'cad:canvas-background:' + (document.body.dataset.editorOwner || 'guest');
    let canvasBackground = 'dark';
    try { if (localStorage.getItem(backgroundKey) === 'white') canvasBackground = 'white'; } catch (_) {}
    $('canvas-background').value = canvasBackground;
    $('canvas-background').onchange = () => {
        canvasBackground = $('canvas-background').value === 'white' ? 'white' : 'dark';
        try { localStorage.setItem(backgroundKey, canvasBackground); } catch (_) {}
        render();
    };
    const displayColor = color => C.canvasColor(color, canvasBackground);
    const styleEdits=new Set(),textEdits=new Set();
    let unitSettings=null, inputScale=1;
    const lengthInputs=['x','y','spacing','offset-distance','array-x','array-y','default-text-height','height','text-dialog-height','text-dialog-width'];
    const lengthValue=id=>$(id).value.trim()===''?NaN:Number($(id).value)/inputScale;
    const displayLength=value=>Number((value*inputScale).toPrecision(15));
    const unitKey=()=> 'cad:length-units:'+document.body.dataset.editorOwner+':'+record?.id;
    function setUnitInputs(settings){
        const next=C.lengthInputScale(settings);
        for(const id of lengthInputs){const field=$(id),value=Number(field.value);if(field.value.trim()&&Number.isFinite(value))field.value=Number((value/inputScale*next).toPrecision(15));}
        inputScale=next;unitSettings=settings;
        $('dimension-unit').value=settings?.unit||'drawing';
        const inputUnit=settings?.unit==='ft-in'?'ft (소수 피트)':settings?.unit||'도면 단위';
        $('length-input-unit').textContent='('+inputUnit+')';
        for(const id of lengthInputs)$(id).title='길이 입력: '+inputUnit;
    }
    function refreshUnitNote(){
        const source=C.drawingUnit(doc);
        $('source-unit-control').hidden=!!source||!doc;
        const basis=source?.name||$('source-unit').selectedOptions[0]?.textContent;
        $('dimension-unit-note').textContent=(source?'원본 단위: '+basis:($('source-unit').value?'지정한 원본 기준: '+basis:'단위 정보 없음 · 원본 기준을 먼저 선택하세요.'))+' · 전환 시 원본 크기 유지. 치수 변경 후 저장하세요. 기존 CAD 문자·잠긴 치수는 유지됩니다.';
    }
    function loadUnits(){
        let saved=null;try{saved=JSON.parse(localStorage.getItem(unitKey()));}catch(_){}
        const source=C.drawingUnit(doc), dimension=doc?.entities.find(e=>e.type==='ERP_DIMENSION');
        let settings=null;try{settings=C.measurementSettings(dimension?dimension.measurement:saved);}catch(_){}
        $('source-unit').value=Object.keys(C.lengthUnits).find(key=>C.lengthUnits[key]===settings?.mmPerUnit)||'';
        // A declared DXF unit takes precedence over stale browser preferences.
        if(source&&settings)settings={...settings,mmPerUnit:source.mmPerUnit};
        setUnitInputs(settings);inspectorKey='';refreshUnitNote();
    }
    function changeUnits(){
        if(!doc||busy||gesture||$('text-dialog').open){$('dimension-unit').value=unitSettings?.unit||'drawing';return;}
        const unit=$('dimension-unit').value,source=C.drawingUnit(doc),mmPerUnit=source?.mmPerUnit||C.lengthUnits[$('source-unit').value];
        if(unit!=='drawing'&&!mmPerUnit){$('dimension-unit').value=unitSettings?.unit||'drawing';refreshUnitNote();status('환산하려면 원본 도면의 기준 단위를 먼저 선택하세요.');return;}
        const settings=unit==='drawing'?null:C.measurementSettings({unit,mmPerUnit});
        const changed=doc.entities.filter(e=>e.type==='ERP_DIMENSION'&&canManage&&editable(e)&&JSON.stringify(e.measurement||null)!==JSON.stringify(settings));
        if(changed.length){snapshot();for(const e of changed){e.measurement=settings?{...settings}:null;e.dirty=true;}}
        for(const e of measurements)e.measurement=settings?{...settings}:null;
        setUnitInputs(settings);refreshUnitNote();
        try{if(settings)localStorage.setItem(unitKey(),JSON.stringify(settings));else localStorage.removeItem(unitKey());}catch(_){}
        render();
        status('치수 단위: '+$('dimension-unit').selectedOptions[0].textContent+(changed.length?' · '+changed.length+'개 치수 변경 · 저장 필요':'')+' · 좌표와 실제 길이 유지');
    }
    $('dimension-unit').onchange=changeUnits;
    $('source-unit').onchange=changeUnits;
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
        tape: "줄자 · 안내선",
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
        $('dxf').disabled=busy||!!record?.reference||!record?.canExport;$('dwg').disabled=busy||!!record?.reference||!record?.canExport||!cap.dwgWrite;
        $('sites').disabled=busy||inClaim||proofMode;$('drawings').disabled=busy||proofMode;
        $('upload').disabled=busy||!uploadAllowed||proofMode||!capabilitiesReady;
        $('save').disabled=busy||!canAnnotate||!record;$('reload').disabled=busy||!record;
        $('history').disabled=busy||proofMode||!record;$('print-markups').disabled=busy||!record?.canExport;inspector();markup?.refresh();if(doc)refreshGuideControls();
        reviewWorkbench?.refresh();workflow?.refresh();packets?.refresh();
    }
    function startIO(kind,title){
        if(busy||gesture)return null;
        const token=++ioSequence;activeIO={token,kind};busy=true;ioControls();
        canvasState('loading',title,'파일 크기와 도면 복잡도에 따라 시간이 걸릴 수 있습니다. 완료될 때까지 기다려 주세요.');
        return token;
    }
    function finishIO(token){
        if(activeIO?.token!==token)return;activeIO=null;busy=false;ioControls();$('viewport').setAttribute('aria-busy','false');
        recovery?.offer();
    }
    $('drawing-state-dismiss').onclick=()=>{$('drawing-state').hidden=true;};
    function byteSize(text) {
        let bytes=0;
        for(let i=0;i<text.length;i++){
            const code=text.charCodeAt(i);
            if(code<128)bytes++;
            else if(code<2048)bytes+=2;
            else if(code>=0xd800&&code<=0xdbff&&text.charCodeAt(i+1)>=0xdc00&&text.charCodeAt(i+1)<=0xdfff){bytes+=4;i++;}
            else bytes+=3;
        }
        return bytes;
    }
    function sizeLabel(bytes){const divisor=bytes<1024?1:bytes<1048576?1024:1048576,unit=divisor===1?' B':divisor===1024?' KiB':' MiB';return (bytes/divisor).toLocaleString('ko-KR',{maximumFractionDigits:2})+unit;}
    function capabilityLimit(name){const value=cap.limits?.[name];return Number.isSafeInteger(value)&&value>0?value:null;}
    const uploadLimitHint=document.createElement('span');
    uploadLimitHint.id='upload-limit';uploadLimitHint.className='note';uploadLimitHint.hidden=true;
    $('upload').closest('label').after(uploadLimitHint);$('upload').setAttribute('aria-describedby','upload-limit');
    function showUploadLimit(){const max=capabilityLimit('maxUploadBytes');uploadLimitHint.hidden=!max;uploadLimitHint.textContent=max?'파일당 최대 '+sizeLabel(max):'';}
    async function api(path = "", options = {}) {
        const maxRequest=capabilityLimit('maxRequestBytes');
        if(maxRequest&&typeof options.body==='string'){
            const actual=byteSize(options.body);
            if(actual>maxRequest)throw Error('저장·전송할 내용이 너무 큽니다 ('+sizeLabel(actual)+' / 최대 '+sizeLabel(maxRequest)+'). 현재 도면과 저장하지 않은 표시는 유지됩니다. 도면을 나누거나 관리자에게 문의하세요.');
        }
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
        aiVersion++;aiPreview=null;
        dirty = true;
        $("dirty").textContent = "● 저장 안 됨";
        recovery?.schedule();
    }
    function snapshot() {
        undo.push(C.copyDocument(doc));
        if (undo.length > 60) undo.shift();
        redo = [];
        mark();
    }
    function selectionFor(operation){
        const all=[...doc.entities,...measurements].filter(e=>selected.has(e.id));
        if(!all.length)throw Error('객체를 선택하세요.');
        const blocked=all.filter(e=>!e.transient&&(!canManage||!editable(e)||!C.allows(e,operation)||e.hidden||e.paper||doc.layers[e.layer]?.visible===false));
        if(blocked.length)throw Error('선택한 '+all.length+'개 중 '+blocked.length+'개는 이 작업을 지원하지 않거나 잠겨 있습니다. 아무 객체도 변경하지 않았습니다. 지원 객체만 다시 선택하세요.');
        return all;
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
        if($('snap').checked||['measure','dimension','tape'].includes(tool)){
            let best=12/scale;for(const e of entities()){if(exclude.has(e.id))continue;for(const q of C.snapPoints(e)){const distance=C.distance(p,q);if(distance<best){best=distance;snapMark={...q};}}}
            if(guides().length&&doc?.workspace?.guidesVisible!==false){const q=C.guideSnap(p,guides(),12/scale,sourceEdges(p));if(q&&(!snapMark||q.kind!=='guide-projection'||C.distance(p,q)<C.distance(p,snapMark)))snapMark={...q};}
            if(snapMark)p={x:snapMark.x,y:snapMark.y};
        }
        const orthoAnchor=points.length&&['line','polyline','move','copy'].includes(tool)?points.at(-1):gesture?.kind==='draw'&&gesture.tool==='line'?gesture.start:gesture?.kind==='move'?gesture.start:null;
        if($('ortho').checked&&orthoAnchor){if(Math.abs(p.x-orthoAnchor.x)>Math.abs(p.y-orthoAnchor.y))p.y=orthoAnchor.y;else p.x=orthoAnchor.x;}
        const anchor=gesture?.kind==='draw'&&gesture.tool==='line'?gesture.start:points.length&&['line','polyline','tape'].includes(tool)?points.at(-1):null;
        inference=null;
        if(anchor&&!(tool==='tape'&&$('tape-mode').value==='parallel')){const lock=axisLock??shiftLock;if(!snapMark||lock!==null){const found=C.inferDirection(anchor,p,{enabled:$('direction-inference').checked,lock,reference:referenceAngle,step:Number($('inference-step').value)||0});if(found.kind){inference=found;p=found.point;if(lock!==null)snapMark=null;}}}
        return p;
    }

    function entityColor(e){return e.color!==undefined&&e.color!==null?'#'+e.color.toString(16).padStart(6,'0'):e.aci>0&&e.aci<256?C.aci(e.aci):doc.layers[e.layer]?.color||'#e2e8f0';}
    function inspector(){
        // Unit selections must wait for the same drawing/save transaction as geometry edits.
        for(const id of ['dimension-unit','source-unit'])$(id).disabled=!doc||busy||!!gesture;
        const es=doc?doc.entities.filter(e=>selected.has(e.id)):[],first=es[0];
        const key=JSON.stringify([canManage,busy,...es.map(e=>[e.id,e.color,e.aci,e.lineWeight,e.lineType,e.text,e.height,e.angle,doc.layers[e.layer]?.locked,doc.layers[e.layer]?.color,doc.layers[e.layer]?.lineWeight])]);
        if(key===inspectorKey)return;inspectorKey=key;styleEdits.clear();textEdits.clear();
        $('selection-properties').textContent=first?(es.length===1?(first.type==='ERP_DIMENSION'?'거리 치수':first.type)+' · '+(editable(first)?(first.editReason||'2D 편집 가능')+' · 지원: '+(first.operations||['move','copy','rotate','scale','resize','style']).map(op=>({move:'이동',copy:'복사',rotate:'회전',scale:'배율',mirror:'대칭',resize:'손잡이',style:'속성',text:'문자',delete:'삭제',layer:'레이어'}[op]||op)).join(' / '):'읽기 전용 또는 레이어 잠금'):es.length+'개 선택 · 첫 객체의 속성 표시'):'객체를 선택하면 속성이 표시됩니다. 새 객체 기본값은 왼쪽에서 설정하세요.';
        if(first){$('entity-color').value=entityColor(first);$('entity-width').value=first.lineWeight>=0?first.lineWeight/100:(first.lineWeight??-1);$('entity-linetype').value=first.lineType||'BYLAYER';}
        const text=es.find(e=>e.type==='TEXT'||e.type==='MTEXT');
        $('edit-text-dialog').disabled=!text||busy||es.length!==1;
        if(text){$('text').value=text.text;$('height').value=displayLength(text.height);$('text-color').value=entityColor(text);}
        $('text-properties').disabled=!text||!canManage||busy||!es.every(e=>['TEXT','MTEXT'].includes(e.type)&&editable(e)&&C.allows(e,'text'));
        const shapeEnabled=canManage&&!busy&&es.length>0&&es.every(e=>!['TEXT','MTEXT'].includes(e.type)&&editable(e)&&C.allows(e,'style'));
        $('apply-style').disabled=!shapeEnabled;
        for(const id of ['entity-color','entity-width','entity-linetype'])$(id).disabled=!shapeEnabled;
    }
    function hitEntity(p){
        for(const e of [...entities(),...measurements].slice().reverse()){
            if(e.type==='ERP_DIMENSION'){for(const part of C.dimensionParts(e)){if(part.type==='LINE'&&C.segmentDistance(p,part.p,part.q)<=10/scale)return e;if(part.type==='TEXT'&&C.geometry(part).some(q=>C.distance(p,q)<=10/scale))return e;}continue;}
            if(['TEXT','MTEXT'].includes(e.type)){const box=C.textBox(e).points;if(window.CADMarkup.inside(p,box)||box.some((q,i)=>C.segmentDistance(p,q,box[(i+1)%box.length])<=5/scale))return e;continue;}
            if(C.contains(e,p))return e;
            if(e.type==='INSERT'){const paths=e.renderParts?.map(C.geometry)||e.preview||[];if(paths.some(path=>path.some((q,i)=>i>0&&C.segmentDistance(p,path[i-1],q)<=10/scale)))return e;continue;}
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
        $('text-dialog-height').value=displayLength(entity?.height||lengthValue('default-text-height')||2.5);
        $('text-dialog-color').value=entity?entityColor(entity):$('default-text-color').value;
        $('text-dialog-width').value=displayLength(box?.width??entity?.width??0);
        $('text-dialog-error').textContent=allowed?'':'이 문자는 읽기 전용입니다. 복잡한 서식 또는 레이어·접근 권한을 원래 CAD에서 확인하세요.';
        for(const id of ['text-dialog-content','text-dialog-height','text-dialog-width'])$(id).readOnly=!allowed||(id==='text-dialog-width'&&entity?.type==='TEXT');
        $('text-dialog-color').disabled=!allowed;$('text-dialog-confirm').disabled=!allowed;
        $('text-dialog').showModal();$('text-dialog-content').focus();
    }
    function commitText(){
        if(!textDraft||busy)return;
        const original=doc.entities.find(e=>e.id===textDraft.entityId),content=$('text-dialog-content').value,height=lengthValue('text-dialog-height'),width=lengthValue('text-dialog-width'),color=parseInt($('text-dialog-color').value.slice(1),16);
        if(!canManage||(original&&!editable(original))||doc.layers[textDraft.layer]?.locked)return;
        if(!content.trim()||!Number.isFinite(height)||height<=0||!Number.isFinite(width)||width<0){$('text-dialog-error').textContent='문자 내용과 양수 크기, 0 이상 박스 폭을 입력하세요.';return;}
        if(original?.type==='TEXT'&&content.includes('\n')){$('text-dialog-error').textContent='기존 한 줄 문자는 줄바꿈을 지원하지 않습니다. 여러 줄 문자는 새 문자로 작성하세요.';return;}
        snapshot();
        let entity;
        if(original){entity={...C.clone(original),text:content,height,color,dirty:true};if(entity.type==='MTEXT')entity.width=width;doc.entities[doc.entities.indexOf(original)]=entity;}
        else {entity=C.newEntity('MTEXT',{p:textDraft.p,text:content,height,width,color,angle:textDraft.angle,plainText:true},textDraft.layer);doc.entities.push(entity);}
        selected.clear();selected.add(entity.id);$('text-dialog').close();textDraft=null;setTool(original?'select':'text');if(!original)selected.clear();render();
    }
    $('text-dialog-confirm').onclick=commitText;
    $('text-dialog-cancel').onclick=()=>{$('text-dialog').close();textDraft=null;};
    $('text-dialog').addEventListener('close',()=>{
        // A queued close event can arrive after the next drawing has reopened this dialog.
        if($('text-dialog').open)return;
        textDraft=null;render();
    });
    $('edit-text-dialog').onclick=()=>{const entity=doc?.entities.find(e=>selected.has(e.id)&&['TEXT','MTEXT'].includes(e.type));if(entity)openTextEditor(entity);};
    function annotationPreview(){
        if(!['measure','dimension'].includes(tool)||!points.length||!hoverPoint)return null;
        try{const a=points[0],b=points[1]||hoverPoint,p=points.length>1?hoverPoint:{x:(a.x+b.x)/2,y:(a.y+b.y)/2};return C.newDimension(a,b,p,{measurement:unitSettings?{...unitSettings}:null,mode:$('dimension-mode').value,height:lengthValue('default-text-height')||2.5,color:0x67e8f9,layer:$('layer').value});}catch(_){return null;}
    }

    function render() {
        const r = canvas.getBoundingClientRect(),
            dpr = printing ? 3 : window.devicePixelRatio || 1;
        const width = Math.floor(r.width * dpr), height = Math.floor(r.height * dpr);
        if (canvas.width !== width) canvas.width = width;
        if (canvas.height !== height) canvas.height = height;
        ctx.save();
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, r.width, r.height);
        ctx.fillStyle = printing || canvasBackground === 'white' ? '#ffffff' : '#0a1020';
        ctx.fillRect(0,0,r.width,r.height);
        const spacing = lengthValue('spacing');
        if (!printing && $("grid").checked && spacing > 0 && spacing * scale > 8) {
            ctx.strokeStyle = canvasBackground === 'white' ? '#e2e8f0' : '#202c40';
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
        ctx.save();
        ctx.globalAlpha=doc?.workspace?.underlay?.opacity ?? 1;
        if(doc?.workspace?.underlay?.grayscale)ctx.filter="grayscale(1)";
        const shown=[...entities(),...measurements.map(e=>gesture?.preview?.get(e.id)||e),...(annotation?[annotation]:[])];
        C.renderEntities(ctx,doc,shown,screen,scale,{paper:printing,selected,background:canvasBackground});
        ctx.restore();
        ctx.setLineDash([]);
        if(gesture?.kind==='draw'&&gesture.moved){
            ctx.strokeStyle=displayColor($('default-color').value);ctx.lineWidth=2;ctx.setLineDash([6,4]);ctx.beginPath();
            const a=screen(gesture.start),b=screen(gesture.end);if(['rectangle','text'].includes(gesture.tool))ctx.rect(a.x,a.y,b.x-a.x,b.y-a.y);else {ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);}ctx.stroke();ctx.setLineDash([]);
        }
        if(!printing&&tool==='select'&&!busy)for(const e of [...entities(),...measurements.map(e=>gesture?.preview?.get(e.id)||e)].filter(e=>selected.has(e.id)&&editable(e)&&(canManage||e.transient))){for(const grip of C.grips(e)){const p=screen(grip.point);ctx.fillStyle='#101827';ctx.strokeStyle='#67e8f9';ctx.lineWidth=1.5;ctx.fillRect(p.x-4,p.y-4,8,8);ctx.strokeRect(p.x-4,p.y-4,8,8);}}
        if(!printing&&snapMark){const marker=screen(snapMark);ctx.strokeStyle=displayColor('#ffb347');ctx.lineWidth=2;ctx.setLineDash([]);ctx.strokeRect(marker.x-6,marker.y-6,12,12);ctx.beginPath();ctx.moveTo(marker.x-10,marker.y);ctx.lineTo(marker.x+10,marker.y);ctx.moveTo(marker.x,marker.y-10);ctx.lineTo(marker.x,marker.y+10);ctx.stroke();}
        canvas.dataset.snapActive=String(!!snapMark);if(snapMark){canvas.dataset.snapX=snapMark.x;canvas.dataset.snapY=snapMark.y;}
        const measured=annotation||measurements.find(e=>selected.has(e.id))||doc?.entities.find(e=>e.type==='ERP_DIMENSION'&&selected.has(e.id));
        $('annotation-readout').textContent=measured?'거리 '+C.dimensionLayout(measured).label+(measured.measurement?' · ':' 도면 단위 · ')+(points.length===2?'치수 위치를 지정하세요':measured.transient?'화면 측정 · 저장되지 않음':'끝점/꼭지점 자동 스냅'):'';

        if(aiPreview){ctx.save();ctx.setLineDash([5,4]);ctx.strokeStyle=displayColor('#77ffb3');ctx.lineWidth=2;for(const id of aiPreview.affectedIds){const entity=aiPreview.document.entities.find(e=>e.id===id);if(!entity)continue;const paths=entity.type==='ERP_DIMENSION'?C.dimensionParts(entity).map(C.geometry):[C.geometry(entity)];for(const path of paths){ctx.beginPath();path.forEach((p,i)=>{const s=screen(p);i?ctx.lineTo(s.x,s.y):ctx.moveTo(s.x,s.y);});ctx.stroke();}}ctx.restore();}
        inspector();aiControls();
        reviewWorkbench?.refresh();
        workflow?.refresh();
        packets?.refresh();
        ctx.fillStyle = displayColor('#5eead4');
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
        renderGuides();
        markup?.render(ctx,{paper:printing,background:canvasBackground,color:printing?undefined:displayColor});
        if(!printing)workflow?.renderOverlay(ctx,screen);
        ctx.restore();
    }
    function focusBounds(b){const width=Math.max(b.maxX-b.minX,1e-6),height=Math.max(b.maxY-b.minY,1e-6);scale=Math.min(canvas.clientWidth/(width*1.4),canvas.clientHeight/(height*1.4));origin={x:canvas.clientWidth/2-(b.minX+b.maxX)/2*scale,y:canvas.clientHeight/2+(b.minY+b.maxY)/2*scale};render();}
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
        tool = t;axisLock=null;shiftLock=null;inference=null;tapeEdge=null;referenceAngle=null;$('numeric-length').value='';
        canvas.style.cursor=t==='select'?'default':'crosshair';
        points = [];
        document
            .querySelectorAll("[data-tool]")
            .forEach((b) => {b.classList.toggle("active", b.dataset.tool === t);b.setAttribute("aria-pressed",String(b.dataset.tool===t));});
        if (t === "delete") {
            try{selectionFor('delete');}catch(error){status(error.message);tool='select';render();return;}
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
            document.querySelectorAll("[data-tool]").forEach(b=>{b.classList.toggle("active",b.dataset.tool===tool);b.setAttribute("aria-pressed",String(b.dataset.tool===tool));});
        }
        render();
    }
    const cadToolArt={"select":"<path d=\"M5 3l14 10-7 1-3 7z\" fill=\"currentColor\"/>","line":"<path d=\"M4 20L20 4\"/><circle cx=\"4\" cy=\"20\" r=\"2\"/><circle cx=\"20\" cy=\"4\" r=\"2\"/>","polyline":"<path d=\"M3 18l6-12 6 12 6-12\"/><path d=\"M2 17h2v2H2zM8 5h2v2H8zM14 17h2v2h-2zM20 5h2v2h-2z\"/>","rectangle":"<rect x=\"3\" y=\"5\" width=\"18\" height=\"14\" rx=\"1\"/>","circle":"<circle cx=\"12\" cy=\"12\" r=\"9\"/><path d=\"M10 12h4m-2-2v4\"/>","arc":"<path d=\"M3 18a9 9 0 0 1 18 0\"/><circle cx=\"3\" cy=\"18\" r=\"1.5\"/><circle cx=\"21\" cy=\"18\" r=\"1.5\"/>","text":"<path d=\"M4 5h16M12 5v15m-4 0h8M4 5v3m16-3v3\"/>","dimension":"<path d=\"M3 4v16M21 4v16M3 12h18m-14-3-4 3 4 3m10-6 4 3-4 3\"/>","measure":"<path d=\"M3 17L17 3l4 4L7 21zM7 13l3 3m1-7 3 3m1-7 3 3\"/>","move":"<path d=\"M12 2v20M2 12h20M8 6l4-4 4 4M8 18l4 4 4-4M6 8l-4 4 4 4m12-8 4 4-4 4\"/>","copy":"<rect x=\"8\" y=\"8\" width=\"13\" height=\"13\" rx=\"2\"/><path d=\"M16 8V3H3v13h5\"/>","rotate":"<path d=\"M4 9a8 8 0 1 1 0 7M4 3v6h6\"/>","scale":"<rect x=\"3\" y=\"13\" width=\"8\" height=\"8\"/><path d=\"M3 9V3h18v18h-6M11 13l10-10m-6 0h6v6\"/>","mirror":"<path d=\"M12 2v3m0 3v3m0 3v3m0 3v2M8 5L2 19h6zM16 5l6 14h-6z\"/>","delete":"<path d=\"M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7\"/>"};
    cadToolArt.tape=cadToolArt.measure;
    Object.entries(labels).forEach(([k, l]) => {
        const b = document.createElement("button");
        b.innerHTML='<span class="cad-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">'+cadToolArt[k]+'</svg></span><span>'+l+'</span>';
        b.setAttribute('aria-label',l);b.title=l;b.setAttribute('aria-pressed',String(k===tool));
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
    let rawHover=null,tapeEdge=null,tapeMeasurement=null,axisLock=null,shiftLock=null,inference=null,referenceAngle=null,guidePanelKey='',edgeCache=null;
    function numericSettings(){return {unit:unitSettings?.unit||'drawing',mmPerUnit:C.drawingUnit(doc)?.mmPerUnit||C.lengthUnits[$('source-unit').value]||doc?.workspace?.measurement?.metersPerUnit*1000||null};}
    function sourceEdges(point){
        const visibility=JSON.stringify(Object.entries(doc.layers).map(([name,layer])=>[name,layer.visible]));
        if(edgeCache?.source!==doc||edgeCache.version!==aiVersion||edgeCache.visibility!==visibility){
            const edges=[];let visited=0,limited=false;
            const add=(p,q)=>{if(edges.length>=100000){limited=true;return;}if(p&&q&&Number.isFinite(p.x)&&Number.isFinite(p.y)&&Number.isFinite(q.x)&&Number.isFinite(q.y))edges.push({p,q,minX:Math.min(p.x,q.x),maxX:Math.max(p.x,q.x),minY:Math.min(p.y,q.y),maxY:Math.max(p.y,q.y)});};
            const collect=e=>{if(limited)return;if(++visited>200000){limited=true;return;}if(e.hidden||e.paper||doc.layers[e.layer]?.visible===false)return;if(e.type==='INSERT'&&e.renderParts){e.renderParts.forEach(collect);return;}if(e.type==='LINE'){const path=e.displayGeometry||[e.p,e.q];if(path.length===2)add(path[0],path[1]);}else if(['LWPOLYLINE','POLYLINE'].includes(e.type)&&!e.points?.some(p=>p.bulge)){const ps=e.displayGeometry||e.points||[];if(ps.length>100000){limited=true;return;}for(let i=1;i<ps.length;i++)add(ps[i-1],ps[i]);if(e.closed&&ps.length>2)add(ps.at(-1),ps[0]);}};
            entities().forEach(collect);
            if(limited){edgeCache={source:doc,version:aiVersion,visibility,edges:[],grid:new Map(),long:[],cell:1};status('줄자 기준선 검색 한도를 넘었습니다. 불필요한 레이어를 숨기고 다시 시도하세요.');return [];}
            const bounds=edges.reduce((b,e)=>({minX:Math.min(b.minX,e.minX),maxX:Math.max(b.maxX,e.maxX),minY:Math.min(b.minY,e.minY),maxY:Math.max(b.maxY,e.maxY)}),{minX:Infinity,minY:Infinity,maxX:-Infinity,maxY:-Infinity}),cell=Math.max((Math.max(bounds.maxX-bounds.minX,bounds.maxY-bounds.minY)||64)/64,1e-6),grid=new Map(),long=[];let references=0;
            edges.forEach((e,id)=>{const x0=Math.floor(e.minX/cell),x1=Math.floor(e.maxX/cell),y0=Math.floor(e.minY/cell),y1=Math.floor(e.maxY/cell);const cells=(x1-x0+1)*(y1-y0+1);if(cells>256||references+cells>500000){long.push(id);return;}references+=cells;for(let x=x0;x<=x1;x++)for(let y=y0;y<=y1;y++){const key=x+','+y;if(!grid.has(key))grid.set(key,[]);grid.get(key).push(id);}});
            edgeCache={source:doc,version:aiVersion,visibility,edges,grid,long,cell};
        }
        if(!point)return edgeCache.edges;
        const {edges,grid,long,cell}=edgeCache,tolerance=12/scale,x0=Math.floor((point.x-tolerance)/cell),x1=Math.floor((point.x+tolerance)/cell),y0=Math.floor((point.y-tolerance)/cell),y1=Math.floor((point.y+tolerance)/cell),ids=new Set(long);
        if((x1-x0+1)*(y1-y0+1)>4096){for(let i=0;i<edges.length;i++)ids.add(i);}else for(let x=x0;x<=x1;x++)for(let y=y0;y<=y1;y++)for(const id of grid.get(x+','+y)||[])ids.add(id);
        return [...ids].map(id=>edges[id]).filter(e=>e.minX<=point.x+tolerance&&e.maxX>=point.x-tolerance&&e.minY<=point.y+tolerance&&e.maxY>=point.y-tolerance).map(e=>({...e,distance:C.segmentDistance(point,e.p,e.q)})).filter(e=>e.distance<tolerance).sort((a,b)=>a.distance-b.distance).slice(0,64);
    }
    function nearestTapeEdge(p){let edge=null,best=12/scale;for(const candidate of sourceEdges(p)){const d=C.segmentDistance(p,candidate.p,candidate.q);if(d<best&&C.distance(candidate.p,candidate.q)>1e-10){edge=candidate;best=d;}}return edge;}
    function guides(){return doc?.workspace?.guides||[];}
    function guideWritable(){return !!doc&&canAnnotate&&!busy&&!gesture;}
    function addGuide(data){if(!guideWritable())throw Error('읽기 전용이거나 저장 중에는 안내선을 수정할 수 없습니다.');if(guides().length>=256)throw Error('안내선·안내점은 최대 256개입니다.');if(!Number.isFinite(data.p.x)||!Number.isFinite(data.p.y)||Math.abs(data.p.x)>1e9||Math.abs(data.p.y)>1e9)throw Error('안내 좌표 범위를 확인하세요.');snapshot();doc.workspace=doc.workspace||{};doc.workspace.guides=[...guides(),{id:C.uid(),...data}];doc.workspace.guidesVisible=true;points=[];tapeEdge=null;$('numeric-length').value='';render();status('안내를 추가했습니다. 저장하면 다음에 다시 사용할 수 있습니다.');}
    function tapePoint(p){
        const mode=$('tape-mode').value;
        if(!points.length){if(mode==='parallel'){tapeEdge=nearestTapeEdge(p);if(!tapeEdge){status('기준이 될 직선 또는 직선 폴리선의 선분을 클릭하세요.');return;}const dx=tapeEdge.q.x-tapeEdge.p.x,dy=tapeEdge.q.y-tapeEdge.p.y,t=((p.x-tapeEdge.p.x)*dx+(p.y-tapeEdge.p.y)*dy)/(dx*dx+dy*dy);points=[{x:tapeEdge.p.x+t*dx,y:tapeEdge.p.y+t*dy}];}else points=[p];referenceAngle=nearestTapeEdge(p);referenceAngle=referenceAngle?Math.atan2(referenceAngle.q.y-referenceAngle.p.y,referenceAngle.q.x-referenceAngle.p.x)*180/Math.PI:null;hoverPoint=p;render();return;}
        try{if(mode==='distance'){if(C.distance(points[0],p)<1e-10)throw Error('서로 다른 두 점을 지정하세요.');tapeMeasurement={p:points[0],q:p};points=[];render();return;}
            if(mode==='point')addGuide({type:'point',p});
            else {const d=C.distance(tapeEdge.p,tapeEdge.q),direction={x:(tapeEdge.q.x-tapeEdge.p.x)/d,y:(tapeEdge.q.y-tapeEdge.p.y)/d},normal={x:-direction.y,y:direction.x},offset=(p.x-tapeEdge.p.x)*normal.x+(p.y-tapeEdge.p.y)*normal.y;if(Math.abs(offset)<1e-10)throw Error('기준 선에서 떨어진 곳을 지정하세요.');addGuide({type:'line',p:{x:tapeEdge.p.x+normal.x*offset,y:tapeEdge.p.y+normal.y*offset},direction});}
        }catch(error){status(error.message);}
    }
    function numericAnchor(){return gesture?.kind==='draw'?gesture.start:points.at(-1);}
    function numericResult(){const a=numericAnchor();if(!a||!hoverPoint)throw Error('시작점을 클릭하고 마우스로 방향을 가리키세요.');if(tool==='tape'&&$('tape-mode').value==='parallel'){if(!tapeEdge)throw Error('기준 선을 먼저 클릭하세요.');const distance=C.parseLengthInput($('numeric-length').value,numericSettings()),d=C.distance(tapeEdge.p,tapeEdge.q),n={x:-(tapeEdge.q.y-tapeEdge.p.y)/d,y:(tapeEdge.q.x-tapeEdge.p.x)/d},side=(hoverPoint.x-a.x)*n.x+(hoverPoint.y-a.y)*n.y<0?-1:1;return {p:a,q:{x:a.x+n.x*distance*side,y:a.y+n.y*distance*side}};}return C.numericDrawing(tool,a,hoverPoint,$('numeric-length').value,numericSettings());}
    function commitNumeric(){if(!doc||busy||!['line','polyline','rectangle','circle','tape'].includes(tool))return;try{const result=numericResult();if(tool==='tape')tapePoint(result.q);else{if(!canManage||doc.layers[$('layer').value]?.locked)throw Error('읽기 전용 또는 레이어 잠금입니다.');if(gesture)cancelGesture();if(!points.length)points=[result.p];point(result.q);}$('numeric-length').value='';canvas.focus();render();}catch(error){status(error.message);}}
    function refreshGuideControls(){
        if(!doc)return;const visible=doc.workspace?.guidesVisible!==false;
        const mode=$('tape-mode').value,labels={line:['선 길이','1200mm 또는 1200mm<80'],polyline:['다음 선분 길이','1200mm · Enter로 다음 점'],rectangle:['사각형 폭,높이','1200mm,800mm · ,800mm'],circle:['원 반지름 / D 지름','250mm 또는 D500mm'],tape:mode==='parallel'?['안내선 간격','기준 선에서 300mm']:mode==='point'?['안내점 거리','500mm 또는 500mm<80']:['측정 거리','두 번째 점까지 길이']};
        $('numeric-label').textContent=labels[tool]?.[0]||'길이 입력';$('numeric-length').placeholder=labels[tool]?.[1]||'그리기 도구를 선택하세요';$('numeric-length').disabled=busy||!labels[tool]||(tool!=='tape'&&!canManage);
        const key=JSON.stringify([guides(),visible,canAnnotate,busy]);if(key!==guidePanelKey){guidePanelKey=key;$('guide-visible').checked=visible;$('guide-visible').disabled=!canAnnotate||busy;$('guide-clear').disabled=!canAnnotate||busy||!guides().length;$('guide-list').replaceChildren();for(const [i,g]of guides().entries()){const button=document.createElement('button');button.textContent=(g.type==='line'?'안내선 ':'안내점 ')+(i+1)+' 삭제';button.disabled=!canAnnotate||busy;button.onclick=()=>{if(!guideWritable())return;snapshot();doc.workspace.guides=guides().filter(item=>item.id!==g.id);render();};$('guide-list').append(button);}}
        return visible;
    }
    function renderGuides(){
        const visible=refreshGuideControls();if(!doc)return;
        if(printing)return;ctx.save();ctx.strokeStyle='#eabf63';ctx.fillStyle='#eabf63';ctx.lineWidth=1;ctx.setLineDash([6,5]);
        const line=(a,b)=>{const u=screen(a),v=screen(b);ctx.beginPath();ctx.moveTo(u.x,u.y);ctx.lineTo(v.x,v.y);ctx.stroke();};
        if(visible)for(const g of guides()){if(g.type==='point'){const p=screen(g.p);ctx.beginPath();ctx.arc(p.x,p.y,4,0,Math.PI*2);ctx.stroke();}else{const length=(canvas.clientWidth+canvas.clientHeight)/scale+Math.hypot(g.p.x-(canvas.clientWidth/2-origin.x)/scale,g.p.y-(origin.y-canvas.clientHeight/2)/scale);line({x:g.p.x-g.direction.x*length,y:g.p.y-g.direction.y*length},{x:g.p.x+g.direction.x*length,y:g.p.y+g.direction.y*length});}}
        const a=numericAnchor();let cursor=hoverPoint,viewInference=inference;if($('numeric-length').value&&a)try{cursor=numericResult().q;if($('numeric-length').value.includes('<'))viewInference={angle:Math.atan2(cursor.y-a.y,cursor.x-a.x)*180/Math.PI,kind:'angle'};}catch(_){}
        if(tool==='tape'){let m=points.length&&cursor?{p:points[0],q:cursor}:tapeMeasurement;if(m&&points.length&&tapeEdge&&$('tape-mode').value==='parallel'){const d=C.distance(tapeEdge.p,tapeEdge.q),n={x:-(tapeEdge.q.y-tapeEdge.p.y)/d,y:(tapeEdge.q.x-tapeEdge.p.x)/d},offset=(cursor.x-points[0].x)*n.x+(cursor.y-points[0].y)*n.y;m={p:points[0],q:{x:points[0].x+n.x*offset,y:points[0].y+n.y*offset}};}if(m){line(m.p,m.q);$('tape-readout').textContent='거리 '+C.formatLength(C.distance(m.p,m.q),unitSettings)+(unitSettings?'':' 도면 단위')+(viewInference?' · '+viewInference.angle.toFixed(1)+'°':'');}else{const edge=hoverPoint&&nearestTapeEdge(hoverPoint);$('tape-readout').textContent=edge?'선 길이 '+C.formatLength(C.distance(edge.p,edge.q),unitSettings):'시작점 또는 기준 선을 클릭하세요.';}}
        else $('tape-readout').textContent=viewInference?'방향 '+viewInference.angle.toFixed(1)+'° · '+({x:'X 가로',y:'Y 세로',parallel:'기준선 평행',perpendicular:'기준선 직각',locked:'방향 잠금',angle:'각도 간격'}[viewInference.kind]||'자유'):'';
        if(a&&cursor&&['line','polyline','rectangle','circle','tape'].includes(tool)){ctx.strokeStyle=viewInference&&(viewInference.kind==='x'||viewInference.kind==='locked'&&Math.abs(viewInference.angle%180)<1e-8)?'#ff7777':viewInference&&(viewInference.kind==='y'||viewInference.kind==='locked'&&Math.abs(Math.abs(viewInference.angle%180)-90)<1e-8)?'#6fe3a1':'#80bfff';if(viewInference){const angle=viewInference.angle*Math.PI/180,L=(canvas.clientWidth+canvas.clientHeight)/scale;ctx.globalAlpha=.45;line({x:a.x-Math.cos(angle)*L,y:a.y-Math.sin(angle)*L},{x:a.x+Math.cos(angle)*L,y:a.y+Math.sin(angle)*L});ctx.globalAlpha=1;}if(tool==='tape'&&tapeEdge&&$('tape-mode').value==='parallel'){const d=C.distance(tapeEdge.p,tapeEdge.q),u={x:(tapeEdge.q.x-tapeEdge.p.x)/d,y:(tapeEdge.q.y-tapeEdge.p.y)/d},n={x:-u.y,y:u.x},offset=(cursor.x-a.x)*n.x+(cursor.y-a.y)*n.y,p={x:a.x+n.x*offset,y:a.y+n.y*offset},L=(canvas.clientWidth+canvas.clientHeight)/scale;line({x:p.x-u.x*L,y:p.y-u.y*L},{x:p.x+u.x*L,y:p.y+u.y*L});}if(tool==='circle'){const p=screen(a);ctx.beginPath();ctx.arc(p.x,p.y,C.distance(a,cursor)*scale,0,Math.PI*2);ctx.stroke();}else if(tool==='rectangle'){line(a,{x:cursor.x,y:a.y});line({x:cursor.x,y:a.y},cursor);line(cursor,{x:a.x,y:cursor.y});line({x:a.x,y:cursor.y},a);}else line(a,cursor);}
        ctx.restore();
    }
    $('numeric-length').addEventListener('input',()=>render());
    $('numeric-length').addEventListener('keydown',e=>{if(e.isComposing)return;if(e.key==='Enter'){e.preventDefault();e.stopPropagation();commitNumeric();}else if(e.key==='Escape'){e.preventDefault();e.stopPropagation();$('numeric-length').value='';points=[];tapeEdge=null;cancelGesture();setTool('select');canvas.focus();}else if((e.ctrlKey||e.metaKey)&&['z','y'].includes(e.key.toLowerCase())){e.preventDefault();e.stopPropagation();$('numeric-length').value='';$(e.key.toLowerCase()==='y'||e.shiftKey?'redo':'undo').click();canvas.focus();}});
    $('tape-mode').onchange=()=>{points=[];tapeEdge=null;tapeMeasurement=null;$('numeric-length').value='';render();};
    $('guide-visible').onchange=()=>{if(!guideWritable())return;snapshot();doc.workspace=doc.workspace||{};doc.workspace.guidesVisible=$('guide-visible').checked;render();};
    $('guide-clear').onclick=()=>{if(!guideWritable()||!guides().length)return;snapshot();doc.workspace.guides=[];render();};
    $('tape-calibration').onclick=()=>{document.querySelector('.mode-switch [data-workspace="markup"]').click();const panel=$('markup-workbench');if(panel){const details=[...panel.querySelectorAll('details')].find(d=>d.querySelector('summary')?.textContent.includes('단위'));if(details){details.open=true;details.scrollIntoView({block:'center'});}}status('이 설정은 표시의 면적·길이 환산 기준입니다. 원본 CAD 크기는 바뀌지 않습니다.');};
    function point(p, shift = false) {
        if (!doc || busy || gesture) return;
        if(tool==='tape'){tapePoint(p);return;}
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
            if(points.length===3){try{const e=C.newDimension(points[0],points[1],points[2],{measurement:unitSettings?{...unitSettings}:null,mode:$('dimension-mode').value,height:lengthValue('default-text-height')||2.5,color:parseInt($('default-color').value.slice(1),16),layer:$('layer').value});selected.clear();selected.add(e.id);if(tool==='measure'){e.transient=true;measurements.push(e);}else{snapshot();doc.entities.push(e);}points=[];hoverPoint=null;}catch(error){points.pop();status(error.message);}}
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
        points.push(p);hoverPoint=p;
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
        if(['move','copy','rotate','scale','mirror'].includes(tool)){try{selectionFor(tool);}catch(error){points=[];status(error.message);render();return;}}
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
        if(['line','rectangle','circle','arc'].includes(tool)){selected.clear();hoverPoint=null;}
        render();
    }
    canvas.onpointerdown = e => {
        if(gesture||pan)return;canvas.focus();
        if(e.button===1||e.button===2){e.preventDefault();canvas.style.cursor='grabbing';pan={pointerId:e.pointerId,x:e.clientX,y:e.clientY,ox:origin.x,oy:origin.y};canvas.setPointerCapture(e.pointerId);return;}
        if(e.button!==0||!doc||busy||$('text-dialog').open)return;
        const p=world(e);
        if(!points.length){const edge=nearestTapeEdge(p);referenceAngle=edge?Math.atan2(edge.q.y-edge.p.y,edge.q.x-edge.p.x)*180/Math.PI:null;}
        if(tool==='select'){
            const grip=!e.shiftKey&&gripAt(p),hit=grip?.entity||hitEntity(p);
            if(!grip){if(e.shiftKey){if(hit){selected.has(hit.id)?selected.delete(hit.id):selected.add(hit.id);}render();return;}
                if(!hit||!selected.has(hit.id))selected.clear();if(hit)selected.add(hit.id);}
            render();if(!hit||(!canManage&&!hit.transient)||!editable(hit))return;
            try{selectionFor(grip?'resize':'move');}catch(error){status(error.message);return;}
            gesture={kind:grip?'resize':'move',key:grip?.key,entity:hit,start:p,end:p,pointerId:e.pointerId,sx:e.clientX,sy:e.clientY,moved:false,originals:grip?[hit]:[...doc.entities,...measurements].filter(e=>selected.has(e.id)&&editable(e)&&(canManage||e.transient)),preview:new Map()};
        }else if(['line','rectangle','text'].includes(tool)&&!points.length){
            if(!canManage||doc.layers[$('layer').value]?.locked){status('읽기 전용 또는 작업 레이어 잠금입니다.');return;}
            gesture={kind:'draw',tool,start:snap(p),end:snap(p),pointerId:e.pointerId,sx:e.clientX,sy:e.clientY,moved:false};
        }else {point(snap(p),e.shiftKey);return;}
        canvas.setPointerCapture(e.pointerId);e.preventDefault();
    };
    canvas.onpointermove=e=>{
        if(pan&&pan.pointerId===e.pointerId){origin={x:pan.ox+e.clientX-pan.x,y:pan.oy+e.clientY-pan.y};render();return;}
        rawHover=world(e);const excluded=new Set(gesture?.originals?.map(e=>e.id)||[]),p=snap({...rawHover},excluded);hoverPoint=p;
        $('x').value=displayLength(p.x);$('y').value=displayLength(p.y);
        if(!gesture||gesture.pointerId!==e.pointerId){if(['measure','dimension','tape','line','polyline','rectangle','circle'].includes(tool))render();canvas.style.cursor=tool==='select'?(gripAt(world(e))?'nwse-resize':'default'):'crosshair';return;}
        if((!canManage&&!gesture.originals?.every(e=>e.transient))||busy){cancelGesture();return;}
        if(Math.hypot(e.clientX-gesture.sx,e.clientY-gesture.sy)<4&&!gesture.moved)return;
        gesture.moved=true;gesture.end=p;canvas.style.cursor=gesture.kind==='move'?'move':gesture.kind==='resize'?'nwse-resize':'crosshair';
        if(gesture.kind!=='draw'){
            try{
                if(gesture.originals.some(e=>!editable([...doc.entities,...measurements].find(n=>n.id===e.id)))){cancelGesture();return;}
                const preview=gesture.originals.map(e=>gesture.kind==='resize'?C.resize(e,gesture.key,p):C.transform(e,{dx:p.x-gesture.start.x,dy:p.y-gesture.start.y}));
                gesture.preview=new Map(preview.map(e=>[e.id,e]));gesture.invalid=false;
            }catch(error){gesture.invalid=true;gesture.preview=new Map();status(error.message);}
        }render();
    };
    canvas.onpointerup=e=>{
        if(pan?.pointerId===e.pointerId){pan=null;canvas.style.cursor=tool==='select'?'default':'crosshair';if(canvas.hasPointerCapture(e.pointerId))canvas.releasePointerCapture(e.pointerId);return;}
        if(!gesture||gesture.pointerId!==e.pointerId)return;
        const finished=gesture;gesture=null;canvas.style.cursor=tool==='select'?'default':'crosshair';if(canvas.hasPointerCapture(e.pointerId))canvas.releasePointerCapture(e.pointerId);
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
    canvas.onpointerleave=()=>{if(!gesture){snapMark=null;if(!numericAnchor())hoverPoint=null;render();}};
    canvas.ondblclick=e=>{if(tool!=='select'||busy||gesture)return;const hit=hitEntity(world(e));if(hit&&['TEXT','MTEXT'].includes(hit.type)){selected=new Set([hit.id]);openTextEditor(hit);}};
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
        if(j.reference){await window.CADReference.load(j.reference);parsed.reference=C.clone(j.reference);}
        if(activeIO?.token!==token)return;
        if(aiSession)stopAISharing(true,'도면을 다시 열어 공유를 중지했습니다.');
        doc = parsed;aiVersion++;aiHashCache=null;edgeCache=null;tapeMeasurement=null;tapeEdge=null;axisLock=null;inference=null;
        doc.markups = C.clone(j.markups || []);
        doc.workspace = C.clone(j.workspace || {});
        cadBaseline = C.exportDXF(parsed);
        markup?.reset();
        record = j;
        loadUnits();
        canManage = !!j.canManage && parsed.acadVersion >= 1015 && !proofMode && !j.reference;
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
        const unavailable=doc.presentation?.unavailable||doc.entities.filter(e=>!e.paper&&!e.hidden&&doc.layers[e.layer]?.visible!==false&&!C.geometry(e).length).map(e=>({id:e.id,type:e.type}));
        const missingTypes=Object.entries(unavailable.reduce((out,e)=>(out[e.type]=(out[e.type]||0)+1,out),{})).map(([type,n])=>type+' '+n+'개').join(', ');
        const presentationWarnings=doc.presentation?.warnings||[],substitutions=[...new Set(presentationWarnings.map(w=>w.reason))];
        const reasons={font_substitution:'브라우저 대체 글꼴',mtext_format_substitution:'MTEXT 특수 서식 일부 단순화',text_fit_alignment:'맞춤 문자 정렬 대체',mirrored_text_substitution:'반사 문자 대체',nonplanar_projection:'비평면 객체의 2D 투영',gradient_outline:'그라데이션은 경계만 표시',hatch_pattern_missing:'해치 패턴 정의 없음',external_or_missing_block:'외부 참조 또는 블록 없음',block_recursion:'순환 블록 참조',block_complexity:'블록 표시 복잡도 초과'};
        $('warnings').textContent=(j.warnings||[]).join(' ')+' 모델 공간 2D 표시 · 원본 데이터 보존. 편집 잠금 '+doc.entities.filter(e=>!e.editable).length+'개 · 화면 미표시 '+unavailable.length+'개'+(missingTypes?' ('+missingTypes+')':'')+'. '+substitutions.map(reason=>reasons[reason]||reason).join(' · ')+'. 레이어 설정은 CAD 저장에 반영됩니다. 도면 위 표시는 원본과 분리하여 개정별로 저장됩니다.';
        $('drawing-fidelity').hidden=!unavailable.length&&!presentationWarnings.length;
        $('drawing-fidelity').textContent='미표시 '+unavailable.length+'개'+(presentationWarnings.length?' · 대체 표현 확인':'')+' · 상세';
        $('drawing-fidelity').onclick=()=>{document.querySelector('.drawing-info').open=true;document.querySelector('.drawing-info').scrollIntoView({block:'nearest'});};
        layers();
        fit();
        const linkedMarkup=doc.markups.find(m=>m.id===query.get('markup_id'));if(linkedMarkup){markup.select([linkedMarkup.id]);focusBounds(window.CADMarkup.bounds(linkedMarkup));}
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
            const content = dxfContent(), markupContent=JSON.stringify(doc.markups || []), workspaceContent=JSON.stringify(doc.workspace || {});
            const maxDrawing=capabilityLimit('maxDrawingBytes');
            if(maxDrawing&&byteSize(content)>maxDrawing)throw Error('저장할 CAD 도면이 너무 큽니다 ('+sizeLabel(byteSize(content))+' / 최대 '+sizeLabel(maxDrawing)+'). 현재 편집은 유지됩니다. 도면을 나누거나 관리자에게 문의하세요.');
            const j = await api("/" + record.id, {
                    method: "PUT",
                    body: JSON.stringify({
                        revision: record.revision,
                        dxf: content,
                        markups: JSON.parse(markupContent),
                        workspace: JSON.parse(workspaceContent),
                    }),
                });
            record.revision = j.revision;
            record.snapshotToken = j.snapshotToken;
            record.dxf = content;
            cadBaseline = C.exportDXF(doc);
            dirty = JSON.stringify(doc.markups || []) !== markupContent || JSON.stringify(doc.workspace || {}) !== workspaceContent;
            if(!dirty)await recovery?.saved();
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
            if(dirty)recovery?.schedule();
            ioControls();
        }
    }
    function dxfContent() {
        const exported=C.exportDXF(doc);
        return exported===cadBaseline ? record.dxf : exported;
    }
    function download() {
        if(record?.reference)return;
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
            const maxUpload=capabilityLimit('maxUploadBytes');
            if(maxUpload&&file.size>maxUpload)throw Error('“'+file.name+'” 파일이 너무 큽니다 ('+sizeLabel(file.size)+' / 파일당 최대 '+sizeLabel(maxUpload)+'). 파일을 나누거나 크기를 줄인 뒤 다시 올려 주세요.');
            if(!guard())return;
            const site=$('sites').value;
            if(!site)throw Error('도면을 등록할 현장을 먼저 선택하세요. 위의 현장 목록에서 현장을 선택한 뒤 다시 파일을 올려 주세요.');
            if(/\.dwg$/i.test(file.name)&&!cap.dwgRead)throw Error('서버의 DWG 읽기 엔진이 준비되지 않았습니다. 관리자에게 DWG 엔진 상태를 확인해 주세요.');
            const reference=await window.CADReference?.prepare(file);if(reference===false)return;
            token=startIO('upload',file.name+' · 업로드 및 변환 중…');if(!token)return;
            const form=new FormData();form.append('file',file);form.append('title',file.name);form.append('site_id',site);
            if(reference)for(const key of ['page','width','height'])form.append('reference['+key+']',reference[key]);
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
    async function openSourceSheet(sheetId) {
        const source=await api('/source-sheets/'+sheetId);
        status('원본 PDF를 확인한 뒤 새 편집본을 만듭니다. 기존 PDF 스튜디오 표시는 원래 화면에 유지됩니다.');
        const response=await fetch(source.url,{credentials:'same-origin',headers:{Accept:'application/pdf'}});
        if(!response.ok){const error=await response.json().catch(()=>({}));throw Error(error.message||'원본 PDF를 불러오지 못했습니다.');}
        const file=new File([await response.blob()],'drawing.pdf',{type:'application/pdf'}),reference=await window.CADReference.prepare(file,Number(source.page));
        if(!reference)return;
        const token=startIO('import','PDF 편집본을 만드는 중…');if(!token)return;
        try {const result=await api('/source-sheets/'+sheetId,{method:'POST',body:JSON.stringify({requestKey:crypto.randomUUID(),title:source.title.slice(0,255),reference})});await list();await loadDrawing(result.id,null,token);history.replaceState(null,'','/cad?drawing_id='+result.id);}
        finally {finishIO(token);}
    }
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
                    "/download?format=" + (record.reference?'original':'dxf') + "&revision=" +
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
        const p = { x: lengthValue('x'), y: lengthValue('y') };
        if (Number.isFinite(p.x) && Number.isFinite(p.y)) point(p);
    };
    $("fit").onclick = fit;
    $("undo").onclick = () => {
        if(busy||gesture||markup?.pending())return;
        if (undo.length) {
            redo.push(C.copyDocument(doc));
            doc = undo.pop();
            loadUnits();
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
            undo.push(C.copyDocument(doc));
            doc = redo.pop();
            loadUnits();
            markup?.changed();
            selected.clear();
            mark();
            layers();
            render();
        }
    };
    $("apply-text").onclick = () => {
        if (!canManage || !doc || busy || gesture) return;
        let selectedText;try{selectedText=selectionFor('text');if(selectedText.some(e=>!['TEXT','MTEXT'].includes(e.type)))throw Error('문자만 선택하세요. 다른 객체는 변경하지 않았습니다.');}catch(error){status(error.message);return;}
        const es = doc.entities.filter(
                (e) =>
                    selected.has(e.id) &&
                    editable(e) &&
                    ["TEXT", "MTEXT"].includes(e.type),
            ),
            h = lengthValue('height');
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
    document.addEventListener('keyup',e=>{if(e.key==='Shift'){shiftLock=null;if(rawHover){hoverPoint=snap({...rawHover});if(gesture?.kind==='draw')gesture.end=hoverPoint;render();}}});
    document.addEventListener("keydown", (e) => {
        if($('text-dialog').open){if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s')e.preventDefault();if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault();commitText();}return;}

        if(e.isComposing||document.querySelector('dialog[open]'))return;
        const editing=['INPUT','SELECT','TEXTAREA'].includes(e.target.tagName);
        if(!editing&&!e.ctrlKey&&!e.metaKey&&!e.altKey){
            if(e.key.toLowerCase()==='t'&&!points.length&&!gesture){e.preventDefault();document.querySelector('.mode-switch [data-workspace="cad"]').click();setTool('tape');return;}
            if(['line','polyline','tape'].includes(tool)&&['ArrowRight','ArrowLeft','ArrowUp','ArrowDown','Shift'].includes(e.key)){e.preventDefault();if(e.key==='ArrowRight')axisLock=0;else if(['ArrowLeft','ArrowUp'].includes(e.key))axisLock=90;else if(e.key==='ArrowDown'){axisLock=null;shiftLock=null;}else if(numericAnchor()&&hoverPoint)shiftLock=inference?.angle??Math.atan2(hoverPoint.y-numericAnchor().y,hoverPoint.x-numericAnchor().x)*180/Math.PI;if(rawHover){hoverPoint=snap({...rawHover});if(gesture?.kind==='draw')gesture.end=hoverPoint;render();}status(axisLock===0?'X 방향 잠금 · 빨강':axisLock===90?'Y 방향 잠금 · 초록':'방향 잠금 갱신');return;}
            if(['line','polyline','rectangle','circle','tape'].includes(tool)&&numericAnchor()&&e.key.length===1&&/^[0-9.d]$/i.test(e.key)){e.preventDefault();$('numeric-length').value=e.key;$('numeric-length').focus();render();return;}
        }
        if(e.key==='Escape'&&gesture){e.preventDefault();cancelGesture();setTool('select');return;}
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
    function operation(fn,kind='style') {
        if (!doc || !canManage || busy || gesture) return;
        try {
            const es = selectionFor(kind);
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
                C.offset(e, lengthValue('offset-distance')),
            );
            return () => doc.entities.push(...added);
        },'copy');
    for (const kind of ["trim", "extend"])
        $(kind).onclick = () =>
            operation((es) => {
                if (es.length !== 2)
                    throw Error("대상 선 → 기준 선 순서로 2개 선택하세요.");
                const n = C.trim(
                    es[0],
                    es[1],
                    { x: lengthValue('x'), y: lengthValue('y') },
                    kind === "extend",
                );
                return () => {
                    doc.entities[
                        doc.entities.findIndex((e) => e.id === es[0].id)
                    ] = n;
                };
            },'resize');
    $("array").onclick = () =>
        operation((es) => {
            const added = C.array(
                es,
                Number($("rows").value),
                Number($("cols").value),
                lengthValue('array-x'),
                lengthValue('array-y'),
            );
            return () => doc.entities.push(...added);
        },'copy');
    $("assign-layer").onclick = () =>
        operation(
            (es) => () =>
                es.forEach((e) => {
                    e.layer = $("layer").value;
                    e.dirty = true;
                }),
            'layer',
        );
    $("apply-style").onclick = () =>
        operation((selectedEntities) => {
            if(selectedEntities.some(e=>['TEXT','MTEXT'].includes(e.type)))throw Error('선·도형 속성은 문자와 혼합 적용할 수 없습니다. 문자를 제외하고 다시 선택하세요.');
            const es=selectedEntities;
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

    // This bridge sends bounded snapshots only after explicit sharing. Editor tokens
    // stay in this closure and commands never invoke provider APIs or save the DWG.
    function aiState(message){$('ai-status').textContent=message;}
    function aiControls(){
        $('ai-start').disabled=aiBusy||!!aiSession||!doc||!record||!aiConfig?.available;
        $('ai-stop').disabled=!aiSession;$('ai-refresh').disabled=aiBusy||!aiSession;
        $('ai-allow-edits').disabled=!canManage||proofMode;
        $('ai-auto-apply').disabled=!canManage||!$('ai-allow-edits').checked||proofMode;
        if($('ai-auto-apply').disabled)$('ai-auto-apply').checked=false;
    }
    async function aiRequest(path,options={},session=aiSession){
        const response=await fetch('/admin-api/cad/ai'+path,{credentials:'same-origin',...options,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,...(session?{'X-CAD-Editor-Token':session.editorToken}:{}),...options.headers}});
        const json=response.status===204?{}:await response.json().catch(()=>({}));
        if(!response.ok||json.success===false){const error=Error(safeApiMessage(json,response.status));error.status=response.status;throw error;}return json.data||json;
    }
    async function configureAI(){
        try{aiConfig=await aiRequest('/config',{},null);$('ai-server-url').value=aiConfig.serverUrl||'';$('ai-config-reason').textContent=aiConfig.available?'MCP 연결 승인 후 현재 도면 공유를 시작하세요.':aiConfig.reason||'현재 서버에서 CAD AI 연결을 사용할 수 없습니다.';
            $('ai-connections').replaceChildren();for(const connection of aiConfig.connections||[]){const row=document.createElement('div'),label=document.createElement('span'),button=document.createElement('button');label.textContent=(connection.name||'연결')+' · '+(connection.createdAt||'');button.textContent='이 연결 해제';button.onclick=async()=>{button.disabled=true;try{await aiRequest('/connections/'+encodeURIComponent(connection.id),{method:'DELETE'},null);await stopAISharing(true,'연결을 해제하고 공유를 중지했습니다.');await configureAI();}catch(error){aiState(error.message);}finally{button.disabled=false;}};row.append(label,button);$('ai-connections').append(row);}
        }catch(error){aiConfig={available:false};$('ai-config-reason').textContent=error.message;}aiControls();
    }
    function hashMaterial(source){return C.exportDXF(source)+'\n'+JSON.stringify({markups:source.markups||[],workspace:source.workspace||{},layers:source.layers,entities:source.entities.map(e=>({...e,raw:undefined,rawParts:undefined}))});}
    async function currentAIHash(){
        const source=doc,version=aiVersion;if(!source)throw Error('도면을 먼저 여세요.');if(aiHashCache?.source===source&&aiHashCache.version===version)return aiHashCache.hash;
        if(!crypto.subtle)throw Error('보안 연결에서만 CAD AI 공유를 사용할 수 있습니다.');
        const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(hashMaterial(source))),hash=Array.from(new Uint8Array(digest),n=>n.toString(16).padStart(2,'0')).join('');
        if(doc!==source||version!==aiVersion)throw Error('도면이 변경 중입니다. 잠시 후 다시 시도하세요.');aiHashCache={source,version,hash};return hash;
    }
    async function captureAI(session=null,recapture=false){
        if(!doc||!record)throw Error('도면을 먼저 여세요.');const hash=await currentAIHash(),version=aiVersion,source=doc;
        const scope=recapture||!session?$('ai-share-scope').value:session.scope,unit=recapture||!session?$('ai-unit').value:session.unit;
        const ids=recapture||!session?null:session.scopeIds;
        const subset=ids?{...doc,entities:doc.entities.filter(e=>ids.has(e.id))}:doc;
        const selection=ids?[...ids]:[...selected];
        const context=C.aiContext(subset,{scope,unit,selection,documentHash:hash,allowEmpty:!!session&&!recapture});
        return {context,version,source,scope,unit,scopeIds:new Set(context.entities.map(e=>e.id))};
    }
    function aiPath(session){return '/sessions/'+encodeURIComponent(session.sessionId);}
    async function startAISharing(){
        if(aiBusy||aiSession)return;if(busy||gesture||markup?.pending()||$('markup-dialog').open||$('text-dialog').open){aiState('현재 편집을 끝낸 뒤 공유하세요.');return;}
        aiBusy=true;aiControls();try{const captured=await captureAI(),drawingId=record.id,allowEdits=canManage&&$('ai-allow-edits').checked;
            const result=await aiRequest('/sessions',{method:'POST',body:JSON.stringify({drawingId,revision:record.revision,context:captured.context,allowEdits})},null);
            if(doc!==captured.source||aiVersion!==captured.version||record.id!==drawingId){await aiRequest(aiPath(result),{method:'DELETE'},result);throw Error('공유 준비 중 도면이 변경되었습니다. 현재 도면을 다시 공유하세요.');}
            aiSession={...captured,...result,drawingId:record.id,lastPublished:Date.now(),lastAllowEdits:allowEdits};aiCommands.clear();aiProcessed.clear();aiState('공유 중 · '+captured.context.entities.length+'개 객체 · '+captured.unit+' · 편집은 저장 버튼으로 저장');renderAICommands();scheduleAI(0);
        }catch(error){aiState(error.message);}finally{aiBusy=false;aiControls();}
    }
    async function stopAISharing(remote=true,message='공유를 중지했습니다.'){
        const session=aiSession;aiSession=null;if(aiTimer)clearTimeout(aiTimer);aiTimer=null;aiPreview=null;aiCommands.clear();aiProcessed.clear();$('ai-auto-apply').checked=false;renderAICommands();aiState(message);aiControls();render();
        if(remote&&session)try{await aiRequest(aiPath(session),{method:'DELETE'},session);}catch(_){aiState(message+' 서버 세션이 남아 있으면 만료 시 종료됩니다.');}
    }
    function scheduleAI(delay=2000){if(aiTimer)clearTimeout(aiTimer);if(aiSession)aiTimer=setTimeout(tickAI,delay);}
    async function refreshAI(){
        if(!aiSession||aiBusy||aiApplying.size)return;const session=aiSession;aiBusy=true;aiControls();try{if(!await flushAIAcks(session))return;const capture=await captureAI(session,true);await aiRequest(aiPath(session),{method:'PUT',body:JSON.stringify({context:capture.context,allowEdits:canManage&&$('ai-allow-edits').checked})},session);if(aiSession===session){Object.assign(session,capture,{lastPublished:Date.now(),lastAllowEdits:canManage&&$('ai-allow-edits').checked});aiPreview=null;aiState('공유 범위를 갱신했습니다 · '+capture.context.entities.length+'개 객체');render();}}
        catch(error){aiState(error.message);}finally{aiBusy=false;aiControls();}
    }
    async function tickAI(){
        const session=aiSession;if(!session)return;if(aiBusy||aiApplying.size){scheduleAI();return;}
        if(record?.id!==session.drawingId){await stopAISharing(true,'다른 도면으로 변경되어 공유를 중지했습니다.');return;}
        if(session.expiresAt&&Date.parse(session.expiresAt)<=Date.now()){await stopAISharing(false,'공유 세션이 만료되었습니다. 다시 공유를 시작하세요.');return;}
        aiBusy=true;aiControls();
        try{
            if(!await flushAIAcks(session))return;
            const captured=await captureAI(session),allowEdits=canManage&&$('ai-allow-edits').checked;
            if(captured.context.documentHash!==session.context.documentHash||allowEdits!==session.lastAllowEdits||Date.now()-session.lastPublished>=60000){
                const renewed=await aiRequest(aiPath(session),{method:'PUT',body:JSON.stringify({context:captured.context,allowEdits})},session);
                if(aiSession!==session)return;session.context=captured.context;session.lastPublished=Date.now();session.lastAllowEdits=allowEdits;if(renewed.expiresAt)session.expiresAt=renewed.expiresAt;
            }
            const result=await aiRequest(aiPath(session)+'/commands',{},session);if(aiSession!==session)return;
            const pendingIds=new Set((result.commands||[]).filter(c=>c.status==='pending').map(c=>c.id));for(const id of aiCommands.keys())if(!pendingIds.has(id))aiCommands.delete(id);
            for(const command of result.commands||[]){if(aiProcessed.has(command.id)){await acknowledgeAI(command.id,aiProcessed.get(command.id),session);continue;}if(command.status==='pending')aiCommands.set(command.id,command);}
            renderAICommands();
            if($('ai-auto-apply').checked&&$('ai-allow-edits').checked&&!busy&&!gesture&&!$('text-dialog').open)for(const command of [...aiCommands.values()]){if(!$('ai-auto-apply').checked||!$('ai-allow-edits').checked||busy||gesture||$('text-dialog').open)break;await applyAICommand(command,true);if(!aiSession)break;}
        }catch(error){if([401,403,404,410].includes(error.status)){await stopAISharing(false,'공유 권한이 해제되었거나 세션이 만료되었습니다.');return;}aiState('연결 확인 필요 · '+error.message);}
        finally{aiBusy=false;aiControls();if(aiSession===session)scheduleAI();}
    }
    async function prepareAICommand(command){
        if(!aiSession)throw Error('공유 세션이 없습니다.');if(busy||gesture||$('text-dialog').open)throw Error('현재 편집을 끝낸 뒤 AI 요청을 적용하세요.');
        if(!$('ai-allow-edits').checked)throw Error('AI 편집 요청 허용을 먼저 켜세요.');
        const currentHash=await currentAIHash(),session=aiSession;
        const result=C.applyAIActions(doc,command.actions,{canManage,expectedHash:command.documentHash,currentHash,sharedIds:session.context.entities.map(e=>e.id),sharedLayers:session.context.layers.map(l=>l.name),transactionId:command.id});
        const nextIds=new Set([...session.scopeIds,...result.affectedIds]),subset={...result.document,entities:result.document.entities.filter(e=>nextIds.has(e.id))};const source=doc,version=aiVersion;const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(hashMaterial(result.document))),nextHash=Array.from(new Uint8Array(digest),n=>n.toString(16).padStart(2,'0')).join('');const resultContext=C.aiContext(subset,{scope:session.scope,unit:session.unit,selection:[...nextIds],documentHash:nextHash,allowEmpty:true});
        return {...result,resultContext,currentHash,source,version,session};
    }
    async function previewAICommand(command){if(aiBusy||aiApplying.size)return;try{const result=await prepareAICommand(command);if(aiBusy||aiApplying.size||result.session!==aiSession||result.source!==doc||result.version!==aiVersion)return;aiPreview=result;aiState('초록 점선은 AI 편집 미리보기입니다. 아직 적용·저장하지 않았습니다.');render();}catch(error){aiState(error.message);}}
    async function acknowledgeAI(id,result,session){
        if(aiSession!==session)return false;
        if(result.acknowledged)return true;
        try{if(result.status==='applied'&&!result.context)result.context=(await captureAI(session)).context;if(aiSession!==session)return false;
            const {acknowledged,...payload}=result;await aiRequest(aiPath(session)+'/commands/'+encodeURIComponent(id)+'/result',{method:'POST',body:JSON.stringify(payload)},session);result.acknowledged=true;if(result.status==='applied'){session.context=result.context;session.lastPublished=Date.now();}return true;
        }catch(error){const applied=result.status==='applied';
            if([401,403,404,409,410].includes(error.status)){await stopAISharing(false,applied?'AI 결과 확인이 종료되었습니다. 로컬 변경은 이미 적용되어 유지됩니다. 저장 또는 실행 취소를 선택하세요.':'AI 요청 권한이 종료되어 공유를 중지했습니다.');return false;}
            aiState(applied?'로컬 편집은 적용됨 · 서버 확인 대기. 저장 또는 실행 취소할 수 있습니다.':'AI 결과 전송 재시도 대기 · '+error.message);return false;
        }
    }
    async function flushAIAcks(session){for(const [id,result] of aiProcessed)if(!result.acknowledged&&!await acknowledgeAI(id,result,session))return false;return aiSession===session;}
    async function applyAICommand(command,fromPoll=false){
        if(aiBusy&&!fromPoll)return;
        if(aiProcessed.has(command.id)||aiApplying.has(command.id))return;
        aiApplying.add(command.id);let committed=false,session=aiSession;
        try{
            const pending=await aiRequest(aiPath(session)+'/commands',{},session);
            const fresh=(pending.commands||[]).find(item=>item.id===command.id&&item.status==='pending');
            if(!fresh||fresh.documentHash!==command.documentHash||JSON.stringify(fresh.actions)!==JSON.stringify(command.actions))throw Error('AI 요청이 만료되었거나 변경되었습니다. 새 요청을 받아 주세요.');
            const result=await prepareAICommand(command);session=result.session;if(session!==aiSession||doc!==result.source||aiVersion!==result.version)throw Error('도면이 변경되었습니다. 새 요청을 받아 주세요.');
            snapshot();doc=result.document;committed=true;const ack={status:'applied',context:result.resultContext,message:'현재 편집기에 적용했습니다. DWG/DXF 저장은 사용자가 진행합니다.'};aiProcessed.set(command.id,ack);aiCommands.delete(command.id);
            for(const id of result.affectedIds)session.scopeIds.add(id);selected=new Set(result.affectedIds);points=[];layers();render();
            await acknowledgeAI(command.id,ack,session);if(aiSession===session)aiState(ack.acknowledged?'AI 편집 적용됨 · 저장하지 않은 변경입니다.':'AI 편집은 적용되었습니다. 결과 동기화 대기 중입니다.');renderAICommands();
        }catch(error){
            aiState(error.message);if(!committed&&aiSession===session){const ack={status:'failed',message:String(error.message).slice(0,1000)};aiProcessed.set(command.id,ack);aiCommands.delete(command.id);await acknowledgeAI(command.id,ack,session);renderAICommands();}
        }finally{aiApplying.delete(command.id);}
    }
    async function rejectAICommand(command){if(aiApplying.has(command.id)||aiProcessed.has(command.id))return;const ack={status:'rejected',message:'사용자가 편집 요청을 거절했습니다.'};aiProcessed.set(command.id,ack);aiCommands.delete(command.id);aiPreview=null;if(aiSession)await acknowledgeAI(command.id,ack,aiSession);renderAICommands();render();}
    function renderAICommands(){
        $('ai-command-list').replaceChildren();for(const command of aiCommands.values()){const card=document.createElement('div');card.className='ai-command';const title=document.createElement('strong'),preview=document.createElement('button'),apply=document.createElement('button'),dismiss=document.createElement('button'),detail=document.createElement('pre');title.textContent='편집 요청 '+command.id;detail.textContent=JSON.stringify(command.actions,null,2);preview.textContent='미리보기';apply.textContent='이 요청 적용';dismiss.textContent='거절';preview.onclick=()=>previewAICommand(command);apply.onclick=()=>applyAICommand(command);dismiss.onclick=()=>rejectAICommand(command);card.append(title,detail,preview,apply,dismiss);$('ai-command-list').append(card);}
    }
    $('ai-toggle').onclick=()=>{$('ai-panel').hidden=!$('ai-panel').hidden;$('ai-toggle').setAttribute('aria-expanded',String(!$('ai-panel').hidden));if(!$('ai-panel').hidden)configureAI();};
    $('ai-close').onclick=()=>{$('ai-panel').hidden=true;$('ai-toggle').setAttribute('aria-expanded','false');};
    $('ai-copy-url').onclick=()=>navigator.clipboard.writeText($('ai-server-url').value).then(()=>aiState('서버 주소를 복사했습니다.')).catch(()=>aiState('위 서버 주소를 직접 복사해 주세요.'));
    $('ai-start').onclick=startAISharing;$('ai-refresh').onclick=refreshAI;$('ai-stop').onclick=()=>stopAISharing();$('ai-reload-config').onclick=configureAI;
    $('ai-allow-edits').onchange=aiControls;$('ai-auto-apply').onchange=aiControls;

    window.addEventListener('pagehide',()=>{const session=aiSession;if(session)fetch('/admin-api/cad/ai'+aiPath(session),{method:'DELETE',credentials:'same-origin',keepalive:true,headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'X-CAD-Editor-Token':session.editorToken,Accept:'application/json'}}).catch(()=>{});});
    window.addEventListener("beforeunload", (e) => {
        if (dirty||$('text-dialog').open||markup?.pending()) {
            e.preventDefault();
            e.returnValue = "";
        }
    });
    markup=window.createCADMarkupTools({canvas,document:()=>doc,canManage:()=>canAnnotate,busy:()=>busy||!!gesture,
        scale:()=>scale,world,screen,snap,render,snapshot,status,clearSnap:()=>{snapMark=null;},
        record:()=>record, request:api, entities:()=>entities(),
        view:()=>({center:{x:(canvas.clientWidth/2-origin.x)/scale,y:(origin.y-canvas.clientHeight/2)/scale},scale}),
        setView:view=>{scale=view.scale;origin={x:canvas.clientWidth/2-view.center.x*scale,y:canvas.clientHeight/2+view.center.y*scale};render();},
        boundary:p=>{
            if(!window.CADMarkupGeometry?.findDrawingBoundary)throw Error('경계 분석 도구를 불러오지 못했습니다. 저장 후 다시 열거나 자유 영역을 사용하세요.');
            return window.CADMarkupGeometry.findDrawingBoundaryAdaptive(entities(),p,{
                layers:doc.layers,radii:[96,192,384,768].map(pixels=>pixels/scale),
            });
        },
        clearCAD:()=>{cancelGesture();selected.clear();points=[];hoverPoint=null;snapMark=null;setTool('select');},
        pan:(dx,dy)=>{origin.x+=dx;origin.y+=dy;render();}});
    recovery=window.createCADRecovery?.({
        state:()=>({record,version:aiVersion,dirty,canWrite:canAnnotate,busy,pending:!!gesture||markup.pending()||$('text-dialog').open}),
        capture:()=>({dxf:dxfContent(),markups:C.clone(doc.markups||[]),workspace:C.clone(doc.workspace||{})}),status,
        restore:async entry=>{
            if(!canAnnotate||busy||entry.drawingId!==record?.id||entry.baseRevision!==record.revision||!guard())return false;
            const parsed=C.parse(entry.dxf);
            if(!window.CADMarkupGeometry.validSet(entry.markups||[]))throw Error('임시 표시 데이터의 경계 또는 한도를 확인하세요.');
            busy=true;ioControls();
            try{
                if(aiSession)await stopAISharing(true,'임시 작업 복구로 AI 공유를 중지했습니다.');
                snapshot();doc=parsed;doc.markups=C.clone(entry.markups||[]);doc.workspace=C.clone(entry.workspace||{});if(record.reference)doc.reference=C.clone(record.reference);
                canManage=canAnnotate&&parsed.acadVersion>=1015&&!record.reference;selected.clear();measurements=[];points=[];loadUnits();markup.reset();layers();fit();
                $('dirty').textContent='● 임시 작업 복구됨 · 저장 필요';status('임시 작업을 복구했습니다. 확인 후 저장하세요.');return true;
            }finally{busy=false;ioControls();render();}
        },
        copy:async entry=>{
            if(!canAnnotate||busy||entry.drawingId!==record?.id||!guard())return false;
            const token=startIO('copy','별도 복구 사본을 저장하는 중…');if(!token)return false;
            let result;
            try{result=await api('/'+entry.drawingId+'/copies',{method:'POST',body:JSON.stringify({requestKey:entry.requestKey,revision:entry.baseRevision,title:(entry.title+' · 복구 사본').slice(0,255),dxf:entry.dxf,markups:entry.markups,workspace:entry.workspace})});await list();await loadDrawing(result.id,null,token);return result;}
            finally{finishIO(token);}
        }
    });
    const printWorkbench=window.createCADPrintWorkbench?.({
        state:()=>({doc,record,dirty,busy,pending:!!gesture||markup.pending()}),status,
        reviewArtifact:artifact=>packets?.review(artifact),
        selected:()=>markup.selected(),pickRegion:receiver=>markup.pickRegion(receiver),
        bounds:()=>({minX:-origin.x/scale,maxX:(canvas.clientWidth-origin.x)/scale,minY:(origin.y-canvas.clientHeight)/scale,maxY:origin.y/scale})
    });
    $('print-markups').onclick=()=>printWorkbench?.open();
    reviewWorkbench=window.createCADReviewWorkbench?.({
        state:()=>({doc,record,version:aiVersion,canManage:canAnnotate,busy,pending:!!gesture||markup.pending(),scale}),request:api,status,
        append:items=>markup.apply([...(doc.markups||[]),...items],items.map(m=>m.id)),
        pickRegion:receiver=>markup.pickRegion(receiver),
        focus:focusBounds
    });
    workflow=window.createCADWorkflow?.({state:()=>({doc,record,version:aiVersion,canManage:canAnnotate,dirty,busy,pending:!!gesture||markup.pending()}),request:api,status,render,
        selected:()=>markup.selected(),select:ids=>markup.select(ids),focus:focusBounds});
    packets=window.createCADPackets?.({state:()=>({record,busy,pending:!!gesture||markup.pending()}),request:api,status});
    document.addEventListener('keydown',event=>{if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='p'){event.preventDefault();printWorkbench?.open();}});
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
            showUploadLimit();
            $("capability").textContent =
                j.message ||
                "DWG 읽기: " +
                    (j.dwgRead ? "가능" : "엔진 설정 필요") +
                    " / 저장: " +
                    (j.dwgWrite ? "가능" : "엔진 설정 필요");
            $("dwg").disabled = !j.dwgWrite;
        }).finally(()=>{capabilitiesReady=true;ioControls();}),
        list().then(()=>{const drawingId=Number(query.get('drawing_id')),revision=Number(query.get('revision')),sheetId=Number(query.get('sheet_id'));if(Number.isSafeInteger(drawingId)&&drawingId>0)return open(drawingId,false,Number.isSafeInteger(revision)&&revision>0?revision:null);if(!inClaim&&Number.isSafeInteger(sheetId)&&sheetId>0)return openSourceSheet(sheetId);}),
    ]).catch(fail);
})();
