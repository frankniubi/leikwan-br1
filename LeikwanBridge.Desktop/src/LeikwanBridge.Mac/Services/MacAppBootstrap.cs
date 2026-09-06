using System.Diagnostics;
using System.Runtime.InteropServices;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.Mac.Services;

public sealed record MacAppServices(
    AgentConfig Config,
    MacAgentConfigStore ConfigStore,
    MacAutostartService Autostart,
    DesktopClientController Controller,
    MacConnectionService Connections,
    MacAppUpdateService Updater);

public static class MacAppBootstrap
{
    public static async Task<MacAppServices> CreateAsync(MacPlatformPaths paths, CancellationToken ct = default)
    {
        ConfigureLogging(paths);
        Log.Information("mac_bootstrap_begin");
        var secrets = new MacKeychainSecretStore(
            new MacKeychainNative(),
            paths.Root.TrimEnd('/', '\\') + "/fallback-secrets.json");
        secrets.WarningRaised += code => Log.Warning("{WarningCode}", code);
        var configStore = new MacAgentConfigStore(paths, secrets);
        Log.Information("mac_bootstrap_config_load_begin");
        var config = await configStore.LoadAsync(ct).ConfigureAwait(false);
        Log.Information("mac_bootstrap_config_load_complete");
        if (string.IsNullOrWhiteSpace(config.MasterUrl))
            config.MasterUrl = "https://v3.leikwanhost.com";
        Log.Information("mac_bootstrap_identity_begin");
        var identity = new MacPlatformIdentityProvider(paths).GetOrCreate();
        Log.Information("mac_bootstrap_identity_complete");
        var autostart = new MacAutostartService(paths);
        var controller = new DesktopClientController(
            config,
            identity,
            configStore.SaveAsync,
            (currentConfig, currentIdentity) => CreateRuntimeSession(paths, currentConfig, currentIdentity));
        var connections = new MacConnectionService(
            paths,
            new MacConnectionProfileStore(secrets));
        Log.Information("mac_bootstrap_connections_load_begin");
        await connections.InitializeAsync(ct).ConfigureAwait(false);
        Log.Information("mac_bootstrap_connections_load_complete");
        var architecture = RuntimeInformation.ProcessArchitecture switch
        {
            Architecture.Arm64 => "arm64",
            Architecture.X64 => "x64",
            _ => throw new PlatformNotSupportedException("mac_architecture_unsupported"),
        };
        var updater = new MacAppUpdateService(
            paths.Root.TrimEnd('/', '\\') + "/updates",
            paths.BundleDirectory,
            architecture);
        Log.Information("mac_bootstrap_complete");
        return new MacAppServices(config, configStore, autostart, controller, connections, updater);
    }

    private static IDesktopRuntimeSession CreateRuntimeSession(
        MacPlatformPaths paths,
        AgentConfig config,
        ClientPlatformIdentity identity)
    {
        var state = new StateManager(config.GetEffectiveStateDir());
        var supervisor = new MacProcessSupervisor();
        var ownership = new MacPortOwnershipProbe();
        Func<int, PortPreflightResult> portPreflight = port => PortConflictPreflight.Run(
            port,
            [],
            ownership.FindOwners,
            ownership.ResolveExecutablePath,
            paths.BinDirectory,
            KillManagedProcess,
            TimeSpan.FromSeconds(3));
        var ultraHost = new MacUltraRuntimeHost(
            config,
            supervisor,
            state,
            portPreflight: portPreflight);
        var runtime = new ForwardRuntimeManager(
            config,
            supervisor,
            state,
            portPreflight: portPreflight,
            ultraRuntimeHost: ultraHost);
        var api = new MasterApiClient(
            config.MasterUrl,
            config.AgentToken,
            platformIdentity: identity);
        var polling = new ConfigPollingService(api, runtime, state, MacForwardPolicy.Instance);
        var heartbeat = new HeartbeatService(
            api,
            state,
            platformIdentity: identity,
            additionalRuntimeDirectories: [paths.RuntimeDirectory]);
        var health = new HealthCheckService(supervisor, runtime, state, config: config);
        var diagnostics = new ClientDiagnosticsService(supervisor, config, logDirectory: paths.LogDirectory);
        return new DesktopRuntimeSession(
            api,
            polling,
            heartbeat,
            health,
            runtime,
            diagnostics,
            config.PollIntervalSeconds,
            config.HealthCheckIntervalSeconds);
    }

    private static void KillManagedProcess(int processId)
    {
        try
        {
            using var process = Process.GetProcessById(processId);
            process.Kill(entireProcessTree: true);
            process.WaitForExit(3000);
        }
        catch
        {
        }
    }

    private static void ConfigureLogging(MacPlatformPaths paths)
    {
        Log.Logger = new LoggerConfiguration()
            .MinimumLevel.Information()
            .WriteTo.File(
                paths.LogDirectory.TrimEnd('/', '\\') + "/client-.log",
                rollingInterval: RollingInterval.Day,
                retainedFileCountLimit: 7,
                fileSizeLimitBytes: 5 * 1024 * 1024,
                rollOnFileSizeLimit: true,
                outputTemplate: "{Timestamp:yyyy-MM-dd HH:mm:ss} [{Level:u3}] {Message:lj}{NewLine}")
            .CreateLogger();
    }
}
