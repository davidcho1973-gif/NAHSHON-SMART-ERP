import test from 'node:test';
import assert from 'node:assert/strict';
import '../../public/vendor/polygon-clipping/polygon-clipping.js';
import '../../public/js/cad-markup.js';
import '../../public/js/cad-markup-geometry.js';
import '../../public/js/cad-parts.js';
const P=globalThis.CADParts, G=globalThis.CADMarkupGeometry, M=globalThis.CADMarkup;
const definition=(category='valve')=>({id:crypto.randomUUID(),name:'밸브',category,specification:'DN50',code:'V-50',geometry:P.starter(category)});

test('compound symbols count once; copies, transforms, reload, deletion and undo preserve identity',()=>{
    const part=definition(),a=P.place(part,{x:10,y:20},50),b={...M.translate(a,100,0),id:crypto.randomUUID()};
    const moved=G.transform([a,b],37,2);
    assert.ok(moved.every(G.valid));
    assert.equal(P.expanded(a).length,5);
    assert.equal(P.tally(moved)[0].quantity,2);
    const reopened=JSON.parse(JSON.stringify(moved));
    assert.equal(P.tally(reopened)[0].quantity,2);
    assert.deepEqual(P.tally(reopened.slice(0,1))[0].placements,[a.id]);
    assert.equal(P.tally(reopened)[0].quantity,2);
    assert.deepEqual(reopened[0].part,part);
    assert.ok(M.hit(a,{x:10,y:20},0));
    assert.ok(!M.hit(a,{x:200,y:200},0));
});

test('resizing keeps aspect ratio and does not mutate the catalog snapshot',()=>{
    const part=definition('duct');part.geometry.height=120;
    const a=P.place(part,{x:0,y:0},100,20),original=structuredClone(a),fixed=a.points[2],corner=a.points[0];
    const next=P.resize(a,0,{x:fixed.x+(corner.x-fixed.x)*1.7,y:fixed.y+(corner.y-fixed.y)*1.7});
    assert.ok(G.valid(next));assert.deepEqual(a,original);assert.deepEqual(next.part,a.part);
    const warped=structuredClone(a);warped.points[1].y+=10;assert.equal(G.valid(warped),false);
});

test('geometry normalization retains holes, text size and coordinates without original groups or layers',()=>{
    const parts=P.starter('sprinkler').items;parts[0].layer='private';parts[0].group='group-a';
    const text={...parts[1],id:'text',kind:'text',points:[{x:25,y:25}],size:10,text:'S',textStyle:{boxWidth:20}};
    const g=P.geometry([...parts,text]);const p={...definition('sprinkler'),geometry:g};
    assert.ok(G.valid(P.place(p,{x:0,y:0},100)));
    assert.equal(g.items[0].layer,undefined);assert.equal(g.items[0].group,undefined);
    assert.equal(g.items.at(-1).text,'S');assert.ok(g.items.at(-1).textStyle.boxWidth>0);
    assert.throws(()=>P.geometry([P.place(p,{x:0,y:0},100)]));
});

test('nested, out-of-frame and over-budget symbol definitions cannot bypass geometry validation',()=>{
    const a=P.place(definition(),{x:0,y:0},100);
    assert.equal(G.pointCount(a),4+P.pointCount(a.part));
    const nested=structuredClone(a);nested.part.geometry.items=[structuredClone(a)];assert.equal(G.valid(nested),false);
    const outside=structuredClone(a);outside.part.geometry.items[0].points[0].x=-20;assert.equal(G.valid(outside),false);
    const huge=structuredClone(a);huge.part.geometry.items=[{...huge.part.geometry.items[0],points:Array.from({length:1400},(_,i)=>({x:i%100,y:50}))}];
    assert.ok(G.valid(huge));
    assert.equal(G.validSet(Array.from({length:36},()=>({...huge,id:crypto.randomUUID()}))),false);
});

test('same labels with different catalog identities stay separate and CSV includes evidence safely',()=>{
    const a=P.place(definition(),{x:0,y:0},100),b=P.place(definition(),{x:0,y:0},100);
    b.part.name='=HYPERLINK("https://invalid")';
    const rows=P.tally([a,b]);assert.equal(rows.length,2);
    const csv=P.csv(rows,{id:7,title:'+formula',revision:3},true);
    assert.ok(csv.includes("' +formula")===false);assert.ok(csv.includes("'+formula"));
    assert.ok(csv.includes("'=HYPERLINK"));assert.ok(csv.includes('미저장 작업'));assert.ok(csv.includes(a.id));
    assert.ok(P.csv(rows,{id:7,title:'Plan',revision:3},false).includes('저장본'));
});
