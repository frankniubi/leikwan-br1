using System.Net.NetworkInformation;
using Microsoft.Win32;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.App.ViewModels;

/// <summary>
/// Brings a running proxy session back after its process dies, the machine
/// resumes from sleep or the network changes. Only sessions the user started
/// are recovered; a user stop or a new user start forgets the session.
/// </summary>
public partial class MainViewModel
{
    public const int MaxProxyRecoveryAttempts = 8;
    private static readonly TimeSpan ProxyRecheckDelay = TimeSpan.FromSeconds(3);

    private sealed record ProxyRecoverySession(
        bool Native,
        WindowsProxyMode Mode,
        int ForwardId,
        int ProfileId,
        string GameId,
        string RouteScope,
        bool BypassChina,
        bool Unrestricted);

    private readonly ControlPlaneBackoff _proxyRecoveryBackoff = new(
        random: () => 1.0,
        initial: TimeSpan.FromSeconds(2),
        cap: TimeSpan.FromSeconds(60));
    private Func<TimeSpan, CancellationToken, Task> _proxyRecoveryDelay = Task.Delay;
    private ProxyRecoverySession? _proxyRecoverySession;
    private CancellationTokenSource? _proxyRecoveryCts;
    private Task? _proxyRecoveryTask;
    private CancellationTokenSource? _proxyRecheckCts;
    private bool _proxyRecovering;
    private bool _proxySystemEventsHooked;

    private void RememberProxySession(ProxyRecoverySession session)
    {
        _proxyRecoverySession = session;
        _proxyRecoveryBackoff.RecordSuccess();
        HookProxySystemEvents();
    }

    private void ForgetProxySession()
    {
        if (_proxyRecovering)
            return;
        _proxyRecoverySession = null;
        try { _proxyRecoveryCts?.Cancel(); }
        catch (ObjectDisposedException) { }
        try { _proxyRecheckCts?.Cancel(); }
        catch (ObjectDisposedException) { }
        UnhookProxySystemEvents();
    }

    private static bool IsProxyLostError(WindowsProxySnapshot snapshot)
        => snapshot.State == WindowsProxyConnectionState.Error
            && snapshot.ErrorCode is WindowsProxyConnectionService.ProcessLostErrorCode or "proxy_runtime_unavailable";

    private void StartProxyRecovery()
    {
        if (IsShuttingDown || _proxyRecoverySession is not { } session || _proxyRecoveryCts is not null)
            return;
        var cts = CancellationTokenSource.CreateLinkedTokenSource(_lifecycleCts.Token);
        _proxyRecoveryCts = cts;
        _proxyRecoveryBackoff.RecordSuccess();
        _proxyRecoveryTask = RecoverProxyAsync(session, cts);
    }

