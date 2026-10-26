import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const box={};box.window=box;vm.createContext(box);
for(const n of ['cad-core','cad-move-tools'])vm.runInContext(fs.readFileSync(new URL('../../public/js/'+n+'.js',import.meta.url),'utf8'),box);
const C=box.CADCore,G=box.CADMoveGeometry,settings={unit:'drawing',mmPerUnit:1};
const near=(a,b)=>assert.ok(Math.abs(a-b)<1e-7,`${a} != ${b}`);
const line=(x1,y1,x2,y2)=>C.newEntity('LINE',{p:{x:x1,y:y1},q:{x:x2,y:y2}});
const plain=o=>JSON.parse(JSON.stringify(o));
test('move accepts signed physical lengths, absolute and relative XY coordinates and polar directions',()=>{
    const a={x:100,y:200},p={x:103,y:204};
    let q=G.target('-10mm',a,p,settings);near(q.x,94);near(q.y,192);
    q=G.target('<-1m,2ft,0>',a,p,settings);near(q.x,-900);near(q.y,809.6);
    assert.deepEqual(plain(G.target('[300mm,-40mm,0]',a,p,settings)),{x:300,y:-40});
    q=G.target('20mm<90',a,p,settings);near(q.x,100);near(q.y,220);
    for(const bad of ['[1,2,3]','<1,>','[1,2>','Infinity','-','[1e99,0]'])assert.throws(()=>G.target(bad,a,p,settings));
});
test('arrays have precise SketchUp copy counts and independent groups',()=>{
    const a=line(0,0,0,5),b=line(0,5,5,5);a.sketchGroup=b.sketchGroup='group';
    const copies=G.copies([a,b],{x:10,y:20},G.arrayInput('5x'));
    assert.equal(copies.length,10);near(copies[8].p.x,50);near(copies[8].p.y,100);
    assert.equal(copies[0].sketchGroup,copies[1].sketchGroup);assert.notEqual(copies[0].sketchGroup,copies[2].sketchGroup);
    assert.notEqual(copies[0].sketchGroup,a.sketchGroup);
    const inside=G.copies([a],{x:40,y:0},G.arrayInput('/4'));assert.deepEqual(plain(inside.map(e=>e.p.x)),[10,20,30,40]);
    assert.deepEqual(plain(G.arrayInput('*3')),{count:3,kind:'repeat'});assert.equal(G.arrayInput('5mm'),null);
    assert.throws(()=>G.arrayInput('501x'));assert.throws(()=>G.arrayInput('0/'));
    assert.throws(()=>G.copies(Array(101).fill(a),{x:1,y:0},{count:500,kind:'repeat'}));
});
test('moving a vertex stretches all connected raw edges without moving their other ends',()=>{
    const a=line(0,0,10,0),b=line(10,0,10,10),c=line(10,0,20,0);c.sketchGroup='independent';
    const before=JSON.stringify([a,b,c]);const plan=G.stretchPlan([a,b,c],[a],{point:{x:10,y:0},tolerance:.5});
    assert.equal(plan.length,2);const result=G.stretch(plan,{x:5,y:2});
    assert.deepEqual(plain(result[0].p),{x:0,y:0});assert.deepEqual(plain(result[0].q),{x:15,y:2});
    assert.deepEqual(plain(result[1].p),{x:15,y:2});assert.deepEqual(plain(result[1].q),{x:10,y:10});
    assert.equal(JSON.stringify([a,b,c]),before);assert.throws(()=>G.stretch(plan,{x:-10,y:0}));
});
test('polyline edge stretching moves both vertices and preselected grouped geometry stays rigid',()=>{
    const e=C.newEntity('LWPOLYLINE',{points:[{x:0,y:0},{x:10,y:0},{x:10,y:10},{x:0,y:10}],p:{x:0,y:0},closed:true});
    const plan=G.stretchPlan([e],[e],{point:{x:10,y:5},tolerance:.5});const result=G.stretch(plan,{x:3,y:0})[0];
    assert.deepEqual(plain(result.points.map(p=>p.x)),[0,13,13,0]);assert.deepEqual(plain(e.points.map(p=>p.x)),[0,10,10,0]);
    e.sketchGroup='g';const grouped=G.stretchPlan([e],[e],{point:{x:10,y:0},tolerance:.5});assert.equal(grouped[0].indices,null);
});
test('shared XY coordinates at different elevations or across groups are not connected',()=>{
    const a=line(0,0,10,0),b=line(10,0,10,10);b.raw=[[30,'100'],[31,'100']];
    assert.equal(G.stretchPlan([a,b],[a]).length,1);
    a.sketchGroup=b.sketchGroup='g';b.raw=[];
    const plan=G.stretchPlan([a,b],[a],null,false,'g');assert.equal(plan.length,2);near(G.stretch(plan,{x:2,y:0})[1].p.x,12);
});
test('array bounds are checked for the last copy before a document can be changed',()=>{
    assert.throws(()=>G.copies([line(0,0,10,0)],{x:1e8,y:0},{count:20,kind:'repeat'}));
    assert.throws(()=>G.copies([line(0,0,10,0)],{x:1,y:0},{count:NaN,kind:'repeat'}));
});
test('connection points survive DXF, copy, translation, rotation, and reflection',()=>{
    const source='0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n';
    const d=C.parse(source),e=line(0,0,10,0);e.sketchGroup='g';e.sketchSnaps=Array.from({length:16},(_,i)=>({x:i+.123,y:0,angle:0}));d.entities=[e];
    const encoded=C.exportDXF(d),r=C.parse(encoded).entities[0];assert.equal(r.sketchSnaps.length,16);near(r.sketchSnaps[15].x,15.123);assert.ok(C.allows(r,'copy'));
    const n=C.transform(r,{angle:90,center:{x:0,y:0},dx:5,dy:10});near(n.sketchSnaps[0].x,5);near(n.sketchSnaps[0].y,10.123);near(n.sketchSnaps[0].angle,90);
    const copy=C.copyEntities([n])[0];assert.notEqual(copy.sketchGroup,n.sketchGroup);near(copy.sketchSnaps[0].y,10.123);
    const mirrored=C.transform(n,{mirror:'y'});near(mirrored.sketchSnaps[0].x,-5);near(mirrored.sketchSnaps[0].angle,90);
    e.sketchSnaps=[{x:NaN,y:0,angle:0}];assert.throws(()=>C.exportDXF(d));
});
