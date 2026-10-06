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
    assert.match(code, /function formChanged\(\) \{ invalidateSuggestion\(\); resetPreview\(\); \}/);
    assert.doesNotMatch(code, /innerHTML\s*=/);
});

class Element {
    constructor(tag='div') { this.tagName=tag; this.children=[]; this.dataset={}; this.listeners={}; this.value=''; this.textContent=''; this.disabled=false; this.hidden=false; this.checked=false; }
    appendChild(child) { this.children.push(child); if(this.tagName==='select' && !this.value) this.value=String(child.value); return child; }
    replaceChildren(...children) { this.children=[]; if(this.tagName==='select') this.value=''; children.forEach(c=>this.appendChild(c)); }
    addEventListener(name,fn) { (this.listeners[name] ||= []).push(fn); }
    dispatch(name,event={}) { (this.listeners[name] || []).forEach(fn=>fn(event)); }
    closest(){return this;}
}
function fixture(fetch, saved) {
    const ids=['company','site','dataset','search','export','status','report','write-fields','mutations-off','budget','checks','operation','record','title','detail','due','approve','preview','preview-body','check-kind','check-interval','check-meaning','report-date','work-today','work-tomorrow','expense-description','amount','expense-date','account','payment','source-document','category','request-text','suggestions-off','suggestion-result','change-panel'];
    const selects=['company','site','dataset','operation','check-kind','check-interval','account','payment','category'];
    const elements=Object.fromEntries(ids.map(id=>[id,new Element(selects.includes(id)?'select':'div')]));
    const buttons=Object.fromEntries(['report','next','propose','confirm','recover','cancel','save-check','activate-check','disable-check','suggest'].map(action=>{const e=new Element('button');e.dataset.action=action;return[action,e]}));
    const host=new Element(); host.dataset={base:'/ask-api/workspace',options:JSON.stringify({actor_id:1,companies:[{id:1,name:'A'},{id:2,name:'B'}],sites:[{id:11,company_id:1,name:'A1'},{id:22,company_id:2,name:'B1'}],datasets:[{key:'wbs_items',label:'WBS'}]})};
    host.querySelectorAll=selector=>selector==='[data-operations]'?[]:Object.values(buttons);
    host.querySelector=s=>buttons[(s.match(/data-action[=\"']+([\w-]+)/)||[])[1]] || null;
    const store=new Map(saved ? [['erp-assistant-proposal-1',saved]] : []);
    const document={getElementById:id=>id==='assistant-workspace'?host:elements[id.replace('assistant-','')],querySelector:()=>({content:'fixture-csrf'}),createElement:tag=>new Element(tag)};
    const events=[];
    const lifecycle=new Element();
    const window={document,fetch,sessionStorage:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)},dispatchEvent:e=>events.push(e.type),addEventListener:(name,fn)=>lifecycle.addEventListener(name,fn)};
    vm.runInNewContext(code,{window,Set,URLSearchParams,Event:class{constructor(type){this.type=type;}}});
    elements.operation.value='ops.todo.create'; elements.title.value='Prepare materials'; elements['check-kind'].value='overdue_wbs';elements['check-interval'].value='24';
    elements['request-text'].value='Inspect the duct layout.'; elements['change-panel'].open=true;
    return {elements,buttons,store,events,navigate(name){lifecycle.dispatch(name);},click(action){host.dispatch('click',{target:buttons[action]});}};
}
const response=d=>({ok:true,json:async()=>({success:true,...d})});
const status=(overrides={})=>response({mutations_enabled:true,suggestions_enabled:true,checks_enabled:false,checks:[],budget:{requests:{user_day:{remaining:20,limit:20}}},...overrides});
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

const mappings = {
    'ops.todo.create': {title:'title',detail:'detail',due_on:'due'},
    'ops.todo.update': {title:'title',detail:'detail',due_on:'due'},
    'daily_plan.draft.update': {work_scope:'title',notes:'detail'},
    'daily_report.draft.create': {report_date:'report-date',work_title:'title',work_today:'work-today',work_tomorrow:'work-tomorrow'},
    'expense.pending.create': {description:'expense-description',amount:'amount',expense_date:'expense-date',accounting_account:'account',payment_type:'payment'},
    'document.category.update': {category:'category'},
};
const plain = value=>JSON.parse(JSON.stringify(value));
function suggested(request, fields={}, other={}) {
    const {request_text,...envelope}=request;
    return {...envelope,fields,questions:[],missing_fields:[],...other};
}
function suggestionTestFixture(overrides={}) {
    const calls=[]; let finish;
    const f=fixture((url,opts)=>{
        calls.push({url,opts});
        if(url.includes('/status')) return Promise.resolve(status(overrides));
        if(url.endsWith('/suggestions')) return new Promise(resolve=>{finish=resolve;});
        return Promise.resolve(response({proposal:preview}));
    });
    return {...f,calls,finish(fields={},other={}) {
        const call=calls.findLast(c=>c.url.endsWith('/suggestions'));
        finish(response({suggestion:suggested(JSON.parse(call.opts.body),fields,other)}));
    },finishRaw(data) { finish(data); }};
}
const resultText=f=>{
    function text(el){return [el.textContent,...el.children.map(text)].join(' ');}
    return text(f.elements['suggestion-result']);
};

for (const [operation,mapping] of Object.entries(mappings)) {
    test('bounded suggestions map only editable fields: '+operation,()=>{
        const helpers=api();
        const request=helpers.suggestionInput(operation,{company:'1',site:'11',record:'72',sourceDocument:'73',requestText:'A request'});
        assert.deepEqual(Object.keys(request),['operation','company_id','site_id','record_id','source_document_id','request_text']);
        assert.equal(request.record_id,operation.endsWith('.update')?72:null);
        assert.equal(request.source_document_id,operation==='expense.pending.create'?73:null);
        const fields=Object.fromEntries(Object.keys(mapping).map(key=>[key,key+' value']));
        const current=Object.fromEntries(Object.values(mapping).map(id=>[id,'']));
        const expected=Object.fromEntries(Object.entries(mapping).map(([key,id])=>[id,fields[key]]));
        assert.deepEqual(plain(helpers.suggestionPatch(request,suggested(request,fields),current)),expected);
    });
    test('DOM fills all allowed fields without proposing or confirming: '+operation,async()=>{
        const f=suggestionTestFixture(); await flush();
        f.elements.operation.value=operation;f.elements.record.value='72';f.elements['source-document'].value='73';
        Object.values(mapping).forEach(id=>{f.elements[id].value='';});
        const fields=Object.fromEntries(Object.keys(mapping).map(key=>[key,key+' plain text']));
        f.click('suggest');f.finish(fields);await flush();
        Object.entries(mapping).forEach(([key,id])=>assert.equal(f.elements[id].value,fields[key]));
        assert.equal(f.elements.record.value,'72');assert.equal(f.elements['source-document'].value,'73');assert.equal(f.elements.approve.checked,false);
        assert.equal(f.calls.filter(c=>c.opts.method==='POST').length,1);assert.equal(f.store.size,0);
    });
}

test('suggestion request is bounded by Unicode characters and rejects missing explicit selections',()=>{
    const helpers=api(), values={company:'1',site:'11',requestText:'🛠'.repeat(2000)};
    assert.equal(helpers.suggestionInput('ops.todo.create',values).request_text,values.requestText);
    for (const requestText of ['', '   ', 'x'.repeat(2001)]) assert.throws(()=>helpers.suggestionInput('ops.todo.create',{...values,requestText}));
    assert.throws(()=>helpers.suggestionInput('ops.todo.update',values));
    assert.throws(()=>helpers.suggestionInput('ops.todo.create',{...values,site:''}));
    assert.throws(()=>helpers.suggestionInput('sql.execute',values));
});

test('absent/null values never clear fields and nonempty values are never replaced',()=>{
    const helpers=api(),request=helpers.suggestionInput('ops.todo.create',{company:'1',site:'11',requestText:'A request'});
    const result=helpers.suggestionPatch(request,suggested(request,{title:'New',detail:null}),{title:'My title',detail:'My detail',due:'2026-10-06'});
    assert.deepEqual(plain(result),{});
    assert.deepEqual(plain(helpers.suggestionPatch(request,suggested(request,{title:'New'}),{title:' ',detail:'',due:''})),{});
});

test('suggestion responses reject mismatched server envelopes and forbidden fields atomically',()=>{
    const helpers=api(),request=helpers.suggestionInput('ops.todo.create',{company:'1',site:'11',requestText:'A request'});
    for (const [key,value] of Object.entries({operation:'ops.todo.update',company_id:2,site_id:22,record_id:1,source_document_id:9})) {
        assert.throws(()=>helpers.suggestionPatch(request,suggested(request,{title:'Unsafe'},{[key]:value}),{title:''}));
    }
    for (const fields of [{title:'Valid',confirmed:'true'},{currency:'USD'},{company_id:'2'},{source_document_id:'2'},{title:12},{title:{}},[]]) {
        assert.throws(()=>helpers.suggestionPatch(request,suggested(request,fields),{title:''}));
    }
    assert.throws(()=>helpers.suggestionPatch(request,suggested(request,{title:'Value'},{questions:[{}]}),{title:''}));
    assert.throws(()=>helpers.suggestionPatch(request,suggested(request,{title:'Value'},{questions:['x'.repeat(201)]}),{title:''}));
    assert.throws(()=>helpers.suggestionPatch(request,suggested(request,{title:'Value'},{execute:'approve'}),{title:''}));
});

test('DOM preserves existing edits and renders questions as inert plain text',async()=>{
    const f=suggestionTestFixture();await flush();f.elements.detail.value='My detail';f.elements.due.value='';
    f.click('suggest');f.finish({title:'Replacement',detail:null,due_on:'2026-10-06'},{questions:['<img src=x onerror=alert(1)>'],missing_fields:['title']});await flush();
    assert.equal(f.elements.title.value,'Prepare materials');assert.equal(f.elements.detail.value,'My detail');assert.equal(f.elements.due.value,'2026-10-06');
    assert.match(resultText(f),/<img src=x onerror=alert\(1\)>/);assert.doesNotMatch(resultText(f),/직접 확인할 필수 항목/);
    assert.doesNotMatch(code,/innerHTML\s*=/);assert.equal(f.calls.filter(c=>c.url.endsWith('/suggestions')).length,1);
});

for (const [id,event,value] of [['company','change','2'],['site','change','22'],['operation','change','daily_report.draft.create'],['record','input','9'],['source-document','change','9'],['request-text','input','New request'],['detail','input','New detail']]) {
    test('DOM discards a delayed suggestion after '+id+' changes',async()=>{
        const f=suggestionTestFixture();await flush();f.elements.title.value='';f.click('suggest');
        assert.equal(f.elements.company.disabled,false);assert.equal(f.elements.site.disabled,false);
        f.elements[id].value=value;f.elements[id].dispatch(event);f.finish({title:'OLD'});await flush();
        assert.equal(f.elements.title.value,'');assert.equal(f.elements[id].value,value);assert.match(resultText(f),/이전 제안을 무시/);
    });
}

test('DOM snapshot also rejects silent field edits with no input event',async()=>{
    const f=suggestionTestFixture();await flush();f.elements.title.value='';f.click('suggest');f.elements.detail.value='silent edit';f.finish({title:'OLD'});await flush();
    assert.equal(f.elements.title.value,'');assert.equal(f.elements.detail.value,'silent edit');
});

test('DOM repeated/conflicting clicks stay blocked across a status refresh',async()=>{
    const f=suggestionTestFixture();await flush();f.click('suggest');f.click('suggest');f.click('propose');f.click('confirm');
    f.elements.company.value='2';f.elements.company.dispatch('change');await flush();
    assert.equal(f.buttons.suggest.disabled,true);assert.equal(f.buttons.propose.disabled,true);assert.equal(f.buttons.confirm.disabled,true);
    f.click('suggest');f.click('propose');assert.equal(f.calls.filter(c=>c.opts.method==='POST').length,1);
    f.finish({title:'OLD'});await flush();assert.equal(f.buttons.suggest.disabled,false);assert.equal(f.buttons.propose.disabled,false);
});

for (const gate of [{suggestions_enabled:false},{mutations_enabled:false},{suggestions_enabled:undefined}]) {
    test('DOM fail-closed suggestion gate '+JSON.stringify(gate),async()=>{
        const f=suggestionTestFixture(gate);await flush();f.click('suggest');assert.equal(f.buttons.suggest.disabled,true);
        assert.equal(f.calls.some(c=>c.url.endsWith('/suggestions')),false);
        if(gate.mutations_enabled!==false) assert.equal(f.buttons.propose.disabled,false);
    });
}

test('DOM rejected envelope preserves every field and uses a generic error',async()=>{
    const f=suggestionTestFixture();await flush();f.elements.title.value='';f.click('suggest');f.finish({title:'Unsafe'},{company_id:2});await flush();
    assert.equal(f.elements.title.value,'');assert.match(resultText(f),/제안을 가져오지 못했습니다/);
    assert.equal(f.buttons.propose.disabled,false);assert.ok(f.calls.filter(c=>c.url.includes('/status')).length>=2);
});

test('DOM provider errors are generic, preserve values, and do not retry automatically',async()=>{
    const f=suggestionTestFixture();await flush();f.click('suggest');f.finishRaw({ok:false,json:async()=>({success:false,error:'SECRET RAW PROVIDER BODY'})});await flush();
    assert.equal(f.elements.title.value,'Prepare materials');assert.doesNotMatch(resultText(f),/SECRET/);
    assert.equal(f.calls.filter(c=>c.url.endsWith('/suggestions')).length,1);assert.equal(f.buttons.propose.disabled,false);
});

test('DOM filled suggestion clears an old immutable preview and its checked consent',async()=>{
    const f=suggestionTestFixture();await flush();f.click('propose');await flush();f.elements.approve.checked=true;f.elements.approve.dispatch('change');
    assert.equal(f.buttons.confirm.disabled,false);f.click('suggest');f.finish({detail:'Suggested detail'});await flush();
    assert.equal(f.elements.detail.value,'Suggested detail');assert.equal(f.elements.preview.hidden,true);assert.equal(f.elements.approve.checked,false);assert.equal(f.store.size,0);
    assert.equal(f.buttons.confirm.disabled,true);assert.equal(f.calls.filter(c=>c.url.endsWith('/proposals')).length,1);
});

test('DOM newer read-only recovery invalidates the pending suggestion and clears consent',async()=>{
    const f=suggestionTestFixture();await flush();f.click('propose');await flush();f.click('suggest');f.click('recover');await flush();
    f.finish({detail:'STALE'});await flush();assert.equal(f.elements.detail.value,'');assert.equal(f.elements.approve.checked,false);assert.equal(f.buttons.confirm.disabled,true);
    assert.equal(f.calls.find(c=>c.url.endsWith('/'+preview.id)).opts.method,'GET');assert.equal(f.store.get('erp-assistant-proposal-1'),preview.id);
});

for (const action of ['pagehide','popstate','close']) {
    test('DOM navigation '+action+' invalidates pending suggestion without losing recovery id',async()=>{
        const f=suggestionTestFixture();await flush();f.click('propose');await flush();f.click('suggest');
        if(action==='close'){f.elements['change-panel'].open=false;f.elements['change-panel'].dispatch('toggle');}else f.navigate(action);
        f.finish({detail:'STALE'});await flush();assert.equal(f.elements.detail.value,'');assert.equal(f.elements.approve.checked,false);
        if(action!=='close') assert.equal(f.store.get('erp-assistant-proposal-1'),preview.id);
    });
}

test('DOM request validation makes no provider call and missing fields respect existing values',async()=>{
    const f=suggestionTestFixture();await flush();f.elements['request-text'].value='x'.repeat(2001);f.click('suggest');
    assert.equal(f.calls.some(c=>c.url.endsWith('/suggestions')),false);
    f.elements['request-text'].value='A request';f.elements.title.value='';f.click('suggest');f.finish({},{questions:[],missing_fields:['title']});await flush();
    assert.match(resultText(f),/직접 확인할 필수 항목/);assert.equal(f.elements.title.value,'');
});

test('DOM a gate revoked by late status suppresses an otherwise valid suggestion',async()=>{
    let count=0,finishStatus,finishSuggestion,request;
    const f=fixture((url,opts)=>{
        if(url.includes('/status')) return ++count===2?new Promise(resolve=>{finishStatus=resolve;}):Promise.resolve(status({suggestions_enabled:count===1}));
        request=JSON.parse(opts.body);return new Promise(resolve=>{finishSuggestion=resolve;});
    });
    await flush();f.elements.title.value='';f.elements.company.dispatch('change');f.click('suggest');
    finishStatus(status({suggestions_enabled:false}));await flush();finishSuggestion(response({suggestion:suggested(request,{title:'REVOKED'})}));await flush();
    assert.equal(f.elements.title.value,'');assert.equal(f.buttons.suggest.disabled,true);assert.equal(f.buttons.propose.disabled,false);
});

test('DOM a delayed status cannot re-enable controls during an immutable preview request',async()=>{
    let count=0,finishStatus,finishProposal;
    const f=fixture(url=>{
        if(url.includes('/status')) return ++count===2?new Promise(resolve=>{finishStatus=resolve;}):Promise.resolve(status());
        return new Promise(resolve=>{finishProposal=resolve;});
    });
    await flush();f.elements.company.dispatch('change');f.click('propose');finishStatus(status());await flush();
    assert.equal(f.elements['write-fields'].disabled,true);assert.equal(f.buttons.suggest.disabled,true);assert.equal(f.buttons.propose.disabled,true);
    finishProposal(response({proposal:preview}));await flush();assert.equal(f.elements['write-fields'].disabled,false);assert.equal(f.buttons.confirm.disabled,true);
});

test('DOM empty suggestion does not claim to have filled fields or create an approval',async()=>{
    const f=suggestionTestFixture();await flush();f.click('suggest');f.finish({});await flush();
    assert.match(resultText(f),/채울 수 있는 빈 항목이 없습니다/);assert.equal(f.store.size,0);assert.equal(f.elements.approve.checked,false);
});

test('DOM missing-report selection offers only hourly checks and explains their scope', async()=>{
    const f=fixture(()=>Promise.resolve(response({mutations_enabled:true,checks_enabled:false,checks:[],
        check_intervals:{missing_trade_reports:[1],pending_expense_approvals:[1,6,24]},
        check_descriptions:{missing_trade_reports:'Today only, after the site deadline.'},budget:{}})));
    await flush();
    f.elements['check-kind'].value='missing_trade_reports';f.elements['check-kind'].dispatch('change');
    assert.equal(f.elements['check-interval'].value,'1');
    assert.deepEqual(f.elements['check-interval'].children.map(c=>c.value),['1']);
    assert.equal(f.elements['check-meaning'].textContent,'Today only, after the site deadline.');
    f.elements['check-kind'].value='pending_expense_approvals';f.elements['check-kind'].dispatch('change');
    assert.deepEqual(f.elements['check-interval'].children.map(c=>c.value),['24','6','1']);
});

test('DOM saving a check never activates it and respects the disabled server gate', async()=>{
    const calls=[];
    const check={id:8,site_name:'Site',label:'오늘 공종·부서 일일보고 미제출',interval_hours:1,enabled:false,approval_version:'version'};
    const f=fixture((url,opts)=>{calls.push({url,opts});return Promise.resolve(url.includes('/status')?response({mutations_enabled:true,checks_enabled:false,checks:calls.some(c=>c.url.endsWith('/checks'))?[check]:[],budget:{}}):response({check}));});
    await flush();f.elements['check-kind'].value='missing_trade_reports';f.elements['check-kind'].dispatch('change');
    f.click('save-check');await flush();await flush();
    const saved=JSON.parse(calls.find(c=>c.url.endsWith('/checks')).opts.body);
    assert.deepEqual(saved,{site_id:11,kind:'missing_trade_reports',interval_hours:1});
    assert.equal(calls.some(c=>c.url.includes('/activate')),false);
    const row=f.elements.checks.children[0];
    assert.equal(row.children.find(c=>c.tagName==='button').disabled,true);
    assert.equal(row.children.some(c=>c.tagName==='label'),false);
});

test('DOM missing-report zero count includes the evaluation note and local cutoff',async()=>{
    const check={id:9,site_name:'Site',label:'Missing reports',interval_hours:1,enabled:true,
        result:{count:0,records:[],state:'no_expectation',message:'No attendance evidence; this does not mean all reports are complete.',due_at:'2026-10-06T17:00:00-04:00',as_of:'2026-10-06T21:00:00Z'}};
    const f=fixture(()=>Promise.resolve(response({mutations_enabled:true,checks_enabled:true,checks:[check],budget:{}})));
    await flush();
    const text=f.elements.checks.children[0].children.map(c=>c.textContent).join(' ');
    assert.match(text,/does not mean all reports are complete/);
    assert.match(text,/17:00:00-04:00/);
});
