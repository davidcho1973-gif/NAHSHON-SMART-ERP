const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/purchase-common.js'), 'utf8');

function harness(fetchImpl) {
  const calls = [];
  const window = {location:{href:'https://erp.example.test/?view=purchase-requests'}};
  vm.runInNewContext(source, {
    window, URL, FormData, crypto:require('node:crypto').webcrypto,
    location:{origin:'https://erp.example.test'},
    document:{querySelector:()=>({content:'csrf-test'})},
    setTimeout:callback=>callback(),
    fetch:async(url,options)=>{calls.push({url,options});return fetchImpl ? fetchImpl(url,options) : {ok:true,json:async()=>({success:true})};},
  });
  return {P:window.PurchaseRequests,calls,window};
}

test('buyer reauthentication opens ERP sign-in without logging out or revoking the personal device', () => {
  const {P,calls,window}=harness();
  P.reauthenticate();
  assert.equal(window.location.href,'/login?erp=1');
  assert.equal(calls.length,0);
});

test('request content is escaped and executable product links are excluded', () => {
  const {P}=harness();
  const html=P.details({site_name:'<img src=x onerror=alert(1)>',note:'<script>bad</script>',lines:[{name:'<b>Pipe</b>',quantity:2,unit:'EA',product_url:'javascript:alert(1)'}],attachments:[{name:'<b>proof</b>',url:'/purchase-requests/1/attachments/2'}]});
  assert.ok(html.includes('&lt;script&gt;'));
  assert.ok(!html.includes('<script>'));
  assert.ok(!html.includes('javascript:'));
  assert.ok(html.includes('https://erp.example.test/purchase-requests/1/attachments/2'));
  assert.equal(P.safeUrl(''),'');
  assert.equal(P.safeUrl(undefined),'');
});

test('mutation includes CSRF and cookies and surfaces field validation errors', async () => {
  const {P,calls}=harness(()=>({ok:false,json:async()=>({errors:{quantity:['수량을 확인하세요.']}})}));
  await assert.rejects(P.api('/4/action','POST',{action:'order'}),/수량을 확인하세요/);
  assert.equal(calls[0].options.credentials,'same-origin');
  assert.equal(calls[0].options.headers['X-CSRF-TOKEN'],'csrf-test');
  assert.equal(calls[0].options.body,'{"action":"order"}');
});

test('upload preserves multipart body and purpose rather than pretending a local file is attached', async () => {
  const {P,calls}=harness();
  await P.upload(8,new Blob(['evidence'],{type:'application/pdf'}),'order');
  assert.equal(calls[0].url,'/purchase-requests/8/attachments');
  assert.equal(calls[0].options.body.get('purpose'),'order');
  assert.equal(calls[0].options.headers['Content-Type'],undefined);
});

test('failed analysis cannot appear as a completed empty draft', async () => {
  let count=0;
  const {P}=harness(()=>({ok:true,json:async()=>++count===1?{job_id:4}:{status:'failed',error:'분석 실패'}}));
  await assert.rejects(P.analyze(new FormData()),/분석 실패/);
});

test('analysis awaits completed result and accepts canonical done flag', async () => {
  let count=0;
  const {P,calls}=harness(()=>({ok:true,json:async()=>++count===1?{job_id:4}:{status:'completed',done:true,result:{lines:[{name:'Pipe'}]}}}));
  const result=await P.analyze(new FormData());
  assert.equal(result.lines[0].name,'Pipe');
  assert.equal(calls[1].url,'/purchase-requests/analysis/4');
});
