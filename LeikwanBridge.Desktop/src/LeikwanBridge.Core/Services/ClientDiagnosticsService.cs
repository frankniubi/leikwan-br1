using System.Text.RegularExpressions;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Core.Services;

public sealed partial class ClientDiagnosticsService
{
    private readonly IProcessSupervisor _supervisor;
    private readonly AgentConfig _config;
    private readonly Func<int, bool> _portListeningProbe;
    private readonly DebugLogUploader _debugLogUploader;
    private readonly object _lock = new();
    private string _lastRequestToken = string.Empty;

    public ClientDiagnosticsService(
        IProcessSupervisor supervisor,
        AgentConfig config,
        Func<int, bool>? portListeningProbe = null,
        string? logDirectory = null)
    {
        _supervisor = supervisor;
        _config = config;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
        var resolvedLogDirectory = string.IsNullOrWhiteSpace(logDirectory)
            ? Path.Combine(Directory.GetParent(config.GetEffectiveStateDir())?.FullName ?? config.GetEffectiveStateDir(), "logs")
            : logDirectory;
        _debugLogUploader = new DebugLogUploader(resolvedLogDirectory, config.GetEffectiveStateDir());
    }

    public List<object> BuildResults(IReadOnlyList<ForwardConfig> forwards)
    {
        return forwards.Take(64).Select(forward =>
        {
            var mode = string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
                ? "ultra"
                : (!string.IsNullOrWhiteSpace(forward.ClientMode) ? forward.ClientMode : forward.Mode).Trim().ToLowerInvariant();
            var listenerReady = forward.ListenPort is > 0 and <= 65535 && _portListeningProbe(forward.ListenPort);
            var processCount = _supervisor.GetProcessIds(forward.ForwardId).Count;
            var helperPresent = mode switch
            {
                "ultra" => File.Exists(_config.GetEffectiveUltraBin()),
                "bond" => File.Exists(_config.GetEffectiveBondBin()) && File.Exists(_config.GetEffectiveBondIngressBin()),
                _ => File.Exists(_config.GetEffectiveWstunnelBin()),
            };
            var errorCode = listenerReady
                ? string.Empty
                : processCount == 0 ? "process_not_running" : "listener_not_ready";
            return (object)new
            {
                forward_id = forward.ForwardId,
                listener_ready = listenerReady,
                managed_process_count = Math.Min(processCount, 128),
                helper_present = helperPresent,
                error_code = BoundErrorCode(errorCode),
            };
        }).ToList();
    }

    public async Task SubmitIfRequestedAsync(
        ClientMeta client,
        IReadOnlyList<ForwardConfig> forwards,
        MasterApiClient api,
        CancellationToken ct)
    {
        var token = (client.DiagRequestToken ?? string.Empty).Trim();
        if (token.Length is < 1 or > 256)
            return;
        lock (_lock)
        {
            if (string.Equals(_lastRequestToken, token, StringComparison.Ordinal))
                return;
            _lastRequestToken = token;
        }
        try
        {
            await api.SubmitDiagnosticsAsync(token, BuildResults(forwards), string.Empty, ct);
            _debugLogUploader.Queue(client, api, ct);
        }
        catch
        {
            lock (_lock)
            {
                if (string.Equals(_lastRequestToken, token, StringComparison.Ordinal))
                    _lastRequestToken = string.Empty;
            }
            throw;
        }
    }

    /// <summary>Queues the raw-log collection independently of summary diagnostics.</summary>
    public void QueueDebugLogUpload(ClientMeta client, MasterApiClient api, CancellationToken ct = default)
        => _debugLogUploader.Queue(client, api, ct);

    private static string BoundErrorCode(string value)
    {
        return ErrorCodePattern().IsMatch(value) ? value : "diagnostic_error";
    }

    [GeneratedRegex("^[a-z0-9_]{0,64}$", RegexOptions.CultureInvariant)]
    private static partial Regex ErrorCodePattern();
}
