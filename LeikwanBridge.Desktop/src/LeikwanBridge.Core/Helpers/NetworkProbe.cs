using System.Net.Sockets;

namespace LeikwanBridge.Core.Helpers;

public static class NetworkProbe
{
    public static async Task<(bool ok, double latencyMs, string? error)> TcpProbeAsync(
        string host, int port, int timeoutMs = 3000, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(host) || port <= 0)
            return (false, 0, "invalid host/port");

        try
        {
            using var client = new TcpClient();
            var sw = System.Diagnostics.Stopwatch.StartNew();
            using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
            cts.CancelAfter(timeoutMs);
            await client.ConnectAsync(host, port, cts.Token);
            sw.Stop();
            return (true, sw.Elapsed.TotalMilliseconds, null);
        }
        catch (OperationCanceledException)
        {
            return (false, 0, "timeout");
        }
        catch (Exception ex)
        {
            return (false, 0, ex.Message);
        }
    }

    public static bool IsPortListening(int port)
    {
        try
        {
            using var client = new TcpClient();
            client.Connect("127.0.0.1", port);
            return true;
        }
        catch
        {
            return false;
        }
    }
}
