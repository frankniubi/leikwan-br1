using LeikwanBridge.Core.Models;
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

    public HeartbeatService(
        MasterApiClient api,
        StateManager stateManager,
        string wireguardPublicKey = "",
        int wireguardListenPort = 0,
        int wireguardFallbackTcpPort = 0,
        Action<IReadOnlyList<UltraPolicyAction>>? onUltraActions = null,
        ClientPlatformIdentity? platformIdentity = null)
    {
        _api = api;
        _stateManager = stateManager;
        _wireguardPublicKey = wireguardPublicKey;
        _wireguardListenPort = wireguardListenPort;
        _wireguardFallbackTcpPort = wireguardFallbackTcpPort;
        _onUltraActions = onUltraActions;
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
        var usage = _stateManager.LoadUsageState();
        long bytesIn = usage.TotalBytesIn - usage.LastReportedBytesIn;
        long bytesOut = usage.TotalBytesOut - usage.LastReportedBytesOut;
        var ultraUsage = CollectUltraUsage(usage, out var pendingUltraState);

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
        };

        var response = await _api.SendHeartbeatAsync(payload, ct);

        usage.LastReportedBytesIn = usage.TotalBytesIn;
        usage.LastReportedBytesOut = usage.TotalBytesOut;
        usage.UltraLastBytesIn = pendingUltraState.LastBytesIn;
        usage.UltraLastBytesOut = pendingUltraState.LastBytesOut;
        usage.UltraSequences = pendingUltraState.Sequences;
        _stateManager.SaveUsageState(usage);
        if (response.UltraActions is { Count: > 0 })
        {
            _onUltraActions?.Invoke(response.UltraActions);
            UltraActionsReceived?.Invoke(response.UltraActions);
        }

        Log.Debug("heartbeat sent: in={BytesIn} out={BytesOut}", bytesIn, bytesOut);
    }

    private List<UltraUsageSnapshot> CollectUltraUsage(UsageState usage, out (Dictionary<int, long> LastBytesIn, Dictionary<int, long> LastBytesOut, Dictionary<int, long> Sequences) pending)
    {
        var lastIn = new Dictionary<int, long>(usage.UltraLastBytesIn);
        var lastOut = new Dictionary<int, long>(usage.UltraLastBytesOut);
        var sequences = new Dictionary<int, long>(usage.UltraSequences);
        var result = new List<UltraUsageSnapshot>();
        var runtimeDir = Path.Combine(_stateManager.EffectiveStateDir, "runtime");
        if (Directory.Exists(runtimeDir))
        {
            var statusFiles = new List<string>();
            try
            {
                statusFiles.AddRange(Directory.EnumerateFiles(
                    runtimeDir,
                    "ultra-forward-*.status.json",
                    SearchOption.TopDirectoryOnly));
                statusFiles.AddRange(Directory.EnumerateFiles(
                    runtimeDir,
                    "status.json",
                    SearchOption.AllDirectories));
            }
            catch (IOException)
            {
                statusFiles.Clear();
            }
            catch (UnauthorizedAccessException)
            {
                statusFiles.Clear();
            }

            // macOS runtimes use a unique directory per lease. A stopped
            // runtime can leave an old status file behind, so only the latest
            // status for each forward is allowed to contribute usage.
            var candidates = new List<(int ForwardId, JsonElement Root, DateTimeOffset UpdatedAt, DateTime LastWrite)>();
            foreach (var path in statusFiles.Distinct(StringComparer.Ordinal))
            {
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
                    candidates.Add((forwardId, root.Clone(), updatedAt, info.LastWriteTimeUtc));
                }
                catch (Exception ex) when (ex is IOException or JsonException or KeyNotFoundException or FormatException or InvalidOperationException or OverflowException)
                {
                    Log.Debug(ex, "skipping malformed Ultra status file {Path}", path);
                }
            }

            foreach (var candidate in candidates
                .GroupBy(item => item.ForwardId)
                .Select(group => group
                    .OrderByDescending(item => item.UpdatedAt)
                    .ThenByDescending(item => item.LastWrite)
                    .First())
                .OrderBy(item => item.ForwardId)
                .Take(32))
            {
                try
                {
                    var root = candidate.Root;
                    var generation = root.TryGetProperty("policy_generation", out var generationElement) ? generationElement.GetString() ?? "" : "";
                    var currentIn = root.TryGetProperty("bytes_in", out var inElement) ? Math.Max(0, inElement.GetInt64()) : 0;
                    var currentOut = root.TryGetProperty("bytes_out", out var outElement) ? Math.Max(0, outElement.GetInt64()) : 0;
                    var forwardId = candidate.ForwardId;
                    if (forwardId <= 0 || generation.Length != 64)
                        continue;
                    var deltaIn = currentIn >= lastIn.GetValueOrDefault(forwardId) ? currentIn - lastIn.GetValueOrDefault(forwardId) : currentIn;
                    var deltaOut = currentOut >= lastOut.GetValueOrDefault(forwardId) ? currentOut - lastOut.GetValueOrDefault(forwardId) : currentOut;
                    lastIn[forwardId] = currentIn;
                    lastOut[forwardId] = currentOut;
                    if (deltaIn == 0 && deltaOut == 0)
                        continue;
                    sequences[forwardId] = sequences.GetValueOrDefault(forwardId) + 1;
                    result.Add(new UltraUsageSnapshot
                    {
                        ForwardId = forwardId,
                        PolicyGeneration = generation,
                        Sequence = sequences[forwardId],
                        BytesIn = Math.Min(deltaIn, 107374182400),
                        BytesOut = Math.Min(deltaOut, 107374182400),
                    });
                }
                catch (Exception ex) when (ex is JsonException or KeyNotFoundException or FormatException or InvalidOperationException or OverflowException)
                {
                    Log.Debug(ex, "skipping malformed Ultra status snapshot");
                }
            }
        }
        pending = (lastIn, lastOut, sequences);
        return result;
    }
}
