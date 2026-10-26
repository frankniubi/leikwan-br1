using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json.Nodes;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Ultra;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Owns one local sing-box instance and the optional NetFilter2 process
/// redirector.  A single gate makes mode changes transactional: the previous
/// mode is stopped before a new config is exposed to the operating system.
/// </summary>
public sealed class WindowsProxyConnectionService : IAsyncDisposable, LeikwanBridge.Core.Nodes.IWindowsProxyProfileHost
{
    private readonly WindowsProxyProfileStore _store;
    private readonly IWindowsProxyProcessHost _processHost;
    private readonly IWindowsSystemProxyManager _systemProxy;
    private readonly IWindowsProcessProxyAdapter _processAdapter;
    private readonly string _singBoxPath;
    private readonly string _runtimeDirectory;
    private int _proxyPort;
    private readonly int _tunMtu;
    private readonly string _tunStack;
    private readonly string _chinaIpListPath;
    private readonly string _ruleSetDirectory;
    private readonly INativeOutboundRelayHost _nativeRelayHost;
    private readonly IWindowsTunMtuReader _tunMtuReader;
    private readonly TimeSpan _mtuApplyMinInterval;
    private string _preferredEgressInterface;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly List<WindowsProxyProfile> _profiles = [];
    private WindowsProxySnapshot _snapshot = WindowsProxySnapshot.Disconnected;
    private int _activeProfileId;
    private NativeOutboundLease? _activeNativeLease;
    private List<NativeOutboundLease> _activeNativeLeases = [];
    private int _measuredPayloadCeiling;
    private int _appliedTunMtu;
    private int _pendingTunMtu;
    private bool _mtuUpdatePending;
    // The exact JSON the live process was started from, kept only for TUN mode.
    // sing-box 1.13 cannot reload inbounds, so a live MTU change has to re-issue
    // the same config; rebuilding it would re-allocate the native relay port.
    private string _activeConfigJson = string.Empty;
    private DateTimeOffset? _lastMtuApplyAttemptAt;
    private bool _stopCompleted;
    private bool _disposed;

    public const string NativeOutboundDisplayName = "原生出口";
    public const string ProcessLostErrorCode = "proxy_process_lost";

    /// <summary>Minimum spacing between two live-MTU apply attempts.</summary>
    public static readonly TimeSpan MtuApplyDefaultInterval = TimeSpan.FromSeconds(60);

    public WindowsProxyConnectionService(
        WindowsProxyProfileStore store,
        string singBoxPath,
        string runtimeDirectory,
        IWindowsProxyProcessHost? processHost = null,
        IWindowsSystemProxyManager? systemProxy = null,
        IWindowsProcessProxyAdapter? processAdapter = null,
        int proxyPort = WindowsProxyConfigBuilder.DefaultProxyPort,
        int tunMtu = WindowsProxyConfigBuilder.DefaultTunMtu,
        string tunStack = "gvisor",
        string? chinaIpListPath = null,
        INativeOutboundRelayHost? nativeRelayHost = null,
        IWindowsTunMtuReader? tunMtuReader = null,
        TimeSpan? mtuApplyMinInterval = null,
        string? preferredEgressInterface = null,
        string? ruleSetDirectory = null)
    {
        _store = store ?? throw new ArgumentNullException(nameof(store));
        _singBoxPath = singBoxPath ?? string.Empty;
        _runtimeDirectory = runtimeDirectory ?? throw new ArgumentNullException(nameof(runtimeDirectory));
        _processHost = processHost ?? new WindowsProxyProcessHost();
        _systemProxy = systemProxy ?? new WindowsSystemProxyManager();
        _processAdapter = processAdapter ?? new WindowsNetchProcessProxyAdapter(
            Path.Combine(Path.GetDirectoryName(_singBoxPath) ?? AppContext.BaseDirectory, "Redirector.bin"),
            Path.Combine(Path.GetDirectoryName(_singBoxPath) ?? AppContext.BaseDirectory, "netfilter2.sys"));
        if (proxyPort is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(proxyPort));
        if (tunMtu is < 576 or > 9000)
            throw new ArgumentOutOfRangeException(nameof(tunMtu));
        tunStack = WindowsProxyConfigBuilder.NormalizeTunStack(tunStack);
        _proxyPort = proxyPort;
        _tunMtu = tunMtu;
        _appliedTunMtu = tunMtu;
        _pendingTunMtu = tunMtu;
        _tunStack = tunStack;
        _chinaIpListPath = chinaIpListPath ?? string.Empty;
        // geoip-cn.srs / geosite-cn.srs ship next to sing-box (bin\), like
        // CN.conf; BuildNative falls back to remote rule-sets without them.
        _ruleSetDirectory = string.IsNullOrWhiteSpace(ruleSetDirectory)
            ? Path.GetDirectoryName(_singBoxPath) ?? string.Empty
            : ruleSetDirectory;
        _nativeRelayHost = nativeRelayHost ?? new NativeOutboundRelayHost();
        _tunMtuReader = tunMtuReader ?? new WindowsTunMtuReader();
        _mtuApplyMinInterval = mtuApplyMinInterval ?? MtuApplyDefaultInterval;
        _preferredEgressInterface = preferredEgressInterface?.Trim() ?? string.Empty;
        if (_mtuApplyMinInterval < TimeSpan.Zero)
            throw new ArgumentOutOfRangeException(nameof(mtuApplyMinInterval));
        _processHost.UnexpectedExit += exitCode => _ = HandleUnexpectedExitAsync(exitCode);
    }

    public event Action<WindowsProxySnapshot>? SnapshotChanged;

    public void SetPreferredEgressInterface(string? interfaceName)
        => _preferredEgressInterface = interfaceName?.Trim() ?? string.Empty;

    public IReadOnlyList<WindowsProxyProfile> Profiles
    {
        get
        {
            lock (_profiles)
                return _profiles.Select(profile => profile.Clone()).ToList();
        }
    }

