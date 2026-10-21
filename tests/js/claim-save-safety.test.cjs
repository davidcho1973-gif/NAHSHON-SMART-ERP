const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
function setup() {
  const data = new Map();
  const storage = {get length(){return data.size;},key:i=>[...data.keys()][i],getItem:k=>data.get(k)||null,setItem:(k,v)=>data.set(k,v),removeItem:k=>data.delete(k)};
  const context={crypto:webcrypto,localStorage:storage}; vm.createContext(context);
  vm.runInContext(fs.readFileSync('public/js/claim-save-safety.js','utf8'),context);
  return {api:context.ClaimSaveSafety,storage,data};
}
test('a lost response retries the exact request before accepting newer edits', async()=>{
  const {api}=setup(); const received=[]; let attempts=0, content='First';
  const writer=new api.Writer(async request=>{received.push(request);if(++attempts===1)throw Error('response lost');return {revision:1};});
  await assert.rejects(writer.run(()=>({content,revision:0})));
  content='Typed during disconnect';
  const retried=await writer.run(()=>({content,revision:0}));
  assert.equal(received[0].requestId,received[1].requestId);
  assert.equal(retried.sent.content,'First');
  await writer.run(()=>({content,revision:1}));
  assert.notEqual(received[2].requestId,received[0].requestId);
  assert.equal(received[2].content,content);
});
test('reload preserves uncertain request identity and newly typed content',async()=>{
  const {api}=setup();const vault=api.recovery('editor:1:2',{user:3});
  const writer=new api.Writer(async()=>{throw Error('offline');});
  await assert.rejects(writer.run(()=>({revision:4,content:{notes:'Sent'}})));
  vault.write({content:{notes:'Newer typing'},pending:writer.pending});
  const recovered=api.recovery('editor:1:2',{user:3}).latest();
  const other=new api.Writer(async request=>({saved:request.content}));other.restore(recovered.pending);
  const ack=await other.run(()=>({content:recovered.content}));
  assert.equal(ack.sent.requestId,writer.pending.requestId);
  assert.equal(ack.response.saved.notes,'Sent');
  assert.equal(recovered.content.notes,'Newer typing');
});
test('two tabs and two users never silently overwrite or clear another newer backup',()=>{
  const {api,data}=setup();const first=api.recovery('line:1',{user:10}),second=api.recovery('line:1',{user:10});
  first.write({notes:'First'});assert.equal(second.latest().notes,'First');
  second.write({notes:'Second'});first.write({notes:'Further first edit'});second.clear();
  assert.equal(data.size,1);
  assert.equal(api.recovery('line:1',{user:10}).latest().notes,'Further first edit');
  assert.equal(api.recovery('line:1',{user:11}).latest(),null);
  assert.equal(api.recovery('line:2',{user:10}).latest(),null);
});
test('storage failures surface rather than claiming backup success',()=>{
  const {api}=setup();const vault=api.recovery('line:1',{user:10,storage:{setItem(){throw Error('quota');}}});
  assert.throws(()=>vault.write({notes:'Keep this on screen'}),/quota/);
});
