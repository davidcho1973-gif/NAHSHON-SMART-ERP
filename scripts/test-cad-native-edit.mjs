import {readFileSync,writeFileSync,mkdirSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
import vm from 'node:vm';
import path from 'node:path';
import assert from 'node:assert/strict';

// Exercise independent native DXF fixtures through the actual DWG engine.
const [dotnet,converter,directory]=process.argv.slice(2);
if(!dotnet||!converter||!directory)throw Error('Usage: node scripts/test-cad-native-edit.mjs <dotnet> <converter.dll> <scratch-directory>');
mkdirSync(directory,{recursive:true});
const require=createRequire(import.meta.url),fixture=require('../tests/Fixtures/cad-native-edit.cjs');
const context=vm.createContext({});
for(const file of ['cad-core','cad-fidelity'])vm.runInContext(readFileSync(`public/js/${file}.js`,'utf8'),context);
const C=context.CADCore,at=name=>path.resolve(directory,name);
const number=(e,code,fallback=0)=>Number(e.raw.find(p=>p[0]===code)?.[1]??fallback);
const value=(e,code)=>e.raw.find(p=>p[0]===code)?.[1];
const near=(actual,expected,label)=>assert.ok(Math.abs(actual-expected)<1e-7,`${label}: ${actual} != ${expected}`);
function convert(input,output,count){
  const metadata=JSON.parse(execFileSync(dotnet,[converter,'convert','--input',at(input),'--output',at(output)],{encoding:'utf8'}));
  assert.equal(metadata.entities,count,`${output} preserves model entity count`);
  return output;
}
function roundtrip(name,doc){
  writeFileSync(at(name+'.dxf'),C.exportDXF(doc));
  const count=doc.entities.filter(e=>!e.paper).length;
  convert(name+'.dxf',name+'.dwg',count);convert(name+'.dwg',name+'-verified.dxf',count);
  return C.parse(readFileSync(at(name+'-verified.dxf'),'utf8'));
}
writeFileSync(at('source.dxf'),fixture.source);
convert('source.dxf','source.dwg',fixture.entityCount);
convert('source.dwg','source-reopened.dxf',fixture.entityCount);
const source=C.parse(readFileSync(at('source-reopened.dxf'),'utf8'));
const entities=source.entities.filter(e=>!e.paper);
const patterned=entities.find(e=>e.type==='HATCH'&&value(e,2)==='ANSI31');
const circular=entities.find(e=>e.type==='HATCH'&&value(e,2)==='SOLID');
assert.equal(number(patterned,91),2,'Native patterned hatch retains its island');
const patternedBounds=C.bounds([patterned]),circleBounds=C.bounds([circular]);
const patternEdit=C.editHatch(C.transform(patterned,{dx:5,dy:-4}),{color:0xf08020,opacity:.25,patternScale:2,patternAngle:30});
const circleEdit=C.editHatch(C.transform(circular,{dx:-3,dy:8}),{color:0xc02060,opacity:.65});
source.entities=source.entities.map(e=>e.id===patterned.id?patternEdit:e.id===circular.id?circleEdit:e);
const hatches=roundtrip('hatches-edited',source);
const finalPattern=hatches.entities.find(e=>e.type==='HATCH'&&value(e,2)==='ANSI31');
const finalCircle=hatches.entities.find(e=>e.type==='HATCH'&&value(e,2)==='SOLID');
assert.equal(number(finalPattern,91),2,'Editing preserves exterior and island boundaries');
assert.equal(number(finalPattern,78),1,'Editing preserves native pattern definition lines');
assert.equal(number(finalPattern,75),0,'Island fill rule remains normal');
assert.equal(number(finalPattern,30),7,'Planar hatch elevation survives');
assert.equal(number(finalPattern,420),0xf08020);assert.equal(number(finalCircle,420),0xc02060);
near(number(finalPattern,41),2,'Pattern scale');near(number(finalPattern,52),30,'Pattern angle');
// The native engine stores integer transparency percentages (not floating alpha).
assert.ok(Math.abs(finalPattern.opacity-.25)<=.011,'Hatch opacity persists within one native percentage step');
assert.ok(Math.abs(finalCircle.opacity-.65)<=.011,'Solid opacity persists');
for(const [after,before,dx,dy] of [[finalPattern,patternedBounds,5,-4],[finalCircle,circleBounds,-3,8]]){
  const bounds=C.bounds([after]);near(bounds.minX,before.minX+dx,'Hatch left');near(bounds.maxX,before.maxX+dx,'Hatch right');near(bounds.minY,before.minY+dy,'Hatch bottom');near(bounds.maxY,before.maxY+dy,'Hatch top');
}
console.log('PASS: native patterned/solid hatches preserve holes, circles, elevation, color, pattern and transparency through DWG editing.');

const beforeDimensions=hatches.entities.filter(e=>e.type==='DIMENSION'&&!e.paper);
assert.equal(beforeDimensions.length,2);
assert.deepEqual(Array.from(beforeDimensions,e=>number(e,70)&7),[0,1],'Fixture has distinct native linear and aligned dimensions');
for(const dimension of beforeDimensions){
  assert.ok(C.allows(dimension,'move'),'Native DSTYLE metadata does not block editing');
  const moved=C.transform(dimension,{dx:7,dy:-3});
  const positioned=C.resize(C.resize(moved,'dimension-line',{x:60,y:dimension.p.y+12}),'dimension-text',{x:38,y:dimension.p.y+17});
  const edited={...positioned,text:'수정 <> mm',height:4,color:0xee8800,dirty:true};
  hatches.entities=hatches.entities.map(e=>e.id===dimension.id?edited:e);
}
const dimensionResult=roundtrip('native-dimensions-edited',hatches);
const afterDimensions=dimensionResult.entities.filter(e=>e.type==='DIMENSION'&&!e.paper);
assert.equal(afterDimensions.length,2,'Dimensions remain native DIMENSION objects');
for(let i=0;i<afterDimensions.length;i++){
  const after=afterDimensions[i],before=beforeDimensions[i];
  assert.equal(number(after,70)&7,number(before,70)&7,'Editing preserves the native dimension type');
  near(number(after,13),number(before,13)+7,'First measurement point X');near(number(after,23),number(before,23)-3,'First measurement point Y');
  near(number(after,14),number(before,14)+7,'Second measurement point X');near(number(after,24),number(before,24)-3,'Second measurement point Y');
  near(number(after,20),before.p.y+12,'Dimension line placement');near(number(after,11),38,'Label X');near(number(after,21),before.p.y+17,'Label Y');
  near(C.nativeDimensionLayout(after).value,40,'Measured length is preserved');
  assert.equal(after.text,'수정 <> mm');near(after.height,4,'Dimension text height');assert.equal(number(after,420),0xee8800);
  assert.notEqual(value(after,2),value(before,2),'Edited dimension receives its own graphics block');
  assert.ok(after.renderParts?.some(p=>p.type==='TEXT'||p.type==='MTEXT'),'Native dimension has saved display text');
  assert.ok(C.nativeDimensionLayout(after).label.includes('40'),'Actual measurement is included in override');
  if((number(after,70)&7)===0)assert.ok(C.nativeDimensionLayout(after).label.includes('1016'),'Alternate millimetre value survives editing the inch dimension');
  else{
    const graphics=C.nativeDimensionParts(after);
    assert.equal(graphics.filter(p=>p.type==='SOLID').length,0,'Custom architectural ticks are not replaced with filled arrowheads');
    assert.equal(graphics.filter(p=>p.type==='LINE'&&Math.abs(p.p.x-p.q.x)>.01&&Math.abs(p.p.y-p.q.y)>.01).length,2,'Both custom tick arrows survive native regeneration');
  }
}
const secondDimension=roundtrip('native-dimensions-resaved',dimensionResult);
assert.equal(secondDimension.entities.filter(e=>e.type==='DIMENSION'&&!e.paper).length,2,'Second save retains native dimensions');
console.log('PASS: imported linear/aligned dimensions retain measurement points, DSTYLE, editable text, text position and fresh native display blocks through repeated DWG saves.');

const legacy=C.copyDocument(secondDimension),longLabel='긴한글검토'.repeat(90)+' <>',legacyTarget=legacy.entities.find(e=>e.type==='DIMENSION');
legacy.acadVersion=1015;legacy.prefix=C.clone(legacy.prefix);
const version=legacy.prefix.findIndex(p=>p[0]===9&&p[1]==='$ACADVER');legacy.prefix[version+1][1]='AC1015';
legacy.entities=legacy.entities.map(e=>e.id===legacyTarget.id?{...e,text:longLabel,dirty:true}:e);
const legacyResult=roundtrip('native-dimensions-legacy',legacy);
assert.equal(legacyResult.entities.find(e=>e.type==='DIMENSION').text,longLabel,'Long Unicode dimension override survives legacy-to-DWG serialization');
assert.ok(C.nativeDimensionLayout(legacyResult.entities.find(e=>e.type==='DIMENSION')).label.startsWith('긴한글검토'),'Generated native display text retains Unicode');
console.log('PASS: long Unicode native dimension labels survive legacy DXF and real DWG serialization.');

const dual=C.parse(fixture.source.replace('1070\n170\n1070\n1\n','1070\n3\n1000\n<>\\X\n1070\n170\n1070\n1\n'));
const dualTarget=dual.entities.find(e=>e.type==='DIMENSION');
assert.ok(C.allows(dualTarget,'text'),'Ordinary native dimension line breaks remain editable');
dual.entities=dual.entities.map(e=>e.id===dualTarget.id?{...e,text:'검토 <>\\P확인',height:3,dirty:true}:e);
const dualResult=roundtrip('native-dimension-linebreak',dual),dualAfter=dualResult.entities.find(e=>e.type==='DIMENSION');
assert.equal(dualAfter.text,'검토 <>\\P확인','Native override line-break instruction is preserved');
assert.equal(dualAfter.nativeDimension.values[3],'<>\\X','Original alternate-line style instruction is preserved');
assert.ok(C.nativeDimensionLayout(dualAfter).label.includes('\n[1016]\n확인'),'Alternate units and intended lines render after real DWG reopen');
console.log('PASS: native alternate-unit and override line breaks retain editable source instructions and multiline display.');

const block=hatches.entities.find(e=>e.type==='INSERT');
const originalDefinition=hatches.prefix.filter(p=>p[0]===2&&p[1]==='ASSEMBLY').length;
const siblings=C.array([block],1,2,60,0);
const parts=C.explodeBlock(block,hatches);
assert.equal(parts.length,3,'Nested block produces all three independently editable children');
assert.equal(parts.filter(e=>e.type==='LINE').length,2);
const note=parts.find(e=>e.type==='TEXT');assert.ok(note&&C.allows(note,'text'),'Exploded text is independently editable');
for(const entity of parts){assert.equal(entity.layer,'PARTS','Layer 0 inherits insert layer');assert.equal(entity.color,0x4488cc,'Nested BYBLOCK color resolves to the insert');}
near(note.p.x,94,'Nested transformed text X');near(note.p.y,34,'Nested transformed text Y');near(note.height,4,'Nested transformed text size');
const editedParts=parts.map(e=>e.id===note.id?{...C.transform(e,{dx:2,dy:3}),text:'분해 후 개별 수정',dirty:true}:e);
hatches.entities=hatches.entities.filter(e=>e.id!==block.id).concat(siblings,editedParts);
const rectangle=hatches.entities.find(e=>e.type==='LWPOLYLINE');
const filled=C.hatchForPolyline(rectangle,{color:0x20aaee,opacity:.35});
hatches.entities.push(filled);
assert.equal(hatches.prefix.filter(p=>p[0]===2&&p[1]==='ASSEMBLY').length,originalDefinition,'Explode does not change the shared block definition');
const exploded=roundtrip('exploded-filled',hatches);
const inserted=exploded.entities.filter(e=>e.type==='INSERT'&&!e.paper);assert.equal(inserted.length,1,'Other block instance survives');near(inserted[0].p.x,block.p.x+60,'Other insert position');
const editedNote=exploded.entities.find(e=>e.type==='TEXT'&&e.text==='분해 후 개별 수정');assert.ok(editedNote);near(editedNote.p.x,96,'Independently moved text X');near(editedNote.p.y,37,'Independently moved text Y');near(number(editedNote,30),7,'Exploded block elevation');
const boxFill=exploded.entities.find(e=>e.type==='HATCH'&&number(e,420)===0x20aaee);assert.ok(boxFill,'Rectangle creates a real solid HATCH');assert.equal(number(boxFill,70),1);assert.ok(Math.abs(boxFill.opacity-.35)<=.011);assert.ok(exploded.entities.some(e=>e.type==='LWPOLYLINE'),'Rectangle outline remains editable');
const handles=exploded.entities.filter(e=>!e.paper).map(e=>value(e,5));assert.equal(new Set(handles).size,handles.length,'Exploded/fill entities have unique native handles');
assert.equal(readFileSync(at('source.dxf'),'utf8'),fixture.source,'Original source is immutable');
console.log('PASS: nested transformed block explodes to independent line/text objects; sibling definition, elevation, inherited style, filled box and alpha survive DWG.');