    public WindowsProxySnapshot Snapshot => _snapshot;

    /// <summary>
    /// Payload ceiling the Ultra runtime measured for the active forward
    /// (0 = nothing measured yet, so the configured MTU stands).
    /// </summary>
    public int MeasuredPayloadCeiling => _measuredPayloadCeiling;

    /// <summary>TUN MTU of the running configuration.</summary>
    public int AppliedTunMtu => _appliedTunMtu;

    /// <summary>TUN MTU the last observed ceiling resolves to.</summary>
    public int PendingTunMtu => _pendingTunMtu;

    /// <summary>
    /// True while a live TUN still runs with an MTU the runtime has since
    /// out-measured. A TUN MTU is fixed when the interface is created, so the
    /// value is applied by <see cref="ApplyPendingTunMtuAsync"/> (or by the next
    /// start) and surfaced here until then.
    /// </summary>
    public bool MtuUpdatePending => _mtuUpdatePending;

    /// <summary>Spacing this instance enforces between two live-MTU applies.</summary>
    public TimeSpan MtuApplyMinInterval => _mtuApplyMinInterval;

    /// <summary>UTC time of the last live-MTU apply attempt, if any.</summary>
    public DateTimeOffset? LastMtuApplyAttemptAt => _lastMtuApplyAttemptAt;

    public DateTimeOffset? ActiveNativeLeaseExpiresAt => _activeNativeLease?.ExpiresAt;

    public string? ActiveNativeLeasePolicyGeneration => _activeNativeLease?.PolicyGeneration;

    public bool IsNativeOutboundActive
        => _activeNativeLease is not null
            && _snapshot.State == WindowsProxyConnectionState.Connected;

    /// <summary>
    /// Reports whether both halves of the native data plane are alive and, in
    /// TUN mode, the interface is actually ready to carry traffic.
    /// Lease delivery can fail transiently while these processes continue to
    /// carry traffic; callers must preserve the data plane in that case.
    /// </summary>
    public bool IsNativeDataPlaneRunning
        => IsNativeOutboundActive
            && _processHost.IsRunning
            && (_snapshot.Mode != WindowsProxyMode.Tun || _processHost.IsTunReady)
            && _nativeRelayHost.IsRunning;

    public bool IsSavedDataPlaneRunning
        => _activeProfileId > 0 && _activeNativeLease is null
            && _snapshot.State == WindowsProxyConnectionState.Connected
            && _processHost.IsRunning
            && (_snapshot.Mode != WindowsProxyMode.Tun || _processHost.IsTunReady);

    /// <summary>
    /// The configured loopback listener used by HTTP and process mode.
    /// TUN mode keeps this value in its snapshot for consistent status output,
    /// although TUN traffic is attached to the virtual interface instead.
    /// </summary>
    public int ProxyPort => _proxyPort;

    public void SetProxyPort(int proxyPort)
    {
        if (proxyPort is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(proxyPort));
        if (proxyPort == _proxyPort)
            return;
        if (_snapshot.State is WindowsProxyConnectionState.Connected
            or WindowsProxyConnectionState.Starting)
            throw new InvalidOperationException("proxy_port_change_requires_stop");
        _proxyPort = proxyPort;
    }

    /// <summary>
    /// Records the payload ceiling a status snapshot reported for
    /// <paramref name="forwardId"/> and reports whether the running connection
    /// already uses the MTU it resolves to.  Returns false while a live TUN must
    /// be recreated; <see cref="MtuUpdatePending"/> stays true until the next
    /// start applies it.
    /// </summary>
    public bool ObserveMeasuredPayloadCeiling(int forwardId, int measuredPayloadCeiling)
    {
        if (forwardId <= 0)
            throw new ArgumentOutOfRangeException(nameof(forwardId));
        if (measuredPayloadCeiling < 0)
            throw new ArgumentOutOfRangeException(nameof(measuredPayloadCeiling));
        // A native connection owns exactly one forward; another forward's
        // ceiling must not resize its TUN.
        if (_activeNativeLease is { } active && active.ForwardId != forwardId)
            return false;

        _measuredPayloadCeiling = measuredPayloadCeiling;
        var resolved = UltraRuntimeMtu.Resolve(_tunMtu, measuredPayloadCeiling);
        _pendingTunMtu = resolved;
        if (resolved == _appliedTunMtu)
        {
            _mtuUpdatePending = false;
            return true;
        }
        if (_snapshot.State == WindowsProxyConnectionState.Connected
            && _snapshot.Mode == WindowsProxyMode.Tun)
        {
            if (!_mtuUpdatePending)
            {
                Log.Warning(
                    "mtu_update_pending forward={ForwardId} ceiling={Ceiling} configured_mtu={ConfiguredMtu} applied_mtu={AppliedMtu} pending_mtu={PendingMtu}",
                    forwardId,
                    measuredPayloadCeiling,
                    _tunMtu,
                    _appliedTunMtu,
                    resolved);
            }
            _mtuUpdatePending = true;
            return false;
        }
        _mtuUpdatePending = false;
        return true;
    }

    /// <summary>
    /// Re-reads the forward's runtime status snapshot and records the ceiling it
    /// reports.  Call this from the status/heartbeat path to let a live TUN
    /// follow a runtime that re-measured after a failover.
    /// </summary>
    public bool RefreshMeasuredPayloadCeiling(int forwardId)
        => ObserveMeasuredPayloadCeiling(
            forwardId,
            UltraRuntimeStatus.ReadForwardPayloadCeiling(_runtimeDirectory, forwardId));

