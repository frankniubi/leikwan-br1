using System.Security.Cryptography;
using System.Text;
using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Models;
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
    private readonly INativeOutboundRelayHost _nativeRelayHost;
    private MacConnectionSnapshot _snapshot = MacConnectionSnapshot.Disconnected;
    private string? _configPath;
    private string? _activeProcessConfigPath;
    private NativeOutboundLease? _activeNativeLease;
    private bool _disposed;

    public const string NativeOutboundDisplayName = "原生出口";

    public MacConnectionService(
        MacPlatformPaths paths,
        MacConnectionProfileStore store,
        MacBypassResolver? resolver = null,
        IMacConnectionProcessHost? processHost = null,
        int proxyPort = MacConnectionConfigBuilder.DefaultProxyPort,
        INativeOutboundRelayHost? nativeRelayHost = null)
    {
        _paths = paths;
        _store = store;
        _resolver = resolver ?? new MacBypassResolver();
        _processHost = processHost ?? new MacConnectionProcessHost();
        _proxyPort = proxyPort;
        _nativeRelayHost = nativeRelayHost ?? new NativeOutboundRelayHost(windows: false);
        _processHost.UnexpectedExit += OnProcessExited;
    }

    public event Action<MacConnectionSnapshot>? SnapshotChanged;

    public IReadOnlyList<MacConnectionProfile> Profiles
    {
        get
        {
            lock (_profiles)
                return _profiles.Select(profile => profile.Clone()).ToList();
        }
    }

    public MacConnectionSnapshot Snapshot => _snapshot;

    public DateTimeOffset? ActiveNativeLeaseExpiresAt => _activeNativeLease?.ExpiresAt;

    public string? ActiveNativeLeasePolicyGeneration => _activeNativeLease?.PolicyGeneration;

    public bool IsNativeOutboundActive
        => _activeNativeLease is not null
            && _snapshot.State == MacConnectionState.Connected;

    public async Task InitializeAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            _profiles.Clear();
            _profiles.AddRange(await _store.LoadAsync(ct).ConfigureAwait(false));
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
            MacConnectionConfigBuilder.Build(profile, MacConnectionMode.Proxy, [], _proxyPort);
            var copy = profile.Clone();
            if (copy.Id <= 0)
                copy.Id = NextId();
            var index = _profiles.FindIndex(item => item.Id == copy.Id);
            if (index >= 0)
                _profiles[index] = copy;
            else
                _profiles.Add(copy);
            await _store.SaveAsync(_profiles, ct).ConfigureAwait(false);
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
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task StartAsync(
        MacConnectionMode mode,
        int profileId,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            var profile = _profiles.FirstOrDefault(item => item.Id == profileId)
                ?? throw new KeyNotFoundException("connection_profile_not_found");
            Publish(new MacConnectionSnapshot(
                MacConnectionState.Starting,
                mode,
                profile.Name,
                _proxyPort,
                0,
                ""));
            await StopCoreAsync(ct).ConfigureAwait(false);
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
            var compiled = MacConnectionConfigBuilder.BuildNative(
                lease,
                mode,
                resolution.All,
                _proxyPort,
                bypassChinaMainland,
                nativeProxyHost: "127.0.0.1",
                nativeProxyPort: nativeRelayPort,
                ruleSetPaths: ruleSets);
            var executable = _paths.BinDirectory.TrimEnd('/', '\\') + "/sing-box";
            if (!File.Exists(executable))
                throw new FileNotFoundException("sing-box helper is missing", executable);
            var ultraExecutable = _paths.BinDirectory.TrimEnd('/', '\\') + "/ultra-client";
            if (!File.Exists(ultraExecutable))
                throw new FileNotFoundException("ultra-client helper is missing", ultraExecutable);
            await _nativeRelayHost.StartAsync(
                lease.ForwardId,
                ultraExecutable,
                nativeRelayPort,
                lease,
                _paths.RuntimeDirectory,
                ct).ConfigureAwait(false);
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
                SecureDelete(configPath);
                _configPath = null;
            }
            _activeNativeLease = lease;
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
        _activeProcessConfigPath = null;
        try { await _processHost.StopAsync(ct).ConfigureAwait(false); }
        finally
        {
            try { await _nativeRelayHost.StopAsync(CancellationToken.None).ConfigureAwait(false); }
            finally
            {
                if (_configPath is not null) SecureDelete(_configPath);
                _configPath = null;
                _activeNativeLease?.Dispose();
                _activeNativeLease = null;
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
