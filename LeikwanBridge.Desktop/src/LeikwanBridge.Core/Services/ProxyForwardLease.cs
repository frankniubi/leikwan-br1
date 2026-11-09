using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
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

/// <summary>
/// A proxy start that failed on one forward. Message is the stable error code, so
/// callers that match on the message still work; ForwardId lets the UI name the line.
/// </summary>
public sealed class ProxyForwardStartException(int forwardId, string errorCode, Exception innerException)
    : InvalidOperationException(errorCode, innerException)
{
    public int ForwardId { get; } = forwardId;
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
        TimeSpan? timeout = null,
        Func<int, string?>? diagnosePort = null)
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
                var cause = (diagnosePort ?? LocalPortFailure.DiagnoseLive)(forward.ListenPort);
                Log.Warning(
                    "ordinary proxy forward readiness timed out forward={ForwardId} port={Port} transport={Transport} owns_forward={OwnsForward} cause={Cause}",
                    forward.ForwardId,
                    forward.ListenPort,
                    forward.Transport,
                    ownsForward,
                    cause ?? "unknown");
                throw new TimeoutException(cause ?? "proxy_forward_readiness_timeout", exception);
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

    public static Task<bool> IsListenerReadyAsync(ForwardConfig forward, CancellationToken ct = default)
    {
        ct.ThrowIfCancellationRequested();
        var transport = forward.Transport.Trim().ToLowerInvariant();
        if (transport is not ("tcp" or "udp" or "tcp+udp"))
            return Task.FromResult(false);
        if (transport != "udp" && !NetworkProbe.IsTcpPortListening(forward.ListenPort))
            return Task.FromResult(false);
        if (transport == "tcp") return Task.FromResult(true);
        return Task.FromResult(NetworkProbe.IsUdpPortListening(forward.ListenPort));
    }

    public async ValueTask DisposeAsync()
    {
        var stop = Interlocked.Exchange(ref _stop, null);
        if (stop is not null)
            await stop(CancellationToken.None).ConfigureAwait(false);
    }
}
