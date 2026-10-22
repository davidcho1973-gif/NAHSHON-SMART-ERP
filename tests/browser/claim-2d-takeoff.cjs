// Synthetic claim boundary: reject failed evidence uploads, replace repeated measurements, never approve.
const {chromium}=require('playwright'),assert=require('node:assert/strict');
(async()=>{
  const browser=await chromium.launch({headless:true,channel:process.env.STUDIO_BROWSER_CHANNEL||undefined});
  try{
    const p=await browser.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
    await p.route('https://synthetic.test/',r=>r.fulfill({contentType:'text/html',body:'<html></html>'}));await p.goto('https://synthetic.test/');
    await p.addStyleTag({path:'public/css/claim-record-studio.css'});await p.addScriptTag({path:'public/js/admin-shell.js'});
    await p.evaluate(()=>{
      window.calls=[];window.drafts=[];window.failImage=true;window.unfinished=true;
      window.DrawingMarker={open(opts){window.viewerOptions=opts;opts.host.textContent='Synthetic drawing';return {beforeClose:()=>!unfinished,close(){},flush:async()=>{if(unfinished)throw Error('Finish the polygon');}};}};
      window.gsRun=async(m,a)=>{calls.push([m,structuredClone(a)]);
        if(m==='api_getClaimEditor')return {success:true,canManage:true,contract:{today:'2026-10-21'},lines:[{id:8,lineNo:'8',description:'Flooring',unit:'SF',contractQty:1000,workSectionId:2}],drafts,records:[],sourceOptions:[{value:'document:20',label:'Measured drawing'},{value:'document:21',label:'Original drawing'}]};
        if(m==='api_saveClaimEditDraft'){const d={lineId:8,revision:a[0].revision+1,content:structuredClone(a[0].content)};drafts=[d];return {success:true,draft:d};}
        if(m==='api_saveClaimDrawingImage'){if(failImage)return {success:false,error:'Synthetic upload failure'};return {success:true,id:20,sourceDocumentId:21};}
        throw Error('Unexpected call: '+m);
      };
      window.takeoff=async quantity=>viewerOptions.onTakeoff({sheetId:4,sheetNo:'A-1',revision:7,rows:[{id:'floor-a',quantity,label:'Floor A'}],blob:new Blob(['synthetic'],{type:'image/png'})});
    });
    for(const path of ['public/js/claim-save-safety.js','public/js/admin-claim-editor.js'])await p.addScriptTag({path});
    await p.evaluate(()=>AdminClaimEditor.open(3,1,[{id:4,sheetNo:'A-1',title:'Plan'}],{lineId:8,fullscreen:true}));await p.locator('[data-field="notes"]').waitFor();
    assert.equal(await p.evaluate(()=>viewerOptions.edit2d),true);
    await p.locator('[data-field="notes"]').fill('Independent note while drawing');
    await p.waitForFunction(()=>drafts[0]?.content.notes==='Independent note while drawing');
    await p.getByRole('button',{name:'저장하고 닫기',exact:true}).click();assert.equal(await p.locator('.ce-studio').count(),1,'An unfinished path must block explicit save-and-close');
    assert.match(await p.evaluate(()=>takeoff(40).then(()=>'',e=>e.message)),/Synthetic upload failure/);
    assert.equal(await p.evaluate(()=>drafts[0].content.quantity),0,'Failed evidence must not change quantities');
    await p.evaluate(()=>{failImage=false;unfinished=false;return takeoff(40);});
    assert.equal(await p.evaluate(()=>drafts[0].content.quantity),40);assert.equal(await p.evaluate(()=>drafts[0].content.evidence.length),2);
    await p.evaluate(()=>takeoff(55));const latest=await p.evaluate(()=>drafts[0].content);
    assert.equal(latest.quantity,55);assert.equal(latest.calculations.length,1);assert.match(latest.calculations[0].label,/A-1 v7/);
    assert.equal(await p.evaluate(()=>calls.some(c=>c[0]==='api_commitClaimEditDraft')),false);
    await p.getByRole('button',{name:'저장하고 닫기',exact:true}).click();assert.equal(await p.locator('.ce-studio').count(),0);assert.deepEqual(errors,[]);
    console.log(JSON.stringify({passed:true,uploadFailureRetained:true,repeatedTakeoffReplaced:true,notesSaveDuringDrawing:true,unfinishedCloseBlocked:true}));
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
