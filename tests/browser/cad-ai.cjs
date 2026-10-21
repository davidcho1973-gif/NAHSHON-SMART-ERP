const {chromium,expect}=require('@playwright/test');
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const sandbox={window:{}};vm.runInNewContext(fs.readFileSync('public/js/cad-core.js','utf8'),sandbox);const C=sandbox.window.CADCore;
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.ERP_TEST_BROWSER_CHANNEL||undefined});try{
 const page=await browser.newPage({viewport:{width:1500,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const doc=C.parse('0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n');doc.entities.push(C.newEntity('LINE',{p:{x:20,y:30},q:{x:100,y:30}}));
 const drawing={id:9,title:'AI synthetic',site_id:1,revision:1,markups:[{id:'synthetic-note',kind:'pin',points:[{x:60,y:30}],color:'#38bdf8',opacity:1,width:2,size:3,text:'Private markup',locked:false}],dxf:C.exportDXF(doc),canManage:true,warnings:[]};let snapshot,queue=[],results=[],writes=0,deleted=0,failedAck=true,heartbeats=0,polls=0;
 const html=fs.readFileSync('resources/views/cad/index.blade.php','utf8').replace(/<script[^>]*>[\s\S]*?<\/script>/g,'').replace(/<link[^>]*>/g,'<link rel="stylesheet" href="/cad-editor.css">').replace('{{ csrf_token() }}','synthetic').replace('</body>','<script src="/cad-core.js"></script><script src="/cad-fidelity.js"></script><script src="/cad-markup.js"></script><script src="/cad-markup-tools.js"></script><script src="/cad-editor.js"></script></body>');
 await page.route('https://synthetic.test/**',async route=>{const u=new URL(route.request().url()),method=route.request().method();let json={};
 if(u.pathname==='/cad')return route.fulfill({contentType:'text/html',body:html});
 if(['/cad-core.js','/cad-fidelity.js','/cad-markup.js','/cad-markup-tools.js','/cad-editor.js'].includes(u.pathname))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('public/js'+u.pathname,'utf8')});
 if(u.pathname==='/cad-editor.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync('public/css/cad-editor.css','utf8')});
 if(u.pathname==='/admin-api/cad/capabilities')json={dwgRead:false,dwgWrite:false};
 else if(u.pathname==='/admin-api/cad')json={sites:[{id:1,name:'Synthetic'}],drawings:[drawing],canManage:true};
 else if(u.pathname==='/admin-api/cad/9'){if(method==='PUT'){writes++;drawing.markups=route.request().postDataJSON().markups;drawing.dxf=route.request().postDataJSON().dxf;drawing.revision++;}json=drawing;}
 else if(u.pathname==='/admin-api/cad/ai/config')json={available:true,serverUrl:'https://synthetic.test/mcp/cad',connections:[{id:'c1',name:'Synthetic client'}]};
 else if(u.pathname==='/admin-api/cad/ai/connections/c1'){deleted++;}
 else if(u.pathname==='/admin-api/cad/ai/sessions'&&method==='POST'){snapshot=route.request().postDataJSON();json={sessionId:'s1',editorToken:'synthetic-secret',expiresAt:new Date(Date.now()+900000).toISOString()};}
 else if(u.pathname==='/admin-api/cad/ai/sessions/s1'){if(method==='DELETE')deleted++;else {heartbeats++;const next=route.request().postDataJSON();if(snapshot?.context.documentHash!==next.context.documentHash&&queue.length){queue=[];throw Error('Heartbeat invalidated pending command before ACK');}snapshot={...snapshot,...next};}json={expiresAt:new Date(Date.now()+900000).toISOString()};}
 else if(u.pathname.endsWith('/commands')){polls++;json={commands:queue};}
 else if(u.pathname.endsWith('/result')){const data=route.request().postDataJSON();results.push(data);if(failedAck&&data.status==='applied'){failedAck=false;return route.fulfill({status:503,json:{message:'Synthetic retry'}});}queue=[];}
 else return route.fulfill({status:404,json:{message:'not found'}});
 return route.fulfill({json});});
 await page.goto('https://synthetic.test/cad?drawing_id=9');await expect(page.locator('#status')).toContainText('AI synthetic');await page.locator('#ai-toggle').click();await expect(page.locator('#ai-server-url')).toHaveValue('https://synthetic.test/mcp/cad');
 assert.equal(await page.locator('#ai-auto-apply').isChecked(),false);assert.equal(await page.locator('#ai-unit').inputValue(),'');
 await page.locator('#ai-share-scope').selectOption('visible');await page.locator('#ai-unit').selectOption('mm');await page.locator('#ai-allow-edits').check();await page.locator('#ai-start').click();await expect(page.locator('#ai-status')).toContainText('공유 중');
 assert.equal(snapshot.context.entities.length,1);assert.ok(!JSON.stringify(snapshot.context).includes('Private markup'));assert.equal(snapshot.context.unit,'mm');assert.ok(!JSON.stringify(snapshot.context).includes('raw'));assert.equal(snapshot.context.documentHash.length,64);
 await expect.poll(()=>polls).toBeGreaterThanOrEqual(2);assert.equal(heartbeats,0,'Unchanged snapshots must not PUT at every poll');
 const entity=snapshot.context.entities[0];queue=[{id:'cmd1',status:'pending',documentHash:snapshot.context.documentHash,actions:[{type:'move',entityIds:[entity.id],dx:10,dy:5}]}];
 await expect(page.locator('.ai-command')).toHaveCount(1,{timeout:10000});await page.locator('.ai-command button').filter({hasText:'미리보기'}).click();assert.equal(writes,0);assert.doesNotMatch(await page.locator('#dirty').innerText(),/저장 안 됨/);
 await page.locator('.ai-command button').filter({hasText:'이 요청 적용'}).click();await expect(page.locator('#dirty')).toContainText('저장 안 됨');await expect.poll(()=>results.length).toBeGreaterThan(1);assert.equal(writes,0);
 if(process.env.ERP_CAD_AI_SCREENSHOT)await page.screenshot({path:process.env.ERP_CAD_AI_SCREENSHOT,fullPage:true});
 await page.locator('#ai-stop').click();await expect(page.locator('#ai-status')).toContainText('공유를 중지');await page.locator('#ai-close').click();await page.keyboard.press('Control+z');await page.keyboard.press('Control+y');await page.locator('#save').click();await expect.poll(()=>writes).toBe(1);const moved=C.parse(drawing.dxf).entities[0];assert.equal(moved.p.x,30);assert.equal(moved.p.y,35);assert.equal(moved.q.x,110);assert.equal(drawing.markups.length,1);assert.equal(drawing.markups[0].text,'Private markup');
 await page.locator('#ai-toggle').click();await page.getByRole('button',{name:'이 연결 해제',exact:true}).click();await expect.poll(()=>deleted).toBeGreaterThanOrEqual(2);assert.deepEqual(errors,[]);console.log('CAD AI browser PASS: explicit sharing, preview, one apply despite ACK retry, explicit save, revoke');
 }finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
