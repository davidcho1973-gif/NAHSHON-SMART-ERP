const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function setup(success=true){
  const elements={},requests=[],groups=[],alerts=[];
  const node=()=>({value:'',textContent:'',style:{},children:[],classList:{visible:false,remove(){this.visible=false},add(){this.visible=true},contains(){return this.visible}},appendChild(n){this.children.push(n)},set innerHTML(v){this.children=[]}});
  const el=id=>elements[id]??=node(),document={getElementById:el,createElement:node,querySelector(){return {getAttribute(){return 'csrf'}}}};
  const html=fs.readFileSync('resources/views/mobile-expense/wizard.blade.php','utf8');
  const start=html.indexOf('    let receiptPages ='),end=html.indexOf('    // 서버가 JSON',start);
  const source=html.slice(start,end).replace(/\{\{ route\('mobile-expense.upload-receipt'\) \}\}/g,'/upload').replace(/@json\(\\App\\Support\\ReceiptUpload::hint\(\)\)/,"'hint'");
  const context=vm.createContext({document,window:{ReceiptPhoto:{prepare:async f=>f}},File,FormData,alert:s=>alerts.push(s),showReceiptUploadPreview(){},rawAmountString:'99',updateAmountDisplay(){el('amountInput').value=vm.runInContext('rawAmountString',context)},buildReceiptDescription:d=>d.description,normalizeAccountingAccount:a=>a,renderReceiptAnalysis(){el('receiptAnalysisCard').classList.add('visible')},goNextStep(){},DocumentPhotos:{async combine(files){groups.push([...files]);return new File(['pdf'],'receipt-pages.pdf',{type:'application/pdf'})}},readReceiptResponse:async res=>res.json(),fetch:async(url,options)=>{requests.push(options.body);return {json:async()=>success?{success:true,receipt_path:'/storage/receipt-pages.pdf',data:{description:'all items',amount:0,date:'2026-10-06',category:'5201 Job Materials'}}:{success:false,error:'retry'}}}});
  vm.runInContext(source,context);
  return {el,groups,requests,alerts,add(name){const input={files:[new File(['photo'],name,{type:'image/jpeg'})],value:name};context.handleReceiptUpload({target:input});return input},analyze:()=>context.analyzeReceiptPages(),size:()=>vm.runInContext('receiptPages.length',context)};
}
test('wizard accumulates repeated captures and analyzes all pages only on explicit action',async()=>{
  const s=setup();const first=s.add('one.jpg');s.add('two.jpg');assert.equal(first.value,'');assert.equal(s.requests.length,0);assert.equal(s.size(),2);
  await s.analyze();assert.equal(s.requests.length,1);assert.equal(s.groups[0].length,2);assert.equal(s.requests[0].get('receipt').type,'application/pdf');
  assert.equal(s.el('receiptPath').value,'/storage/receipt-pages.pdf');assert.equal(s.el('amountInput').value,'0.00');
});
test('adding or removing a page invalidates the previously analyzed attachment',async()=>{
  const s=setup();s.add('one.jpg');await s.analyze();s.add('two.jpg');assert.equal(s.el('receiptPath').value,'');assert.equal(s.el('ocrData').value,'');
  s.el('receiptPagesList').children[0].children[0].onclick();assert.equal(s.size(),1);assert.equal(s.el('receiptPath').value,'');
});
test('analysis failure retains original pages and allows retry',async()=>{
  const s=setup(false);s.add('one.jpg');s.add('two.jpg');await s.analyze();assert.equal(s.size(),2);assert.equal(s.el('receiptAnalyzeButton').disabled,false);assert.equal(s.el('receiptFileInput').disabled,false);assert.equal(s.el('receiptPath').value,'');
});
