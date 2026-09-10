namespace LeikwanBridge.Core.Services;

/// <summary>
/// Keeps a failed desktop connection attempt alive in the background until it
/// succeeds or the owning application is closed. The supervisor owns no API
/// state and never changes request paths or payloads.
/// </summary>
public sealed class ConnectionRecoverySupervisor : IAsyncDisposable
{
    private static readonly TimeSpan[] DefaultRetryDelays =
    [
        TimeSpan.FromSeconds(2),
        TimeSpan.FromSeconds(5),
        TimeSpan.FromSeconds(15),
    ];

    private readonly Func<CancellationToken, Task> _connect;
    private readonly IReadOnlyList<TimeSpan> _retryDelays;
    private readonly object _gate = new();
    private CancellationTokenSource? _cts;
    private Task? _loop;

    public ConnectionRecoverySupervisor(
        Func<CancellationToken, Task> connect,
        IReadOnlyList<TimeSpan>? retryDelays = null)
    {
        ArgumentNullException.ThrowIfNull(connect);
        _connect = connect;
        _retryDelays = retryDelays is { Count: > 0 }
            ? retryDelays.ToArray()
            : DefaultRetryDelays;
    }

    public Task StartAsync(CancellationToken ct = default)
    {
        lock (_gate)
        {
            if (_loop is not null)
                return _loop;

            _cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
            _loop = RunAsync(_cts);
            return _loop;
        }
    }

    public async Task StopAsync()
    {
        Task? loop;
        CancellationTokenSource? cts;
        lock (_gate)
        {
            loop = _loop;
            cts = _cts;
        }
        if (loop is null || cts is null)
            return;

        cts.Cancel();
        try
        {
            await loop.ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (cts.IsCancellationRequested)
        {
        }
        finally
        {
            lock (_gate)
            {
                if (ReferenceEquals(_loop, loop))
                {
                    _loop = null;
                    _cts = null;
                }
            }
            cts.Dispose();
        }
    }

    public async ValueTask DisposeAsync() => await StopAsync().ConfigureAwait(false);

    private async Task RunAsync(CancellationTokenSource ownerCts)
    {
        var ct = ownerCts.Token;
        var retryIndex = 0;
        while (!ct.IsCancellationRequested)
        {
            try
            {
                await _connect(ct).ConfigureAwait(false);
                return;
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                return;
            }
            catch
            {
                var delay = _retryDelays[Math.Min(retryIndex++, _retryDelays.Count - 1)];
                try
                {
                    await Task.Delay(delay, ct).ConfigureAwait(false);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    return;
                }
            }
        }
    }
}
