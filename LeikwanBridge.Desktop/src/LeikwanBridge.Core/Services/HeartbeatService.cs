using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Ultra;
using Serilog;
using System.Text.Json;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Sends periodic heartbeats with usage info.
/// </summary>
public sealed class HeartbeatService
{
    private readonly MasterApiClient _api;
    private readonly StateManager _stateManager;
    private readonly string _wireguardPublicKey;
    private readonly int _wireguardListenPort;
    private readonly int _wireguardFallbackTcpPort;
    private readonly Action<IReadOnlyList<UltraPolicyAction>>? _onUltraActions;
    private readonly ClientPlatformIdentity _platformIdentity;
    private readonly IReadOnlyList<string> _additionalRuntimeDirectories;
    private readonly SemaphoreSlim _sendGate = new(1, 1);

    public HeartbeatService(
        MasterApiClient api,
        StateManager stateManager,
        string wireguardPublicKey = "",
        int wireguardListenPort = 0,
        int wireguardFallbackTcpPort = 0,
        Action<IReadOnlyList<UltraPolicyAction>>? onUltraActions = null,
        ClientPlatformIdentity? platformIdentity = null,
        IReadOnlyList<string>? additionalRuntimeDirectories = null)
    {
        _api = api;
        _stateManager = stateManager;
        _wireguardPublicKey = wireguardPublicKey;
        _wireguardListenPort = wireguardListenPort;
        _wireguardFallbackTcpPort = wireguardFallbackTcpPort;
        _onUltraActions = onUltraActions;
        _additionalRuntimeDirectories = additionalRuntimeDirectories is null
            ? []
            : additionalRuntimeDirectories
                .Where(path => !string.IsNullOrWhiteSpace(path))
                .Select(Path.GetFullPath)
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .ToArray();
        _platformIdentity = platformIdentity ?? new ClientPlatformIdentity(
            "windows-desktop",
            string.Empty,
            string.Empty,
            "Leikwan-Windows-GUI",
            MasterApiClient.AgentVersion,
            SupportsMptcp: true);
    }

    public event Action<IReadOnlyList<UltraPolicyAction>>? UltraActionsReceived;

