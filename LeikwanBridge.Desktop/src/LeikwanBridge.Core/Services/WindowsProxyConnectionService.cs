using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Ultra;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Owns one local sing-box instance and the optional NetFilter2 process
/// redirector.  A single gate makes mode changes transactional: the previous
/// mode is stopped before a new config is exposed to the operating system.
/// </summary>
public sealed class WindowsProxyConnectionService : IAsyncDisposable
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
    private readonly INativeOutboundRelayHost _nativeRelayHost;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly List<WindowsProxyProfile> _profiles = [];
    private WindowsProxySnapshot _snapshot = WindowsProxySnapshot.Disconnected;
    private int _activeProfileId;
    private NativeOutboundLease? _activeNativeLease;
    private bool _stopCompleted;
    private bool _disposed;

    public const string NativeOutboundDisplayName = "原生出口";

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
        INativeOutboundRelayHost? nativeRelayHost = null)
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
        _tunStack = tunStack;
        _chinaIpListPath = chinaIpListPath ?? string.Empty;
        _nativeRelayHost = nativeRelayHost ?? new NativeOutboundRelayHost();
    }

    public event Action<WindowsProxySnapshot>? SnapshotChanged;

    public IReadOnlyList<WindowsProxyProfile> Profiles
    {
        get
        {
            lock (_profiles)
                return _profiles.Select(profile => profile.Clone()).ToList();
        }
    }

    public WindowsProxySnapshot Snapshot => _snapshot;

    public DateTimeOffset? ActiveNativeLeaseExpiresAt => _activeNativeLease?.ExpiresAt;

    public string? ActiveNativeLeasePolicyGeneration => _activeNativeLease?.PolicyGeneration;

    public bool IsNativeOutboundActive
        => _activeNativeLease is not null
            && _snapshot.State == WindowsProxyConnectionState.Connected;

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
        WindowsProxyConfigBuilder.Build(profile, WindowsProxyMode.Http, [], _proxyPort, _tunMtu, _tunStack);
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
        IEnumerable<string>? customDnsServers = null)
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
                customDnsServers);

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
                    await _processHost.StartAsync(_singBoxPath, configPath, mode, ct);
                    if (mode == WindowsProxyMode.Process
                        && _processHost is WindowsProxyProcessHost concreteHost
                        && !await concreteHost.WaitForListenerAsync(proxyPort, ct))
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
    public async Task StartNativeAsync(
        WindowsProxyMode mode,
        NativeOutboundLease lease,
        IEnumerable<string>? bypassHosts = null,
        IEnumerable<WindowsProcessProxyRule>? processRules = null,
        CancellationToken ct = default,
        bool bypassChinaMainland = false,
        WindowsProxyDnsMode dnsMode = WindowsProxyDnsMode.Remote,
        IEnumerable<string>? customDnsServers = null)
    {
        ArgumentNullException.ThrowIfNull(lease);
        await _gate.WaitAsync(ct);
        var previousSnapshot = _snapshot;
        var connectionStopped = false;
        var proxyPort = _proxyPort;
        var leaseTransferred = false;
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            if (!lease.IsUsable(DateTimeOffset.UtcNow))
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
                nativeProxyPort: nativeRelayPort);

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
            await _nativeRelayHost.StartAsync(
                lease.ForwardId,
                ResolveUltraBinaryPath(),
                nativeRelayPort,
                lease,
                _runtimeDirectory,
                ct);
            Directory.CreateDirectory(_runtimeDirectory);
            var configPath = Path.Combine(_runtimeDirectory, $"proxy-{Guid.NewGuid():N}.json");
            try
            {
                try
                {
                    await WriteEphemeralConfigAsync(configPath, compiled.Json, ct);
                    await _processHost.StartAsync(_singBoxPath, configPath, mode, ct);
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

            _activeProfileId = 0;
            _activeNativeLease = lease;
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
                lease.Dispose();
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
                || _snapshot.State != WindowsProxyConnectionState.Connected
                || lease == current
                || lease.ForwardId != current.ForwardId
                || !string.Equals(lease.RouteScope, current.RouteScope, StringComparison.OrdinalIgnoreCase)
                || !string.Equals(lease.Transport, current.Transport, StringComparison.OrdinalIgnoreCase)
                || !string.Equals(lease.ScopeDigest, current.ScopeDigest, StringComparison.OrdinalIgnoreCase)
                || !lease.IsRuntimeUsable(DateTimeOffset.UtcNow))
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

    private async Task StopCoreAsync(CancellationToken ct)
    {
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
            _activeNativeLease?.Dispose();
            _activeNativeLease = null;
            _stopCompleted = true;
            return;
        }

        _activeNativeLease?.Dispose();
        _activeNativeLease = null;
        _stopCompleted = false;
        if (failures.Count == 1)
            throw failures[0];
        if (failures.Count > 1)
            throw new AggregateException("proxy_stop_failed", failures);
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

    private static string ErrorCode(Exception exception)
    {
        var code = exception switch
        {
            AggregateException aggregate when aggregate.Flatten().InnerExceptions.Count > 0
                => ErrorCode(aggregate.Flatten().InnerExceptions[0]),
            FileNotFoundException file when file.FileName?.Contains("sing", StringComparison.OrdinalIgnoreCase) == true
                => "sing_box_missing",
            FileNotFoundException => "proxy_config_missing",
            KeyNotFoundException => "proxy_profile_not_found",
            WindowsProxyProcessExitException => "proxy_process_exited",
            UnauthorizedAccessException => "permission_required",
            _ => exception.Message,
        };
        var normalized = new string((code ?? "proxy_connection_failed").ToLowerInvariant()
            .Where(character => character is >= 'a' and <= 'z' or >= '0' and <= '9' or '_')
            .Take(64)
            .ToArray());
        return normalized.Length == 0 ? "proxy_connection_failed" : normalized;
    }
}
