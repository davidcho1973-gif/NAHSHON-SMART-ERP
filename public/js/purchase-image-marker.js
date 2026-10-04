/* Preserve the original attachment; save a separate marked image for discussion. */
(function (global) {
  'use strict';
  global.PurchaseImageMarker = {open(file, host, onSave) {
    const url=URL.createObjectURL(file), img=new Image(), strokes=[];let current=null;
    host.hidden=false;
    host.innerHTML='<p>필요한 부분에 동그라미를 그려주세요. 표시만으로 작업 목적이 확정되지는 않습니다.</p><canvas aria-label="필요한 위치 표시" style="width:100%;touch-action:none;border:1px solid #cbd5e1"></canvas><div class="pr-actions"><button type="button" data-undo>실행 취소</button><button type="button" data-save>표시 저장</button><button type="button" data-close>닫기</button></div>';
    const canvas=host.querySelector('canvas'),ctx=canvas.getContext('2d');
    function paint(){ctx.drawImage(img,0,0,canvas.width,canvas.height);ctx.lineWidth=Math.max(3,canvas.width/200);ctx.strokeStyle='#e11d48';ctx.lineCap='round';for(const stroke of strokes){ctx.beginPath();if(stroke.length===1){ctx.arc(stroke[0][0],stroke[0][1],Math.max(9,canvas.width/70),0,Math.PI*2);}else{stroke.forEach((p,i)=>i?ctx.lineTo(...p):ctx.moveTo(...p));}ctx.stroke();}}
    function point(ev){const r=canvas.getBoundingClientRect();return [(ev.clientX-r.left)*canvas.width/r.width,(ev.clientY-r.top)*canvas.height/r.height];}
    canvas.onpointerdown=ev=>{canvas.setPointerCapture(ev.pointerId);current=[point(ev)];strokes.push(current);};
    canvas.onpointermove=ev=>{if(current){current.push(point(ev));paint();}};
    canvas.onpointerup=canvas.onpointercancel=()=>{current=null;paint();};
    host.querySelector('[data-undo]').onclick=()=>{strokes.pop();paint();};
    host.querySelector('[data-close]').onclick=()=>{host.hidden=true;URL.revokeObjectURL(url);};
    host.querySelector('[data-save]').onclick=()=>{if(!strokes.length)return;canvas.toBlob(blob=>{if(!blob)return;onSave(new File([blob],'marked-'+file.name.replace(/\.[^.]+$/,'')+'.jpg',{type:'image/jpeg'}));host.hidden=true;URL.revokeObjectURL(url);},'image/jpeg',0.92);};
    img.onload=()=>{const scale=Math.min(1,2400/Math.max(img.width,img.height));canvas.width=Math.round(img.width*scale);canvas.height=Math.round(img.height*scale);paint();};
    img.onerror=()=>{host.textContent='사진을 열 수 없습니다. 다른 사진을 선택하세요.';URL.revokeObjectURL(url);};img.src=url;
  }};
})(window);
