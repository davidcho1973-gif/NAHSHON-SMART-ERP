using System.Text;
using System.Text.Json;
using System.Runtime.CompilerServices;
using ACadSharp;
using ACadSharp.IO;
using ACadSharp.Objects;
using ACadSharp.Tables;
using ACadSharp.Entities;

// The ERP needs real DWG serialization: changing an extension cannot convert a drawing.
Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
long maxBytes = 64 * 1024 * 1024;
var warnings = new List<string>();
// These resources describe presentation/associativity, not the static 2D
// entities. A working copy may omit them only with an explicit warning.
var optionalMetadata = new HashSet<string>(StringComparer.Ordinal)
    { "SUN", "ACDBASSOCNETWORK", "ACDBDETAILVIEWSTYLE", "ACDBSECTIONVIEWSTYLE" };
void Notify(object sender, NotificationEventArgs e)
{
    const string unknownPrefix = "Unlisted object with DXF name ";
    if (e.Message.StartsWith(unknownPrefix, StringComparison.Ordinal) &&
        (!e.Message.EndsWith("UnknownNonGraphicalObject", StringComparison.Ordinal) ||
         !optionalMetadata.Contains(e.Message[unknownPrefix.Length..].Split(' ')[0])))
        throw new InvalidDataException("Proxy objects or dynamic block metadata cannot be preserved by the writer. Keep the original drawing.");
    if (e.NotificationType == NotificationType.NotImplemented &&
        e.Message == "Object not implemented : ACadSharp.Objects.VisualStyle")
    {
        warnings.Add("3D 표시 스타일은 2D 편집본에서 제외됩니다. 원본 DWG에는 유지됩니다.");
        return;
    }
    if (e.NotificationType is NotificationType.Error or NotificationType.NotImplemented or NotificationType.NotSupported)
        throw new InvalidDataException("Conversion refused because an object could not be preserved: " + e.Message);
    if (warnings.Count < 100) warnings.Add(e.Message.Length > 600 ? e.Message[..600] : e.Message);
}
try
{
    // The PHP bridge passes its canonical limit to both commands. Standalone
    // invocations retain the same bounded default; invalid overrides fail closed.
    var configuredLimit = Environment.GetEnvironmentVariable("CAD_CONVERTER_MAX_BYTES");
    if (configuredLimit != null && (!long.TryParse(configuredLimit, out maxBytes) || maxBytes <= 0 || maxBytes > int.MaxValue))
        throw new ArgumentException("Invalid CAD size limit.");
    if (args is ["capabilities"])
    {
        Console.WriteLine(JsonSerializer.Serialize(new { version = 1, engine = "ACadSharp 3.8.0", dwgRead = true, dwgWrite = true, maxBytes }));
        return 0;
    }
    // Explicit derived-copy command; the strict round-trip converter below
    // continues refusing lossy conversions and is used for DWG export.
    if (args is ["prepare-2d", "--input", var workingInput, "--output", var workingDirectory])
    {
        Console.WriteLine(JsonSerializer.Serialize(WorkingCopy2D.Prepare(workingInput, workingDirectory, maxBytes)));
        return 0;
    }
    if (args.Length != 5 || args[0] != "convert" || args[1] != "--input" || args[3] != "--output")
        throw new ArgumentException("Usage: convert --input /absolute/input.dwg --output /absolute/output.dxf");
    var input = Path.GetFullPath(args[2]);
    var output = Path.GetFullPath(args[4]);
    if (input.Equals(output, StringComparison.OrdinalIgnoreCase)) throw new ArgumentException("Input and output must differ.");
    if (!File.Exists(input)) throw new ArgumentException("Input missing.");
    if (new FileInfo(input).Length > maxBytes) throw new ArgumentException("Input size exceeds limit.");
    if (File.Exists(output)) throw new ArgumentException("Refusing to replace an existing output.");
    var source = Path.GetExtension(input).ToLowerInvariant();
    var target = Path.GetExtension(output).ToLowerInvariant();
    if (source is not (".dwg" or ".dxf") || target is not (".dwg" or ".dxf")) throw new ArgumentException("Only DWG and DXF are supported.");
    // Do not retain the source object graph while reopening the emitted file.
    // DWG blocks can expand a small input into hundreds of thousands of objects.
    var snapshot = WriteDrawing(input, output, source, target, Notify, optionalMetadata, warnings);
    GC.Collect();
    GC.WaitForPendingFinalizers();
    GC.Collect();
    if (!File.Exists(output) || new FileInfo(output).Length == 0 || new FileInfo(output).Length > maxBytes) throw new InvalidDataException("Invalid output size.");
    var check = target == ".dwg" ? DwgColorCompatibility.Read(output, Notify)
        : DxfReader.Read(output, new DxfReaderConfiguration { CreateDefaults = true, Failsafe = false, KeepUnknownEntities = true, KeepUnknownNonGraphicalObjects = true }, Notify);
    if (check.BlockRecords.Sum(b => b.Entities.Count) != snapshot.Entities) throw new InvalidDataException("Entity count changed during serialization; conversion refused.");
    if (!snapshot.Geometry.OrderBy(x => x.Key).SequenceEqual(GeometryInventory(check).OrderBy(x => x.Key)))
        throw new InvalidDataException("Entity types, block membership or display colors changed during serialization; conversion refused.");
    var outputTypes = Inventory(check).GroupBy(ResourceKey).ToDictionary(g => g.Key, g => g.Count());
    foreach (var (key, required) in snapshot.Resources)
        if (outputTypes.GetValueOrDefault(key) < required) throw new InvalidDataException("Object inventory decreased during serialization: " + key);
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

[MethodImpl(MethodImplOptions.NoInlining)]
static ConversionSnapshot WriteDrawing(string input, string output, string source, string target,
    NotificationEventHandler notify, HashSet<string> optionalMetadata, List<string> warnings)
{
    CadDocument document = source == ".dwg"
        ? DwgColorCompatibility.Read(input, notify)
        : DxfReader.Read(input, new DxfReaderConfiguration { CreateDefaults = true, Failsafe = false, KeepUnknownEntities = true, KeepUnknownNonGraphicalObjects = true }, notify);
    // Both writers skip solids. Refuse before writing a large, incomplete DXF.
    if (document.BlockRecords.SelectMany(b => b.Entities).Any(e => e is Solid3D or CadBody or Region))
        throw new InvalidDataException("Unsupported 3D solid entities cannot be preserved by the writer.");
    var entityCount = document.BlockRecords.Sum(b => b.Entities.Count);
    var inputObjects = Inventory(document).ToList();
    var unsafeObjects = new HashSet<string> { "EvaluationGraph", "BlockRepresentationData", "DynamicBlockPurgePreventer" };
    var defaultMaterials = new HashSet<string>(StringComparer.OrdinalIgnoreCase) { "Global", "ByLayer", "ByBlock" };
    bool Optional(CadObject o) => OptionalResource(o, new HashSet<CadObject>(ReferenceEqualityComparer.Instance));
    bool OptionalResource(CadObject o, HashSet<CadObject> visited)
    {
        if (o is VisualStyle || o is UnknownNonGraphicalObject unknown && optionalMetadata.Contains(unknown.ObjectName)) return true;
        if (o is not Entity)
        {
            var ancestors = new HashSet<CadObject>(ReferenceEqualityComparer.Instance);
            for (var parent = o.Owner as CadObject; parent != null && ancestors.Add(parent); parent = parent.Owner as CadObject)
                if (parent is VisualStyle || parent is UnknownNonGraphicalObject metadata && optionalMetadata.Contains(metadata.ObjectName) ||
                    parent is Material material && defaultMaterials.Contains(material.Name)) return true;
        }
        // The DXF writer also omits empty containers of those resources.
        if (o is not CadDictionary dictionary || !visited.Add(o)) return false;
        return dictionary.All(child => child is Material m && defaultMaterials.Contains(m.Name) || OptionalResource(child, visited));
    }
    if (inputObjects.Any(e => (e.GetType().Name.StartsWith("Unknown", StringComparison.Ordinal) && !Optional(e)) || unsafeObjects.Contains(e.GetType().Name)))
        throw new InvalidDataException("Proxy objects or dynamic block metadata cannot be preserved by the writer. Keep the original drawing.");
    var omitted = inputObjects.Where(o => o is VisualStyle || o is UnknownNonGraphicalObject unknown && optionalMetadata.Contains(unknown.ObjectName))
        .Select(o => o.ObjectName).Distinct().Order().ToArray();
    if (omitted.Length > 0)
        warnings.Add("2D 편집본에서는 3D 표시·태양광·연관 자동 갱신·상세/단면 보기 스타일 정보를 제외합니다 (" + string.Join(", ", omitted) + "). 선·도형은 유지하며 원본 DWG를 별도로 보존합니다.");
    if (inputObjects.OfType<Material>().Any(m => !defaultMaterials.Contains(m.Name)))
        throw new InvalidDataException("Custom rendering materials require the original CAD application.");
    // UTF-8 DXF and DWG 2018 are the explicit interchange version, including old source drawings.
    document.Header.Version = ACadVersion.AC1032;
    var inputGeometry = GeometryInventory(document);
    var resources = inputObjects.GroupBy(ResourceKey).ToDictionary(g => g.Key,
        g => g.Count(o => !(o is Material m && defaultMaterials.Contains(m.Name)) && !Optional(o)));
    if (inputObjects.OfType<Material>().Any())
        warnings.Add("Default 3D rendering materials may be omitted by 2D DXF interchange; original is preserved.");
    if (target == ".dwg") DwgWriter.Write(output, document, notification: notify);
    else DxfWriter.Write(output, document, binary: false, notification: notify);
    return new ConversionSnapshot(entityCount, inputGeometry, resources);
}

// Handles identify containers across the DXF reader's anonymous dictionary rename.
static string ResourceKey(CadObject o) => o.GetType().FullName + (o is CadDictionary or XRecord ? "|" + o.Handle : "");

static Dictionary<string, int> GeometryInventory(CadDocument document) =>
    document.BlockRecords.SelectMany(block => block.Entities.Select(entity =>
    {
        var color = entity.BookColor?.Color ?? entity.GetActiveColor();
        return $"{block.Name}|{entity.GetType().FullName}|{color.R},{color.G},{color.B}";
    })).GroupBy(key => key).ToDictionary(group => group.Key, group => group.Count());

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

record ConversionSnapshot(int Entities, Dictionary<string, int> Geometry, Dictionary<string, int> Resources);
