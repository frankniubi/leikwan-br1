using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Persists local game/process profiles.  These profiles contain only process
/// names and regexes, so unlike proxy profiles they do not need DPAPI.
/// </summary>
public sealed class WindowsProxyGameProfileStore
{
    public const int MaxProfiles = 512;
    public const int MaxSerializedBytes = 1024 * 1024;

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = false,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
    };

    private readonly string _path;
    private readonly SemaphoreSlim _gate = new(1, 1);

    public WindowsProxyGameProfileStore(string path)
    {
        if (string.IsNullOrWhiteSpace(path))
            throw new ArgumentException("game profile path required", nameof(path));
        _path = path;
    }

    public async Task<IReadOnlyList<WindowsProxyGameProfile>> LoadAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try
        {
            if (!File.Exists(_path) || new FileInfo(_path).Length > MaxSerializedBytes)
                return [];
            try
            {
                var json = await File.ReadAllTextAsync(_path, ct);
                return Normalize(JsonSerializer.Deserialize<List<WindowsProxyGameProfile>>(json, JsonOptions) ?? []);
            }
            catch (JsonException)
            {
                return [];
            }
            catch (IOException)
            {
                return [];
            }
        }
        finally { _gate.Release(); }
    }

    public async Task SaveAsync(IEnumerable<WindowsProxyGameProfile> profiles, CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(profiles);
        var normalized = Normalize(profiles.ToList());
        var json = normalized.Count == 0 ? string.Empty : JsonSerializer.Serialize(normalized, JsonOptions);
        if (Encoding.UTF8.GetByteCount(json) > MaxSerializedBytes)
            throw new InvalidOperationException("proxy_game_profiles_too_large");

        await _gate.WaitAsync(ct);
        try
        {
            var directory = Path.GetDirectoryName(_path);
            if (!string.IsNullOrWhiteSpace(directory))
                Directory.CreateDirectory(directory);
            var tempPath = _path + ".tmp-" + Guid.NewGuid().ToString("N");
            try
            {
                await File.WriteAllTextAsync(tempPath, json, ct);
                File.Move(tempPath, _path, true);
            }
            finally
            {
                try { if (File.Exists(tempPath)) File.Delete(tempPath); } catch (IOException) { }
            }
        }
        finally { _gate.Release(); }
    }

    private static List<WindowsProxyGameProfile> Normalize(List<WindowsProxyGameProfile> profiles)
    {
        var result = new List<WindowsProxyGameProfile>(Math.Min(profiles.Count, MaxProfiles));
        var ids = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var source in profiles)
        {
            if (source is null)
                continue;
            var id = Bound(source.Id, 160);
            var name = Bound(source.DisplayName, 120);
            if (id.Length == 0 || name.Length == 0 || !ids.Add(id))
                continue;
            var patterns = source.ProcessPatterns
                .Where(pattern => pattern is not null)
                .Select(pattern => Bound(pattern, 512))
                .Where(pattern => pattern.Length > 0 && !pattern.Any(char.IsControl))
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .Take(WindowsNetchModeImporter.MaxPatternsPerProfile)
                .ToList();
            if (patterns.Count == 0)
                continue;
            result.Add(new WindowsProxyGameProfile
            {
                Id = id,
                DisplayName = name,
                ProcessPatterns = patterns,
                PatternsAreRegex = source.PatternsAreRegex,
                Source = Bound(string.IsNullOrWhiteSpace(source.Source) ? "custom" : source.Source, 32),
            });
            if (result.Count >= MaxProfiles)
                break;
        }
        return result;
    }

    private static string Bound(string? value, int max)
    {
        var normalized = value?.Trim() ?? string.Empty;
        return normalized.Length <= max ? normalized : normalized[..max];
    }
}
