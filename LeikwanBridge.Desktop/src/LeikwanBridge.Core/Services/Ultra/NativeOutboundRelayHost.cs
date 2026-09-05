using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Core.Services.Ultra;

public sealed record NativeOutboundRelayEndpoint(string Host, int Port);

public interface INativeOutboundRelayHost : IAsyncDisposable
{
    /// <summary>
    /// True while the relay process is still alive.  The control channel is
    /// validated separately by RefreshAsync; a rejected lease alone must not
    /// justify tearing down the attached sing-box/TUN data plane.
    /// </summary>
    bool IsRunning { get; }

    Task<NativeOutboundRelayEndpoint> StartAsync(
        int forwardId,
        string ultraBinaryPath,
        int listenPort,
        NativeOutboundLease lease,
        string runtimeDirectory,
        CancellationToken ct = default);

    /// <summary>
    /// Starts a relay with a bounded candidate batch. Implementations that do
    /// not support local probing retain the legacy first-candidate behavior.
    /// </summary>
    Task<NativeOutboundRelayEndpoint> StartCandidatesAsync(
        int forwardId,
        string ultraBinaryPath,
        int listenPort,
        IReadOnlyList<NativeOutboundLease> leases,
        string runtimeDirectory,
        CancellationToken ct = default)
    {
        if (leases is null || leases.Count == 0)
            throw new ArgumentException("native relay lease batch is empty", nameof(leases));
        return StartAsync(forwardId, ultraBinaryPath, listenPort, leases[0], runtimeDirectory, ct);
    }

    /// <summary>
    /// Delivers a replacement lease to the already-running relay.  Returning
    /// false means the relay/control channel is no longer usable; callers may
    /// then perform a full restart.
    /// </summary>
    Task<bool> RefreshAsync(
        int forwardId,
        NativeOutboundLease lease,
        CancellationToken ct = default);

    Task<bool> RefreshCandidatesAsync(
        int forwardId,
        IReadOnlyList<NativeOutboundLease> leases,
        CancellationToken ct = default)
    {
        if (leases is null || leases.Count == 0)
            return Task.FromResult(false);
        return RefreshAsync(forwardId, leases[0], ct);
    }

    Task StopAsync(CancellationToken ct = default);
}

/// <summary>
/// Owns the app-private Ultra process used as the native SOCKS5 facade.
/// The process receives provider material only through its protected control
/// channel; sing-box is pointed at the returned loopback endpoint.
/// </summary>
public sealed class NativeOutboundRelayHost : INativeOutboundRelayHost
{
    private readonly bool _windows;
    private readonly Func<ProcessStartInfo, Process> _processStarter;
    private readonly Func<string, int, NativeOutboundLease, CancellationToken, Task<bool>> _windowsSender;
    private readonly Func<string, int, IReadOnlyList<NativeOutboundLease>, CancellationToken, Task<bool>> _windowsBatchSender;
    private readonly Func<string, int, NativeOutboundLease, CancellationToken, Task<bool>> _unixSender;
    private readonly Func<string, int, IReadOnlyList<NativeOutboundLease>, CancellationToken, Task<bool>> _unixBatchSender;
    private readonly Func<NativeOutboundRelayEndpoint, CancellationToken, Task<bool>> _portProbe;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private Process? _process;
    private string? _runtimeRoot;
    private int _activeForwardId;
    private string? _activeControlEndpoint;

    public bool IsRunning
    {
        get
        {
            var process = _process;
            if (process is null)
                return false;
            try
            {
                return !process.HasExited;
            }
            catch (Exception exception) when (
                exception is InvalidOperationException or ObjectDisposedException)
            {
                return false;
            }
        }
    }

