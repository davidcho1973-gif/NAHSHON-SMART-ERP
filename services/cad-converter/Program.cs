using System.Text;
using System.Text.Json;
using ACadSharp;
using ACadSharp.IO;

// The ERP needs real DWG serialization: changing an extension cannot convert a drawing.
Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
const long maxBytes = 20 * 1024 * 1024;
var warnings = new List<string>();
void Notify(object sender, NotificationEventArgs e)
{
    if (e.NotificationType is NotificationType.Error or NotificationType.NotImplemented or NotificationType.NotSupported)
        throw new InvalidDataException("Conversion refused because an object could not be preserved: " + e.Message);
    if (warnings.Count < 100) warnings.Add(e.Message.Length > 600 ? e.Message[..600] : e.Message);
}
try
{
    if (args is ["capabilities"])
    {
        Console.WriteLine(JsonSerializer.Serialize(new { version = 1, engine = "ACadSharp 3.8.0", dwgRead = true, dwgWrite = true }));
        return 0;
    }
    if (args.Length != 5 || args[0] != "convert" || args[1] != "--input" || args[3] != "--output")
        throw new ArgumentException("Usage: convert --input /absolute/input.dwg --output /absolute/output.dxf");
    var input = Path.GetFullPath(args[2]);
    var output = Path.GetFullPath(args[4]);
    if (input.Equals(output, StringComparison.OrdinalIgnoreCase)) throw new ArgumentException("Input and output must differ.");
    if (!File.Exists(input) || new FileInfo(input).Length > maxBytes) throw new ArgumentException("Input missing or larger than 20 MiB.");
    if (File.Exists(output)) throw new ArgumentException("Refusing to replace an existing output.");
    var source = Path.GetExtension(input).ToLowerInvariant();
    var target = Path.GetExtension(output).ToLowerInvariant();
    if (source is not (".dwg" or ".dxf") || target is not (".dwg" or ".dxf")) throw new ArgumentException("Only DWG and DXF are supported.");
    CadDocument document = source == ".dwg"
        ? DwgReader.Read(input, new DwgReaderConfiguration { Failsafe = false, KeepUnknownEntities = true, KeepUnknownNonGraphicalObjects = true }, Notify)
        : DxfReader.Read(input, new DxfReaderConfiguration { CreateDefaults = true, Failsafe = false, KeepUnknownEntities = true, KeepUnknownNonGraphicalObjects = true }, Notify);
    var inputEntities = document.BlockRecords.SelectMany(b => b.Entities).ToList();
    if (inputEntities.Any(e => e.GetType().Name.StartsWith("Unknown", StringComparison.Ordinal)))
        throw new InvalidDataException("Unknown proxy entities cannot be preserved by the writer. Keep the original drawing.");
    // UTF-8 DXF and DWG 2018 are the explicit interchange version, including old source drawings.
    document.Header.Version = ACadVersion.AC1032;
    if (target == ".dwg") DwgWriter.Write(output, document, notification: Notify);
    else DxfWriter.Write(output, document, binary: false, notification: Notify);
    if (!File.Exists(output) || new FileInfo(output).Length is 0 or > maxBytes) throw new InvalidDataException("Invalid output size.");
    // Reopen actual emitted bytes, never report a successful file extension alone.
    var check = target == ".dwg" ? DwgReader.Read(output, Notify) : DxfReader.Read(output, Notify);
    if (check.BlockRecords.SelectMany(b => b.Entities).Count() != inputEntities.Count) throw new InvalidDataException("Entity count changed during serialization; conversion refused.");
    warnings.Insert(0, "DWG/DXF conversion may change custom/proxy objects, layouts, fonts and external references. Original is preserved; verify the exported drawing in your CAD program.");
    Console.WriteLine(JsonSerializer.Serialize(new { warnings = warnings.Distinct().Take(100), entities = check.Entities.Count, engine = "ACadSharp 3.8.0", outputVersion = "AC1032" }));
    return 0;
}
catch (Exception e)
{
    // No stack traces, paths or document content are sent to browser users by the PHP bridge.
    Console.Error.WriteLine(JsonSerializer.Serialize(new { error = e.GetType().Name, message = e.Message }));
    return 2;
}
