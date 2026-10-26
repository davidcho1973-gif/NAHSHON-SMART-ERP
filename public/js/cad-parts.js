(function (root) {
    'use strict';
    const P = {}, categories = {valve:'밸브', sprinkler:'스프링클러', duct:'덕트', other:'기타'};
    const primitiveKinds = ['area','ellipse','pen','route','text','callout'];
    P.categories = categories;
    P.pointCount = part => (part?.geometry?.items || []).reduce((n, item) => n + item.points.length + (item.holes || []).reduce((s,r) => s+r.length,0),0);
    P.valid = item => {
        const p=item.part, g=p?.geometry, ps=item.points;
        if (!p || !/^[0-9a-f-]{36}$/i.test(p.id) || !p.name || p.name.length>100 || !categories[p.category] || !g || ps.length!==4 || item.holes) return false;
        if (![g.width,g.height].every(n=>Number.isFinite(n)&&n>=1e-6&&n<=1e6) || !Array.isArray(g.items) || !g.items.length || g.items.length>50 || P.pointCount(p)>1500) return false;
        if (typeof p.specification !== 'string' || p.specification.length>100 || typeof p.code !== 'string' || p.code.length>80) return false;
        if (g.items.some(m=>!primitiveKinds.includes(m.kind)||m.part||!root.CADMarkupGeometry.valid(m))) return false;
        if (g.items.some(m=>root.CADMarkupGeometry.rings(m).some(r=>r.some(q=>q.x < -1e-6 || q.y < -1e-6 || q.x > g.width+1e-6 || q.y > g.height+1e-6)))) return false;
        if (new TextEncoder().encode(JSON.stringify(g)).length>100000) return false;
        const [a,b,c,d]=ps, ux=b.x-a.x,uy=b.y-a.y,vx=d.x-a.x,vy=d.y-a.y,w=Math.hypot(ux,uy),h=Math.hypot(vx,vy);
        return w>=1e-6 && h>=1e-6 && Math.abs(ux*vy-uy*vx)>=1e-12 && Math.abs(ux*vx+uy*vy)<=w*h*1e-7 &&
            Math.hypot(a.x+c.x-b.x-d.x,a.y+c.y-b.y-d.y)<=Math.max(w,h)*1e-7 && Math.abs(h/w-g.height/g.width)<=Math.max(1,g.height/g.width)*1e-7;
    };
    P.geometry = items => {
        if (!items.length || items.length>50 || items.some(m=>!primitiveKinds.includes(m.kind)||m.part)) throw Error('선·도형·문자를 1~50개 선택하세요. 다른 부품이나 상세도는 포함할 수 없습니다.');
        const G=root.CADMarkupGeometry, b=G.bounds(items), width=b.maxX-b.minX,height=b.maxY-b.minY;
        if (width<1e-6||height<1e-6) throw Error('너비와 높이가 있는 기호를 선택하세요.');
        const factor=100/Math.max(width,height);
        const copies=items.map(m=>{
            const next=G.map(m,p=>({x:(p.x-b.minX)*factor,y:(p.y-b.minY)*factor}));
            next.id=crypto.randomUUID();next.size*=factor;next.locked=false;
            if(next.textStyle?.boxWidth)next.textStyle.boxWidth*=factor;
            for(const key of ['group','layer','symbol','label'])delete next[key];
            return next;
        });
        const geometry={width:width*factor,height:height*factor,items:copies};
        if(P.pointCount({geometry})>1500||new TextEncoder().encode(JSON.stringify(geometry)).length>100000)throw Error('기호는 1,500개 점과 100KB 이내로 저장하세요.');
        return geometry;
    };
    P.place = (part, center, width, angle=0) => {
        if(!Number.isFinite(width)||width<=1e-6||!Number.isFinite(angle))throw Error('배치 크기와 각도를 확인하세요.');
        const h=width*part.geometry.height/part.geometry.width, r=angle*Math.PI/180, c=Math.cos(r),s=Math.sin(r);
        const points=[[-width/2,-h/2],[width/2,-h/2],[width/2,h/2],[-width/2,h/2]].map(([x,y])=>({x:center.x+x*c-y*s,y:center.y+x*s+y*c}));
        return {id:crypto.randomUUID(),kind:'part',points,color:part.geometry.items[0].color,opacity:1,width:2,size:width/10,pattern:'solid',text:'',locked:false,part:structuredClone(part)};
    };
    P.expanded = item => {
        const [a,b,,d]=item.points,g=item.part.geometry, ux=(b.x-a.x)/g.width,uy=(b.y-a.y)/g.width,vx=(d.x-a.x)/g.height,vy=(d.y-a.y)/g.height;
        const factor=Math.hypot(ux,uy),angle=Math.atan2(uy,ux)*180/Math.PI;
        return g.items.map(child=>{
            const next=root.CADMarkupGeometry.map(child,p=>({x:a.x+p.x*ux+p.y*vx,y:a.y+p.x*uy+p.y*vy}));
            next.size*=factor;next.opacity*=item.opacity;next.width*=item.width/2;
            const mainColor=g.items[0].color;
            if(next.color===mainColor)next.color=item.color;
            if(next.fillColor===mainColor)next.fillColor=item.color;
            if(item.dash!==undefined)next.dash=item.dash;
            if(next.textStyle?.boxWidth)next.textStyle.boxWidth*=factor;
            if(['text','callout'].includes(next.kind))next.rotation=((next.rotation||0)+angle)%360;
            return next;
        });
    };
    P.draw = (ctx,item,screen,scale,options) => P.expanded(item).forEach(child=>root.CADMarkup.draw(ctx,child,screen,scale,options));
    P.resize = (item, index, point) => {
        const fixed=item.points[(index+2)%4], old=item.points[index],dx=old.x-fixed.x,dy=old.y-fixed.y;
        const factor=((point.x-fixed.x)*dx+(point.y-fixed.y)*dy)/(dx*dx+dy*dy);
        if(!Number.isFinite(factor)||factor<=1e-6)return item;
        return root.CADMarkupGeometry.transform([item],0,factor,fixed)[0];
    };
    P.tally = items => {
        const rows=new Map();
        for(const item of items){if(item.kind!=='part')continue;const part=item.part;
            if(!rows.has(part.id))rows.set(part.id,{id:part.id,name:part.name,category:part.category,specification:part.specification,code:part.code,quantity:0,placements:[]});
            const row=rows.get(part.id);row.quantity++;row.placements.push(item.id);
        }
        return [...rows.values()].sort((a,b)=>a.category.localeCompare(b.category)||a.name.localeCompare(b.name));
    };
    P.csv = (rows, record, dirty) => {
        const cell=v=>{let s=String(v??'');if(/^[\s]*[=+@-]/.test(s))s="'"+s;return '"'+s.replaceAll('"','""')+'"';};
        return '\uFEFF'+[['도면 ID','도면','기준 개정','상태','분류','부품','규격','코드','단위','수량','배치 ID'],...rows.map(r=>[record.id,record.title,record.revision,dirty?'미저장 작업':'저장본',categories[r.category],r.name,r.specification,r.code,'EA',r.quantity,r.placements.join(' ')])].map(r=>r.map(cell).join(',')).join('\r\n');
    };
    P.starter = category => {
        const base={color:'#38bdf8',opacity:1,width:2,size:8,pattern:'solid',text:'',locked:false};
        const make=(kind,points,extra={})=>({...base,id:crypto.randomUUID(),kind,points:points.map(([x,y])=>({x,y})),...extra});
        const line=points=>make('pen',points);
        let items;
        if(category==='valve')items=[line([[0,50],[20,50]]),line([[80,50],[100,50]]),make('area',[[20,20],[50,50],[20,80]],{fillColor:'none'}),make('area',[[80,20],[50,50],[80,80]],{fillColor:'none'}),line([[50,50],[50,10],[35,10],[65,10]])];
        else if(category==='sprinkler')items=[make('ellipse',[[15,15],[85,15],[85,85],[15,85]],{fillColor:'none'}),line([[0,50],[100,50]]),line([[50,0],[50,100]])];
        else items=[make('area',[[0,20],[100,20],[100,80],[0,80]],{fillColor:'none'}),line([[20,20],[20,80]]),line([[80,20],[80,80]]),line([[40,50],[65,50],[55,40]]),line([[65,50],[55,60]])];
        return {width:100,height:100,items};
    };
    root.CADParts=P;

    root.createCADPartLibrary = api => {
        const button=document.createElement('button');button.id='cad-parts-open';button.textContent='회사 공용 부품함';document.querySelector('header').append(button);
        const dialog=document.createElement('dialog');dialog.id='cad-parts-dialog';dialog.className='cad-dialog';
        dialog.innerHTML='<h2>회사 공용 부품함</h2><p id="pl-company"></p><p class="note">부품 한 번 배치 = 1개. 덕트·배관의 길이와 실제 시공량은 별도입니다. 도면 위 표시로 저장되며 PDF에 포함됩니다.</p><div class="action-grid"><input id="pl-search" maxlength="100" placeholder="이름·규격·코드 검색"><button id="pl-find">검색</button></div><select id="pl-list" size="6" aria-label="회사 공용 부품"></select><button id="pl-more" hidden>더 불러오기</button><canvas id="pl-preview" width="400" height="180" aria-label="부품 미리보기"></canvas><div class="action-grid"><label>배치 너비 · 도면 좌표 단위<input id="pl-width" type="number" min="0.000001" step="any"></label><label>회전 각도<input id="pl-angle" type="number" step="any" value="0"></label></div><div class="action-grid"><button id="pl-place">도면에서 위치 선택</button><button id="pl-archive">선택 부품 사용 중지</button></div><details><summary>회사 공용으로 새 부품 저장</summary><p class="note">회사에서 도면을 편집하는 사용자가 함께 사용합니다. 저장된 정의는 고정되며 수정본은 새 부품으로 등록합니다.</p><label>이름<input id="pl-name" maxlength="100"></label><label>분류<select id="pl-category"><option value="valve">밸브</option><option value="sprinkler">스프링클러</option><option value="duct">덕트</option><option value="other">기타</option></select></label><label>규격<input id="pl-spec" maxlength="100" placeholder="예: 2 inch · 600 × 300"></label><label>자재 코드<input id="pl-code" maxlength="80"></label><div class="action-grid"><button id="pl-save-selection">선택한 선·도형·문자 저장</button><button id="pl-save-starter">분류의 기본 기호 저장</button></div><p class="note">기본 기호는 편집용 예시입니다. 프로젝트 범례에 맞는 기호를 등록하세요.</p></details><h3>현재 도면 부품 수량</h3><p id="pl-basis"></p><div id="pl-counts"></div><button id="pl-csv">수량 CSV 저장</button><p id="pl-message" role="status"></p><button id="pl-close">닫기</button>';
        document.body.append(dialog);
        const $=id=>document.getElementById('pl-'+id);let rows=[],next=null,selected=null,openedId=null,placing=null,hover=null,loading=false,requestNo=0;
        const state=()=>api.state(), message=s=>{$('message').textContent=s;api.status(s);};
        const editable=()=>state().record&&state().canWrite&&!state().busy&&!api.markup.pending();
        const permissions=()=>{for(const id of ['archive','save-selection','save-starter'])$(id).disabled=!editable();};
        const same=id=>state().record?.id===id;
        const request=(suffix,options)=>api.request('/'+openedId+'/parts'+suffix,options);
        function quantities(){
            const s=state(),data=P.tally(s.doc?.markups||[]);$('counts').replaceChildren();
            $('basis').textContent=`${s.record?.title||''} · ${s.dirty?'미저장 작업 포함':'저장 개정 '+s.record?.revision} · 숨긴 레이어 포함 · 단위 EA`;
            for(const row of data){const b=document.createElement('button');b.textContent=`${categories[row.category]} · ${row.name} · ${row.specification||'규격 없음'} · ${row.quantity}개`;b.onclick=()=>{dialog.close();api.markup.setMode('markup');api.markup.select(row.placements);api.focus(root.CADMarkupGeometry.bounds((s.doc.markups||[]).filter(m=>row.placements.includes(m.id))));};$('counts').append(b);}
            if(!data.length)$('counts').textContent='배치한 공용 부품이 없습니다.';
            $('csv').disabled=!data.length||!s.canExport;
        }
        async function search(append=false){
            const id=openedId,token=++requestNo;loading=true;selected=null;$('place').disabled=true;preview();permissions();
            try{const data=await request('?search='+encodeURIComponent($('search').value)+(append&&next?'&after='+encodeURIComponent(next):''));if(!same(id)||token!==requestNo)return;
                rows=append?[...rows,...data.items]:data.items;next=data.next;$('list').replaceChildren(...rows.map(p=>new Option(`${categories[p.category]} · ${p.name} · ${p.specification||''} ${p.code||''}`,p.id)));$('more').hidden=!next;$('company').textContent=data.company||'현재 도면의 회사';message(`${rows.length}개 부품`);
            }catch(e){message(e.message);}finally{if(token===requestNo)loading=false;}
        }
        function preview(){const ctx=$('preview').getContext('2d');ctx.clearRect(0,0,400,180);if(!selected)return;const item=P.place(selected,{x:200,y:90},Math.min(320,140*selected.geometry.width/selected.geometry.height));P.draw(ctx,item,p=>({x:p.x,y:180-p.y}),1,{});}
        $('list').onchange=async()=>{const id=openedId,key=$('list').value;selected=null;$('place').disabled=true;try{const p=await request('/'+key);if(!same(id)||$('list').value!==key)return;selected=p;preview();$('place').disabled=!editable();}catch(e){message(e.message);}};
        button.onclick=()=>{const s=state();if(!s.record||s.busy){message('도면을 먼저 열고 작업을 마무리하세요.');return;}placing=null;openedId=s.record.id;$('width').value=Number((80/api.scale()).toPrecision(6));$('angle').value=0;quantities();dialog.showModal();dialog.scrollTop=0;search();};
        $('find').onclick=()=>search();$('more').onclick=()=>search(true);$('close').onclick=()=>dialog.close();
        async function save(starter){if(!editable()||!same(openedId)||loading)return;const name=$('name').value.trim();if(!name){message('부품 이름을 입력하세요.');return;}loading=true;const id=openedId;
            try{const category=$('category').value;if(starter&&category==='other')throw Error('기타 부품은 도면에서 선·도형·문자를 선택해 저장하세요.');const geometry=starter?P.starter(category):P.geometry(api.markup.selected());
                const saved=await request('',{method:'POST',body:JSON.stringify({name,category,specification:$('spec').value.trim(),code:$('code').value.trim(),geometry})});
                if(!same(id))return;$('search').value='';await search();$('list').value=saved.id;selected=saved;preview();$('place').disabled=false;message('회사 공용 부품으로 저장했습니다.');
            }catch(e){message(e.message);}finally{loading=false;}}
        $('save-selection').onclick=()=>save(false);$('save-starter').onclick=()=>save(true);
        $('place').onclick=()=>{if(!selected||!editable()||!same(openedId))return;try{const width=Number($('width').value),angle=Number($('angle').value);P.place(selected,{x:0,y:0},width,angle);placing={part:selected,width,angle,drawingId:openedId};hover=null;api.markup.setMode('markup');api.markup.setTool('select');dialog.close();message('도면에서 배치할 위치를 클릭하세요. Esc로 취소합니다.');}catch(e){message(e.message);}};
        api.canvas.parentElement.addEventListener('pointermove',e=>{if(e.target!==api.canvas||!placing)return;hover=api.world(e);api.render();},true);
        api.canvas.parentElement.addEventListener('pointerdown',e=>{if(e.target!==api.canvas||!placing||e.button!==0)return;e.preventDefault();e.stopImmediatePropagation();const pending=placing;placing=null;hover=null;
            if(!same(pending.drawingId)||!editable()){message('도면 또는 편집 상태가 변경되어 배치를 취소했습니다.');api.render();return;}
            try{const item=P.place(pending.part,api.snap(api.world(e)),pending.width,pending.angle);if(api.markup.apply([...api.markup.items(),item],[item.id]))message('부품 1개 배치 · Ctrl+D로 복사 · Ctrl+S로 도면 저장');}catch(error){message(error.message);api.render();}},true);
        document.addEventListener('keydown',e=>{if(e.key==='Escape'&&placing){placing=null;hover=null;api.render();}},true);
        $('archive').onclick=async()=>{if(!selected||!editable()||loading||!same(openedId))return;if(!confirm('공용 부품함에서 사용 중지할까요? 이미 저장한 도면은 유지됩니다.'))return;loading=true;try{await request('/'+selected.id,{method:'DELETE'});await search();preview();message('사용 중지했습니다. 기존 도면의 부품과 수량은 유지됩니다.');}catch(e){message(e.message);}finally{loading=false;}};
        $('csv').onclick=()=>{const s=state();if(!s.record||!same(openedId)||!s.canExport)return;const csv=P.csv(P.tally(s.doc.markups||[]),s.record,s.dirty),url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download=`cad-${s.record.id}-r${s.record.revision}-parts${s.dirty?'-draft':''}.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
        return {render(ctx,screen,scale){if(placing&&hover&&same(placing.drawingId))P.draw(ctx,{...P.place(placing.part,hover,placing.width,placing.angle),opacity:0.5},screen,scale,{});},pending:()=>!!placing};
    };
})(typeof window==='undefined'?globalThis:window);