    public NativeOutboundRelayHost(
        bool? windows = null,
        Func<ProcessStartInfo, Process>? processStarter = null,
        Func<int, NativeOutboundLease, CancellationToken, Task<bool>>? windowsSender = null,
        Func<string, int, NativeOutboundLease, CancellationToken, Task<bool>>? unixSender = null,
        Func<NativeOutboundRelayEndpoint, CancellationToken, Task<bool>>? portProbe = null,
        Func<string, int, NativeOutboundLease, CancellationToken, Task<bool>>? windowsPipeSender = null,
        Func<string, int, IReadOnlyList<NativeOutboundLease>, CancellationToken, Task<bool>>? windowsBatchSender = null,
        Func<string, int, IReadOnlyList<NativeOutboundLease>, CancellationToken, Task<bool>>? unixBatchSender = null)
    {
        _windows = windows ?? OperatingSystem.IsWindows();
        _processStarter = processStarter ?? StartProcess;
        _windowsSender = windowsPipeSender
            ?? (windowsSender is not null
                ? ((_, forwardId, lease, ct) => windowsSender(forwardId, lease, ct))
                : UltraLeaseClient.SendNativeAsync);
        _windowsBatchSender = windowsBatchSender ?? UltraLeaseClient.SendNativeAsync;
        var unixClient = new UnixUltraLeaseClient(timeout: TimeSpan.FromSeconds(5));
        _unixSender = unixSender ?? unixClient.SendNativeAsync;
        _unixBatchSender = unixBatchSender ?? unixClient.SendNativeAsync;
        _portProbe = portProbe ?? ProbePortAsync;
    }

    public Task<NativeOutboundRelayEndpoint> StartAsync(
        int forwardId,
        string ultraBinaryPath,
        int listenPort,
        NativeOutboundLease lease,
        string runtimeDirectory,
        CancellationToken ct = default)
        => StartCandidatesAsync(
            forwardId,
            ultraBinaryPath,
            listenPort,
            new[] { lease },
            runtimeDirectory,
            ct);

