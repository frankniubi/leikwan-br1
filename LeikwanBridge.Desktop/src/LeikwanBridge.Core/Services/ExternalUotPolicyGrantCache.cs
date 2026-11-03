using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Memory-only sidecar material from configs/pull. Some panels attach it to
/// ultra_access, not to each provider lease. Scope it to the exact policy and
/// clone it into leases; never serialize it with the persisted forward model.
/// </summary>
public sealed class ExternalUotPolicyGrantCache : IDisposable
{
    private sealed record Entry(string Generation, string Digest, DateTimeOffset Expires,
        bool Required, ExternalUotGrant? Grant);

    private readonly object _gate = new();
    private Dictionary<int, Entry> _entries = [];
    private bool _hasSnapshot;
    private bool _disposed;

    public void Replace(PullResponse response, JsonElement root)
    {
        var next = new Dictionary<int, Entry>();
        if (root.TryGetProperty("forwards", out var items) && items.ValueKind == JsonValueKind.Array)
        {
            foreach (var item in items.EnumerateArray())
            {
                if (!item.TryGetProperty("forward_id", out var id) || !id.TryGetInt32(out var forwardId))
                    continue;
                var policy = response.Forwards.FirstOrDefault(f => f.ForwardId == forwardId)?.UltraAccess;
                if (policy is null)
                    continue;
                var required = false;
                ExternalUotGrant? grant = null;
                if (item.TryGetProperty("ultra_access", out var access) &&
                    access.TryGetProperty("external_uot", out var wire) &&
                    wire.ValueKind == JsonValueKind.Object &&
                    wire.TryGetProperty("enabled", out var enabled) && enabled.ValueKind == JsonValueKind.True)
                {
                    required = true;
                    grant = ExternalUotGrant.TryParse(wire, out _);
                    if (wire.TryGetProperty("target_digest", out var digest) &&
                        (digest.ValueKind != JsonValueKind.String ||
                         !string.Equals(digest.GetString(), policy.TargetDigest, StringComparison.OrdinalIgnoreCase)))
                    {
                        grant?.Dispose();
                        grant = null;
                    }
                }
                if (next.TryGetValue(forwardId, out var duplicate))
                    duplicate.Grant?.Dispose();
                next[forwardId] = new Entry(policy.PolicyGeneration, policy.TargetDigest,
                    policy.ExpiresAt, required, grant);
            }
        }
        lock (_gate)
        {
            if (_disposed)
            {
                foreach (var entry in next.Values) entry.Grant?.Dispose();
                return;
            }
            // ConfigPollingService can reuse an unexpired policy omitted by a
            // transient pull for a still-running, unchanged target. Keep its
            // memory-only grant under the same expiry and digest constraints.
            // A removed forward or an explicit replacement never inherits it.
            foreach (var forward in response.Forwards)
            {
                if (forward.UltraAccess is not null || next.ContainsKey(forward.ForwardId) ||
                    !_entries.TryGetValue(forward.ForwardId, out var previous) ||
                    previous.Expires <= DateTimeOffset.UtcNow ||
                    !string.Equals(previous.Digest, UltraPolicy.ComputeTargetDigest(forward.Target),
                        StringComparison.OrdinalIgnoreCase))
                    continue;
                next[forward.ForwardId] = previous with { Grant = Clone(previous.Grant) };
            }
            foreach (var entry in _entries.Values) entry.Grant?.Dispose();
            _entries = next;
            _hasSnapshot = true;
        }
    }

    public bool TryAttach(ForwardConfig forward, UltraLease lease)
    {
        lock (_gate)
        {
            if (_disposed)
                return false;
            if (!_hasSnapshot)
                return true; // compatibility for callers supplied a lease-only contract
            if (!_entries.TryGetValue(forward.ForwardId, out var entry) ||
                forward.UltraAccess is not { } policy ||
                entry.Expires <= DateTimeOffset.UtcNow ||
                entry.Generation != policy.PolicyGeneration ||
                !string.Equals(entry.Digest, policy.TargetDigest, StringComparison.OrdinalIgnoreCase) ||
                !string.Equals(entry.Digest, UltraPolicy.ComputeTargetDigest(forward.Target), StringComparison.OrdinalIgnoreCase) ||
                lease.ForwardId != forward.ForwardId ||
                lease.PolicyGeneration != policy.PolicyGeneration ||
                !string.Equals(lease.TargetDigest, entry.Digest, StringComparison.OrdinalIgnoreCase))
                return false;
            if (!entry.Required)
                return true;
            if (entry.Grant is not { } source)
                return false; // enabled but malformed must not become native UDP
            lease.ExternalUot ??= Clone(source);
            return true;
        }
    }

    private static ExternalUotGrant? Clone(ExternalUotGrant? source) => source is null ? null : new()
    {
        SidecarTarget = source.SidecarTarget,
        Token = source.Token.ToArray(),
        QueuePackets = source.QueuePackets,
        KeepaliveSeconds = source.KeepaliveSeconds,
        ReconnectGraceSeconds = source.ReconnectGraceSeconds,
        FramePayloadMax = source.FramePayloadMax,
        DeliveryMode = source.DeliveryMode,
    };

    public void Dispose()
    {
        lock (_gate)
        {
            _disposed = true;
            foreach (var entry in _entries.Values) entry.Grant?.Dispose();
            _entries.Clear();
        }
    }
}
