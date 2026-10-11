using System.Security.Cryptography;
using System.Text;
using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Ultra;
using Serilog;

namespace LeikwanBridge.Mac.Services;

public sealed class MacConnectionService : IMacConnectionService
{
    private readonly MacPlatformPaths _paths;
    private readonly MacConnectionProfileStore _store;
    private readonly MacBypassResolver _resolver;
    private readonly IMacConnectionProcessHost _processHost;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly List<MacConnectionProfile> _profiles = [];
    private readonly int _proxyPort;
    private readonly int _tunMtu;
    private readonly INativeOutboundRelayHost _nativeRelayHost;
    private readonly Func<DateTimeOffset> _utcNow;
    private readonly TimeSpan _nativeTunMtuApplyInterval;
    private string _preferredEgressInterface;
    private MacConnectionSnapshot _snapshot = MacConnectionSnapshot.Disconnected;
    private string? _configPath;
    private string? _activeProcessConfigPath;
    private NativeOutboundLease? _activeNativeLease;
    private int _activeNativePayloadCeiling;
    private int _activeNativeTunMtu;
    private MacConnectionMode _activeNativeMode = MacConnectionMode.Proxy;
    private IReadOnlyList<string> _activeNativeBypassHosts = [];
    private bool _activeNativeBypassChina;
    private int _activeNativeRelayPort;
    private MacNativeRuleSetPaths? _activeNativeRuleSets;
    private DateTimeOffset? _nativeTunMtuAppliedAt;
    private bool _disposed;
    private MacConnectionSelection _selection = MacConnectionSelection.Empty;

    public const string NativeOutboundDisplayName = "原生出口";

    /// <summary>
    /// A re-measured payload ceiling is hot-applied to the live tunnel at most this often;
    /// the association re-measures while it runs, and reloading sing-box rebuilds the TUN.
    /// </summary>
    public static readonly TimeSpan NativeTunMtuApplyInterval = TimeSpan.FromSeconds(60);

    public MacConnectionService(
        MacPlatformPaths paths,
        MacConnectionProfileStore store,
        MacBypassResolver? resolver = null,
        IMacConnectionProcessHost? processHost = null,
        int proxyPort = MacConnectionConfigBuilder.DefaultProxyPort,
        INativeOutboundRelayHost? nativeRelayHost = null,
        int tunMtu = MacConnectionConfigBuilder.DefaultTunMtu,
        Func<DateTimeOffset>? utcNow = null,
        TimeSpan? nativeTunMtuApplyInterval = null,
        string? preferredEgressInterface = null)
    {
        _paths = paths;
        _store = store;
        _resolver = resolver ?? new MacBypassResolver();
        _processHost = processHost ?? new MacConnectionProcessHost();
        _proxyPort = proxyPort;
        _tunMtu = tunMtu;
        _nativeRelayHost = nativeRelayHost ?? new NativeOutboundRelayHost(windows: false);
        _utcNow = utcNow ?? (() => DateTimeOffset.UtcNow);
        _nativeTunMtuApplyInterval = nativeTunMtuApplyInterval ?? NativeTunMtuApplyInterval;
        _preferredEgressInterface = preferredEgressInterface?.Trim() ?? string.Empty;
        _processHost.UnexpectedExit += OnProcessExited;
    }

    public event Action<MacConnectionSnapshot>? SnapshotChanged;

    public void SetPreferredEgressInterface(string? interfaceName)
        => _preferredEgressInterface = interfaceName?.Trim() ?? string.Empty;

    public IReadOnlyList<MacConnectionProfile> Profiles
    {
        get
        {
            lock (_profiles)
                return _profiles.Select(profile => profile.Clone()).ToList();
        }
    }

    public MacConnectionSnapshot Snapshot => _snapshot;
    public MacConnectionSelection Selection => _selection with
    {
        ForwardProfiles = new Dictionary<int, int>(_selection.ForwardProfiles),
    };

    public DateTimeOffset? ActiveNativeLeaseExpiresAt => _activeNativeLease?.ExpiresAt;

    public string? ActiveNativeLeasePolicyGeneration => _activeNativeLease?.PolicyGeneration;

    public bool IsNativeOutboundActive
        => _activeNativeLease is not null
            && _snapshot.State == MacConnectionState.Connected;

