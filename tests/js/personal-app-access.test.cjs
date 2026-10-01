const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const source=name=>fs.readFileSync(path.join(__dirname,'../../public/js/',name),'utf8');

test('QR manager reauthentication opens ERP sign-in without submitting logout or clearing the device',()=>{
  const window={location:{href:'https://erp.example.com/attendance-app/manager-access'},addEventListener(){}};
  vm.runInNewContext(source('personal-app-access.js'),{window,document:{getElementById(){return null;}}});
  window.PersonalAppAccess.loginAgain();
  assert.equal(window.location.href,'/login?erp=1');
});

test('QR links stay on this ERP and cannot become a different action or external URL',()=>{
  const window={addEventListener(){}};
  vm.runInNewContext(source('personal-app-access.js'),{window,document:{getElementById(){return null;}},URL,location:{origin:'https://erp.example.com'}});
  const parse=window.PersonalAppAccess.connectionUrl;
  assert.equal(parse('https://erp.example.com/app/connect/valid_TOKEN-123'),'https://erp.example.com/app/connect/valid_TOKEN-123');
  for(const url of ['https://other.example.com/app/connect/token','javascript:alert(1)','/gate/2','/app/connect/token?next=/','/app/connect/token#secret'])assert.equal(parse(url),null);
});

function connect(auto){
  let listener,submissions=0;
  const button={disabled:false},message={textContent:''};
  const form={dataset:{autoConnect:auto},addEventListener(type,callback){if(type==='submit')listener=callback;},querySelector(){return button;},requestSubmit(){let blocked=false;listener({preventDefault(){blocked=true;}});if(!blocked)submissions++;}};
  vm.runInNewContext(source('personal-app-connect.js'),{document:{getElementById(id){return id==='personal-connect-form'?form:message;}}});
  return {form,button,message,get submissions(){return submissions;}};
}

test('anonymous personal QR activates with one POST, not repeated submits',()=>{
  const page=connect('1');
  assert.equal(page.submissions,1);assert.equal(page.button.disabled,true);
  page.form.requestSubmit();assert.equal(page.submissions,1);
});

test('switching an existing account waits for explicit confirmation',()=>{
  const page=connect('0');
  assert.equal(page.submissions,0);assert.equal(page.button.disabled,false);
  page.form.requestSubmit();assert.equal(page.submissions,1);
});

test('a server-verified personal app clears only legacy worker identity, preserving drafts and preferences',()=>{
  const records=new Map([
    ['dasolWorkerDevice','old-worker-token'],['workerJoinLastPerson','123'],
    ['dasolWorkerLang','es'],['workerJoinCompany','company-4'],
    ['purchase-analysis-42','pending-job'],['erp-history','saved-navigation']
  ]);
  vm.runInNewContext(source('personal-app-connect.js'),{
    document:{currentScript:{dataset:{personalDeviceVerified:'1'}},getElementById(){return null;}},
    localStorage:{removeItem(key){records.delete(key);}}
  });
  assert.equal(records.has('dasolWorkerDevice'),false);
  assert.equal(records.has('workerJoinLastPerson'),false);
  assert.deepEqual([...records.keys()],['dasolWorkerLang','workerJoinCompany','purchase-analysis-42','erp-history']);
});

test('QR preview, expired pages and account-switch cancellation never clear local identity',()=>{
  let removed=0;
  const document={currentScript:{dataset:{}},getElementById(){return null;}};
  vm.runInNewContext(source('personal-app-connect.js'),{document,localStorage:{removeItem(){removed++;}}});
  assert.equal(removed,0);
});

test('blocked browser storage does not prevent the connected home from opening',()=>{
  assert.doesNotThrow(()=>vm.runInNewContext(source('personal-app-connect.js'),{
    document:{currentScript:{dataset:{personalDeviceVerified:'1'}},getElementById(){return null;}},
    localStorage:{removeItem(){throw new Error('SecurityError');}}
  }));
});
