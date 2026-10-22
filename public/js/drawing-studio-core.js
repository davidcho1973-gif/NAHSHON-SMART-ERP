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
    if(e.calibration){w=e.calibration.widthPt;h=e.calibration.heightPt;fpp=e.calibration.feetPerPoint;}
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
    const gross=area;
    (e.holes||[]).forEach(r=>{area-=Math.abs(signedArea(r))*w*h;});
    return {length:fpp>0?length*fpp:null,area:fpp>0?Math.max(0,area)*fpp*fpp:null,gross:fpp>0?gross*fpp*fpp:null,angle};
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
    // Only nearby segments participate in intersection inference, even on large drawings.
    const near=[];
    entities.filter(e=>e.type!=='ellipse').forEach(e=>{const pts=vertices(e),closed=['polygon','rect','cloud'].includes(e.type);for(let i=0;i<pts.length-(closed?0:1);i++){const a=pts[i],b=pts[(i+1)%pts.length],dx=(b[0]-a[0])*w,dy=(b[1]-a[1])*h,den=dx*dx+dy*dy;if(!den)continue;const t=Math.max(0,Math.min(1,((p[0]-a[0])*w*dx+(p[1]-a[1])*h*dy)/den));if(dist(p,[a[0]+t*(b[0]-a[0]),a[1]+t*(b[1]-a[1])],w,h)*k<tolerance)near.push([a,b]);}});
    for(let i=0;i<near.length;i++)for(let j=i+1;j<near.length;j++){const q=intersection(...near[i],...near[j]);if(q)offer(q,'교차점');}
    // Feature points take priority; otherwise a nearby segment would always win.
    if(best)return best;
    entities.filter(e=>e.type!=='ellipse').forEach(e=>{const pts=vertices(e);for(let i=1;i<pts.length;i++)segment(pts[i-1],pts[i]);if(['polygon','rect','cloud'].includes(e.type))segment(pts.at(-1),pts[0]);});
    function segment(a,b){
      const dx=(b[0]-a[0])*w,dy=(b[1]-a[1])*h,den=dx*dx+dy*dy;
      if(den){const t=((p[0]-a[0])*w*dx+(p[1]-a[1])*h*dy)/den;if(t>0&&t<1)offer([a[0]+t*(b[0]-a[0]),a[1]+t*(b[1]-a[1])],'선 위');}}
    return best;
  }
  function translate(entities,dx,dy){const b=bounds(entities.flatMap(e=>e.points));dx=Math.max(-b.x,Math.min(1-b.right,dx));dy=Math.max(-b.y,Math.min(1-b.bottom,dy));return entities.map(e=>mapPoints(e,p=>[p[0]+dx,p[1]+dy]));}
  const signedArea=p=>p.reduce((a,q,i)=>{const r=p[(i+1)%p.length];return a+q[0]*r[1]-r[0]*q[1];},0)/2;
  const cross=(a,b,c)=>(b[0]-a[0])*(c[1]-a[1])-(b[1]-a[1])*(c[0]-a[0]);
  function intersection(a,b,c,d,segments=true){const x=b[0]-a[0],y=b[1]-a[1],u=d[0]-c[0],z=d[1]-c[1],den=x*z-y*u;
    if(Math.abs(den)<1e-12){if(!segments||Math.abs(cross(a,b,c))>1e-12)return null;const on=(p,q,r)=>Math.abs(cross(q,r,p))<1e-12&&p[0]>=Math.min(q[0],r[0])-1e-12&&p[0]<=Math.max(q[0],r[0])+1e-12&&p[1]>=Math.min(q[1],r[1])-1e-12&&p[1]<=Math.max(q[1],r[1])+1e-12;return [a,b].find(p=>on(p,c,d))||[c,d].find(p=>on(p,a,b))||null;}const t=((c[0]-a[0])*z-(c[1]-a[1])*u)/den,s=((c[0]-a[0])*y-(c[1]-a[1])*x)/den;
    return !segments||(t>=0&&t<=1&&s>=0&&s<=1)?[a[0]+t*x,a[1]+t*y]:null;}
  function polygonError(p){
    if(p.length<3)return '영역은 서로 다른 점을 3개 이상 찍으세요.';
    if(p.some((a,i)=>p.some((b,j)=>i!==j&&dist(a,b,1,1)<1e-9)))return '같은 꼭짓점이 반복됩니다. 마지막 점은 첫 점과 별도로 찍지 않아도 됩니다.';
    for(let i=0;i<p.length;i++)for(let j=i+1;j<p.length;j++)if(j!==i+1&&!(i===0&&j===p.length-1)&&intersection(p[i],p[(i+1)%p.length],p[j],p[(j+1)%p.length]))return '경계선이 교차합니다. 교차하는 꼭짓점을 수정하세요.';
    return Math.abs(signedArea(p))<1e-10?'면적이 0입니다. 폭과 높이가 있는 영역을 그리세요.':'';
  }
  function inside(p,ring){let yes=false;for(let i=0,j=ring.length-1;i<ring.length;j=i++){const a=ring[i],b=ring[j];if((a[1]>p[1])!==(b[1]>p[1])&&p[0]<(b[0]-a[0])*(p[1]-a[1])/(b[1]-a[1])+a[0])yes=!yes;}return yes;}
  function ringsTouch(a,b){return a.some((p,i)=>b.some((q,j)=>intersection(p,a[(i+1)%a.length],q,b[(j+1)%b.length])));}
  function holeError(e,ring){const outer=outline(e);return polygonError(ring)||(!ring.every(p=>inside(p,outer))||ringsTouch(outer,ring)?'공제 영역은 바깥 경계 안에 완전히 들어가야 합니다.':'')||((e.holes||[]).some(h=>ringsTouch(h,ring)||inside(ring[0],h)||inside(h[0],ring))?'이미 공제한 영역과 겹칩니다.':'');}
  function outline(e){if(e.type!=='ellipse')return vertices(e);const b=bounds(e.points);return Array.from({length:96},(_,i)=>[(b.x+b.right)/2+(b.right-b.x)/2*Math.cos(i*Math.PI/48),(b.y+b.bottom)/2+(b.bottom-b.y)/2*Math.sin(i*Math.PI/48)]);}
  function mapPoints(e,fn){const n=clone(e);n.points=n.points.map(fn);if(n.holes)n.holes=n.holes.map(h=>h.map(fn));return n;}
  function offset(e,feet,v){
    if(e.calibration)v={...v,wPt:e.calibration.widthPt,hPt:e.calibration.heightPt,fpp:e.calibration.feetPerPoint};
    if(!v.fpp)throw Error('축척을 먼저 설정하세요.');
    if(!['line','dimension','polyline','polygon','rect'].includes(e.type)||e.holes?.length)throw Error('공제 없는 선·연속선·다각형·사각형을 선택하세요.');
    const closed=['polygon','rect'].includes(e.type),p=vertices(e).map(q=>[q[0]*v.wPt,q[1]*v.hPt]),d=feet/v.fpp,sign=closed?(signedArea(p)>0?-1:1):1;
    const edges=Array.from({length:closed?p.length:p.length-1},(_,i)=>{const a=p[i],b=p[(i+1)%p.length],len=dist(a,b,1,1);if(len<1e-9)throw Error('길이가 0인 선을 먼저 수정하세요.');const dx=-(b[1]-a[1])/len*d*sign,dy=(b[0]-a[0])/len*d*sign;return [[a[0]+dx,a[1]+dy],[b[0]+dx,b[1]+dy]];});
    const out=p.map((q,i)=>{if(!closed&&i===0)return edges[0][0];if(!closed&&i===p.length-1)return edges.at(-1)[1];const before=edges[(i+edges.length-1)%edges.length],after=edges[i%edges.length];return intersection(...before,...after,false)||after[0];}).map(q=>[q[0]/v.wPt,q[1]/v.hPt]);
    if(out.some(q=>q.some(n=>!Number.isFinite(n)||n<0||n>1)))throw Error('간격이 커서 도면 밖으로 나갑니다.');
    const reversed=out.some((q,i)=>{if(!closed&&i===out.length-1)return false;const j=(i+1)%out.length;return (out[j][0]-q[0])*v.wPt*(p[j][0]-p[i][0])+(out[j][1]-q[1])*v.hPt*(p[j][1]-p[i][1])<=1e-9;});
    if(reversed||closed&&(polygonError(out)||Math.sign(signedArea(out))!==Math.sign(signedArea(p))))throw Error('이 간격에서는 경계가 겹칩니다. 간격을 줄이세요.');
    return {...clone(e),type:closed?'polygon':e.type,points:out};
  }
  function arcPoints(points,w,h){if(points.length!==3)return points;const [a,b,c]=points.map(p=>[p[0]*w,p[1]*h]),den=2*cross(a,b,c);if(Math.abs(den)<1e-7)throw Error('호의 세 점이 일직선입니다. 가운데 점을 곡선 쪽으로 옮기세요.');
    const aa=a[0]**2+a[1]**2,bb=b[0]**2+b[1]**2,cc=c[0]**2+c[1]**2,center=[(aa*(b[1]-c[1])+bb*(c[1]-a[1])+cc*(a[1]-b[1]))/den,(aa*(c[0]-b[0])+bb*(a[0]-c[0])+cc*(b[0]-a[0]))/den];
    const start=Math.atan2(a[1]-center[1],a[0]-center[0]),end=Math.atan2(c[1]-center[1],c[0]-center[0]),tau=2*Math.PI;let sweep=(end-start+tau)%tau;if(cross(a,b,c)<0)sweep-=tau;const r=dist(a,center,1,1),n=Math.max(16,Math.ceil(Math.abs(sweep)*32));
    return Array.from({length:n+1},(_,i)=>[(center[0]+r*Math.cos(start+sweep*i/n))/w,(center[1]+r*Math.sin(start+sweep*i/n))/h]);}
  function takeoff(items,unit,v){const u=unit.toUpperCase().replace(/\s/g,''),area=['SF','SQFT','FT2','M2','㎡','SY','YD2'].includes(u),length=['LF','FT','M','LM','MM'].includes(u),count=['EA','EACH','PCS','개','SET'].includes(u);
    if(!area&&!length&&!count)throw Error('이 계약 단위는 자동 환산을 지원하지 않습니다. 수량 근거를 확인해 직접 입력하세요.');
    let total=0;const rows=items.map(e=>{const m=measure(e,v.wPt,v.hPt,v.fpp),closed=['polygon','rect','ellipse'].includes(e.type);let quantity;
      if(area){if(!closed||m.area===null)throw Error('면적 공정에는 축척이 있는 닫힌 영역을 선택하세요.');quantity=m.area*(u==='M2'||u==='㎡'?.09290304:['SY','YD2'].includes(u)?1/9:1);}
      else if(length){if(closed||['text','angle','symbol'].includes(e.type)||m.length===null)throw Error('길이 공정에는 축척이 있는 선을 선택하세요. 면적을 길이로 넣을 수 없습니다.');quantity=m.length*(u==='M'||u==='LM'?.3048:u==='MM'?304.8:1);}
      else {if(e.type!=='symbol')throw Error('개수 공정에는 개수 기호를 선택하세요.');quantity=1;}
      total+=quantity;return {id:e.id,label:e.text||'측정',quantity,unit,gross:m.gross,area:m.area,length:m.length,calibration:clone(e.calibration||{feetPerPoint:v.fpp,widthPt:v.wPt,heightPt:v.hPt})};});return {quantity:Math.round(total*10000)/10000,unit,rows};}
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
    else if(e.type==='symbol')out=`<circle cx="${p[0][0]}" cy="${p[0][1]}" r="${10*s}" ${style}/>`+text(p[0][0]-4*s,p[0][1]+4*s,e.symbol||'●');
    else if(e.type==='rect')out=`<rect x="${b.x}" y="${b.y}" width="${b.right-b.x}" height="${b.bottom-b.y}" ${style}/>`;
    else if(e.type==='ellipse')out=`<ellipse cx="${(b.x+b.right)/2}" cy="${(b.y+b.bottom)/2}" rx="${(b.right-b.x)/2}" ry="${(b.bottom-b.y)/2}" ${style}/>`;
    else if(e.type==='cloud')out=`<path d="${cloudPath(b,s)}" ${style}/>`;
    else out=`<${e.type==='polygon'?'polygon':'polyline'} points="${pts}" ${style}${e.type==='highlight'?` style="mix-blend-mode:multiply"`:''}/>`;
    if(e.type==='arrow'&&p.length>=2){const a=p.at(-2),q=p.at(-1),t=Math.atan2(q[1]-a[1],q[0]-a[0]),n=10*s;out+=`<path d="M ${q[0]-n*Math.cos(t-.5)} ${q[1]-n*Math.sin(t-.5)} L ${q.join(' ')} L ${q[0]-n*Math.cos(t+.5)} ${q[1]-n*Math.sin(t+.5)}" ${style}/>`;}
    if(e.holes?.length){const rings=[outline(e),...e.holes];out=`<path d="${rings.map(r=>'M '+r.map(q=>[q[0]*W,q[1]*H].join(' ')).join(' L ')+' Z').join(' ')}" ${style} fill-rule="evenodd"/>`;}
    if(e.type==='dimension'&&p.length===2){const a=p[0],q=p[1],ang=Math.atan2(q[1]-a[1],q[0]-a[0]),nx=-Math.sin(ang),ny=Math.cos(ang),o=(e.dimensionOffset??18)*s;
      const u=[a[0]+nx*o,a[1]+ny*o],z=[q[0]+nx*o,q[1]+ny*o];out=`<path d="M ${a.join(' ')} L ${a[0]+nx*(o+5*s)} ${a[1]+ny*(o+5*s)} M ${q.join(' ')} L ${q[0]+nx*(o+5*s)} ${q[1]+ny*(o+5*s)} M ${u.join(' ')} L ${z.join(' ')}" ${style}/>`;
      [u,z].forEach(t=>{out+=`<path d="M ${t[0]-4*s} ${t[1]+4*s} l ${8*s} ${-8*s}" ${style}/>`;});out+=text((u[0]+z[0])/2,(u[1]+z[1])/2-4*s,format(m.length,doc.unit,doc.precision));
    }
    if(e.type==='angle'&&m.angle!==null)out+=text(p[1][0]+8*s,p[1][1]-8*s,m.angle.toFixed(doc.precision)+'°');
    if(['polygon','rect','ellipse'].includes(e.type)&&(e.calibration||v.fpp))out+=text(b.x+4*s,b.y+16*s,format(m.area,doc.unit,doc.precision,true));
    if(e.text&&e.type!=='text')out+=text(p[0][0]+5*s,p[0][1]-7*s,e.text);
    return out;
  }
  global.DrawingStudioCore={clone,esc,dist,bounds,vertices,measure,format,constrain,drawingPoint,cloudPath,snap,translate,simplify,render,signedArea,intersection,polygonError,inside,holeError,outline,mapPoints,offset,arcPoints,takeoff};
})(window);
