const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function setup(results=[{success:true,analyzed:{vendor:'Supplier',amount:100}}],bad=false){
  const elements={},requests=[],groups=[],released=[];
  function node(){return {style:{},value:'',textContent:'',dataset:{},listeners:{},classList:{toggle(){},add(){},remove(){}},addEventListener(k,fn){this.listeners[k]=fn},click(){},get innerHTML(){return this.html??this.textContent.replace(/[<>&"]/g,c=>({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]))},set innerHTML(v){this.html=v}}}
  const el=id=>elements[id]??=node();
  const document={getElementById:el,createElement:node,querySelector(selector){return selector==='meta[name=csrf-token]'?{content:'csrf'}:el(selector)},querySelectorAll(){return []}};
  el('group-receipt').checked=true;el('pane-mine').style.display='none';
  const window={scrollTo(){},ReceiptPhoto:{prepare:async f=>f}};
  const context=vm.createContext({window,document,localStorage:{getItem(){return 'ko'}},URL:{createObjectURL:f=>'blob:'+f.name,revokeObjectURL:url=>released.push(url)},FormData,File,Promise,setTimeout(){},clearTimeout(){},DocumentPhotos:{async combine(files){groups.push([...files]);if(bad)throw Error('decode failed');return new File(['pdf'],'purchase-pages.pdf',{type:'application/pdf'})}},fetch:async(url,opts)=>{requests.push(opts.body);const result=results.shift()||{success:true,analyzed:{amount:100}};return {json:async()=>result}}});
  let source=[...fs.readFileSync('resources/views/expense-app/index.blade.php','utf8').matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1];
  source=source.replace(/@json\(\\App\\Support\\AppLocale::dictionary\(\)\)/,'{}').replace(/@json\(\(bool\) \$employee\)/,'true').replace(/@json\(route\('([^']+)'\)\)/g,"'/api'");
  vm.runInContext(source,context);
  const add=name=>{el('file-cam').files=[new File(['photo'],name,{type:'image/jpeg'})];el('file-cam').listeners.change()};
  return {add,el,groups,requests,released,window,send:()=>el('go').listeners.click()};
}
test('continuation photos submit once with one payment type and memo',async()=>{
  const s=setup();s.el('memo').value='One purchase';s.add('page1.jpg');s.add('page2.jpg');await s.send();
  assert.equal(s.groups[0].length,2);assert.equal(s.requests.length,1);assert.equal(s.requests[0].get('receipt').type,'application/pdf');
  assert.equal(s.requests[0].get('memo'),'One purchase');assert.equal(s.requests[0].get('payment_type'),'personal');
  assert.match(s.el('result-card').innerHTML,/1건 접수/);assert.equal(s.released.length,2);
});
test('group needing manual total retries the same PDF as one purchase',async()=>{
  const s=setup([{success:false,code:'need_amount'},{success:true,analyzed:{amount:130}}]);s.add('p1.jpg');s.add('p2.jpg');await s.send();
  assert.match(s.el('queue').innerHTML,/q-amount/);assert.equal(s.released.length,0);
  s.window._qAmount(0,'130');await s.send();assert.equal(s.groups.length,1);assert.equal(s.requests.length,2);assert.equal(s.requests[1].get('amount'),'130');assert.equal(s.released.length,2);
});
test('conversion failure leaves pages for retry and sends no expenses',async()=>{
  const s=setup([],true);s.add('one.jpg');s.add('two.jpg');await s.send();assert.equal(s.requests.length,0);assert.equal(s.released.length,0);
  assert.equal(s.el('group-receipt').disabled,false);assert.equal(s.el('go').disabled,false);assert.match(s.el('toast').textContent,/decode failed/);
});
test('different-purchase mode keeps independent receipt submissions',async()=>{
  const s=setup();s.el('group-receipt').checked=false;s.add('one.jpg');s.add('two.jpg');await s.send();assert.equal(s.groups.length,0);assert.equal(s.requests.length,2);
});
test('a page added after a grouped error rebuilds all original pages in order',async()=>{
  const s=setup([{success:false,code:'need_amount'}]);s.add('p1.jpg');s.add('p2.jpg');await s.send();s.add('p3.jpg');await s.send();
  assert.deepEqual(s.groups[1].map(f=>f.name),['p1.jpg','p2.jpg','p3.jpg']);assert.equal(s.requests.length,2);
});
