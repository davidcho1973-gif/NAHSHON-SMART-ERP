import {createRequire} from 'node:module';
import {readFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
import vm from 'node:vm';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const shell=await readFile('storage/app/navigation-shell-fixture.html','utf8');
const documents=await readFile('storage/app/navigation-documents-fixture.html','utf8');
const worker=await readFile('storage/app/navigation-worker-fixture.html','utf8');
const ops=await readFile('storage/app/navigation-ops-fixture.html','utf8');
for(const html of [shell,documents,worker,ops])for(const match of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/g)){
 if(!/src=|application\/ld\+json|application\/json/.test(match[1]))new vm.Script(match[2]);
}
const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 const page=await browser.newPage({viewport:{width:1200,height:850}});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const docs=[1,2].map(id=>({id,title:'Navigation document '+id,fileName:'Example_'+id+'.pdf',fileSize:100,aiStatus:'ready',site:'703K',project:'703K',extension:'pdf',previewUrl:'https://erp.test/preview/'+id,downloadUrl:'https://erp.test/download/'+id,actions:[],keyFacts:[],keywords:[]}));
 await page.route('**/*',async route=>{
  const u=new URL(route.request().url());
  if(/^\/(js|css)\//.test(u.pathname)){
   try{return await route.fulfill({body:await readFile('public'+u.pathname),contentType:u.pathname.endsWith('.js')?'application/javascript':'text/css'});}catch{return route.fulfill({body:''});}
  }
  if(u.pathname==='/')return route.fulfill({body:shell,contentType:'text/html'});
  if(u.pathname==='/document-hub')return route.fulfill({body:documents,contentType:'text/html'});
  if(u.pathname==='/attendance-app')return route.fulfill({body:worker,contentType:'text/html'});
  if(u.pathname==='/attendance-app/ops-room')return route.fulfill({body:ops,contentType:'text/html'});
  if(u.pathname.includes('/api/documents')){
   const id=u.pathname.match(/\/documents\/(\d+)$/);
   return route.fulfill({json:id?{success:true,document:docs[Number(id[1])-1]}:{success:true,documents:docs,pagination:{last_page:3,total:42},stats:{total:42}}});
  }
  if(u.pathname.startsWith('/api/smart-company') || u.pathname.startsWith('/smart-company-api')){
   if(u.pathname.endsWith('api_getOpsBatch'))return route.fulfill({json:{success:true,id:1,raw:'Navigation example',items:[]}});
   return route.fulfill({json:[]});
  }
  if(u.pathname.startsWith('/api/attendance-app'))return route.fulfill({json:{success:true,employee:{name:'Navigation tester',number:'TEST'},site:{code:'703K'},events:[]}});
  if(u.pathname.startsWith('/preview/'))return route.fulfill({body:'Example preview',contentType:'text/plain'});
  return route.fulfill({body:'',contentType:'text/plain'});
 });
 await page.goto('https://erp.test/?view=wbs&site=703K');
 await page.waitForFunction(()=>window._currentView==='wbs');
 await page.evaluate(()=>{window.SITE_DB_IDS['703K']=2;window.goToView('document-hub');});
 const frame=page.frameLocator('#page-container > iframe');
 await frame.locator('.doc-open').first().waitFor();
 await frame.locator('.doc-open').first().click();
 await page.waitForURL(/document=1/);
 await frame.locator('#detail-title').filter({hasText:'Navigation document 1'}).waitFor();
 await frame.locator('body').evaluate(()=>openViewer());
 await frame.locator('#viewer-bg.open').waitFor();
 await page.goBack();await frame.locator('#viewer-bg:not(.open)').waitFor({state:'attached'});
 await page.goForward();await frame.locator('#viewer-bg.open').waitFor();
 await page.goBack();await frame.locator('#viewer-bg:not(.open)').waitFor({state:'attached'});
 await frame.locator('.doc-open').nth(1).click();
 await page.waitForURL(/document=2/);
 await page.goBack();
 await frame.locator('#detail-title').filter({hasText:'Navigation document 1'}).waitFor();
 assert.equal(new URL(page.url()).searchParams.get('document'),'1');
 await page.goBack();
 await frame.locator('#drawer-body .desk-empty').waitFor();
 await page.goBack();
 await page.waitForFunction(()=>window._currentView==='wbs');
 assert.equal(new URL(page.url()).searchParams.get('view'),'wbs');
 await page.goForward();
 await frame.locator('.doc-open').first().waitFor();
 await frame.locator('#search').fill('Example');await frame.locator('#search-btn').click();
 await frame.locator('#page-next').click();
 await page.evaluate(()=>window.goToView('profile'));
 await page.goBack();
 await frame.locator('.doc-open').first().waitFor();
 await page.waitForFunction(()=>document.querySelector('#page-container iframe').contentWindow.document.getElementById('search').value==='Example');
 assert.match(await frame.locator('#document-count').textContent(),/2 \/ 3/);
 await page.reload();
 await frame.locator('.doc-open').first().waitFor();
 await page.waitForFunction(()=>document.querySelector('#page-container iframe').contentWindow.document.getElementById('search').value==='Example');
 await page.goto('https://erp.test/attendance-app?tab=home');
 await page.locator('#tabs [data-tab="work"]').click();
 await page.locator('#tabs [data-tab="pay"]').click();
 assert.equal(new URL(page.url()).searchParams.get('tab'),'pay');
 await page.goBack();await page.locator('#tabs [data-tab="work"][aria-selected="true"]').waitFor();
 await page.goForward();await page.locator('#tabs [data-tab="pay"][aria-selected="true"]').waitFor();
 await page.goto('https://erp.test/attendance-app/ops-room');
 await page.evaluate(()=>openDetail(1));
 await page.locator('#back-btn').waitFor();
 await page.locator('#back-btn').click();
 await page.waitForFunction(()=>document.getElementById('detail-screen').hidden);
 await page.goForward();await page.locator('#back-btn').waitFor();
 assert.equal(errors.length,0,errors.join('\n'));
 console.log('PASS: rendered script syntax, WBS/document back-forward, detail selection, query/page restoration, reload, worker tabs, ops detail back/forward; no page errors.');
}finally{await browser.close();}