    /// <summary>
    /// Applies the pending TUN MTU to the live connection.
    ///
    /// sing-box 1.13 cannot reload inbounds: its Clash API answers
    /// <c>PUT /configs</c> with 204 but keeps the inbound that is already bound
    /// (verified against the pinned 1.13.19 binary), and a TUN MTU is fixed when
    /// the interface is created. The supported mechanism is therefore to
    /// recreate the interface: the sing-box process is restarted in place from
    /// the same config with the new MTU. Process supervision and the published
    /// Connected snapshot are preserved; the restart is debounced to at most one
    /// attempt per <see cref="MtuApplyMinInterval"/> and can never overlap a
    /// start/stop transition because every transition holds the same gate.
    ///
    /// Returns true only when the live adapter reports the requested MTU
    /// afterwards; <see cref="MtuUpdatePending"/> is cleared on that success only.
    /// </summary>
    public async Task<bool> ApplyPendingTunMtuAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (_disposed || !_mtuUpdatePending || _activeConfigJson.Length == 0)
                return false;
            if (_snapshot.State != WindowsProxyConnectionState.Connected
                || _snapshot.Mode != WindowsProxyMode.Tun)
                return false;
            var target = _pendingTunMtu;
            if (target == _appliedTunMtu)
            {
                // The measured ladder wobbled inside the MTU that is already live.
                _mtuUpdatePending = false;
                return true;
            }

