import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {createRequire} from 'node:module';
import test from 'node:test';
const require=createRequire(import.meta.url),fixture=require('../Fixtures/cad-native-edit.cjs');
const context=vm.createContext({});
for(const name of ['cad-core','cad-fidelity'])vm.runInContext(fs.readFileSync(`public/js/${name}.js`,'utf8'),context);
const C=context.CADCore,parse=()=>C.parse(fixture.source),near=(a,b)=>assert.ok(Math.abs(a-b)<1e-8,`${a} != ${b}`);
const native=doc=>doc.entities.filter(e=>e.type==='DIMENSION'),rawValue=(e,c)=>e.raw.find(p=>p[0]===c)?.[1];
const editedFixture=(updates,modify=()=>{})=>{
    const pairs=fixture.pairs.map(p=>[...p]),start=pairs.findIndex((p,i)=>p[0]===0&&p[1]==='DIMENSION'&&pairs[i+1]?.[1]==='F1');let end=start+1;while(end<pairs.length&&pairs[end][0]!==0)end++;
    const close=pairs.findIndex((p,i)=>i>start&&i<end&&p[0]===1002&&p[1]==='}');pairs.splice(close,0,...updates);modify(pairs,start);return C.parse(pairs.flatMap(p=>p.map(String)).join('\n')+'\n');
};

test('existing linear/aligned dimensions with ACAD style XDATA are editable native objects',()=>{
    const doc=parse(),dims=native(doc);assert.equal(dims.length,2);
    for(const e of dims){assert.equal(e.nativeDimension.compatible,true);assert.ok(C.allows(e,'move'));assert.ok(C.allows(e,'copy'));near(e.height,2.5);near(C.nativeDimensionLayout(e).value,40);assert.equal(C.nativeDimensionLayout(e).label,e.nativeDimension.kind===0?'40.00[1016]':'40.00');}
    assert.equal(C.exportDXF(doc).replace(/\r/g,''),fixture.source,'Unchanged source records remain exact');
});

test('dimension grip edits update independent measurement and label position, with undo state isolation',()=>{
    const doc=parse(),e=native(doc)[0],saved=C.copyDocument(doc);
    const end=C.resize(e,'dimension-end',{x:70,y:40});near(C.nativeDimensionLayout(end).value,60);
    const moved=C.resize(C.resize(end,'dimension-line',{x:40,y:65}),'dimension-text',{x:45,y:72});
    near(C.nativeDimensionLayout(moved).a.y,65);near(C.nativeDimensionLayout(moved).b.y,65);near(C.nativeDimensionLayout(moved).text.x,45);near(C.nativeDimensionLayout(moved).text.y,72);
    assert.equal(C.nativeDimensionLayout(saved.entities.find(n=>n.id===e.id)).value,40);assert.equal(e.nativeDimension.manualText,false);
    doc.entities=doc.entities.map(n=>n.id===e.id?{...moved,text:'검토 <> mm',height:4,color:0x7744aa,dirty:true}:n);
    const out=C.exportDXF(doc),reopened=C.parse(out),after=native(reopened)[0];
    assert.equal(after.type,'DIMENSION');near(C.nativeDimensionLayout(after).value,60);assert.equal(C.nativeDimensionLayout(after).label,'검토 60.00[1524] mm');near(after.height,4);near(after.textPosition.y,72);assert.equal(after.color,0x7744aa);
    assert.notEqual(rawValue(after,2),rawValue(e,2));assert.ok(reopened.prefix.some(p=>p[0]===2&&p[1]==='*D1'),'Original graphics definition remains unchanged');
    assert.ok(after.renderParts.some(p=>p.text?.includes('검토 60.00[1524] mm')),'New native block text matches the edited value');
});

