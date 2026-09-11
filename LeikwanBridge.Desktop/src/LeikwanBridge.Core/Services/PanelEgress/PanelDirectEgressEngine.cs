using System.Collections.Concurrent;
using System.Net;
using System.Net.Sockets;
using Serilog;

namespace LeikwanBridge.Core.Services.PanelEgress;

/// <summary>
/// Applies the kernel-level outgoing-interface socket option before connect.
/// </summary>
public interface IPanelSocketBinder
{
    void BindSocket(Socket socket);
}

public interface IPanelDirectEgressCoordinator
{
    bool IsDirectEgressHost(string host);
    bool IsDirectEgressUri(Uri uri);
    Task<IReadOnlyList<IPAddress>> ResolveHostAsync(string host, CancellationToken ct = default);
    void BindSocket(Socket socket);
}

/// <summary>
/// Orchestrates direct egress for panel/update traffic:
///  - resolves target hosts exclusively through the injected DoH resolver
///    (<see cref="StrictDohResolver"/>) so third-party TUN/proxy tools cannot
///    poison the answers;
///  - binds every panel socket to the discovered physical interface via
///    IP_UNICAST_IF / IPV6_UNICAST_IF (Windows) or IP_BOUND_IF /
///    IPV6_BOUND_IF (macOS) — the primary, tool-agnostic, root-free
///    mechanism;
///  - optionally maintains /32 and /128 host routes through the physical
///    gateway as a fallback, only when a TUN-like interface currently holds
///    the default route (zero side effects otherwise), and removes them on
///    shutdown or when the default route moves back to a physical interface.
/// </summary>
public sealed class PanelDirectEgressEngine : IPanelDirectEgressCoordinator, IPanelSocketBinder, IDisposable
{
    public static readonly TimeSpan DefaultResolutionTtl = TimeSpan.FromMinutes(10);
    private static readonly TimeSpan InterfaceSnapshotTtl = TimeSpan.FromSeconds(5);

    private readonly ILeikwanDnsResolver _resolver;
    private readonly IPhysicalEgressInterfaceProvider _interfaceProvider;
    private readonly IHostRouteExecutor _routeExecutor;
    private readonly TimeSpan _resolutionTtl;
    private readonly ConcurrentDictionary<string, byte> _registeredHosts = new(StringComparer.OrdinalIgnoreCase);
    private readonly ConcurrentDictionary<string, CachedResolution> _resolutions = new(StringComparer.OrdinalIgnoreCase);
    private readonly ConcurrentDictionary<string, Task<IReadOnlyList<IPAddress>>> _inflight = new(StringComparer.OrdinalIgnoreCase);
    private readonly object _routeLock = new();
    private readonly Dictionary<string, HostRouteEntry> _appliedRoutes = new(StringComparer.Ordinal);
    private readonly object _snapshotLock = new();
    private PhysicalEgressInterface? _snapshotV4;
    private PhysicalEgressInterface? _snapshotV6;
    private long _snapshotTicks;
    private int _refreshInProgress;
    private int _disposed;

    public PanelDirectEgressEngine(
        ILeikwanDnsResolver resolver,
        IPhysicalEgressInterfaceProvider interfaceProvider,
        IHostRouteExecutor? routeExecutor = null,
        TimeSpan? resolutionTtl = null)
    {
        _resolver = resolver ?? throw new ArgumentNullException(nameof(resolver));
        _interfaceProvider = interfaceProvider ?? throw new ArgumentNullException(nameof(interfaceProvider));
        _routeExecutor = routeExecutor ?? new NoopHostRouteExecutor();
        _resolutionTtl = resolutionTtl ?? DefaultResolutionTtl;
    }

    public IEnumerable<string> RegisteredHosts =>
        PanelDirectEgress.WellKnownHosts
            .Concat(_registeredHosts.Keys)
            .Distinct(StringComparer.OrdinalIgnoreCase);

    public int AppliedRouteCount
    {
        get
        {
            lock (_routeLock)
                return _appliedRoutes.Count;
        }
    }

