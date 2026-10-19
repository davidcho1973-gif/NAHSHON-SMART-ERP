/* Geometry uses page-normalized coordinates and PDF points, never viewport pixels. */
(function (global) {
  'use strict';
  const clone = value => JSON.parse(JSON.stringify(value));
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const dist = (a,b,w,h) => Math.hypot((a[0]-b[0])*w,(a[1]-b[1])*h);
  const bounds = points => ({x:Math.min(...points.map(p=>p[0])),y:Math.min(...points.map(p=>p[1])),right:Math.max(...points.map(p=>p[0])),bottom:Math.max(...points.map(p=>p[1]))});
  function vertices(e) {
    if (!['rect','ellipse','cloud'].includes(e.type)) return e.points;
    const b=bounds(e.points); return [[b.x,b.y],[b.right,b.y],[b.right,b.bottom],[b.x,b.bottom]];
  }
  function measure(e,w,h,fpp) {
    const p=vertices(e); let length=0,area=0,angle=null;
    for(let i=1;i<p.length;i++) length+=dist(p[i-1],p[i],w,h);
    if(['polygon','rect','cloud'].includes(e.type)) {
      length+=dist(p.at(-1),p[0],w,h);
      p.forEach((a,i)=>{const b=p[(i+1)%p.length];area+=(a[0]*b[1]-b[0]*a[1])*w*h;}); area=Math.abs(area/2);
    }
    if(e.type==='ellipse') { const b=bounds(e.points),a=(b.right-b.x)*w/2,c=(b.bottom-b.y)*h/2;
      area=Math.PI*a*c; length=a+c?Math.PI*(3*(a+c)-Math.sqrt((3*a+c)*(a+3*c))):0; }
    if(e.type==='angle'&&p.length===3){const a=[(p[0][0]-p[1][0])*w,(p[0][1]-p[1][1])*h],b=[(p[2][0]-p[1][0])*w,(p[2][1]-p[1][1])*h];
      const d=Math.hypot(...a)*Math.hypot(...b);angle=d?Math.acos(Math.max(-1,Math.min(1,(a[0]*b[0]+a[1]*b[1])/d)))*180/Math.PI:null;}
    return {length:fpp>0?length*fpp:null,area:fpp>0?area*fpp*fpp:null,angle};
  }
  function format(value,unit='ft',precision=2,area=false) {
    if(value===null||!Number.isFinite(value)) return '축척 필요';
    if(unit==='ft-in'&&!area){let n=Math.round(value*12*16);return `${Math.floor(n/192)}′ ${Number(((n%192)/16).toFixed(4))}″`;}
    let factor=unit==='mm'?304.8:unit==='m'?0.3048:1;
    return (value*(area?factor*factor:factor)).toLocaleString('en-US',{minimumFractionDigits:precision,maximumFractionDigits:precision})+' '+(unit==='ft-in'?'ft':unit)+(area?'²':'');
  }
  function constrain(prev,p,w,h,step) {
    const dx=(p[0]-prev[0])*w,dy=(p[1]-prev[1])*h,r=Math.hypot(dx,dy),a=Math.round(Math.atan2(dy,dx)/step)*step;
    return [prev[0]+r*Math.cos(a)/w,prev[1]+r*Math.sin(a)/h];
  }
  function drawingPoint(type,prev,p,w,h,ortho,shift) {
    if(!prev)return p;
    // Box tools use opposite corners, not a line direction. Ortho collapsed their height to zero.
    if(['rect','ellipse','cloud'].includes(type)) {
      if(!shift)return p;
      const dx=(p[0]-prev[0])*w,dy=(p[1]-prev[1])*h,size=Math.max(Math.abs(dx),Math.abs(dy));
      return [prev[0]+(dx<0?-1:1)*size/w,prev[1]+(dy<0?-1:1)*size/h];
    }
    return ['line','dimension','polyline','polygon','arrow','angle'].includes(type)&&(ortho||shift)
      ?constrain(prev,p,w,h,ortho?Math.PI/2:Math.PI/12):p;
  }
  function cloudPath(b,s) {
    const width=b.right-b.x,height=b.bottom-b.y;
    // Keep old collapsed clouds selectable so their corner handles can repair the stored geometry.
    if(!(width>0&&height>0))return `M ${b.x} ${b.y} L ${b.right} ${b.bottom}`;
    const pitch=Math.min(24*s,Math.max(8*s,Math.min(width,height)/3));
    const corners=[[b.x,b.y],[b.right,b.y],[b.right,b.bottom],[b.x,b.bottom],[b.x,b.y]];
    let d=`M ${b.x} ${b.y}`;
    for(let i=1;i<corners.length;i++) {
      const a=corners[i-1],q=corners[i],dx=q[0]-a[0],dy=q[1]-a[1],length=Math.hypot(dx,dy);
      const n=Math.max(2,Math.round(length/pitch)),radius=length/n/2;
      // Clockwise edges with outward semicircles form the same closed revision cloud on every render/export.
      for(let j=1;j<=n;j++)d+=` A ${radius} ${radius} 0 0 1 ${a[0]+dx*j/n} ${a[1]+dy*j/n}`;
    }
    return d+' Z';
  }
  function snap(p,entities,w,h,k,tolerance=10) {
    let best=null,d=tolerance;
    const offer=(q,label)=>{const n=dist(p,q,w,h)*k;if(n<d){d=n;best={point:q,label};}};
    entities.forEach(e=>{
      if(e.type==='ellipse'){const b=bounds(e.points),cx=(b.x+b.right)/2,cy=(b.y+b.bottom)/2;offer([cx,cy],'중심');[[cx,b.y],[cx,b.bottom],[b.x,cy],[b.right,cy]].forEach(q=>offer(q,'사분점'));return;}
      const pts=vertices(e); pts.forEach(q=>offer(q,'끝점'));
      for(let i=1;i<pts.length;i++)offer([(pts[i-1][0]+pts[i][0])/2,(pts[i-1][1]+pts[i][1])/2],'중점');
      if(['polygon','rect','cloud'].includes(e.type))offer([(pts.at(-1)[0]+pts[0][0])/2,(pts.at(-1)[1]+pts[0][1])/2],'중점');
    });
    // Feature points take priority; otherwise a nearby segment would always win.
    if(best)return best;
    entities.filter(e=>e.type!=='ellipse').forEach(e=>{const pts=vertices(e);for(let i=1;i<pts.length;i++)segment(pts[i-1],pts[i]);if(['polygon','rect','cloud'].includes(e.type))segment(pts.at(-1),pts[0]);});
    function segment(a,b){
      const dx=(b[0]-a[0])*w,dy=(b[1]-a[1])*h,den=dx*dx+dy*dy;
      if(den){const t=((p[0]-a[0])*w*dx+(p[1]-a[1])*h*dy)/den;if(t>0&&t<1)offer([a[0]+t*(b[0]-a[0]),a[1]+t*(b[1]-a[1])],'선 위');}}
    return best;
  }
  function translate(entities,dx,dy){const b=bounds(entities.flatMap(e=>e.points));dx=Math.max(-b.x,Math.min(1-b.right,dx));dy=Math.max(-b.y,Math.min(1-b.bottom,dy));return entities.map(e=>({...clone(e),points:e.points.map(p=>[p[0]+dx,p[1]+dy])}));}
  function simplify(points,tolerance,w,h) {
    if(points.length<=2)return points;
    const a=points[0],b=points.at(-1),dx=(b[0]-a[0])*w,dy=(b[1]-a[1])*h,den=dx*dx+dy*dy;let max=0,index=0;
    for(let i=1;i<points.length-1;i++){const p=points[i],t=den?Math.max(0,Math.min(1,((p[0]-a[0])*w*dx+(p[1]-a[1])*h*dy)/den)):0;
      const d=dist(p,[a[0]+t*(b[0]-a[0]),a[1]+t*(b[1]-a[1])],w,h);if(d>max){max=d;index=i;}}
    return max>tolerance?[...simplify(points.slice(0,index+1),tolerance,w,h).slice(0,-1),...simplify(points.slice(index),tolerance,w,h)]:[a,b];
  }
  function render(e,v,doc) {
    const W=v.W,H=v.H,s=W/v.wPt,p=e.points.map(q=>[q[0]*W,q[1]*H]),pts=p.map(q=>q.join(',')).join(' '),b=bounds(p),c=esc(e.color),sw=e.width*s;
    const style=`stroke="${c}" stroke-width="${sw}" fill="${esc(['polygon','rect','ellipse','cloud'].includes(e.type)?e.fill:'none')}" opacity="${e.opacity}" stroke-linejoin="round" stroke-linecap="round"${e.dash==='solid'?'':` stroke-dasharray="${e.dash==='dash'?`${6*s} ${4*s}`:`${s} ${3*s}`}"`}`;
    const text=(x,y,t)=>`<text x="${x}" y="${y}" fill="${c}" opacity="${e.opacity}" font-size="${e.fontSize*s}" font-family="Arial, sans-serif" paint-order="stroke" stroke="white" stroke-width="${2*s}" stroke-linejoin="round">${esc(t)}</text>`;
    let out='';const m=measure(e,v.wPt,v.hPt,v.fpp);
    if(e.type==='text')out=text(p[0][0],p[0][1],e.text);
    else if(e.type==='rect')out=`<rect x="${b.x}" y="${b.y}" width="${b.right-b.x}" height="${b.bottom-b.y}" ${style}/>`;
    else if(e.type==='ellipse')out=`<ellipse cx="${(b.x+b.right)/2}" cy="${(b.y+b.bottom)/2}" rx="${(b.right-b.x)/2}" ry="${(b.bottom-b.y)/2}" ${style}/>`;
    else if(e.type==='cloud')out=`<path d="${cloudPath(b,s)}" ${style}/>`;
    else out=`<${e.type==='polygon'?'polygon':'polyline'} points="${pts}" ${style}${e.type==='highlight'?` style="mix-blend-mode:multiply"`:''}/>`;
    if(e.type==='arrow'&&p.length>=2){const a=p.at(-2),q=p.at(-1),t=Math.atan2(q[1]-a[1],q[0]-a[0]),n=10*s;out+=`<path d="M ${q[0]-n*Math.cos(t-.5)} ${q[1]-n*Math.sin(t-.5)} L ${q.join(' ')} L ${q[0]-n*Math.cos(t+.5)} ${q[1]-n*Math.sin(t+.5)}" ${style}/>`;}
    if(e.type==='dimension'&&p.length===2){const a=p[0],q=p[1],ang=Math.atan2(q[1]-a[1],q[0]-a[0]),nx=-Math.sin(ang),ny=Math.cos(ang),o=18*s;
      const u=[a[0]+nx*o,a[1]+ny*o],z=[q[0]+nx*o,q[1]+ny*o];out=`<path d="M ${a.join(' ')} L ${a[0]+nx*(o+5*s)} ${a[1]+ny*(o+5*s)} M ${q.join(' ')} L ${q[0]+nx*(o+5*s)} ${q[1]+ny*(o+5*s)} M ${u.join(' ')} L ${z.join(' ')}" ${style}/>`;
      [u,z].forEach(t=>{out+=`<path d="M ${t[0]-4*s} ${t[1]+4*s} l ${8*s} ${-8*s}" ${style}/>`;});out+=text((u[0]+z[0])/2,(u[1]+z[1])/2-4*s,format(m.length,doc.unit,doc.precision));
    }
    if(e.type==='angle'&&m.angle!==null)out+=text(p[1][0]+8*s,p[1][1]-8*s,m.angle.toFixed(doc.precision)+'°');
    if(['polygon','rect','ellipse'].includes(e.type)&&v.fpp)out+=text(b.x+4*s,b.y+16*s,format(m.area,doc.unit,doc.precision,true));
    if(e.text&&e.type!=='text')out+=text(p[0][0]+5*s,p[0][1]-7*s,e.text);
    return out;
  }
  global.DrawingStudioCore={clone,esc,dist,bounds,vertices,measure,format,constrain,drawingPoint,cloudPath,snap,translate,simplify,render};
})(window);
