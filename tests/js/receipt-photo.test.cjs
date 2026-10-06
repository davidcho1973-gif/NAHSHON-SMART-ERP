const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
function setup(width, height, bytes = 100000) {
  let closed = false, canvas;
  const window = {createImageBitmap: async () => ({width,height,close(){closed=true;}})};
  const document = {createElement(){canvas={getContext(){return {fillRect(){},drawImage(){}};},toBlob(cb){cb({size:bytes});}};return canvas;}};
  class File {constructor(parts,name,options){this.size=parts[0].size;this.name=name;Object.assign(this,options);}}
  vm.runInNewContext(fs.readFileSync('public/js/receipt-photo.js','utf8'),{window,document,File});
  return {prepare:window.ReceiptPhoto.prepare,getCanvas:()=>canvas,closed:()=>closed};
}
test('reduces a camera photo before upload and releases decoded pixels',async()=>{
  const s=setup(4000,3000); const result=await s.prepare({type:'image/jpeg',size:4000000,name:'receipt.jpg'});
  assert.equal(result.size,100000);assert.equal(s.getCanvas().width,2560);assert.equal(s.closed(),true);
});
test('long receipt keeps a readable width',async()=>{
  const s=setup(2000,7000);await s.prepare({type:'image/jpeg',size:4000000,name:'long.jpg'});
  assert.equal(s.getCanvas().width,1600);assert.equal(s.getCanvas().height,5600);
});
test('PDF, small images and larger recompression keep original',async()=>{
  const s=setup(4000,3000,5000000);
  for(const f of [{type:'application/pdf',size:4000000},{type:'image/jpeg',size:1000},{type:'image/jpeg',size:4000000,name:'r.jpg'}]) assert.equal(await s.prepare(f),f);
});
