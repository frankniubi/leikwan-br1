using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.App.ViewModels;

public partial class MainViewModel
{
    private TaskCompletionSource _proxyForwardsAvailable = new(TaskCreationOptions.RunContinuationsAsynchronously);
    private readonly SemaphoreSlim _proxyForwardGate = new(1, 1);
    private CancellationTokenSource? _proxyForwardStartCts;
    private ProxyForwardLease? _proxyForwardLease;
    private int _proxyForwardProfileId;
    private bool _proxyForwardExternal;
    private ForwardConfig? _proxyPendingForward;

    [ObservableProperty] private int _selectedProxyForwardId;
    [ObservableProperty] private int _selectedProxyProfileId;

    public bool IsExternalProxySelected => ProxyProfiles.FirstOrDefault(item => item.Id == SelectedProxyProfileId)?.IsExternal == true;
    public bool IsExternalProxyActive => _proxyForwardLease is not null && _proxyForwardExternal;

    public IReadOnlyList<ForwardViewModel> ProxyForwardOptions => Forwards
        .Where(item => item.Config is { ListenPort: > 0 and <= 65535 } forward
            && string.Equals(forward.ServiceStatus, "active", StringComparison.OrdinalIgnoreCase))
        .ToList();

    public IReadOnlyList<ForwardViewModel> PairedProxyForwardOptions
        => ProxyForwardOptions.Where(item => GetProxyProfileIdForForward(item.ForwardId) > 0).ToList();

    public string ProxyForwardEndpoint => PairedProxyForwardOptions
        .FirstOrDefault(item => item.ForwardId == SelectedProxyForwardId)?.Config is { } forward
        ? $"127.0.0.1:{forward.ListenPort}"
        : string.Empty;

    public bool IsSelectedProxyForwardUnavailable
        => _config.ProxySelectedForwardId > 0 && SelectedProxyForwardId != _config.ProxySelectedForwardId;

    partial void OnSelectedProxyForwardIdChanged(int value)
    {
        if (value > 0)
            _config.ProxySelectedForwardId = value;
        RestoreSavedProxySelection();
        OnPropertyChanged(nameof(ProxyForwardEndpoint));
        OnPropertyChanged(nameof(IsSelectedProxyForwardUnavailable));
        NotifySelectedProxyPairingStateChanged();
    }

    partial void OnSelectedProxyProfileIdChanged(int value)
    {
        var profile = ProxyProfiles.FirstOrDefault(item => item.Id == value);
        if (profile is null)
            return;
        _config.ProxyActiveProfileId = value;
        if (SelectedProxyForwardId > 0)
            _config.ProxyProfileIdsByForwardId[SelectedProxyForwardId] = value;
        ProxyEditor.Load(profile);
        OnPropertyChanged(nameof(IsExternalProxySelected));
        NotifySelectedProxyPairingStateChanged();
    }

    private void RestoreSavedProxySelection()
    {
        _config.ProxyProfileIdsByForwardId.TryGetValue(SelectedProxyForwardId, out var pairedId);
        var profile = ProxyProfiles.FirstOrDefault(item => item.Id == pairedId)
            ?? (SelectedProxyForwardId <= 0
                ? ProxyProfiles.FirstOrDefault(item => item.Id == _config.ProxyActiveProfileId)
                : null);
        SelectedProxyProfileId = profile?.Id ?? 0;
        ProxyEditor.Load(profile);
    }

    private void RefreshProxyForwardOptions()
    {
        var options = ProxyForwardOptions;
        if (_proxyPendingForward is { } pending && !IsCurrentProxyForward(pending))
            CancelProxyForwardStart();
        // A line the user picked is never swapped for another one: if it is no
        // longer offered the selection is cleared and the UI says so.
        var chosen = _config.ProxySelectedForwardId;
        var selected = chosen > 0
            ? options.FirstOrDefault(item => item.ForwardId == chosen)
            : options.FirstOrDefault();
        var wasUnavailable = IsSelectedProxyForwardUnavailable;
        OnPropertyChanged(nameof(ProxyForwardOptions));
        OnPropertyChanged(nameof(PairedProxyForwardOptions));
        SelectedProxyForwardId = selected?.ForwardId ?? 0;
        if (IsSelectedProxyForwardUnavailable && !wasUnavailable)
            AddLog($"Selected forward {chosen} is unavailable; choose another service to connect.");
        OnPropertyChanged(nameof(IsSelectedProxyForwardUnavailable));
        OnPropertyChanged(nameof(ProxyForwardEndpoint));
        RefreshProxyForwardRows();
        _proxyForwardsAvailable.TrySetResult();
    }

    public async Task WaitForProxyForwardSelectionAsync(CancellationToken ct = default)
    {
        await _proxyForwardsAvailable.Task.WaitAsync(TimeSpan.FromSeconds(45), ct);
        if (SelectedProxyForwardId <= 0)
            throw new InvalidOperationException("proxy_forward_required");
    }

