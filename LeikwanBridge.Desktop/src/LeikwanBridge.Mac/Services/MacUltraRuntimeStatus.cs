using System.Text.Json;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// Payload ceiling the bundled ultra-client last measured for its active relay
/// association. The runtime omits <c>udp_payload_ceiling</c> while the association
/// is unusable (payloadLimit() == 0), so a missing field, a malformed status file
/// and an unusable association all read back as "not measured" (0).
/// </summary>
public readonly record struct MacUltraRuntimeStatus(int PayloadCeiling, long OversizeDrops)
{
    public static MacUltraRuntimeStatus Unknown { get; } = new(0, 0);
}

public static class MacUltraRuntimeStatusReader
{
    /// <summary>Runtime status files are rewritten in place and stay small.</summary>
    private const long MaxStatusBytes = 64 * 1024;

    public static MacUltraRuntimeStatus Read(string? statusPath)
    {
        if (string.IsNullOrWhiteSpace(statusPath))
            return MacUltraRuntimeStatus.Unknown;
        try
        {
            var info = new FileInfo(statusPath);
            if (!info.Exists || info.Length is <= 0 or > MaxStatusBytes)
                return MacUltraRuntimeStatus.Unknown;
            using var document = JsonDocument.Parse(File.ReadAllBytes(statusPath));
            var root = document.RootElement;
            if (root.ValueKind != JsonValueKind.Object)
                return MacUltraRuntimeStatus.Unknown;
            var ceiling = root.TryGetProperty("udp_payload_ceiling", out var ceilingElement)
                && ceilingElement.TryGetInt32(out var ceilingValue)
                ? Math.Clamp(ceilingValue, 0, ushort.MaxValue)
                : 0;
            var drops = root.TryGetProperty("udp_oversize_drops", out var dropsElement)
                && dropsElement.TryGetInt64(out var dropCount)
                ? Math.Max(0, dropCount)
                : 0;
            return new MacUltraRuntimeStatus(ceiling, drops);
        }
        catch (Exception exception) when (
            exception is IOException
                or UnauthorizedAccessException
                or JsonException
                or InvalidOperationException
                or FormatException)
        {
            // A concurrent rewrite is not an error; fall back to "not measured".
            return MacUltraRuntimeStatus.Unknown;
        }
    }

    public static int ReadPayloadCeiling(string? statusPath) => Read(statusPath).PayloadCeiling;

    /// <summary>
    /// Newest status file the native relay wrote for <paramref name="forwardId"/>.
    /// LeikwanBridge.Core's NativeOutboundRelayHost keeps that snapshot outside its
    /// private runtime directory ({runtimeDirectory}/native-usage/native-{forwardId}-{instance})
    /// so the counters survive a stop; the newest instance is the live association.
    /// </summary>
    public static string? FindNativeRelayStatusPath(string? runtimeDirectory, int forwardId)
    {
        if (string.IsNullOrWhiteSpace(runtimeDirectory) || forwardId <= 0)
            return null;
        var usageRoot = Path.Combine(Path.GetFullPath(runtimeDirectory), "native-usage");
        if (!Directory.Exists(usageRoot))
            return null;
        try
        {
            string? newest = null;
            var newestWrite = DateTime.MinValue;
            foreach (var directory in Directory.EnumerateDirectories(
                usageRoot,
                $"native-{forwardId}-*",
                SearchOption.TopDirectoryOnly))
            {
                var candidate = Path.Combine(directory, "status.json");
                var info = new FileInfo(candidate);
                if (!info.Exists || info.LastWriteTimeUtc <= newestWrite)
                    continue;
                newest = candidate;
                newestWrite = info.LastWriteTimeUtc;
            }
            return newest;
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            return null;
        }
    }
}
