using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Helpers;
using System.Net;
using System.Net.NetworkInformation;
using Serilog;

namespace LeikwanBridge.Core.Services;

public sealed class ProxyForwardLeaseCancellationException : OperationCanceledException
{
    public int ForwardId { get; }
    public string ErrorCode => "proxy_forward_start_cancelled";

    public ProxyForwardLeaseCancellationException(int forwardId, Exception innerException)
        : base("proxy_forward_start_cancelled", innerException)
    {
        ForwardId = forwardId;
    }
}

public sealed class ProxyForwardLease : IAsyncDisposable
{
    private Func<CancellationToken, Task>? _stop;
    public ForwardConfig Forward { get; }
    public int ListenPort => Forward.ListenPort;
    public bool OwnsForward { get; }

    public ProxyForwardLease(ForwardConfig forward, bool ownsForward, Func<CancellationToken, Task>? stop = null)
    {
        Forward = forward;
        OwnsForward = ownsForward;
        _stop = ownsForward ? stop : null;
    }

    public static async Task<ProxyForwardLease> AcquireAsync(
        ForwardConfig forward,
        Func<bool> isRunning,
        Func<CancellationToken, Task> start,
        Func<CancellationToken, Task> stop,
        CancellationToken ct = default,
        Func<ForwardConfig, CancellationToken, Task<bool>>? readinessProbe = null,
        TimeSpan? timeout = null)
    {
        ArgumentNullException.ThrowIfNull(forward);
        if (forward.ForwardId <= 0 || forward.ListenPort is < 1 or > 65535
            || !string.Equals(forward.ServiceStatus, "active", StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("proxy_forward_unavailable");
        ct.ThrowIfCancellationRequested();
        var ownsForward = !isRunning();
        var lease = new ProxyForwardLease(forward, ownsForward, stop);
        using var deadline = CancellationTokenSource.CreateLinkedTokenSource(ct);
        deadline.CancelAfter(timeout ?? TimeSpan.FromSeconds(45));
        try
        {
            if (ownsForward)
                await start(deadline.Token).ConfigureAwait(false);
            var probe = readinessProbe ?? IsListenerReadyAsync;
            while (true)
            {
                deadline.Token.ThrowIfCancellationRequested();
                if (isRunning() && await probe(forward, deadline.Token).ConfigureAwait(false))
                {
                    deadline.Token.ThrowIfCancellationRequested();
                    return lease;
                }
                await Task.Delay(100, deadline.Token).ConfigureAwait(false);
            }
        }
        catch (Exception exception)
        {
            try { await lease.DisposeAsync().ConfigureAwait(false); }
            catch (Exception cleanupError)
            {
                Log.Warning(cleanupError, "failed to release ordinary proxy forward {ForwardId}", forward.ForwardId);
            }
            if (exception is OperationCanceledException && !ct.IsCancellationRequested)
            {
                Log.Warning(
                    "ordinary proxy forward readiness timed out forward={ForwardId} port={Port} transport={Transport} owns_forward={OwnsForward}",
                    forward.ForwardId,
                    forward.ListenPort,
                    forward.Transport,
                    ownsForward);
                throw new TimeoutException("proxy_forward_readiness_timeout", exception);
            }

            if (exception is OperationCanceledException && ct.IsCancellationRequested)
            {
                Log.Information(
                    "ordinary proxy forward start cancelled forward={ForwardId} port={Port} transport={Transport} owns_forward={OwnsForward}",
                    forward.ForwardId,
                    forward.ListenPort,
                    forward.Transport,
                    ownsForward);
                throw new ProxyForwardLeaseCancellationException(forward.ForwardId, exception);
            }

            Log.Warning(
                exception,
                "ordinary proxy forward acquire failed forward={ForwardId} port={Port} transport={Transport} owns_forward={OwnsForward}",
                forward.ForwardId,
                forward.ListenPort,
                forward.Transport,
                ownsForward);
            throw;
        }
    }

    public static async Task<bool> IsListenerReadyAsync(ForwardConfig forward, CancellationToken ct = default)
    {
        ct.ThrowIfCancellationRequested();
        var transport = forward.Transport.Trim().ToLowerInvariant();
        if (transport is not ("tcp" or "udp" or "tcp+udp"))
            return false;
        if (transport != "udp")
        {
            var (ok, _, _) = await NetworkProbe.TcpProbeAsync("127.0.0.1", forward.ListenPort, 300, ct)
                .ConfigureAwait(false);
            ct.ThrowIfCancellationRequested();
            if (!ok) return false;
        }
        if (transport == "tcp") return true;
        try
        {
            return IPGlobalProperties.GetIPGlobalProperties().GetActiveUdpListeners().Any(endpoint =>
                endpoint.Port == forward.ListenPort
                && (endpoint.Address.Equals(IPAddress.Loopback) || endpoint.Address.Equals(IPAddress.Any)));
        }
        catch (NetworkInformationException) { return false; }
    }

    public async ValueTask DisposeAsync()
    {
        var stop = Interlocked.Exchange(ref _stop, null);
        if (stop is not null)
            await stop(CancellationToken.None).ConfigureAwait(false);
    }
}
