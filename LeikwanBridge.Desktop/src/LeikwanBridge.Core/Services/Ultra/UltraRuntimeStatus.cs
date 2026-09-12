using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services.Ultra;

/// <summary>
/// Reads the non-secret Ultra runtime status snapshot (the file the runtime
/// config names through "status_path").  The runtime measures the UDP payload
/// ceiling of its active relay association, counts the datagrams it had to
/// refuse, and reports the identity of its own binary;
/// <see cref="UltraRuntimeMtu"/> turns the ceiling into the MTU the host gives
/// the TUN.
/// </summary>
public static class UltraRuntimeStatus
{
    /// <summary>Largest datagram payload the active association carried.</summary>
    public const string PayloadCeilingProperty = "udp_payload_ceiling";

    /// <summary>Datagrams refused because they exceeded that ceiling.</summary>
    public const string OversizeDropsProperty = "udp_oversize_drops";

    /// <summary>Identity of the running helper binary.</summary>
    public const string AttestationProperty = "runtime_attestation";

    /// <summary>Build identity of a locally built (unstamped) helper.</summary>
    public const string DevelopmentFingerprint = "development";

    /// <summary>Status snapshots are small JSON documents; refuse anything larger.</summary>
    private const long MaximumStatusFileBytes = 64 * 1024;

    /// <summary>
    /// Reads "udp_payload_ceiling" from one status snapshot.  Returns 0 when the
    /// file is missing, unreadable, malformed, or carries no positive ceiling;
    /// 0 means "not measured (yet)" or "association unusable", and both leave the
    /// configured MTU untouched.
    /// </summary>
    public static int ReadPayloadCeiling(string? statusPath)
        => TryReadStatusRoot(statusPath, out var root) ? ReadPayloadCeiling(root) : 0;

    /// <summary>Reads "udp_payload_ceiling" from an already parsed snapshot.</summary>
    public static int ReadPayloadCeiling(JsonElement statusRoot)
        => TryReadPositiveInt(statusRoot, PayloadCeilingProperty, out var ceiling) ? ceiling : 0;

    /// <summary>
    /// Reads "udp_oversize_drops".  Returns 0 when the snapshot does not carry a
    /// positive counter, so an absent field is never reported as measured data.
    /// </summary>
    public static long ReadOversizeDrops(string? statusPath)
        => TryReadStatusRoot(statusPath, out var root) ? ReadOversizeDrops(root) : 0;

    /// <summary>Reads "udp_oversize_drops" from an already parsed snapshot.</summary>
    public static long ReadOversizeDrops(JsonElement statusRoot)
        => TryReadPositiveLong(statusRoot, OversizeDropsProperty, out var drops) ? drops : 0;

    /// <summary>
    /// Reads "runtime_attestation", or null when the snapshot does not carry one
    /// or carries one that does not match the published shape.  A malformed
    /// object is dropped as a whole: the panel must never be shown a repaired
    /// identity.
    /// </summary>
    public static RuntimeAttestationSnapshot? ReadAttestation(string? statusPath)
        => TryReadStatusRoot(statusPath, out var root) ? ReadAttestation(root) : null;

    /// <summary>Reads "runtime_attestation" from an already parsed snapshot.</summary>
    public static RuntimeAttestationSnapshot? ReadAttestation(JsonElement statusRoot)
    {
        if (statusRoot.ValueKind != JsonValueKind.Object
            || !statusRoot.TryGetProperty(AttestationProperty, out var attestation)
            || attestation.ValueKind != JsonValueKind.Object)
            return null;

        // A released runtime stamps itself "<version>.<shortsha>" (the build
        // scripts inject main.runtimeBuildVersion); an older or third-party build
        // may report a bare hex build id. Both are identities; anything else
        // cannot be compared against a release and is dropped.
        if (!attestation.TryGetProperty("fingerprint", out var fingerprintElement)
            || fingerprintElement.ValueKind != JsonValueKind.String)
            return null;
        var fingerprint = fingerprintElement.GetString() ?? "";
        if (fingerprint != DevelopmentFingerprint && !IsBuildFingerprint(fingerprint))
            return null;

        var result = new RuntimeAttestationSnapshot { Fingerprint = fingerprint };
        if (attestation.TryGetProperty("self_sha256", out var selfHashElement))
        {
            // Present but unusable makes the whole identity unusable.
            if (selfHashElement.ValueKind != JsonValueKind.String
                || !IsLowerHex(selfHashElement.GetString() ?? "", 64, 64))
                return null;
            result.SelfSha256 = selfHashElement.GetString();
        }
        if (!TryReadOptionalBool(attestation, "debugger", out var debugger))
            return null;
        if (!TryReadOptionalBool(attestation, "instrumented", out var instrumented))
            return null;
        result.Debugger = debugger;
        result.Instrumented = instrumented;
        return result;
    }

