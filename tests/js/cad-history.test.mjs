import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
import '../../public/js/cad-core.js';
import '../../public/js/cad-fidelity.js';
const C=globalThis.CADCore, require=createRequire(import.meta.url);
const source=require('../Fixtures/cad-history.cjs').drawing;

test('history shares immutable block paths while nested editing state stays independent',()=>{
    const doc=C.parse(source());
    doc.entities.push(C.newEntity('LWPOLYLINE',{p:{x:0,y:0},points:[{x:0,y:0},{x:2,y:0},{x:2,y:3}],closed:true}));
    doc.markups=[{id:'area',points:[{x:1,y:1}],style:{color:'#112233'}}];
    doc.workspace={underlay:{opacity:.5},issues:[{status:'open'}]};
    const before=C.exportDXF(doc),snapshot=C.copyDocument(doc),again=C.copyDocument(snapshot);
    assert.equal(snapshot.entities[0].renderParts,doc.entities[0].renderParts);
    assert.equal(snapshot.entities[0].preview,doc.entities[0].preview);
    assert.equal(snapshot.entities[0].displayGeometry,doc.entities[0].displayGeometry);
    assert.equal(snapshot.entities[0].raw,doc.entities[0].raw);
    assert.equal(snapshot.prefix,doc.prefix);
    assert.equal(again.entities[0].renderParts,doc.entities[0].renderParts);
    assert.throws(()=>{snapshot.entities[0].renderParts[0].displayGeometry[0].x=999;},TypeError);
    doc.entities.at(-1).points[0].x=99;
    doc.entities.at(-1).color=0xff0000;
    doc.layers['0'].locked=true;
    doc.markups[0].points[0].x=999;
    doc.markups[0].style.color='#ffffff';
    doc.workspace.underlay.opacity=1;
    doc.workspace.issues[0].status='closed';
    assert.equal(C.exportDXF(snapshot),before);
    assert.equal(snapshot.layers['0'].locked,false);
    assert.equal(snapshot.markups[0].points[0].x,1);
    assert.equal(snapshot.markups[0].style.color,'#112233');
    assert.equal(snapshot.workspace.underlay.opacity,.5);
    assert.equal(snapshot.workspace.issues[0].status,'open');
    snapshot.markups[0].style.color='#123456';
    assert.equal(again.markups[0].style.color,'#112233');
});

test('layer rename detaches raw assets without changing previous history or export',()=>{
    const doc=C.parse('0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n9\n$CLAYER\n8\nPIPES\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nLINE\n8\nPIPES\n10\n1\n20\n2\n11\n5\n21\n2\n0\nENDSEC\n0\nEOF\n');
    const history=C.copyDocument(doc),original=C.exportDXF(doc),renamed=C.renameLayer(doc,'PIPES','NEW PIPES');
    assert.equal(C.exportDXF(doc),original);
    assert.equal(C.exportDXF(history),original);
    assert.equal(C.parse(C.exportDXF(renamed)).entities[0].layer,'NEW PIPES');
    assert.notEqual(renamed.entities[0].raw,history.entities[0].raw);
    assert.notEqual(renamed.prefix,history.prefix);
});

test('60 large expanded drawing edits fit a bounded child heap and preserve complete history',()=>{
    const output=execFileSync(process.execPath,['--max-old-space-size=256','tests/Fixtures/cad-history.cjs'],{encoding:'utf8',timeout:30000});
    assert.match(output,/60 dense drawing history states retained/);
});
