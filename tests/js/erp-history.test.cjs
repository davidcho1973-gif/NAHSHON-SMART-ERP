const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/js/erp-history.js', 'utf8');
function setup(start='https://erp.test/?view=wbs&site=2') {
  const listeners={}, entries=[{url:start,state:{other:'preserved'}}];let at=0, snapshot={y:0}; const rendered=[];
  const win={location:new URL(start),addEventListener:(n,f)=>listeners[n]=f};
  const copy=x=>structuredClone(x);
  win.history={get state(){return entries[at].state;},replaceState(s,_,u){entries[at]={state:copy(s),url:new URL(u,win.location).href};win.location=new URL(entries[at].url);},pushState(s,_,u){entries.splice(at+1);at++;entries.push({state:copy(s),url:new URL(u,win.location).href});win.location=new URL(entries[at].url);}};
  vm.runInNewContext(source,{window:win,Date,JSON,Object});
  const api=win.ERPHistory.create({read:()=>({view:win.location.searchParams.get('view'),site:win.location.searchParams.get('site')}),url:r=>'/?view='+r.view+'&site='+r.site+(r.document?'&document='+r.document:''),capture:()=>copy(snapshot),render:(r,s)=>rendered.push(copy({r,s}))});
  api.start();
  return {api,win,entries,rendered,setSnapshot:s=>snapshot=s,go(delta){at+=delta;win.location=new URL(entries[at].url);listeners.popstate({state:copy(entries[at].state)});}};
}
test('WBS → documents → payroll goes back and forward in exact order with site and scroll',()=>{
  const h=setup();h.setSnapshot({y:370,filters:{trade:'PLUMB'}});h.api.navigate({view:'document-hub',site:'2'});h.setSnapshot({y:82});h.api.navigate({view:'payroll',site:'ALL'});h.go(-1);
  assert.equal(h.rendered.at(-1).r.view,'document-hub');assert.equal(h.rendered.at(-1).s.y,82);
  h.go(-1);assert.equal(h.rendered.at(-1).r.site,'2');assert.equal(h.rendered.at(-1).s.y,370);h.go(1);assert.equal(h.rendered.at(-1).r.view,'document-hub');
});
test('same route and explicit refresh do not add duplicate back stops',()=>{
  const h=setup();assert.equal(h.api.navigate({view:'wbs',site:'2'}),false);h.api.navigate({view:'wbs',site:'2'},{refresh:true});assert.equal(h.entries.length,1);
});
test('document detail uses the same top-level history; silent updates do not repaint iframe',()=>{
  const h=setup();h.api.navigate({view:'document-hub',site:'2'});const count=h.rendered.length;
  h.api.navigate({view:'document-hub',site:'2',document:'93'},{silent:true});assert.equal(h.rendered.length,count);
  h.go(-1);assert.equal(h.rendered.at(-1).r.document,undefined);h.go(1);assert.equal(h.rendered.at(-1).r.document,'93');
});
test('new navigation after back discards forward branch, preserving unrelated history state',()=>{
  const h=setup();h.api.navigate({view:'hr',site:'ALL'});h.api.navigate({view:'payroll',site:'ALL'});h.go(-1);h.api.navigate({view:'wbs',site:'2'});
  assert.equal(h.entries.length,3);assert.equal(h.entries[2].state.other,'preserved');assert.equal(h.entries[2].state.erpNavigation.route.view,'wbs');
});
test('deep link becomes initial entry rather than adding an unwanted dashboard entry',()=>{
  const h=setup('https://erp.test/?view=document-hub&site=2');assert.equal(h.entries.length,1);assert.equal(h.rendered[0].r.view,'document-hub');
});
test('save records the current screen before leaving the document',()=>{
  const h=setup();h.setSnapshot({y:620});h.api.save();assert.equal(h.win.history.state.erpNavigation.snapshot.y,620);
});
test('replace updates document query state without creating a fake extra visit',()=>{
  const h=setup();h.api.navigate({view:'document-hub',site:'2',document:'93',documentState:{page:3}},{replace:true,silent:true});assert.equal(h.entries.length,1);assert.equal(h.win.history.state.erpNavigation.route.documentState.page,3);
});
test('in-app back from a direct link uses its fallback instead of leaving ERP',()=>{
 const h=setup('https://erp.test/?view=document-hub&site=2');let backCalls=0;h.win.history.back=()=>backCalls++;
 h.api.back({view:'wbs',site:'2'});assert.equal(backCalls,0);assert.equal(h.entries.length,1);assert.equal(h.rendered.at(-1).r.view,'wbs');
});
test('in-app back after an internal visit uses native history',()=>{
 const h=setup();let backCalls=0;h.win.history.back=()=>backCalls++;h.api.navigate({view:'document-hub',site:'2'});h.api.back({view:'dashboard',site:'2'});assert.equal(backCalls,1);
});
