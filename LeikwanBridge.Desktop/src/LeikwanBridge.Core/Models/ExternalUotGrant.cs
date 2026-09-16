using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

/// <summary>
/// Operator-owned UDP-over-TCP sidecar grant carried by an Ultra lease
/// ("external_uot" in the master API grant). Mirrors the runtime contract in
/// tools/ultra-client/external_uot.go: the relay stream is handed to the
/// sidecar named by <see cref="SidecarTarget"/> and authenticated with
/// <see cref="Token"/>.
///
/// This is provider-material-equivalent: every StateManager serializer ignores
/// it, the token bytes are zeroed on <see cref="Dispose"/> (which
/// <see cref="UltraLease.Dispose"/> calls), and neither the token nor the
/// target may be written to a log.
/// </summary>
public sealed class ExternalUotGrant : IDisposable
{
    /// <summary>Runtime contract cap on the sidecar token (external_uot.go: maxTokenBytes).</summary>
    public const int MaxTokenBytes = 8192;

    /// <summary>Runtime contract cap on the sidecar endpoint string (external_uot.go: maxTargetLength).</summary>
    public const int MaxTargetLength = 512;

    /// <summary>Runtime-accepted windows for the sidecar knobs (external_uot.go: normalized()).
    /// Values outside a window mean "use the sidecar default".</summary>
    public const int MinQueuePackets = 1;
    public const int MaxQueuePackets = 4096;
    public const int MinKeepaliveSeconds = 5;
    public const int MaxKeepaliveSeconds = 30;
    public const int MinReconnectGraceSeconds = 5;
    public const int MaxReconnectGraceSeconds = 300;
    public const int MinFramePayloadMax = 256;
    public const int MaxFramePayloadMax = 65507;

    /// <summary>Sidecar endpoint the relay stream is handed to ("host:port" or "[v6]:port").</summary>
    [JsonIgnore] public string SidecarTarget { get; set; } = "";

    /// <summary>Shared secret for the sidecar handshake. Zeroed on dispose.</summary>
    [JsonIgnore] public byte[] Token { get; init; } = [];

    /// <summary>Operator override; 0 means "use the sidecar default".</summary>
    [JsonIgnore] public int QueuePackets { get; init; }

    [JsonIgnore] public int KeepaliveSeconds { get; init; }

    [JsonIgnore] public int ReconnectGraceSeconds { get; init; }

    [JsonIgnore] public int FramePayloadMax { get; init; }

    /// <summary>
    /// Parses the optional "external_uot" grant.
    /// <para>
    /// A missing object, a non-object, or enabled != true yields null with an
    /// empty <paramref name="failure"/>: the runtime must stay on its legacy UoT
    /// path without any diagnostic noise. An enabled but unusable grant also
    /// yields null, but reports why through <paramref name="failure"/> so the
    /// caller can warn without ever logging the token.
    /// </para>
    /// </summary>
    public static ExternalUotGrant? TryParse(JsonElement item, out string failure)
    {
        failure = "";
        if (item.ValueKind != JsonValueKind.Object)
            return null;
        if (!item.TryGetProperty("enabled", out var enabledElement)
            || enabledElement.ValueKind != JsonValueKind.True)
            return null;

        var target = (ReadString(item, "sidecar_target") ?? "").Trim();
        if (target.Length == 0)
        {
            failure = "sidecar_target_missing";
            return null;
        }
        if (target.Length > MaxTargetLength || !TryParseSidecarTarget(target, out _, out _))
        {
            failure = "sidecar_target_invalid";
            return null;
        }

        var token = ReadString(item, "token") ?? "";
        if (token.Length == 0)
        {
            failure = "token_missing";
            return null;
        }
        if (token.Contains('\0'))
        {
            failure = "token_invalid";
            return null;
        }
        var tokenBytes = Encoding.UTF8.GetBytes(token);
        if (tokenBytes.Length > MaxTokenBytes)
        {
            failure = "token_too_long";
            return null;
        }

        return new ExternalUotGrant
        {
            SidecarTarget = target,
            Token = tokenBytes,
            // Knobs outside the runtime windows stay 0 so the runtime normalizes
            // them back to its own defaults (external_uot.go normalized()).
            QueuePackets = ReadInt(item, "queue_packets", MinQueuePackets, MaxQueuePackets),
            KeepaliveSeconds = ReadInt(item, "keepalive_seconds", MinKeepaliveSeconds, MaxKeepaliveSeconds),
            ReconnectGraceSeconds = ReadInt(item, "reconnect_grace_seconds", MinReconnectGraceSeconds, MaxReconnectGraceSeconds),
            FramePayloadMax = ReadInt(item, "frame_payload_max", MinFramePayloadMax, MaxFramePayloadMax),
        };
    }

