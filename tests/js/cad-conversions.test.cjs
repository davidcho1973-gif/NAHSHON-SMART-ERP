const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
class Element {
    constructor(tag='div') {this.tag=tag;this.children=[];this.hidden=true;this.attributes={};this.textContent='';}
    append(...items){this.children.push(...items);}
    replaceChildren(...items){this.children=items;this.textContent='';}
    setAttribute(key,value){this.attributes[key]=value;}
}
function setup(tasks=[]){
    const elements={},listeners={},calls=[],opened=[],messages=[],timers=new Map();let seq=0;
    const context={document:{getElementById:id=>elements[id]??=new Element(),createElement:tag=>new Element(tag)},crypto:require('node:crypto').webcrypto,
        setTimeout:(fn,ms)=>{timers.set(++seq,{fn,ms});return seq;},clearTimeout:id=>timers.delete(id)};
    context.window={addEventListener:(event,fn)=>{listeners[event]=fn;}};
    vm.runInNewContext(fs.readFileSync('public/js/cad-conversions.js','utf8'),context);
    const request=async(url,options)=>{calls.push({url,options});if(options?.method==='POST')return {id:'queued'};return {conversions:tasks};};
    const ui=context.window.createCADConversions({request,openDrawing:async id=>opened.push(id),status:text=>messages.push(text),siteId:()=>1});
    return {ui,elements,listeners,calls,opened,messages,timers,tasks};
}
const settle=()=>new Promise(resolve=>setImmediate(resolve));
function descendants(element){return element.children.flatMap(e=>[e,...descendants(e)]);}
test('persisted tasks recover on reload, filter site, and never replace the active drawing automatically',async()=>{
    const s=setup([{id:'a',siteId:1,title:'Civil',status:'completed',operation:'import',originalUrl:'/original',result:{drawings:[{id:8,title:'평면'}],warnings:['3D 원본 별도 보존']}},
        {id:'b',siteId:2,title:'Other site',status:'completed'}]);
    s.ui.enable(true);await settle();
    assert.equal(s.elements['cad-conversion-toggle'].hidden,false);
    assert.equal(s.elements['cad-conversion-list'].children.length,1);assert.deepEqual(s.opened,[]);
    const open=descendants(s.elements['cad-conversion-list']).find(e=>e.tag==='button');await open.onclick();assert.deepEqual(s.opened,[8]);
    assert.equal([...s.timers.values()][0].ms,30000);
});
test('queued and failed jobs remain reviewable; retry uses the existing source',async()=>{
    const s=setup([{id:'a',siteId:1,title:'Civil',status:'queued',operation:'import',originalUrl:'/original',notice:'대기 안내'},
        {id:'b',siteId:1,title:'Retry',status:'failed',operation:'import',attempt:1,originalUrl:'/original-b',error:'검증 실패'}]);
    s.ui.enable(true);await settle();
    assert.equal(s.elements['cad-conversion-toggle'].textContent,'변환 작업 (1)');assert.equal([...s.timers.values()][0].ms,4000);
    const retry=descendants(s.elements['cad-conversion-list']).find(e=>e.textContent==='보관된 원본으로 재시도');await retry.onclick();
    assert.equal(s.calls.filter(c=>c.options?.method==='POST')[0].url,'/conversions/b/retry');
    assert.deepEqual(s.opened,[]);
});
test('upload and export show durable progress and page restoration restarts polling',async()=>{
    const s=setup();s.ui.enable(true);await settle();
    const form={opaque:'upload'};await s.ui.submit(form);
    assert.equal(s.calls.find(c=>c.options?.body===form).url,'/conversions');
    assert.equal(s.elements['cad-conversions'].hidden,false);
    await s.ui.exportDwg({id:12,revision:3});const call=s.calls.find(c=>c.url==='/12/export-dwg');
    const body=JSON.parse(call.options.body);assert.equal(body.revision,3);assert.match(body.requestKey,/^[0-9a-f-]{36}$/);
    s.listeners.pagehide();assert.equal(s.timers.size,0);const before=s.calls.length;
    s.listeners.pageshow();await settle();assert.equal(s.calls.length,before+1);
});