    private async Task RunProxyThroughForwardAsync(
        WindowsProxyMode mode,
        WindowsProxyProfile profile,
        ForwardConfig forward,
        Func<int, CancellationToken, Task> startProxy,
        CancellationToken ct)
    {
        var runtime = _runtimeManager ?? throw new InvalidOperationException("proxy_forward_runtime_unavailable");
        await _proxyForwardGate.WaitAsync(ct);
        using var startup = CancellationTokenSource.CreateLinkedTokenSource(ct, _lifecycleCts.Token);
        _proxyForwardStartCts = startup;
        _proxyPendingForward = forward;
        ProxyForwardLease? acquired = null;
        try
        {
            ThrowIfShuttingDown();
            if (_proxyService!.Snapshot.State != WindowsProxyConnectionState.Disconnected)
                await _proxyService.StopAsync(startup.Token);
            await ReleaseProxyForwardAsync();
            HandleProxySnapshotChanged(new WindowsProxySnapshot(
                WindowsProxyConnectionState.Starting, mode, profile.Name, _proxyService.ProxyPort,
                0, false, false, string.Empty));
            acquired = await runtime.AcquireProxyForwardAsync(forward, startup.Token);
            startup.Token.ThrowIfCancellationRequested();
            await ValidateCurrentProxyForwardAsync(forward, runtime, startup.Token);
            if (!profile.IsExternal)
                await startProxy(acquired.ListenPort, startup.Token);
            startup.Token.ThrowIfCancellationRequested();
            await ValidateCurrentProxyForwardAsync(forward, runtime, startup.Token);
            _proxyForwardLease = acquired;
            _proxyForwardProfileId = profile.Id;
            _proxyForwardExternal = profile.IsExternal;
            acquired = null;
            _proxyPendingForward = null;
            if (profile.IsExternal)
                HandleProxySnapshotChanged(new WindowsProxySnapshot(
                    WindowsProxyConnectionState.Connected, mode, profile.Name, _proxyForwardLease.ListenPort,
                    0, false, false, string.Empty));
            else
                HandleProxySnapshotChanged(_proxyService.Snapshot);
        }
        catch (Exception exception)
        {
            try { await _proxyService!.StopAsync(CancellationToken.None); }
            finally
            {
                if (acquired is not null)
                    await acquired.DisposeAsync();
                HandleProxySnapshotChanged(new WindowsProxySnapshot(
                    exception is OperationCanceledException ? WindowsProxyConnectionState.Disconnected : WindowsProxyConnectionState.Error,
                    mode, profile.Name, _proxyService!.ProxyPort, 0, false, false,
                    exception is OperationCanceledException ? string.Empty : exception.Message));
            }
            throw;
        }
        finally
        {
            _proxyForwardStartCts = null;
            _proxyPendingForward = null;
            _proxyForwardGate.Release();
        }
    }

    private async Task ReleaseProxyForwardAsync()
    {
        var lease = _proxyForwardLease;
        _proxyForwardLease = null;
        _proxyForwardProfileId = 0;
        _proxyForwardExternal = false;
        if (lease is not null)
            await lease.DisposeAsync();
    }

    private void CancelProxyForwardStart()
    {
        try { _proxyForwardStartCts?.Cancel(); }
        catch (ObjectDisposedException) { }
    }

    private bool IsCurrentProxyForward(ForwardConfig forward)
        => ProxyForwardOptions.Any(item => item.Config is { } current
            && current.ForwardId == forward.ForwardId && current.ListenPort == forward.ListenPort
            && string.Equals(current.Transport, forward.Transport, StringComparison.OrdinalIgnoreCase));

    private async Task ValidateCurrentProxyForwardAsync(ForwardConfig forward, ForwardRuntimeManager runtime, CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        if (!IsCurrentProxyForward(forward) || !runtime.IsForwardRunning(forward.ForwardId)
            || !await ProxyForwardLease.IsListenerReadyAsync(forward, ct))
            throw new InvalidOperationException("proxy_forward_unavailable");
        ct.ThrowIfCancellationRequested();
        if (!IsCurrentProxyForward(forward) || !runtime.IsForwardRunning(forward.ForwardId))
            throw new InvalidOperationException("proxy_forward_unavailable");
    }

    public async Task CheckProxyForwardHealthAsync()
    {
        if (_proxyForwardLease is null || !await _proxyForwardGate.WaitAsync(0))
            return;
        try
        {
            var lease = _proxyForwardLease;
            var service = _proxyService;
            if (lease is null || service is null)
                return;
            if ((_proxyForwardExternal || service.IsSavedDataPlaneRunning)
                && IsCurrentProxyForward(lease.Forward)
                && _runtimeManager?.IsForwardRunning(lease.Forward.ForwardId) == true
                && await ProxyForwardLease.IsListenerReadyAsync(lease.Forward))
                return;
            var snapshot = ProxySnapshot;
            try { await service.StopAsync(CancellationToken.None); }
            finally
            {
                await ReleaseProxyForwardAsync();
                HandleProxySnapshotChanged(snapshot with
                {
                    State = WindowsProxyConnectionState.Error,
                    SystemProxyEnabled = false,
                    ProcessProxyEnabled = false,
                    ErrorCode = "proxy_runtime_unavailable",
                });
            }
        }
        finally { _proxyForwardGate.Release(); }
    }
}