    /// <summary>
    /// Payload ceiling the native relay published for the live association, or 0 while no
    /// native connection is up or the runtime has not measured one yet.
    /// </summary>
    public int ActiveNativePayloadCeiling => _activeNativePayloadCeiling;

    /// <summary>
    /// True when the relay has since published a ceiling whose TUN MTU differs from the one
    /// the running sing-box config was compiled with. sing-box only reads the TUN MTU while
    /// it builds the service, so the live tunnel keeps the old MTU until
    /// <see cref="ApplyPendingNativeTunMtuAsync"/> reloads it in place (sing-box rebuilds
    /// itself on SIGHUP); <see cref="TryRefreshNativeAsync"/> still declines an in-place
    /// lease refresh in that case, leaving the caller's restart path as the fallback.
    /// </summary>
    public bool IsNativeTunMtuPending
    {
        get
        {
            var lease = _activeNativeLease;
            if (lease is null || _activeNativeTunMtu <= 0)
                return false;
            return UltraRuntimeMtu.Resolve(_tunMtu, ReadNativeRelayStatus(lease.ForwardId).PayloadCeiling)
                != _activeNativeTunMtu;
        }
    }

    public async Task InitializeAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            _profiles.Clear();
            _profiles.AddRange(await _store.LoadAsync(ct).ConfigureAwait(false));
            _selection = await _store.LoadSelectionAsync(ct).ConfigureAwait(false);
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task SaveProfileAsync(MacConnectionProfile profile, CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(profile);
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (profile.Protocol == MacConnectionProtocol.External)
            {
                if (!IPAddress.TryParse(profile.Server, out _) || profile.Port is < 1 or > 65535)
                    throw new ArgumentException("external_endpoint_invalid", nameof(profile));
            }
            else
                MacConnectionConfigBuilder.Build(profile, MacConnectionMode.Proxy, [], _proxyPort);
            var copy = profile.Clone();
            if (copy.Id <= 0)
                copy.Id = NextId();
            var nextProfiles = _profiles.ToList();
            var index = nextProfiles.FindIndex(item => item.Id == copy.Id);
            if (index >= 0)
                nextProfiles[index] = copy;
            else
                nextProfiles.Add(copy);
            await _store.SaveAsync(nextProfiles, ct).ConfigureAwait(false);
            lock (_profiles)
            {
                _profiles.Clear();
                _profiles.AddRange(nextProfiles);
            }
            profile.Id = copy.Id;
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task DeleteProfileAsync(int profileId, CancellationToken ct = default)
    {
        if (profileId <= 0)
            return;
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            _profiles.RemoveAll(profile => profile.Id == profileId);
            await _store.SaveAsync(_profiles, ct).ConfigureAwait(false);
            _selection = _selection with
            {
                ProfileId = _selection.ProfileId == profileId ? 0 : _selection.ProfileId,
                ForwardProfiles = _selection.ForwardProfiles.Where(pair => pair.Value != profileId)
                    .ToDictionary(pair => pair.Key, pair => pair.Value),
            };
            await _store.SaveSelectionAsync(_selection, ct).ConfigureAwait(false);
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task SaveSelectionAsync(int forwardId, int profileId, CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (profileId > 0 && !_profiles.Any(profile => profile.Id == profileId))
                throw new KeyNotFoundException("connection_profile_not_found");
            var pairs = new Dictionary<int, int>(_selection.ForwardProfiles);
            if (forwardId > 0 && profileId > 0)
                pairs[forwardId] = profileId;
            var nextSelection = new MacConnectionSelection(Math.Max(0, forwardId), Math.Max(0, profileId), pairs);
            await _store.SaveSelectionAsync(nextSelection, ct).ConfigureAwait(false);
            _selection = nextSelection;
        }
        finally
        {
            _gate.Release();
        }
    }

    public Task StartAsync(
        MacConnectionMode mode,
        int profileId,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        CancellationToken ct = default)
        => Task.FromException(new InvalidOperationException("connection_route_required"));

    public async Task StartRoutedAsync(
        MacConnectionMode mode,
        int profileId,
        int forwardListenPort,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            if (forwardListenPort is < 1 or > 65535)
                throw new ArgumentOutOfRangeException(nameof(forwardListenPort));
            var profile = (_profiles.FirstOrDefault(item => item.Id == profileId)
                ?? throw new KeyNotFoundException("connection_profile_not_found")).Clone();
            Publish(new MacConnectionSnapshot(
                MacConnectionState.Starting,
                mode,
                profile.Name,
                _proxyPort,
                0,
                ""));
            await StopCoreAsync(ct).ConfigureAwait(false);
            if (profile.Protocol == MacConnectionProtocol.External)
            {
                Publish(new MacConnectionSnapshot(MacConnectionState.Connected, mode, profile.Name, forwardListenPort, 0, ""));
                return;
            }
            profile.Server = "127.0.0.1";
            profile.Port = forwardListenPort;
            var resolution = await _resolver.ResolveAsync(panelUrl, forwards ?? [], ct).ConfigureAwait(false);
            var compiled = MacConnectionConfigBuilder.Build(
                profile,
                mode,
                resolution.All,
                _proxyPort);
            var executable = _paths.BinDirectory.TrimEnd('/', '\\') + "/sing-box";
            if (!File.Exists(executable))
                throw new FileNotFoundException("sing-box helper is missing", executable);
            var configPath = Path.Combine(
                _paths.RuntimeDirectory,
                $"connection-{Guid.NewGuid():N}.json");
            await WriteEphemeralConfigAsync(configPath, compiled.Json, ct).ConfigureAwait(false);
            _configPath = configPath;
            _activeProcessConfigPath = configPath;
            try
            {
                await _processHost.StartAsync(executable, configPath, mode, ct).ConfigureAwait(false);
            }
            finally
            {
                SecureDelete(configPath);
                _configPath = null;
            }
            Publish(new MacConnectionSnapshot(
                MacConnectionState.Connected,
                mode,
                profile.Name,
                _proxyPort,
                compiled.BypassIps.Count + compiled.BypassDomains.Count,
                ""));
            Log.Information("mac_connection_started {Mode} {Protocol}", mode, profile.Protocol);
        }
        catch (Exception exception)
        {
            await StopCoreAsync(CancellationToken.None).ConfigureAwait(false);
            _activeProcessConfigPath = null;
            Publish(new MacConnectionSnapshot(
                MacConnectionState.Error,
                mode,
                _snapshot.ActiveProfileName,
                _proxyPort,
                0,
                ErrorCode(exception)));
            throw;
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task StartNativeAsync(
        MacConnectionMode mode,
        NativeOutboundLease lease,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        bool bypassChinaMainland = false,
        CancellationToken ct = default)
        => await StartNativeAsync(
            mode,
            lease,
            panelUrl,
            forwards,
            bypassChinaMainland,
            "restricted",
            ct).ConfigureAwait(false);

    public async Task StartNativeAsync(
        MacConnectionMode mode,
        NativeOutboundLease lease,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        bool bypassChinaMainland,
        string nativeRoutePolicy,
        CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(lease);
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        var transferred = false;
        var relayStarted = false;
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            if (!lease.IsUsable(DateTimeOffset.UtcNow))
                throw new InvalidOperationException("native_lease_invalid");
            if (!string.Equals(
                    NativeOutboundLease.NormalizeRoutePolicy(nativeRoutePolicy),
                    lease.RoutePolicy,
                    StringComparison.Ordinal))
                throw new InvalidOperationException("native_route_policy_mismatch");
            if (mode is not (MacConnectionMode.Proxy or MacConnectionMode.Vpn))
                throw new InvalidOperationException("connection_mode_invalid");
            Publish(new MacConnectionSnapshot(
                MacConnectionState.Starting,
                mode,
                NativeOutboundDisplayName,
                _proxyPort,
                0,
                ""));
            await StopCoreAsync(ct).ConfigureAwait(false);
            var resolution = await _resolver.ResolveAsync(panelUrl, forwards ?? [], ct).ConfigureAwait(false);
            var nativeRelayPort = AllocateLoopbackPort(_proxyPort);
            var ruleSets = await MacNativeRuleSetStore.EnsureAsync(
                Path.Combine(_paths.StateDirectory, "native-rule-sets"), ct).ConfigureAwait(false);
            // The association that carried the previous session already measured a payload
            // ceiling; the instance started below re-measures and refreshes it while it runs.
            var relayStatus = ReadNativeRelayStatus(lease.ForwardId);
            var tunMtu = UltraRuntimeMtu.Resolve(_tunMtu, relayStatus.PayloadCeiling);
            Log.Information(
                "mac_native_tun_mtu forward={ForwardId} ceiling={Ceiling} mtu={Mtu} oversize_drops={Drops}",
                lease.ForwardId, relayStatus.PayloadCeiling, tunMtu, relayStatus.OversizeDrops);
            var compiled = MacConnectionConfigBuilder.BuildNative(
                lease,
                mode,
                resolution.All,
                _proxyPort,
                bypassChinaMainland,
                nativeProxyHost: "127.0.0.1",
                nativeProxyPort: nativeRelayPort,
                ruleSetPaths: ruleSets,
                tunMtu: tunMtu,
                measuredPayloadCeiling: relayStatus.PayloadCeiling);
            var executable = _paths.BinDirectory.TrimEnd('/', '\\') + "/sing-box";
            if (!File.Exists(executable))
                throw new FileNotFoundException("sing-box helper is missing", executable);
            var ultraExecutable = _paths.BinDirectory.TrimEnd('/', '\\') + "/ultra-client";
            if (!File.Exists(ultraExecutable))
                throw new FileNotFoundException("ultra-client helper is missing", ultraExecutable);
            var egressInterface = EgressInterfaceConfig.ResolveOrThrow(_preferredEgressInterface);
            await _nativeRelayHost.StartWithEgressAsync(
                lease.ForwardId,
                ultraExecutable,
                nativeRelayPort,
                lease,
                _paths.RuntimeDirectory,
                ct,
                egressInterface).ConfigureAwait(false);
            relayStarted = true;
            var configPath = Path.Combine(
                _paths.RuntimeDirectory,
                $"connection-{Guid.NewGuid():N}.json");
            _configPath = configPath;
            _activeProcessConfigPath = configPath;
            await WriteEphemeralConfigAsync(configPath, compiled.Json, ct).ConfigureAwait(false);
            try
            {
                await _processHost.StartAsync(executable, configPath, mode, ct).ConfigureAwait(false);
            }
            finally
            {
                // The native config stays on disk (0600, wiped by StopCoreAsync) for the
                // whole session: sing-box re-reads that exact path when a re-measured
                // payload ceiling changes the TUN MTU.
                _configPath = null;
            }
            _activeNativeLease = lease;
            _activeNativePayloadCeiling = relayStatus.PayloadCeiling;
            _activeNativeTunMtu = tunMtu;
            _activeNativeMode = mode;
            _activeNativeBypassHosts = resolution.All.ToList();
            _activeNativeBypassChina = bypassChinaMainland;
            _activeNativeRelayPort = nativeRelayPort;
            _activeNativeRuleSets = ruleSets;
            _nativeTunMtuAppliedAt = null;
            transferred = true;
            Publish(new MacConnectionSnapshot(
                MacConnectionState.Connected,
                mode,
                NativeOutboundDisplayName,
                _proxyPort,
                compiled.BypassIps.Count + compiled.BypassDomains.Count,
                ""));
            Log.Information("mac_native_connection_started {Mode}", mode);
        }
        catch (Exception exception)
        {
            Log.Warning("mac native connection failed error={ErrorCode}", ErrorCode(exception));
            if (relayStarted || _configPath is not null)
                await StopCoreAsync(CancellationToken.None).ConfigureAwait(false);
            if (!transferred)
                lease.Dispose();
            Publish(new MacConnectionSnapshot(
                MacConnectionState.Error,
                mode,
                NativeOutboundDisplayName,
                _proxyPort,
                0,
                ErrorCode(exception)));
            throw;
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task<bool> TryRefreshNativeAsync(
        NativeOutboundLease lease,
        CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(lease);
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            var current = _activeNativeLease;
            if (_disposed
                || current is null
                || _snapshot.State != MacConnectionState.Connected
                || !_processHost.IsRunning
                || lease == current
                || lease.ForwardId != current.ForwardId
                || !string.Equals(lease.RouteScope, current.RouteScope, StringComparison.OrdinalIgnoreCase)
                || !string.Equals(lease.RoutePolicy, current.RoutePolicy, StringComparison.Ordinal)
                || !string.Equals(lease.Transport, current.Transport, StringComparison.OrdinalIgnoreCase)
                || !string.Equals(lease.ScopeDigest, current.ScopeDigest, StringComparison.OrdinalIgnoreCase)
                || !lease.IsRuntimeUsable(DateTimeOffset.UtcNow))
                return false;

            // A lease refresh alone cannot carry a re-measured TUN MTU and the caller's
            // restart path is still the fallback when the in-place reload did not take:
            // decline the refresh (leaving the lease to the caller) while the MTU is pending.
            if (IsNativeTunMtuPending)
                return false;

            if (!await _nativeRelayHost.RefreshAsync(lease.ForwardId, lease, ct).ConfigureAwait(false))
                return false;

            _activeNativeLease = lease;
            current.Dispose();
            return true;
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task<bool> ApplyPendingNativeTunMtuAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            // Holding the gate means this can never interleave with a start or a stop.
            var lease = _activeNativeLease;
            if (_disposed
                || lease is null
                || _snapshot.State != MacConnectionState.Connected
                || !_processHost.IsRunning)
                return false;
            var status = ReadNativeRelayStatus(lease.ForwardId);
            var targetMtu = UltraRuntimeMtu.Resolve(_tunMtu, status.PayloadCeiling);
            if (_activeNativeTunMtu > 0 && targetMtu == _activeNativeTunMtu)
            {
                // The ceiling wobbled inside the same resolved MTU: nothing to reload.
                _activeNativePayloadCeiling = status.PayloadCeiling;
                return true;
            }
            if (_activeNativeMode != MacConnectionMode.Vpn)
            {
                // Only the TUN inbound carries the MTU; a proxy-only config has nothing to
                // reload, so the measured ceiling is not pending for it at all.
                _activeNativeTunMtu = targetMtu;
                _activeNativePayloadCeiling = status.PayloadCeiling;
                return true;
            }
            var configPath = _activeProcessConfigPath;
            if (string.IsNullOrEmpty(configPath) || !File.Exists(configPath))
                return false;
            var now = _utcNow();
            if (_nativeTunMtuAppliedAt is { } appliedAt
                && now - appliedAt < _nativeTunMtuApplyInterval)
                return false;

            string json;
            try
            {
                json = MacConnectionConfigBuilder.BuildNative(
                    lease,
                    _activeNativeMode,
                    _activeNativeBypassHosts,
                    _proxyPort,
                    _activeNativeBypassChina,
                    nativeProxyHost: "127.0.0.1",
                    nativeProxyPort: _activeNativeRelayPort,
                    ruleSetPaths: _activeNativeRuleSets,
                    tunMtu: _tunMtu,
                    measuredPayloadCeiling: status.PayloadCeiling).Json;
            }
            catch (Exception exception) when (exception is InvalidOperationException or ArgumentException)
            {
                // An expired or rejected lease is not a reload failure to retry here: the
                // caller's lease-renewal restart path owns it.
                Log.Warning(
                    "mac_native_tun_mtu_rebuild_failed forward={ForwardId} error={ErrorCode}",
                    lease.ForwardId,
                    exception.Message);
                return false;
            }

            _nativeTunMtuAppliedAt = now;
            await RewriteEphemeralConfigAsync(configPath, json, ct).ConfigureAwait(false);
            if (!await _processHost.ReloadAsync(ct).ConfigureAwait(false))
            {
                Log.Warning(
                    "mac_native_tun_mtu_reload_failed forward={ForwardId} ceiling={Ceiling} mtu={Mtu}",
                    lease.ForwardId,
                    status.PayloadCeiling,
                    targetMtu);
                return false;
            }

            _activeNativeTunMtu = targetMtu;
            _activeNativePayloadCeiling = status.PayloadCeiling;
            Log.Information(
                "mac_native_tun_mtu_reloaded forward={ForwardId} ceiling={Ceiling} mtu={Mtu}",
                lease.ForwardId,
                status.PayloadCeiling,
                targetMtu);
            return true;
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task StopAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            await StopCoreAsync(ct).ConfigureAwait(false);
            Publish(MacConnectionSnapshot.Disconnected);
        }
        finally
        {
            _gate.Release();
        }
    }

    public async ValueTask DisposeAsync()
    {
        if (_disposed)
            return;
        _disposed = true;
        _processHost.UnexpectedExit -= OnProcessExited;
        await StopAsync(CancellationToken.None).ConfigureAwait(false);
        await _processHost.DisposeAsync().ConfigureAwait(false);
        await _nativeRelayHost.DisposeAsync().ConfigureAwait(false);
        _gate.Dispose();
    }

    private async Task StopCoreAsync(CancellationToken ct)
    {
        var processConfigPath = _activeProcessConfigPath;
        _activeProcessConfigPath = null;
        try { await _processHost.StopAsync(ct).ConfigureAwait(false); }
        finally
        {
            try { await _nativeRelayHost.StopAsync(CancellationToken.None).ConfigureAwait(false); }
            finally
            {
                if (_configPath is not null) SecureDelete(_configPath);
                _configPath = null;
                // The live config holds provider credentials; wipe it with the session.
                if (processConfigPath is not null)
                {
                    SecureDelete(processConfigPath);
                    SecureDelete(processConfigPath + ".tmp");
                }
                _activeNativeLease?.Dispose();
                _activeNativeLease = null;
                _activeNativePayloadCeiling = 0;
                _activeNativeTunMtu = 0;
                _activeNativeBypassHosts = [];
                _activeNativeBypassChina = false;
                _activeNativeRelayPort = 0;
                _activeNativeRuleSets = null;
                _nativeTunMtuAppliedAt = null;
            }
        }
    }

    private void OnProcessExited(MacConnectionProcessExit exit)
        => _ = Task.Run(() => HandleProcessExitAsync(exit));

    private async Task HandleProcessExitAsync(MacConnectionProcessExit exit)
    {
        if (_disposed) return;
        try
        {
            await _gate.WaitAsync().ConfigureAwait(false);
            try
            {
                if (_disposed || _activeProcessConfigPath != exit.ConfigPath) return;
                var snapshot = _snapshot;
                try { await StopCoreAsync(CancellationToken.None).ConfigureAwait(false); }
                finally { Publish(snapshot with { State = MacConnectionState.Error, ErrorCode = exit.ErrorCode }); }
            }
            finally { _gate.Release(); }
        }
        catch (ObjectDisposedException) { }
        catch (Exception)
        {
            Log.Warning("mac connection exit cleanup failed");
        }
    }

    private MacUltraRuntimeStatus ReadNativeRelayStatus(int forwardId)
        => MacUltraRuntimeStatusReader.Read(
            MacUltraRuntimeStatusReader.FindNativeRelayStatusPath(_paths.RuntimeDirectory, forwardId));

    private static int AllocateLoopbackPort(int avoidPort)
    {
        for (var attempt = 0; attempt < 8; attempt++)
        {
            var listener = new TcpListener(IPAddress.Loopback, 0);
            listener.Start();
            var port = ((IPEndPoint)listener.LocalEndpoint).Port;
            listener.Stop();
            if (port != avoidPort)
                return port;
        }
        throw new InvalidOperationException("native_relay_port_unavailable");
    }

    private static async Task WriteEphemeralConfigAsync(string path, string json, CancellationToken ct)
    {
        var bytes = Encoding.UTF8.GetBytes(json);
        try
        {
            await File.WriteAllBytesAsync(path, bytes, ct).ConfigureAwait(false);
            if (!OperatingSystem.IsWindows())
                File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(bytes);
        }
    }

    /// <summary>
    /// Replace a live config in place. sing-box re-reads this exact path, so the new content
    /// must be complete before the reload is requested; the rename keeps a partially written
    /// file from ever being the one it reads.
    /// </summary>
    private static async Task RewriteEphemeralConfigAsync(string path, string json, CancellationToken ct)
    {
        var temp = path + ".tmp";
        await WriteEphemeralConfigAsync(temp, json, ct).ConfigureAwait(false);
        try
        {
            File.Move(temp, path, overwrite: true);
        }
        catch
        {
            SecureDelete(temp);
            throw;
        }
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

    private int NextId()
    {
        for (var id = 1; id <= 256; id++)
        {
            if (_profiles.All(profile => profile.Id != id))
                return id;
        }
        return _profiles.Count + 1;
    }

    private void Publish(MacConnectionSnapshot snapshot)
    {
        _snapshot = snapshot;
        SnapshotChanged?.Invoke(snapshot);
    }

    private static string ErrorCode(Exception exception)
    {
        var code = exception switch
        {
            FileNotFoundException => "sing_box_missing",
            KeyNotFoundException => "connection_profile_not_found",
            UnauthorizedAccessException => "permission_required",
            _ => exception.Message,
        };
        var normalized = new string((code ?? "connection_failed").ToLowerInvariant()
            .Where(character => character is >= 'a' and <= 'z' or >= '0' and <= '9' or '_')
            .Take(64)
            .ToArray());
        return normalized.Length == 0 ? "connection_failed" : normalized;
    }
}
