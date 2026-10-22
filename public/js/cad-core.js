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

    // Raw DXF and tessellated block geometry are read-only assets, not edit state.
    // A JSON copy expands shared point paths into separate multi-megabyte copies.
    // Share these assets across history entries; copy every mutable editing field.
    const immutableAssets = new WeakSet();
    const documentAssets = new Set(['prefix', 'suffix', 'lineTypes', 'presentation']);
    const entityAssets = new Set(['raw', 'rawParts', 'displayGeometry', 'preview', 'renderParts', 'hatch', 'presentationWarnings']);
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
    function newDimension(a,b,placement,options={}){
        if(![a.x,a.y,b.x,b.y,placement.x,placement.y].every(Number.isFinite)||distance(a,b)<1e-10)throw Error('서로 다른 두 점을 지정하세요.');
        const mode=options.mode||'aligned',height=options.height??2.5; if(options.axis&&(![options.axis.x,options.axis.y].every(Number.isFinite)||Math.abs(Math.hypot(options.axis.x,options.axis.y)-1)>1e-8))throw Error('치수 기준 축이 잘못되었습니다.');if(options.textPosition&&![options.textPosition.x,options.textPosition.y].every(Number.isFinite))throw Error('치수 문자 위치가 잘못되었습니다.');if(!['aligned','horizontal','vertical'].includes(mode)||!Number.isFinite(height)||height<=0||(mode==='horizontal'&&Math.abs(a.x-b.x)<1e-10)||(mode==='vertical'&&Math.abs(a.y-b.y)<1e-10))throw Error('치수 방향·문자 크기·두 점을 확인하세요.');
        return newEntity('ERP_DIMENSION',{p:{...a},q:{...b},placement:{...placement},textPosition:null,mode:options.mode||'aligned',height:options.height||2.5,color:options.color??0xe2e8f0,lineWeight:25,lineType:'BYLAYER',groupId:uid(),...options},options.layer||'0');
    }
    function dimensionLayout(e){
        const delta={x:e.q.x-e.p.x,y:e.q.y-e.p.y},len=Math.hypot(delta.x,delta.y),u=e.axis|| (e.mode==='horizontal'?{x:1,y:0}:e.mode==='vertical'?{x:0,y:1}:{x:delta.x/len,y:delta.y/len}),n={x:-u.y,y:u.x};
        const offset=(e.placement.x-e.p.x)*n.x+(e.placement.y-e.p.y)*n.y,value=Math.abs(delta.x*u.x+delta.y*u.y);
        const a={x:e.p.x+n.x*offset,y:e.p.y+n.y*offset},b={x:a.x+u.x*(delta.x*u.x+delta.y*u.y),y:a.y+u.y*(delta.x*u.x+delta.y*u.y)};
        const text=e.textPosition||{x:(a.x+b.x)/2+n.x*e.height*.5,y:(a.y+b.y)/2+n.y*e.height*.5};
        return {a,b,text,value,label:value.toFixed(3),angle:Math.atan2(u.y,u.x)*180/Math.PI,u,n};
    }
    function dimensionParts(e){
        const l=dimensionLayout(e),length=e.height*.3,tick=p=>({p:{x:p.x-(l.u.x+l.n.x)*length,y:p.y-(l.u.y+l.n.y)*length},q:{x:p.x+(l.u.x+l.n.x)*length,y:p.y+(l.u.y+l.n.y)*length}});
        const shapes=[['LINE',{p:e.p,q:l.a}],['LINE',{p:e.q,q:l.b}],['LINE',{p:l.a,q:l.b}],['LINE',tick(l.a)],['LINE',tick(l.b)],['TEXT',{p:l.text,text:l.label,height:e.height,angle:l.angle}]];
        return shapes.map(([type,shape],index)=>{
            const payload=JSON.stringify({v:1,group:e.groupId,part:index,a:e.p,b:e.q,placement:e.placement,textPosition:e.textPosition||null,mode:e.mode,axis:e.axis||null,height:e.height,color:e.color,lineWeight:e.lineWeight??25,lineType:e.lineType||'BYLAYER',layer:e.layer}).replace(/[^\x00-\x7f]/g,character=>'\\u'+character.charCodeAt(0).toString(16).padStart(4,'0'));
            let raw=e.rawParts?.[index]?clone(e.rawParts[index]):null;
            if(raw){const at=raw.findIndex(p=>p[0]===1001&&p[1]==='ERP_CAD_DIM');if(at>=0){let end=at+1;while(end<raw.length&&raw[end][0]!==1001)end++;raw.splice(at,end-at);}}
            if(!raw)raw=[[0,type],[100,'AcDbEntity'],[8,e.layer],[100,type==='LINE'?'AcDbLine':'AcDbText']];
            raw.push([1001,'ERP_CAD_DIM']);for(let i=0;i<payload.length;i+=200)raw.push([1000,payload.slice(i,i+200)]);
            return newEntity(type,{...shape,id:e.id+'-'+index,color:e.color,lineWeight:e.lineWeight??25,lineType:e.lineType||'BYLAYER',raw,dirty:true},e.layer);
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
            const e=newDimension(m.a,m.b,m.placement,{id:'dim-'+group,groupId:group,mode:m.mode,axis:m.axis||undefined,textPosition:m.textPosition,height:m.height,color:m.color,lineWeight:m.lineWeight,lineType:m.lineType||'BYLAYER',layer:m.layer});
            const expected=dimensionParts(e),near=(a,b)=>Number.isFinite(a)&&Number.isFinite(b)&&Math.abs(a-b)<=Math.max(1,Math.abs(a),Math.abs(b))*1e-8;
            if(entries.some(({e:actual},i)=>{const wanted=expected[i];return !actual.editable||actual.type!==wanted.type||actual.layer!==wanted.layer||actual.color!==wanted.color||actual.lineWeight!==wanted.lineWeight||canonicalLineType(actual.lineType)!==canonicalLineType(wanted.lineType)||!near(actual.p.x,wanted.p.x)||!near(actual.p.y,wanted.p.y)||(actual.type==='LINE'? !near(actual.q.x,wanted.q.x)||!near(actual.q.y,wanted.q.y):actual.text!==wanted.text||!near(actual.height,wanted.height)||Math.abs(((actual.angle-wanted.angle+180)%360+360)%360-180)>1e-8);}))continue;
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
                if(type==='MTEXT'){const decoded=plainMText(e.text);e.text=decoded.text;e.width=num(r,41);e.plainText=decoded.safe&&num(r,71)===1&&num(r,72,1)===1&&num(r,44,1)===1&&!r.some(p=>[75,76,78,79].includes(p[0]));}
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
        return prepareDocumentAssets(restoreDimensions(g.CADFidelity ? g.CADFidelity.enhance(doc,pairs) : doc));
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
    function textBox(e){const lines=textLines(e),width=e.type==='MTEXT'&&e.width>0?e.width:Math.max(1,...lines.map(line=>Array.from(line).reduce((w,c)=>w+e.height*(c.charCodeAt(0)>255?1:.6),0))),height=e.height*lines.length*1.2,a=(e.angle||0)*Math.PI/180,u={x:Math.cos(a),y:Math.sin(a)},v={x:-Math.sin(a),y:Math.cos(a)};return {width,height,u,v,points:[[0,e.type==='MTEXT'?0:e.height],[width,e.type==='MTEXT'?0:e.height],[width,e.type==='MTEXT'?-height:-e.height*.2],[0,e.type==='MTEXT'?-height:-e.height*.2]].map(([x,y])=>({x:e.p.x+x*u.x+y*v.x,y:e.p.y+x*u.y+y*v.y}))};}
    function geometry(e) {
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
        if (!e.editable)
            throw Error(
                "지원하지 않는 객체는 원본 보존을 위해 편집할 수 없습니다.",
            );
        const n = clone(e),
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
        if (n.q) n.q = f(n.q);
        if (n.points) n.points = n.points.map(f);
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
    function exportDXF(d) {
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
            layerRecords.push(...r);
        }
        if (table >= 0) {
            let headerEnd = table + 2;
            while (headerEnd < end && prefix[headerEnd][0] !== 0) headerEnd++;
            let head = prefix.slice(table, headerEnd);
            const count = head.find((p) => p[0] === 70);
            if (count) count[1] = String(Object.keys(d.layers).length);
            else head.push([70, String(Object.keys(d.layers).length)]);
            prefix.splice(table, end - table + 1, ...head, ...layerRecords, [
                0,
                "ENDTAB",
            ]);
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
            if (tablesEnd >= 0) prefix.splice(tablesEnd, 0, ...chunk);
            else
                prefix.splice(
                    prefix.length - 2,
                    0,
                    [0, "SECTION"],
                    [2, "TABLES"],
                    ...chunk,
                    [0, "ENDSEC"],
                );
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
            prefix.splice(at, 0, ...records);
        }
        if(hasDimensions){
            let table=prefix.findIndex((p,i)=>p[0]===0&&p[1]==='TABLE'&&prefix[i+1]?.[1]==='APPID'),end=table;
            if(table>=0){while(end<prefix.length&&!(prefix[end][0]===0&&prefix[end][1]==='ENDTAB'))end++;}
            const exists=prefix.some((p,i)=>p[0]===0&&p[1]==='APPID'&&prefix.slice(i+1,i+9).some(p=>p[0]===2&&p[1]==='ERP_CAD_DIM'));
            if(!exists){const record=[[0,'APPID'],[5,next()],[100,'AcDbSymbolTableRecord'],[100,'AcDbRegAppTableRecord'],[2,'ERP_CAD_DIM'],[70,'0']];if(table>=0){prefix.splice(end,0,...record);const count=prefix.slice(table,end).find(p=>p[0]===70);if(count)count[1]=String(Number(count[1])+1);}else{let at=prefix.findIndex((p,i)=>p[0]===0&&p[1]==='SECTION'&&prefix[i+1]?.[1]==='TABLES');while(at<prefix.length&&!(prefix[at][0]===0&&prefix[at][1]==='ENDSEC'))at++;prefix.splice(at,0,[0,'TABLE'],[2,'APPID'],[70,'1'],...record,[0,'ENDTAB']);}}
        }
        let out = [...prefix];
        for (const e of d.entities) {
            if (!e.dirty && e.raw) {
                out.push(...e.raw);
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
            if (e.text !== undefined) {
                r = r.filter((p) => p[0] !== 3);
                if (e.type === "MTEXT")
                    r = r.filter((p) => ![11, 21, 31].includes(p[0]));
                const textValue=e.type==='MTEXT'?encodeMText(e.text):e.text;
                if(e.type==='MTEXT'){const encoded=String(encode(textValue));const chunks=encoded.match(/[\s\S]{1,200}/g)||[''];set(1,chunks.pop());const at=r.findIndex(p=>p[0]===1);r.splice(at,0,...chunks.map(chunk=>[3,chunk]));}else set(1,textValue);
                if (!r.some((p) => p[0] === 7)) set(7, styleName);
                set(40, e.height);
                if(e.type==='MTEXT'){r=r.filter(pair=>pair[0]!==50);const angle=(e.angle||0)*Math.PI/180;set(11,Math.cos(angle));set(21,Math.sin(angle));set(31,0);set(41,e.width||0);set(71,1);set(72,1);}else set(50,e.angle||0);
            }
            if (e.type === "LWPOLYLINE") {
                r = r.filter((p) => ![10, 20, 40, 41, 42, 91].includes(p[0]));
                set(90, e.points.length);
                set(70, e.closed ? 1 : 0);
                e.points.forEach((p) => {
                    const at = r.findIndex((p) => p[0] >= 1000);
                    r.splice(
                        at < 0 ? r.length : at,
                        0,
                        [10, String(p.x)],
                        [20, String(p.y)],
                    );
                });
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
            out.push(...r);
        }
        out.push(...suffix);
        const seed = out.findIndex((p) => p[0] === 9 && p[1] === "$HANDSEED");
        if (seed >= 0 && out[seed + 1]?.[0] === 5) out[seed + 1][1] = next();
        return (
            out.map((p) => p[0] + "\r\n" + encode(p[1])).join("\r\n") + "\r\n"
        );
    }
    function distance(a, b) {
        return Math.hypot(a.x - b.x, a.y - b.y);
    }
    function segmentDistance(p, a, b) {
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
        return distance(p, {
            x: a.x + t * (b.x - a.x),
            y: a.y + t * (b.y - a.y),
        });
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
        if (!Number.isFinite(d)) throw Error("오프셋 거리가 잘못되었습니다.");
        const n = clone(e);
        if (e.type === "LINE") {
            const len = distance(e.p, e.q);
            if (!len) throw Error("길이 0인 선은 오프셋할 수 없습니다.");
            const dx = (-(e.q.y - e.p.y) * d) / len,
                dy = ((e.q.x - e.p.x) * d) / len;
            n.p = { x: e.p.x + dx, y: e.p.y + dy };
            n.q = { x: e.q.x + dx, y: e.q.y + dy };
        } else if (e.type === "CIRCLE") {
            n.radius += d;
            if (n.radius <= 0) throw Error("원 반지름이 0 이하입니다.");
        } else throw Error("오프셋은 선 또는 원만 지원합니다.");
        n.id = "offset" + Math.random();
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
        const n = clone(e);
        if (extend) {
            if (t < 0) n.p = hit;
            else n.q = hit;
        } else if (distance(pick, a) < distance(pick, b)) n.p = hit;
        else n.q = hit;
        n.dirty = true;
        return n;
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
                if (x || y)
                    for (const e of es) {
                        const n = transform(e, { dx: x * dx, dy: y * dy });
                        n.id = "array" + Math.random();
            if(n.type==='ERP_DIMENSION'){n.groupId=uid();delete n.rawParts;}
                        if (n.raw)
                            n.raw = n.raw.filter(
                                (p) => ![5, 330, 360].includes(p[0]),
                            );
                        out.push(n);
                    }
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
        if(e.type==='ERP_DIMENSION'){const l=dimensionLayout(e);return [{key:'dimension-line',point:{x:l.a.x+(l.b.x-l.a.x)/3,y:l.a.y+(l.b.y-l.a.y)/3}},{key:'dimension-text',point:l.text}];}
        if(e.type==='MTEXT'&&e.editable){const b=textBox(e);return [{key:'text-width',point:{x:e.p.x+b.u.x*b.width,y:e.p.y+b.u.y*b.width}},{key:'text-size',point:{x:e.p.x-b.v.x*b.height,y:e.p.y-b.v.y*b.height}}];}
        if(e.type==='LINE')return [{key:'p',point:e.p},{key:'q',point:e.q}];
        if(!rectangleFrame(e))return [];
        return e.points.flatMap((p,i)=>[{key:'corner'+i,point:p},{key:'edge'+i,point:{x:(p.x+e.points[(i+1)%4].x)/2,y:(p.y+e.points[(i+1)%4].y)/2}}]);
    }
    function resize(e,key,p) {
        if(!e.editable||![p.x,p.y].every(Number.isFinite))throw Error('크기를 조절할 수 없는 객체입니다.');
        const n=clone(e);
        if(e.type==='ERP_DIMENSION'){if(key==='dimension-line')n.placement={...p};else if(key==='dimension-text')n.textPosition={...p};else throw Error('알 수 없는 치수 손잡이입니다.');n.dirty=true;return n;}
        if(e.type==='MTEXT'){const b=textBox(e),dx=p.x-e.p.x,dy=p.y-e.p.y;if(key==='text-width')n.width=Math.max(e.height,Math.abs(dx*b.u.x+dy*b.u.y));else if(key==='text-size')n.height=Math.max(.001,Math.abs(dx*b.v.x+dy*b.v.y)/(textLines(e).length*1.2));else throw Error('알 수 없는 문자 손잡이입니다.');n.dirty=true;return n;}
        if(e.type==='LINE'&&['p','q'].includes(key)){
            if(distance(p,e[key==='p'?'q':'p'])<1e-10)throw Error('선 길이는 0보다 커야 합니다.');
            n[key]={...p};n.dirty=true;return n;
        }
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
        const f=rectangleFrame(e);if(!f)return false;
        const x=(p.x-f.a.x)*f.u.x+(p.y-f.a.y)*f.u.y,y=(p.x-f.a.x)*f.v.x+(p.y-f.a.y)*f.v.y;
        return x>=0&&x<=f.w&&y>=0&&y<=f.h;
    }


    function quantities(e){
        const unavailable=reason=>({length:null,area:null,available:false,reason});
        if(e.raw&&([30,31,38,39,210,220].some(code=>num(e.raw,code)!==0)||num(e.raw,230,1)!==1))return unavailable('nonplanar_entity');
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
        if(e.raw&&([30,31,38,39,210,220].some(code=>num(e.raw,code)!==0)||num(e.raw,230,1)!==1))return null;
        const point=p=>({x:p.x,y:p.y});let result=null;
        if(e.type==='POINT')result={p:point(e.p)};
        if(e.type==='LINE')result={p:point(e.p),q:point(e.q)};
        if(e.type==='LWPOLYLINE')result={points:e.points.map(p=>({...point(p),...(p.bulge?{bulge:p.bulge}:{})})),closed:!!e.closed};
        if(e.type==='CIRCLE'||e.type==='ARC')result={center:point(e.p),radius:e.radius,...(e.type==='ARC'?{start:e.start,end:e.end}:{})};
        if(e.type==='TEXT'||e.type==='MTEXT'){if(e.text.length>2000)throw Error('공유할 문자 내용은 객체당 2,000자 이내여야 합니다. 해당 문자를 제외하고 선택해 주세요.');result={p:point(e.p),text:e.text,height:e.height,width:e.width||0,angle:e.angle||0};}
        if(e.type==='ERP_DIMENSION')result={p:point(e.p),q:point(e.q),placement:point(e.placement),mode:e.mode,height:e.height,...(e.axis?{axis:point(e.axis)}:{}),textPosition:e.textPosition?point(e.textPosition):null};
        function finite(value){if(typeof value==='number'&&(!Number.isFinite(value)||Math.abs(value)>1e12))throw Error('AI 공유/편집 좌표와 수치는 유한한 ±1조 범위여야 합니다.');if(Array.isArray(value))value.forEach(finite);else if(value&&typeof value==='object')Object.values(value).forEach(finite);}finite(result);return result;
    }
    function utf8Size(value){let bytes=0;for(const character of JSON.stringify(value)){const n=character.codePointAt(0);bytes+=n<=127?1:n<=2047?2:n<=65535?3:4;}return bytes;}
    function aiContext(doc,options){
        if(!['drawing','mm','cm','m','in','ft'].includes(options.unit)||!['selection','visible'].includes(options.scope)||!/^([a-f0-9]{64})$/.test(options.documentHash))throw Error('공유 범위·도면 단위·문서 해시를 확인하세요.');
        const selection=new Set(options.selection||[]),visible=doc.entities.filter(e=>!e.hidden&&!e.paper&&doc.layers[e.layer]?.visible!==false),es=options.scope==='selection'?visible.filter(e=>selection.has(e.id)):visible;
        if(options.scope==='selection'&&!es.length&&!options.allowEmpty)throw Error('공유할 객체를 먼저 선택하세요.');if(es.length>2000)throw Error('공유 객체가 2,000개를 넘습니다. 선택 범위를 줄이세요.');
        const layers=[...new Set(es.map(e=>e.layer))].map(name=>({name,color:doc.layers[name]?.color||'#e2e8f0',visible:true,locked:!!doc.layers[name]?.locked}));
        const entities=es.map(e=>({id:e.id,type:e.type,layer:e.layer,editable:!!e.editable&&!doc.layers[e.layer]?.locked&&aiGeometry(e)!==null,geometry:aiGeometry(e),style:{color:e.color===null||e.color===undefined?null:'#'+e.color.toString(16).padStart(6,'0'),lineType:e.lineType||'BYLAYER',lineWeight:e.lineWeight??-1},quantities:quantities(e)}));
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
            for(const id of action.entityIds){const index=doc.entities.findIndex(e=>e.id===id),e=doc.entities[index];if(!ids.has(id)||!e||!e.editable||e.hidden||e.paper||doc.layers[e.layer]?.locked||doc.layers[e.layer]?.visible===false)throw Error('공유 범위 밖·읽기 전용·숨김·잠금 객체는 편집할 수 없습니다.');let n;
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
        for (const e of shown.flatMap(e=>e.renderParts?.length?e.renderParts.filter(part=>!part.hidden&&doc.layers[part.layer]?.visible!==false).map(part=>({...part,id:e.id})):e.type==='ERP_DIMENSION'?C.dimensionParts(e).map(part=>({...part,id:e.id})):e)) {
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

    g.CADCore = {
        canvasColor,
        renderEntities,
        quantities, aiGeometry, aiContext, applyAIActions, utf8Size,
        uid, newDimension, dimensionLayout, dimensionParts, textLines, textBox, snapPoints,
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
        newEntity,
        clone,
        copyDocument,
        aci,
    };
})(typeof window === "undefined" ? globalThis : window);
