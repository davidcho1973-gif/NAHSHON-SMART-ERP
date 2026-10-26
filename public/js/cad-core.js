/* DXF editing uses lossless raw records for unchanged entities. Unsupported records are never regenerated. */
(function (g) {
    "use strict";
    const clone = (x) => JSON.parse(JSON.stringify(x)),
        num = (r, c, d = 0) => Number((r.find((p) => p[0] === c) || [0, d])[1]),
        val = (r, c, d = "") => (r.find((p) => p[0] === c) || [0, d])[1],
        pt = (r, c = 10) => ({ x: num(r, c), y: num(r, c + 10) });
    const numericCodes = new Set([
        10, 20, 30, 11, 21, 31, 38, 39, 40, 41, 42, 43, 44, 45, 50, 51, 210,
        220, 230, 370, 420,
    ]);
    const supported = new Set([
        "LINE",
        "CIRCLE",
        "ARC",
        "LWPOLYLINE",
        "TEXT",
        "MTEXT",
        "POINT",
    ]);
    function readSketchMetadata(raw){
        const at=raw.findIndex(p=>p[0]===1001&&p[1]==='ERP_CAD_EDIT');if(at<0||raw.filter(p=>p[0]===1001&&p[1]==='ERP_CAD_EDIT').length!==1)return null;
        let payload='';for(let i=at+1;i<raw.length&&raw[i][0]!==1001;i++){if(raw[i][0]!==1000)return null;payload+=raw[i][1];if(payload.length>1024)return null;}
        try{const m=JSON.parse(payload);if(m.v!==1||Object.keys(m).some(k=>!['v','group','locked'].includes(k))||m.group!==null&&m.group!==undefined&&(typeof m.group!=='string'||! /^[a-zA-Z0-9_-]{1,100}$/.test(m.group))||m.locked!==undefined&&typeof m.locked!=='boolean')return null;return m;}catch(_){return null;}
    }

    // Raw DXF and tessellated block geometry are read-only assets, not edit state.
    // A JSON copy expands shared point paths into separate multi-megabyte copies.
    // Share these assets across history entries; copy every mutable editing field.
    const immutableAssets = new WeakSet();
    const documentAssets = new Set(['prefix', 'suffix', 'lineTypes', 'presentation']);
    const entityAssets = new Set(['raw', 'rawParts', 'displayGeometry', 'preview', 'renderParts', 'hatch', 'presentationWarnings', 'explodeRaw']);
    function immutableAsset(value) {
        if (!value || typeof value !== 'object' || immutableAssets.has(value)) return value;
        immutableAssets.add(value);
        for (const child of Object.values(value)) immutableAsset(child);
        return Object.freeze(value);
    }
    function copyEditState(value, assets) {
        const mutable = {}, shared = {};
        for (const [key, item] of Object.entries(value)) {
            if (assets.has(key)) shared[key] = immutableAsset(item);
            else mutable[key] = item;
        }
        return Object.assign(clone(mutable), shared);
    }
    function copyDocument(source) {
        const {entities, ...metadata} = source;
        const result = copyEditState(metadata, documentAssets);
        result.entities = entities.map(entity => copyEditState(entity, entityAssets));
        return result;
    }
    function prepareDocumentAssets(doc) {
        for (const key of documentAssets) immutableAsset(doc[key]);
        for (const entity of doc.entities)
            for (const key of entityAssets) immutableAsset(entity[key]);
        return doc;
    }

    function canonicalLineType(value){const upper=String(value).toUpperCase();return ['BYLAYER','BYBLOCK','CONTINUOUS'].includes(upper)?upper:String(value);}
    function decodeUnicode(value){return String(value).replace(/\\U\+([0-9a-f]{4})/gi,(_,h)=>String.fromCharCode(parseInt(h,16)));}
    function uid(){return 'g'+Date.now().toString(36)+Math.random().toString(36).slice(2);}
    function plainMText(value){let safe=!/[{}\\]/.test(value.replace(/\\(?:P|\\|{|})/g,''));return {safe,text:value.replace(/\\(P|\\|{|})/g,(_,token)=>token==='P'?'\n':token)};}
    function encodeMText(value){return String(value).replace(/\\/g,'\\\\').replace(/{/g,'\\{').replace(/}/g,'\\}').replace(/\r?\n/g,'\\P');}
    function textLines(e){
        const out=[],limit=e.type==='MTEXT'&&e.width>0?e.width:Infinity;
        for(const line of String(e.text||'').split('\n')){let current='',width=0;for(const character of line){const advance=e.height*(character.charCodeAt(0)>255?1:.6);if(width+advance>limit&&current){out.push(current);current='';width=0;}current+=character;width+=advance;}out.push(current);}
        return out;
    }
    // Geometry remains in source DXF coordinates. Display units only format lengths.
    const lengthUnits = Object.freeze({in:25.4,ft:304.8,mm:1,cm:10,m:1000});
    function drawingUnit(doc) {
        const pairs=doc?.prefix||[], at=pairs.findIndex(p=>p[0]===9&&p[1]==='$INSUNITS');
        const code=at>=0&&pairs[at+1]?.[0]===70?Number(pairs[at+1][1]):0;
        const units={1:['in',25.4],2:['ft',304.8],3:['mi',1609344],4:['mm',1],5:['cm',10],6:['m',1000],7:['km',1000000],10:['yd',914.4],14:['dm',100],21:['US survey ft',1200000/3937]};
        const unit=units[code];return unit?{code,name:unit[0],mmPerUnit:unit[1]}:null;
    }
    function measurementSettings(value) {
        if(value==null)return null;
        if(!['in','ft','ft-in','mm','cm','m'].includes(value.unit)||!Number.isFinite(value.mmPerUnit)||value.mmPerUnit<=0)throw Error('원본 도면 단위와 표시 단위를 확인하세요.');
        return {unit:value.unit,mmPerUnit:value.mmPerUnit};
    }
    function lengthInputScale(settings) {
        const m=measurementSettings(settings);return m?m.mmPerUnit/lengthUnits[m.unit==='ft-in'?'ft':m.unit]:1;
    }
    function formatLength(value,settings) {
        const m=measurementSettings(settings);
        if(!Number.isFinite(value))throw Error('유효한 거리를 입력하세요.');
        if(!m)return value.toFixed(3);
        if(m.unit==='ft-in'){
            // Round total inches first so 11.9999 inches carries into the next foot.
            const inches=Math.round(Math.abs(value)*m.mmPerUnit/25.4*1000)/1000;
            const feet=Math.floor(inches/12),remainder=+(inches-feet*12).toFixed(3);
            return (value<0&&inches?'-':'')+feet+"' "+remainder+'"';
        }
        return (value*lengthInputScale(m)).toFixed(3)+' '+m.unit;
    }
    function newDimension(a,b,placement,options={}){
        if(options.measurement!=null)options={...options,measurement:measurementSettings(options.measurement)};
        if(![a.x,a.y,b.x,b.y,placement.x,placement.y].every(Number.isFinite)||distance(a,b)<1e-10)throw Error('서로 다른 두 점을 지정하세요.');
        const mode=options.mode||'aligned',height=options.height??2.5; if(options.axis&&(![options.axis.x,options.axis.y].every(Number.isFinite)||Math.abs(Math.hypot(options.axis.x,options.axis.y)-1)>1e-8))throw Error('치수 기준 축이 잘못되었습니다.');if(options.textPosition&&![options.textPosition.x,options.textPosition.y].every(Number.isFinite))throw Error('치수 문자 위치가 잘못되었습니다.');if(!['aligned','horizontal','vertical'].includes(mode)||!Number.isFinite(height)||height<=0||(mode==='horizontal'&&Math.abs(a.x-b.x)<1e-10)||(mode==='vertical'&&Math.abs(a.y-b.y)<1e-10))throw Error('치수 방향·문자 크기·두 점을 확인하세요.');
        return newEntity('ERP_DIMENSION',{p:{...a},q:{...b},placement:{...placement},textPosition:null,mode:options.mode||'aligned',height:options.height||2.5,color:options.color??0xe2e8f0,lineWeight:25,lineType:'BYLAYER',groupId:uid(),...options},options.layer||'0');
    }
    function dimensionLayout(e){
        const delta={x:e.q.x-e.p.x,y:e.q.y-e.p.y},len=Math.hypot(delta.x,delta.y),u=e.axis|| (e.mode==='horizontal'?{x:1,y:0}:e.mode==='vertical'?{x:0,y:1}:{x:delta.x/len,y:delta.y/len}),n={x:-u.y,y:u.x};
        const offset=(e.placement.x-e.p.x)*n.x+(e.placement.y-e.p.y)*n.y,value=Math.abs(delta.x*u.x+delta.y*u.y);
        const a={x:e.p.x+n.x*offset,y:e.p.y+n.y*offset},b={x:a.x+u.x*(delta.x*u.x+delta.y*u.y),y:a.y+u.y*(delta.x*u.x+delta.y*u.y)};
        const text=e.textPosition||{x:(a.x+b.x)/2+n.x*e.height*.5,y:(a.y+b.y)/2+n.y*e.height*.5};
        return {a,b,text,value,label:formatLength(value,e.measurement),angle:Math.atan2(u.y,u.x)*180/Math.PI,u,n};
    }
    function dimensionParts(e){
        const l=dimensionLayout(e),length=e.height*.3,tick=p=>({p:{x:p.x-(l.u.x+l.n.x)*length,y:p.y-(l.u.y+l.n.y)*length},q:{x:p.x+(l.u.x+l.n.x)*length,y:p.y+(l.u.y+l.n.y)*length}});
        const shapes=[['LINE',{p:e.p,q:l.a}],['LINE',{p:e.q,q:l.b}],['LINE',{p:l.a,q:l.b}],['LINE',tick(l.a)],['LINE',tick(l.b)],['TEXT',{p:l.text,text:l.label,height:e.height,angle:l.angle}]];
        return shapes.map(([type,shape],index)=>{
            const payload=JSON.stringify({...(e.measurement?{measurement:measurementSettings(e.measurement)}:{}),v:1,group:e.groupId,part:index,a:e.p,b:e.q,placement:e.placement,textPosition:e.textPosition||null,mode:e.mode,axis:e.axis||null,height:e.height,color:e.color,lineWeight:e.lineWeight??25,lineType:e.lineType||'BYLAYER',layer:e.layer}).replace(/[^\x00-\x7f]/g,character=>'\\u'+character.charCodeAt(0).toString(16).padStart(4,'0'));
            let raw=e.rawParts?.[index]?clone(e.rawParts[index]):null;
            if(raw){const at=raw.findIndex(p=>p[0]===1001&&p[1]==='ERP_CAD_DIM');if(at>=0){let end=at+1;while(end<raw.length&&raw[end][0]!==1001)end++;raw.splice(at,end-at);}}
            if(!raw)raw=[[0,type],[100,'AcDbEntity'],[8,e.layer],[100,type==='LINE'?'AcDbLine':'AcDbText']];
            raw.push([1001,'ERP_CAD_DIM']);for(let i=0;i<payload.length;i+=200)raw.push([1000,payload.slice(i,i+200)]);
            return newEntity(type,{...shape,id:e.id+'-'+index,color:e.color,lineWeight:e.lineWeight??25,lineType:e.lineType||'BYLAYER',raw,dirty:true,hidden:e.hidden,sketchGroup:e.sketchGroup,sketchLocked:e.sketchLocked},e.layer);
        });
    }
    function restoreDimensions(doc){
        const groups=new Map();
        for(const e of doc.entities){const at=e.raw?.findIndex(p=>p[0]===1001&&p[1]==='ERP_CAD_DIM');if(at===undefined||at<0)continue;let raw='';for(let i=at+1;i<e.raw.length&&e.raw[i][0]!==1001;i++)if(e.raw[i][0]===1000)raw+=e.raw[i][1];try{const m=JSON.parse(raw.replace(/\\U\+([0-9a-f]{4})/gi,(_,h)=>String.fromCharCode(parseInt(h,16))));if(m.v!==1||typeof m.group!=='string')continue;const entries=groups.get(m.group)||[];entries.push({e,m});groups.set(m.group,entries);}catch(_){}}
        const replace=new Map(),remove=new Set();
        for(const [group,entries]of groups){try{
            if(entries.length!==6||new Set(entries.map(x=>x.m.part)).size!==6||entries.some(x=>!Number.isInteger(x.m.part)||x.m.part<0||x.m.part>5))continue;
            entries.sort((a,b)=>a.m.part-b.m.part);const m=entries[0].m;
            if(!['aligned','horizontal','vertical'].includes(m.mode)||!Number.isFinite(m.height)||m.height<=0||entries.some(x=>JSON.stringify({...x.m,part:0})!==JSON.stringify({...m,part:0})))continue;
            const e=newDimension(m.a,m.b,m.placement,{id:'dim-'+group,groupId:group,measurement:m.measurement,mode:m.mode,axis:m.axis||undefined,textPosition:m.textPosition,height:m.height,color:m.color,lineWeight:m.lineWeight,lineType:m.lineType||'BYLAYER',layer:m.layer});
            const expected=dimensionParts(e),near=(a,b)=>Number.isFinite(a)&&Number.isFinite(b)&&Math.abs(a-b)<=Math.max(1,Math.abs(a),Math.abs(b))*1e-8;
            if(entries.some(({e:actual},i)=>{const wanted=expected[i];return !actual.editable||actual.type!==wanted.type||actual.layer!==wanted.layer||actual.color!==wanted.color||actual.lineWeight!==wanted.lineWeight||canonicalLineType(actual.lineType)!==canonicalLineType(wanted.lineType)||!near(actual.p.x,wanted.p.x)||!near(actual.p.y,wanted.p.y)||(actual.type==='LINE'? !near(actual.q.x,wanted.q.x)||!near(actual.q.y,wanted.q.y):actual.text!==wanted.text||!near(actual.height,wanted.height)||Math.abs(((actual.angle-wanted.angle+180)%360+360)%360-180)>1e-8);}))continue;
            if(entries.some(x=>x.e.sketchGroup!==entries[0].e.sketchGroup||x.e.sketchLocked!==entries[0].e.sketchLocked||x.e.hidden!==entries[0].e.hidden))continue;
            Object.assign(e,{hidden:entries[0].e.hidden,sketchGroup:entries[0].e.sketchGroup,sketchLocked:entries[0].e.sketchLocked});
            e.dirty=false;e.rawParts=entries.map(x=>x.e.raw);replace.set(entries[0].e.id,e);entries.slice(1).forEach(x=>remove.add(x.e.id));
        }catch(_){}}
        doc.entities=doc.entities.filter(e=>!remove.has(e.id)).map(e=>replace.get(e.id)||e);return doc;
    }
    function snapPoints(e){
        if(e.type==='INSERT')return (e.preview||[]).flatMap(path=>path.length?[path[0],path.at(-1)]:[]);
        if(e.type==='ERP_DIMENSION')return [e.p,e.q];
        if(e.points)return e.points;
        if(e.type==='LINE')return [e.p,e.q,{x:(e.p.x+e.q.x)/2,y:(e.p.y+e.q.y)/2}];
        if(!['TEXT','MTEXT','POINT','CIRCLE','ARC','ELLIPSE'].includes(e.type))return [];
        if(e.type==='ARC'){const path=geometry(e);return [e.p,path[0],path.at(-1)].filter(Boolean);}
        return e.p?[e.p]:[];
    }

    function planarXY(e) {
        if(e.sourcePlanarXY===false)return false;
        const r=e.raw||[];
        if(num(r,39)!==0||num(r,210)!==0||num(r,220)!==0||num(r,230,1)!==1)return false;
        if(e.type==='LINE')return num(r,30)===num(r,31);
        if(e.type==='TEXT'&&(num(r,72)||num(r,73))&&r.some(p=>p[0]===11))return num(r,30)===num(r,31);
        return e.type!=='MTEXT'||num(r,31)===0;
    }
    function allows(e,operation){return !!e&&(e.operations?e.operations.includes(operation):!!e.editable);}

    // Native DIMENSIONs carry defining points and a separate anonymous graphics
    // block. Changing only the defining points leaves stale graphics in DWG.
    // Keep the native entity and style data; regenerate a private block on save.
    function ndSet(raw, code, value) {
        const end=raw.findIndex(p=>p[0]===1001),limit=end<0?raw.length:end;
        const index=raw.findIndex((p,i)=>i<limit&&p[0]===code);
        if(index>=0)raw[index]=[code,String(value)];
        else raw.splice(limit,0,[code,String(value)]);
    }
    function ndPoint(raw,code,p,z){ndSet(raw,code,p.x);ndSet(raw,code+10,p.y);if(z!==undefined)ndSet(raw,code+20,z);}
    const ndIndexes=new WeakMap();
    function ndIndex(prefix){
        let index=ndIndexes.get(prefix);if(index)return index;
        index={styles:new Map(),fonts:new Map(),blockNames:new Map(),blocks:new Map()};let block=null;
        for(let i=0;i<prefix.length;i++)if(prefix[i][0]===0){let end=i+1;while(end<prefix.length&&prefix[end][0]!==0)end++;const r=prefix.slice(i,end),type=r[0][1];
            if(type==='DIMSTYLE')index.styles.set(String(val(r,2)).toUpperCase(),r);
            if(type==='STYLE')index.fonts.set(String(val(r,5)).toUpperCase(),val(r,2,'Standard'));
            if(type==='BLOCK_RECORD')index.blockNames.set(String(val(r,5)).toUpperCase(),val(r,2));
            if(type==='BLOCK'){block={name:val(r,2),base:pt(r),raw:[],safe:(num(r,70)&~1)===0};index.blocks.set(block.name,block);}
            else if(type==='ENDBLK')block=null;
            else if(block){block.raw.push(r);if(!['LINE','CIRCLE','ARC','SOLID','TRACE','LWPOLYLINE'].includes(type)||r.some(p=>[102,360,1001].includes(p[0]))||num(r,210)!==0||num(r,220)!==0||num(r,230,1)!==1||num(r,39)!==0||block.raw.length>128)block.safe=false;}
            i=end-1;
        }
        ndIndexes.set(prefix,index);return index;
    }
    function ndStyle(doc,raw) {
        const index=ndIndex(doc.prefix),style=index.styles.get(String(val(raw,3,'Standard')).toUpperCase())||[];
        const values={40:1,41:2.5,42:.625,44:1.25,140:2.5,142:0,144:1,147:.625,271:2,277:2,278:46,176:0,177:0,178:0};
        for(const [code,value]of style)if(code!==100&&code!==1001)values[code]=value;
        let at=raw.findIndex((p,i)=>p[0]===1000&&p[1]==='DSTYLE'&&raw.slice(0,i).some(p=>p[0]===1001&&p[1]==='ACAD'));
        if(at>=0){at++;if(raw[at]?.[0]===1002&&raw[at][1]==='{')at++;for(;at+1<raw.length&&raw[at][0]!==1001;at++){if(raw[at][0]===1002&&raw[at][1]==='}')break;if(raw[at][0]===1070){values[Number(raw[at][1])]=raw[at+1][1];at++;}}}
        const textStyle=index.fonts.get(String(values[340]||'').toUpperCase())||'Standard';
        const arrow=code=>{const handle=String(values[code]||'0').toUpperCase();if(handle==='0')return null;const name=index.blockNames.get(handle);return index.blocks.get(name)||{safe:false};};
        const arrows=Number(values[173])?[arrow(343)||arrow(342),arrow(344)||arrow(342)]:[arrow(342),arrow(342)];
        return {values,textStyle,arrows};
    }
    function ndOverride(raw,updates){
        let app=raw.findIndex(p=>p[0]===1001&&p[1]==='ACAD');
        if(app<0){raw.push([1001,'ACAD']);app=raw.length-1;}
        let end=app+1;while(end<raw.length&&raw[end][0]!==1001)end++;
        let start=raw.findIndex((p,i)=>i>app&&i<end&&p[0]===1000&&p[1]==='DSTYLE');
        if(start<0){raw.splice(end,0,[1000,'DSTYLE'],[1002,'{'],[1002,'}']);start=end;end+=3;}
        let close=start+2;while(close<end&&!(raw[close][0]===1002&&raw[close][1]==='}'))close++;
        if(close===end)throw Error('치수 스타일 재정의가 잘못되었습니다.');
        for(const [code,value]of Object.entries(updates)){
            let found=false;for(let i=start+2;i<close-1;i++)if(raw[i][0]===1070&&Number(raw[i][1])===Number(code)){raw[i+1]=[Number(code)>=170&&Number(code)<=289?1070:1040,String(value)];found=true;break;}
            if(!found){raw.splice(close,0,[1070,String(code)],[Number(code)>=170&&Number(code)<=289?1070:1040,String(value)]);close+=2;}
        }
    }
    function nativeDimensionPlainText(value){return !/[{}\\\r\n]/.test(decodeUnicode(value??'').replace(/\\[XP]/g,''));}
    function nativeDimensionParse(e,doc){
        if(e.type!=='DIMENSION'||e.nativeDimension)return e;
        const raw=e.raw||[],kind=num(raw,70)&7,style=ndStyle(doc,raw),values=style.values;
        const has=code=>raw.some(p=>p[0]===code),a=pt(raw,13),b=pt(raw,14),z=num(raw,30),restricted=raw.some(p=>[102,360].includes(p[0]));
        const planar=num(raw,210)===0&&num(raw,220)===0&&num(raw,230,1)===1&&[31,33,34].every(c=>!has(c)||Math.abs(num(raw,c)-z)<1e-7);
        const finiteStyle=[40,41,42,44,140,142,143,144,147,171,271,273,277].every(c=>values[c]===undefined||Number.isFinite(Number(values[c])));
        const textSafe=[val(raw,1),values[3],values[4]].every(nativeDimensionPlainText);
        const compatible=textSafe&&[0,1].includes(kind)&&planar&&finiteStyle&&!Number(values[71]||0)&&!Number(values[72]||0)&&num(raw,52)===0&&style.arrows.every(arrow=>!arrow||arrow.safe)&&[10,20,13,23,14,24].every(has)&&distance(a,b)>1e-10&&![...Object.values(a),...Object.values(b),...Object.values(pt(raw)),num(raw,50)].some(v=>!Number.isFinite(v));
        if(!compatible){e.nativeDimension={compatible:false,reason:textSafe?'지원하는 2D 선형·정렬 치수만 편집할 수 있습니다.':'복잡한 문자 서식이 있는 치수는 원본 서식을 보존하여 읽기 전용으로 표시합니다.'};return e;}
        const factor=Number(values[40])>0?Number(values[40]):1,height=Number(values[140])*factor;
        e.nativeDimension={compatible:true,kind,a,b,z,zs:{31:num(raw,31,z),33:num(raw,33,z),34:num(raw,34,z)},angle:num(raw,50),values,textStyle:style.textStyle,arrows:style.arrows,scaleFactor:factor,associated:restricted,manualText:!!(num(raw,70)&128),originalHeight:height>0?height:2.5};
        e.text=decodeUnicode(val(raw,1,'<>'))||'<>';e.height=height>0?height:2.5;e.textPosition=has(11)&&has(21)?pt(raw,11):null;
        e.nativeDimension.operations=restricted?['style','text','resize','layer']:['move','copy','rotate','scale','resize','style','text','delete','layer'];
        const apps=raw.map((p,i)=>p[0]===1001?i:-1).filter(i=>i>=0);
        if(apps.some(i=>!(raw[i][1]==='ERP_CAD_EDIT'&&readSketchMetadata(raw))&&(raw[i][1]!=='ACAD'||raw[i+1]?.[0]!==1000||raw[i+1]?.[1]!=='DSTYLE')))e.nativeDimension.operations=e.nativeDimension.operations.filter(op=>op!=='copy');
        return e;
    }
    function nativeDimensionLayout(e){
        const d=e.nativeDimension;if(!d?.compatible)throw Error('지원하지 않는 치수입니다.');
        const delta={x:d.b.x-d.a.x,y:d.b.y-d.a.y},length=Math.hypot(delta.x,delta.y),angle=d.kind===0?d.angle*Math.PI/180:Math.atan2(delta.y,delta.x),u={x:Math.cos(angle),y:Math.sin(angle)},n={x:-Math.sin(angle),y:Math.cos(angle)};
        const offset=(e.p.x-d.b.x)*n.x+(e.p.y-d.b.y)*n.y,firstOffset=(e.p.x-d.a.x)*n.x+(e.p.y-d.a.y)*n.y,a={x:d.a.x+n.x*firstOffset,y:d.a.y+n.y*firstOffset},b={x:d.b.x+n.x*offset,y:d.b.y+n.y*offset};
        const value=d.kind===1?length:Math.abs(delta.x*u.x+delta.y*u.y),gap=Number(d.values[147]||0)*d.scaleFactor;
        const text=e.textPosition||{x:(a.x+b.x)/2+n.x*(gap+e.height/2),y:(a.y+b.y)/2+n.y*(gap+e.height/2)};
        return {a,b,u,n,value,offset,text,angle:angle*180/Math.PI,label:ndLabel(e,value)};
    }
    function ndFormat(value,unit,precision,zero=0,separator=46,round=0){
        const decimals=Math.max(0,Math.min(8,precision)),actual=round>0?Math.round(value/round)*round:value;
        let text;if(unit===1)text=actual.toExponential(decimals);else if(unit===3||unit===4){let inches=Math.abs(actual),feet=Math.floor(inches/12),remaining=inches-feet*12;if(unit===3){remaining=Number(remaining.toFixed(decimals));if(remaining>=12){feet++;remaining=0;}text=`${actual<0?'-':''}${feet}'-${remaining.toFixed(decimals)}\"`;}else{const denominator=2**decimals,total=Math.round(inches*denominator),whole=Math.floor(total/denominator);feet=Math.floor(whole/12);const inch=whole%12;let numerator=total%denominator,denom=denominator;while(numerator&&numerator%2===0){numerator/=2;denom/=2;}text=`${actual<0?'-':''}${feet}'-${inch}${numerator?' '+numerator+'/'+denom:''}\"`;}}
        else if(unit===5){const denom=2**decimals,total=Math.round(Math.abs(actual)*denom),whole=Math.floor(total/denom);let numerator=total%denom,d=denom;while(numerator&&numerator%2===0){numerator/=2;d/=2;}text=(actual<0?'-':'')+whole+(numerator?' '+numerator+'/'+d:'');}
        else{ text=actual.toFixed(decimals);if(zero&8)text=text.replace(/(\.\d*?)0+$/,'$1').replace(/\.$/,'');if(zero&4)text=text.replace(/^(-?)0\./,'$1.');if(separator!==46&&separator>0)text=text.replace('.',String.fromCharCode(separator));}
        return text;
    }
    function ndLabel(e,measurement){
        const v=e.nativeDimension.values,value=measurement*Number(v[144]??1),separator=Number(v[278]||46);
        let text=ndFormat(value,Number(v[277]||2),Number(v[271]??2),Number(v[78]||0),separator,Number(v[45]||0));
        const post=decodeUnicode(v[3]||'<>');text=post.includes('<>')?post.replace(/<>/g,text):post+text;
        if(Number(v[170])){const alternate=ndFormat(value*Number(v[143]??25.4),Number(v[273]||2),Number(v[171]??3),Number(v[285]||0),separator,Number(v[148]||0)),post=decodeUnicode(v[4]||'[]');text+=post.includes('[]')?post.replace('[]','['+alternate+']'):post.includes('<>')?'['+post.replace(/<>/g,alternate)+']':'['+alternate+post+']';}
        const override=String(e.text??'<>');return override===' '?'' : (override||'<>').replace(/<>/g,text).replace(/\\[XP]/g,'\n');
    }
    function ndArrowParts(definition,tip,angle,scale,properties){
        const radians=angle*Math.PI/180,move=p=>({x:tip.x+(p.x-definition.base.x)*scale*Math.cos(radians)-(p.y-definition.base.y)*scale*Math.sin(radians),y:tip.y+(p.x-definition.base.x)*scale*Math.sin(radians)+(p.y-definition.base.y)*scale*Math.cos(radians)}),parts=[];
        for(const raw of definition.raw){
            const type=raw[0][1],layer=val(raw,8,'0'),index=num(raw,62,0),part={type,p:pt(raw),layer:layer==='0'?properties.layer:layer,color:raw.some(p=>p[0]===420)?num(raw,420):index===0?properties.color:null,aci:index===0?properties.aci:index,lineWeight:num(raw,370,-2)===-2?properties.lineWeight:num(raw,370,-1)};
            if(type==='LINE')part.q=pt(raw,11);
            if(type==='CIRCLE'||type==='ARC'){part.radius=num(raw,40);part.start=num(raw,50);part.end=num(raw,51);}
            if(type==='LWPOLYLINE'){part.points=[];for(let i=0;i<raw.length;i++)if(raw[i][0]===10){let end=i+1;while(end<raw.length&&raw[end][0]!==10)end++;const record=raw.slice(i,end);part.points.push({...pt(record),bulge:num(record,42)});i=end-1;}part.closed=!!(num(raw,70)&1);}
            let path;if(['SOLID','TRACE'].includes(type)){path=[pt(raw),pt(raw,11),pt(raw,13),pt(raw,12),pt(raw)];part.solid=true;}else if(type==='LWPOLYLINE'&&g.CADFidelity)path=g.CADFidelity.polyline(part.points,part.closed);else path=geometry(part);
            part.displayGeometry=path.map(move);part.p=move(part.p);if(part.q)part.q=move(part.q);if(part.points)part.points=part.points.map(move);if(part.radius)part.radius*=scale;if(type==='ARC'){part.start+=angle;part.end+=angle;}
            part.nativeArrowSkip=parts.length>0;if(!parts.length)part.nativeArrow={name:definition.name,p:tip,angle,scale};parts.push(part);
        }
        return parts;
    }
    function nativeDimensionParts(e){
        if(!e.nativeDimension?.compatible)return e.renderParts||[];
        const d=e.nativeDimension,l=nativeDimensionLayout(e),scale=d.scaleFactor,values=d.values,parts=[];
        const color=code=>e.color??(Number(values[code])>0&&Number(values[code])<256?parseInt(aci(Number(values[code])).slice(1),16):null);
        const line=(p,q,code=176)=>parts.push({type:'LINE',p:{...p},q:{...q},layer:e.layer,color:color(code),aci:e.aci??256,lineWeight:e.lineWeight,lineType:e.lineType||'BYLAYER'});
        const offset=Number(values[42]||0)*scale,extension=Number(values[44]||0)*scale,sign=l.offset<0?-1:1;
        if(!Number(values[75]||0))line({x:d.a.x+l.n.x*offset*sign,y:d.a.y+l.n.y*offset*sign},{x:l.a.x+l.n.x*extension*sign,y:l.a.y+l.n.y*extension*sign},177);
        if(!Number(values[76]||0))line({x:d.b.x+l.n.x*offset*sign,y:d.b.y+l.n.y*offset*sign},{x:l.b.x+l.n.x*extension*sign,y:l.b.y+l.n.y*extension*sign},177);
        if(!Number(values[281]||0)||!Number(values[282]||0))line(l.a,l.b);
        const tick=Number(values[142]||0)*scale,arrow=Number(values[41]||2.5)*scale;
        for(const [p,direction,suppress,arrowDefinition]of [[l.a,1,Number(values[281]||0),d.arrows?.[0]],[l.b,-1,Number(values[282]||0),d.arrows?.[1]]]){
            if(suppress)continue;
            if(tick>0)line({x:p.x-(l.u.x+l.n.x)*tick,y:p.y-(l.u.y+l.n.y)*tick},{x:p.x+(l.u.x+l.n.x)*tick,y:p.y+(l.u.y+l.n.y)*tick});
            else if(arrowDefinition)parts.push(...ndArrowParts(arrowDefinition,p,l.angle+(direction===1?180:0),arrow,{layer:e.layer,color:color(176),aci:e.aci??256,lineWeight:e.lineWeight}));
            else{const back={x:p.x+l.u.x*arrow*direction,y:p.y+l.u.y*arrow*direction},p1={x:back.x+l.n.x*arrow/6,y:back.y+l.n.y*arrow/6},p2={x:back.x-l.n.x*arrow/6,y:back.y-l.n.y*arrow/6};parts.push({type:'SOLID',p:{...p},points:[{...p},p1,p2],displayGeometry:[{...p},p1,p2,{...p}],solid:true,layer:e.layer,color:color(176),aci:e.aci??256,lineWeight:e.lineWeight});}
        }
        if(l.label)parts.push({type:'MTEXT',p:{...l.text},text:l.label,height:e.height,width:0,angle:l.angle,attachment:5,textAlign:'center',textBaseline:'middle',plainText:true,layer:e.layer,color:color(178),aci:e.aci??256,lineWeight:e.lineWeight,fontStyle:d.textStyle});
        return parts;
    }
    function nativeDimensionGrips(e){
        if(!e.nativeDimension?.compatible)return [];
        const l=nativeDimensionLayout(e),out=[{key:'dimension-line',point:{x:l.a.x+(l.b.x-l.a.x)/3,y:l.a.y+(l.b.y-l.a.y)/3}},{key:'dimension-text',point:l.text}];
        if(!e.nativeDimension.associated)out.push({key:'dimension-start',point:e.nativeDimension.a},{key:'dimension-end',point:e.nativeDimension.b});
        return out;
    }
    function nativeDimensionRefresh(e){delete e.displayGeometry;delete e.preview;e.renderParts=nativeDimensionParts(e);e.dirty=true;return e;}
    function nativeDimensionResize(e,key,p){
        if(!e.nativeDimension?.compatible||![p.x,p.y].every(Number.isFinite))throw Error('유효한 치수 손잡이 위치가 필요합니다.');
        const n=clone(e),before=nativeDimensionLayout(n);
        if(key==='dimension-line'){
            const amount=(p.x-before.b.x)*before.n.x+(p.y-before.b.y)*before.n.y;n.p={x:n.p.x+before.n.x*amount,y:n.p.y+before.n.y*amount};
            if(n.textPosition&&!n.nativeDimension.manualText)n.textPosition={x:n.textPosition.x+before.n.x*amount,y:n.textPosition.y+before.n.y*amount};
        }else if(key==='dimension-text'){n.textPosition={...p};n.nativeDimension.manualText=true;}
        else if(['dimension-start','dimension-end'].includes(key)&&!n.nativeDimension.associated){n.nativeDimension[key==='dimension-start'?'a':'b']={...p};if(distance(n.nativeDimension.a,n.nativeDimension.b)<1e-10)throw Error('치수 측정점은 서로 달라야 합니다.');if(!n.nativeDimension.manualText)n.textPosition=null;}
        else throw Error('연관 치수의 측정점은 원본 형상을 보존합니다. 치수선이나 문자 위치를 조절하세요.');
        return nativeDimensionRefresh(n);
    }
    function nativeDimensionTransform(e,o){
        if(!e.nativeDimension?.compatible||e.nativeDimension.associated||o.mirror)throw Error('이 치수는 치수선·문자 위치와 속성만 수정할 수 있습니다.');
        const n=clone(e),angle=(o.angle||0)*Math.PI/180,s=o.scale??1,c=o.center||{x:0,y:0};
        if(![angle,s,c.x,c.y,o.dx??0,o.dy??0].every(Number.isFinite)||s<=0)throw Error('치수 변환 수치를 확인하세요.');
        const move=p=>({x:c.x+(p.x-c.x)*s*Math.cos(angle)-(p.y-c.y)*s*Math.sin(angle)+(o.dx||0),y:c.y+(p.x-c.x)*s*Math.sin(angle)+(p.y-c.y)*s*Math.cos(angle)+(o.dy||0)});
        n.p=move(n.p);n.nativeDimension.a=move(n.nativeDimension.a);n.nativeDimension.b=move(n.nativeDimension.b);if(n.textPosition)n.textPosition=move(n.textPosition);n.nativeDimension.angle+=(o.angle||0);n.height*=s;n.nativeDimension.scaleFactor*=s;
        return nativeDimensionRefresh(n);
    }
    function ndSectionEnd(prefix,name){const start=prefix.findIndex((p,i)=>p[0]===0&&p[1]==='SECTION'&&prefix[i+1]?.[1]===name);if(start<0)return -1;let end=start+2;while(end<prefix.length&&!(prefix[end][0]===0&&prefix[end][1]==='ENDSEC'))end++;return end;}
    function nativeDimensionDocument(source){
        const dirty=source.entities.filter(e=>e.type==='DIMENSION'&&e.dirty&&e.nativeDimension?.compatible);if(!dirty.length)return source;
        const doc={...source,prefix:clone(source.prefix),entities:[...source.entities]};let max=0;
        for(const records of [doc.prefix,source.suffix,...source.entities.map(e=>e.raw||[])])for(const [code,value]of records)if(code===5||code===105){const handle=parseInt(value,16);if(Number.isFinite(handle))max=Math.max(max,handle);}
        const next=()=>(++max).toString(16).toUpperCase();
        if(ndSectionEnd(doc.prefix,'TABLES')<0)doc.prefix.splice(doc.prefix.length-2,0,[0,'SECTION'],[2,'TABLES'],[0,'ENDSEC']);
        if(ndSectionEnd(doc.prefix,'BLOCKS')<0)doc.prefix.splice(doc.prefix.length-2,0,[0,'SECTION'],[2,'BLOCKS'],[0,'ENDSEC']);
        let table=doc.prefix.findIndex((p,i)=>p[0]===0&&p[1]==='TABLE'&&doc.prefix[i+1]?.[1]==='BLOCK_RECORD');
        if(table<0){const end=ndSectionEnd(doc.prefix,'TABLES');doc.prefix.splice(end,0,[0,'TABLE'],[2,'BLOCK_RECORD'],[5,next()],[100,'AcDbSymbolTable'],[70,'0'],[0,'ENDTAB']);table=end;}
        const tableHead=doc.prefix.slice(table,table+6),tableHandle=val(tableHead,5),blockRecords=[],blocks=[];
        for(const e of dirty){
            if(!nativeDimensionPlainText(e.text))throw Error('치수 문자는 일반 문자와 <> 측정값 기호를 사용하세요. CAD 서식 코드·중괄호·줄바꿈은 지원하지 않습니다.');
            const d=e.nativeDimension,l=nativeDimensionLayout(e),recordHandle=next(),name='*D_ERP_'+recordHandle;
            blockRecords.push([0,'BLOCK_RECORD'],[5,recordHandle],[330,tableHandle],[100,'AcDbSymbolTableRecord'],[100,'AcDbBlockTableRecord'],[2,name],[70,'0'],[280,'1'],[281,'0']);
            blocks.push([0,'BLOCK'],[5,next()],[330,recordHandle],[100,'AcDbEntity'],[8,e.layer],[100,'AcDbBlockBegin'],[2,name],[70,'1'],[10,'0'],[20,'0'],[30,'0'],[3,name],[1,'']);
            for(const part of nativeDimensionParts(e)){
                if(part.nativeArrowSkip)continue;
                const raw=[[0,part.nativeArrow?'INSERT':part.type],[5,next()],[330,recordHandle],[100,'AcDbEntity'],[8,part.layer],[62,part.aci??256]];
                if(part.color!==null&&part.color!==undefined)raw.push([420,part.color]);if(part.lineWeight!==undefined)raw.push([370,part.lineWeight]);
                if(part.nativeArrow){const arrow=part.nativeArrow;raw.push([100,'AcDbBlockReference'],[2,arrow.name]);ndPoint(raw,10,arrow.p,d.z);raw.push([41,arrow.scale],[42,arrow.scale],[43,arrow.scale],[50,arrow.angle]);}
                else if(part.type==='LINE'){raw.push([100,'AcDbLine']);ndPoint(raw,10,part.p,d.z);ndPoint(raw,11,part.q,d.z);}
                else if(part.type==='SOLID'){raw.push([100,'AcDbTrace']);ndPoint(raw,10,part.points[0],d.z);ndPoint(raw,11,part.points[1],d.z);ndPoint(raw,12,part.points[2],d.z);ndPoint(raw,13,part.points[2],d.z);}
                else if(part.type==='MTEXT'){raw.push([100,'AcDbMText']);ndPoint(raw,10,part.p,d.z);const text=encodeMText(part.text),encoded=doc.acadVersion<1021?text.replace(/[^\x00-\x7f]/g,c=>'\\U+'+c.charCodeAt(0).toString(16).toUpperCase().padStart(4,'0')):text,chunks=encoded.match(/[\s\S]{1,200}/g)||[''];raw.push([40,part.height],[41,0],[71,5],[72,1]);for(const chunk of chunks.slice(0,-1))raw.push([3,chunk]);raw.push([1,chunks.at(-1)],[7,d.textStyle],[11,Math.cos(part.angle*Math.PI/180)],[21,Math.sin(part.angle*Math.PI/180)],[31,0]);}
                for(const pair of raw)blocks.push([pair[0],String(pair[1])]);
            }
            blocks.push([0,'ENDBLK'],[5,next()],[330,recordHandle],[100,'AcDbEntity'],[8,e.layer],[100,'AcDbBlockEnd']);
            // Code 50 is specific to rotated linear dimensions. ACadSharp (and
            // native CAD readers) treats its presence as a linear subtype cue.
            const raw=clone(e.raw||[]).filter(pair=>d.kind!==1||pair[0]!==50);ndSet(raw,2,name);ndPoint(raw,10,e.p,d.z);ndPoint(raw,11,l.text,d.zs?.[31]??d.z);ndPoint(raw,13,d.a,d.zs?.[33]??d.z);ndPoint(raw,14,d.b,d.zs?.[34]??d.z);
            ndSet(raw,70,(num(raw,70)|32)|(d.manualText?128:0));if(d.kind===0)ndSet(raw,50,d.angle);ndSet(raw,42,l.value);ndSet(raw,1,e.text??'<>');ndSet(raw,8,e.layer);if(e.color!==undefined&&e.color!==null)ndSet(raw,420,e.color);if(e.lineWeight!==undefined)ndSet(raw,370,e.lineWeight);if(e.lineType)ndSet(raw,6,e.lineType);
            if(!raw.some(p=>p[0]===5))raw.splice(1,0,[5,next()]);ndOverride(raw,{40:d.scaleFactor,140:e.height/d.scaleFactor});
            const n={...e,raw,dirty:false};doc.entities[doc.entities.indexOf(e)]=n;
        }
        let tableEnd=table+2;while(tableEnd<doc.prefix.length&&!(doc.prefix[tableEnd][0]===0&&doc.prefix[tableEnd][1]==='ENDTAB'))tableEnd++;
        doc.prefix=doc.prefix.slice(0,tableEnd).concat(blockRecords,doc.prefix.slice(tableEnd));
        const count=doc.prefix.slice(table,table+8).find(p=>p[0]===70);if(count)count[1]=String(Number(count[1])+dirty.length);
        const blocksEnd=ndSectionEnd(doc.prefix,'BLOCKS');doc.prefix=doc.prefix.slice(0,blocksEnd).concat(blocks,doc.prefix.slice(blocksEnd));
        const seed=doc.prefix.findIndex(p=>p[0]===9&&p[1]==='$HANDSEED');if(seed>=0&&doc.prefix[seed+1]?.[0]===5)doc.prefix[seed+1][1]=next();
        return doc;
    }

    // Native HATCH records remain authoritative; index coordinate fields instead of
    // rebuilding boundaries from their tessellated presentation.
    function hatchFields(raw) {
        let cursor=raw.findIndex(p=>p[0]===91);if(cursor<0)throw Error('해치 경계 정보가 없습니다.');
        const points=[],vectors=[],lengths=[],angles=[];let hasSources=false;
        const take=(code,optional=false)=>{if(raw[cursor]?.[0]!==code){if(optional)return null;throw Error('지원하지 않는 해치 경계 구조입니다.');}return cursor++;};
        const pair=(code,vector=false)=>{const x=take(code),y=take(code+10);(vector?vectors:points).push([x,y]);};
        const count=Number(raw[cursor++][1]);if(count<1||count>256)throw Error('해치 경계가 너무 복잡합니다.');
        for(let loop=0;loop<count;loop++){
            const flags=Number(raw[take(92)][1]);
            if(flags&2){const bulge=Number(raw[take(72)][1]);take(73);const n=Number(raw[take(93)][1]);if(n>2048)throw Error('해치 경계가 너무 복잡합니다.');for(let j=0;j<n;j++){pair(10);if(bulge)take(42,true);}}
            else{const n=Number(raw[take(93)][1]);if(n>2048)throw Error('해치 경계가 너무 복잡합니다.');for(let j=0;j<n;j++){const type=Number(raw[take(72)][1]);if(type===1){pair(10);pair(11);}else if(type===2){pair(10);lengths.push(take(40));angles.push(take(50),take(51));take(73);}else if(type===3){pair(10);pair(11,true);take(40);take(50);take(51);take(73);}else throw Error('스플라인 해치의 형상 변경은 아직 지원하지 않습니다.');}}
            const n=take(97,true);if(n!==null){const sources=Number(raw[n][1]);hasSources ||= sources>0;if(sources>4096)throw Error('해치 참조가 너무 많습니다.');for(let j=0;j<sources;j++)take(330);}
        }
        for(let i=cursor;i<raw.length&&raw[i][0]!==1001;i++){
            if(raw[i][0]===53)angles.push(i);
            if([41,49].includes(raw[i][0]))lengths.push(i);
            if(raw[i][0]===52)angles.push(i);
            if([43,45,10].includes(raw[i][0])&&raw[i+1]?.[0]===raw[i][0]+1){(raw[i][0]===45?vectors:points).push([i,i+1]);i++;}
            else if(raw[i][0]===10&&raw[i+1]?.[0]===20){points.push([i,i+1]);i++;}
        }
        return {points,vectors,lengths,angles,hasSources};
    }
    function hatchRefresh(e){
        const n={...e};n.hatch=g.CADFidelity.hatch(n.raw);n.displayGeometry=n.hatch.rings.flat();n.opacity=(num(n.raw,440,0)&0x2000000)?(num(n.raw,440)&255)/255:1;return n;
    }
    function editHatch(e,changes={}){
        if(e.type!=='HATCH'||!allows(e,'style'))throw Error('편집 가능한 해치를 선택하세요.');
        const n={...e,raw:clone(e.raw),dirty:true};
        const set=(code,value)=>{let at=n.raw.findIndex(p=>p[0]===code);if(at<0){at=n.raw.findIndex(p=>p[0]===1001);n.raw.splice(at<0?n.raw.length:at,0,[code,String(value)]);}else n.raw[at][1]=String(value);};
        if(changes.color!==undefined){if(!Number.isInteger(changes.color)||changes.color<0||changes.color>0xffffff)throw Error('해치 색상을 확인하세요.');n.color=changes.color;}
        if(changes.opacity!==undefined){if(!Number.isFinite(changes.opacity)||changes.opacity<.1||changes.opacity>1)throw Error('CAD 해치 불투명도는 10~100%입니다. 숨기려면 객체를 삭제하거나 레이어를 끄세요.');set(440,0x2000000|Math.round(changes.opacity*255));}
        if(changes.patternScale!==undefined||changes.patternAngle!==undefined){
            if(n.hatch?.gradient)throw Error('그라데이션 해치는 색·불투명도만 수정할 수 있습니다.');
            const oldScale=num(n.raw,41,1),scale=changes.patternScale??oldScale,oldAngle=num(n.raw,52),angle=changes.patternAngle??oldAngle;
            if(!Number.isFinite(scale)||scale<=0||!Number.isFinite(angle))throw Error('패턴 배율은 양수, 각도는 유효한 숫자여야 합니다.');
            const factor=scale/oldScale,rotation=(angle-oldAngle)*Math.PI/180,c=Math.cos(rotation),sin=Math.sin(rotation);
            for(let i=0;i<n.raw.length;i++){const code=n.raw[i][0];if(code===53)n.raw[i][1]=String(Number(n.raw[i][1])+angle-oldAngle);if(code===49)n.raw[i][1]=String(Number(n.raw[i][1])*factor);if([43,45].includes(code)&&n.raw[i+1]?.[0]===code+1){const x=Number(n.raw[i][1])*factor,y=Number(n.raw[i+1][1])*factor;n.raw[i][1]=String(x*c-y*sin);n.raw[i+1][1]=String(x*sin+y*c);i++;}}
            set(41,scale);set(52,angle);
        }
        return hatchRefresh(n);
    }
    function transformHatch(e,o){
        const fields=hatchFields(e.raw),n={...e,raw:clone(e.raw),dirty:true},s=o.scale??1,a=(o.angle||0)*Math.PI/180,c=o.center||{x:0,y:0};
        if(o.mirror)throw Error('해치는 대칭 변환을 지원하지 않습니다.');
        if(![s,a,c.x,c.y,o.dx||0,o.dy||0].every(Number.isFinite)||s<=0)throw Error('해치 변환 값을 확인하세요.');
        const transform=(indices,vector)=>{const x=(Number(e.raw[indices[0]][1])-(vector?0:c.x))*s,y=(Number(e.raw[indices[1]][1])-(vector?0:c.y))*s;n.raw[indices[0]][1]=String(x*Math.cos(a)-y*Math.sin(a)+(vector?0:c.x+(o.dx||0)));n.raw[indices[1]][1]=String(x*Math.sin(a)+y*Math.cos(a)+(vector?0:c.y+(o.dy||0)));};
        fields.points.forEach(p=>transform(p,false));fields.vectors.forEach(p=>transform(p,true));for(const i of fields.lengths)n.raw[i][1]=String(Number(e.raw[i][1])*s);for(const i of fields.angles)n.raw[i][1]=String(Number(e.raw[i][1])+(o.angle||0));return hatchRefresh(n);
    }
    function hatchForPolyline(e,options={}){
        if(e.type!=='LWPOLYLINE'||!e.closed||!allows(e,'style')||e.points.length<3||e.points.length>2048)throw Error('닫힌 2D 폴리선을 선택하세요.');
        const bulged=e.points.some(p=>p.bulge),raw=[[0,'HATCH'],[100,'AcDbEntity'],[8,e.layer],[100,'AcDbHatch'],[10,0],[20,0],[30,num(e.raw||[],38)],[210,0],[220,0],[230,1],[2,'SOLID'],[70,1],[71,0],[91,1],[92,3],[72,bulged?1:0],[73,1],[93,e.points.length]];
        for(const p of e.points){raw.push([10,p.x],[20,p.y]);if(bulged)raw.push([42,p.bulge||0]);}raw.push([97,0],[75,0],[76,1],[98,0]);
        let n=newEntity('HATCH',{raw:raw.map(p=>[p[0],String(p[1])]),color:options.color??e.color,operations:['move','copy','rotate','scale','style','delete','layer']},e.layer);n=hatchRefresh(n);return editHatch(n,{opacity:options.opacity??.5,...options});
    }



    function findPolylineFill(doc,e){
        if(e.type!=='LWPOLYLINE'||!e.closed)return null;
        const path=g.CADFidelity.polyline(e.points,true).slice(0,-1),near=(a,b)=>distance(a,b)<=1e-7;
        return doc.entities.find(h=>h.type==='HATCH'&&h.hatch?.solid&&h.hatch.rings.length===1&&(()=>{const ring=h.hatch.rings[0].slice(0,-1);if(path.length!==ring.length)return false;const starts=[];for(let i=0;i<ring.length&&starts.length<16;i++)if(near(path[0],ring[i]))starts.push(i);return starts.some(start=>[1,-1].some(direction=>path.every((p,i)=>near(p,ring[(start+direction*i+ring.length)%ring.length]))));})())||null;
    }

    function explodeBlock(e,doc){
        if(e.type!=='INSERT'||!allows(e,'move')||!allows(e,'copy')||!e.renderParts?.length)throw Error('이 블록은 안전하게 풀 수 없습니다. 외부 참조·속성·3D·특수 객체를 확인하세요.');
        if(e.renderParts.length>5000)throw Error('한 번에 5,000개 이하인 블록만 풀 수 있습니다.');
        const allowed=['LINE','ARC','CIRCLE','LWPOLYLINE','TEXT','MTEXT','POINT'];
        if(e.renderParts.some(p=>p.blockExplodable===false||!allowed.includes(p.type)||!planarXY(p)||p.presentationWarnings?.some(w=>!['font_substitution'].includes(w))||p.type==='MTEXT'&&!plainMText(p.text).safe))throw Error('비균일 배율·대칭·미지원 문자를 포함한 블록은 원본 보존을 위해 풀 수 없습니다.');
        const children=e.renderParts.map(part=>{
            const n={...part,id:uid(),dirty:true,raw:clone(part.explodeRaw||[]),layer:part.blockLayerInherited?e.layer:part.layer};
            delete n.explodeRaw;delete n.renderParts;delete n.preview;delete n.blockExplodable;
            n.raw=n.raw.filter(p=>![5,330,360].includes(p[0]));
            if(part.blockColorInherited){n.color=e.color;n.aci=e.aci;}
            if(part.lineWeight===-2)n.lineWeight=e.lineWeight;
            if(canonicalLineType(part.lineType||'')==='BYBLOCK')n.lineType=e.lineType;
            const set=(code,value)=>{const pair=n.raw.find(p=>p[0]===code);if(pair)pair[1]=String(value);else n.raw.push([code,String(value)]);};
            set(62,n.aci??256);set(n.type==='LWPOLYLINE'?38:30,part.blockElevation||0);if(n.type==='LINE'||n.type==='TEXT'&&n.textAnchor)set(31,part.blockElevation||0);
            if(n.type==='LWPOLYLINE')n.raw=n.raw.map(p=>[p[0],[40,41,43].includes(p[0])?String(Number(p[1])*(part.blockScale||1)):p[1]]);
            if(n.type==='MTEXT'){const plain=plainMText(n.text);n.text=plain.text;n.plainText=true;n.attachment=num(n.raw,71,1);}
            return n;
        });
        annotate2D({...doc,entities:children});if(children.some(p=>!p.editable))throw Error('블록 내부에 개별 편집을 지원하지 않는 객체가 있습니다. 아무 객체도 변경하지 않았습니다.');return children;
    }

    function annotate2D(doc){
        const full=['move','copy','rotate','scale','mirror','resize','style','delete','layer'];
        const blocks=new Map();let block=null;
        for(let i=0;i<doc.prefix.length;i++){const pair=doc.prefix[i];if(pair[0]!==0)continue;let end=i+1;while(end<doc.prefix.length&&doc.prefix[end][0]!==0)end++;
            if(pair[1]==='BLOCK'){const raw=doc.prefix.slice(i,end);block={name:val(raw,2),safe:num(raw,70)===0&&!raw.some(p=>[102,360,1001].includes(p[0])),children:[]};blocks.set(block.name,block);}
            else if(pair[1]==='ENDBLK')block=null;
            else if(block){if(!['INSERT','LINE','POINT','CIRCLE','ARC','LWPOLYLINE','TEXT','MTEXT'].includes(pair[1]))block.safe=false;if(['ATTDEF','ATTRIB'].includes(pair[1]))block.safe=false;if(pair[1]==='INSERT'){const raw=doc.prefix.slice(i,end);block.children.push(val(raw,2));if(num(raw,66)||raw.some(p=>[102,360,1001].includes(p[0])))block.safe=false;}}
            i=end-1;
        }
        const safeBlock=(name,seen=new Set())=>{const b=blocks.get(name);if(!b||!b.safe||seen.has(name)||seen.size>=16)return false;const next=new Set(seen);next.add(name);return b.children.every(child=>safeBlock(child,next));};
        for(const e of doc.entities){
            let ops=[];
            nativeDimensionParse(e,doc);
            if(doc.acadVersion>=1015&&!e.paper&&planarXY(e)){
                if(['LINE','CIRCLE','ARC','POINT','ERP_DIMENSION'].includes(e.type))ops=[...full];
                if(e.type==='LWPOLYLINE'&&!(num(e.raw||[],70)&~129)){ops=[...full];if(e.points.length>512||e.points.some(p=>p.bulge)||(e.raw||[]).some(p=>p[0]===91||[40,41].includes(p[0])&&Number(p[1])!==0)||num(e.raw||[],43)!==0)ops=ops.filter(op=>op!=='resize');}
                if(e.type==='TEXT'){ops=[...full.filter(op=>op!=='mirror'),'text'];if([71,72,73].some(code=>num(e.raw||[],code)))ops=['move','copy','style','text','delete','layer'];}
                if(e.type==='MTEXT'&&e.plainText){ops=[...full.filter(op=>op!=='mirror'),'text'];if((e.attachment||1)!==1)ops=['move','copy','style','text','delete','layer'];}
                if(e.nativeDimension?.compatible)ops=[...e.nativeDimension.operations];
                if(e.type==='HATCH'&&e.hatch){ops=['style','delete','layer'];try{const fields=hatchFields(e.raw);if(!num(e.raw,71)){ops.push('move','rotate','scale');if(!fields.hasSources)ops.push('copy');}}catch(_){}e.opacity=(num(e.raw,440)&0x2000000)?(num(e.raw,440)&255)/255:1;}
                if(e.type==='INSERT'&&e.renderParts?.length&&!String(val(e.raw||[],2)).startsWith('*')&&safeBlock(val(e.raw||[],2))&&!num(e.raw||[],66)&&num(e.raw||[],70,1)<=1&&num(e.raw||[],71,1)<=1&&e.renderParts.every(part=>['LINE','CIRCLE','ARC','LWPOLYLINE','TEXT','MTEXT','POINT'].includes(part.type)&&planarXY(part)))ops=['move','copy','style','delete','layer'];
                if((e.raw||[]).some(p=>[102,360].includes(p[0])||p[0]===1001&&!(p[1]==='ERP_CAD_EDIT'&&readSketchMetadata(e.raw)))&&e.type!=='ERP_DIMENSION'&&!e.nativeDimension?.compatible)ops=ops.filter(op=>op!=='copy');
            }
            e.operations=ops;e.editable=ops.length>0;
            e.editReason=ops.length?(ops.includes('rotate')?'2D 편집 가능':ops.includes('move')?'이동·속성 편집 지원 · 형상 변경 제한':ops.includes('resize')?'속성·치수 위치 편집 지원 · 원본 측정점 보호':'속성 편집 지원 · 형상 변경 제한'):(e.nativeDimension?.reason||'3D·특수 객체 또는 지원하지 않는 형식 · 원본 보존');
        }
        return doc;
    }
    function parse(text) {
        if (/^AutoCAD Binary DXF/.test(text))
            throw Error("바이너리 DXF는 서버에서 ASCII DXF로 변환해야 합니다.");
        const lines = text.replace(/\r/g, "").split("\n"),
            pairs = [];
        for (let i = 0; i + 1 < lines.length; i += 2) {
            const c = Number(lines[i].trim());
            if (!Number.isInteger(c))
                throw Error("DXF 그룹 코드가 잘못되었습니다.");
            pairs.push([c, lines[i + 1]]);
        }
        const start = pairs.findIndex(
            (p, i) =>
                p[0] === 0 &&
                p[1] === "SECTION" &&
                pairs[i + 1]?.[1] === "ENTITIES",
        );
        if (start < 0) throw Error("DXF ENTITIES 섹션이 없습니다.");
        let end = start + 2;
        while (
            end < pairs.length &&
            !(pairs[end][0] === 0 && pairs[end][1] === "ENDSEC")
        )
            end++;
        if (end === pairs.length) throw Error("DXF ENDSEC가 없습니다.");
        const doc = {
            prefix: pairs.slice(0, start + 2),
            suffix: pairs.slice(end),
            entities: [],
            lineTypes: Object.assign(Object.create(null), {
                BYLAYER: [],
                CONTINUOUS: [],
            }),
            layers: Object.assign(Object.create(null), {
                0: { color: "#e2e8f0", visible: true, locked: false },
            }),
        };
        let i = start + 2;
        while (i < end) {
            let j = i + 1;
            while (j < end && pairs[j][0] !== 0) j++;
            const r = pairs.slice(i, j),
                type = r[0][1],
                e = {
                    id: "e" + doc.entities.length,
                    type,
                    layer: decodeUnicode(val(r, 8, "0")),
                    raw: r,
                    dirty: false,
                    editable: supported.has(type) && num(r, 67) === 0,
                };
            e.p = pt(r);
            e.color = r.some((p) => p[0] === 420) ? num(r, 420) : null;
            e.aci = num(r, 62, 256);
            e.hidden = !!num(r, 60) || e.aci < 0;
            const editMeta=readSketchMetadata(r);if(editMeta){if(editMeta.group)e.sketchGroup=editMeta.group;e.sketchLocked=!!editMeta.locked;}
            e.paper = !!num(r, 67) || (val(r, 410) && val(r, 410) !== "Model");
            e.lineType = val(r, 6, "BYLAYER");
            e.lineWeight = num(r, 370, -1);
            for (const pair of r) {
                if (
                    numericCodes.has(pair[0]) &&
                    !Number.isFinite(Number(pair[1]))
                )
                    throw Error("DXF에 유효하지 않은 수치가 있습니다.");
            }
            if (type === "LINE") e.q = pt(r, 11);
            if (["CIRCLE", "ARC"].includes(type)) e.radius = num(r, 40);
            if (type === "ARC") {
                e.start = num(r, 50);
                e.end = num(r, 51);
            }
            if (["TEXT", "MTEXT"].includes(type)) {
                e.text = r
                    .filter((p) => p[0] === 3 || p[0] === 1)
                    .map((p) => p[1])
                    .join("")
                    .replace(/\\U\+([0-9a-f]{4})/gi, (_, h) =>
                        String.fromCharCode(parseInt(h, 16)),
                    );
                if(type==='MTEXT'){const decoded=plainMText(e.text);e.text=decoded.text;e.width=num(r,41);e.attachment=num(r,71,1);e.plainText=decoded.safe&&e.attachment>=1&&e.attachment<=9&&num(r,72,1)===1&&num(r,44,1)===1&&!r.some(p=>[75,76,78,79].includes(p[0]));}
                if(type==='TEXT'&&(num(r,72)||num(r,73))&&r.some(p=>p[0]===11)){e.textAnchor=pt(r,11);e.textAlign=['left','center','right'][num(r,72)]||'left';e.textBaseline=['alphabetic','bottom','middle','top'][num(r,73)]||'alphabetic';}
                e.height = num(r, 40, 2.5);
                e.angle =
                    type === "MTEXT" && r.some((p) => p[0] === 11)
                        ? (Math.atan2(num(r, 21), num(r, 11)) * 180) / Math.PI
                        : type === "MTEXT"
                          ? (num(r, 50) * 180) / Math.PI
                          : num(r, 50);
            }
            if (type === "LWPOLYLINE") {
                e.points = [];
                for (let k = 0; k < r.length; k++)
                    if (r[k][0] === 10)
                        e.points.push({
                            x: Number(r[k][1]),
                            y: num(r.slice(k + 1), 20),
                            bulge: num(
                                r.slice(
                                    k + 1,
                                    (r.findIndex(
                                        (p, z) => z > k && p[0] === 10,
                                    ) + 1 || r.length + 1) - 1,
                                ),
                                42,
                            ),
                        });
                e.closed = !!(num(r, 70) & 1);
                if (
                    e.points.some((p) => p.bulge) ||
                    r.some((p) => [40, 41, 91].includes(p[0])) ||
                    num(r, 43) !== 0 ||
                    (num(r, 70) & ~1) !== 0
                )
                    e.editable = false;
            }
            // Non-planar/extruded entities require a 3D engine. Keep them intact and read-only.
            if (
                (type === "MTEXT" && !e.plainText) ||
                (type === "TEXT" &&
                    (num(r, 72) !== 0 || num(r, 73) !== 0 || num(r, 71) !== 0))
            )
                e.editable = false;
            if (
                num(r, 39) !== 0 ||
                num(r, 30) !== 0 ||
                num(r, 31) !== 0 ||
                num(r, 38) !== 0 ||
                num(r, 210) !== 0 ||
                num(r, 220) !== 0 ||
                num(r, 230, 1) !== 1
            )
                e.editable = false;
            doc.entities.push(e);
            if (!doc.layers[e.layer])
                doc.layers[e.layer] = {
                    color: "#e2e8f0",
                    visible: true,
                    locked: false,
                };
            i = j;
        }
        for (let k = 0; k < pairs.length; k++) {
            if (pairs[k][0] === 0 && pairs[k][1] === "LTYPE") {
                let j = k + 1;
                while (j < pairs.length && pairs[j][0] !== 0) j++;
                const r = pairs.slice(k, j);
                doc.lineTypes[val(r, 2)] = r
                    .filter((p) => p[0] === 49)
                    .map((p) => Number(p[1]));
            }
            if (pairs[k][0] === 0 && pairs[k][1] === "LAYER") {
                let j = k + 1;
                while (j < pairs.length && pairs[j][0] !== 0) j++;
                const r = pairs.slice(k, j),
                    name = decodeUnicode(val(r, 2)),
                    color = num(r, 62, 7);
                if (name)
                    doc.layers[name] = {
                        color: r.some((p) => p[0] === 420)
                            ? "#" + num(r, 420).toString(16).padStart(6, "0")
                            : aci(Math.abs(color)),
                        visible: color >= 0,
                        locked: !!(num(r, 70) & 4),
                        lineType: val(r, 6, "CONTINUOUS"),
                        lineWeight: num(r, 370, -1),
                        originalColor: r.some((p) => p[0] === 420)
                            ? "#" + num(r, 420).toString(16).padStart(6, "0")
                            : aci(Math.abs(color)),
                        originalVisible: color >= 0,
                        originalLocked: !!(num(r, 70) & 4),
                    };
            }
        }
        for (let i = 0; i < doc.entities.length; i++) {
            const e = doc.entities[i];
            if (e.type === "POLYLINE") {
                e.points = [];
                for (
                    let j = i + 1;
                    j < doc.entities.length &&
                    doc.entities[j].type === "VERTEX";
                    j++
                )
                    e.points.push(doc.entities[j].p);
                e.closed = !!(num(e.raw, 70) & 1);
            }
            if (e.type === "ELLIPSE") {
                e.major = pt(e.raw, 11);
                e.ratio = num(e.raw, 40, 1);
                e.startParam = num(e.raw, 41, 0);
                e.endParam = num(e.raw, 42, Math.PI * 2);
            }
        }
        const blocks = Object.create(null);
        for (let k = 0; k < pairs.length; k++) {
            if (pairs[k][0] === 0 && pairs[k][1] === "BLOCK") {
                let h = k + 1;
                while (h < pairs.length && pairs[h][0] !== 0) h++;
                const name = val(pairs.slice(k, h), 2),
                    base = pt(pairs.slice(k, h)),
                    es = [];
                let j = h;
                while (
                    j < pairs.length &&
                    !(pairs[j][0] === 0 && pairs[j][1] === "ENDBLK")
                ) {
                    let z = j + 1;
                    while (z < pairs.length && pairs[z][0] !== 0) z++;
                    const r = pairs.slice(j, z),
                        e = { type: r[0][1], p: pt(r) };
                    if (e.type === "LINE") e.q = pt(r, 11);
                    if (["CIRCLE", "ARC"].includes(e.type)) {
                        e.radius = num(r, 40);
                        e.start = num(r, 50);
                        e.end = num(r, 51);
                    }
                    if (["LINE", "CIRCLE", "ARC"].includes(e.type)) es.push(e);
                    j = z;
                }
                blocks[name] = { base, es };
                k = j;
            }
        }
        for (const e of doc.entities)
            if (e.type === "INSERT") {
                const block = blocks[val(e.raw, 2)];
                if (!block) continue;
                const a = (num(e.raw, 50) * Math.PI) / 180,
                    sx = num(e.raw, 41, 1),
                    sy = num(e.raw, 42, 1);
                e.preview = block.es.map((item) =>
                    geometry(item).map((p) => {
                        const x = (p.x - block.base.x) * sx,
                            y = (p.y - block.base.y) * sy;
                        return {
                            x: e.p.x + x * Math.cos(a) - y * Math.sin(a),
                            y: e.p.y + x * Math.sin(a) + y * Math.cos(a),
                        };
                    }),
                );
            }
        const versionIndex = pairs.findIndex(
            (p) => p[0] === 9 && p[1] === "$ACADVER",
        );
        doc.acadVersion =
            versionIndex >= 0
                ? Number(String(pairs[versionIndex + 1]?.[1]).replace("AC", ""))
                : 1032;
        if (doc.acadVersion < 1015)
            doc.entities.forEach((e) => (e.editable = false));
        return prepareDocumentAssets(annotate2D(restoreDimensions(g.CADFidelity ? g.CADFidelity.enhance(doc,pairs) : doc)));
    }
    function aci(n) {
        return (
            [
                "#e2e8f0",
                "#ff5252",
                "#ffe66d",
                "#58df8a",
                "#62dbed",
                "#629cff",
                "#e082ed",
                "#e2e8f0",
            ][n] || "#aab8cc"
        );
    }
    function textBox(e){
        const lines=textLines(e),width=e.type==='MTEXT'&&e.width>0?e.width:Math.max(1,...lines.map(line=>Array.from(line).reduce((w,c)=>w+e.height*(c.charCodeAt(0)>255?1:.6),0))),height=e.height*lines.length*1.2,a=(e.angle||0)*Math.PI/180,u={x:Math.cos(a),y:Math.sin(a)},v={x:-Math.sin(a),y:Math.cos(a)},anchor=e.textAnchor||e.p;
        const attachment=e.attachment||1,align=e.textAlign||(e.type==='MTEXT'?['left','center','right'][(attachment-1)%3]:'left'),baseline=e.textBaseline||(e.type==='MTEXT'?(attachment<=3?'top':attachment<=6?'middle':'bottom'):'alphabetic'),x=align==='center'?-width/2:align==='right'?-width:0,top=baseline==='top'?0:baseline==='middle'?height/2:baseline==='bottom'?height:e.height,bottom=baseline==='alphabetic'?-e.height*.2:top-height;
        return {width,height,u,v,points:[[x,top],[x+width,top],[x+width,bottom],[x,bottom]].map(([x,y])=>({x:anchor.x+(x*(e.type==='TEXT'?(e.widthFactor||1):1)+Math.tan(e.oblique||0)*y)*u.x+y*v.x,y:anchor.y+(x*(e.type==='TEXT'?(e.widthFactor||1):1)+Math.tan(e.oblique||0)*y)*u.y+y*v.y}))};
    }
    function geometry(e) {
        if(e.nativeDimension?.compatible&&e.dirty)return nativeDimensionParts(e).flatMap(geometry);
        if(e.displayGeometry)return e.displayGeometry;
        if(e.renderParts)return e.renderParts.flatMap(geometry);
        if(['TEXT','MTEXT'].includes(e.type)){const box=textBox({...e,text:e.dirty?e.text:(e.displayText??e.text),p:e.textAnchor||e.p});return [...box.points,box.points[0]];}

        if(e.type==='ERP_DIMENSION')return dimensionParts(e).flatMap(geometry);

        let p = [];
        if (e.type === "INSERT") return (e.preview || []).flat();
        if (e.type === "POLYLINE")
            return (e.points || []).concat(
                e.closed && e.points?.length ? [e.points[0]] : [],
            );
        if (e.type === "ELLIPSE") {
            const v = e.major,
                a = Math.atan2(v.y, v.x),
                rx = Math.hypot(v.x, v.y),
                ry = rx * e.ratio;
            for (let i = 0; i <= 96; i++) {
                const t = e.startParam + ((e.endParam - e.startParam) * i) / 96;
                p.push({
                    x:
                        e.p.x +
                        rx * Math.cos(t) * Math.cos(a) -
                        ry * Math.sin(t) * Math.sin(a),
                    y:
                        e.p.y +
                        rx * Math.cos(t) * Math.sin(a) +
                        ry * Math.sin(t) * Math.cos(a),
                });
            }
            return p;
        }
        if (!supported.has(e.type)) return p;
        if (e.type === "LINE") p = [e.p, e.q];
        else if (e.type === "LWPOLYLINE")
            p = e.points.concat(e.closed ? [e.points[0]] : []);
        else if (["CIRCLE", "ARC"].includes(e.type)) {
            let a = e.type === "CIRCLE" ? 0 : e.start,
                b = e.type === "CIRCLE" ? 360 : e.end;
            while (b <= a) b += 360;
            for (let i = 0; i <= 72; i++) {
                let t = ((a + ((b - a) * i) / 72) * Math.PI) / 180;
                p.push({
                    x: e.p.x + Math.cos(t) * e.radius,
                    y: e.p.y + Math.sin(t) * e.radius,
                });
            }
        } else p = [e.p];
        return p.filter(Boolean);
    }
    function bounds(es) {
        const ps = es.flatMap(geometry);
        if (!ps.length) return { minX: -100, minY: -100, maxX: 100, maxY: 100 };
        return ps.reduce(
            (b, p) => ({
                minX: Math.min(b.minX, p.x),
                minY: Math.min(b.minY, p.y),
                maxX: Math.max(b.maxX, p.x),
                maxY: Math.max(b.maxY, p.y),
            }),
            {
                minX: Infinity,
                minY: Infinity,
                maxX: -Infinity,
                maxY: -Infinity,
            },
        );
    }
    function transform(e, o) {
        const operation=o.mirror?'mirror':o.scale!==undefined?'scale':o.angle!==undefined?'rotate':'move';
        if (!allows(e,operation))
            throw Error(
                "지원하지 않는 객체는 원본 보존을 위해 편집할 수 없습니다.",
            );
        if(e.nativeDimension?.compatible)return nativeDimensionTransform(e,o);
        if(e.type==='HATCH')return transformHatch(e,o);
        const n = copyEditState(e, entityAssets),
            a = ((o.angle || 0) * Math.PI) / 180,
            s = o.scale ?? 1,
            c = o.center || { x: 0, y: 0 };
        if (
            ![s, o.angle ?? 0, o.dx ?? 0, o.dy ?? 0, c.x, c.y].every(
                Number.isFinite,
            ) ||
            s <= 0
        )
            throw Error("배율은 양수여야 합니다.");
        function f(p) {
            let x = (p.x - c.x) * s,
                y = (p.y - c.y) * s;
            if (o.mirror === "x") y = -y;
            if (o.mirror === "y") x = -x;
            return {
                ...p,
                x: c.x + x * Math.cos(a) - y * Math.sin(a) + (o.dx || 0),
                y: c.y + x * Math.sin(a) + y * Math.cos(a) + (o.dy || 0),
            };
        }
        if(n.type==='ERP_DIMENSION'){const l=dimensionLayout(n),axisEnd=f({x:n.p.x+l.u.x,y:n.p.y+l.u.y}),origin=f(n.p),len=Math.hypot(axisEnd.x-origin.x,axisEnd.y-origin.y);n.axis={x:(axisEnd.x-origin.x)/len,y:(axisEnd.y-origin.y)/len};n.placement=f(n.placement);if(n.textPosition)n.textPosition=f(n.textPosition);}
        n.p = f(n.p);
        if(n.textAnchor)n.textAnchor=f(n.textAnchor);
        if(n.displayGeometry)n.displayGeometry=n.displayGeometry.map(f);
        if(n.preview)n.preview=n.preview.map(path=>path.map(f));
        if(n.renderParts)n.renderParts=n.renderParts.map(part=>{const moved={...part,p:f(part.p),displayGeometry:geometry(part).map(f)};if(part.q)moved.q=f(part.q);if(part.textAnchor)moved.textAnchor=f(part.textAnchor);if(part.points)moved.points=part.points.map(f);return moved;});
        if (n.q) n.q = f(n.q);
        if (n.points) n.points = n.points.map(p=>({...f(p),...(p.bulge!==undefined?{bulge:o.mirror?-p.bulge:p.bulge}:{})}));
        if(n.type==='LWPOLYLINE'&&n.raw&&s!==1)n.raw=n.raw.map(pair=>[...pair.slice(0,1),[40,41,43].includes(pair[0])?String(Number(pair[1])*s):pair[1]]);
        if (n.radius) n.radius *= s;
        if (n.height) n.height *= s;
        if(n.width)n.width*=s;
        if (n.angle !== undefined) {
            if (o.mirror) n.angle = o.mirror === "x" ? -n.angle : 180 - n.angle;
            n.angle = (n.angle + (o.angle || 0)) % 360;
        }
        if (n.type === "ARC") {
            if (o.mirror) {
                let st = n.start,
                    en = n.end;
                n.start = o.mirror === "x" ? -en : 180 - en;
                n.end = o.mirror === "x" ? -st : 180 - st;
            }
            n.start += o.angle || 0;
            n.end += o.angle || 0;
        }
        n.dirty = true;
        return n;
    }
    // DXF raw sections can contain hundreds of thousands of pairs. Passing them
    // as function arguments exceeds browser call-stack limits; copy as data.
    function appendRecords(target, records) {
        for (const record of records) target.push(record);
    }
    function replaceRecords(records, index, count, replacement) {
        const at = index < 0 ? Math.max(0, records.length + index) : Math.min(index, records.length);
        return records.slice(0, at).concat(replacement, records.slice(at + count));
    }
    function exportDXF(d) {
        d=nativeDimensionDocument(d);
        const hasDimensions=d.entities.some(e=>e.type==='ERP_DIMENSION');
        d={...d,entities:d.entities.flatMap(e=>e.type==='ERP_DIMENSION'?e.dirty?dimensionParts(e):e.rawParts.map((raw,i)=>({id:e.id+'-'+i,raw,dirty:false})):e)};

        if (d.acadVersion < 1015 && d.entities.some((e) => e.dirty))
            throw Error(
                "R12 도면은 원래 CAD에서 최신 DXF로 저장한 뒤 편집하세요.",
            );
        const encode = (v) =>
            d.acadVersion < 1021
                ? String(v).replace(
                      /[^\x00-\x7f]/g,
                      (c) =>
                          "\\U+" +
                          c
                              .charCodeAt(0)
                              .toString(16)
                              .toUpperCase()
                              .padStart(4, "0"),
                  )
                : v;
        if (!d.layersDirty && !d.entities.some((e) => e.dirty)) {
            return (
                [
                    ...d.prefix,
                    ...d.entities.flatMap((e) => e.raw || []),
                    ...d.suffix,
                ]
                    .map((p) => p[0] + "\r\n" + encode(p[1]))
                    .join("\r\n") + "\r\n"
            );
        }
        let prefix = clone(d.prefix),
            suffix = clone(d.suffix),
            max = 0;
        [
            ...prefix,
            ...suffix,
            ...d.entities.flatMap((e) => e.raw || []),
        ].forEach((p) => {
            if (p[0] === 5 || p[0] === 105) {
                const n = parseInt(p[1], 16);
                if (Number.isFinite(n)) max = Math.max(max, n);
            }
        });
        const next = () => (++max).toString(16).toUpperCase();
        let table = -1,
            end = -1;
        for (let i = 0; i < prefix.length - 1; i++)
            if (
                prefix[i][0] === 0 &&
                prefix[i][1] === "TABLE" &&
                prefix[i + 1][0] === 2 &&
                prefix[i + 1][1] === "LAYER"
            ) {
                table = i;
                end = i + 1;
                while (
                    end < prefix.length &&
                    !(prefix[end][0] === 0 && prefix[end][1] === "ENDTAB")
                )
                    end++;
                break;
            }
        const layerRecords = [];
        for (const [name, l] of Object.entries(d.layers)) {
            let r = [];
            if (table >= 0) {
                for (let i = table + 2; i < end; i++)
                    if (prefix[i][0] === 0 && prefix[i][1] === "LAYER") {
                        let j = i + 1;
                        while (j < end && prefix[j][0] !== 0) j++;
                        if (decodeUnicode(val(prefix.slice(i, j), 2)) === name) {
                            r = clone(prefix.slice(i, j));
                            break;
                        }
                    }
            }
            const isNew = !r.length;
            if (isNew)
                r = [
                    [0, "LAYER"],
                    [5, next()],
                    [100, "AcDbSymbolTableRecord"],
                    [100, "AcDbLayerTableRecord"],
                    [2, name],
                    [70, "0"],
                    [62, "7"],
                    [6, "CONTINUOUS"],
                ];
            const set = (c, v) => {
                const p = r.find((p) => p[0] === c);
                if (p) p[1] = String(v);
                else r.push([c, String(v)]);
            };
            if (isNew || l.locked !== l.originalLocked)
                set(70, (num(r, 70) & ~4) | (l.locked ? 4 : 0));
            if (isNew || l.visible !== l.originalVisible)
                set(
                    62,
                    (l.visible ? 1 : -1) * Math.max(1, Math.abs(num(r, 62, 7))),
                );
            if (isNew || l.color !== l.originalColor)
                set(420, parseInt(l.color.slice(1), 16));
            appendRecords(layerRecords, r);
        }
        if (table >= 0) {
            let headerEnd = table + 2;
            while (headerEnd < end && prefix[headerEnd][0] !== 0) headerEnd++;
            let head = prefix.slice(table, headerEnd);
            const count = head.find((p) => p[0] === 70);
            if (count) count[1] = String(Object.keys(d.layers).length);
            else head.push([70, String(Object.keys(d.layers).length)]);
            prefix = replaceRecords(prefix, table, end - table + 1, head.concat(layerRecords, [[0, "ENDTAB"]]));
        } else {
            const chunk = [
                [0, "TABLE"],
                [2, "LAYER"],
                [70, String(Object.keys(d.layers).length)],
                ...layerRecords,
                [0, "ENDTAB"],
            ];
            let tablesEnd = -1;
            for (let i = 0; i < prefix.length - 1; i++)
                if (
                    prefix[i][0] === 0 &&
                    prefix[i][1] === "SECTION" &&
                    prefix[i + 1][1] === "TABLES"
                ) {
                    tablesEnd = i + 2;
                    while (
                        tablesEnd < prefix.length &&
                        !(
                            prefix[tablesEnd][0] === 0 &&
                            prefix[tablesEnd][1] === "ENDSEC"
                        )
                    )
                        tablesEnd++;
                    break;
                }
            if (tablesEnd >= 0) prefix = replaceRecords(prefix, tablesEnd, 0, chunk);
            else
                prefix = replaceRecords(prefix, prefix.length - 2, 0, [[0, "SECTION"], [2, "TABLES"]].concat(chunk, [[0, "ENDSEC"]]));
        }
        let styleName = "Standard",
            styleFound = false;
        for (let i = 0; i < prefix.length; i++)
            if (prefix[i][0] === 0 && prefix[i][1] === "STYLE") {
                let j = i + 1;
                while (j < prefix.length && prefix[j][0] !== 0) j++;
                styleName = val(prefix.slice(i, j), 2, "Standard");
                styleFound = true;
                break;
            }
        if (!styleFound) {
            const records = [
                [0, "TABLE"],
                [2, "STYLE"],
                [70, "1"],
                [0, "STYLE"],
                [5, next()],
                [100, "AcDbSymbolTableRecord"],
                [100, "AcDbTextStyleTableRecord"],
                [2, styleName],
                [70, "0"],
                [40, "0"],
                [41, "1"],
                [50, "0"],
                [71, "0"],
                [42, "2.5"],
                [3, "txt"],
                [4, ""],
                [0, "ENDTAB"],
            ];
            let at = prefix.findIndex(
                (p, i) =>
                    p[0] === 0 &&
                    p[1] === "SECTION" &&
                    prefix[i + 1]?.[1] === "TABLES",
            );
            while (
                at < prefix.length &&
                !(prefix[at][0] === 0 && prefix[at][1] === "ENDSEC")
            )
                at++;
            prefix = replaceRecords(prefix, at, 0, records);
        }
        for(const appId of [...(hasDimensions?['ERP_CAD_DIM']:[]),...(d.entities.some(e=>e.sketchGroup||e.sketchLocked)?['ERP_CAD_EDIT']:[])]){
            let table=prefix.findIndex((p,i)=>p[0]===0&&p[1]==='TABLE'&&prefix[i+1]?.[1]==='APPID'),end=table;
            if(table>=0){while(end<prefix.length&&!(prefix[end][0]===0&&prefix[end][1]==='ENDTAB'))end++;}
            const exists=prefix.some((p,i)=>p[0]===0&&p[1]==='APPID'&&prefix.slice(i+1,i+9).some(p=>p[0]===2&&p[1]===appId));
            if(!exists){const record=[[0,'APPID'],[5,next()],[100,'AcDbSymbolTableRecord'],[100,'AcDbRegAppTableRecord'],[2,appId],[70,'0']];if(table>=0){prefix=replaceRecords(prefix,end,0,record);const count=prefix.slice(table,end).find(p=>p[0]===70);if(count)count[1]=String(Number(count[1])+1);}else{let at=prefix.findIndex((p,i)=>p[0]===0&&p[1]==='SECTION'&&prefix[i+1]?.[1]==='TABLES');while(at<prefix.length&&!(prefix[at][0]===0&&prefix[at][1]==='ENDSEC'))at++;prefix=replaceRecords(prefix,at,0,[[0,'TABLE'],[2,'APPID'],[70,'1']].concat(record,[[0,'ENDTAB']]));}}
        }
        let out = [...prefix];
        for (const e of d.entities) {
            if (!e.dirty && e.raw) {
                appendRecords(out, e.raw);
                continue;
            }
            let r = e.raw
                ? clone(e.raw)
                : [
                      [0, e.type],
                      [5, next()],
                      [100, "AcDbEntity"],
                      [8, e.layer],
                      [
                          100,
                          {
                              LINE: "AcDbLine",
                              CIRCLE: "AcDbCircle",
                              ARC: "AcDbCircle",
                              LWPOLYLINE: "AcDbPolyline",
                              TEXT: "AcDbText",
                              MTEXT: "AcDbMText",
                              POINT: "AcDbPoint",
                          }[e.type] || "AcDbEntity",
                      ],
                  ];
            if (!r.some((p) => p[0] === 5)) r.splice(1, 0, [5, next()]);
            const set = (c, v) => {
                let end = r.findIndex((p) => p[0] === 1001);
                let p = r
                    .slice(0, end < 0 ? r.length : end)
                    .find((p) => p[0] === c);
                if (p) p[1] = String(v);
                else {
                    const at = r.findIndex((p) => p[0] >= 1000);
                    r.splice(at < 0 ? r.length : at, 0, [c, String(v)]);
                }
            };
            set(8, e.layer);
            if (e.color !== undefined && e.color !== null) set(420, e.color);
            if (e.lineType) set(6, e.lineType);
            if (e.lineWeight !== undefined) set(370, e.lineWeight);
            if(e.hidden!==undefined){set(60,e.hidden?1:0);if(!e.hidden&&num(r,62)<0)set(62,Math.abs(num(r,62)));}
            // Keep persistent selection groups and locks on the DXF entities,
            // not in a side table keyed by IDs that change when DXF is reopened.
            const editRecords=()=>{if(e.sketchGroup===undefined&&e.sketchLocked===undefined)return;const at=r.findIndex(p=>p[0]===1001&&p[1]==='ERP_CAD_EDIT');if(at>=0){if(!readSketchMetadata(r))throw Error('지원하지 않는 편집 메타데이터가 있습니다. 원본을 보존합니다.');let end=at+1;while(end<r.length&&r[end][0]!==1001)end++;r.splice(at,end-at);}if(e.sketchGroup||e.sketchLocked)r.push([1001,'ERP_CAD_EDIT'],[1000,JSON.stringify({v:1,group:e.sketchGroup||null,locked:!!e.sketchLocked})]);};
            if(e.type==='HATCH'){editRecords();appendRecords(out,r);continue;}
            set(10, e.p.x);
            set(20, e.p.y);
            if (e.type === "LINE") {
                set(11, e.q.x);
                set(21, e.q.y);
            }
            if (e.radius !== undefined) set(40, e.radius);
            if (e.type === "ARC") {
                set(50, e.start);
                set(51, e.end);
            }
            if(e.type==='TEXT'&&e.raw?.some(p=>p[0]===11)&&(num(e.raw,72)||num(e.raw,73))){const anchor=e.textAnchor||pt(e.raw,11);set(11,anchor.x);set(21,anchor.y);}
            if (e.text !== undefined) {
                r = r.filter((p) => p[0] !== 3);
                if (e.type === "MTEXT")
                    r = r.filter((p) => ![11, 21, 31].includes(p[0]));
                const textValue=e.type==='MTEXT'?encodeMText(e.text):e.text;
                if(e.type==='MTEXT'){const encoded=String(encode(textValue));const chunks=encoded.match(/[\s\S]{1,200}/g)||[''];set(1,chunks.pop());const at=r.findIndex(p=>p[0]===1);r=replaceRecords(r,at,0,chunks.map(chunk=>[3,chunk]));}else set(1,textValue);
                if (!r.some((p) => p[0] === 7)) set(7, styleName);
                set(40, e.height);
                if(e.type==='MTEXT'){r=r.filter(pair=>pair[0]!==50);const angle=(e.angle||0)*Math.PI/180;set(11,Math.cos(angle));set(21,Math.sin(angle));set(31,0);set(41,e.width||0);set(71,e.attachment||1);set(72,1);}else set(50,e.angle||0);
            }
            if (e.type === 'LWPOLYLINE') {
                const existing=r.filter(pair=>pair[0]===10).length===e.points.length;
                if(existing){let index=-1;for(let i=0;i<r.length;i++){if(r[i][0]===10){index++;r[i][1]=String(e.points[index].x);}else if(r[i][0]===20&&index>=0)r[i][1]=String(e.points[index].y);else if(r[i][0]===42&&index>=0)r[i][1]=String(e.points[index].bulge||0);}}
                else {r=r.filter(pair=>![10,20,42,40,41,91].includes(pair[0]));for(const point of e.points){r.push([10,String(point.x)],[20,String(point.y)]);if(point.bulge)r.push([42,String(point.bulge)]);}}
                set(90,e.points.length);set(70,(num(r,70)&~1)|(e.closed?1:0));
            }
            if (
                e.type === "ARC" &&
                !r.some((p) => p[0] === 100 && p[1] === "AcDbArc")
            )
                r.splice(
                    r.findIndex((p) => p[0] === 50),
                    0,
                    [100, "AcDbArc"],
                );
            editRecords();
            appendRecords(out, r);
        }
        appendRecords(out, suffix);
        const seed = out.findIndex((p) => p[0] === 9 && p[1] === "$HANDSEED");
        if (seed >= 0 && out[seed + 1]?.[0] === 5) out[seed + 1][1] = next();
        return (
            out.map((p) => p[0] + "\r\n" + encode(p[1])).join("\r\n") + "\r\n"
        );
    }
    function distance(a, b) {
        return Math.hypot(a.x - b.x, a.y - b.y);
    }
    function segmentPoint(p, a, b) {
        const l = distance(a, b) ** 2,
            t = l
                ? Math.max(
                      0,
                      Math.min(
                          1,
                          ((p.x - a.x) * (b.x - a.x) +
                              (p.y - a.y) * (b.y - a.y)) /
                              l,
                      ),
                  )
                : 0;
        return {
            x: a.x + t * (b.x - a.x),
            y: a.y + t * (b.y - a.y),
        };
    }
    function segmentDistance(p, a, b) { return distance(p, segmentPoint(p, a, b)); }
    // Preview, typed distance and committed guides must use the same signed normal.
    function parallelGuide(edge, anchor, cursor, length) {
        const d=distance(edge.p,edge.q);
        if(!Number.isFinite(d)||d<1e-10)throw Error('길이가 있는 기준 선을 선택하세요.');
        const direction={x:(edge.q.x-edge.p.x)/d,y:(edge.q.y-edge.p.y)/d},normal={x:-direction.y,y:direction.x};
        let offset=(cursor.x-anchor.x)*normal.x+(cursor.y-anchor.y)*normal.y;
        if(length!==undefined){if(!Number.isFinite(length)||length<=0)throw Error('간격은 0보다 커야 합니다.');offset=length*(offset<0?-1:1);}
        return {p:{...anchor},q:{x:anchor.x+normal.x*offset,y:anchor.y+normal.y*offset},direction,offset};
    }
    function newEntity(type, data, layer = "0") {
        return {
            id: "n" + Date.now() + Math.random(),
            type,
            layer,
            p: { x: 0, y: 0 },
            editable: true,
            dirty: true,
            ...data,
        };
    }

    function offset(e, d) {
        if (!Number.isFinite(d)||Math.abs(d)<1e-10) throw Error("0이 아닌 오프셋 거리를 입력하세요.");
        if(!allows(e,'copy'))throw Error('이 객체는 복사를 지원하지 않습니다.');
        const n = copyEditState(e, entityAssets);delete n.displayGeometry;
        if (e.type === "LINE") {
            const len = distance(e.p, e.q);
            if (!len) throw Error("길이 0인 선은 오프셋할 수 없습니다.");
            const dx = (-(e.q.y - e.p.y) * d) / len,
                dy = ((e.q.x - e.p.x) * d) / len;
            n.p = { x: e.p.x + dx, y: e.p.y + dy };
            n.q = { x: e.q.x + dx, y: e.q.y + dy };
        } else if (e.type === "CIRCLE" || e.type === 'ARC') {
            n.radius += d;
            if (n.radius <= 0) throw Error("원 반지름이 0 이하입니다.");
        } else if(e.type==='LWPOLYLINE'){
            const ps=e.points,count=ps.length,closed=e.closed;
            if(count<2||count>2000||ps.some(p=>p.bulge))throw Error('오프셋은 직선 폴리선(최대 2,000개 점)을 지원합니다. 곡선 구간은 원·원호 도구를 사용하세요.');
            const cross=(a,b)=>a.x*b.y-a.y*b.x,sub=(a,b)=>({x:a.x-b.x,y:a.y-b.y}),area=points=>points.reduce((sum,p,i)=>sum+cross(p,points[(i+1)%points.length]),0)/2;
            const signedArea=area(ps),sign=closed?(signedArea>0?-1:1):1;
            const edges=ps.slice(0,closed?count:count-1).map((p,i)=>{const q=ps[(i+1)%count],len=distance(p,q);if(len<1e-10)throw Error('길이 0인 구간은 오프셋할 수 없습니다.');const dx=-(q.y-p.y)*d*sign/len,dy=(q.x-p.x)*d*sign/len;return [{x:p.x+dx,y:p.y+dy},{x:q.x+dx,y:q.y+dy}];});
            const intersect=(a,b,c,d,segment=false)=>{const u=sub(b,a),v=sub(d,c),den=cross(u,v);if(Math.abs(den)<1e-12)return null;const t=cross(sub(c,a),v)/den,s=cross(sub(c,a),u)/den;if(segment&&(t<0||t>1||s<0||s>1))return null;return {x:a.x+t*u.x,y:a.y+t*u.y};};
            const out=ps.map((p,i)=>{if(!closed&&i===0)return {...p,...edges[0][0]};if(!closed&&i===count-1)return {...p,...edges.at(-1)[1]};const prev=edges[(i-1+edges.length)%edges.length],next=edges[i%edges.length],q=intersect(...prev,...next)||next[0];if(distance(q,p)>Math.max(1e-8,Math.abs(d)*30))throw Error('모서리가 너무 날카롭습니다. 오프셋 간격을 줄이세요.');return {...p,...q};});
            const invalid=()=>{throw Error('오프셋 결과가 뒤집히거나 겹칩니다. 간격을 줄이세요.');};
            if(closed&&(Math.abs(area(out))<1e-10||Math.sign(area(out))!==Math.sign(signedArea)))invalid();
            const end=closed?count:count-1;
            for(let i=0;i<end;i++){const a=out[i],b=out[(i+1)%count];if(cross(sub(b,a),sub(ps[(i+1)%count],ps[i]))>1e-6)invalid();if((b.x-a.x)*(ps[(i+1)%count].x-ps[i].x)+(b.y-a.y)*(ps[(i+1)%count].y-ps[i].y)<=0)invalid();for(let j=i+2;j<end;j++){if(closed&&i===0&&j===end-1)continue;if(intersect(a,b,out[j],out[(j+1)%count],true))invalid();}}
            if(closed&&d<0&&out.some(p=>ps.some((a,i)=>segmentDistance(p,a,ps[(i+1)%count])<Math.abs(d)-Math.max(1e-8,Math.abs(d)*1e-7))))invalid();
            n.points=out;n.p=out[0];
        } else throw Error("오프셋은 선·원·원호·직선 폴리선을 지원합니다.");
        n.id = "offset" + Math.random();
        delete n.sketchGroup;n.sketchLocked=false;
        n.dirty = true;
        if (n.raw) n.raw = n.raw.filter((p) => ![5, 330, 360].includes(p[0]));
        return n;
    }
    function trim(e, boundary, pick, extend = false) {
        if (e.type !== "LINE" || boundary.type !== "LINE")
            throw Error("두 개의 직선을 선택하세요.");
        const a = e.p,
            b = e.q,
            c = boundary.p,
            d = boundary.q,
            den = (a.x - b.x) * (c.y - d.y) - (a.y - b.y) * (c.x - d.x);
        if (Math.abs(den) < 1e-12)
            throw Error("평행하거나 겹치는 선은 처리할 수 없습니다.");
        const t = ((a.x - c.x) * (c.y - d.y) - (a.y - c.y) * (c.x - d.x)) / den,
            hit = { x: a.x + t * (b.x - a.x), y: a.y + t * (b.y - a.y) };
        if (extend ? t >= 0 && t <= 1 : t < 0 || t > 1)
            throw Error(
                extend
                    ? "교점이 이미 선 안에 있습니다."
                    : "교점이 선 밖에 있습니다.",
            );
        if(!allows(e,'resize'))throw Error('이 객체는 자르기·연장을 지원하지 않습니다.');
        const n = copyEditState(e, entityAssets);delete n.displayGeometry;
        if (extend) {
            if (t < 0) n.p = hit;
            else n.q = hit;
        } else if (distance(pick, a) < distance(pick, b)) n.p = hit;
        else n.q = hit;
        n.dirty = true;
        return n;
    }
    function copyEntities(es){
        const groups=new Map();
        return es.map(e=>{if(!allows(e,'copy'))throw Error('선택 객체 중 복사할 수 없는 객체가 있습니다.');const n=copyEditState(e,entityAssets);n.id=uid();n.dirty=true;n.sketchLocked=false;
            if(n.raw)n.raw=n.raw.filter(p=>![5,330,360].includes(p[0]));
            if(n.sketchGroup){if(!groups.has(n.sketchGroup))groups.set(n.sketchGroup,uid());n.sketchGroup=groups.get(n.sketchGroup);}
            if(n.type==='ERP_DIMENSION'){n.groupId=uid();delete n.rawParts;}return n;});
    }
    function array(es, rows, cols, dx, dy) {
        if (
            !Number.isInteger(rows) ||
            !Number.isInteger(cols) ||
            rows < 1 ||
            cols < 1 ||
            rows * cols > 500 ||
            es.length * (rows * cols - 1) > 50000 ||
            ![dx, dy].every(Number.isFinite)
        )
            throw Error(
                "배열은 양의 정수 행·열, 최대 500칸·추가 객체 50,000개입니다.",
            );
        const out = [];
        for (let y = 0; y < rows; y++)
            for (let x = 0; x < cols; x++)
                if (x || y)for(const n of copyEntities(es.map(e=>transform(e,{dx:x*dx,dy:y*dy}))))out.push(n);
        return out;
    }
    function renameLayer(source, old, name) {
        if (old === "0" || !Object.hasOwn(source.layers, old))
            throw Error("기본 또는 없는 레이어는 이름을 바꿀 수 없습니다.");
        if (
            !name ||
            ["__proto__", "constructor", "prototype"].includes(name) ||
            /[<>/\\":;?*|=\r\n]/.test(name) ||
            Object.hasOwn(source.layers, name)
        )
            throw Error("사용할 수 없는 레이어 이름입니다.");
        const opaque =
            source.prefix.some(
                (p, i) =>
                    p[0] === 0 &&
                    p[1] === "SECTION" &&
                    !["HEADER", "TABLES", "ENTITIES"].includes(
                        source.prefix[i + 1]?.[1],
                    ),
            ) ||
            source.suffix.some((p) => p[0] === 0 && p[1] === "SECTION") ||
            source.entities.some((e) => !e.editable);
        if (opaque)
            throw Error(
                "블록 또는 미지원 데이터가 있는 도면의 레이어 이름은 원래 CAD에서 바꾸세요.",
            );
        const doc = copyDocument(source);
        // Layer renaming is one of the few operations that edits raw DXF records.
        // Detach those assets before changing them so undo and the source stay intact.
        doc.prefix = clone(source.prefix);
        doc.layers[name] = doc.layers[old];
        delete doc.layers[old];
        doc.layersDirty = true;
        for (const entity of doc.entities)
            if (entity.layer === old) {
                entity.layer = name;
                if(entity.type==='ERP_DIMENSION')entity.dirty=true;
                if (entity.raw) entity.raw = clone(entity.raw);
                const pair = entity.raw?.find((p) => p[0] === 8);
                if (pair) pair[1] = name;
            }
        for (let i = 0; i < doc.prefix.length; i++) {
            const pair = doc.prefix[i];
            if (
                pair[0] === 9 &&
                pair[1] === "$CLAYER" &&
                doc.prefix[i + 1]?.[0] === 8 &&
                decodeUnicode(doc.prefix[i + 1][1]) === old
            )
                doc.prefix[i + 1][1] = name;
            if (pair[0] === 0 && pair[1] === "LAYER") {
                for (
                    let j = i + 1;
                    j < doc.prefix.length && doc.prefix[j][0] !== 0;
                    j++
                )
                    if (doc.prefix[j][0] === 2 && decodeUnicode(doc.prefix[j][1]) === old)
                        doc.prefix[j][1] = name;
            }
        }
        return doc;
    }

    // Orthogonal four-vertex polylines expose local axes, including rotated rectangles.
    function rectangleFrame(e) {
        if(e.type!=='LWPOLYLINE'||!e.closed||e.points?.length!==4)return null;
        const [a,b,c,d]=e.points,w=distance(a,b),h=distance(a,d);
        if(!w||!h)return null;
        const u={x:(b.x-a.x)/w,y:(b.y-a.y)/w},v={x:(d.x-a.x)/h,y:(d.y-a.y)/h};
        const tolerance=Math.max(w,h)*1e-8;
        if(Math.abs(u.x*v.x+u.y*v.y)>1e-8||distance(c,{x:b.x+d.x-a.x,y:b.y+d.y-a.y})>tolerance)return null;
        return {a,u,v,w,h};
    }
    function grips(e) {
        if(!allows(e,'resize'))return [];
        if(e.nativeDimension?.compatible)return nativeDimensionGrips(e);
        if(e.type==='ERP_DIMENSION'){const l=dimensionLayout(e);return [{key:'dimension-line',point:{x:l.a.x+(l.b.x-l.a.x)/3,y:l.a.y+(l.b.y-l.a.y)/3}},{key:'dimension-text',point:l.text}];}
        if(e.type==='MTEXT'&&e.editable){const b=textBox(e);return [{key:'text-width',point:{x:e.p.x+b.u.x*b.width,y:e.p.y+b.u.y*b.width}},{key:'text-size',point:{x:e.p.x-b.v.x*b.height,y:e.p.y-b.v.y*b.height}}];}
        if(e.type==='LINE')return [{key:'p',point:e.p},{key:'q',point:e.q}];
        if(!rectangleFrame(e))return e.type==='LWPOLYLINE'?e.points.map((point,index)=>({key:'vertex'+index,point})):[];
        return e.points.flatMap((p,i)=>[{key:'corner'+i,point:p},{key:'edge'+i,point:{x:(p.x+e.points[(i+1)%4].x)/2,y:(p.y+e.points[(i+1)%4].y)/2}}]);
    }
    function resize(e,key,p) {
        if(!allows(e,'resize'))throw Error('이 객체는 형상 손잡이 변경을 지원하지 않습니다. 이동 또는 속성 편집을 사용하세요.');
        if(!e.editable||![p.x,p.y].every(Number.isFinite))throw Error('크기를 조절할 수 없는 객체입니다.');
        if(e.nativeDimension?.compatible)return nativeDimensionResize(e,key,p);
        const n=clone(e);delete n.displayGeometry;
        if(e.type==='ERP_DIMENSION'){if(key==='dimension-line')n.placement={...p};else if(key==='dimension-text')n.textPosition={...p};else throw Error('알 수 없는 치수 손잡이입니다.');n.dirty=true;return n;}
        if(e.type==='MTEXT'){const b=textBox(e),dx=p.x-e.p.x,dy=p.y-e.p.y;if(key==='text-width')n.width=Math.max(e.height,Math.abs(dx*b.u.x+dy*b.u.y));else if(key==='text-size')n.height=Math.max(.001,Math.abs(dx*b.v.x+dy*b.v.y)/(textLines(e).length*1.2));else throw Error('알 수 없는 문자 손잡이입니다.');n.dirty=true;return n;}
        if(e.type==='LINE'&&['p','q'].includes(key)){
            if(distance(p,e[key==='p'?'q':'p'])<1e-10)throw Error('선 길이는 0보다 커야 합니다.');
            n[key]={...p};n.dirty=true;return n;
        }
        if(e.type==='LWPOLYLINE'&&/^vertex\d+$/.test(key)){const index=Number(key.slice(6));if(!n.points[index])throw Error('꼭지점이 없습니다.');n.points[index]={...n.points[index],...p};n.p={...n.points[0]};delete n.displayGeometry;n.dirty=true;return n;}
        const frame=rectangleFrame(e);if(!frame)throw Error('사각형 손잡이가 아닙니다.');
        const {a,u,v,w,h}=frame,dx=p.x-a.x,dy=p.y-a.y,x=dx*u.x+dy*u.y,y=dx*v.x+dy*v.y;
        let left=0,right=w,bottom=0,top=h;
        const corners={corner0:()=>{left=x;bottom=y;},corner1:()=>{right=x;bottom=y;},corner2:()=>{right=x;top=y;},corner3:()=>{left=x;top=y;}};
        const edges={edge0:()=>bottom=y,edge1:()=>right=x,edge2:()=>top=y,edge3:()=>left=x};
        if(corners[key])corners[key]();else if(edges[key])edges[key]();else throw Error('알 수 없는 손잡이입니다.');
        if(Math.abs(right-left)<1e-10||Math.abs(top-bottom)<1e-10)throw Error('사각형 폭과 높이는 0보다 커야 합니다.');
        n.points=[[left,bottom],[right,bottom],[right,top],[left,top]].map(([x,y])=>({x:a.x+x*u.x+y*v.x,y:a.y+x*u.y+y*v.y}));
        n.p={...n.points[0]};n.dirty=true;return n;
    }
    function contains(e,p) {
        if(e.type==='CIRCLE')return distance(e.p,p)<=e.radius;
        if(e.type==='LWPOLYLINE'&&e.closed){const ring=geometry(e);let hit=false;for(let i=0,j=ring.length-1;i<ring.length;j=i++){const a=ring[i],b=ring[j];if(segmentDistance(p,a,b)<1e-9)return true;if((a.y>p.y)!==(b.y>p.y)&&p.x<(b.x-a.x)*(p.y-a.y)/(b.y-a.y)+a.x)hit=!hit;}return hit;}
        if(e.hatch){const inside=ring=>{let hit=false;for(let i=0,j=ring.length-1;i<ring.length;j=i++){const a=ring[i],b=ring[j];if((a.y>p.y)!==(b.y>p.y)&&p.x<(b.x-a.x)*(p.y-a.y)/(b.y-a.y)+a.x)hit=!hit;}return hit;};const count=e.hatch.rings.filter(inside).length;return e.hatch.style===2?count>0:e.hatch.style===1?count===1:count%2===1;}
        const f=rectangleFrame(e);if(!f)return false;
        const x=(p.x-f.a.x)*f.u.x+(p.y-f.a.y)*f.u.y,y=(p.x-f.a.x)*f.v.x+(p.y-f.a.y)*f.v.y;
        return x>=0&&x<=f.w&&y>=0&&y<=f.h;
    }


    function quantities(e){
        const unavailable=reason=>({length:null,area:null,available:false,reason});
        if(!planarXY(e))return unavailable('nonplanar_entity');
        if(e.type==='LINE')return {length:distance(e.p,e.q),area:null,available:true,reason:null};
        if(e.type==='CIRCLE'&&e.radius>=0)return {length:2*Math.PI*e.radius,area:Math.PI*e.radius*e.radius,available:true,reason:null};
        if(e.type==='ARC'&&e.radius>=0){const sweep=((e.end-e.start)%360+360)%360;if(!sweep)return unavailable('zero_sweep_arc');return {length:e.radius*sweep*Math.PI/180,area:null,available:true,reason:null};}
        if(e.type==='LWPOLYLINE'){
            if(e.points.some(p=>p.bulge))return unavailable('curved_polyline');
            let length=0,area=0;for(let i=1;i<e.points.length;i++)length+=distance(e.points[i-1],e.points[i]);
            if(e.closed&&e.points.length>=2)length+=distance(e.points.at(-1),e.points[0]);
            if(e.closed&&e.points.length>2){for(let i=0;i<e.points.length;i++){const a=e.points[i],b=e.points[(i+1)%e.points.length];area+=a.x*b.y-b.x*a.y;}area=Math.abs(area)/2;}
            return {length,area:e.closed?area:null,available:true,reason:null};
        }
        return unavailable(['TEXT','MTEXT','ERP_DIMENSION','POINT'].includes(e.type)?'annotation_or_point':'unsupported_entity');
    }
    function aiGeometry(e){
        if(!planarXY(e))return null;
        const point=p=>({x:p.x,y:p.y});let result=null;
        if(e.type==='POINT')result={p:point(e.p)};
        if(e.type==='LINE')result={p:point(e.p),q:point(e.q)};
        if(e.type==='LWPOLYLINE')result={points:e.points.map(p=>({...point(p),...(p.bulge?{bulge:p.bulge}:{})})),closed:!!e.closed};
        if(e.type==='CIRCLE'||e.type==='ARC')result={center:point(e.p),radius:e.radius,...(e.type==='ARC'?{start:e.start,end:e.end}:{})};
        if(e.type==='TEXT'||e.type==='MTEXT'){if(e.text.length>2000)throw Error('공유할 문자 내용은 객체당 2,000자 이내여야 합니다. 해당 문자를 제외하고 선택해 주세요.');result={p:point(e.textAnchor||e.p),text:e.text,height:e.height,width:e.width||0,angle:e.angle||0};}
        if(e.type==='ERP_DIMENSION')result={p:point(e.p),q:point(e.q),placement:point(e.placement),mode:e.mode,height:e.height,...(e.axis?{axis:point(e.axis)}:{}),textPosition:e.textPosition?point(e.textPosition):null};
        function finite(value){if(typeof value==='number'&&(!Number.isFinite(value)||Math.abs(value)>1e12))throw Error('AI 공유/편집 좌표와 수치는 유한한 ±1조 범위여야 합니다.');if(Array.isArray(value))value.forEach(finite);else if(value&&typeof value==='object')Object.values(value).forEach(finite);}finite(result);return result;
    }
    function aiEditable(e){return !!e.editable&&!e.sketchLocked&&aiGeometry(e)!==null&&['move','rotate','scale','style','delete',...(['TEXT','MTEXT'].includes(e.type)?['text']:[])].every(op=>allows(e,op));}
    function utf8Size(value){let bytes=0;for(const character of JSON.stringify(value)){const n=character.codePointAt(0);bytes+=n<=127?1:n<=2047?2:n<=65535?3:4;}return bytes;}
    function aiContext(doc,options){
        if(!['drawing','mm','cm','m','in','ft'].includes(options.unit)||!['selection','visible'].includes(options.scope)||!/^([a-f0-9]{64})$/.test(options.documentHash))throw Error('공유 범위·도면 단위·문서 해시를 확인하세요.');
        const selection=new Set(options.selection||[]),visible=doc.entities.filter(e=>!e.hidden&&!e.paper&&doc.layers[e.layer]?.visible!==false),es=options.scope==='selection'?visible.filter(e=>selection.has(e.id)):visible;
        if(options.scope==='selection'&&!es.length&&!options.allowEmpty)throw Error('공유할 객체를 먼저 선택하세요.');if(es.length>2000)throw Error('공유 객체가 2,000개를 넘습니다. 선택 범위를 줄이세요.');
        const layers=[...new Set(es.map(e=>e.layer))].map(name=>({name,color:doc.layers[name]?.color||'#e2e8f0',visible:true,locked:!!doc.layers[name]?.locked}));
        const entities=es.map(e=>({id:e.id,type:e.type,layer:e.layer,editable:aiEditable(e)&&!doc.layers[e.layer]?.locked,geometry:aiGeometry(e),style:{color:e.color===null||e.color===undefined?null:'#'+e.color.toString(16).padStart(6,'0'),lineType:e.lineType||'BYLAYER',lineWeight:e.lineWeight??-1},quantities:quantities(e)}));
        const totals=entities.reduce((t,e)=>({length:t.length+(e.quantities.length||0),area:t.area+(e.quantities.area||0),unavailableCount:t.unavailableCount+(!e.quantities.available?1:0)}),{length:0,area:0,unavailableCount:0});
        const context={version:1,documentHash:options.documentHash,unit:options.unit,scope:options.scope,selection:es.filter(e=>selection.has(e.id)).map(e=>e.id),entities,layers,totals};
        if(utf8Size(context)>512*1024)throw Error('공유 데이터가 512KB를 넘습니다. 선택 범위를 줄이세요.');return context;
    }
    function applyAIActions(source,actions,options){
        if(!options.canManage)throw Error('현재 도면은 읽기 전용입니다.');
        if(!/^[a-f0-9]{64}$/.test(options.expectedHash||'')||!/^[a-f0-9]{64}$/.test(options.currentHash||'')||options.expectedHash!==options.currentHash)throw Error('AI 요청 이후 도면이 변경되었습니다. 다시 공유한 뒤 요청하세요.');
        if(!Array.isArray(actions)||!actions.length||actions.length>100)throw Error('편집 요청은 1~100개 동작이어야 합니다.');
        const ids=new Set(options.sharedIds||[]),layers=new Set(options.sharedLayers||[]),doc=copyDocument(source),affected=new Set();let targets=0;
        const point=p=>{if(!p||Object.keys(p).some(k=>!['x','y'].includes(k))||!Number.isFinite(p.x)||!Number.isFinite(p.y)||Math.abs(p.x)>1e12||Math.abs(p.y)>1e12)throw Error('올바른 좌표가 필요합니다.');return {x:p.x,y:p.y};};
        const finite=n=>{if(!Number.isFinite(n)||Math.abs(n)>1e12)throw Error('올바른 수치가 필요합니다.');return n;};
        const color=c=>{if(typeof c!=='string'||!/^#[a-f0-9]{6}$/i.test(c))throw Error('색상은 #RRGGBB 형식이어야 합니다.');return parseInt(c.slice(1),16);};
        const text=t=>{if(typeof t!=='string'||!t.trim()||t.length>2000)throw Error('문자는 1~2,000자로 입력하세요.');return t;};
        const schema={move:['entityIds','dx','dy'],rotate:['entityIds','angle','center'],scale:['entityIds','factor','center'],style:['entityIds','color','lineWeight'],text:['entityIds','text','height','width','color'],delete:['entityIds'],add_line:['p','q','layer','color'],add_rectangle:['p','q','layer','color'],add_text:['p','text','height','width','color','layer'],add_dimension:['p','q','placement','mode','height','color','layer']};
        actions.forEach((action,index)=>{
            if(!action||!schema[action.type]||Object.keys(action).some(k=>k!=='type'&&!schema[action.type].includes(k)))throw Error('지원하지 않는 AI 편집 동작 또는 매개변수입니다.');
            if(action.type.startsWith('add_')){
                if(!layers.has(action.layer)||!Object.hasOwn(doc.layers,action.layer)||doc.layers[action.layer].locked||doc.layers[action.layer].visible===false)throw Error('공유한 편집 가능 레이어에만 새 객체를 추가할 수 있습니다.');
                const p=point(action.p),base={color:action.color===undefined?0xe2e8f0:color(action.color),lineWeight:25};let e;
                if(action.type==='add_line'){const q=point(action.q);if(distance(p,q)<1e-10)throw Error('선 길이는 0보다 커야 합니다.');e=newEntity('LINE',{...base,p,q},action.layer);}
                if(action.type==='add_rectangle'){const q=point(action.q);if(p.x===q.x||p.y===q.y)throw Error('사각형 폭과 높이는 0보다 커야 합니다.');e=newEntity('LWPOLYLINE',{...base,p,points:[p,{x:q.x,y:p.y},q,{x:p.x,y:q.y}],closed:true},action.layer);}
                if(action.type==='add_text'){const h=finite(action.height),w=finite(action.width??0);if(h<=0||w<0)throw Error('문자 크기·폭을 확인하세요.');e=newEntity('MTEXT',{...base,p,text:text(action.text),height:h,width:w,angle:0,plainText:true},action.layer);}
                if(action.type==='add_dimension'&&!['aligned','horizontal','vertical'].includes(action.mode))throw Error('치수 방향을 지정하세요.');
                if(action.type==='add_dimension')e=newDimension(p,point(action.q),point(action.placement),{...base,mode:action.mode,height:finite(action.height),layer:action.layer});
                const prefix='ai-'+String(options.transactionId||source.entities.length).replace(/[^a-z0-9_-]/gi,'')+'-'+index;let id=prefix,suffix=0;while(doc.entities.some(n=>n.id===id))id=prefix+'-'+(++suffix);e.id=id;if(e.type==='ERP_DIMENSION')e.groupId=id;aiGeometry(e);doc.entities.push(e);affected.add(id);return;
            }
            if(!Array.isArray(action.entityIds)||!action.entityIds.length||new Set(action.entityIds).size!==action.entityIds.length)throw Error('대상 객체 목록을 확인하세요.');targets+=action.entityIds.length;if(targets>1000)throw Error('한 요청의 대상은 최대 1,000개입니다.');
            for(const id of action.entityIds){const index=doc.entities.findIndex(e=>e.id===id),e=doc.entities[index];if(!ids.has(id)||!e||!aiEditable(e)||!allows(e,action.type)||e.hidden||e.paper||doc.layers[e.layer]?.locked||doc.layers[e.layer]?.visible===false)throw Error('공유 범위 밖·읽기 전용·숨김·잠금 객체는 편집할 수 없습니다.');let n;
                if(action.type==='delete'){doc.entities.splice(index,1);affected.add(id);continue;}
                if(action.type==='move')n=transform(e,{dx:finite(action.dx),dy:finite(action.dy)});
                if(action.type==='rotate')n=transform(e,{angle:finite(action.angle),center:point(action.center)});
                if(action.type==='scale'){const factor=finite(action.factor);if(factor<=0)throw Error('배율은 양수여야 합니다.');n=transform(e,{scale:factor,center:point(action.center)});}
                if(action.type==='style'){n=clone(e);if(action.color!==undefined)n.color=color(action.color);if(action.lineWeight!==undefined){if(![-3,-2,-1,0,5,9,13,15,18,20,25,30,35,40,50,53,60,70,80,90,100,106,120,140,158,200,211].includes(action.lineWeight))throw Error('지원하지 않는 선 굵기입니다.');n.lineWeight=action.lineWeight;}if(action.color===undefined&&action.lineWeight===undefined)throw Error('변경할 스타일을 지정하세요.');n.dirty=true;}
                if(action.type==='text'){if(!['text','height','width','color'].some(k=>action[k]!==undefined))throw Error('변경할 문자 속성을 지정하세요.');if(!['TEXT','MTEXT'].includes(e.type))throw Error('문자 객체만 수정할 수 있습니다.');n=clone(e);if(action.text!==undefined){n.text=text(action.text);if(e.type==='TEXT'&&n.text.includes('\n'))throw Error('한 줄 문자는 줄바꿈을 지원하지 않습니다.');}if(action.height!==undefined){n.height=finite(action.height);if(n.height<=0)throw Error('문자 크기는 양수여야 합니다.');}if(action.width!==undefined){if(e.type!=='MTEXT')throw Error('박스 폭은 여러 줄 문자에서만 지원합니다.');n.width=finite(action.width);if(n.width<0)throw Error('문자 폭은 0 이상이어야 합니다.');}if(action.color!==undefined)n.color=color(action.color);n.dirty=true;}
                aiGeometry(n);doc.entities[index]=n;affected.add(id);
            }
        });return {document:doc,affectedIds:[...affected]};
    }

    // Display contrast belongs to the viewport, never the saved entity or paper export.
    const canvasColors = new Map();
    function canvasColor(color, background) {
        if (background !== 'white' || !/^#[0-9a-f]{6}$/i.test(color)) return color;
        if (canvasColors.has(color)) return canvasColors.get(color);
        const rgb = [1, 3, 5].map(i => parseInt(color.slice(i, i + 2), 16));
        const luminance = values => values.map(v => {
            const c = v / 255;
            return c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4;
        }).reduce((sum, c, i) => sum + c * [.2126, .7152, .0722][i], 0);
        let result = color;
        if (1.05 / (luminance(rgb) + .05) < 4.5) {
            if (Math.max(...rgb) - Math.min(...rgb) < 24) result = '#334155';
            else {
                let low = 0, high = 1;
                for (let i = 0; i < 12; i++) {
                    const factor = (low + high) / 2;
                    if (1.05 / (luminance(rgb.map(v => Math.floor(v * factor))) + .05) >= 4.5) low = factor;
                    else high = factor;
                }
                result = '#' + rgb.map(v => Math.floor(v * low).toString(16).padStart(2, '0')).join('');
            }
        }
        if (canvasColors.size >= 1024) canvasColors.clear();
        canvasColors.set(color, result);
        return result;
    }

    // The editor and paper export use the same native CAD rendering path.
    function renderEntities(ctx, doc, shown, screen, scale, options = {}) {
        if(doc?.reference){g.CADReference?.draw(ctx,doc,screen);shown=shown.filter(e=>!doc.entities.some(original=>original.id===e.id));}
        const C = g.CADCore, printing = !!options.paper, selected = options.selected || new Set();
        for (const e of shown.flatMap(e=>e.nativeDimension?.compatible&&e.dirty?nativeDimensionParts(e).map(part=>({...part,id:e.id})):e.renderParts?.length?e.renderParts.filter(part=>!part.hidden&&doc.layers[part.layer]?.visible!==false).map(part=>({...part,id:e.id,...(part.blockColorInherited?{color:e.color,aci:e.aci}:{}),...(part.blockLayerInherited?{layer:e.layer}:{}),...(part.lineWeight===-2?{lineWeight:e.lineWeight}:{}),...(canonicalLineType(part.lineType||'')==='BYBLOCK'?{lineType:e.lineType}:{})})):e.type==='ERP_DIMENSION'?C.dimensionParts(e).map(part=>({...part,id:e.id})):e)) {
            const ps = ['TEXT','MTEXT'].includes(e.type)?[]:C.geometry(e);
            if(['TEXT','MTEXT'].includes(e.type))ps.push(e.p);
            if (!ps.length) continue;
            ctx.strokeStyle = e.color !== undefined && e.color !== null
                  ? "#" + e.color.toString(16).padStart(6, "0")
                  : e.aci > 0 && e.aci < 256
                    ? C.aci(e.aci)
                    : doc.layers[e.layer]?.color || "#e2e8f0";
            if(printing && /^#(?:ffffff|e2e8f0)$/i.test(ctx.strokeStyle))ctx.strokeStyle="#334155";
            if(!printing)ctx.strokeStyle=canvasColor(ctx.strokeStyle,options.background);
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
            if(printing)ctx.lineWidth=Math.max((options.pixelsPerMm||3.78)*.12,(e.lineWeight>0?e.lineWeight/100:.18)*(options.pixelsPerMm||3.78));
            ctx.beginPath();
            if(e.hatch){g.CADFidelity?.drawHatch(ctx,e,screen,scale,options);continue;}
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
            if(!["TEXT","MTEXT"].includes(e.type)){if(e.solid)ctx.fill();else ctx.stroke();}
            if (["TEXT", "MTEXT"].includes(e.type)) {
                const p = screen(e.textAnchor||e.p);
                ctx.save();
                ctx.translate(p.x, p.y);
                const angle=(e.angle||0)*Math.PI/180,axis=screen({x:(e.textAnchor||e.p).x+Math.cos(angle),y:(e.textAnchor||e.p).y+Math.sin(angle)});
                ctx.rotate(Math.atan2(axis.y-p.y,axis.x-p.x));
                if(e.widthFactor||e.oblique)ctx.transform(e.widthFactor||1,0,-Math.tan(e.oblique||0),1,0,0);
                ctx.font = Math.max(1, e.height * scale) + "px sans-serif";
                ctx.textAlign=e.textAlign||'left';
                ctx.textBaseline=e.type==='MTEXT'?'top':(e.textBaseline||'alphabetic');
                const lines=C.textLines({...e,text:e.dirty?e.text:(e.displayText??e.text)}),height=lines.length*e.height*scale*1.2;
                const offset=e.type==='MTEXT'?(e.textBaseline==='middle'?-height/2:e.textBaseline==='bottom'?-height:0):0;
                lines.forEach((line,i)=>ctx.fillText(line,0,offset+i*e.height*scale*1.2));
                ctx.restore();
            }
            if (e.type === "POINT") {
                const p = screen(e.p);
                ctx.fillRect(p.x - 2, p.y - 2, 4, 4);
            }
        }
    }

    function parseLengthInput(text, settings = {}) {
        const value=String(text).trim().toLowerCase();
        const number=token=>{const m=token.trim().match(/^(?:(\d+)\s+)?(\d+)\/(\d+)$|^(\d+(?:\.\d*)?|\.\d+)$/);if(!m)throw Error('길이는 숫자·소수 또는 분수로 입력하세요.');const n=m[4]!==undefined?Number(m[4]):Number(m[1]||0)+Number(m[2])/Number(m[3]);if(!Number.isFinite(n)||n<0)throw Error('길이는 0 이상이어야 합니다.');return n;};
        let physical=null,n;
        const feet=value.match(/^(.+?)\s*(?:'|ft)\s*(?:(.+?)\s*(?:"|in))?$/);
        if(feet){n=number(feet[1])*304.8;if(feet[2])n+=number(feet[2])*25.4;physical=n;}
        else {const m=value.match(/^(.*?)\s*(mm|cm|m|in|ft|"|')$/);if(m)physical=number(m[1])*lengthUnits[m[2]==='"'?'in':m[2]==="'"?'ft':m[2]];else n=number(value);}
        const basis=settings.mmPerUnit;
        if(physical!==null){if(!Number.isFinite(basis)||basis<=0)throw Error('mm·inch 등을 입력하려면 원본 도면의 기준 단위를 먼저 지정하세요.');n=physical/basis;}
        else if(settings.unit&&settings.unit!=='drawing'){if(!Number.isFinite(basis)||basis<=0)throw Error('원본 도면의 기준 단위를 먼저 지정하세요.');n=n*lengthUnits[settings.unit==='ft-in'?'ft':settings.unit]/basis;}
        if(!Number.isFinite(n)||n<=0||n>1e9)throw Error('길이는 0보다 크고 10억 도면 단위 이하여야 합니다.');return n;
    }
    function pointAtLength(a,cursor,length){const dx=cursor.x-a.x,dy=cursor.y-a.y,d=Math.hypot(dx,dy);if(!Number.isFinite(d)||d<1e-12)throw Error('마우스로 길이 방향을 먼저 지정하세요.');const p={x:a.x+dx/d*length,y:a.y+dy/d*length};if(!Number.isFinite(length)||length<=0||Math.abs(p.x)>1e9||Math.abs(p.y)>1e9)throw Error('좌표·길이 범위를 확인하세요.');return p;}
    function numericDrawing(tool,a,cursor,text,settings={}){
        if(tool==='rectangle'){const parts=String(text).split(',');if(parts.length!==2||parts.every(part=>!part.trim()))throw Error('사각형은 폭,높이를 입력하세요. 예: 1200mm,800mm');const w=parts[0].trim()?parseLengthInput(parts[0],settings):Math.abs(cursor.x-a.x),h=parts[1].trim()?parseLengthInput(parts[1],settings):Math.abs(cursor.y-a.y),q={x:a.x+(cursor.x<a.x?-w:w),y:a.y+(cursor.y<a.y?-h:h)};if(!Number.isFinite(w)||!Number.isFinite(h)||w<=0||h<=0||Math.abs(q.x)>1e9||Math.abs(q.y)>1e9)throw Error('폭·높이·좌표 범위를 확인하세요.');return {p:a,q};}
        const polar=String(text).split('<');if(polar.length>2)throw Error('각도는 길이<80 형식으로 입력하세요.');if(polar.length===2){const angle=Number(polar[1].replace(/°|deg/gi,'').trim());if(!Number.isFinite(angle)||!polar[1].trim())throw Error('각도를 확인하세요.');cursor={x:a.x+Math.cos(angle*Math.PI/180),y:a.y+Math.sin(angle*Math.PI/180)};text=polar[0];}
        const diameter=tool==='circle'&&/^d\s*/i.test(text),length=parseLengthInput(diameter?String(text).replace(/^d\s*/i,''):text,settings);
        return {p:a,q:pointAtLength(a,cursor,length/(diameter?2:1)),length:length/(diameter?2:1)};
    }
    function inferDirection(a,p,options={}) {
        const dx=p.x-a.x,dy=p.y-a.y,length=Math.hypot(dx,dy);if(!length)return {point:{...p},angle:0,kind:null};
        const angle=Math.atan2(dy,dx)*180/Math.PI,wrap=x=>((x+180)%360+360)%360-180;
        let target=options.lock,kind=target===undefined||target===null?null:'locked';
        if(kind===null&&options.enabled!==false){const candidates=[{angle:Math.round(angle/180)*180,kind:'x'},{angle:90+Math.round((angle-90)/180)*180,kind:'y'}];if(options.reference!==undefined&&options.reference!==null){candidates.push({angle:options.reference+Math.round((angle-options.reference)/180)*180,kind:'parallel'},{angle:options.reference+90+Math.round((angle-options.reference-90)/180)*180,kind:'perpendicular'});}if(options.step>0)candidates.push({angle:Math.round(angle/options.step)*options.step,kind:'angle'});const hit=candidates.find(c=>Math.abs(wrap(angle-c.angle))<=(options.thresholdDeg??3));if(hit){target=hit.angle;kind=hit.kind;}}
        if(!kind)return {point:{...p},angle,kind:null};const r=target*Math.PI/180,t=dx*Math.cos(r)+dy*Math.sin(r);return {point:{x:a.x+Math.cos(r)*t,y:a.y+Math.sin(r)*t},angle:target,kind};
    }
    function guideSnap(p,guides,tolerance,edges=[]){
        let result=null,best=tolerance,priority=-1;const lines=guides.filter(g=>g.type==='line'),offer=(q,kind)=>{const d=distance(p,q),rank=kind==='guide-projection'?0:1;if(d<tolerance&&(rank>priority||rank===priority&&d<best)){priority=rank;best=d;result={...q,kind};}};
        for(const guide of guides){if(guide.type==='point')offer(guide.p,'guide-point');else{const u=guide.direction,t=(p.x-guide.p.x)*u.x+(p.y-guide.p.y)*u.y;offer({x:guide.p.x+u.x*t,y:guide.p.y+u.y*t},'guide-projection');}}
        const intersect=(a,u,b,v,segment=false)=>{const den=u.x*v.y-u.y*v.x;if(Math.abs(den)<1e-12)return;const dx=b.x-a.x,dy=b.y-a.y,t=(dx*v.y-dy*v.x)/den,k=(dx*u.y-dy*u.x)/den;if(segment&&(k<0||k>1))return;offer({x:a.x+u.x*t,y:a.y+u.y*t},'guide-intersection');};
        for(let i=0;i<lines.length;i++){for(let j=i+1;j<lines.length;j++)intersect(lines[i].p,lines[i].direction,lines[j].p,lines[j].direction);for(const edge of edges.slice(0,64))intersect(lines[i].p,lines[i].direction,edge.p,{x:edge.q.x-edge.p.x,y:edge.q.y-edge.p.y},true);}
        return result;
    }
    g.CADCore = {
        nativeDimensionPlainText, nativeDimensionLayout, nativeDimensionParts, nativeDimensionGrips,
        editHatch, hatchForPolyline, findPolylineFill, explodeBlock,
        allows, planarXY,
        parseLengthInput, pointAtLength, numericDrawing, guideSnap, inferDirection, parallelGuide,
        canvasColor,
        renderEntities,
        quantities, aiGeometry, aiContext, applyAIActions, utf8Size,
        uid, copyEntities, drawingUnit, lengthUnits, measurementSettings, lengthInputScale, formatLength, newDimension, dimensionLayout, dimensionParts, textLines, textBox, snapPoints,
        rectangleFrame, grips, resize, contains,
        renameLayer,
        offset,
        trim,
        array,
        parse,
        exportDXF,
        geometry,
        bounds,
        transform,
        distance,
        segmentDistance,
        segmentPoint,
        newEntity,
        clone,
        copyDocument,
        aci,
    };
})(typeof window === "undefined" ? globalThis : window);
