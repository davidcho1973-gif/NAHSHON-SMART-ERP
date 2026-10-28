using System.Diagnostics;
using System.Text;
using System.Text.Json;
using ACadSharp;
using ACadSharp.Entities;
using ACadSharp.IO;
using ACadSharp.Objects;
using CSMath;

Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
var directory = Path.Combine(Path.GetTempPath(), "cad-compatibility-" + Guid.NewGuid());
Directory.CreateDirectory(directory);
void Assert(bool value, string message) { if (!value) throw new Exception(message); }
void Strict(object sender, NotificationEventArgs e)
{
    if (e.NotificationType is NotificationType.Error or NotificationType.NotSupported or NotificationType.NotImplemented)
        throw new InvalidDataException(e.Message);
}
string Fixture(string name, bool conflicting)
{
    var doc = new CadDocument();
    doc.Header.Version = ACadVersion.AC1032;
    doc.Entities.Add(new Line(new XYZ(10, 20, 0), new XYZ(110, 70, 0)) {Color=new Color(206,0,88)});
    var colors = doc.RootDictionary.GetEntry<CadDictionary>("ACAD_COLOR");
    colors.Add(new AliasedColor("alias-a", new Color(206,0,88)));
    colors.Add(new AliasedColor("alias-b", conflicting ? new Color(0,200,40) : new Color(206,0,88)));
    var style=new VisualStyle {Name="Synthetic 3D style"};
    var presentation=new XRecord("Presentation");
    presentation.CreateEntry(1,"3D-only settings");
    style.CreateExtendedDictionary().Add(presentation);
    doc.RootDictionary.Add(style);
    var required=new XRecord("RequiredDrawingData");
    required.CreateEntry(1,"Preserve this drawing information");
    doc.RootDictionary.Add(required);
    var path=Path.Combine(directory,name+".dwg");
    DwgWriter.Write(path,doc,notification:Strict);
    return path;
}
try
{
    WorkingCopyTests.Run(directory, Assert);
    var safe=Fixture("identical",false);
    var warnings=new List<string>();
    var doc=DwgColorCompatibility.Read(safe,(sender,e)=>{Strict(sender,e);warnings.Add(e.Message);});
    Assert(doc.Entities.Count==1,"Duplicate named colors must not remove geometry.");
    Assert(doc.Colors.Count()==1,"Identical duplicate definitions must coalesce.");
    Assert(warnings.Any(x=>x.Contains("같은 이름과 RGB")),"Normalization must be disclosed.");
    var conflict=Fixture("conflicting",true);
    bool refused=false;
    try {DwgColorCompatibility.Read(conflict,Strict);} catch(InvalidDataException) {refused=true;}
    Assert(refused,"Different RGB values under the same name must fail closed.");
    var output=Path.Combine(directory,"working-copy.dxf");
    var start=new ProcessStartInfo(args[0]) {RedirectStandardOutput=true,RedirectStandardError=true};
    if(args.Length>1)start.ArgumentList.Add(args[1]);
    foreach(var arg in new[]{"convert","--input",safe,"--output",output})start.ArgumentList.Add(arg);
    using var process=Process.Start(start)!;
    var stdout=process.StandardOutput.ReadToEnd();var stderr=process.StandardError.ReadToEnd();process.WaitForExit();
    Assert(process.ExitCode==0,"Working-copy conversion failed: "+stderr);
    var meta=JsonDocument.Parse(stdout).RootElement;
    var reopened=DxfReader.Read(output,Strict);
    var line=reopened.Entities.OfType<Line>().Single();
    Assert(line.StartPoint==new XYZ(10,20,0)&&line.EndPoint==new XYZ(110,70,0),"Coordinates must survive.");
    Assert(line.Color.Equals(new Color(206,0,88)),"RGB must survive.");
    Assert(reopened.RootDictionary.GetEntry<XRecord>("RequiredDrawingData").Entries.Single(entry=>entry.Code==1).Value.ToString()=="Preserve this drawing information","Unrelated XRecords must survive.");
    // ACadSharp's writer omits VisualStyle and its DXF reader ignores this
    // object. Use the upstream MIT-licensed empty DWG, not a user drawing.
    var presentationInput=Path.Combine(AppContext.BaseDirectory,"cad-presentation.dwg");
    var presentationSource=DwgColorCompatibility.Read(presentationInput,Strict);
    Assert(presentationSource.GetInstanceCount("VISUALSTYLE")>0,"Fixture must actually contain presentation resources.");
    var presentationOutput=Path.Combine(directory,"presentation.dxf");
    var presentationStart=new ProcessStartInfo(args[0]) {RedirectStandardOutput=true,RedirectStandardError=true};
    if(args.Length>1)presentationStart.ArgumentList.Add(args[1]);
    foreach(var arg in new[]{"convert","--input",presentationInput,"--output",presentationOutput})presentationStart.ArgumentList.Add(arg);
    using var presentationProcess=Process.Start(presentationStart)!;
    var presentationStdout=presentationProcess.StandardOutput.ReadToEnd();
    var presentationStderr=presentationProcess.StandardError.ReadToEnd();presentationProcess.WaitForExit();
    Assert(presentationProcess.ExitCode==0,"Presentation fixture failed: "+presentationStderr);
    var presentationMeta=JsonDocument.Parse(presentationStdout).RootElement;
    Assert(presentationMeta.GetProperty("warnings").EnumerateArray().Any(x=>x.GetString()!.Contains("3D 표시")),"Omitted presentation metadata must be disclosed.");
    var presentationCheck=DxfReader.Read(presentationOutput,Strict);
    Assert(presentationCheck.Entities.Count==presentationSource.Entities.Count,"Presentation conversion must preserve model entity count.");
    // A legitimate XRecord makes both DXF directions exceed the old 20 MiB
    // bound, without storing customer drawings or a large binary fixture.
    var largeDoc = new CadDocument();
    largeDoc.Header.Version = ACadVersion.AC1032;
    largeDoc.Entities.Add(new Line(new XYZ(0, 0, 0), new XYZ(100, 50, 0)));
    var payload = new XRecord("SyntheticLargeDrawingData");
    for (int i = 0; i < 90000; i++) payload.CreateEntry(1, new string('x', 240) + i.ToString("D6"));
    largeDoc.RootDictionary.Add(payload);
    var largeDxf = Path.Combine(directory, "large.dxf");
    var largeDwg = Path.Combine(directory, "large.dwg");
    var largeReopened = Path.Combine(directory, "large-reopened.dxf");
    DxfWriter.Write(largeDxf, largeDoc, binary: false, notification: Strict);
    Assert(new FileInfo(largeDxf).Length > 20 * 1024 * 1024, "Fixture must exceed the old input limit.");
    (int code, string error) ConvertLarge(string from, string to, long limit)
    {
        var command = new ProcessStartInfo(args[0]) { RedirectStandardOutput = true, RedirectStandardError = true };
        if (args.Length > 1) command.ArgumentList.Add(args[1]);
        foreach (var arg in new[] { "convert", "--input", from, "--output", to }) command.ArgumentList.Add(arg);
        command.Environment["CAD_CONVERTER_MAX_BYTES"] = limit.ToString();
        using var native = Process.Start(command)!;
        var outputTask = native.StandardOutput.ReadToEndAsync();
        var errorTask = native.StandardError.ReadToEndAsync();
        native.WaitForExit();
        return (native.ExitCode, errorTask.GetAwaiter().GetResult());
    }
    var largeWrite = ConvertLarge(largeDxf, largeDwg, 64 * 1024 * 1024);
    Assert(largeWrite.code == 0, "Large DXF input failed: " + largeWrite.error);
    var largeRead = ConvertLarge(largeDwg, largeReopened, 64 * 1024 * 1024);
    Assert(largeRead.code == 0, "Large DXF output failed: " + largeRead.error);
    Assert(new FileInfo(largeReopened).Length > 20 * 1024 * 1024, "Converted output must exceed the old limit.");
    var largeCheck = DxfReader.Read(largeReopened, Strict);
    Assert(largeCheck.Entities.OfType<Line>().Single().EndPoint == new XYZ(100, 50, 0), "Large conversion must preserve geometry.");
    // ACadSharp also exposes serialization code 280 flags as entries. Compare
    // every payload value, not those reader-added structural flags.
    var preservedEntries = largeCheck.RootDictionary.GetEntry<XRecord>("SyntheticLargeDrawingData").Entries.Where(e => e.Code == 1).Select(e => e.Value.ToString());
    Assert(preservedEntries.SequenceEqual(Enumerable.Range(0, 90000).Select(i => new string('x', 240) + i.ToString("D6"))), "Every large metadata value must survive.");
    var rejectedInput = ConvertLarge(largeDxf, Path.Combine(directory, "oversize-input.dwg"), 1024);
    Assert(rejectedInput.code != 0 && rejectedInput.error.Contains("Input size exceeds limit"), "Native input must enforce the passed limit.");
    var rejectedOutput = ConvertLarge(largeDwg, Path.Combine(directory, "oversize-output.dxf"), new FileInfo(largeDwg).Length + 1024);
    Assert(rejectedOutput.code != 0 && rejectedOutput.error.Contains("Invalid output size"), "Native output must enforce the passed limit.");
    // Exercise the actual .NET allocator under the same process-only budget
    // used by PHP; a file byte limit cannot protect a shared web container.
    var bounded = new ProcessStartInfo(args[0]) { RedirectStandardOutput = true, RedirectStandardError = true };
    if (args.Length > 1) bounded.ArgumentList.Add(args[1]);
    foreach (var arg in new[] { "convert", "--input", largeDwg, "--output", Path.Combine(directory, "bounded.dxf") }) bounded.ArgumentList.Add(arg);
    bounded.Environment["DOTNET_GCHeapHardLimit"] = "4000000"; // 64 MiB, hexadecimal.
    using var boundedProcess = Process.Start(bounded)!;
    var boundedOut = boundedProcess.StandardOutput.ReadToEndAsync();
    var boundedErr = boundedProcess.StandardError.ReadToEndAsync();
    Assert(boundedProcess.WaitForExit(60000), "Budgeted conversion must finish or refuse within a minute.");
    Assert(boundedProcess.ExitCode != 0, "Large conversion must refuse the small heap budget.");
    var boundedError = boundedErr.GetAwaiter().GetResult();
    Assert(boundedError.Contains("OutOfMemoryException") || boundedError.Contains("Out of memory."), "Expected a recognizable memory refusal: " + boundedError);
    var solidDxf = Path.Combine(directory, "solid.dxf");
    File.WriteAllText(solidDxf, "0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1032\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\n3DSOLID\n5\nF0\n100\nAcDbEntity\n8\n0\n100\nAcDbModelerGeometry\n70\n1\n100\nAcDb3dSolid\n0\nENDSEC\n0\nEOF\n");
    Assert(DxfReader.Read(solidDxf, Strict).Entities.Single() is Solid3D, "Fixture must contain a real solid object.");
    var solidOutput = Path.Combine(directory, "solid.dwg");
    var solidResult = ConvertLarge(solidDxf, solidOutput, 64 * 1024 * 1024);
    Assert(solidResult.code != 0 && solidResult.error.Contains("Unsupported 3D solid entities"), "Solid must be refused explicitly.");
    Assert(!File.Exists(solidOutput), "Unsupported geometry must be refused before emitting a partial drawing.");
    Console.WriteLine("PASS: bounded native heap and explicit 3D solid refusal before writing.");
    Console.WriteLine("PASS: >20 MiB DXF input/output, real DWG roundtrip, metadata preservation and configured input/output limits.");
    Console.WriteLine("PASS: real DWG duplicate book colors, conflicting RGB rejection, presentation warning, geometry and RGB preservation.");
}
finally {Directory.Delete(directory,true);}

// The DWG dictionary key can differ from the book's internal name. This
// synthetic fixture reproduces that legal source structure without user files.
class AliasedColor : BookColor
{
    private readonly string alias;
    public override string Name {get=>alias;set{}}
    public AliasedColor(string key, Color color) {alias=key;BookName="Synthetic Book";ColorName="Synthetic Red";Color=color;}
}
