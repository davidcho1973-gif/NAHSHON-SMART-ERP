// Synthetic dense block expansion. Never includes a customer drawing.
exports.drawing = () => {
    const pairs = [[0,'SECTION'],[2,'HEADER'],[9,'$ACADVER'],[1,'AC1032'],[0,'ENDSEC'],[0,'SECTION'],[2,'BLOCKS'],[0,'BLOCK'],[2,'DENSE'],[10,0],[20,0]];
    for (let i=0;i<160;i++) pairs.push([0,'CIRCLE'],[10,i%20],[20,Math.floor(i/20)],[40,.3]);
    pairs.push([0,'ENDBLK'],[0,'ENDSEC'],[0,'SECTION'],[2,'ENTITIES']);
    for (let i=0;i<20;i++) pairs.push([0,'INSERT'],[5,(100+i).toString(16)],[2,'DENSE'],[10,(i%5)*25],[20,Math.floor(i/5)*15]);
    pairs.push([0,'ENDSEC'],[0,'EOF']);
    return pairs.map(([code,value])=>code+'\n'+value+'\n').join('');
};

if (require.main === module) {
    const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
    const context={window:{}}; vm.createContext(context);
    for (const file of ['cad-core','cad-fidelity']) vm.runInContext(fs.readFileSync('public/js/'+file+'.js','utf8'),context);
    const C=context.window.CADCore,doc=C.parse(exports.drawing()),history=[];
    const original=C.exportDXF(doc);doc.markups=[];doc.workspace={};
    for(let i=0;i<60;i++) {
        history.push(C.copyDocument(doc));
        doc.entities.push(C.newEntity('MTEXT',{p:{x:i,y:10},text:'반복 '+i,height:2,width:20}));
        doc.markups.push({id:'note-'+i,type:'text',text:'표시 '+i,p:{x:i,y:0}});
    }
    assert.equal(history[0].entities.length,20);
    assert.equal(history[59].entities.length,79);
    assert.equal(doc.entities.length,80);
    assert.equal(C.exportDXF(history[0]),original);
    assert.equal(C.parse(C.exportDXF(doc)).entities.length,80);
    for(const entry of history) {
        assert.equal(entry.prefix,doc.prefix);
        assert.equal(entry.entities[0].renderParts,doc.entities[0].renderParts);
        assert.notEqual(entry.markups,doc.markups);
    }
    console.log('60 dense drawing history states retained within 256 MiB heap');
}