            var now = DateTimeOffset.UtcNow;
            if (_lastMtuApplyAttemptAt is { } last && now - last < _mtuApplyMinInterval)
                return false;
            // A dead process belongs to the recovery paths, not to a resize.
            if (!_processHost.IsRunning)
                return false;
            _lastMtuApplyAttemptAt = now;
            return await ApplyTunMtuCoreAsync(target, ct).ConfigureAwait(false);
        }
        finally { _gate.Release(); }
    }

    private async Task<bool> ApplyTunMtuCoreAsync(int target, CancellationToken ct)
    {
        var previousJson = _activeConfigJson;
        string configPath;
        try
        {
            Directory.CreateDirectory(_runtimeDirectory);
            configPath = Path.Combine(_runtimeDirectory, $"proxy-{Guid.NewGuid():N}.json");
            await WriteEphemeralConfigAsync(configPath, WithTunMtu(previousJson, target), ct)
                .ConfigureAwait(false);
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            Log.Error(exception, "mtu_apply_config_invalid target_mtu={TargetMtu}", target);
            return false;
        }

        Log.Information(
            "mtu_apply_started applied_mtu={AppliedMtu} target_mtu={TargetMtu} ceiling={Ceiling}",
            _appliedTunMtu,
            target,
            _measuredPayloadCeiling);
        try
        {
            // The existing supervision path: stop, start, wait for the interface,
            // one retry on a refused TUN.
            await _processHost.StopAsync(CancellationToken.None).ConfigureAwait(false);
            await StartProxyProcessAsync(configPath, WindowsProxyMode.Tun, ct).ConfigureAwait(false);
            if (!await WaitForTunMtuAsync(target, ct).ConfigureAwait(false))
                throw new InvalidOperationException("proxy_mtu_not_applied");

            _appliedTunMtu = target;
            _mtuUpdatePending = false;
            Log.Information(
                "mtu_apply_succeeded mtu={Mtu} ceiling={Ceiling}",
                target,
                _measuredPayloadCeiling);
            return true;
        }
        catch (Exception exception)
        {
            Log.Warning(
                exception,
                "mtu_apply_failed target_mtu={TargetMtu} applied_mtu={AppliedMtu}",
                target,
                _appliedTunMtu);
            await RecoverAfterFailedMtuApplyAsync(previousJson).ConfigureAwait(false);
            return false;
        }
        finally { SecureDelete(configPath); }
    }

    /// <summary>
    /// Puts the data plane back after a failed in-place MTU restart. A process
    /// that is still running with a ready TUN is left alone (its MTU is simply
    /// not the one we asked for); otherwise the previous config is started again
    /// so a failed resize can never leave the user disconnected.
    /// </summary>
    private async Task RecoverAfterFailedMtuApplyAsync(string previousJson)
    {
        try
        {
            if (_processHost.IsRunning && _processHost.IsTunReady)
                return;
            var configPath = Path.Combine(_runtimeDirectory, $"proxy-{Guid.NewGuid():N}.json");
            var restored = false;
            try
            {
                await WriteEphemeralConfigAsync(configPath, previousJson, CancellationToken.None)
                    .ConfigureAwait(false);
                await _processHost.StopAsync(CancellationToken.None).ConfigureAwait(false);
                await StartProxyProcessAsync(configPath, WindowsProxyMode.Tun, CancellationToken.None)
                    .ConfigureAwait(false);
                restored = true;
            }
            finally { SecureDelete(configPath); }
            if (restored)
            {
                Log.Warning("mtu_apply_rolled_back applied_mtu={AppliedMtu}", _appliedTunMtu);
                return;
            }
        }
        catch (Exception exception)
        {
            Log.Error(exception, "mtu_apply_recovery_failed applied_mtu={AppliedMtu}", _appliedTunMtu);
        }

        Publish(new WindowsProxySnapshot(
            WindowsProxyConnectionState.Error,
            _snapshot.Mode,
            _snapshot.ActiveProfileName,
            _snapshot.ProxyPort,
            _snapshot.BypassAddressCount,
            false,
            false,
            "proxy_mtu_apply_failed"));
    }

    /// <summary>
    /// Waits for the live TUN adapter to report <paramref name="expectedMtu"/>.
    /// The adapter is only re-sized by a (re)creation of the interface, so this is
    /// the verification that the rewrite actually reached the data plane.
    /// </summary>
    private async Task<bool> WaitForTunMtuAsync(int expectedMtu, CancellationToken ct)
    {
        // The interface is already up when this runs; the grace only absorbs a
        // driver that applies the new size a moment after it comes up.
        var deadline = DateTime.UtcNow + TimeSpan.FromSeconds(3);
        while (true)
        {
            ct.ThrowIfCancellationRequested();
            var observed = _tunMtuReader.ReadTunMtu();
            if (observed == expectedMtu)
                return true;
            if (DateTime.UtcNow >= deadline)
            {
                // Some drivers do not expose the interface MTU; a TUN that is up
                // and was created from a config carrying the requested value is
                // then the best available evidence.
                if (observed == 0 && _processHost.IsRunning && _processHost.IsTunReady)
                {
                    Log.Warning("mtu_apply_unverified mtu={Mtu}", expectedMtu);
                    return true;
                }
                Log.Error(
                    "mtu_apply_not_verified expected_mtu={ExpectedMtu} observed_mtu={ObservedMtu}",
                    expectedMtu,
                    observed);
                return false;
            }
            await Task.Delay(TimeSpan.FromMilliseconds(100), ct).ConfigureAwait(false);
        }
    }

    /// <summary>
    /// Returns the live config with only the TUN inbound MTU replaced. Rebuilding
    /// the config instead is not an option: the native relay port is allocated per
    /// start and the profile may already point at a forward listener.
    /// </summary>
    private static string WithTunMtu(string configJson, int tunMtu)
    {
        var root = JsonNode.Parse(configJson)?.AsObject()
            ?? throw new InvalidOperationException("proxy_config_invalid");
        if (root["inbounds"] is not JsonArray { Count: > 0 } inbounds
            || inbounds[0] is not JsonObject tun
            || tun["type"]?.GetValue<string>() != "tun")
            throw new InvalidOperationException("proxy_config_invalid");
        tun["mtu"] = tunMtu;
        return root.ToJsonString();
    }

    public async Task InitializeAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try
        {
            _profiles.Clear();
            _profiles.AddRange(await _store.LoadAsync(ct));
        }
        finally { _gate.Release(); }
    }

    public async Task SaveProfileAsync(WindowsProxyProfile profile, CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(profile);
        // Build in proxy mode validates the profile without starting a process.
        if (profile.IsExternal)
        {
            if (!IPAddress.TryParse(profile.Server, out _) || profile.Port is < 1 or > 65535)
                throw new InvalidOperationException("proxy_external_endpoint_invalid");
        }
        else
        {
            WindowsProxyConfigBuilder.Build(profile, WindowsProxyMode.Http, [], _proxyPort, _tunMtu, _tunStack);
        }
        await _gate.WaitAsync(ct);
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            var copy = profile.Clone();
            if (copy.Id <= 0)
                copy.Id = NextId();
            profile.Id = copy.Id;
            var index = _profiles.FindIndex(item => item.Id == copy.Id);
            if (index >= 0)
                _profiles[index] = copy;
            else
                _profiles.Add(copy);
            await _store.SaveAsync(_profiles, ct);
        }
        finally { _gate.Release(); }
    }

    public async Task DeleteProfileAsync(int profileId, CancellationToken ct = default)
    {
        if (profileId <= 0)
            return;
        await _gate.WaitAsync(ct);
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            if (_snapshot.State is not WindowsProxyConnectionState.Disconnected
                && _activeProfileId == profileId)
            {
                await StopCoreAsync(ct);
                _activeProfileId = 0;
                Publish(WindowsProxySnapshot.Disconnected);
            }
            _profiles.RemoveAll(profile => profile.Id == profileId);
            await _store.SaveAsync(_profiles, ct);
        }
        finally { _gate.Release(); }
    }

    public async Task StartAsync(
        WindowsProxyMode mode,
        int profileId,
        IEnumerable<string>? bypassHosts = null,
        IEnumerable<WindowsProcessProxyRule>? processRules = null,
        CancellationToken ct = default,
        bool bypassChinaMainland = false,
        WindowsProxyDnsMode dnsMode = WindowsProxyDnsMode.Remote,
        IEnumerable<string>? customDnsServers = null,
        int? forwardListenPort = null)
    {
        await _gate.WaitAsync(ct);
        var profileName = "";
        var previousSnapshot = _snapshot;
        var connectionStopped = false;
        var proxyPort = _proxyPort;
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            if (mode is not (WindowsProxyMode.Http or WindowsProxyMode.Tun or WindowsProxyMode.Process))
                throw new InvalidOperationException("proxy_mode_invalid");
            var profile = _profiles.FirstOrDefault(item => item.Id == profileId)
                ?? throw new KeyNotFoundException("proxy_profile_not_found");
            if (profile.IsExternal)
                throw new InvalidOperationException("proxy_external_requires_forward");
            if (forwardListenPort.HasValue)
            {
                if (forwardListenPort.Value is < 1 or > 65535)
                    throw new InvalidOperationException("proxy_forward_listener_invalid");
                profile = LeikwanBridge.Core.Nodes.UserNodeOutbound.RewriteForForward(profile, forwardListenPort.Value);
            }
            profileName = profile.Name;
            var normalizedProcessRules = (processRules ?? [])
                .Where(rule => rule is not null && !string.IsNullOrWhiteSpace(rule.Pattern))
                .Take(64)
                .ToList();
            if (mode == WindowsProxyMode.Process && normalizedProcessRules.Count == 0)
                throw new InvalidOperationException("process_rule_required");
            if (!File.Exists(_singBoxPath))
                throw new FileNotFoundException("sing_box_missing", _singBoxPath);
            if (mode == WindowsProxyMode.Process && !_processAdapter.IsAvailable)
                throw new InvalidOperationException(_processAdapter.AvailabilityReason);

            proxyPort = ResolveProxyPort(mode, previousSnapshot);

            var compiled = WindowsProxyConfigBuilder.Build(
                profile,
                mode,
                bypassHosts,
                proxyPort,
                _tunMtu,
                _tunStack,
                bypassChinaMainland,
                _chinaIpListPath,
                dnsMode,
                customDnsServers,
                _measuredPayloadCeiling);

            // Perform all deterministic validation before tearing down an
            // existing connection.  A bad URI, missing runtime, or empty
            // process selection must not create a needless connectivity gap.
            Publish(new WindowsProxySnapshot(
                WindowsProxyConnectionState.Starting,
                mode,
                profile.Name,
                proxyPort,
                0,
                false,
                false,
                ""));
            connectionStopped = true;
            await StopCoreAsync(ct);
            Directory.CreateDirectory(_runtimeDirectory);
            var configPath = Path.Combine(_runtimeDirectory, $"proxy-{Guid.NewGuid():N}.json");
            try
            {
                try
                {
                    await WriteEphemeralConfigAsync(configPath, compiled.Json, ct);
                    await StartProxyProcessAsync(configPath, mode, ct);
                    if (mode is WindowsProxyMode.Http or WindowsProxyMode.Process
                        && !await _processHost.WaitForListenerAsync(proxyPort, ct))
                    {
                        throw new InvalidOperationException("proxy_listener_unavailable");
                    }
                }
                catch
                {
                    // A listener check can fail after sing-box has started.
                    // Roll back immediately so a failed start never leaves a
                    // live process behind the Error snapshot.
                    await StopCoreAsync(CancellationToken.None);
                    throw;
                }
            }
            finally
            {
                SecureDelete(configPath);
            }

            var systemProxyEnabled = false;
            var processProxyEnabled = false;
            try
            {
                if (mode == WindowsProxyMode.Http)
                {
                    await _systemProxy.EnableAsync(proxyPort, ct);
                    systemProxyEnabled = true;
                }
                else if (mode == WindowsProxyMode.Process)
                {
                    if (!_processAdapter.IsAvailable)
                        throw new InvalidOperationException(_processAdapter.AvailabilityReason);
                    await _processAdapter.StartAsync(proxyPort, normalizedProcessRules, ct);
                    processProxyEnabled = true;
                }
            }
            catch
            {
                await StopCoreAsync(CancellationToken.None);
                throw;
            }

            RecordAppliedTunMtu();
            // Keep the launched JSON so a live MTU change can re-issue it verbatim.
            _activeConfigJson = mode == WindowsProxyMode.Tun ? compiled.Json : string.Empty;
            _activeProfileId = profileId;
            _stopCompleted = false;
            Publish(new WindowsProxySnapshot(
                WindowsProxyConnectionState.Connected,
                mode,
                profile.Name,
                compiled.ProxyPort,
                compiled.BypassIps.Count + compiled.BypassDomains.Count,
                systemProxyEnabled,
                processProxyEnabled,
                ""));
        }
        catch (Exception exception)
        {
            // If deterministic preflight failed before StopCoreAsync, the
            // previous connection is still serving traffic.  Keep its
            // Connected snapshot instead of reporting a false outage.
            if (connectionStopped || previousSnapshot.State != WindowsProxyConnectionState.Connected)
            {
                Publish(new WindowsProxySnapshot(
                    WindowsProxyConnectionState.Error,
                    mode,
                    profileName,
                    proxyPort,
                    0,
                    false,
                    false,
                    ErrorCode(exception)));
            }
            throw;
        }
        finally { _gate.Release(); }
    }

    /// <summary>
    /// Starts the same local sing-box lifecycle as a saved profile, but uses a
    /// short-lived panel-issued native SOCKS5 lease. Ownership of <paramref
    /// name="lease"/> transfers to this service once startup succeeds; it is
    /// wiped whenever the connection is stopped or startup fails.
    /// </summary>
    public Task StartNativeAsync(
        WindowsProxyMode mode,
        NativeOutboundLease lease,
        IEnumerable<string>? bypassHosts = null,
        IEnumerable<WindowsProcessProxyRule>? processRules = null,
        CancellationToken ct = default,
        bool bypassChinaMainland = false,
        WindowsProxyDnsMode dnsMode = WindowsProxyDnsMode.Remote,
        IEnumerable<string>? customDnsServers = null)
        => StartNativeAsync(
            mode,
            new[] { lease },
            bypassHosts,
            processRules,
            ct,
            bypassChinaMainland,
            dnsMode,
            customDnsServers);

    public async Task StartNativeAsync(
        WindowsProxyMode mode,
        IReadOnlyList<NativeOutboundLease> leases,
        IEnumerable<string>? bypassHosts = null,
        IEnumerable<WindowsProcessProxyRule>? processRules = null,
        CancellationToken ct = default,
        bool bypassChinaMainland = false,
        WindowsProxyDnsMode dnsMode = WindowsProxyDnsMode.Remote,
        IEnumerable<string>? customDnsServers = null)
    {
        if (leases is null || leases.Count is < 1 or > 4 || leases.Any(lease => lease is null))
            throw new ArgumentException("native lease batch is invalid", nameof(leases));
        var lease = leases[0];
        await _gate.WaitAsync(ct);
        var previousSnapshot = _snapshot;
        var connectionStopped = false;
        var proxyPort = _proxyPort;
        var leaseTransferred = false;
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            if (leases.Any(candidate => candidate.ForwardId != lease.ForwardId
                || !candidate.IsUsable(DateTimeOffset.UtcNow)))
                throw new InvalidOperationException("native_lease_invalid");
            if (mode is not (WindowsProxyMode.Http or WindowsProxyMode.Tun or WindowsProxyMode.Process))
                throw new InvalidOperationException("proxy_mode_invalid");
            var normalizedProcessRules = (processRules ?? [])
                .Where(rule => rule is not null && !string.IsNullOrWhiteSpace(rule.Pattern))
                .Take(64)
                .ToList();
            if (mode == WindowsProxyMode.Process && normalizedProcessRules.Count == 0)
                throw new InvalidOperationException("process_rule_required");
            if (!File.Exists(_singBoxPath))
                throw new FileNotFoundException("sing_box_missing", _singBoxPath);
            if (mode == WindowsProxyMode.Process && !_processAdapter.IsAvailable)
                throw new InvalidOperationException(_processAdapter.AvailabilityReason);

            proxyPort = ResolveProxyPort(mode, previousSnapshot);
            var nativeRelayPort = AllocateLoopbackPort(proxyPort);
            // The ceiling belongs to the relay association, and the runtime
            // re-measures it after a failover, so read it from the status
            // snapshot on every start instead of trusting a stale value.
            _measuredPayloadCeiling = UltraRuntimeStatus.ReadForwardPayloadCeiling(
                _runtimeDirectory,
                lease.ForwardId);
            var compiled = WindowsProxyConfigBuilder.BuildNative(
                lease,
                mode,
                bypassHosts,
                proxyPort,
                _tunMtu,
                _tunStack,
                bypassChinaMainland,
                _chinaIpListPath,
                dnsMode,
                customDnsServers,
                nativeProxyHost: "127.0.0.1",
                nativeProxyPort: nativeRelayPort,
                measuredPayloadCeiling: _measuredPayloadCeiling,
                ruleSetDirectory: _ruleSetDirectory);

            Publish(new WindowsProxySnapshot(
                WindowsProxyConnectionState.Starting,
                mode,
                NativeOutboundDisplayName,
                proxyPort,
                0,
                false,
                false,
                ""));
            connectionStopped = true;
            await StopCoreAsync(ct);
            var egressInterface = EgressInterfaceConfig.ResolveOrThrow(_preferredEgressInterface);
            await _nativeRelayHost.StartCandidatesWithEgressAsync(
                lease.ForwardId,
                ResolveUltraBinaryPath(),
                nativeRelayPort,
                leases,
                _runtimeDirectory,
                ct,
                egressInterface);
            Directory.CreateDirectory(_runtimeDirectory);
            var configPath = Path.Combine(_runtimeDirectory, $"proxy-{Guid.NewGuid():N}.json");
            try
            {
                try
                {
                    await WriteEphemeralConfigAsync(configPath, compiled.Json, ct);
                    await StartProxyProcessAsync(configPath, mode, ct);
                    if (mode == WindowsProxyMode.Process
                        && _processHost is WindowsProxyProcessHost concreteHost
                        && !await concreteHost.WaitForListenerAsync(proxyPort, ct))
                        throw new InvalidOperationException("proxy_listener_unavailable");
                }
                catch
                {
                    await StopCoreAsync(CancellationToken.None);
                    throw;
                }
            }
            finally
            {
                SecureDelete(configPath);
            }

            var systemProxyEnabled = false;
            var processProxyEnabled = false;
            try
            {
                if (mode == WindowsProxyMode.Http)
                {
                    await _systemProxy.EnableAsync(proxyPort, ct);
                    systemProxyEnabled = true;
                }
                else if (mode == WindowsProxyMode.Process)
                {
                    await _processAdapter.StartAsync(proxyPort, normalizedProcessRules, ct);
                    processProxyEnabled = true;
                }
            }
            catch
            {
                await StopCoreAsync(CancellationToken.None);
                throw;
            }

            RecordAppliedTunMtu();
            // Keep the launched JSON so a live MTU change can re-issue it verbatim.
            _activeConfigJson = mode == WindowsProxyMode.Tun ? compiled.Json : string.Empty;
            _activeProfileId = 0;
            _activeNativeLease = lease;
            _activeNativeLeases = leases.ToList();
            leaseTransferred = true;
            _stopCompleted = false;
            Publish(new WindowsProxySnapshot(
                WindowsProxyConnectionState.Connected,
                mode,
                NativeOutboundDisplayName,
                compiled.ProxyPort,
                compiled.BypassIps.Count + compiled.BypassDomains.Count,
                systemProxyEnabled,
                processProxyEnabled,
                ""));
        }
        catch (Exception exception)
        {
            if (!leaseTransferred)
            {
                foreach (var candidate in leases)
                    candidate.Dispose();
            }
            if (connectionStopped || previousSnapshot.State != WindowsProxyConnectionState.Connected)
            {
                Publish(new WindowsProxySnapshot(
                    WindowsProxyConnectionState.Error,
                    mode,
                    NativeOutboundDisplayName,
                    proxyPort,
                    0,
                    false,
                    false,
                    ErrorCode(exception)));
            }
            throw;
        }
        finally { _gate.Release(); }
    }

    /// <summary>
    /// Replaces the credentials in the running native relay without restarting
    /// sing-box or toggling the system proxy. The caller retains ownership of
    /// <paramref name="lease"/> when this returns false and may fall back to a
    /// full start.
    /// </summary>
    public Task<bool> TryRefreshNativeAsync(
        NativeOutboundLease lease,
        CancellationToken ct = default)
        => TryRefreshNativeAsync(new[] { lease }, ct);

    public async Task<bool> TryRefreshNativeAsync(
        IReadOnlyList<NativeOutboundLease> leases,
        CancellationToken ct = default)
    {
        if (leases is null || leases.Count is < 1 or > 4 || leases.Any(lease => lease is null))
            return false;
        var lease = leases[0];
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            var current = _activeNativeLease;
            if (_disposed
                || current is null
                || _snapshot.State != WindowsProxyConnectionState.Connected
                || lease == current
                || lease.ForwardId != current.ForwardId
                || leases.Any(candidate => candidate.ForwardId != current.ForwardId
                    || !string.Equals(candidate.RouteScope, current.RouteScope, StringComparison.OrdinalIgnoreCase)
                    || !string.Equals(candidate.Transport, current.Transport, StringComparison.OrdinalIgnoreCase)
                    || !string.Equals(candidate.ScopeDigest, current.ScopeDigest, StringComparison.OrdinalIgnoreCase)
                    || !candidate.IsRuntimeUsable(DateTimeOffset.UtcNow)))
                return false;

            if (!await _nativeRelayHost.RefreshCandidatesAsync(lease.ForwardId, leases, ct).ConfigureAwait(false))
                return false;

            var previousLeases = _activeNativeLeases;
            _activeNativeLease = lease;
            _activeNativeLeases = leases.ToList();
            foreach (var previous in previousLeases)
            {
                if (!leases.Any(candidate => ReferenceEquals(candidate, previous)))
                    previous.Dispose();
            }
            return true;
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task StopAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try
        {
            if (_stopCompleted
                && _snapshot.State == WindowsProxyConnectionState.Disconnected
                && _activeProfileId == 0
                && _activeNativeLease is null)
                return;

            await StopCoreAsync(ct);
            _activeProfileId = 0;
            Publish(WindowsProxySnapshot.Disconnected);
        }
        catch (Exception exception)
        {
            _activeProfileId = 0;
            Publish(new WindowsProxySnapshot(
                WindowsProxyConnectionState.Error,
                _snapshot.Mode,
                _snapshot.ActiveProfileName,
                _snapshot.ProxyPort,
                _snapshot.BypassAddressCount,
                false,
                false,
                ErrorCode(exception)));
            throw;
        }
        finally { _gate.Release(); }
    }

    public async Task<WindowsSocks5UdpProbeResult> TestUdpAsync(CancellationToken ct = default)
    {
        var snapshot = Snapshot;
        if (snapshot.State != WindowsProxyConnectionState.Connected
            || snapshot.Mode == WindowsProxyMode.Tun)
            return WindowsSocks5UdpProbeResult.Failed("proxy_udp_probe_requires_http_or_process_mode");
        return await WindowsSocks5UdpProbe.QueryDnsAsync("127.0.0.1", snapshot.ProxyPort, ct: ct);
    }

    public async ValueTask DisposeAsync()
    {
        if (_disposed)
            return;
        _disposed = true;
        try
        {
            await StopAsync(CancellationToken.None);
        }
        catch
        {
            // StopAsync already publishes the Error snapshot.  Disposal must
            // continue releasing user-mode handles even when the native driver
            // refused to stop; the UI process is exiting at this point.
        }
        finally
        {
            await _processHost.DisposeAsync();
            await _processAdapter.DisposeAsync();
            await _nativeRelayHost.DisposeAsync();
            _gate.Dispose();
        }
    }

    private async Task HandleUnexpectedExitAsync(int exitCode)
    {
        try { await _gate.WaitAsync().ConfigureAwait(false); }
        catch (ObjectDisposedException) { return; }
        WindowsProxySnapshot? lost = null;
        try
        {
            if (_disposed
                || _snapshot.State != WindowsProxyConnectionState.Connected
                || _processHost.IsRunning)
                return;
            Log.Warning("proxy process exited unexpectedly (exit code {ExitCode}, mode {Mode})", exitCode, _snapshot.Mode);
            try { await StopCoreAsync(CancellationToken.None).ConfigureAwait(false); }
            catch (Exception exception) { Log.Warning(exception, "cleanup after unexpected proxy exit failed"); }
            _activeProfileId = 0;
            lost = _snapshot = _snapshot with
            {
                State = WindowsProxyConnectionState.Error,
                SystemProxyEnabled = false,
                ProcessProxyEnabled = false,
                ErrorCode = ProcessLostErrorCode,
            };
        }
        finally
        {
            try { _gate.Release(); }
            catch (ObjectDisposedException) { }
        }
        // Outside the gate: subscribers reconnect, which needs the gate again.
        if (lost is not null)
            SnapshotChanged?.Invoke(lost);
    }

    private async Task StopCoreAsync(CancellationToken ct)
    {
        // Nothing live is left to resize; the next start resolves the MTU again
        // from the ceiling that is current then.
        _mtuUpdatePending = false;
        _activeConfigJson = string.Empty;
        _lastMtuApplyAttemptAt = null;
        var failures = new List<Exception>();

        // Stop the local target first.  If a native redirector is slow to
        // release, no new packets can be sent to a stale SOCKS listener.
        try { await _processHost.StopAsync(CancellationToken.None); }
        catch (Exception exception) { failures.Add(exception); }

        try { await _systemProxy.DisableAsync(CancellationToken.None); }
        catch (Exception exception) { failures.Add(exception); }

        // Always attempt the driver cleanup, even when one of the user-mode
        // cleanup steps failed.  Swallowing this exception leaves the whole
        // machine filtered after the UI reports that it stopped.
        try { await _processAdapter.StopAsync(CancellationToken.None); }
        catch (Exception exception) { failures.Add(exception); }

        try { await _nativeRelayHost.StopAsync(CancellationToken.None); }
        catch (Exception exception) { failures.Add(exception); }

        if (failures.Count == 0)
        {
            DisposeActiveNativeLeases();
            _activeNativeLease = null;
            _stopCompleted = true;
            return;
        }

        DisposeActiveNativeLeases();
        _activeNativeLease = null;
        _stopCompleted = false;
        if (failures.Count == 1)
            throw failures[0];
        if (failures.Count > 1)
            throw new AggregateException("proxy_stop_failed", failures);
    }

    private async Task StartProxyProcessAsync(
        string configPath,
        WindowsProxyMode mode,
        CancellationToken ct)
    {
        var attempts = mode == WindowsProxyMode.Tun ? 2 : 1;
        Exception? lastFailure = null;
        for (var attempt = 1; attempt <= attempts; attempt++)
        {
            try
            {
                await _processHost.StartAsync(_singBoxPath, configPath, mode, ct);
                if (mode != WindowsProxyMode.Tun
                    || await _processHost.WaitForTunReadyAsync(ct))
                    return;
                lastFailure = new InvalidOperationException("proxy_tun_unavailable");
            }
            catch (WindowsProxyProcessExitException exception) when (attempt < attempts)
            {
                lastFailure = exception;
            }

            await _processHost.StopAsync(CancellationToken.None);
            if (attempt < attempts)
                await Task.Delay(TimeSpan.FromMilliseconds(500), ct);
        }

        throw lastFailure ?? new InvalidOperationException("proxy_tun_unavailable");
    }

    private void DisposeActiveNativeLeases()
    {
        var leases = _activeNativeLeases;
        _activeNativeLeases = [];
        foreach (var lease in leases)
            lease.Dispose();
        if (_activeNativeLease is not null && !leases.Any(lease => ReferenceEquals(lease, _activeNativeLease)))
            _activeNativeLease.Dispose();
    }

    private void RecordAppliedTunMtu()
    {
        _appliedTunMtu = UltraRuntimeMtu.Resolve(_tunMtu, _measuredPayloadCeiling);
        _pendingTunMtu = _appliedTunMtu;
        _mtuUpdatePending = false;
    }

    private int ResolveProxyPort(
        WindowsProxyMode mode,
        WindowsProxySnapshot previousSnapshot)
    {
        // Reuse the active listener during a mode restart. It is owned by this
        // service and will be stopped immediately after validation.
        if (previousSnapshot.State == WindowsProxyConnectionState.Connected)
        {
            if (mode == WindowsProxyMode.Process
                && previousSnapshot.Mode == WindowsProxyMode.Process)
                return previousSnapshot.ProxyPort;
            if (previousSnapshot.ProxyPort == _proxyPort
                && mode == WindowsProxyMode.Process)
                return previousSnapshot.ProxyPort;
            if (mode == previousSnapshot.Mode
                && previousSnapshot.ProxyPort > 0)
                return previousSnapshot.ProxyPort;
        }

        return mode switch
        {
            WindowsProxyMode.Process => WindowsProxyPortAllocator.Resolve(
                _proxyPort,
                allowFallback: true),
            _ => _proxyPort,
        };
    }

    private string ResolveUltraBinaryPath()
    {
        var configuredDirectory = Path.GetDirectoryName(_singBoxPath);
        var adjacent = Path.Combine(configuredDirectory ?? AppContext.BaseDirectory, "ultra-client.exe");
        if (File.Exists(adjacent))
            return adjacent;
        var bundled = Path.Combine(AppContext.BaseDirectory, "bin", "ultra-client.exe");
        return File.Exists(bundled) ? bundled : adjacent;
    }

    private static int AllocateLoopbackPort(int avoidPort)
    {
        TcpListener? listener = null;
        try
        {
            for (var attempt = 0; attempt < 8; attempt++)
            {
                listener = new TcpListener(IPAddress.Loopback, 0);
                listener.Start();
                var port = ((IPEndPoint)listener.LocalEndpoint).Port;
                listener.Stop();
                listener = null;
                if (port != avoidPort)
                    return port;
            }
        }
        finally
        {
            listener?.Stop();
        }
        throw new InvalidOperationException("native_relay_port_unavailable");
    }

    private int NextId()
    {
        for (var id = 1; id <= WindowsProxyProfileStore.MaxProfiles; id++)
            if (_profiles.All(profile => profile.Id != id))
                return id;
        return _profiles.Count + 1;
    }

    private void Publish(WindowsProxySnapshot snapshot)
    {
        _snapshot = snapshot;
        SnapshotChanged?.Invoke(snapshot);
    }

    private static async Task WriteEphemeralConfigAsync(string path, string json, CancellationToken ct)
    {
        var bytes = Encoding.UTF8.GetBytes(json);
        try
        {
            await File.WriteAllBytesAsync(path, bytes, ct);
            if (!OperatingSystem.IsWindows())
                File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
        }
        finally { CryptographicOperations.ZeroMemory(bytes); }
    }

    private static void SecureDelete(string path)
    {
        try
        {
            if (!File.Exists(path))
                return;
            var length = new FileInfo(path).Length;
            if (length is > 0 and <= 4 * 1024 * 1024)
            {
                using var stream = new FileStream(path, FileMode.Open, FileAccess.Write, FileShare.Read, 4096, FileOptions.WriteThrough);
                var zeros = new byte[(int)length];
                stream.Write(zeros, 0, zeros.Length);
                stream.Flush(true);
                CryptographicOperations.ZeroMemory(zeros);
            }
            File.Delete(path);
        }
        catch (IOException)
        {
            try { File.Delete(path); } catch (IOException) { }
        }
        catch (UnauthorizedAccessException)
        {
            try { File.Delete(path); } catch (IOException) { }
        }
    }

    private string ErrorCode(Exception exception)
    {
        var code = exception switch
        {
            AggregateException aggregate when aggregate.Flatten().InnerExceptions.Count > 0
                => ErrorCode(aggregate.Flatten().InnerExceptions[0]),
            FileNotFoundException file when file.FileName?.Contains("sing", StringComparison.OrdinalIgnoreCase) == true
                => "sing_box_missing",
            FileNotFoundException => "proxy_config_missing",
            KeyNotFoundException => "proxy_profile_not_found",
            WindowsProxyProcessExitException exit => ClassifyProcessExit(exit.Diagnostics, () => LocalPortFailure.DiagnoseLive(_proxyPort)),
            UnauthorizedAccessException => "permission_required",
            _ => exception.Message,
        };
        var normalized = new string((code ?? "proxy_connection_failed").ToLowerInvariant()
            .Where(character => character is >= 'a' and <= 'z' or >= '0' and <= '9' or '_')
            .Take(64)
            .ToArray());
        return normalized.Length == 0 ? "proxy_connection_failed" : normalized;
    }

    public static string ClassifyProcessExit(string diagnostics, Func<string?> livePortCause)
    {
        if (diagnostics.Contains("Only one usage", StringComparison.OrdinalIgnoreCase)
            || diagnostics.Contains("address already in use", StringComparison.OrdinalIgnoreCase))
            return livePortCause() ?? LocalPortFailure.InUse;
        if (diagnostics.Contains("access permissions", StringComparison.OrdinalIgnoreCase))
            return LocalPortFailure.Reserved;
        return "proxy_process_exited";
    }

    /// <summary>
    /// The code a start failure shows in the banner. Known causes become stable
    /// codes; anything else keeps its own message so nothing is hidden.
    /// </summary>
    public static string FailureCode(Exception exception, Func<string?> livePortCause)
    {
        switch (exception)
        {
            case WindowsProxyProcessExitException exit:
                var code = ClassifyProcessExit(exit.Diagnostics, livePortCause);
                return code == "proxy_process_exited" ? exception.Message : code;
            case UnauthorizedAccessException:
                return "permission_required";
            default:
                return exception.Message;
        }
    }
}
