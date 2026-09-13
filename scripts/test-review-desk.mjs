import { createRequire } from 'node:module';
import { readFile, mkdir } from 'node:fs/promises';
import assert from 'node:assert/strict';
const require=createRequire(import.meta.url);
const { chromium }=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const html=await readFile('storage/app/review-desk-preview.html','utf8');
const css=await readFile('public/css/document-review-desk.css','utf8');
const docs=[1,2].map(id=>({id,title:id===1?'드라이월 3종 설치 계획':'도면·BOQ 대조 및 조달 계획',fileName:id===1?'703K_A01-01_Drywall_3Types_Color_Plan_EN.pdf':'703K_BOQ_Review.pdf',fileSize:1468006,aiStatus:id===1?'ready':'review_required',categoryLabel:'도면 · 시방서',documentTypeLabel:'도면',project:'703K-KITCHEN',site:'703K',documentNumber:'703K-A01-01',documentDate:'2026-09-11',revision:'00',openActions:id===2?3:0,virtualPath:'NAHSHON / 703K-KITCHEN / Engineering / Architectural Drawings / 2026',extension:'pdf',previewUrl:'https://desk.test/preview/'+id,downloadUrl:'https://desk.test/download/'+id,summary:'벽체별 석고보드 적용 범위와 검토 기준입니다.',keyFacts:['원본 도면과 최신 개정 내용을 함께 검토합니다.'],actions:[],keywords:['벽체','드라이월']}));
const browser=await chromium.launch({channel:'chrome',headless:true});
const page=await browser.newPage({viewport:{width:1180,height:1000}});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
let slow=false;const requests=[];
await page.route('**/*',async route=>{const u=new URL(route.request().url());requests.push(u.pathname+u.search);
 if(u.pathname.endsWith('.css'))return route.fulfill({contentType:'text/css',body:css});
 if(u.pathname.includes('/api/documents')){
  const match=u.pathname.match(/\/documents\/(\d+)$/);
  if(match){if(slow&&match[1]==='1')await new Promise(r=>setTimeout(r,200));return route.fulfill({json:{success:true,document:docs[Number(match[1])-1]}});}
  let rows=docs;if(u.searchParams.get('ai_status'))rows=rows.filter(d=>d.aiStatus===u.searchParams.get('ai_status'));
  if(u.searchParams.get('q'))rows=rows.filter(d=>d.title.includes(u.searchParams.get('q')));
  const p=Number(u.searchParams.get('page')||1);
  return route.fulfill({json:{success:true,documents:p===2?[docs[1]]:rows,pagination:{current_page:p,last_page:2,total:32},stats:{total:122,analyzing:0,review_required:35,open_actions:273,critical_actions:138,unassigned:47}}});
 }
 if(u.pathname.startsWith('/preview/'))return route.fulfill({body:'Preview fixture',contentType:'text/plain'});
 if(u.pathname==='/')return route.fulfill({body:html,contentType:'text/html'});
 return route.fulfill({status:404,body:'Fixture does not allow this request'});
});
await page.goto('https://desk.test/?embed=1');await page.locator('.doc-open').first().waitFor();
assert.equal(await page.locator('#stat-total').textContent(),'122');
assert.equal(await page.locator('#upload-dialog').evaluate(e=>e.open),false);
await page.getByRole('button',{name:'＋ 문서 올리기',exact:true}).click();assert.equal(await page.locator('#upload-dialog').evaluate(e=>e.open),true);
await page.evaluate(()=>toast('파일 확인이 필요합니다.',true));assert.ok(await page.locator('#upload-dialog #toast').isVisible(),'upload errors stay visible in the dialog top layer');
await page.keyboard.press('Escape');assert.equal(await page.locator('#upload-dialog').evaluate(e=>e.open),false);
await page.locator('.doc-open').first().click();await page.waitForFunction(()=>document.getElementById('detail-title').textContent==='드라이월 3종 설치 계획');
const listRect=await page.locator('.desk-list').boundingBox(),detailRect=await page.locator('.desk-review').boundingBox();assert.ok(detailRect.x>listRect.x+listRect.width-1,'detail is next to list');
await page.getByText('원본 미리보기 펼치기',{exact:true}).click();await page.locator('.inline-preview-body iframe').waitFor();
await page.getByRole('button',{name:'크게 보기',exact:true}).click();assert.ok(await page.locator('#viewer-bg').evaluate(e=>e.classList.contains('open')));await page.keyboard.press('Escape');
slow=true;await page.evaluate(()=>{openDocument(1);openDocument(2);});await page.waitForTimeout(400);assert.equal(await page.locator('#detail-title').textContent(),docs[1].title,'out-of-order responses do not overwrite selection');
await page.evaluate(()=>{openDocument(1);clearDocument();});await page.waitForTimeout(300);assert.equal(await page.locator('#detail-title').textContent(),'문서 상세','cleared selection remains cleared');slow=false;
await page.locator('#status-filter').selectOption('review_required');await page.waitForFunction(()=>document.querySelectorAll('.doc-open').length===1);assert.ok(requests.some(r=>r.includes('ai_status=review_required')));
await page.locator('#status-filter').selectOption('');await page.waitForFunction(()=>document.querySelectorAll('.doc-open').length===2);
await page.locator('#page-next').click();await page.waitForFunction(()=>document.getElementById('document-count').textContent.includes('2 / 2'));assert.ok(await page.locator('#page-next').isDisabled());
await page.locator('#page-prev').click();await page.waitForFunction(()=>document.querySelectorAll('.doc-open').length===2);
await page.locator('.doc-open').first().click();await page.waitForFunction(()=>document.getElementById('detail-title').textContent==='드라이월 3종 설치 계획');
await mkdir('storage/app/review-desk-checks',{recursive:true});
for(const width of [1180,980,780,390]){await page.setViewportSize({width,height:1000});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no page overflow at '+width);await page.screenshot({path:'storage/app/review-desk-checks/desk-'+width+'.png',fullPage:true});}
assert.equal(errors.length,0,errors.join('\n'));
await browser.close();console.log('PASS: list/detail, upload, inline/full preview, request races, status filter, pagination, responsive widths, no JS errors');