    public async Task SendHeartbeatAsync(CancellationToken ct = default)
    {
        await _sendGate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            await SendHeartbeatCoreAsync(ct).ConfigureAwait(false);
        }
        finally
        {
            _sendGate.Release();
        }
    }

    private async Task SendHeartbeatCoreAsync(CancellationToken ct)
    {
        var usage = _stateManager.LoadUsageState();
        long bytesIn = usage.TotalBytesIn - usage.LastReportedBytesIn;
        long bytesOut = usage.TotalBytesOut - usage.LastReportedBytesOut;
        var pendingUltraState = (
            LastBytesIn: new Dictionary<int, long>(usage.UltraLastBytesIn),
            LastBytesOut: new Dictionary<int, long>(usage.UltraLastBytesOut),
            Sequences: new Dictionary<int, long>(usage.UltraSequences),
            LastBytesInByInstance: new Dictionary<string, long>(usage.UltraLastBytesInByInstance, StringComparer.OrdinalIgnoreCase),
            LastBytesOutByInstance: new Dictionary<string, long>(usage.UltraLastBytesOutByInstance, StringComparer.OrdinalIgnoreCase));
        var hasPendingUltra = usage.PendingUltraUsage.Count > 0;
        List<UltraUsageSnapshot> ultraUsage;
        if (hasPendingUltra)
        {
            // The retained batch is resent with the runtime status it was
            // collected with; a retry must not silently drop observations.
            ultraUsage = usage.PendingUltraUsage.Select(item => new UltraUsageSnapshot
            {
                ForwardId = item.ForwardId,
                PolicyGeneration = item.PolicyGeneration,
                Sequence = item.Sequence,
                BytesIn = item.BytesIn,
                BytesOut = item.BytesOut,
                UdpPayloadCeiling = item.UdpPayloadCeiling,
                UdpOversizeDrops = item.UdpOversizeDrops,
                RuntimeAttestation = item.RuntimeAttestation,
            }).ToList();
        }
        else
        {
            ultraUsage = CollectUltraUsage(usage, out pendingUltraState);
        }
        var ultraCandidateFeedback = CollectUltraCandidateFeedback();

        if (!hasPendingUltra)
        {
            usage.UltraLastBytesIn = pendingUltraState.LastBytesIn;
            usage.UltraLastBytesOut = pendingUltraState.LastBytesOut;
            usage.UltraSequences = pendingUltraState.Sequences;
            usage.UltraLastBytesInByInstance = pendingUltraState.LastBytesInByInstance;
            usage.UltraLastBytesOutByInstance = pendingUltraState.LastBytesOutByInstance;
            if (ultraUsage.Count > 0)
                usage.PendingUltraUsage = ultraUsage;
            _stateManager.SaveUsageState(usage);
        }

        var payload = new HeartbeatPayload
        {
            AgentVersion = _platformIdentity.AppVersion,
            UsageBytesIn = Math.Max(0, bytesIn),
            UsageBytesOut = Math.Max(0, bytesOut),
            SupportsMptcp = _platformIdentity.SupportsMptcp,
            WireguardPublicKey = _wireguardPublicKey,
            WireguardListenPort = _wireguardListenPort,
            WireguardFallbackTcpPort = _wireguardFallbackTcpPort,
            UltraUsage = ultraUsage,
            UltraCandidateFeedback = ultraCandidateFeedback,
        };

        var response = await _api.SendHeartbeatAsync(payload, ct);
        if (hasPendingUltra || ultraUsage.Count > 0)
        {
            // Missing usage acknowledgement uses the legacy ok contract;
            // an explicit rejection always retains the exact pending batch.
            if (!(response.UltraUsageAcknowledged ?? response.Ok))
                throw new HttpRequestException("ultra_usage_not_acknowledged");
            usage.PendingUltraUsage = [];
        }

        usage.LastReportedBytesIn = usage.TotalBytesIn;
        usage.LastReportedBytesOut = usage.TotalBytesOut;
        _stateManager.SaveUsageState(usage);
        if (response.UltraActions is { Count: > 0 })
        {
            _onUltraActions?.Invoke(response.UltraActions);
            UltraActionsReceived?.Invoke(response.UltraActions);
        }

        Log.Debug("heartbeat sent: in={BytesIn} out={BytesOut}", bytesIn, bytesOut);
    }

    private List<UltraUsageSnapshot> CollectUltraUsage(
        UsageState usage,
        out (Dictionary<int, long> LastBytesIn,
            Dictionary<int, long> LastBytesOut,
            Dictionary<int, long> Sequences,
            Dictionary<string, long> LastBytesInByInstance,
            Dictionary<string, long> LastBytesOutByInstance) pending)
    {
        var lastIn = new Dictionary<int, long>(usage.UltraLastBytesIn);
        var lastOut = new Dictionary<int, long>(usage.UltraLastBytesOut);
        var sequences = new Dictionary<int, long>(usage.UltraSequences);
        var lastInByInstance = new Dictionary<string, long>(usage.UltraLastBytesInByInstance, StringComparer.OrdinalIgnoreCase);
        var lastOutByInstance = new Dictionary<string, long>(usage.UltraLastBytesOutByInstance, StringComparer.OrdinalIgnoreCase);
        var result = new List<UltraUsageSnapshot>();
        var statusFiles = EnumerateUltraStatusFiles();

        // Ordinary Ultra status files retain the old latest-per-forward rule;
        // native lease directories are independent streams and all contribute.
        var candidates = new List<(int ForwardId, JsonElement Root, DateTimeOffset UpdatedAt, DateTime LastWrite, string InstanceKey, bool Legacy)>();
        foreach (var item in statusFiles.DistinctBy(item => item.Path, StringComparer.OrdinalIgnoreCase))
        {
            var path = item.Path;
            try
            {
                var info = new FileInfo(path);
                if (!info.Exists || info.Length > 64 * 1024)
                    continue;
                using var document = JsonDocument.Parse(File.ReadAllBytes(path));
                var root = document.RootElement;
                var forwardId = root.GetProperty("forward_id").GetInt32();
                if (forwardId <= 0)
                    continue;
                var updatedAt = DateTimeOffset.MinValue;
                if (root.TryGetProperty("updated_at", out var updatedElement)
                    && DateTimeOffset.TryParse(updatedElement.GetString(), out var parsedUpdatedAt))
                    updatedAt = parsedUpdatedAt;
                candidates.Add((forwardId, root.Clone(), updatedAt, info.LastWriteTimeUtc,
                    Path.GetFullPath(path), item.Legacy));
            }
            catch (Exception ex) when (ex is IOException or JsonException or KeyNotFoundException or FormatException or InvalidOperationException or OverflowException)
            {
                Log.Debug(ex, "skipping malformed Ultra status file {Path}", path);
            }
        }

        var selectedCandidates = candidates
            .Where(item => !item.Legacy)
            .Concat(candidates
                .Where(item => item.Legacy)
                .GroupBy(item => item.ForwardId)
                .Select(group => group
                    .OrderByDescending(item => item.UpdatedAt)
                    .ThenByDescending(item => item.LastWrite)
                    .First()))
            .OrderBy(item => item.ForwardId)
            .ThenBy(item => item.InstanceKey, StringComparer.OrdinalIgnoreCase)
            .ToList();

        var observations = new List<(
            int ForwardId,
            string Generation,
            long CurrentIn,
            long CurrentOut,
            long PreviousIn,
            long PreviousOut,
            long DeltaIn,
            long DeltaOut,
            string InstanceKey,
            bool Legacy,
            DateTime LastWrite,
            int PayloadCeiling,
            long OversizeDrops,
            RuntimeAttestationSnapshot? Attestation)>();
        foreach (var candidate in selectedCandidates)
        {
            try
            {
                var root = candidate.Root;
                var generation = root.TryGetProperty("policy_generation", out var generationElement) ? generationElement.GetString() ?? "" : "";
                var currentIn = root.TryGetProperty("bytes_in", out var inElement) && inElement.ValueKind == JsonValueKind.Number ? Math.Max(0, inElement.GetInt64()) : 0;
                var currentOut = root.TryGetProperty("bytes_out", out var outElement) && outElement.ValueKind == JsonValueKind.Number ? Math.Max(0, outElement.GetInt64()) : 0;
                var forwardId = candidate.ForwardId;
                if (forwardId <= 0 || generation.Length != 64 || !generation.All(Uri.IsHexDigit))
                    continue;

                var hasInstanceCursor = lastInByInstance.ContainsKey(candidate.InstanceKey)
                    || lastOutByInstance.ContainsKey(candidate.InstanceKey);
                var previousIn = hasInstanceCursor
                    ? lastInByInstance.GetValueOrDefault(candidate.InstanceKey)
                    : candidate.Legacy ? lastIn.GetValueOrDefault(forwardId) : 0;
                var previousOut = hasInstanceCursor
                    ? lastOutByInstance.GetValueOrDefault(candidate.InstanceKey)
                    : candidate.Legacy ? lastOut.GetValueOrDefault(forwardId) : 0;
                previousIn = currentIn >= previousIn ? Math.Max(0, previousIn) : 0;
                previousOut = currentOut >= previousOut ? Math.Max(0, previousOut) : 0;
                var deltaIn = currentIn - previousIn;
                var deltaOut = currentOut - previousOut;
                observations.Add((
                    forwardId,
                    generation,
                    currentIn,
                    currentOut,
                    previousIn,
                    previousOut,
                    deltaIn,
                    deltaOut,
                    candidate.InstanceKey,
                    candidate.Legacy,
                    candidate.LastWrite,
                    UltraRuntimeStatus.ReadPayloadCeiling(root),
                    UltraRuntimeStatus.ReadOversizeDrops(root),
                    UltraRuntimeStatus.ReadAttestation(root)));
            }
            catch (Exception ex) when (ex is JsonException or KeyNotFoundException or FormatException or InvalidOperationException or OverflowException)
            {
                Log.Debug(ex, "skipping malformed Ultra status snapshot");
            }
        }

        // The panel accepts at most 32 usage rows per heartbeat. Do not
        // advance cursors for rows outside this batch: they must be retried on
        // the next heartbeat instead of being silently lost.
        var acceptedGroups = observations
            .Where(item => item.DeltaIn > 0 || item.DeltaOut > 0)
            .GroupBy(item => (item.ForwardId, item.Generation))
            .OrderBy(group => group.Key.ForwardId)
            .ThenBy(group => group.Key.Generation, StringComparer.Ordinal)
            .Take(32)
            .ToList();
        void AdvanceCursor(string instanceKey, int forwardId, bool legacy, long bytesIn, long bytesOut)
        {
            if (legacy)
            {
                lastIn[forwardId] = bytesIn;
                lastOut[forwardId] = bytesOut;
            }
            lastInByInstance[instanceKey] = bytesIn;
            lastOutByInstance[instanceKey] = bytesOut;
        }
        foreach (var observation in observations.Where(item => item.DeltaIn == 0 && item.DeltaOut == 0))
            AdvanceCursor(observation.InstanceKey, observation.ForwardId, observation.Legacy, observation.CurrentIn, observation.CurrentOut);

        foreach (var group in acceptedGroups)
        {
            const long maxBytesPerRow = 107374182400;
            long bytesIn = 0, bytesOut = 0;
            foreach (var observation in group)
            {
                var consumedIn = Math.Min(observation.DeltaIn, maxBytesPerRow - bytesIn);
                var consumedOut = Math.Min(observation.DeltaOut, maxBytesPerRow - bytesOut);
                bytesIn += consumedIn;
                bytesOut += consumedOut;
                AdvanceCursor(observation.InstanceKey, observation.ForwardId, observation.Legacy,
                    observation.PreviousIn + consumedIn, observation.PreviousOut + consumedOut);
            }
            var forwardId = group.Key.ForwardId;
            sequences[forwardId] = sequences.GetValueOrDefault(forwardId) + 1;
            // Runtime status travels with the counters it was measured next to.
            // The freshest snapshot of the group is the instance still running;
            // an older one only contributes historical bytes.
            var runtime = group.OrderByDescending(observation => observation.LastWrite).First();
            result.Add(new UltraUsageSnapshot
            {
                ForwardId = forwardId,
                PolicyGeneration = group.Key.Generation,
                Sequence = sequences[forwardId],
                BytesIn = bytesIn,
                BytesOut = bytesOut,
                UdpPayloadCeiling = runtime.PayloadCeiling,
                UdpOversizeDrops = runtime.OversizeDrops,
                RuntimeAttestation = runtime.Attestation,
            });
        }

        pending = (lastIn, lastOut, sequences, lastInByInstance, lastOutByInstance);
        return result;
    }

    private IEnumerable<(string Path, bool Legacy)> EnumerateUltraStatusFiles()
    {
        var roots = new List<(string Path, bool Legacy)>
        {
            (Path.Combine(_stateManager.EffectiveStateDir, "runtime"), true),
        };
        roots.AddRange(_additionalRuntimeDirectories.Select(path => (Path.Combine(path, "native-usage"), false)));
        var statusFiles = new List<(string Path, bool Legacy)>();
        foreach (var (runtimeDir, legacy) in roots.OrderBy(item => item.Legacy).DistinctBy(item => item.Path, StringComparer.OrdinalIgnoreCase))
        {
            if (!Directory.Exists(runtimeDir))
                continue;
            try
            {
                if (legacy)
                {
                    statusFiles.AddRange(Directory.EnumerateFiles(runtimeDir, "ultra-forward-*.status.json", SearchOption.TopDirectoryOnly)
                        .Select(path => (path, true)));
                    // macOS ordinary Ultra stores its status one level below
                    // runtime/ultra-<forward>-<instance>.
                    statusFiles.AddRange(Directory.EnumerateDirectories(runtimeDir, "ultra-*", SearchOption.TopDirectoryOnly)
                        .Select(directory => Path.Combine(directory, "status.json"))
                        .Where(File.Exists)
                        .Select(path => (path, true)));
                }
                else
                {
                    statusFiles.AddRange(Directory.EnumerateFiles(runtimeDir, "status.json", SearchOption.AllDirectories)
                        .Select(path => (path, false)));
                }
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
            {
                Log.Debug(exception, "could not enumerate Ultra runtime directory {Path}", runtimeDir);
            }
        }
        return statusFiles.DistinctBy(item => item.Path, StringComparer.OrdinalIgnoreCase);
    }

    private List<UltraCandidateFeedback> CollectUltraCandidateFeedback()
    {
        var result = new List<UltraCandidateFeedback>();
        foreach (var (path, _) in EnumerateUltraStatusFiles())
        {
            if (result.Count >= 32)
                break;
            try
            {
                var info = new FileInfo(path);
                if (!info.Exists || info.Length > 64 * 1024)
                    continue;
                using var document = JsonDocument.Parse(File.ReadAllBytes(path));
                var root = document.RootElement;
                if (!root.TryGetProperty("forward_id", out var forwardElement)
                    || !forwardElement.TryGetInt32(out var forwardId) || forwardId <= 0
                    || !root.TryGetProperty("candidate_feedback", out var feedbackElement)
                    || feedbackElement.ValueKind != JsonValueKind.Array)
                    continue;
                foreach (var item in feedbackElement.EnumerateArray())
                {
                    if (result.Count >= 32 || item.ValueKind != JsonValueKind.Object)
                        break;
                    var candidateHash = ReadOpaqueHash(item, "candidate_key_hash");
                    var leaseHash = ReadOpaqueHash(item, "lease_hash");
                    var protocol = ReadBounded(item, "protocol", 32).ToLowerInvariant();
                    var outcome = ReadBounded(item, "outcome", 16).ToLowerInvariant();
                    var reason = ReadBounded(item, "reason", 64).ToLowerInvariant();
                    var evidenceId = ReadBounded(item, "evidence_id", 128);
                    if (candidateHash.Length != 64 || leaseHash.Length != 64
                        || protocol is not ("tcp" or "udp" or "tcp+udp" or "native_socks5")
                        || outcome is not ("failure" or "success")
                        || reason.Length == 0 || reason == "process_stderr"
                        || !IsSafeFeedbackText(evidenceId))
                        continue;
                    result.Add(new UltraCandidateFeedback
                    {
                        ForwardId = forwardId,
                        CandidateKeyHash = candidateHash,
                        LeaseHash = leaseHash,
                        Protocol = protocol,
                        Outcome = outcome,
                        Reason = reason,
                        EvidenceId = evidenceId,
                        ObservedAt = ReadBounded(item, "observed_at", 40),
                    });
                }
            }
            catch (Exception exception) when (exception is IOException or JsonException or InvalidOperationException or FormatException)
            {
                Log.Debug(exception, "skipping malformed Ultra candidate feedback file {Path}", path);
            }
        }
        return result;
    }

    private static string ReadOpaqueHash(JsonElement item, string property)
    {
        var value = ReadBounded(item, property, 64).ToLowerInvariant();
        return value.Length == 64 && value.All(character => character is >= '0' and <= '9' or >= 'a' and <= 'f')
            ? value
            : "";
    }

    private static string ReadBounded(JsonElement item, string property, int maximum)
        => item.TryGetProperty(property, out var value) && value.ValueKind == JsonValueKind.String
            ? (value.GetString() ?? "").Trim()[..Math.Min((value.GetString() ?? "").Trim().Length, maximum)]
            : "";

    private static bool IsSafeFeedbackText(string value)
        => value.Length == 0 || value.All(character => character is >= 'A' and <= 'Z'
            or >= 'a' and <= 'z' or >= '0' and <= '9' or '.' or '_' or ':' or '-');
}
