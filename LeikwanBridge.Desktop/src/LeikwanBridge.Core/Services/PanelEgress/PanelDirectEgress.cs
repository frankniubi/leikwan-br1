using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Static entry point for panel/update direct egress. Panel and update
/// traffic must always ignore third-party TUN tools and HTTP proxies and
/// leave through the physical NIC; see <see cref="PanelEgress.PanelDirectEgressEngine"/>
/// for the mechanics (kernel-level socket interface binding first, host
/// routes as an optional fallback).
/// </summary>
public static class PanelDirectEgress
{
    public const string CloudflarePanelHost = "v3.leikwanhost.com";
    public const string AliyunPanelHost = "api-leikwanbridge.nbwly.com";

    private static readonly HashSet<string> WellKnownSet = new(StringComparer.OrdinalIgnoreCase)
    {
        CloudflarePanelHost,
        AliyunPanelHost,
        "dns.alidns.com",
        "doh.pub",
        "doh.360.cn",
    };

    /// <summary>Panel endpoints, DoH providers and known update origins.</summary>
    public static IReadOnlyList<string> WellKnownHosts { get; } = WellKnownSet.ToArray();

    internal static PanelEgress.PanelDirectEgressEngine Engine => PanelDirectEgressBootstrap.Engine;

    private static readonly object LifecycleLock = new();
    private static CancellationTokenSource? _refreshCts;
    private static int _initialized;
    private static bool _shutdown;

    public static bool IsWellKnownHost(string? host)
        => host is not null && WellKnownSet.Contains(host.Trim());

    public static bool IsDirectEgressHost(string host) => Engine.IsDirectEgressHost(host);

    public static bool IsDirectEgressUri(Uri uri) => Engine.IsDirectEgressUri(uri);

    /// <summary>Registers a dynamically discovered panel/update host (e.g. an update artifact CDN).</summary>
    public static void RegisterHost(string host) => Engine.RegisterHost(host);

    public static void RegisterRequestHost(Uri uri) => Engine.RegisterRequestHost(uri);

    public static Task<IReadOnlyList<IPAddress>> ResolveHostAsync(string host, CancellationToken ct = default)
        => Engine.ResolveHostAsync(host, ct);

    public static void BindSocket(Socket socket) => Engine.BindSocket(socket);

    public static Task RefreshAsync(CancellationToken ct = default) => Engine.RefreshAsync(ct);

    /// <summary>
    /// Subscribes network-address changes with a debounced route refresh.
    /// Idempotent; call once at application startup.
    /// </summary>
    public static void Initialize()
    {
        lock (LifecycleLock)
        {
            if (_shutdown || Interlocked.Exchange(ref _initialized, 1) != 0)
                return;
            NetworkChange.NetworkAddressChanged += OnNetworkAddressChanged;
        }
    }

    /// <summary>Removes all host routes and stops listening for network changes.</summary>
    public static void Shutdown()
    {
        lock (LifecycleLock)
        {
            if (_shutdown)
                return;
            _shutdown = true;
            NetworkChange.NetworkAddressChanged -= OnNetworkAddressChanged;
            _refreshCts?.Cancel();
            _refreshCts?.Dispose();
            _refreshCts = null;
        }
        Engine.Dispose();
    }

    private static void OnNetworkAddressChanged(object? sender, EventArgs e)
    {
        CancellationTokenSource? previous;
        lock (LifecycleLock)
        {
            if (_shutdown)
                return;
            previous = _refreshCts;
            _refreshCts = new CancellationTokenSource();
            var token = _refreshCts.Token;
            _ = Task.Run(async () =>
            {
                try
                {
                    // Debounce: interfaces flap in bursts while TUN tools
                    // reconfigure the route table.
                    await Task.Delay(TimeSpan.FromSeconds(2), token).ConfigureAwait(false);
                    await Engine.RefreshAsync(token).ConfigureAwait(false);
                }
                catch (OperationCanceledException)
                {
                }
                catch (Exception ex)
                {
                    Log.Warning(ex, "panel_direct_egress network change refresh failed");
                }
            }, CancellationToken.None);
        }
        previous?.Cancel();
        previous?.Dispose();
    }
}

/// <summary>
/// Single construction site for the shared resolver/engine pair so the
/// anti-poison DoH resolver and the direct-egress engine share one
/// StrictDohResolver instance without Lazy&lt;T&gt; recursion.
/// </summary>
internal static class PanelDirectEgressBootstrap
{
    internal static StrictDohResolver Resolver { get; } = CreateResolver();
    internal static PanelEgress.PanelDirectEgressEngine Engine { get; } = CreateEngine();

    internal static void BindSocket(Socket socket) => Engine.BindSocket(socket);

    private static StrictDohResolver CreateResolver()
        => new(StrictDohResolver.DefaultProviders, configureSocket: BindSocket);

    private static PanelEgress.PanelDirectEgressEngine CreateEngine()
        => new(
            Resolver,
            new PanelEgress.SystemPhysicalEgressInterfaceProvider(),
            CreateRouteExecutor());

    private static PanelEgress.IHostRouteExecutor CreateRouteExecutor()
    {
        if (OperatingSystem.IsWindows())
            return new PanelEgress.WindowsRouteCommandExecutor();
        if (OperatingSystem.IsMacOS())
            return new PanelEgress.MacRouteCommandExecutor();
        return new PanelEgress.NoopHostRouteExecutor();
    }
}
