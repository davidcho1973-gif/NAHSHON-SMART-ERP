using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using ACadSharp;
using ACadSharp.Entities;
using ACadSharp.IO;
using ACadSharp.Objects;
using ACadSharp.Tables;
using CSMath;

// A derived top-view document is deliberately separate from native round-trip
// conversion. Original bytes stay in durable storage; no fallback overwrites them.
static class WorkingCopy2D
{
    public static object Prepare(string input, string directory, long maxBytes)
    {
        input = Path.GetFullPath(input);
        directory = Path.GetFullPath(directory);
        if (!File.Exists(input) || new FileInfo(input).Length > maxBytes)
            throw new InvalidDataException("Input size exceeds limit.");
        if (Directory.Exists(directory)) throw new IOException("Output directory must be new.");
        var extension = Path.GetExtension(input).ToLowerInvariant();
        if (extension is not (".dwg" or ".dxf")) throw new InvalidDataException("Invalid input format.");
        var notices = new HashSet<string>();
        void ReadNotice(object sender, NotificationEventArgs e)
        {
            // Unknown objects are retained by the reader and accounted for
            // below. Actual read errors still fail the complete operation.
            if (e.NotificationType is NotificationType.Error or NotificationType.NotImplemented or NotificationType.NotSupported)
                throw new InvalidDataException("Working-copy reader could not preserve an object: " + e.Message);
        }
        var doc = extension == ".dwg" ? DwgColorCompatibility.Read(input, ReadNotice)
            : DxfReader.Read(input, new DxfReaderConfiguration { Failsafe = false, CreateDefaults = true, KeepUnknownEntities = true, KeepUnknownNonGraphicalObjects = true }, ReadNotice);
        var excluded = new SortedDictionary<string, int>();
        var projected = new SortedDictionary<string, int>();
        var protectedBlocks = new HashSet<BlockRecord>(ReferenceEqualityComparer.Instance);
        void Protect(BlockRecord block)
        {
            if (!protectedBlocks.Add(block)) return;
            foreach (var insert in block.Entities.OfType<Insert>()) Protect(insert.Block);
        }
        foreach (var insert in doc.BlockRecords.SelectMany(b => b.Entities).OfType<Insert>())
            if (!Horizontal(insert.Normal)) Protect(insert.Block);
        foreach (var block in doc.BlockRecords.ToArray())
        {
            StaticMetadata(block);
            foreach (var entity in block.Entities.ToArray())
            {
                StaticMetadata(entity);
                // ACadSharp 3.8 Entity.Clone does not detach BookColor. Resolve
                // this named color to its exact RGB for the independent copy.
                if (entity.BookColor != null) { entity.Color = entity.BookColor.Color; entity.BookColor = null; }
                if (entity is Solid3D or CadBody or Region or UnknownEntity || entity.ObjectName == "POSITIONMARKER")
                {
                    Count(excluded, entity.ObjectName);
                    block.Entities.Remove(entity);
                    continue;
                }
                if (protectedBlocks.Contains(block)) continue;
                var replacement = Flatten(entity);
                if (replacement != null)
                {
                    Count(projected, entity.ObjectName);
                    if (!ReferenceEquals(replacement, entity))
                    {
                        block.Entities.Remove(entity);
                        block.Entities.Add(replacement);
                    }
                }
            }
        }
        var sourceEntities = doc.Entities.ToArray();
        if (sourceEntities.Length == 0) throw new InvalidDataException("No supported 2D entities.");
        Directory.CreateDirectory(directory);
        var parts = new List<object>();
        var totalEntities = 0;
        foreach (var group in sourceEntities.GroupBy(e => Category(e.Layer.Name)).OrderBy(g => g.Key))
        {
            // Bound vertex-heavy contour workspaces, independently of file size.
            var chunk = new List<Entity>();
            var weight = 0;
            foreach (var entity in group)
            {
                var cost = entity is IPolyline poly ? Math.Max(1, poly.Vertices.Count()) : 1;
                if (chunk.Count > 0 && weight + cost > 240000) { WritePart(chunk, group.Key); chunk.Clear(); weight = 0; }
                chunk.Add(entity); weight += cost;
            }
            if (chunk.Count > 0) WritePart(chunk, group.Key);
        }
        if (totalEntities != sourceEntities.Length) throw new InvalidDataException("Model entity coverage changed during serialization.");
        var warnings = new List<string> {
            "원본을 별도 보존한 2D 평면 작업본입니다. 종이 배치·Civil 자동 갱신·동적 블록 동작·3D 재질은 원본에서 확인하세요.",
            "수평 객체의 높이를 0으로 투영했습니다. 기울어진 블록은 평면 위치를 보존하기 위해 높이를 유지하며 일부 정밀 편집이 제한됩니다."
        };
        if (excluded.Count > 0) warnings.Add("2D 작업본에서 제외: " + string.Join(", ", excluded.Select(p => p.Key + " " + p.Value + "개")) + ". 원본에는 유지됩니다.");
        warnings.AddRange(notices);
        return new { version = 1, mode = "2d-working-copy", engine = "ACadSharp 3.8.0", parts, excluded, projected, protectedBlocks = protectedBlocks.Count, warnings, modelEntities = totalEntities, units = (int)doc.Header.InsUnits };