    public async Task<NativeOutboundRelayEndpoint> StartCandidatesAsync(
        int forwardId,
        string ultraBinaryPath,
        int listenPort,
        IReadOnlyList<NativeOutboundLease> leases,
        string runtimeDirectory,
        CancellationToken ct = default)
    {
        if (leases is null || leases.Count is < 1 or > 4 || leases.Any(lease => lease is null))
            throw new ArgumentException("native relay lease batch is invalid", nameof(leases));
        var lease = leases[0];
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        var leaseTransferred = false;
        try
        {
            if (forwardId <= 0 || listenPort is < 1 or > 65535
                || string.IsNullOrWhiteSpace(ultraBinaryPath)
                || !File.Exists(ultraBinaryPath)
                || !lease.IsRuntimeUsable(DateTimeOffset.UtcNow))
                throw new InvalidOperationException("native_relay_input_invalid");

            await StopCoreAsync().ConfigureAwait(false);
            var instanceId = Guid.NewGuid().ToString("N");
            var root = Path.GetFullPath(Path.Combine(
                runtimeDirectory,
                $"native-{forwardId}-{instanceId}"));
            Directory.CreateDirectory(root);
            SetPrivateDirectoryMode(root);
            var configPath = Path.Combine(root, "runtime.json");
            var statusPath = Path.Combine(root, "status.json");
            var controlPipeName = UltraLeaseClient.NativePipeName(forwardId, instanceId);
            var controlPath = _windows
                ? $"\\\\.\\pipe\\{controlPipeName}"
                : Path.Combine(root, "control.sock");
            var config = new Dictionary<string, object?>
            {
                ["forward_id"] = forwardId,
                ["listen_host"] = "127.0.0.1",
                ["listen_port"] = listenPort,
                ["target"] = "1.1.1.1:443",
                ["native_socks5"] = true,
                ["native_route_policy"] = lease.RoutePolicy,
                ["native_port_min"] = 1000,
                ["native_filter_profile"] = lease.RoutePolicy == "unrestricted" ? "off" : "opengfw_tcp",
                ["control_socket"] = controlPath,
                ["transport"] = "tcp+udp",
                ["policy_generation"] = lease.PolicyGeneration,
                ["target_digest"] = lease.ScopeDigest,
                ["max_tcp_connections"] = 512,
                ["udp_queue_packets"] = 128,
                ["udp_max_associations"] = 1024,
                ["connect_timeout_seconds"] = 5,
                ["auto_select"] = leases.Count > 1,
                ["probe_duration_seconds"] = 5,
                ["probe_timeout_ms"] = 800,
                ["probe_workers"] = Math.Min(4, leases.Count),
                ["probe_samples"] = 5,
                ["probe_interval_ms"] = 1000,
                ["status_path"] = statusPath,
                ["location_cache_path"] = Path.Combine(root, "location.json"),
                ["location_lock_path"] = Path.Combine(root, "location.lock"),
            };
            var configBytes = JsonSerializer.SerializeToUtf8Bytes(config);
            try
            {
                await File.WriteAllBytesAsync(configPath, configBytes, ct).ConfigureAwait(false);
                SetPrivateFileMode(configPath);
            }
            finally
            {
                CryptographicOperations.ZeroMemory(configBytes);
            }

            var startInfo = new ProcessStartInfo
            {
                FileName = ultraBinaryPath,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                WorkingDirectory = root,
            };
            startInfo.ArgumentList.Add("--config");
            startInfo.ArgumentList.Add(configPath);
            var process = _processStarter(startInfo);
            _process = process;
            _runtimeRoot = root;
            process.OutputDataReceived += (_, _) => { };
            process.ErrorDataReceived += (_, _) => { };
            process.BeginOutputReadLine();
            process.BeginErrorReadLine();

            var endpoint = new NativeOutboundRelayEndpoint("127.0.0.1", listenPort);
            try
            {
                if (!_windows && !await WaitForPathAsync(controlPath, TimeSpan.FromSeconds(3), ct).ConfigureAwait(false))
                    throw new InvalidOperationException("native_relay_control_unavailable");
                var delivered = leases.Count > 1
                    ? (_windows
                        ? await _windowsBatchSender(controlPipeName, forwardId, leases, ct).ConfigureAwait(false)
                        : await _unixBatchSender(controlPath, forwardId, leases, ct).ConfigureAwait(false))
                    : (_windows
                        ? await _windowsSender(controlPipeName, forwardId, lease, ct).ConfigureAwait(false)
                        : await _unixSender(controlPath, forwardId, lease, ct).ConfigureAwait(false));
                if (!delivered)
                    throw new InvalidOperationException("native_relay_lease_rejected");
                if (!await _portProbe(endpoint, ct).ConfigureAwait(false))
                    throw new InvalidOperationException("native_relay_listener_unavailable");
                _activeForwardId = forwardId;
                _activeControlEndpoint = _windows ? controlPipeName : controlPath;
                // The caller now owns the lease and will wipe it when the
                // connection stops. Keeping the object alive here would make
                // the caller believe it owns usable credentials after they
                // have already been cleared.
                leaseTransferred = true;
                return endpoint;
            }
            catch
            {
                await StopCoreAsync().ConfigureAwait(false);
                throw;
            }
        }
        finally
        {
            // Validation/startup failures remain owned by this method. On a
            // successful return the service takes ownership and disposes the
            // lease during its normal stop path.
            if (!leaseTransferred)
            {
                foreach (var candidate in leases)
                    candidate.Dispose();
            }
            _gate.Release();
        }
    }

