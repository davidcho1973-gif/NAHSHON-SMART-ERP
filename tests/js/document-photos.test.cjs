const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
function setup() {
  const sizes = [], closed = [];
  const window = {createImageBitmap: async file => {
    if (file.bad) throw Error('decode');
    return {width:file.width,height:file.height,close(){closed.push(file.name)}};
  }};
  const document = {createElement(){const c={getContext(){return {fillRect(){},drawImage(){}}},toBlob(cb){sizes.push([c.width,c.height]);cb(new Blob([new Uint8Array([255,216,1,255,217])]))}};return c}};
  vm.runInNewContext(fs.readFileSync('public/js/document-photos.js','utf8'),{window,document,Blob,File,TextEncoder,Uint8Array});
  return {...window.DocumentPhotos,sizes,closed};
}
test('each PDF page preserves order, dimensions and binary-safe xref offsets', async()=>{
  const s=setup(); const blob=s.pdf([{width:100,height:200,bytes:new Uint8Array([255,216,65,255,217])},{width:300,height:100,bytes:new Uint8Array([255,216,66,255,217])}]);
  const bytes=Buffer.from(await blob.arrayBuffer()), text=bytes.toString('latin1');
  assert.match(text,/\/Count 2 \/Kids \[3 0 R 6 0 R\]/);
  assert.match(text,/\/MediaBox \[0 0 48 96\]/);
  assert.match(text,/\/MediaBox \[0 0 144 48\]/);
  const xref=Number(text.match(/startxref\n(\d+)/)[1]);assert.equal(text.slice(xref,xref+4),'xref');
  const entries=text.slice(xref).split('\n').slice(3,11);
  entries.forEach((line,i)=>{const offset=Number(line.slice(0,10));assert.equal(text.slice(offset,offset+7),`${i+1} 0 obj`)});
  assert.ok(bytes.indexOf(Buffer.from([255,216,65,255,217]))<bytes.indexOf(Buffer.from([255,216,66,255,217])));
});
test('camera photos and long receipts become one readable reduced document',async()=>{
  const s=setup();const result=await s.combine([{name:'invoice.jpg',type:'image/jpeg',width:4000,height:3000},{name:'page2.png',type:'image/png',width:2000,height:7000}]);
  assert.equal(result.type,'application/pdf');assert.equal(result.name,'invoice-pages.pdf');
  assert.deepEqual(s.sizes,[[2560,1920],[1600,5600]]);assert.deepEqual(s.closed,['invoice.jpg','page2.png']);
  assert.match(await result.text(),/\/Count 2/);
});
test('unsupported or mixed input fails explicitly and releases decoded images',async()=>{
  const s=setup();await assert.rejects(s.combine([{type:'application/pdf'}]),/사진만/);
  await assert.rejects(s.combine([{type:'image/heic',name:'photo.heic',bad:true}]),/JPG 또는 PNG/);
  assert.deepEqual(s.closed,[]);
});
