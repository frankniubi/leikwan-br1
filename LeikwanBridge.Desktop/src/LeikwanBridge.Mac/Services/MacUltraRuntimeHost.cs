using System.Collections.Concurrent;
using System.Security.Cryptography;
using System.Text.Json;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Core.Services.Ultra;
using Serilog;

namespace LeikwanBridge.Mac.Services;

public sealed class MacUltraRuntimeHost : IUltraRuntimeHost
{
    private sealed record RuntimeState(string Directory, string SocketDirectory, string SocketPath, string StatusPath);

    private readonly AgentConfig _config;
    private readonly IProcessSupervisor _supervisor;
    private readonly StateManager _stateManager;
    private readonly Func<string, bool> _binaryExists;
    private readonly Func<string, bool> _socketExists;
    private readonly Func<int, bool> _portListeningProbe;
    private readonly Func<string, int, UltraLease, CancellationToken, Task<bool>> _leaseSender;
    private readonly Func<int, PortPreflightResult>? _portPreflight;
    private readonly string _socketRoot;
    private readonly ConcurrentDictionary<int, RuntimeState> _runtimes = new();

    public MacUltraRuntimeHost(
        AgentConfig config,
        IProcessSupervisor supervisor,
        StateManager stateManager,
        Func<string, bool>? binaryExists = null,
        Func<string, bool>? socketExists = null,
        Func<int, bool>? portListeningProbe = null,
        Func<string, int, UltraLease, CancellationToken, Task<bool>>? leaseSender = null,
        Func<int, PortPreflightResult>? portPreflight = null,
        string? socketRoot = null)
    {
        _config = config;
        _supervisor = supervisor;
        _stateManager = stateManager;
        _binaryExists = binaryExists ?? File.Exists;
        _socketExists = socketExists ?? File.Exists;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
        var client = new UnixUltraLeaseClient();
        _leaseSender = leaseSender ?? client.SendAsync;
        _portPreflight = portPreflight;
        _socketRoot = socketRoot ?? (OperatingSystem.IsMacOS() ? "/tmp" : Path.GetTempPath());
    }