    public async Task<bool> RefreshAsync(
        int forwardId,
        NativeOutboundLease lease,
        CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(lease);
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (forwardId <= 0
                || lease.ForwardId != forwardId
                || !lease.IsRuntimeUsable(DateTimeOffset.UtcNow)
                || !IsRunning
                || _activeForwardId != forwardId
                || string.IsNullOrWhiteSpace(_activeControlEndpoint))
                return false;

            try
            {
                return _windows
                    ? await _windowsSender(_activeControlEndpoint!, forwardId, lease, ct).ConfigureAwait(false)
                    : await _unixSender(_activeControlEndpoint!, forwardId, lease, ct).ConfigureAwait(false);
            }
            catch (Exception exception) when (
                exception is IOException
                    or SocketException
                    or TimeoutException
                    or InvalidOperationException
                    or System.ComponentModel.Win32Exception)
            {
                return false;
            }
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task<bool> RefreshCandidatesAsync(
        int forwardId,
        IReadOnlyList<NativeOutboundLease> leases,
        CancellationToken ct = default)
    {
        if (leases is null || leases.Count is < 1 or > 4 || leases.Any(lease => lease is null))
            return false;
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (forwardId <= 0
                || leases.Any(lease => lease.ForwardId != forwardId
                    || !lease.IsRuntimeUsable(DateTimeOffset.UtcNow))
                || !IsRunning
                || _activeForwardId != forwardId
                || string.IsNullOrWhiteSpace(_activeControlEndpoint))
                return false;

            try
            {
                return leases.Count > 1
                    ? (_windows
                        ? await _windowsBatchSender(_activeControlEndpoint!, forwardId, leases, ct).ConfigureAwait(false)
                        : await _unixBatchSender(_activeControlEndpoint!, forwardId, leases, ct).ConfigureAwait(false))
                    : (_windows
                        ? await _windowsSender(_activeControlEndpoint!, forwardId, leases[0], ct).ConfigureAwait(false)
                        : await _unixSender(_activeControlEndpoint!, forwardId, leases[0], ct).ConfigureAwait(false));
            }
            catch (Exception exception) when (
                exception is IOException
                    or SocketException
                    or TimeoutException
                    or InvalidOperationException
                    or System.ComponentModel.Win32Exception)
            {
                return false;
            }
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
            await StopCoreAsync().ConfigureAwait(false);
        }
        finally
        {
            _gate.Release();
        }
    }

    public async ValueTask DisposeAsync()
    {
        await StopAsync(CancellationToken.None).ConfigureAwait(false);
        _gate.Dispose();
    }

    private async Task StopCoreAsync()
    {
        var process = _process;
        _process = null;
        _activeForwardId = 0;
        _activeControlEndpoint = null;
        if (process is not null)
        {
            try
            {
                if (!process.HasExited)
                    process.Kill(entireProcessTree: true);
                await process.WaitForExitAsync().ConfigureAwait(false);
            }
            catch (InvalidOperationException)
            {
            }
            catch (System.ComponentModel.Win32Exception)
            {
            }
            finally
            {
                process.Dispose();
            }
        }
        var root = _runtimeRoot;
        _runtimeRoot = null;
        if (!string.IsNullOrWhiteSpace(root))
            SecureDeleteDirectory(root);
    }

    private static Process StartProcess(ProcessStartInfo startInfo)
        => Process.Start(startInfo) ?? throw new InvalidOperationException("native_relay_process_start_failed");

    private static async Task<bool> ProbePortAsync(NativeOutboundRelayEndpoint endpoint, CancellationToken ct)
    {
        var deadline = DateTime.UtcNow + TimeSpan.FromSeconds(15);
        while (DateTime.UtcNow < deadline)
        {
            ct.ThrowIfCancellationRequested();
            try
            {
                using var client = new TcpClient();
                await client.ConnectAsync(endpoint.Host, endpoint.Port, ct).ConfigureAwait(false);
                return true;
            }
            catch (SocketException)
            {
                await Task.Delay(100, ct).ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
                throw;
            }
        }
        return false;
    }

    private static async Task<bool> WaitForPathAsync(string path, TimeSpan timeout, CancellationToken ct)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (File.Exists(path))
                return true;
            await Task.Delay(25, ct).ConfigureAwait(false);
        }
        return File.Exists(path);
    }

    private static void SetPrivateDirectoryMode(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
    }

    private static void SetPrivateFileMode(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
    }

    private static void SecureDeleteDirectory(string path)
    {
        try
        {
            if (!Directory.Exists(path))
                return;
            foreach (var file in Directory.EnumerateFiles(path, "*", SearchOption.AllDirectories))
            {
                try
                {
                    var length = new FileInfo(file).Length;
                    if (length is > 0 and <= 4 * 1024 * 1024)
                    {
                        using var stream = new FileStream(file, FileMode.Open, FileAccess.Write, FileShare.Read, 4096, FileOptions.WriteThrough);
                        var zeros = new byte[(int)length];
                        stream.Write(zeros, 0, zeros.Length);
                        stream.Flush(true);
                        CryptographicOperations.ZeroMemory(zeros);
                    }
                }
                catch (IOException)
                {
                }
            }
            Directory.Delete(path, recursive: true);
        }
        catch (IOException)
        {
        }
        catch (UnauthorizedAccessException)
        {
        }
    }
}
