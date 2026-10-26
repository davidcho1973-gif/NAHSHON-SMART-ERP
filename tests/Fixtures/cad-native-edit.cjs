// Synthetic native entities, independent of customer drawings and browser-generated annotations.
const pairs = [[0,'SECTION'],[2,'HEADER'],[9,'$ACADVER'],[1,'AC1032'],[9,'$INSUNITS'],[70,4],[0,'ENDSEC'],
  [0,'SECTION'],[2,'TABLES'],[0,'TABLE'],[2,'LAYER'],[70,2],
  [0,'LAYER'],[2,'0'],[70,0],[62,7],[6,'CONTINUOUS'],
  [0,'LAYER'],[2,'PARTS'],[70,0],[62,3],[6,'CONTINUOUS'],[0,'ENDTAB'],
  [0,'TABLE'],[2,'APPID'],[70,1],[0,'APPID'],[5,'90'],[100,'AcDbSymbolTableRecord'],[100,'AcDbRegAppTableRecord'],[2,'ACAD'],[70,0],[0,'ENDTAB'],
  [0,'TABLE'],[2,'BLOCK_RECORD'],[5,'92'],[100,'AcDbSymbolTable'],[70,1],
  [0,'BLOCK_RECORD'],[5,'91'],[330,'92'],[100,'AcDbSymbolTableRecord'],[100,'AcDbBlockTableRecord'],[2,'_ARCHTICK'],[70,0],[280,1],[281,0],[0,'ENDTAB'],[0,'ENDSEC'],
  [0,'SECTION'],[2,'BLOCKS']];
const common=(type,handle,layer='0')=>[[0,type],[5,handle],[100,'AcDbEntity'],[8,layer]];
const point=(code,x,y,z=0)=>[[code,x],[code+10,y],[code+20,z]];
const beginBlock=(name,handle,flags=0)=>[...common('BLOCK',handle),[100,'AcDbBlockBegin'],[2,name],[70,flags],...point(10,0,0),[3,name],[1,'']];
const endBlock=handle=>[...common('ENDBLK',handle),[100,'AcDbBlockEnd']];
const line=(handle,a,b)=>[...common('LINE',handle),[62,0],[100,'AcDbLine'],...point(10,...a),...point(11,...b)];
pairs.push(...beginBlock('_ARCHTICK','901'),...line('902',[-.5,-.5],[.5,.5]),...endBlock('903'));
pairs.push(...beginBlock('CHILD','A0'),...line('A1',[0,0],[10,0]),
  ...common('TEXT','A2'),[62,0],[100,'AcDbText'],...point(10,2,3),[40,2],[1,'PART NOTE'],[50,0],[7,'Standard'],[72,0],[100,'AcDbText'],[73,0],...endBlock('A3'));
pairs.push(...beginBlock('ASSEMBLY','B0'),...common('INSERT','B1'),[62,0],[100,'AcDbBlockReference'],[2,'CHILD'],...point(10,5,0),[41,1],[42,1],[43,1],[50,0],
  ...line('B2',[0,0],[0,12]),...endBlock('B3'));
for(const [name,h,y] of [['*D1','C',55],['*D2','D',85]]){
  pairs.push(...beginBlock(name,h+'0',1),...line(h+'1',[10,y],[50,y]),
    ...common('TEXT',h+'2'),[100,'AcDbText'],...point(10,30,y+2),[40,2.5],[1,'40.00'],[7,'Standard'],[100,'AcDbText'],[73,0],...endBlock(h+'3'));
}
pairs.push([0,'ENDSEC'],[0,'SECTION'],[2,'ENTITIES']);
const boundary=(points,flags)=>[[92,flags],[72,0],[73,1],[93,points.length],...points.flatMap(([x,y])=>[[10,x],[20,y]]),[97,0]];
pairs.push(...common('HATCH','E1'),[420,0x33aa88],[440,0x02000000+128],[100,'AcDbHatch'],...point(10,0,0,7),...point(210,0,0,1),[2,'ANSI31'],[70,0],[71,0],[91,2],
  ...boundary([[0,0],[40,0],[40,30],[0,30]],3),...boundary([[10,10],[20,10],[20,20],[10,20]],2),
  [75,0],[76,1],[52,0],[41,1],[77,0],[78,1],[53,45],[43,0],[44,0],[45,-2],[46,2],[79,0],[47,0.1],[98,1],[10,1],[20,1]);
pairs.push(...common('HATCH','E2'),[420,0x6688aa],[100,'AcDbHatch'],...point(10,0,0),...point(210,0,0,1),[2,'SOLID'],[70,1],[71,0],[91,1],
  [92,1],[93,1],[72,2],[10,65],[20,15],[40,10],[50,0],[51,360],[73,1],[97,0],[75,0],[76,1],[98,0]);
for(const [handle,type,block,y] of [['F1',0,'*D1',40],['F2',1,'*D2',70]]){
  pairs.push(...common('DIMENSION',handle),[100,'AcDbDimension'],[280,0],[2,block],...point(10,50,y+15),...point(11,30,y+17),[70,32+type],[71,5],[72,1],[41,1],[42,40],[1,'<>'],[3,'Standard'],...point(210,0,0,1),
    [100,'AcDbAlignedDimension'],...point(13,10,y),...point(14,50,y),[52,0]);
  if(type===0)pairs.push([50,0],[100,'AcDbRotatedDimension']);
  pairs.push([1001,'ACAD'],[1000,'DSTYLE'],[1002,'{'],[1070,140],[1040,2.5],[1070,271],[1070,2]);
  if(type===0)pairs.push([1070,170],[1070,1],[1070,143],[1040,25.4],[1070,273],[1070,2],[1070,171],[1070,0]);
  else pairs.push([1070,342],[1005,'91']);
  pairs.push([1002,'}']);
}
pairs.push(...common('INSERT','F3','PARTS'),[420,0x4488cc],[100,'AcDbBlockReference'],[2,'ASSEMBLY'],...point(10,100,20,7),[41,2],[42,2],[43,1],[50,90]);
pairs.push(...common('LWPOLYLINE','F4'),[100,'AcDbPolyline'],[90,4],[70,1],[10,100],[20,60],[10,140],[20,60],[10,140],[20,90],[10,100],[20,90]);
pairs.push([0,'ENDSEC'],[0,'EOF']);
module.exports={pairs,source:pairs.flatMap(pair=>pair.map(String)).join('\n')+'\n',entityCount:6};
