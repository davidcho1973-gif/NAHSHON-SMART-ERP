import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const box={};box.window=box;vm.createContext(box);
for(const name of ['cad-core','cad-fidelity','cad-sketch-tools'])vm.runInContext(fs.readFileSync(new URL('../../public/js/'+name+'.js',import.meta.url),'utf8'),box);
const C=box.CADCore,G=box.CADSketchGeometry;
const source='0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nLINE\n5\nA0\n8\n0\n10\n0\n20\n0\n11\n10\n21\n0\n1001\nFOREIGN\n1000\nuntouched\n0\nENDSEC\n0\nEOF\n';
const rect=()=>C.newEntity('LWPOLYLINE',{p:{x:0,y:0},points:[{x:0,y:0},{x:40,y:0},{x:40,y:20},{x:0,y:20}],closed:true});
const near=(a,b)=>assert.ok(Math.abs(a-b)<1e-7,`${a} != ${b}`);
test('2D rectangle offsets expand or contract, preserve source, reject collapse and curved polylines',()=>{
    const e=rect(),raw=JSON.stringify(e),outer=C.offset(e,5),inner=C.offset(e,-5);
    near(C.bounds([outer]).minX,-5);near(C.bounds([outer]).maxY,25);near(C.bounds([inner]).minX,5);near(C.bounds([inner]).maxY,15);
    assert.equal(JSON.stringify(e),raw);assert.throws(()=>C.offset(e,-11));assert.throws(()=>C.offset(e,-30));
    assert.throws(()=>C.offset({...e,points:e.points.map((p,i)=>({...p,bulge:i===0?.5:0}))},5));
    const reversed={...e,points:[...e.points].reverse()};near(C.bounds([C.offset(reversed,5)]).minX,-5);
    const r=C.parse(source);r.entities.push(outer);const saved=C.parse(C.exportDXF(r));near(C.bounds([saved.entities[1]]).maxX,45);
});
test('native arc offset keeps radius and exact angle data',()=>{const e=C.newEntity('ARC',{p:{x:8,y:5},radius:20,start:20,end:240}),n=C.offset(e,3);near(n.radius,23);assert.equal(n.start,20);assert.equal(n.end,240);assert.throws(()=>C.offset(e,-20));});
test('selection groups, hidden state and locks persist independently from unstable entity ids',()=>{
    const d=C.parse(source);Object.assign(d.entities[0],{sketchGroup:'group_123',sketchLocked:true,hidden:true,dirty:true});
    d.entities.unshift(rect());const out=C.exportDXF(d),r=C.parse(out),e=r.entities[1];assert.notEqual(e.id,d.entities[1].id);assert.equal(e.sketchGroup,'group_123');assert.equal(e.sketchLocked,true);assert.equal(e.hidden,true);assert.ok(e.raw.some(p=>p[1]==='untouched'));assert.ok(r.prefix.some(p=>p[0]===2&&p[1]==='ERP_CAD_EDIT'));
    Object.assign(e,{sketchGroup:undefined,sketchLocked:false,hidden:false,dirty:true});const unlocked=C.parse(C.exportDXF(r)).entities[1];assert.equal(unlocked.sketchGroup,undefined);assert.ok(!unlocked.sketchLocked);assert.equal(unlocked.hidden,false);assert.ok(unlocked.raw.some(p=>p[1]==='untouched'));
});
test('dimension regrouping retains shared edit metadata after native-part reassembly',()=>{
    const d=C.parse(source),dim=C.newDimension({x:0,y:0},{x:20,y:0},{x:0,y:10});Object.assign(dim,{sketchGroup:'dim_group',sketchLocked:true,hidden:true});d.entities.push(dim);const saved=C.parse(C.exportDXF(d)).entities.find(e=>e.type==='ERP_DIMENSION');assert.ok(saved);assert.equal(saved.sketchGroup,'dim_group');assert.equal(saved.sketchLocked,true);assert.equal(saved.hidden,true);
});
test('three picked points produce an exact native arc through the bulge point',()=>{const a={x:0,y:0},b={x:20,y:0},h={x:10,y:10},arc=G.arc(a,b,h);near(arc.p.x,10);near(arc.p.y,0);near(arc.radius,10);const e=C.newEntity('ARC',arc);assert.ok(C.geometry(e).some(p=>dist(p,h)<.01));assert.throws(()=>G.arc(a,b,{x:5,y:0}));function dist(a,b){return Math.hypot(a.x-b.x,a.y-b.y);}});
test('window selection distinguishes crossing geometry from full containment',()=>{const box=[{x:0,y:0},{x:10,y:0},{x:10,y:10},{x:0,y:10}],line=C.newEntity('LINE',{p:{x:-5,y:5},q:{x:15,y:5}});assert.ok(G.regionHit(line,box,false));assert.ok(!G.regionHit(line,box,true));assert.ok(G.regionHit(C.newEntity('LINE',{p:{x:2,y:5},q:{x:8,y:5}}),box,true));});
test('unknown edit metadata remains protected; valid group metadata permits independent copies',()=>{
    const unknown=source.replace('FOREIGN\n1000\nuntouched','ERP_CAD_EDIT\n1000\n{"v":2,"group":"future"}'),d=C.parse(unknown);
    assert.equal(d.entities[0].sketchGroup,undefined);assert.ok(!C.allows(d.entities[0],'copy'));d.entities[0]=C.transform(d.entities[0],{dx:1});assert.ok(C.exportDXF(d).includes('"v":2'));
    const plain=C.parse(source.replace('1001\nFOREIGN\n1000\nuntouched\n',''));Object.assign(plain.entities[0],{sketchGroup:'safe_group',sketchLocked:false,dirty:true});const e=C.parse(C.exportDXF(plain)).entities[0];assert.ok(C.allows(e,'copy'));
});
