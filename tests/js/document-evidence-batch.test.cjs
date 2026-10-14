const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/document-evidence-batch.js','utf8');
const context = {}; vm.runInNewContext(source,context);
const {Workflow,esc} = context.DocumentEvidenceBatch;
const file = (name,size=10) => ({name,size,lastModified:1,webkitRelativePath:''});
function setup(overrides={}) {
    let serial=0, requests=[], sent=[];
    const request = async(path, options)=>{requests.push({path,options});return {batch:{id:7,status:path.includes('/start')?'running':'draft',total:path.includes('/start')?sent.length:0}};};
    const send = async(id, original, key, progress)=>{sent.push({id,original,key});progress(original.size);return {success:true,batch:{id,status:'draft',total:sent.length}};};
    return {workflow:new Workflow({request,send,maxBytes:100,makeKey:()=>String(++serial),...overrides}),requests,sent};
}
test('stores mixed original files serially and never starts analysis during upload',async()=>{
    const {workflow,requests,sent}=setup(); const originals=[file('receipt.jpg'),file('delivery.pdf')];
    workflow.select(originals); await workflow.upload({project_id:8,contract_id:4});
    assert.equal(requests.length,1); assert.equal(requests[0].options.body.project_id,8);
    assert.equal(sent[0].original,originals[0]); assert.equal(sent[1].original,originals[1]);
    assert.equal(workflow.batch.status,'draft'); assert.ok(workflow.files.every(r=>r.state==='stored'));
    await workflow.start(); assert.equal(requests[1].path,'/7/start'); assert.equal(workflow.batch.status,'running');
});
test('partial upload failure retries the original stable key and skips stored files',async()=>{
    const calls=[];let fail=true,total=0;
    const {workflow}=setup({send:async(id,original,key)=>{calls.push({name:original.name,key});if(original.name==='b.pdf'&&fail){fail=false;throw new Error('network lost');}return {batch:{id,status:'draft',total:++total}};}});
    workflow.select([file('a.pdf'),file('b.pdf')]);await workflow.upload({project_id:1});
    assert.equal(workflow.files[0].state,'stored');assert.equal(workflow.files[1].state,'failed');
    await workflow.upload({project_id:1});assert.deepEqual(calls.map(r=>r.name),['a.pdf','b.pdf','b.pdf']);assert.equal(calls[1].key,calls[2].key);
});
test('a failed create retries with the same session key and no file transfer beforehand',async()=>{
    const keys=[];let first=true;
    const {workflow,sent}=setup({request:async(path,o)=>{keys.push(o.body.upload_session_key);if(first){first=false;throw new Error('timeout');}return {batch:{id:7,total:0,status:'draft'}};}});
    workflow.select([file('one.pdf')]);await assert.rejects(workflow.upload({project_id:1}),/timeout/);assert.equal(sent.length,0);
    await workflow.upload({project_id:1});assert.equal(keys[0],keys[1]);assert.equal(sent.length,1);
});
test('double click, reset, selection and start cannot interrupt an upload',async()=>{
    let release;
    const {workflow,requests}=setup({send:()=>new Promise(resolve=>{release=()=>resolve({batch:{id:7,total:1,status:'draft'}});})});
    workflow.select([file('one.pdf')]); const pending=workflow.upload({project_id:1});await Promise.resolve();
    await workflow.upload({project_id:2});assert.equal(workflow.reset(),false);workflow.select([file('two.pdf')]);await workflow.start();
    assert.equal(workflow.files.length,1);assert.equal(requests.length,1);release();await pending;assert.equal(workflow.busy,false);
});
test('start refuses selected files; rematch and failure retry are explicit requests',async()=>{
    const {workflow,requests}=setup();workflow.batch={id:7,status:'draft',total:1};workflow.select([file('one.pdf')]);
    await assert.rejects(workflow.start(),/업로드/);assert.equal(requests.length,0);
    workflow.files=[];workflow.batch.status='done';await workflow.start({rematch:true});assert.equal(requests[0].options.body.rematch,true);
    workflow.batch.status='failed';workflow.batch.total=1;await workflow.start({retry_failed:true});assert.equal(requests[1].options.body.retry_failed,true);
});
test('oversized and empty files stay visibly invalid instead of being sent or compressed',async()=>{
    const {workflow,sent}=setup();workflow.select([file('large.jpg',101),file('empty.pdf',0),file('ok.jpg',50)]);
    await workflow.upload({project_id:1});assert.equal(sent.length,1);assert.equal(sent[0].original.name,'ok.jpg');assert.equal(workflow.files.filter(r=>r.state==='invalid').length,2);
});
test('folder selection deduplicates identical entries but retains different relative paths',()=>{
    const {workflow}=setup();const a={...file('one.pdf'),webkitRelativePath:'floor1/one.pdf'},b={...file('one.pdf'),webkitRelativePath:'floor2/one.pdf'};
    workflow.select([a,a,b]);assert.equal(workflow.files.length,2);
    workflow.batch={id:7,status:'running',total:2};workflow.select([file('three.pdf')]);assert.equal(workflow.files.length,2);
});
test('batch file cap includes already stored files without double counting local stored entries',()=>{
    const {workflow}=setup();workflow.batch={id:7,status:'draft',total:999};workflow.select([file('last.pdf')]);
    assert.throws(()=>workflow.select([file('extra.pdf')]),/1,000/);assert.equal(workflow.files.length,1);
});
test('untrusted names and errors are escaped and upload transport does not combine or reencode sources',()=>{
    assert.equal(esc('<img src=x onerror="alert(1)">'), '&lt;img src=x onerror=&quot;alert(1)&quot;&gt;');
    assert.ok(source.includes("form.append('file',file)"));assert.ok(!source.includes('ReceiptPhoto.prepare'));assert.ok(!source.includes('DocumentPhotos.combine'));
});
test('original review JSON requests retain CSRF and Accept headers',async()=>{
    const blade=fs.readFileSync('resources/views/document-intelligence/index.blade.php','utf8');
    const helper=blade.split('\n').find(line=>line.startsWith('async function jsonFetch('));
    let captured;
    const ctx={csrf:'synthetic-csrf',fetch:async(url,options)=>{captured=options;return {ok:true,json:async()=>({success:true})};}};
    vm.runInNewContext(helper,ctx);
    await ctx.jsonFetch('/document-hub/api/documents/1/review',{method:'PATCH',headers:{'Content-Type':'application/json'},body:'{}'});
    assert.equal(captured.headers['X-CSRF-TOKEN'],'synthetic-csrf');assert.equal(captured.headers.Accept,'application/json');assert.equal(captured.headers['Content-Type'],'application/json');assert.equal(captured.credentials,'same-origin');
});