    /// <summary>
    /// Splits a sidecar endpoint the same way the runtime does
    /// (net.SplitHostPort): "host:port" with a colon-free host, or the bracketed
    /// "[v6]:port" form. A bare IPv6 literal without brackets is rejected.
    /// </summary>
    public static bool TryParseSidecarTarget(string value, out string host, out int port)
    {
        host = "";
        port = 0;
        if (string.IsNullOrEmpty(value))
            return false;

        string hostPart;
        string portPart;
        if (value[0] == '[')
        {
            var closing = value.IndexOf(']');
            if (closing < 2 || closing + 1 >= value.Length || value[closing + 1] != ':')
                return false;
            hostPart = value[1..closing];
            portPart = value[(closing + 2)..];
        }
        else
        {
            var separator = value.LastIndexOf(':');
            if (separator <= 0)
                return false;
            hostPart = value[..separator];
            portPart = value[(separator + 1)..];
            if (hostPart.Contains(':'))
                return false;
        }

        if (hostPart.Length is 0 or > 255 || portPart.Length is 0 or > 5)
            return false;
        foreach (var character in hostPart)
        {
            if (char.IsWhiteSpace(character) || character is '[' or ']')
                return false;
        }
        var parsed = 0;
        foreach (var character in portPart)
        {
            if (character is < '0' or > '9')
                return false;
            parsed = (parsed * 10) + (character - '0');
        }
        if (parsed is < 1 or > 65535)
            return false;

        host = hostPart;
        port = parsed;
        return true;
    }

    /// <summary>
    /// Bootstrap keys for the runtime ("external_uot" in the startup config).
    /// Keys and value types mirror the Go struct's json tags; disabled grants
    /// never reach this point, so "enabled" is always true here.
    /// </summary>
    public Dictionary<string, object?> ToBootstrapPayload() => new()
    {
        ["enabled"] = true,
        ["sidecar_target"] = SidecarTarget,
        ["token"] = Encoding.UTF8.GetString(Token),
        ["queue_packets"] = QueuePackets,
        ["keepalive_seconds"] = KeepaliveSeconds,
        ["reconnect_grace_seconds"] = ReconnectGraceSeconds,
        ["frame_payload_max"] = FramePayloadMax,
    };

    /// <summary>
    /// Non-reversible fingerprint used by the forward contract signature. The
    /// applied-state signature is written to disk, so it must never contain the
    /// token itself.
    /// </summary>
    public string TokenFingerprint()
        => Convert.ToHexString(SHA256.HashData(Token)).ToLowerInvariant();

    /// <summary>
    /// The grant a runtime bootstrap should carry: the panel attaches one
    /// operator grant per forward and repeats it on every candidate, so the
    /// first grant wins. Null keeps the runtime on its legacy UoT path.
    /// </summary>
    public static ExternalUotGrant? Select(IEnumerable<UltraLease> leases)
    {
        foreach (var lease in leases)
        {
            if (lease.ExternalUot is { } grant)
                return grant;
        }
        return null;
    }

    public void Dispose()
    {
        CryptographicOperations.ZeroMemory(Token);
        SidecarTarget = "";
    }

    private static string? ReadString(JsonElement item, string name)
        => item.TryGetProperty(name, out var element) && element.ValueKind == JsonValueKind.String
            ? element.GetString()
            : null;

    private static int ReadInt(JsonElement item, string name, int min, int max)
        => item.TryGetProperty(name, out var element)
            && element.ValueKind == JsonValueKind.Number
            && element.TryGetInt32(out var value)
            && value >= min
            && value <= max
                ? value
                : 0;
}
