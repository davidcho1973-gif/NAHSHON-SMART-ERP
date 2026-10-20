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
