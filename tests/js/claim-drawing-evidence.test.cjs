const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname,'../../public/js/claim-drawing-evidence.js'),'utf8');
function load() {
  const window = {location:{href:'https://erp.example.com/',origin:'https://erp.example.com'}, AdminUI:{esc:s=>String(s).replace(/</g,'&lt;').replace(/"/g,'&quot;')}};
  vm.runInNewContext(source,{window,URL,document:{body:{contains:()=>false}}});
  return window;
}
test('only same-origin HTTP evidence links are rendered and source titles are escaped',()=>{
  const w=load();
  assert.equal(w.AdminDrawingEvidence._safeUrl('javascript:alert(1)'),'');
  assert.equal(w.AdminDrawingEvidence._safeUrl('https://other.example/a'),'');
  const html=w.AdminDrawingEvidence.sheetHtml([{contractId:2,pagination:{total:1},rows:[{title:'<script>x</script>',url:'/document/1',lineLabels:['Piping'],status:'review'}]}]);
  assert.ok(!html.includes('<script>'));
  assert.ok(html.includes('다시 확인 필요'));
  assert.ok(html.includes('도면 위치 미지정'));
});
test('drawing evidence listing keeps scope and paginates through the existing inbox',async()=>{
  const w=load();let call;
  w.gsRun=async(method,args)=>{call={method,args};return {success:true,rows:[],pagination:{page:1,lastPage:2}};};
  w.AdminUI.table=()=>'<table></table>';
  w.AdminUI.modal=opts=>{assert.ok(opts.actions.some(a=>a.value==='next'));return {result:Promise.resolve(null)};};
  await w.AdminDrawingEvidence.list(5,null,'P-101',1);
  assert.equal(call.method,'api_getClaimEvidenceInbox');
  assert.equal(call.args[0],5);
  assert.equal(call.args[1].sheetNo,'P-101');
  assert.equal(call.args[1].status,'linked');
});
test('intake opens for the selected site and contract and blocks closing during upload',()=>{
  const w=load();let config;
  w.AdminUI.modal=opts=>{config=opts;return {result:new Promise(()=>{})};};
  w.AdminUI.toast=()=>{};
  w.AdminDrawingEvidence.upload(4,9);
  assert.ok(config.body.includes('site_id=4&amp;') || config.body.includes('site_id=4&contract_id=9'));
  assert.equal(config.beforeClose({querySelector:()=>({contentWindow:{documentEvidenceWorkflow:{busy:true}}})}),false);
});
