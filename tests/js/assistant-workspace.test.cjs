const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync('public/js/assistant-workspace.js', 'utf8');
function api() { const context = { module: {exports:{}}, Set }; vm.runInNewContext(code, context); return context.module.exports; }
test('repeated clicks cannot overlap the same operation', () => {
    const guard = api().createGuard(), first = guard.begin('report');
    assert.ok(first); assert.equal(guard.begin('report'), null); guard.end(first); assert.ok(guard.begin('report'));
});
test('newer company/site navigation invalidates an earlier response', () => {
    const guard = api().createGuard(), first = guard.begin('report'); guard.change();
    assert.equal(guard.valid(first), false); guard.end(first); const second = guard.begin('report'); assert.equal(guard.valid(second), true);
});
test('report and export selectors contain no user role or arbitrary SQL', () => {
    const params = api().reportParams('7','8','wbs_items','pump',25);
    assert.equal(JSON.stringify(params), JSON.stringify({company_id:7,site_id:8,dataset:'wbs_items',search:'pump',after_id:25}));
});
test('confirmation sends only original server preview and requires checkbox', () => {
    assert.match(code, /if \(!proposal \|\| !el\('approve'\)\.checked\) return/);
    assert.match(code, /preview_token: proposal\.preview_token, version: proposal\.version, confirmed: true/);
    assert.match(code, /\['operation', 'record', 'title', 'detail', 'due'.*resetPreview/s);
    assert.doesNotMatch(code, /innerHTML\s*=/);
});

class Element {
    constructor(tag='div') { this.tagName=tag; this.children=[]; this.dataset={}; this.listeners={}; this.value=''; this.textContent=''; this.disabled=false; this.hidden=false; this.checked=false; }
    appendChild(child) { this.children.push(child); if(this.tagName==='select' && !this.value) this.value=String(child.value); return child; }
    replaceChildren(...children) { this.children=[]; if(this.tagName==='select') this.value=''; children.forEach(c=>this.appendChild(c)); }
    addEventListener(name,fn) { this.listeners[name]=fn; }
    dispatch(name,event={}) { if(this.listeners[name]) this.listeners[name](event); }
    closest(){return this;}
}
function fixture(fetch, saved) {
    const ids=['company','site','dataset','search','export','status','report','write-fields','mutations-off','budget','checks','operation','record','title','detail','due','approve','preview','preview-body','check-kind','check-interval','report-date','work-today','work-tomorrow','expense-description','amount','expense-date','account','payment','source-document','category'];
    const selects=['company','site','dataset','operation','check-kind','check-interval','account','payment','category'];
    const elements=Object.fromEntries(ids.map(id=>[id,new Element(selects.includes(id)?'select':'div')]));
    const buttons=Object.fromEntries(['report','next','propose','confirm','recover','cancel','save-check','activate-check','disable-check'].map(action=>{const e=new Element('button');e.dataset.action=action;return[action,e]}));
    const host=new Element(); host.dataset={base:'/ask-api/workspace',options:JSON.stringify({actor_id:1,companies:[{id:1,name:'A'},{id:2,name:'B'}],sites:[{id:11,company_id:1,name:'A1'},{id:22,company_id:2,name:'B1'}],datasets:[{key:'wbs_items',label:'WBS'}]})};
    host.querySelectorAll=selector=>selector==='[data-operations]'?[]:Object.values(buttons);
    host.querySelector=s=>buttons[(s.match(/data-action[=\"']+([\w-]+)/)||[])[1]] || null;
    const store=new Map(saved ? [['erp-assistant-proposal-1',saved]] : []);
    const document={getElementById:id=>id==='assistant-workspace'?host:elements[id.replace('assistant-','')],querySelector:()=>({content:'fixture-csrf'}),createElement:tag=>new Element(tag)};
    const events=[];
    const window={document,fetch,sessionStorage:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)},dispatchEvent:e=>events.push(e.type)};
    vm.runInNewContext(code,{window,Set,URLSearchParams,Event:class{constructor(type){this.type=type;}}});
    elements.operation.value='ops.todo.create'; elements.title.value='Prepare materials'; elements['check-kind'].value='overdue_wbs';elements['check-interval'].value='24';
    return {elements,buttons,store,events,click(action){host.dispatch('click',{target:buttons[action]});}};
}
const response=d=>({ok:true,json:async()=>({success:true,...d})});
const status=()=>response({mutations_enabled:true,checks_enabled:false,checks:[],budget:{requests:{user_day:{remaining:20,limit:20}}}});
const preview={id:'11111111-1111-4111-8111-111111111111',version:'v1',preview_token:'token1',status:'pending',company_id:1,site_id:11,operation:'ops.todo.create',record_id:null,before:null,after:{title:'Prepare materials'},expires_at:'2026-10-06T01:00:00Z'};
const flush=()=>new Promise(resolve=>setImmediate(resolve));
test('DOM flow requires reviewed checkbox and does not duplicate an in-flight proposal', async()=>{
    const calls=[];let finish;
    const f=fixture((url,opts)=>{calls.push({url,opts});if(url.includes('/status'))return Promise.resolve(status());return new Promise(r=>{finish=r;});});
    await flush();f.click('propose');f.click('propose');assert.equal(calls.filter(c=>c.url.endsWith('/proposals')).length,1);
    finish(response({proposal:preview}));await flush();assert.equal(f.buttons.confirm.disabled,true);
    assert.equal(f.store.get('erp-assistant-proposal-1'),preview.id);
    f.elements.approve.checked=true;f.elements.approve.dispatch('change');assert.equal(f.buttons.confirm.disabled,false);
    f.click('confirm');f.click('confirm');assert.equal(calls.filter(c=>c.url.endsWith('/confirm')).length,1);
    const body=JSON.parse(calls.find(c=>c.url.endsWith('/confirm')).opts.body);assert.deepEqual(body,{preview_token:'token1',version:'v1',confirmed:true});
    finish(response({proposal:{status:'applied',record_id:7}}));await flush();assert.equal(f.store.size,0);assert.equal(f.elements.preview.hidden,true);
});
test('DOM changing form values discards old approval and preview token',async()=>{
    const f=fixture(url=>Promise.resolve(url.includes('/status')?status():response({proposal:preview})));
    await flush();f.click('propose');await flush();f.elements.approve.checked=true;f.elements.approve.dispatch('change');
    f.elements.title.value='different';f.elements.title.dispatch('input');assert.equal(f.buttons.confirm.disabled,true);assert.equal(f.elements.preview.hidden,true);assert.equal(f.store.size,0);
});
test('DOM ignores delayed report after a company change',async()=>{
    let finish; const f=fixture(url=>url.includes('/status')?Promise.resolve(status()):new Promise(r=>finish=r));
    await flush();f.click('report');f.elements.company.value='2';f.elements.company.dispatch('change');
    finish(response({report:{summary:'OLD PRIVATE DATA',as_of:'now',columns:[],records:[],limitations:[],next_after_id:null}}));await flush();
    assert.equal(f.elements.report.children.length,0);assert.equal(f.elements.site.value,'22');assert.ok(f.events.includes('assistant-scope-changed'));
});
test('DOM restores saved proposal by read-only status before asking for fresh consent',async()=>{
    const calls=[];const f=fixture((url,opts)=>{calls.push({url,opts});return Promise.resolve(url.includes('/status')?status():response({proposal:preview}));},preview.id);
    await flush();assert.equal(calls.find(c=>c.url.includes('/proposals/')).opts.method,'GET');assert.equal(f.elements.approve.checked,false);assert.equal(f.buttons.confirm.disabled,true);
});
test('DOM lost confirmation response can recover applied status without reapplying',async()=>{
    const calls=[];const f=fixture((url,opts)=>{calls.push(url);if(url.includes('/status'))return Promise.resolve(status());if(url.endsWith('/confirm'))return Promise.reject(new Error('network lost'));return Promise.resolve(response({proposal:url.endsWith('/proposals')?preview:{...preview,status:'applied',result:{record_id:7}}}));});
    await flush();f.click('propose');await flush();f.elements.approve.checked=true;f.elements.approve.dispatch('change');f.click('confirm');await flush();
    assert.equal(f.store.get('erp-assistant-proposal-1'),preview.id);f.click('recover');await flush();
    assert.equal(calls.filter(u=>u.endsWith('/confirm')).length,1);assert.equal(f.store.size,0);assert.equal(f.buttons.confirm.disabled,true);
});

test('DOM recovery restores and visibly identifies a different immutable company/site target',async()=>{
    const saved={...preview,company_id:2,site_id:22,operation:'ops.todo.update',record_id:79};
    const f=fixture(url=>Promise.resolve(url.includes('/status')?status():response({proposal:saved})),preview.id);
    await flush();assert.equal(f.elements.company.value,'2');assert.equal(f.elements.site.value,'22');assert.equal(f.elements.record.value,79);
    const text=f.elements['preview-body'].children.map(c=>c.textContent).join(' ');
    assert.match(text,/B \(ID 2\)/);assert.match(text,/B1 \(ID 22\)/);assert.match(text,/ops.todo.update/);assert.match(text,/79/);
    assert.equal(f.elements.approve.checked,false);assert.equal(f.buttons.confirm.disabled,true);
});

test('expense registration preserves cents and explicitly selects USD without approval or payment fields',()=>{
    const result=api().proposalInput('expense.pending.create',{site:'11',expenseDescription:'Materials',amount:'1234.50',expenseDate:'2026-10-06',account:'5201 Job Materials',payment:'corporate',sourceDocument:'71'});
    assert.equal(result.record_id,null);assert.equal(result.payload.amount,'1234.50');assert.equal(result.payload.currency,'USD');assert.equal(result.payload.source_document_id,71);
    assert.equal(result.payload.status,undefined);assert.equal(result.payload.paid_at,undefined);assert.equal(result.payload.reviewed_at,undefined);
});
test('daily report creation cannot submit or overwrite a selected record ID',()=>{
    const result=api().proposalInput('daily_report.draft.create',{site:'11',record:'99',title:'Daily work',reportDate:'2026-10-06',workToday:'Duct installed',workTomorrow:'Pressure test'});
    assert.equal(result.record_id,null);assert.equal(result.payload.work_today,'Duct installed');assert.equal(result.payload.status,undefined);assert.equal(result.payload.submit,undefined);
});
test('category correction sends no document privacy or owner controls',()=>{
    const result=api().proposalInput('document.category.update',{site:'11',record:'71',category:'drawing_spec',title:'ignored',detail:'ignored'});
    assert.equal(result.record_id,71);assert.equal(JSON.stringify(result.payload),JSON.stringify({category:'drawing_spec'}));
    assert.throws(()=>api().proposalInput('sql.execute',{}));
});
test('DOM financial preview recovery shows the exact approved amount and public destination',async()=>{
    const saved={...preview,operation:'expense.pending.create',visibility:'finance_pending_review',after:{description:'Materials',amount:'1234.50',currency:'USD',expense_date:'2026-10-06',accounting_account:'5201 Job Materials',payment_type:'corporate',status:'pending',receipt:{document_id:71}}};
    const f=fixture(url=>Promise.resolve(url.includes('/status')?status():response({proposal:saved})),preview.id);await flush();
    assert.equal(f.elements.amount.value,'1234.50');assert.equal(f.elements['source-document'].value,71);
    const text=f.elements['preview-body'].children.map(c=>c.textContent).join(' ');assert.match(text,/1234.50/);assert.match(text,/USD/);assert.match(text,/finance_pending_review/);assert.equal(f.elements.approve.checked,false);
});

test('DOM early proposal recovery reapplies real select values after delayed status metadata',async()=>{
    let finishStatus;
    const saved={...preview,operation:'expense.pending.create',after:{description:'Materials',amount:'12.34',currency:'USD',accounting_account:'5201 Job Materials',payment_type:'corporate'}};
    const f=fixture(url=>url.includes('/status')?new Promise(r=>finishStatus=r):Promise.resolve(response({proposal:saved})),preview.id);
    const account=f.elements.account;let selected='';
    Object.defineProperty(account,'value',{configurable:true,get:()=>selected,set:v=>{selected=account.children.some(c=>c.value===v)?v:'';}});
    await flush();assert.equal(account.value,'');
    finishStatus(response({mutations_enabled:true,checks_enabled:false,checks:[],expense_accounts:['5201 Job Materials'],document_categories:{drawing_spec:'Drawing'},budget:{}}));
    await flush();assert.equal(account.value,'5201 Job Materials');assert.equal(f.elements.approve.checked,false);
});
