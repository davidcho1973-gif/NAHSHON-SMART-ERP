const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function setup(ok=true){
  const elements={},requests=[],combined=[];
  function element(){return {hidden:false,disabled:false,listeners:{},children:[],appendChild(c){this.children.push(c)},setAttribute(){},getAttribute(){return 'csrf'},addEventListener(k,fn){this.listeners[k]=fn},click(){this.clicked=true},textContent:'',innerHTML:''}}
  const document={getElementById(id){return elements[id]??=element()},querySelector(){return element()},createElement(){return element()}};
  const html=fs.readFileSync('resources/views/attendance-app/docs.blade.php','utf8');
  const script=[...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1]
    .replace(/@json\(\\App\\Support\\AppLocale::dictionary\(\)\)/,'{}')
    .replace(/@json\(\$user\?->employee\?->site_id\)/,'123')
    .replace(/@json\(\$recent, JSON_UNESCAPED_UNICODE\)/,'[]')
    .replace(/@json\(route\('docs.upload'\)\)/,"'/docs-api/upload'");
  const context=vm.createContext({document,FormData,Promise,DocumentPhotos:{isPhoto:f=>f.type.startsWith('image/'),async combine(files){combined.push([...files]);return new File(['pdf'],'invoice-pages.pdf',{type:'application/pdf'})}},ReceiptPhoto:{prepare:async f=>f},fetch:async(url,opts)=>{requests.push(opts.body);return {ok,json:async()=>({success:ok,error:ok?undefined:'Upload failed',document:ok?{id:1,title:'Invoice'}:undefined})}}});
  vm.runInContext(script,context);document.getElementById('combine-photos').checked=true;
  const add=(id,file)=>{elements[id].files=[file];elements[id].listeners.change.call(elements[id])};
  const send=async()=>{await elements.send.listeners.click.call(elements.send);for(let i=0;i<5;i++)await new Promise(setImmediate)};
  return {elements,requests,combined,add,send,queue:()=>vm.runInContext('queue',context)};
}
test('repeated camera captures accumulate and submit one document with its site',async()=>{
  const s=setup();for(const name of ['first.jpg','second.jpg'])s.add('camera-file',new File(['image'],name,{type:'image/jpeg'}));
  assert.equal(s.queue().length,2);assert.equal(s.elements['camera-file'].value,'');
  await s.send();assert.equal(s.combined[0].length,2);assert.equal(s.requests.length,1);
  assert.equal(s.requests[0].get('file').name,'invoice-pages.pdf');assert.equal(s.requests[0].get('site_id'),'123');assert.equal(s.queue().length,0);
});
test('failed registration retains original pages and re-enables capture for retry',async()=>{
  const s=setup(false);for(const name of ['one.jpg','two.jpg'])s.add('camera-file',new File(['image'],name,{type:'image/jpeg'}));
  await s.send();assert.equal(s.queue().length,2);assert.equal(s.elements.camera.disabled,false);assert.equal(s.elements.send.disabled,false);assert.match(s.elements.msg.textContent,/Upload failed/);
});
test('separate mode registers photos independently',async()=>{
  const s=setup();s.elements['combine-photos'].checked=false;
  for(const name of ['one.jpg','two.jpg'])s.add('files',new File(['image'],name,{type:'image/jpeg'}));
  await s.send();assert.equal(s.combined.length,0);assert.equal(s.requests.length,2);
});
