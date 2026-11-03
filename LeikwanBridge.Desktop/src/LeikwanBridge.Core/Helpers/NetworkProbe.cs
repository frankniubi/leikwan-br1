using System.Net.NetworkInformation;
using System.Net.Sockets;
using Serilog;

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

    /// <summary>
    /// True when <paramref name="port"/> has a local listener on a protocol the
    /// Ultra runtime serves for it — TCP or UDP.
    /// </summary>
    /// <remarks>
    /// <para>
    /// This is a <b>passive</b> observation: it reads the kernel's bound-endpoint
    /// tables and never opens a connection. That matters because the two callers
    /// (<c>ForwardRuntimeManager</c>'s start path and <c>HealthCheckService</c>)
    /// poll this on every 20s health cycle, and the Ultra runtime interprets an
    /// accepted TCP connection as a real client: it dials the forward's target
    /// through the relay and, when the target does not answer, logs
    /// <c>tcp stream ended ... reason=io_error</c> and increments the forward's
    /// <c>ha_failures</c> counter.
    /// </para>
    /// <para>
    /// So an active probe measured the health check itself. A forward whose
    /// target is UDP-only (for example an external UoT sidecar) but is declared
    /// <c>transport=tcp+udp</c> therefore accumulated one false HA failure every
    /// 20s — 164 of them on the Windows test client — which makes the counter
    /// useless for alarming. It also injected a spurious stream per cycle into
    /// the relay for every forward.
    /// </para>
    /// <para>
    /// The earlier UDP-only bug is fixed by the same mechanism: a bare TCP
    /// connect cannot see a UDP-only forward, so the start path killed a healthy
    /// runtime with "ultra_listener_not_ready" every ~40s, which is what made a
    /// macOS UDP forward lose ~96% of its datagrams while its lease was being
    /// delivered successfully the whole time.
    /// </para>
    /// </remarks>
    public static bool IsPortListening(int port)
        => IsTcpPortListening(port) || IsUdpPortListening(port);

    /// <summary>
    /// True when <paramref name="port"/> has a local TCP socket in the LISTEN state.
    /// </summary>
    /// <remarks>
    /// Reads the kernel's connection table instead of dialling the port, so the
    /// probe is invisible to the data plane. It also cannot block: the old
    /// <c>TcpClient.Connect</c> had no timeout, so a listener with a full accept
    /// backlog could stall the health-check loop.
    /// </remarks>
    public static bool IsTcpPortListening(int port)
    {
        if (port is < 1 or > 65535)
            return false;
        try
        {
            foreach (var listener in IPGlobalProperties.GetIPGlobalProperties().GetActiveTcpListeners())
            {
                if (listener.Port == port)
                    return true;
            }
        }
        catch (NetworkInformationException ex)
        {
            Log.Warning(ex, "IsTcpPortListening failed for port {Port}", port);
            return false;
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "IsTcpPortListening unexpected error for port {Port}", port);
            throw;
        }
        return false;
    }

    /// <summary>
    /// True when <paramref name="port"/> has a local UDP socket bound.
    /// </summary>
    /// <remarks>
    /// A UDP connect() proves nothing (it is connectionless and always succeeds),
    /// so read the kernel's bound-endpoint table instead.
    /// </remarks>
    public static bool IsUdpPortListening(int port)
    {
        if (port is < 1 or > 65535)
            return false;
        try
        {
            foreach (var endpoint in IPGlobalProperties.GetIPGlobalProperties().GetActiveUdpListeners())
            {
                if (endpoint.Port == port)
                    return true;
            }
        }
        catch (NetworkInformationException ex)
        {
            Log.Warning(ex, "IsUdpPortListening failed for port {Port}", port);
            return false;
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "IsUdpPortListening unexpected error for port {Port}", port);
            throw;
        }
        return false;
    }
}