        void StaticMetadata(CadObject obj)
        {
            if (obj.XDictionary == null) return;
            foreach (var item in obj.XDictionary.ToArray())
            {
                if (item.GetType().Name is "EvaluationGraph" or "BlockRepresentationData" or "DynamicBlockPurgePreventer")
                {
                    obj.XDictionary.Remove(item.Name);
                    notices.Add("동적 블록은 현재 표시된 모양의 정적 블록으로 저장합니다. 동적 조절 동작은 원본에서만 유지됩니다.");
                }
            }
        }

        void WritePart(List<Entity> entities, int category)
        {
            if (parts.Count >= 32) throw new InvalidDataException("Too many working-copy parts.");
            var name = $"part-{parts.Count + 1:D2}.dxf";
            var path = Path.Combine(directory, name);
            var target = new CadDocument(ACadVersion.AC1032);
            target.Header.InsUnits = doc.Header.InsUnits;
            target.Header.MeasurementUnits = doc.Header.MeasurementUnits;
            target.Header.LinearUnitFormat = doc.Header.LinearUnitFormat;
            target.Header.LinearUnitPrecision = doc.Header.LinearUnitPrecision;
            target.Header.ModelSpaceInsertionBase = doc.Header.ModelSpaceInsertionBase;
            // Each clone carries its layer, style, and referenced blocks. The
            // destination deduplicates named resources when assigning entities.
            foreach (var entity in entities) target.Entities.Add((Entity)entity.Clone());
            var before = Inventory(target);
            if (!Inventory(entities).OrderBy(p => p.Key).SequenceEqual(before.OrderBy(p => p.Key)))
                throw new InvalidDataException("Working-copy geometry changed while copying resources.");
            void WriteNotice(object sender, NotificationEventArgs e)
            {
                if (e.NotificationType == NotificationType.NotImplemented && e.Message == "Object not implemented : ACadSharp.Objects.VisualStyle")
                { notices.Add("3D 표시 스타일은 원본에만 보존됩니다."); return; }
                if (e.NotificationType == NotificationType.NotImplemented && e.Message == "Object not implemented : ACadSharp.Objects.DimensionAssociation")
                { notices.Add("치수는 현재 표시를 보존한 정적 치수입니다. 연결된 객체를 따라 자동 갱신되는 관계는 원본에만 유지됩니다."); return; }
                if (e.NotificationType is NotificationType.Error or NotificationType.NotImplemented or NotificationType.NotSupported)
                    throw new InvalidDataException("Working-copy writer could not preserve an object: " + e.Message);
            }
            DxfWriter.Write(path, target, binary: false, notification: WriteNotice);
            PreservePolylineDefaults(path, target);
            var size = new FileInfo(path).Length;
            if (size > maxBytes && entities.Count > 1)
            {
                File.Delete(path);
                var mid = entities.Count / 2;
                WritePart(entities.Take(mid).ToList(), category);
                WritePart(entities.Skip(mid).ToList(), category);
                return;
            }
            if (size == 0 || size > maxBytes) throw new InvalidDataException("Invalid output size.");
            var reopened = DxfReader.Read(path, new DxfReaderConfiguration { Failsafe = false, CreateDefaults = true }, ReadNotice);
            if (reopened.Entities.Count != entities.Count || reopened.Header.InsUnits != doc.Header.InsUnits ||
                !before.OrderBy(p => p.Key).SequenceEqual(Inventory(reopened).OrderBy(p => p.Key)))
                throw new InvalidDataException("Working-copy geometry changed during serialization.");
            using var stream = File.OpenRead(path);
            parts.Add(new { file = name, label = category switch { 1 => "기존 등고선", 2 => "설계 등고선", _ => "2D 평면" }, entities = entities.Count, bytes = size, sha256 = Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant() });
            totalEntities += entities.Count;
        }
    }