test('rotated dimensions keep definition points on a shared dimension line',()=>{
    const doc=parse(),e=native(doc)[0];
    const moved=C.resize(e,'dimension-end',{x:50,y:50}),l=C.nativeDimensionLayout(moved);near(l.a.y,l.b.y);near(l.value,40);
    const rotated=C.transform(moved,{angle:90,center:{x:0,y:0}}),lr=C.nativeDimensionLayout(rotated);near(lr.value,40);near(lr.a.x,lr.b.x);
    const scaled=C.transform(rotated,{scale:2});near(C.nativeDimensionLayout(scaled).value,80);near(scaled.height,5);
});

test('saved dimension copies use distinct native blocks and handles without changing siblings',()=>{
    const doc=parse(),[first,second]=native(doc),before=JSON.stringify(second.raw);
    const copies=C.array([first],1,2,100,0);assert.equal(copies.length,1);doc.entities.push(...copies);
    const reopened=C.parse(C.exportDXF(doc)),dims=native(reopened);assert.equal(dims.length,3);
    assert.equal(new Set(dims.map(e=>rawValue(e,5))).size,3);assert.equal(new Set(dims.map(e=>rawValue(e,2))).size,3);
    assert.equal(JSON.stringify(dims.find(e=>rawValue(e,5)==='F2').raw),before);
    const copy=dims.find(e=>e.nativeDimension.a.x>100);near(copy.nativeDimension.a.x,110);near(C.nativeDimensionLayout(copy).value,40);
});

test('associated dimensions permit label and dimension-line placement without moving measured endpoints',()=>{
    const doc=parse(),first=native(doc)[0];
    const pairs=fixture.pairs.slice();const at=pairs.findIndex((p,i)=>p[0]===0&&p[1]==='DIMENSION'&&pairs[i+1]?.[1]==='F1');
    pairs.splice(at+2,0,[102,'{ACAD_REACTORS}'],[330,'FF0'],[102,'}']);
    const source=pairs.flatMap(p=>p.map(String)).join('\n')+'\n',d=C.parse(source),e=native(d)[0];
    assert.ok(e.nativeDimension.associated);assert.ok(C.allows(e,'text'));assert.ok(C.allows(e,'resize'));assert.equal(C.allows(e,'move'),false);assert.equal(C.allows(e,'copy'),false);
    assert.deepEqual(Array.from(C.grips(e),g=>g.key),['dimension-line','dimension-text']);
    assert.throws(()=>C.resize(e,'dimension-start',{x:15,y:30}),/연관|지원/);
    const n=C.resize(e,'dimension-line',{x:30,y:65});assert.equal(JSON.stringify(n.nativeDimension.a),JSON.stringify(first.nativeDimension.a));assert.equal(JSON.stringify(n.nativeDimension.b),JSON.stringify(first.nativeDimension.b));
    d.entities=d.entities.map(v=>v.id===e.id?{...n,text:'현장 <>',dirty:true}:v);
    const result=native(C.parse(C.exportDXF(d)))[0];assert.ok(result.raw.some(p=>p[0]===102&&p[1]==='{ACAD_REACTORS}'));near(C.nativeDimensionLayout(result).value,40);assert.equal(C.nativeDimensionLayout(result).label,'현장 40.00[1016]');
});

test('dimension text override remains distinct from actual measurement',()=>{
    const e=native(parse())[0];e.text='1200 지정값';assert.equal(C.nativeDimensionLayout(e).label,'1200 지정값');near(C.nativeDimensionLayout(e).value,40);
    e.text='<> + <> mm';assert.equal(C.nativeDimensionLayout(e).label,'40.00[1016] + 40.00[1016] mm');
});

test('spatial and unsupported angular dimension entities stay untouched',()=>{
    const source=fixture.source.replace('70\n32\n','70\n34\n'),doc=C.parse(source),e=native(doc)[0];assert.equal(e.nativeDimension.compatible,false);assert.equal(C.allows(e,'move'),false);assert.equal(C.exportDXF(doc).replace(/\r/g,''),source);
});

