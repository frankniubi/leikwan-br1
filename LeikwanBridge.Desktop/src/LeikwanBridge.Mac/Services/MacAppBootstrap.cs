using System.Diagnostics;
using System.Runtime.InteropServices;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;
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
        ConfigureLogging(paths, DesktopLogLevel.Warning);
        Log.Information("mac_bootstrap_begin");
        // Panel/update traffic must egress through the physical interface
        // (e.g. en0, discovered dynamically) even when a utun TUN is up.
        PanelDirectEgress.Initialize();
        var secretsPath = MacSecretStoreSelection.SecretsFilePath(paths);
        MacSecretStoreSelection.MergeLegacyFallbackFile(secretsPath);
        ISecretStore secrets;
        if (MacSecretStoreSelection.PreferKeychain(paths.ConfigPath))
        {
            var keychain = new MacKeychainSecretStore(new MacKeychainNative(), secretsPath);
            keychain.WarningRaised += code => Log.Warning("{WarningCode}", code);
            secrets = keychain;
        }
        else
        {
            var file = new MacFileSecretStore(secretsPath, new MacKeychainNative());
            file.WarningRaised += code => Log.Warning("{WarningCode}", code);
            await file.MigrateLegacyAsync(ct).ConfigureAwait(false);
            secrets = file;
        }
        var configStore = new MacAgentConfigStore(paths, secrets);
        Log.Information("mac_bootstrap_config_load_begin");
        var config = await configStore.LoadAsync(ct).ConfigureAwait(false);
        Log.Information("mac_bootstrap_config_load_complete");
        config.LogLevel = DesktopLogLevel.Normalize(config.LogLevel);
        ConfigureLogging(paths, config.LogLevel);
        config.MasterUrl = PanelEndpoints.NormalizeKnownUrl(config.MasterUrl);
        if (string.IsNullOrWhiteSpace(config.MasterUrl))
            config.MasterUrl = PanelEndpoints.Default.Url;
        PanelDirectEgress.SetPreferredInterface(config.PreferredEgressInterface);
        StartOrphanRuntimeReap(paths, config);
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
            new MacConnectionProfileStore(secrets),
            preferredEgressInterface: config.PreferredEgressInterface);
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
            ultraRuntimeHost: ultraHost,
            proxyPortOwners: ownership.FindOwners);
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

    /// <summary>
    /// A previous host that crashed can leave ultra-client runtimes behind
    /// (reparented to launchd, still holding relay sessions). Reap them in the
    /// background: only ppid-1 processes of this bundle's helper binary and this
    /// uid are touched, so the children this host is about to start are never
    /// candidates, and startup does not wait on the scan.
    /// </summary>
    private static void StartOrphanRuntimeReap(MacPlatformPaths paths, AgentConfig config)
    {
        if (!OperatingSystem.IsMacOS())
            return;
        var executables = new List<string> { Path.Combine(paths.BinDirectory, "ultra-client") };
        try
        {
            executables.Add(config.GetEffectiveUltraBin());
        }
        catch (Exception exception)
        {
            Log.Debug("orphan_runtime_path_unavailable type={ErrorType}", exception.GetType().Name);
        }
        _ = Task.Run(() =>
        {
            try
            {
                var reaped = MacOrphanRuntimeReaper.CreateDefault().Reap(executables);
                if (reaped.Count > 0)
                    Log.Warning("orphan_runtime_reaped count={Count}", reaped.Count);
            }
            catch (Exception exception)
            {
                Log.Warning("orphan_runtime_reap_failed type={ErrorType}", exception.GetType().Name);
            }
        });
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

    public static void ReconfigureLogging(MacPlatformPaths paths, string? level)
        => ConfigureLogging(paths, DesktopLogLevel.Normalize(level));

    private static void ConfigureLogging(MacPlatformPaths paths, string? level)
    {
        Log.CloseAndFlush();
        Log.Logger = new LoggerConfiguration()
            .MinimumLevel.Is(DesktopLogLevel.ToSerilog(level))
            .WriteTo.File(
                new RedactingTextFormatter("{Timestamp:yyyy-MM-dd HH:mm:ss} [{Level:u3}] {Message:lj}{NewLine}{Exception}"),
                paths.LogDirectory.TrimEnd('/', '\\') + "/client-.log",
                rollingInterval: RollingInterval.Day,
                retainedFileCountLimit: 7,
                fileSizeLimitBytes: 5 * 1024 * 1024,
                rollOnFileSizeLimit: true,
                buffered: false)
            .CreateLogger();
    }
}
