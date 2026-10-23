const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const context={window:{}};vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(__dirname,'../../public/js/drawing-studio-core.js'),'utf8'),context);const C=context.window.DrawingStudioCore;
test('takeoff preserves recorded scale, subtracts holes and rejects dimensional mismatch',()=>{
  const e={id:'room',type:'rect',points:[[.1,.1],[.6,.6]],holes:[[[.2,.2],[.3,.2],[.3,.3],[.2,.3]]],calibration:{widthPt:100,heightPt:100,feetPerPoint:1}};
  assert.ok(Math.abs(C.measure(e,200,300,2).area-2400)<1e-8);
  assert.ok(Math.abs(C.takeoff([e],'M2',{wPt:200,hPt:300,fpp:2}).quantity-222.9673)<1e-4);
  assert.throws(()=>C.takeoff([e],'LF',{wPt:100,hPt:100,fpp:1}),/길이 공정/);
  assert.throws(()=>C.takeoff([{type:'line',points:[[0,0],[1,0]]}],'SF',{wPt:100,hPt:100,fpp:1}),/면적 공정/);
  assert.equal(C.takeoff([{id:'light',type:'symbol',points:[[.2,.2]]}],'EA',{wPt:100,hPt:100,fpp:null}).quantity,1);
});
test('crossed, repeated, zero-area and overlapping deductions are rejected',()=>{
  assert.match(C.polygonError([[0,0],[1,1],[0,1],[1,0]]),/교차/);
  assert.match(C.polygonError([[0,0],[.5,0],[1,0]]),/0/);
  const e={type:'rect',points:[[.1,.1],[.9,.9]],holes:[[[.2,.2],[.4,.2],[.4,.4],[.2,.4]]]};
  assert.equal(C.holeError(e,[[.5,.5],[.6,.5],[.6,.6],[.5,.6]]),'');
  assert.match(C.holeError(e,[[.3,.3],[.5,.3],[.5,.5],[.3,.5]]),/겹칩니다/);
  assert.match(C.holeError(e,[[0,0],[.2,0],[.2,.2]]),/안에/);
});
test('offset uses physical axes and preserves source geometry; holes move with the outer ring',()=>{
  const e={type:'rect',points:[[.2,.2],[.5,.5]]},before=JSON.stringify(e);
  const n=C.offset(e,10,{wPt:200,hPt:100,fpp:1});assert.equal(n.type,'polygon');assert.equal(JSON.stringify(e),before);
  assert.ok(Math.abs(C.bounds(n.points).x-.15)<1e-10);assert.ok(Math.abs(C.bounds(n.points).y-.1)<1e-10);
  const moved=C.translate([{...e,holes:[[[.3,.3],[.4,.3],[.4,.4]]]}],.1,.1)[0];assert.ok(Math.abs(moved.holes[0][0][0]-.4)<1e-10);
});
test('three point arcs preserve endpoints and approximate physical semicircle length',()=>{
  const p=C.arcPoints([[.2,.5],[.5,.2],[.8,.5]],100,100);assert.ok(C.dist(p[0],[.2,.5],100,100)<1e-8);assert.ok(C.dist(p.at(-1),[.8,.5],100,100)<1e-8);
  const length=C.measure({type:'polyline',points:p},100,100,1).length;assert.ok(Math.abs(length-30*Math.PI)<.02);
  assert.throws(()=>C.arcPoints([[0,0],[.5,.5],[1,1]],100,100),/일직선/);
});
test('collinear boundary contacts, collapsed insets and intersection inference stay deterministic',()=>{
  assert.ok(C.intersection([0,0],[1,0],[.2,0],[.8,0]));
  assert.throws(()=>C.offset({type:'rect',points:[[.2,.2],[.4,.4]]},-15,{wPt:100,hPt:100,fpp:1}),/경계/);
  const e=[{type:'line',points:[[.1,.1],[.9,.9]]},{type:'line',points:[[.2,.6],[.8,.3]]}];
  assert.equal(C.snap([.468,.468],e,1000,1000,1).label,'교차점');
});
test('physical distance uses both page dimensions and scale, independent of raster zoom',()=>{const e={type:'line',points:[[.1,.1],[.4,.5]]};assert.equal(C.measure(e,100,100,.1).length,5);assert.ok(Math.abs(C.measure(e,200,100,.1).length-Math.hypot(60,40)*.1)<1e-10);assert.equal(C.measure(e,100,100,null).length,null);});
test('closed area, perimeter and angular geometry respect nonsquare pages',()=>{const r={type:'rect',points:[[0,0],[.5,.5]]},m=C.measure(r,200,100,.1);assert.equal(m.area,50);assert.equal(m.length,30);assert.equal(C.measure({type:'angle',points:[[1,0],[0,0],[0,1]]},200,100,null).angle,90);});
test('unit conversion and inch rounding carry correctly',()=>{assert.equal(C.format(1,'mm',2),'304.80 mm');assert.equal(C.format(1,'m',2,true),'0.09 m²');assert.equal(C.format(1.9999,'ft-in',2),'2′ 0″');assert.equal(C.format(null),'축척 필요');});
test('snap radius stays constant in screen pixels and can find segment midpoints',()=>{const a=[{type:'line',points:[[0,0],[1,0]]}];assert.equal(C.snap([.5,.001],a,1000,1000,1).label,'중점');assert.equal(C.snap([.5,.1],a,1000,1000,1),null);assert.ok(C.snap([.5,.1],a,1000,1000,.05));});
test('moving a group clamps once and preserves relative positions',()=>{const e=[{points:[[.2,.1],[.4,.2]]},{points:[[.7,.2],[.9,.3]]}];const m=C.translate(e,.4,-.5);assert.equal(m[1].points[1][0],1);assert.equal(m[0].points[0][1],0);assert.ok(Math.abs(m[1].points[0][0]-m[0].points[0][0]-.5)<1e-9);});
test('feature snaps win over an even nearer segment and ellipse snaps use real quadrants',()=>{const line=[{type:'line',points:[[0,0],[1,0]]}];assert.equal(C.snap([.502,.001],line,1000,1000,1).label,'중점');assert.equal(C.snap([.999,.001],line,1000,1000,1).label,'끝점');const ellipse=[{type:'ellipse',points:[[.2,.2],[.8,.8]]}];assert.equal(C.snap([.5,.2],ellipse,1000,1000,1).label,'사분점');assert.equal(C.snap([.2,.2],ellipse,1000,1000,1),null);});
test('freehand simplification retains endpoints and bends',()=>{const p=[[0,0],[.1,0],[.2,0],[.3,.2],[.4,.2]];assert.equal(C.simplify(p,1,100,100).length,4);});
test('SVG labels are escaped and measurement annotations retain calibration requirement',()=>{const e={type:'dimension',points:[[0,0],[1,0]],color:'#ff0000',fill:'none',width:1,opacity:1,dash:'solid',fontSize:12,text:'<script>alert(1)</script>'};const s=C.render(e,{W:100,H:100,wPt:100,hPt:100,fpp:null},{unit:'m',precision:2});assert.ok(!s.includes('<script>'));assert.ok(s.includes('&lt;script&gt;'));assert.ok(s.includes('축척 필요'));});
test('orthographic mode never collapses opposite-corner shapes, while shift preserves physical aspect',()=>{
  for(const type of ['cloud','rect','ellipse']){
    assert.deepEqual(Array.from(C.drawingPoint(type,[.1,.1],[.7,.4],800,600,true,false)),[.7,.4]);
    const square=C.drawingPoint(type,[.1,.1],[.4,.3],800,600,true,true);
    assert.ok(Math.abs((square[0]-.1)*800-(square[1]-.1)*600)<1e-9);
  }
  const line=C.drawingPoint('line',[.1,.1],[.7,.4],800,600,true,false);
  assert.ok(Math.abs(line[1]-.1)<1e-9);
});
test('revision clouds use closed round arcs with bounded counts, independent of draw direction and render scale',()=>{
  const shape={type:'cloud',points:[[.1,.1],[.7,.4]],color:'#ef4444',width:1.5,fill:'none',opacity:1,fontSize:12,dash:'solid',text:''};
  const view={W:800,H:600,wPt:800,hPt:600,fpp:null};
  const normal=C.render(shape,view,{unit:'ft',precision:2}),reverse=C.render({...shape,points:[...shape.points].reverse()},view,{unit:'ft',precision:2});
  assert.equal(normal,reverse);assert.match(normal,/ A /);assert.doesNotMatch(normal,/ Q |NaN|Infinity/);assert.match(normal,/ Z"/);
  const zoom=C.render(shape,{...view,W:1600,H:1200},{unit:'ft',precision:2});
  assert.equal(normal.match(/ A /g).length,zoom.match(/ A /g).length);
  assert.ok(normal.match(/ A /g).length<100);
  assert.equal(C.cloudPath({x:0,y:0,right:10,bottom:0},1),'M 0 0 L 10 0');
});