    public void RegisterHost(string host)
    {
        var normalized = NormalizeHost(host);
        if (normalized.Length == 0
            || IPAddress.TryParse(normalized, out _)
            || string.Equals(normalized, "localhost", StringComparison.OrdinalIgnoreCase)
            || normalized.EndsWith(".localhost", StringComparison.OrdinalIgnoreCase))
            return;
        if (!_registeredHosts.TryAdd(normalized, 0) || _disposed != 0)
            return;
        _ = PrewarmAsync(normalized);
    }

    public void RegisterRequestHost(Uri uri)
    {
        if (uri is null || !uri.IsAbsoluteUri)
            return;
        if (!string.Equals(uri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
            return;
        RegisterHost(uri.IdnHost);
    }

    public bool IsDirectEgressHost(string host)
    {
        var normalized = NormalizeHost(host);
        if (normalized.Length == 0)
            return false;
        if (string.Equals(normalized, "localhost", StringComparison.OrdinalIgnoreCase)
            || normalized.EndsWith(".localhost", StringComparison.OrdinalIgnoreCase))
            return false;
        if (IPAddress.TryParse(normalized, out var literal))
            return !LeikwanHttpHostPolicy.IsBypassAddress(literal);
        return PanelDirectEgress.IsWellKnownHost(normalized)
            || (normalized.EndsWith(".leikwanhost.com", StringComparison.OrdinalIgnoreCase)
                && !string.Equals(normalized, "leikwanhost.com", StringComparison.OrdinalIgnoreCase))
            || _registeredHosts.ContainsKey(normalized);
    }

    public bool IsDirectEgressUri(Uri uri)
        => uri is not null && uri.IsAbsoluteUri && IsDirectEgressHost(uri.IdnHost);

    public async Task<IReadOnlyList<IPAddress>> ResolveHostAsync(string host, CancellationToken ct = default)
    {
        if (_disposed != 0)
            return [];
        var normalized = NormalizeHost(host);
        if (normalized.Length == 0)
            return [];

        if (IPAddress.TryParse(normalized, out var literal))
            return LeikwanHttpHostPolicy.IsBypassAddress(literal) ? [] : [literal];

        if (!IsDirectEgressHost(normalized))
            return [];

        if (_resolutions.TryGetValue(normalized, out var cached)
            && DateTimeOffset.UtcNow - cached.ResolvedAt <= _resolutionTtl)
            return cached.Addresses;

        var inflight = _inflight.GetOrAdd(normalized, _ => ResolveCoreAsync(normalized));
        try
        {
            return await inflight.WaitAsync(ct).ConfigureAwait(false);
        }
        finally
        {
            _inflight.TryRemove(new KeyValuePair<string, Task<IReadOnlyList<IPAddress>>>(normalized, inflight));
        }
    }

    private async Task<IReadOnlyList<IPAddress>> ResolveCoreAsync(string host)
    {
        var addresses = await _resolver.ResolveAAsync(host, CancellationToken.None).ConfigureAwait(false);
        var usable = addresses
            .Where(address => !LeikwanHttpHostPolicy.IsBypassAddress(address))
            .Distinct()
            .ToArray();
        if (usable.Length == 0)
            return [];
        _resolutions[host] = new CachedResolution(usable, DateTimeOffset.UtcNow);
        ReplanRoutes();
        return usable;
    }

    /// <summary>
    /// Re-resolves every registered host and re-plans host routes against the
    /// current gateways. Called on debounced network-address changes.
    /// </summary>
    public async Task RefreshAsync(CancellationToken ct = default)
    {
        if (_disposed != 0)
            return;
        Interlocked.Exchange(ref _refreshInProgress, 1);
        try
        {
            _resolutions.Clear();
            _inflight.Clear();
            var hosts = RegisteredHosts.ToList();
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(TimeSpan.FromSeconds(20));
            var tasks = hosts.Select(host => SafeRefreshAsync(host, timeout.Token)).ToList();
            await Task.WhenAll(tasks).ConfigureAwait(false);
        }
        finally
        {
            Interlocked.Exchange(ref _refreshInProgress, 0);
        }
        // Prune routes whose host failed to re-resolve, and pick up new
        // gateways; routing churn is suppressed while the refresh is running.
        ReplanRoutes();
    }

    private async Task SafeRefreshAsync(string host, CancellationToken ct)
    {
        try
        {
            await ResolveHostAsync(host, ct).ConfigureAwait(false);
        }
        catch (OperationCanceledException)
        {
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "panel_direct_egress refresh resolve failed host={Host}", host);
        }
    }

    public void BindSocket(Socket socket)
    {
        if (socket is null || _disposed != 0)
            return;
        var family = socket.AddressFamily;
        if (family is not (AddressFamily.InterNetwork or AddressFamily.InterNetworkV6))
            return;

        var egress = GetInterfaceSnapshot(family);
        if (egress is null || egress.Index <= 0)
            return;
        var request = PanelSocketOptionBuilder.Build(
            PanelSocketOptionBuilder.CurrentPlatform, family, egress.Index);
        if (request is null)
            return;
        try
        {
            socket.SetRawSocketOption(request.Level, request.Name, request.Value);
        }
        catch (SocketException ex)
        {
            Log.Debug(
                "panel_direct_egress socket option failed family={Family} index={Index} error={ErrorCode}",
                family, egress.Index, ex.SocketErrorCode);
        }
    }

    public void Dispose()
    {
        if (Interlocked.Exchange(ref _disposed, 1) != 0)
            return;
        lock (_routeLock)
        {
            foreach (var entry in _appliedRoutes.Values.ToList())
                _routeExecutor.DeleteHostRoute(entry);
            _appliedRoutes.Clear();
        }
        _resolutions.Clear();
        _inflight.Clear();
        _registeredHosts.Clear();
    }

    private void ReplanRoutes()
    {
        if (_disposed != 0 || Volatile.Read(ref _refreshInProgress) != 0)
            return;
        lock (_routeLock)
        {
            if (_disposed != 0)
                return;
            var allAddresses = _resolutions.Values
                .SelectMany(resolution => resolution.Addresses)
                .Distinct()
                .ToList();

            // Zero side effects when the default route already runs on a
            // physical interface (no TUN present).
            PhysicalEgressInterface? v4 = null;
            PhysicalEgressInterface? v6 = null;
            if (!_interfaceProvider.IsDefaultRoutePhysical(AddressFamily.InterNetwork))
                v4 = _interfaceProvider.GetPreferredInterface(AddressFamily.InterNetwork);
            if (!_interfaceProvider.IsDefaultRoutePhysical(AddressFamily.InterNetworkV6))
                v6 = _interfaceProvider.GetPreferredInterface(AddressFamily.InterNetworkV6);

            var plan = PanelHostRoutePlanner.Plan(allAddresses, v4, v6, _appliedRoutes.Values);
            foreach (var entry in plan.ToAdd)
            {
                if (_disposed != 0)
                    break;
                var result = _routeExecutor.AddHostRoute(entry);
                if (result is RouteOperationResult.Applied or RouteOperationResult.AlreadyPresent)
                    _appliedRoutes[entry.Address.ToString()] = entry;
            }
            foreach (var entry in plan.ToRemove)
            {
                _routeExecutor.DeleteHostRoute(entry);
                _appliedRoutes.Remove(entry.Address.ToString());
            }
        }
    }

    private PhysicalEgressInterface? GetInterfaceSnapshot(AddressFamily family)
    {
        var now = Environment.TickCount64;
        lock (_snapshotLock)
        {
            if (_snapshotV4 is null
                || _snapshotV6 is null
                || now - _snapshotTicks > (long)InterfaceSnapshotTtl.TotalMilliseconds)
            {
                _snapshotV4 = _interfaceProvider.GetPreferredInterface(AddressFamily.InterNetwork);
                _snapshotV6 = _interfaceProvider.GetPreferredInterface(AddressFamily.InterNetworkV6);
                _snapshotTicks = now;
            }
            return family == AddressFamily.InterNetwork ? _snapshotV4 : _snapshotV6;
        }
    }

    private async Task PrewarmAsync(string host)
    {
        try
        {
            await ResolveHostAsync(host, CancellationToken.None).ConfigureAwait(false);
        }
        catch (Exception ex)
        {
            Log.Debug(ex, "panel_direct_egress prewarm resolve failed host={Host}", host);
        }
    }

    private static string NormalizeHost(string host)
        => (host ?? string.Empty).Trim().TrimEnd('.').ToLowerInvariant();

    private sealed record CachedResolution(IReadOnlyList<IPAddress> Addresses, DateTimeOffset ResolvedAt);
}