    static int Category(string layer) => layer.StartsWith("V-TOPO", StringComparison.OrdinalIgnoreCase) ? 1 : layer.Contains("TOPO", StringComparison.OrdinalIgnoreCase) ? 2 : 0;
    // ACadSharp 3.8's writePolyline omits DXF groups 40/41, losing the
    // POLYLINE's default widths. Serialize those exact source properties at
    // the matching handle/subclass; the subsequent reader verifies them.
    static void PreservePolylineDefaults(string path, CadDocument doc)
    {
        var widths = doc.BlockRecords.SelectMany(b => b.Entities).OfType<Polyline2D>()
            .Where(p => p.StartWidth != 0 || p.EndWidth != 0).ToDictionary(p => p.Handle);
        if (widths.Count == 0) return;
        var replacement = path + ".writing";
        var restored = new HashSet<ulong>();
        using (var reader = new StreamReader(path, Encoding.UTF8, true))
        using (var writer = new StreamWriter(replacement, false, new UTF8Encoding(false)))
        {
            bool polyline = false, inSubclass = false;
            ulong handle = 0;
            while (reader.ReadLine() is { } codeText)
            {
                var value = reader.ReadLine() ?? throw new InvalidDataException("Incomplete DXF pair.");
                if (!int.TryParse(codeText.Trim(), out var code)) throw new InvalidDataException("Invalid DXF code.");
                if (code == 0) { polyline = value == "POLYLINE"; inSubclass = false; handle = 0; }
                if (polyline && code == 5) ulong.TryParse(value, NumberStyles.HexNumber, CultureInfo.InvariantCulture, out handle);
                if (polyline && code == 100) inSubclass = value == "AcDb2dPolyline";
                if (inSubclass && widths.ContainsKey(handle) && code is 40 or 41) continue;
                writer.WriteLine(codeText); writer.WriteLine(value);
                if (inSubclass && code == 100 && widths.TryGetValue(handle, out var p))
                {
                    writer.WriteLine("40"); writer.WriteLine(p.StartWidth.ToString("R", CultureInfo.InvariantCulture));
                    writer.WriteLine("41"); writer.WriteLine(p.EndWidth.ToString("R", CultureInfo.InvariantCulture));
                    restored.Add(handle);
                }
            }
        }
        if (restored.Count != widths.Count) throw new InvalidDataException("Polyline width serialization did not cover all objects.");
        File.Move(replacement, path, true);
    }
    static void Count(IDictionary<string, int> counts, string key) => counts[key] = (counts.TryGetValue(key, out var value) ? value : 0) + 1;
    static bool Horizontal(XYZ v) => Math.Abs(v.X) < 1e-12 && Math.Abs(v.Y) < 1e-12 && Math.Abs(v.Z - 1) < 1e-12;
    static XYZ XY0(XYZ p) => new(p.X, p.Y, 0);
    static Entity? Flatten(Entity e)
    {
        switch (e)
        {
            case Line line: line.StartPoint = XY0(line.StartPoint); line.EndPoint = XY0(line.EndPoint); line.Normal = XYZ.AxisZ; line.Thickness = 0; return e;
            // Curve/spline-fit POLYLINEs and inherited widths cannot be reduced
            // to bare vertices without changing their displayed shape.
            case Polyline3D poly when ((int)poly.Flags & 6) == 0:
                var flat = new LwPolyline { IsClosed = poly.IsClosed }; flat.MatchProperties(poly);
                foreach (var v in poly.Vertices) flat.Vertices.Add(new LwPolyline.Vertex(v.Location.X, v.Location.Y));
                return flat;
            case Polyline2D poly when Horizontal(poly.Normal) && ((int)poly.Flags & 6) == 0 && poly.StartWidth == 0 && poly.EndWidth == 0:
                var lw = new LwPolyline { IsClosed = poly.IsClosed }; lw.MatchProperties(poly);
                foreach (var v in poly.Vertices) lw.Vertices.Add(new LwPolyline.Vertex(v.Location.X, v.Location.Y) { Bulge = v.Bulge, StartWidth = v.StartWidth, EndWidth = v.EndWidth });
                return lw;
            case LwPolyline poly when Horizontal(poly.Normal): poly.Elevation = 0; poly.Thickness = 0; return e;
            case Circle circle when Horizontal(circle.Normal): circle.Center = XY0(circle.Center); circle.Thickness = 0; return e;
            case TextEntity text when Horizontal(text.Normal): text.InsertPoint = XY0(text.InsertPoint); text.AlignmentPoint = XY0(text.AlignmentPoint); text.Thickness = 0; return e;
            case MText text when Horizontal(text.Normal): text.InsertPoint = XY0(text.InsertPoint); return e;
            case Insert insert when Horizontal(insert.Normal): insert.InsertPoint = XY0(insert.InsertPoint); return e;
            case ACadSharp.Entities.Point point when Horizontal(point.Normal): point.Location = XY0(point.Location); point.Thickness = 0; return e;
            case Hatch hatch when Horizontal(hatch.Normal): hatch.Elevation = 0; return e;
            default: return null;
        }
    }