test('alternate dimensions retain converted secondary values and precision in native block text',()=>{
    const doc=editedFixture([[1070,170],[1070,1],[1070,143],[1040,25.4],[1070,171],[1070,0],[1070,273],[1070,2]]),e=native(doc)[0];
    assert.equal(C.nativeDimensionLayout(e).label,'40.00[1016]');
    doc.entities=doc.entities.map(n=>n.id===e.id?{...C.resize(e,'dimension-end',{x:60,y:40}),text:'검토 <>',dirty:true}:n);
    const after=native(C.parse(C.exportDXF(doc)))[0];assert.equal(C.nativeDimensionLayout(after).label,'검토 50.00[1270]');assert.ok(after.renderParts.some(p=>p.text==='검토 50.00[1270]'));
});

test('custom arrow block geometry and native INSERT references survive edit and reopen',()=>{
    const doc=editedFixture([[1070,342],[1005,'ABC1']],pairs=>{
        const tables=pairs.findIndex((p,i)=>p[0]===0&&p[1]==='SECTION'&&pairs[i+1]?.[1]==='TABLES');let tableEnd=tables+2;while(!(pairs[tableEnd][0]===0&&pairs[tableEnd][1]==='ENDSEC'))tableEnd++;
        pairs.splice(tableEnd,0,[0,'TABLE'],[2,'BLOCK_RECORD'],[5,'ABC0'],[70,1],[0,'BLOCK_RECORD'],[5,'ABC1'],[100,'AcDbSymbolTableRecord'],[100,'AcDbBlockTableRecord'],[2,'CUSTOM_TICK'],[70,0],[0,'ENDTAB']);
        const blocks=pairs.findIndex((p,i)=>p[0]===0&&p[1]==='SECTION'&&pairs[i+1]?.[1]==='BLOCKS');let blockEnd=blocks+2;while(!(pairs[blockEnd][0]===0&&pairs[blockEnd][1]==='ENDSEC'))blockEnd++;
        pairs.splice(blockEnd,0,[0,'BLOCK'],[5,'ABC2'],[2,'CUSTOM_TICK'],[70,0],[10,0],[20,0],[30,0],[0,'LINE'],[5,'ABC3'],[8,'0'],[62,0],[10,-.5],[20,-.5],[30,0],[11,.5],[21,.5],[31,0],[0,'ENDBLK'],[5,'ABC4']);
    }),e=native(doc)[0];assert.equal(e.nativeDimension.compatible,true);assert.equal(C.nativeDimensionParts(e).filter(p=>p.nativeArrow).length,2);assert.equal(C.nativeDimensionParts(e).filter(p=>p.type==='SOLID').length,0);
    doc.entities=doc.entities.map(n=>n.id===e.id?C.transform(e,{dx:5,dy:2}):n);
    const reopened=C.parse(C.exportDXF(doc)),after=native(reopened)[0];assert.equal(after.nativeDimension.arrows[0].name,'CUSTOM_TICK');assert.equal(reopened.prefix.filter(p=>p[0]===2&&p[1]==='CUSTOM_TICK').length,4,'Definition, table entry and two arrow INSERTs remain');assert.ok(after.renderParts.filter(p=>p.type==='LINE').length>=5);
});

test('long native labels are chunked and foreign XDATA cannot be silently duplicated',()=>{
    const doc=parse(),e=native(doc)[0];doc.entities=doc.entities.map(n=>n.id===e.id?{...n,text:'현장 검토 '.repeat(60)+'<>',dirty:true}:n);
    const reopened=C.parse(C.exportDXF(doc)),after=native(reopened)[0];assert.equal(C.nativeDimensionLayout(after).label,'현장 검토 '.repeat(60)+'40.00[1016]');assert.ok(after.renderParts.some(p=>p.text===C.nativeDimensionLayout(after).label));
    const foreign=editedFixture([], (pairs,start)=>{let end=start+1;while(end<pairs.length&&pairs[end][0]!==0)end++;pairs.splice(end,0,[1001,'VENDOR_DATA'],[1005,'F4']);});
    assert.equal(C.allows(native(foreign)[0],'copy'),false);assert.equal(C.allows(native(foreign)[0],'move'),true);
});

