using System.Collections;
using System.Reflection;
using ACadSharp;
using ACadSharp.IO;
using ACadSharp.Objects;

// ACadSharp 3.8.0 indexes dictionary entries by object Name, not the source
// dictionary key. Valid DWGs can therefore report duplicate book colors.
// Only identical named RGB definitions may be coalesced; conflicting colors
// and every other reader error still fail. No private state is modified.
static class DwgColorCompatibility
{
    public static CadDocument Read(string path, NotificationEventHandler notify)
    {
        using var reader = new DwgReader(path);
        reader.Configuration = new() { Failsafe = false, KeepUnknownEntities = true, KeepUnknownNonGraphicalObjects = true };
        reader.OnNotification += (sender, e) =>
        {
            if (IsIdenticalDuplicate(reader, e))
                notify(sender, new NotificationEventArgs(
                    "같은 이름과 RGB 값을 가진 중복 별색 정의를 하나로 정리했습니다. 표시 색상은 유지되며 원본 DWG는 별도로 보존됩니다.",
                    NotificationType.Warning));
            else notify(sender, e);
        };
        return reader.Read();
    }

    private static bool IsIdenticalDuplicate(DwgReader reader, NotificationEventArgs e)
    {
        const string prefix = "Error when trying to add the entry ";
        const string separator = " to ACAD_COLOR|";
        if (e.NotificationType != NotificationType.Error || e.Exception is not ArgumentException ||
            !e.Message.StartsWith(prefix, StringComparison.Ordinal)) return false;
        var end = e.Message.LastIndexOf(separator, StringComparison.Ordinal);
        if (end < prefix.Length || !ulong.TryParse(e.Message[(end + separator.Length)..], out var dictionaryHandle)) return false;
        var name = e.Message[prefix.Length..end];
        // The pinned dependency has no public API for raw objects before the
        // dictionary is built. If its internals change, this fails closed.
        var builder = typeof(DwgReader).GetField("_builder", BindingFlags.Instance | BindingFlags.NonPublic)?.GetValue(reader);
        if (builder?.GetType().GetField("cadObjects", BindingFlags.Instance | BindingFlags.NonPublic)?.GetValue(builder) is not IDictionary objects ||
            objects[dictionaryHandle] is not CadDictionary dictionary || dictionary.Name != "ACAD_COLOR") return false;
        var matches = objects.Values.Cast<object>().OfType<BookColor>()
            .Where(color => string.Equals(color.Name, name, StringComparison.OrdinalIgnoreCase)).ToList();
        return matches.Count > 1 && matches.All(color => color.Color.Equals(matches[0].Color)) &&
            dictionary.Any(color => color is BookColor book && string.Equals(book.Name, name, StringComparison.OrdinalIgnoreCase) && book.Color.Equals(matches[0].Color));
    }
}