    // Compare real geometry, text and display properties after reopening, not
    // merely a successful process exit or a matching number of objects.
    static Dictionary<string, int> Inventory(CadDocument doc) => Inventory(doc.Entities);
    static Dictionary<string, int> Inventory(IEnumerable<Entity> roots)
    {
        var values = new List<string>();
        var blocks = new HashSet<BlockRecord>(ReferenceEqualityComparer.Instance);
        void Visit(IEnumerable<Entity> entities, string name)
        {
            foreach (var entity in entities)
            {
                values.Add(name + "|" + Signature(entity));
                if (entity is Insert insert && blocks.Add(insert.Block)) Visit(insert.Block.Entities, insert.Block.Name);
            }
        }
        Visit(roots, "*MODEL_SPACE");
        return values.GroupBy(s => s).ToDictionary(g => g.Key, g => g.Count());
    }
    static string Signature(Entity e)
    {
        string Number(double n) => Math.Round(n, 7) == 0 ? "0" : Math.Round(n, 7).ToString("R", CultureInfo.InvariantCulture);
        string Angle(double n)
        {
            var a = (n % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI);
            return Number(Math.Abs(a - 2 * Math.PI) < 5e-8 ? 0 : a);
        }
        string ArcAngles(double start, double end) => Angle(start) + "|" + Angle(end) + "|" + (Math.Abs(end - start) >= 2 * Math.PI - 1e-8);
        string Point(XYZ p) => $"{Number(p.X)},{Number(p.Y)},{Number(p.Z)}";
        var color = e.BookColor?.Color ?? e.GetActiveColor();
        var value = new StringBuilder($"{e.ObjectName}|{e.Layer.Name}|{color.R},{color.G},{color.B}|{e.LineWeight}|");
        if (e is IOrientable oriented) value.Append(Point(oriented.Normal)).Append('|');
        switch (e)
        {
            case Line x: value.Append(Point(x.StartPoint)).Append('|').Append(Point(x.EndPoint)); break;
            case Arc x: value.Append(Point(x.Center)).Append('|').Append(Number(x.Radius)).Append('|').Append(Number(x.StartAngle)).Append('|').Append(Number(x.EndAngle)); break;
            case Circle x: value.Append(Point(x.Center)).Append('|').Append(Number(x.Radius)); break;
            case LwPolyline x:
                value.Append(x.IsClosed).Append('|').Append(Number(x.Elevation));
                foreach (var v in x.Vertices) value.Append('|').Append(Number(v.Location.X)).Append(',').Append(Number(v.Location.Y)).Append(',').Append(Number(v.Bulge)).Append(',').Append(Number(v.StartWidth)).Append(',').Append(Number(v.EndWidth));
                break;
            case TextEntity x: value.Append(Point(x.InsertPoint)).Append('|').Append(Point(x.AlignmentPoint)).Append('|').Append(x.Value).Append('|').Append(Number(x.Height)).Append('|').Append(Number(x.Rotation)); break;
            case MText x: value.Append(Point(x.InsertPoint)).Append('|').Append(x.Value).Append('|').Append(Number(x.Height)); break;
            case Insert x:
                value.Append(x.Block.Name).Append('|').Append(Point(x.InsertPoint)).Append('|').Append(Number(x.XScale)).Append('|').Append(Number(x.YScale)).Append('|').Append(Number(x.ZScale)).Append('|').Append(Number(x.Rotation));
                value.Append('|').Append(x.RowCount).Append('|').Append(x.ColumnCount).Append('|').Append(Number(x.RowSpacing)).Append('|').Append(Number(x.ColumnSpacing));
                foreach (var a in x.Attributes) value.Append('|').Append(Signature(a));
                break;
            case ACadSharp.Entities.Point x: value.Append(Point(x.Location)); break;
            case Polyline2D x:
                value.Append(x.IsClosed).Append('|').Append(Number(x.StartWidth)).Append('|').Append(Number(x.EndWidth)).Append('|').Append(Number(x.Elevation)).Append('|').Append(x.Flags);
                foreach (var v in x.Vertices) value.Append('|').Append(Point(v.Location)).Append('|').Append(Number(v.Bulge)).Append('|').Append(Number(v.StartWidth)).Append('|').Append(Number(v.EndWidth));
                break;
            case Polyline3D x:
                value.Append(x.IsClosed).Append('|').Append(x.Flags);
                foreach (var v in x.Vertices) value.Append('|').Append(Point(v.Location));
                break;
            case Ellipse x: value.Append(Point(x.Center)).Append('|').Append(Point(x.MajorAxisEndPoint)).Append('|').Append(Number(x.RadiusRatio)).Append('|').Append(Number(x.StartParameter)).Append('|').Append(Number(x.EndParameter)); break;
            case Spline x:
                value.Append(x.Degree).Append('|').Append(x.IsClosed).Append('|').Append(x.IsPeriodic);
                foreach (var p in x.ControlPoints) value.Append('|').Append(Point(p));
                foreach (var p in x.FitPoints) value.Append('|').Append(Point(p));
                foreach (var n in x.Knots) value.Append('|').Append(Number(n));
                foreach (var n in x.Weights) value.Append('|').Append(Number(n));
                break;
            case Hatch x:
                value.Append(x.IsSolid).Append('|').Append(x.Pattern.Name).Append('|').Append(Angle(x.PatternAngle)).Append('|').Append(Number(x.PatternScale)).Append('|').Append(Number(x.Elevation));
                string XYPoint(XY p) => Number(p.X) + "," + Number(p.Y);
                foreach (var path in x.Paths)
                {
                    value.Append("|path");
                    foreach (var edge in path.Edges)
                    {
                        value.Append('|').Append(edge.Type);
                        switch (edge)
                        {
                            case Hatch.BoundaryPath.Polyline p:
                                value.Append('|').Append(p.IsClosed);
                                foreach (var v in p.Vertices) value.Append('|').Append(Point(v)); break;
                            case Hatch.BoundaryPath.Line p: value.Append('|').Append(XYPoint(p.Start)).Append('|').Append(XYPoint(p.End)); break;
                            case Hatch.BoundaryPath.Arc p: value.Append('|').Append(XYPoint(p.Center)).Append('|').Append(Number(p.Radius)).Append('|').Append(ArcAngles(p.StartAngle, p.EndAngle)).Append('|').Append(p.CounterClockWise); break;
                            case Hatch.BoundaryPath.Ellipse p: value.Append('|').Append(XYPoint(p.Center)).Append('|').Append(XYPoint(p.MajorAxisEndPoint)).Append('|').Append(Number(p.RadiusRatio)).Append('|').Append(ArcAngles(p.StartAngle, p.EndAngle)).Append('|').Append(p.CounterClockWise); break;
                            case Hatch.BoundaryPath.Spline p:
                                value.Append('|').Append(p.Degree).Append('|').Append(p.IsPeriodic).Append('|').Append(p.IsRational);
                                foreach (var v in p.ControlPoints) value.Append('|').Append(Point(v));
                                foreach (var v in p.FitPoints) value.Append('|').Append(XYPoint(v));
                                foreach (var n in p.Knots) value.Append('|').Append(Number(n)); break;
                            default: throw new InvalidDataException("Unsupported hatch boundary verification.");
                        }
                    }
                }
                break;
        }
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(value.ToString())));
    }
}
