using System.Text.Json;

namespace LeikwanBridge.Mac.Services;

/// <summary>Cumulative byte counters of one runtime status file.</summary>
/// <param name="BytesIn">Bytes received from the relay (download).</param>
/// <param name="BytesOut">Bytes sent to the relay (upload).</param>
public readonly record struct TrafficCounter(long BytesIn, long BytesOut);

/// <summary>
/// Reads the cumulative counters the bundled ultra-client publishes in its status
/// files: ordinary Ultra forwards under <c>{state}/runtime/ultra-*/status.json</c>
/// (plus the legacy flat <c>ultra-forward-*.status.json</c>) and native outbound
/// relays under <c>{runtime}/native-usage/native-*/status.json</c>. Each file is a
/// separate stream, keyed by its path, so a restarted runtime starts a new key
/// instead of looking like a counter that went backwards.
/// </summary>
public sealed class MacTrafficCounterReader
{
    private const int MaxFiles = 64;
    private const long MaxStatusBytes = 64 * 1024;
    private readonly Func<string?> _stateDirectory;
    private readonly string? _runtimeDirectory;

    public MacTrafficCounterReader(Func<string?> stateDirectory, string? runtimeDirectory)
    {
        _stateDirectory = stateDirectory;
        _runtimeDirectory = runtimeDirectory;
    }

    public IReadOnlyDictionary<string, TrafficCounter> Read()
    {
        var result = new Dictionary<string, TrafficCounter>(StringComparer.Ordinal);
        foreach (var path in EnumerateStatusFiles().Take(MaxFiles))
        {
            if (TryReadCounter(path, out var counter))
                result[path] = counter;
        }
        return result;
    }

    private IEnumerable<string> EnumerateStatusFiles()
    {
        var files = new List<string>();
        var stateDirectory = SafeInvoke(_stateDirectory);
        if (!string.IsNullOrWhiteSpace(stateDirectory))
        {
            var runtime = Path.Combine(stateDirectory, "runtime");
            files.AddRange(SafeEnumerate(() => Directory.EnumerateFiles(runtime, "ultra-forward-*.status.json")));
            files.AddRange(SafeEnumerate(() => Directory.EnumerateDirectories(runtime, "ultra-*")
                .Select(directory => Path.Combine(directory, "status.json"))));
        }
        if (!string.IsNullOrWhiteSpace(_runtimeDirectory))
        {
            var nativeUsage = Path.Combine(_runtimeDirectory, "native-usage");
            files.AddRange(SafeEnumerate(() => Directory.EnumerateDirectories(nativeUsage, "native-*")
                .Select(directory => Path.Combine(directory, "status.json"))));
        }
        return files.Distinct(StringComparer.Ordinal);
    }

    public static bool TryReadCounter(string path, out TrafficCounter counter)
    {
        counter = default;
        try
        {
            var info = new FileInfo(path);
            if (!info.Exists || info.Length is <= 0 or > MaxStatusBytes)
                return false;
            using var document = JsonDocument.Parse(File.ReadAllBytes(path));
            var root = document.RootElement;
            if (root.ValueKind != JsonValueKind.Object)
                return false;
            counter = new TrafficCounter(ReadCounter(root, "bytes_in"), ReadCounter(root, "bytes_out"));
            return true;
        }
        catch (Exception exception) when (
            exception is IOException or UnauthorizedAccessException or JsonException
                or InvalidOperationException or FormatException)
        {
            // Status files are rewritten in place; skip one caught mid-write.
            return false;
        }
    }

    private static long ReadCounter(JsonElement root, string name)
        => root.TryGetProperty(name, out var value) && value.TryGetInt64(out var number) ? Math.Max(0, number) : 0;

    private static string? SafeInvoke(Func<string?> provider)
    {
        try
        {
            return provider();
        }
        catch
        {
            return null;
        }
    }

    private static IEnumerable<string> SafeEnumerate(Func<IEnumerable<string>> enumerate)
    {
        try
        {
            return enumerate().ToList();
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            return [];
        }
    }
}