test('negligible elevation noise stays editable and every original Z is preserved',()=>{
    const doc=editedFixture([], (pairs,start)=>{let end=start+1;while(end<pairs.length&&pairs[end][0]!==0)end++;for(let i=start;i<end;i++){if(pairs[i][0]===33)pairs[i][1]=3e-8;if(pairs[i][0]===34)pairs[i][1]=-2e-8;}}),e=native(doc)[0];
    assert.equal(e.nativeDimension.compatible,true);doc.entities=doc.entities.map(n=>n.id===e.id?C.transform(e,{dx:3,dy:4}):n);const after=native(C.parse(C.exportDXF(doc)))[0];assert.equal(Number(rawValue(after,33)),3e-8);assert.equal(Number(rawValue(after,34)),-2e-8);
});

test('legacy DXF chunks native dimension graphics after Unicode escaping',()=>{
    const doc=C.parse(fixture.source.replace('AC1032','AC1015')),e=native(doc)[0],text='한글 검토 '.repeat(90)+'<>';
    doc.entities=doc.entities.map(n=>n.id===e.id?{...n,text,dirty:true}:n);
    const out=C.exportDXF(doc),reopened=C.parse(out),after=native(reopened)[0];
    assert.equal(after.text,text);assert.ok(reopened.prefix.filter(p=>p[0]===1||p[0]===3).every(p=>String(p[1]).length<=200),'Each anonymous-block MTEXT chunk stays within 200 encoded characters');
    assert.ok(after.renderParts.some(p=>(p.displayText??p.text)==='한글 검토 '.repeat(90)+'40.00[1016]'));
});

test('editing and rotating aligned dimensions never emits linear-only group code 50',()=>{
    const doc=parse(),e=native(doc)[1];assert.equal(e.nativeDimension.kind,1);
    const diagonal=C.resize(e,'dimension-end',{x:40,y:110});near(C.nativeDimensionLayout(diagonal).value,50);
    const moved=C.transform(diagonal,{angle:45,center:{x:0,y:0}});near(C.nativeDimensionLayout(moved).value,50);
    doc.entities=doc.entities.map(n=>n.id===e.id?moved:n);
    const after=native(C.parse(C.exportDXF(doc)))[1];assert.equal(after.nativeDimension.kind,1);assert.equal(after.raw.some(p=>p[0]===50),false,'Code 50 makes a native reader change aligned DIMENSION to linear');near(C.nativeDimensionLayout(after).value,50);
});

test('complex native dimension text formatting is preserved read-only instead of becoming literal',()=>{
    const source=fixture.source.replace('1\n<>\n3\nStandard','1\n{\\H2x;<>}\\S1/2;\n3\nStandard'),doc=C.parse(source),e=native(doc)[0];
    assert.equal(e.nativeDimension.compatible,false);assert.equal(C.allows(e,'text'),false);assert.equal(C.exportDXF(doc).replace(/\r/g,''),source);
    const plain=parse(),first=native(plain)[0];plain.entities=plain.entities.map(n=>n.id===first.id?{...n,text:'{\\H2x;<>}',dirty:true}:n);assert.throws(()=>C.exportDXF(plain),/문자|서식/);
});

test('ordinary dimension X/P line breaks retain alternate units and native source formatting',()=>{
    const doc=editedFixture([[1070,3],[1000,'<>\\X']]),e=native(doc)[0];
    assert.equal(e.nativeDimension.compatible,true);assert.equal(C.nativeDimensionLayout(e).label,'40.00\n[1016]');
    doc.entities=doc.entities.map(n=>n.id===e.id?{...n,text:'검토 <>\\P확인',dirty:true}:n);
    const reopened=C.parse(C.exportDXF(doc)),after=native(reopened)[0];
    assert.equal(after.nativeDimension.values[3],'<>\\X','Original DIMPOST line-break instruction is retained');assert.equal(after.text,'검토 <>\\P확인','Native dimension override retains its instruction');
    assert.equal(C.nativeDimensionLayout(after).label,'검토 40.00\n[1016]\n확인');assert.ok(after.renderParts.some(p=>(p.displayText??p.text)==='검토 40.00\n[1016]\n확인'));
});