    public void Start(ForwardConfig forward, UltraLease lease, string contractSignature, string applyReason)
    {
        byte[] bootstrap = [];
        try
        {
            Log.Information("ultra runtime start begin for forward {ForwardId}", forward.ForwardId);
            if (forward.ListenPort is < 1 or > 65535)
                throw new InvalidOperationException("ultra_listen_port_invalid");
            var ultraBinary = _config.GetEffectiveUltraBin();
            if (!_binaryExists(ultraBinary))
                throw new InvalidOperationException("ultra_runtime_unavailable");
            if (_portPreflight is not null)
            {
                Log.Information("ultra port preflight begin for forward {ForwardId}", forward.ForwardId);
                var preflight = _portPreflight.Invoke(forward.ListenPort);
                Log.Information("ultra port preflight complete blocked={Blocked} for forward {ForwardId}",
                    preflight.Blocked, forward.ForwardId);
                if (preflight.Blocked)
                    throw new InvalidOperationException("ultra_port_conflict");
            }

            Log.Information("ultra previous runtime stop begin for forward {ForwardId}", forward.ForwardId);
            Stop(forward.ForwardId);
            Log.Information("ultra previous runtime stop complete for forward {ForwardId}", forward.ForwardId);
            var runtimeRoot = Path.Combine(_config.GetEffectiveStateDir(), "runtime");
            Directory.CreateDirectory(runtimeRoot);
            SetPrivateDirectoryMode(runtimeRoot);
            var runtimeDirectory = Path.Combine(runtimeRoot, $"ultra-{forward.ForwardId}-{Guid.NewGuid():N}");
            Directory.CreateDirectory(runtimeDirectory);
            SetPrivateDirectoryMode(runtimeDirectory);
            Directory.CreateDirectory(_socketRoot);
            var socketDirectory = Path.Combine(_socketRoot, $"lk-u-{Guid.NewGuid():N}");
            Directory.CreateDirectory(socketDirectory);
            SetPrivateDirectoryMode(socketDirectory);
            var socketPath = Path.Combine(socketDirectory, "c.sock");
            var statusPath = Path.Combine(runtimeDirectory, "status.json");
            var runtimeState = new RuntimeState(runtimeDirectory, socketDirectory, socketPath, statusPath);
            _runtimes[forward.ForwardId] = runtimeState;
            Log.Information("ultra runtime paths ready for forward {ForwardId}", forward.ForwardId);

            bootstrap = JsonSerializer.SerializeToUtf8Bytes(new
            {
                forward_id = forward.ForwardId,
                listen_host = "127.0.0.1",
                listen_port = forward.ListenPort,
                target = forward.Target,
                control_socket = socketPath,
                transport = lease.Transport,
                policy_generation = lease.PolicyGeneration,
                target_digest = lease.TargetDigest,
                max_tcp_connections = 512,
                udp_queue_packets = 64,
                connect_timeout_seconds = 5,
                status_path = statusPath,
                location_cache_path = Path.Combine(runtimeDirectory, "location.json"),
                location_lock_path = Path.Combine(runtimeDirectory, "location.lock"),
            });
            _supervisor.StartProcess(
                forward.ForwardId,
                "ultra-runtime",
                "Ultra direct runtime",
                ultraBinary,
                "--config-stdin",
                bootstrap,
                logArguments: false,
                workingDirectory: runtimeDirectory);
            Log.Information("ultra runtime process started for forward {ForwardId}", forward.ForwardId);
            var socketReady = WaitForSocket(socketPath, TimeSpan.FromSeconds(2));
            Log.Information("ultra control socket ready={SocketReady} for forward {ForwardId}", socketReady, forward.ForwardId);
            if (!socketReady)
                throw new InvalidOperationException("ultra_control_socket_not_ready");
            Log.Information("ultra lease delivery begin for forward {ForwardId}", forward.ForwardId);
            var leaseDelivered = _leaseSender(socketPath, forward.ForwardId, lease, CancellationToken.None)
                .WaitAsync(TimeSpan.FromSeconds(3))
                .GetAwaiter()
                .GetResult();
            Log.Information("ultra lease delivery result={LeaseDelivered} for forward {ForwardId}", leaseDelivered, forward.ForwardId);
            if (!leaseDelivered)
                throw new InvalidOperationException("ultra_lease_delivery_failed");
            var portReady = WaitForPort(forward.ListenPort, TimeSpan.FromSeconds(6));
            Log.Information("ultra listener ready={PortReady} for forward {ForwardId}", portReady, forward.ForwardId);
            if (!portReady)
                throw new InvalidOperationException("ultra_listener_not_ready");

            var applied = _stateManager.LoadApplied();
            applied[forward.ForwardId.ToString()] = new AppliedForwardState
            {
                ForwardId = forward.ForwardId,
                Mode = "ultra",
                ListenPort = forward.ListenPort,
                IngressMode = "direct-provider",
                EndpointCount = 0,
                LegPorts = [],
                ProcessRoles = ["ultra-runtime"],
                ProcessLabels = ["Ultra direct runtime"],
                ProcessIds = [.. _supervisor.GetProcessIds(forward.ForwardId)],
                ContractSignature = contractSignature,
                LastApplyReason = string.IsNullOrWhiteSpace(applyReason) ? "config_sync" : applyReason,
                UserStopped = false,
                UpdatedAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
            };
            _stateManager.SaveApplied(applied);
        }
        catch (Exception exception)
        {
            Log.Warning("ultra runtime start failed for forward {ForwardId} ({ErrorType})",
                forward.ForwardId, exception.GetType().Name);
            Stop(forward.ForwardId);
            throw new InvalidOperationException("ultra_runtime_start_failed");
        }
        finally
        {
            CryptographicOperations.ZeroMemory(bootstrap);
            lease.Dispose();
        }
    }

    public bool Refresh(ForwardConfig forward, UltraLease lease)
    {
        try
        {
            return _runtimes.TryGetValue(forward.ForwardId, out var runtime)
                && _socketExists(runtime.SocketPath)
                && _leaseSender(runtime.SocketPath, forward.ForwardId, lease, CancellationToken.None).GetAwaiter().GetResult();
        }
        catch
        {
            return false;
        }
        finally
        {
            lease.Dispose();
        }
    }

    public void Stop(int forwardId)
    {
        _supervisor.StopAll(forwardId);
        if (_runtimes.TryRemove(forwardId, out var runtime))
        {
            DeletePrivateDirectory(runtime.Directory, Path.Combine(_config.GetEffectiveStateDir(), "runtime"), "ultra-");
            DeletePrivateDirectory(runtime.SocketDirectory, _socketRoot, "lk-u-");
        }
    }

    private bool WaitForSocket(string socketPath, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (_socketExists(socketPath))
                return true;
            Thread.Sleep(25);
        }
        return _socketExists(socketPath);
    }

    private bool WaitForPort(int port, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (_portListeningProbe(port))
                return true;
            Thread.Sleep(25);
        }
        return _portListeningProbe(port);
    }

    private static void DeletePrivateDirectory(string directory, string allowedRoot, string requiredPrefix)
    {
        var root = Path.GetFullPath(allowedRoot);
        var resolved = Path.GetFullPath(directory);
        var rootPrefix = root.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
            + Path.DirectorySeparatorChar;
        if (!resolved.StartsWith(rootPrefix, StringComparison.Ordinal)
            || !Path.GetFileName(resolved).StartsWith(requiredPrefix, StringComparison.Ordinal))
            return;
        try
        {
            if (Directory.Exists(resolved))
                Directory.Delete(resolved, recursive: true);
        }
        catch (IOException)
        {
        }
        catch (UnauthorizedAccessException)
        {
        }
    }

    private static void SetPrivateDirectoryMode(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
    }
}
