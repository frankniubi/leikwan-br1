using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Reads Netch mode files without depending on Netch at runtime.  Netch uses
/// one process pattern per line in text modes and a ProcessMode JSON object
/// with a <c>handle</c> array; both are normalized to the local profile model.
/// </summary>
public static class WindowsNetchModeImporter
{
    public const int MaxProfiles = 512;
    public const int MaxPatternsPerProfile = 64;
    public const long MaxFileBytes = 2 * 1024 * 1024;

    public static IReadOnlyList<WindowsProxyGameProfile> Load(string modeDirectory)
    {
        if (string.IsNullOrWhiteSpace(modeDirectory) || !Directory.Exists(modeDirectory))
            throw new DirectoryNotFoundException("netch_mode_directory_missing");

        var result = new List<WindowsProxyGameProfile>();
        IEnumerable<string> files;
        try
        {
            files = Directory.EnumerateFiles(modeDirectory, "*.*", SearchOption.AllDirectories)
                .Where(path => string.Equals(Path.GetExtension(path), ".txt", StringComparison.OrdinalIgnoreCase)
                    || string.Equals(Path.GetExtension(path), ".json", StringComparison.OrdinalIgnoreCase))
                .OrderBy(path => path, StringComparer.OrdinalIgnoreCase)
                .Take(MaxProfiles * 2)
                .ToList();
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            throw new InvalidOperationException("netch_mode_directory_unreadable", exception);
        }

        foreach (var file in files)
        {
            if (result.Count >= MaxProfiles)
                break;
            try
            {
                var profile = ParseFile(modeDirectory, file);
                if (profile is not null)
                    result.Add(profile);
            }
            catch (IOException) { }
            catch (UnauthorizedAccessException) { }
            catch (JsonException) { }
        }
        return result;
    }

    private static WindowsProxyGameProfile? ParseFile(string root, string path)
    {
        var info = new FileInfo(path);
        if (!info.Exists || info.Length <= 0 || info.Length > MaxFileBytes)
            return null;

        var extension = Path.GetExtension(path);
        var relative = Path.GetRelativePath(root, path).Replace('\\', '/');
        if (relative.StartsWith("TUNTAP/", StringComparison.OrdinalIgnoreCase))
            return null;
        var id = "netch:" + Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(relative.ToLowerInvariant())))[..16].ToLowerInvariant();
        if (string.Equals(Path.GetFileNameWithoutExtension(path), "Global", StringComparison.OrdinalIgnoreCase))
            return null;

        return string.Equals(extension, ".json", StringComparison.OrdinalIgnoreCase)
            ? ParseJson(id, path, info)
            : ParseText(id, path, info);
    }

    private static WindowsProxyGameProfile? ParseText(string id, string path, FileInfo info)
    {
        var displayName = Path.GetFileNameWithoutExtension(path);
        var patterns = new List<string>();
        foreach (var raw in File.ReadLines(path, Encoding.UTF8))
        {
            var line = raw.Trim();
            if (line.Length == 0)
                continue;
            if (line.StartsWith("#include", StringComparison.OrdinalIgnoreCase))
                continue;
            if (line.StartsWith('#'))
            {
                if (line.Length > 1 && !char.IsWhiteSpace(line[1]))
                    continue;
                if (displayName.Equals(Path.GetFileNameWithoutExtension(path), StringComparison.Ordinal))
                    displayName = ParseCommentName(line) ?? displayName;
                continue;
            }
            AddPattern(patterns, line);
            if (patterns.Count >= MaxPatternsPerProfile)
                break;
        }

        return Create(id, displayName, patterns, source: "netch");
    }

    private static WindowsProxyGameProfile? ParseJson(string id, string path, FileInfo info)
    {
        using var stream = File.OpenRead(path);
        using var document = JsonDocument.Parse(stream);
        var root = document.RootElement;
        if (root.ValueKind != JsonValueKind.Object
            || (root.TryGetProperty("type", out var type)
                && !string.Equals(type.GetString(), "ProcessMode", StringComparison.OrdinalIgnoreCase)))
            return null;

        var displayName = Path.GetFileNameWithoutExtension(path);
        if (root.TryGetProperty("remark", out var remark) && remark.ValueKind == JsonValueKind.Object)
        {
            displayName = ReadRemark(remark) ?? displayName;
        }

        if (!root.TryGetProperty("handle", out var handle) || handle.ValueKind != JsonValueKind.Array)
            return null;
        var patterns = new List<string>();
        foreach (var item in handle.EnumerateArray())
        {
            if (item.ValueKind != JsonValueKind.String)
                continue;
            AddPattern(patterns, item.GetString());
            if (patterns.Count >= MaxPatternsPerProfile)
                break;
        }
        return Create(id, displayName, patterns, source: "netch");
    }

    private static WindowsProxyGameProfile? Create(
        string id,
        string? displayName,
        IEnumerable<string> patterns,
        string source)
    {
        var normalizedPatterns = patterns
            .Select(pattern => pattern?.Trim() ?? string.Empty)
            .Where(pattern => pattern.Length > 0 && pattern.Length <= 512 && !pattern.Any(char.IsControl))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Take(MaxPatternsPerProfile)
            .ToList();
        normalizedPatterns.RemoveAll(pattern => pattern.StartsWith("include <", StringComparison.OrdinalIgnoreCase));
        if (normalizedPatterns.Count == 0 || normalizedPatterns.All(IsNonProcessPattern))
            return null;

        var name = string.IsNullOrWhiteSpace(displayName) ? id : displayName.Trim();
        if (name.Length > 120)
            name = name[..120];
        return new WindowsProxyGameProfile
        {
            Id = id,
            DisplayName = name,
            ProcessPatterns = normalizedPatterns,
            PatternsAreRegex = true,
            Source = source,
        };
    }

    private static void AddPattern(ICollection<string> patterns, string? value)
    {
        var normalized = value?.Trim() ?? string.Empty;
        if (normalized.Length == 0 || normalized.Length > 512 || normalized.Any(char.IsControl))
            return;
        if (!patterns.Contains(normalized, StringComparer.OrdinalIgnoreCase))
            patterns.Add(normalized);
    }

    private static bool IsNonProcessPattern(string pattern)
    {
        if (pattern.StartsWith("--", StringComparison.Ordinal))
            return true;
        var slash = pattern.LastIndexOf('/');
        if (slash <= 0 || slash == pattern.Length - 1)
            return false;
        return pattern[..slash].Count(ch => ch == '.') == 3
            && int.TryParse(pattern[(slash + 1)..], out var prefix)
            && prefix is >= 0 and <= 128;
    }

    private static string? ParseCommentName(string line)
    {
        var value = line.TrimStart('#', ' ', '\t');
        var comma = value.IndexOf(',');
        if (comma >= 0)
            value = value[..comma];
        return string.IsNullOrWhiteSpace(value) ? null : value.Trim();
    }

    private static string? ReadRemark(JsonElement remark)
    {
        foreach (var key in new[] { "zh-CN", "zh_cn", "en-US", "en_us" })
        {
            if (remark.TryGetProperty(key, out var value) && value.ValueKind == JsonValueKind.String
                && !string.IsNullOrWhiteSpace(value.GetString()))
                return value.GetString()!.Trim();
        }
        foreach (var property in remark.EnumerateObject())
        {
            if (property.Value.ValueKind == JsonValueKind.String
                && !string.IsNullOrWhiteSpace(property.Value.GetString()))
                return property.Value.GetString()!.Trim();
        }
        return null;
    }
}
