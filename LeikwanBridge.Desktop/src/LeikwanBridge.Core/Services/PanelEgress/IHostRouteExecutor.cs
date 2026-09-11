namespace LeikwanBridge.Core.Services.PanelEgress;

public enum RouteOperationResult
{
    Applied,
    AlreadyPresent,
    NotFound,
    Failed,
    Skipped,
}

/// <summary>
/// Executes host-route add/delete operations. Production implementations run
/// route.exe (Windows) or /sbin/route (macOS, needs root, degrades to
/// socket-only binding on permission errors). Tests inject a recording
/// executor for dry-run verification.
/// </summary>
public interface IHostRouteExecutor
{
    RouteOperationResult AddHostRoute(HostRouteEntry entry);
    RouteOperationResult DeleteHostRoute(HostRouteEntry entry);
}

public sealed class NoopHostRouteExecutor : IHostRouteExecutor
{
    public RouteOperationResult AddHostRoute(HostRouteEntry entry) => RouteOperationResult.Skipped;
    public RouteOperationResult DeleteHostRoute(HostRouteEntry entry) => RouteOperationResult.Skipped;
}
