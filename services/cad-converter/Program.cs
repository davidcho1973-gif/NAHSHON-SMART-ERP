using System.Text;
using System.Text.Json;
using ACadSharp;
using ACadSharp.IO;
using ACadSharp.Objects;
using ACadSharp.Tables;

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
    var inputObjects = Inventory(document).ToList();
    var unsafeObjects = new HashSet<string> { "EvaluationGraph", "BlockRepresentationData", "DynamicBlockPurgePreventer" };
    if (inputObjects.Any(e => e.GetType().Name.StartsWith("Unknown", StringComparison.Ordinal) || unsafeObjects.Contains(e.GetType().Name)))
        throw new InvalidDataException("Proxy objects or dynamic block metadata cannot be preserved by the writer. Keep the original drawing.");
    var defaultMaterials = new HashSet<string>(StringComparer.OrdinalIgnoreCase) { "Global", "ByLayer", "ByBlock" };
    if (inputObjects.OfType<Material>().Any(m => !defaultMaterials.Contains(m.Name)))
        throw new InvalidDataException("Custom rendering materials require the original CAD application.");
    // UTF-8 DXF and DWG 2018 are the explicit interchange version, including old source drawings.
    document.Header.Version = ACadVersion.AC1032;
    if (target == ".dwg") DwgWriter.Write(output, document, notification: Notify);
    else DxfWriter.Write(output, document, binary: false, notification: Notify);
    if (!File.Exists(output) || new FileInfo(output).Length is 0 or > maxBytes) throw new InvalidDataException("Invalid output size.");
    // Reopen actual emitted bytes, never report a successful file extension alone.
    var check = target == ".dwg" ? DwgReader.Read(output, Notify) : DxfReader.Read(output, Notify);
    if (check.BlockRecords.SelectMany(b => b.Entities).Count() != inputEntities.Count) throw new InvalidDataException("Entity count changed during serialization; conversion refused.");
    var outputTypes = Inventory(check).GroupBy(o => o.GetType().FullName!).ToDictionary(g => g.Key, g => g.Count());
    foreach (var group in inputObjects.GroupBy(o => o.GetType().FullName!))
        if (outputTypes.GetValueOrDefault(group.Key) < group.Count())
        {
            // The library synthesizes the three default 3D materials when reading DWG.
            // They are outside this 2D editor; explicitly disclose their omission.
            if (group.All(o => o is Material m && defaultMaterials.Contains(m.Name)))
                warnings.Add("Default 3D rendering materials are omitted by 2D DXF interchange; 2D geometry is preserved.");
            else throw new InvalidDataException("Object inventory decreased during serialization: " + group.Key);
        }
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

static IEnumerable<CadObject> Inventory(CadDocument document)
{
    var seen = new HashSet<CadObject>(ReferenceEqualityComparer.Instance);
    var queue = new Queue<CadObject>();
    if (document.RootDictionary != null) queue.Enqueue(document.RootDictionary);
    // All public table/object collections, then nested block entities and extension dictionaries.
    foreach (var property in typeof(CadDocument).GetProperties())
    {
        if (property.GetIndexParameters().Length != 0 || !typeof(System.Collections.IEnumerable).IsAssignableFrom(property.PropertyType)) continue;
        if (property.GetValue(document) is System.Collections.IEnumerable collection)
            foreach (var item in collection) if (item is CadObject cad) queue.Enqueue(cad);
    }
    while (queue.TryDequeue(out var item))
    {
        if (!seen.Add(item)) continue;
        yield return item;
        if (item.XDictionary != null) queue.Enqueue(item.XDictionary);
        foreach (var reactor in item.Reactors) queue.Enqueue(reactor);
        if (item is CadDictionary dictionary) foreach (var child in dictionary) queue.Enqueue(child);
        if (item is BlockRecord block) foreach (var entity in block.Entities) queue.Enqueue(entity);
    }
}
