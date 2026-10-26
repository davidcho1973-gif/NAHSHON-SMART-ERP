import test from 'node:test';import assert from 'node:assert/strict';import fs from 'node:fs';import vm from 'node:vm';const box={};vm.createContext(box);vm.runInContext(fs.readFileSync(new URL('../../public/js/cad-core.js',import.meta.url),'utf8'),box);const C=box.CADCore,near=(a,b)=>assert.ok(Math.abs(a-b)<1e-7,`${a} != ${b}`);
test('strict length parser respects source and selected units and imperial fractions',()=>{for(const [text,value]of [['1200mm',1200],['1.2m',1200],['5\'6 1/2"',1689.1],['5\'0"',1524],['0\'6"',152.4],['1/2in',12.7],['6 1/2"',165.1]])near(C.parseLengthInput(text,{mmPerUnit:1}),value);near(C.parseLengthInput('100',{unit:'cm',mmPerUnit:1}),1000);near(C.parseLengthInput('25.4mm',{mmPerUnit:25.4}),1);near(C.parseLengthInput('5',{}),5);for(const text of ['','0','-1','Infinity','1/0in','10junk','1mm extra'])assert.throws(()=>C.parseLengthInput(text,{mmPerUnit:1}),text);assert.throws(()=>C.parseLengthInput('1m',{}));});
test('numeric line polar and rectangle partial dimensions stay precise',()=>{let r=C.numericDrawing('line',{x:2,y:3},{x:5,y:7},'10',{});near(r.q.x,8);near(r.q.y,11);r=C.numericDrawing('line',{x:0,y:0},{x:1,y:0},'100mm<80',{mmPerUnit:1});near(r.q.x,100*Math.cos(80*Math.PI/180));near(r.q.y,100*Math.sin(80*Math.PI/180));r=C.numericDrawing('circle',{x:0,y:0},{x:1,y:0},'D20mm',{mmPerUnit:1});near(r.length,10);r=C.numericDrawing('rectangle',{x:0,y:0},{x:5,y:-8},',20',{});near(r.q.x,5);near(r.q.y,-20);assert.throws(()=>C.numericDrawing('rectangle',{x:0,y:0},{x:5,y:8},','));assert.throws(()=>C.numericDrawing('line',{x:0,y:0},{x:0,y:0},'3'));assert.throws(()=>C.numericDrawing('rectangle',{x:0,y:0},{x:0,y:8},',20'));});
test('guide discrete points and intersections take precedence over projections',()=>{const line={id:'a',type:'line',p:{x:0,y:0},direction:{x:1,y:0}},vertical={id:'b',type:'line',p:{x:10,y:0},direction:{x:0,y:1}};let p=C.guideSnap({x:9.8,y:.1},[line,vertical],1);near(p.x,10);near(p.y,0);assert.equal(p.kind,'guide-intersection');p=C.guideSnap({x:4.2,y:.1},[line,{id:'p',type:'point',p:{x:4,y:0}}],1);near(p.x,4);assert.equal(p.kind,'guide-point');p=C.guideSnap({x:8,y:.4},[line],1);near(p.y,0);assert.equal(p.kind,'guide-projection');p=C.guideSnap({x:7.8,y:.2},[line],1,[{p:{x:8,y:-2},q:{x:8,y:2}}]);near(p.x,8);assert.equal(p.kind,'guide-intersection');});
test('direction inference distinguishes axes angle increments reference and explicit locks',()=>{let r=C.inferDirection({x:0,y:0},{x:10,y:.3},{enabled:true});near(r.point.y,0);assert.equal(r.kind,'x');r=C.inferDirection({x:0,y:0},{x:1,y:2},{lock:0});near(r.point.y,0);r=C.inferDirection({x:0,y:0},{x:1,y:1.03},{step:45});assert.equal(r.kind,'angle');r=C.inferDirection({x:0,y:0},{x:10*Math.cos(.4),y:10*Math.sin(.4)},{reference:23});assert.equal(r.kind,'parallel');r=C.inferDirection({x:0,y:0},{x:1,y:2},{enabled:false});near(r.point.y,2);assert.equal(r.kind,null);});
test('edge projection uses any point on the finite segment and handles zero length',()=>{
    const a={x:10,y:20},b={x:70,y:50};let p=C.segmentPoint({x:28,y:34},a,b);near(p.x,30);near(p.y,30);
    p=C.segmentPoint({x:-10,y:0},a,b);near(p.x,10);near(p.y,20);
    p=C.segmentPoint({x:100,y:70},a,b);near(p.x,70);near(p.y,50);
    p=C.segmentPoint({x:100,y:70},a,a);near(p.x,10);near(p.y,20);
});
test('parallel tape measures perpendicular spacing independent of longitudinal pointer drift',()=>{
    const edge={p:{x:10,y:20},q:{x:70,y:50}},a={x:30,y:30},u={x:2/Math.sqrt(5),y:1/Math.sqrt(5)},n={x:-u.y,y:u.x};
    for(const drift of [-100,0,100])for(const gap of [-12,12]){
        const cursor={x:a.x+u.x*drift+n.x*gap,y:a.y+u.y*drift+n.y*gap},m=C.parallelGuide(edge,a,cursor);
        near(m.offset,gap);near(C.distance(m.p,m.q),12);near(m.direction.x,u.x);near(m.direction.y,u.y);
        near((m.q.x-a.x)*u.x+(m.q.y-a.y)*u.y,0);
        const exact=C.parallelGuide(edge,a,cursor,25);near(exact.offset,gap<0?-25:25);near(C.distance(exact.p,exact.q),25);
    }
    let m=C.parallelGuide({p:{x:10,y:20},q:{x:10,y:70}},{x:10,y:30},{x:35,y:60});near(m.offset,-25);near(m.q.x,35);near(m.q.y,30);
    m=C.parallelGuide({p:edge.q,q:edge.p},a,{x:a.x-12*u.y,y:a.y+12*u.x});near(m.offset,-12);near(C.distance(m.p,m.q),12);
    assert.throws(()=>C.parallelGuide({p:a,q:a},a,a));assert.throws(()=>C.parallelGuide(edge,a,a,0));
});