    private async Task RecoverProxyAsync(ProxyRecoverySession session, CancellationTokenSource cts)
    {
        var ct = cts.Token;
        try
        {
            for (var attempt = 1; attempt <= MaxProxyRecoveryAttempts; attempt++)
            {
                var delay = _proxyRecoveryBackoff.RecordFailure();
                PublishProxyRecoveryStatus(string.Format(
                    GetStringOr("ProxyReconnecting", "Connection lost. Reconnecting in {0} s (attempt {1}/{2})..."),
                    (int)Math.Ceiling(delay.TotalSeconds), attempt, MaxProxyRecoveryAttempts));
                await _proxyRecoveryDelay(delay, ct);
                if (!ReferenceEquals(_proxyRecoverySession, session))
                    return;
                _proxyRecovering = true;
                try
                {
                    await RestartProxySessionAsync(session, ct);
                    AddLog("Proxy reconnected.");
                    return;
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    return;
                }
                catch (Exception exception) when (WindowsProxyElevation.IsElevationCancelled(exception))
                {
                    Log.Information("proxy reconnect stopped: elevation was declined");
                    break;
                }
                catch (Exception exception) when (
                    string.Equals(exception.Message, "administrator_required", StringComparison.OrdinalIgnoreCase))
                {
                    // A TUN/process session can only be reconnected from an
                    // elevated instance; silently retrying here would never
                    // succeed.  Stop and let the user reconnect.
                    Log.Information("proxy reconnect stopped: administrator rights are required");
                    break;
                }
                catch (Exception exception)
                {
                    Log.Warning(exception, "proxy reconnect attempt {Attempt} failed", attempt);
                }
                finally
                {
                    _proxyRecovering = false;
                }
            }
            if (ReferenceEquals(_proxyRecoverySession, session))
                PublishProxyRecoveryStatus(string.Format(
                    GetStringOr("ProxyReconnectFailed", "Could not reconnect after {0} attempts. Press Connect to try again."),
                    MaxProxyRecoveryAttempts));
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
        finally
        {
            if (ReferenceEquals(_proxyRecoveryCts, cts))
                _proxyRecoveryCts = null;
            cts.Dispose();
        }
    }

    private async Task RestartProxySessionAsync(ProxyRecoverySession session, CancellationToken ct)
    {
        if (session.Native)
        {
            await StartNativeOutboundAsync(
                session.Mode,
                session.ForwardId,
                session.RouteScope,
                session.GameId,
                session.BypassChina,
                session.Unrestricted,
                ct);
            return;
        }
        // Never reconnect through a different line than the one that dropped.
        if (SelectedProxyForwardId != session.ForwardId)
            throw new InvalidOperationException("proxy_forward_unavailable");
        await StartProxyAsync(session.Mode, session.ProfileId, session.GameId, ct);
        RefreshProxyForwardRows();
    }

    private void PublishProxyRecoveryStatus(string message)
        => HandleProxySnapshotChanged(ProxySnapshot with
        {
            State = WindowsProxyConnectionState.Error,
            SystemProxyEnabled = false,
            ProcessProxyEnabled = false,
            ErrorCode = message,
        });

    private void HookProxySystemEvents()
    {
        if (_proxySystemEventsHooked)
            return;
        _proxySystemEventsHooked = true;
        NetworkChange.NetworkAddressChanged += OnProxyNetworkAddressChanged;
        try { SystemEvents.PowerModeChanged += OnProxyPowerModeChanged; }
        catch (Exception exception) { Log.Debug(exception, "power mode notifications are unavailable"); }
    }

    private void UnhookProxySystemEvents()
    {
        if (!_proxySystemEventsHooked)
            return;
        _proxySystemEventsHooked = false;
        NetworkChange.NetworkAddressChanged -= OnProxyNetworkAddressChanged;
        try { SystemEvents.PowerModeChanged -= OnProxyPowerModeChanged; }
        catch (Exception exception) { Log.Debug(exception, "power mode notifications are unavailable"); }
    }

    private void OnProxyNetworkAddressChanged(object? sender, EventArgs e)
        => ScheduleProxyRecheck("network_changed");

    private void OnProxyPowerModeChanged(object? sender, PowerModeChangedEventArgs e)
    {
        if (e.Mode == PowerModes.Resume)
            ScheduleProxyRecheck("resume");
    }

    /// <summary>Debounced: a burst of address changes runs one check after it settles.</summary>
    private void ScheduleProxyRecheck(string reason)
    {
        InvokeOnUiThread(() =>
        {
            if (_proxyRecoverySession is null)
                return;
            try { _proxyRecheckCts?.Cancel(); }
            catch (ObjectDisposedException) { }
            var cts = CancellationTokenSource.CreateLinkedTokenSource(_lifecycleCts.Token);
            _proxyRecheckCts = cts;
            _ = RecheckProxyAsync(reason, cts);
        });
    }

    private async Task RecheckProxyAsync(string reason, CancellationTokenSource cts)
    {
        try
        {
            await _proxyRecoveryDelay(ProxyRecheckDelay, cts.Token);
            if (_proxyRecoverySession is not { } session || _proxyRecoveryCts is not null
                || ProxySnapshot.State == WindowsProxyConnectionState.Starting)
                return;
            var service = _proxyService;
            var running = ProxySnapshot.State == WindowsProxyConnectionState.Connected
                && (session.Native
                    ? service?.IsNativeDataPlaneRunning == true
                    : IsExternalProxyActive || service?.IsSavedDataPlaneRunning == true);
            Log.Information("proxy check after {Reason}: running={Running}", reason, running);
            if (!running)
                StartProxyRecovery();
            else if (session.Native)
                ScheduleNativeLeaseRefresh();
            else
                await CheckProxyForwardHealthAsync();
        }
        catch (OperationCanceledException) when (cts.IsCancellationRequested)
        {
        }
        catch (Exception exception)
        {
            Log.Warning(exception, "proxy check after {Reason} failed", reason);
        }
        finally
        {
            if (ReferenceEquals(_proxyRecheckCts, cts))
                _proxyRecheckCts = null;
            cts.Dispose();
        }
    }
}