    /// <summary>
    /// Status snapshots that can describe a forward's UDP path, newest first:
    /// the native relay instances
    /// ("&lt;runtimeDirectory&gt;/native-usage/native-&lt;forwardId&gt;-*/status.json") and the
    /// ordinary Ultra runtime file
    /// ("&lt;runtimeDirectory&gt;/ultra-forward-&lt;forwardId&gt;.status.json").
    /// </summary>
    public static IEnumerable<string> EnumerateForwardStatusPaths(string? runtimeDirectory, int forwardId)
    {
        if (forwardId <= 0 || string.IsNullOrWhiteSpace(runtimeDirectory))
            yield break;

        var nativeUsageRoot = Path.Combine(runtimeDirectory, "native-usage");
        List<(string Path, DateTime LastWrite)> instances = [];
        try
        {
            if (Directory.Exists(nativeUsageRoot))
            {
                foreach (var directory in Directory.EnumerateDirectories(
                    nativeUsageRoot, $"native-{forwardId}-*", SearchOption.TopDirectoryOnly))
                {
                    var statusPath = Path.Combine(directory, "status.json");
                    if (File.Exists(statusPath))
                        instances.Add((statusPath, File.GetLastWriteTimeUtc(statusPath)));
                }
            }
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            instances.Clear();
        }

        foreach (var instance in instances.OrderByDescending(item => item.LastWrite))
            yield return instance.Path;
        yield return Path.Combine(runtimeDirectory, $"ultra-forward-{forwardId}.status.json");
    }

    /// <summary>
    /// Payload ceiling a forward's newest measured snapshot reports, or 0 when no
    /// snapshot measured one.
    /// </summary>
    public static int ReadForwardPayloadCeiling(string? runtimeDirectory, int forwardId)
    {
        foreach (var statusPath in EnumerateForwardStatusPaths(runtimeDirectory, forwardId))
        {
            var ceiling = ReadPayloadCeiling(statusPath);
            if (ceiling > 0)
                return ceiling;
        }
        return 0;
    }

    private static bool TryReadStatusRoot(string? statusPath, out JsonElement root)
    {
        root = default;
        if (string.IsNullOrWhiteSpace(statusPath))
            return false;
        try
        {
            var info = new FileInfo(statusPath);
            if (!info.Exists || info.Length is <= 0 or > MaximumStatusFileBytes)
                return false;
            using var document = JsonDocument.Parse(File.ReadAllBytes(statusPath));
            if (document.RootElement.ValueKind != JsonValueKind.Object)
                return false;
            root = document.RootElement.Clone();
            return true;
        }
        catch (Exception exception) when (exception is IOException
            or UnauthorizedAccessException
            or JsonException
            or FormatException
            or OverflowException)
        {
            return false;
        }
    }

    private static bool TryReadPositiveInt(JsonElement statusRoot, string property, out int value)
    {
        value = 0;
        return statusRoot.ValueKind == JsonValueKind.Object
            && statusRoot.TryGetProperty(property, out var element)
            && element.ValueKind == JsonValueKind.Number
            && element.TryGetInt32(out value)
            && value > 0;
    }

    private static bool TryReadPositiveLong(JsonElement statusRoot, string property, out long value)
    {
        value = 0;
        return statusRoot.ValueKind == JsonValueKind.Object
            && statusRoot.TryGetProperty(property, out var element)
            && element.ValueKind == JsonValueKind.Number
            && element.TryGetInt64(out value)
            && value > 0;
    }

    private static bool TryReadOptionalBool(JsonElement attestation, string property, out bool? value)
    {
        value = null;
        if (!attestation.TryGetProperty(property, out var element))
            return true;
        if (element.ValueKind is not (JsonValueKind.True or JsonValueKind.False))
            return false;
        value = element.GetBoolean();
        return true;
    }

    private static bool IsLowerHex(string value, int minimum, int maximum)
        => value.Length >= minimum && value.Length <= maximum
            && value.All(character => character is >= '0' and <= '9' or >= 'a' and <= 'f');

    /// <summary>
    /// Accepts the released build fingerprint shape "2026.09.12.1.4b106b02" (the
    /// value the build scripts inject) as well as a bare hex build id used by
    /// older builds. Nothing else is treated as an identity.
    /// </summary>
    private static bool IsBuildFingerprint(string value)
    {
        if (IsLowerHex(value, 8, 64))
            return true;
        var segments = value.Split('.');
        if (segments.Length is not (4 or 5))
            return false;
        for (var index = 0; index < 4; index++)
        {
            if (segments[index].Length == 0 || !segments[index].All(character => character is >= '0' and <= '9'))
                return false;
        }
        return segments.Length == 4 || IsLowerHex(segments[4], 7, 40);
    }
}
