using System.Security.Cryptography;
using System.Text.Json;
using ACadSharp;
using ACadSharp.Entities;
using ACadSharp.IO;
using ACadSharp.Tables;
using CSMath;

static class WorkingCopyTests
{
    public static void Run(string directory, Action<bool, string> check)
    {
        var doc = new CadDocument(ACadVersion.AC1032);
        doc.Entities.Add(new Line(new XYZ(10,20,80),new XYZ(100,200,120)) { Color = new Color(10,20,30) });
        doc.Entities.Add(new TextEntity { InsertPoint = new XYZ(15,25,90), Value = "한글 검증 703K", Height = 12 });
        var contour = new Polyline3D();
        contour.Layer = new Layer("V-TOPO-EXIST");
        contour.Vertices.Add(new Vertex3D(new XYZ(1,2,100)));
        contour.Vertices.Add(new Vertex3D(new XYZ(3,4,110)));
        doc.Entities.Add(contour);
        var curved = new LwPolyline { Layer = new Layer("C-TOPO-DESIGN"), Elevation = 25 };
        curved.Vertices.Add(new LwPolyline.Vertex(6,8) { Bulge = 0.5, StartWidth = 2, EndWidth = 3 });
        curved.Vertices.Add(new LwPolyline.Vertex(10,15));
        doc.Entities.Add(curved);
        var wide = new Polyline2D { StartWidth = 4, EndWidth = 5 };
        wide.Vertices.Add(new Vertex2D(new XYZ(30,40,0)));
        wide.Vertices.Add(new Vertex2D(new XYZ(50,60,0)));
        doc.Entities.Add(wide);
        var hatch = new Hatch { IsSolid = true };
        hatch.Paths.Add(new Hatch.BoundaryPath(new[] {new Hatch.BoundaryPath.Polyline(new[] {new XYZ(0,0,0),new XYZ(10,0,0),new XYZ(10,10,0)}, true)}));
        doc.Entities.Add(hatch);
        // DWG can retain unwrapped angles; DXF readers normalize them. A full
        // circle must remain a full circle, not become a zero-length edge.
        var roundHatch = new Hatch { IsSolid = true };
        roundHatch.Paths.Add(new Hatch.BoundaryPath(new[] {new Hatch.BoundaryPath.Arc {
            Center = new XY(30,40), Radius = 5, StartAngle = 2*Math.PI+0.7, EndAngle = 4*Math.PI+0.7, CounterClockWise = true }}));
        doc.Entities.Add(roundHatch);
        var input = Path.Combine(directory,"working-copy-input.dwg");
        DwgWriter.Write(input,doc);
        var hash = SHA256.HashData(File.ReadAllBytes(input));
        var output = Path.Combine(directory,"working-copy-parts");
        var report = JsonSerializer.SerializeToElement(WorkingCopy2D.Prepare(input,output,64*1024*1024));
        check(report.GetProperty("parts").GetArrayLength()==3,"Plan and two contour groups need separate working copies.");
        var entities = report.GetProperty("parts").EnumerateArray().SelectMany(p => DxfReader.Read(Path.Combine(output,p.GetProperty("file").GetString()!)).Entities).ToArray();
        check(entities.Length==7,"All supported entities must survive.");
        var line = entities.OfType<Line>().Single();
        check(line.StartPoint==new XYZ(10,20,0)&&line.EndPoint==new XYZ(100,200,0),"Top view keeps XY while flattening Z.");
        check(line.Color.Equals(new Color(10,20,30)),"Working copy preserves RGB.");
        check(entities.OfType<TextEntity>().Single().Value=="한글 검증 703K","Unicode text survives.");
        check(entities.OfType<LwPolyline>().Single(p=>p.Layer.Name=="V-TOPO-EXIST").Vertices[1].Location==new XY(3,4),"3D contour must retain every XY vertex.");
        check(entities.OfType<LwPolyline>().Single(p=>p.Layer.Name=="C-TOPO-DESIGN").Vertices[0].Bulge==0.5,"Arc bulges must survive.");
        check(entities.OfType<Polyline2D>().Single().StartWidth==4,"Inherited widths must not disappear in conversion.");
        check(entities.OfType<Hatch>().Count()==2 && entities.OfType<Hatch>().All(h=>h.Paths.Count==1),"Area boundaries must survive.");
        var arc = entities.OfType<Hatch>().SelectMany(h=>h.Paths).SelectMany(p=>p.Edges).OfType<Hatch.BoundaryPath.Arc>().Single();
        check(Math.Abs(Math.Abs(arc.EndAngle-arc.StartAngle)-2*Math.PI)<1e-7,"Unwrapped full-circle boundaries must retain their sweep.");
        check(hash.SequenceEqual(SHA256.HashData(File.ReadAllBytes(input))),"Original DWG is immutable.");
        var rejected = false;
        try { WorkingCopy2D.Prepare(input,output,64*1024*1024); } catch (IOException) {rejected=true;}
        check(rejected,"Existing output must never be overwritten.");
        Console.WriteLine("PASS: isolated 2D plan/contours, XY, RGB, Unicode, bulges, widths, hatch boundaries and immutable DWG.");
    }
}
