// Synthetic size checks; no live ERP/file contents are used.
const {chromium,expect}=require('@playwright/test');
const fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL||undefined});try{
 const page=await browser.newPage({viewport:{width:1500,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{window.prepareCount=0;window.CADReference={prepare:async()=>{window.prepareCount++;return undefined;}};});
 const dxf='0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nLINE\n10\n0\n20\n0\n11\n200\n21\n100\n0\nENDSEC\n0\nEOF\n';
 let drawing={id:9,title:'Synthetic size plan',site_id:1,revision:1,dxf,markups:[],canManage:true,warnings:[]},posts=0,puts=0,mode='fail',confirmations=0;
 let limits={maxUploadBytes:24*1048576,maxDrawingBytes:64*1048576,maxRequestBytes:300};
 page.on('dialog',async dialog=>{confirmations++;await dialog.accept();});
 const html=fs.readFileSync('resources/views/cad/index.blade.php','utf8').replace(/<script[^>]*>[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'<link rel="stylesheet" href="/cad-editor.css">').replace('{{ csrf_token() }}','synthetic').replaceAll('{{ \\App\\Support\\Org::name() }}','SYNTHETIC ERP').replace('</body>','<script src="/cad-core.js"></script><script src="/cad-fidelity.js"></script><script src="/cad-markup.js"></script><script src="/cad-markup-tools.js"></script><script src="/cad-editor.js"></script></body>');
 await page.route('https://synthetic.test/**',async route=>{const u=new URL(route.request().url()),method=route.request().method();let json;
 if(u.pathname==='/cad')return route.fulfill({contentType:'text/html',body:html});
 if(['/cad-core.js','/cad-fidelity.js','/cad-markup.js','/cad-markup-tools.js','/cad-editor.js'].includes(u.pathname))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+u.pathname,'utf8')});
 if(u.pathname==='/cad-editor.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync('public/css/cad-editor.css','utf8')});
 if(u.pathname==='/admin-api/cad/capabilities')json={dwgRead:true,dwgWrite:true,limits,message:'Synthetic converter'};
 else if(u.pathname==='/admin-api/cad'&&method==='POST'){posts++;if(mode==='fail')return route.fulfill({status:422,json:{message:'서버에서 이 파일을 확인하지 못했습니다.'}});json=drawing;}
 else if(u.pathname==='/admin-api/cad')json={canManage:true,sites:[{id:1,name:'Synthetic Site'}],drawings:[drawing]};
 else if(u.pathname==='/admin-api/cad/9'&&method==='PUT'){puts++;const data=route.request().postDataJSON();drawing={...drawing,...data,revision:drawing.revision+1};json=drawing;}
 else if(u.pathname==='/admin-api/cad/9')json=drawing;
 else return route.fulfill({status:404,json:{message:'not found'}});
 return route.fulfill({json});});
 await page.goto('https://synthetic.test/cad?drawing_id=9&site_id=1');await expect(page.locator('#status')).toContainText('Synthetic size plan');await expect(page.locator('#upload-limit')).toContainText('24 MiB');await expect(page.locator('#upload')).toBeEnabled();
 await page.locator('[data-markup-tool="pin"]').click();const box=await page.locator('#canvas').boundingBox();await page.mouse.click(box.x+box.width/2,box.y+box.height/2);await expect(page.locator('#markup-count')).toHaveText('1개 표시');await expect(page.locator('#dirty')).toContainText('저장 안 됨');
 await page.locator('#upload').setInputFiles({name:'too-large.dwg',mimeType:'application/octet-stream',buffer:Buffer.alloc(25*1048576)});await expect(page.locator('#drawing-state-message')).toContainText('too-large.dwg');await expect(page.locator('#drawing-state-message')).toContainText('25 MiB / 파일당 최대 24 MiB');assert.equal(posts,0);assert.equal(confirmations,0);assert.equal(await page.evaluate(()=>window.prepareCount),0);await expect(page.locator('#markup-count')).toHaveText('1개 표시');await expect(page.locator('#dirty')).toContainText('저장 안 됨');await expect(page.locator('#upload')).toHaveValue('');
 await page.locator('#drawing-state-dismiss').click();await page.locator('#save').click();await expect(page.locator('#status')).toContainText('저장·전송할 내용이 너무 큽니다');assert.equal(puts,0);await expect(page.locator('#dirty')).toContainText('저장 안 됨');
 const accepted={name:'over-twenty.dwg',mimeType:'application/octet-stream',buffer:Buffer.alloc(21*1048576)};
 await page.locator('#upload').setInputFiles(accepted);await expect(page.locator('#drawing-state-message')).toContainText('서버에서 이 파일');assert.equal(posts,1);assert.equal(await page.evaluate(()=>window.prepareCount),1);await expect(page.locator('#markup-count')).toHaveText('1개 표시');await expect(page.locator('#dirty')).toContainText('저장 안 됨');await expect(page.locator('#upload')).toHaveValue('');
 mode='success';await page.locator('#upload').setInputFiles(accepted);await expect.poll(()=>posts).toBe(2);await expect(page.locator('#dirty')).not.toContainText('저장 안 됨');await expect(page.locator('#upload')).toHaveValue('');
 limits=undefined;await page.reload();await expect(page.locator('#upload')).toBeEnabled();await expect(page.locator('#upload-limit')).toBeHidden();await page.locator('#upload').setInputFiles({name:'legacy.dwg',mimeType:'application/octet-stream',buffer:Buffer.from('synthetic')});await expect.poll(()=>posts).toBe(3);assert.deepEqual(errors,[]);console.log('CAD upload limits PASS: dynamic effective limits, >20MiB accepted, preparse rejection preserves dirty markups, encoded request blocked, 422 retry, legacy caps');
 }finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
