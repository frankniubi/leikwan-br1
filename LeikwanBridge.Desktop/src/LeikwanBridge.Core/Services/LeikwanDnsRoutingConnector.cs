using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Services.PanelEgress;

namespace LeikwanBridge.Core.Services;

public sealed class LeikwanDnsRoutingConnector
{
    private readonly ILeikwanDnsResolver _resolver;
    private readonly IPanelDirectEgressCoordinator? _egress;
    private readonly Action<Socket>? _configureSocket;
    private readonly Func<string, int, CancellationToken, ValueTask<Stream>> _connectToHostAsync;
    private readonly Func<IPAddress, int, CancellationToken, ValueTask<Stream>> _connectToIpAsync;

    public LeikwanDnsRoutingConnector(ILeikwanDnsResolver resolver)
        : this(resolver, null, null, null, null)
    {
    }

    public LeikwanDnsRoutingConnector(
        ILeikwanDnsResolver resolver,
        Func<string, int, CancellationToken, ValueTask<Stream>>? connectToHostAsync = null,
        Func<IPAddress, int, CancellationToken, ValueTask<Stream>>? connectToIpAsync = null,
        IPanelDirectEgressCoordinator? egress = null,
        Action<Socket>? configureSocket = null)
    {
        _resolver = resolver ?? throw new ArgumentNullException(nameof(resolver));
        _connectToHostAsync = connectToHostAsync ?? ConnectSocketAsync;
        _connectToIpAsync = connectToIpAsync ?? ConnectSocketAsync;
        _egress = egress;
        _configureSocket = configureSocket;
    }

    public async ValueTask<Stream> ConnectAsync(Uri requestUri, string host, int port, CancellationToken ct)
    {
        var directEgressHost = _egress?.IsDirectEgressHost(requestUri.IdnHost) == true;
        if (!directEgressHost && !LeikwanHttpHostPolicy.ShouldResolveWithDoh(requestUri))
            return await _connectToHostAsync(host, port, ct).ConfigureAwait(false);

        // Direct-egress hosts always resolve through the anti-poison DoH
        // path (via the shared engine when available) so a TUN tool cannot
        // poison the answers either.
        var addresses = directEgressHost
            ? await _egress!.ResolveHostAsync(requestUri.IdnHost, ct).ConfigureAwait(false)
            : await _resolver.ResolveAAsync(requestUri.IdnHost, ct).ConfigureAwait(false);

        Exception? lastError = null;
        foreach (var address in addresses)
        {
            if (LeikwanHttpHostPolicy.IsBypassAddress(address))
                continue;

            try
            {
                return await _connectToIpAsync(address, port, ct).ConfigureAwait(false);
            }
            catch (Exception ex) when (ex is not OperationCanceledException)
            {
                lastError = ex;
            }
        }

        throw new InvalidOperationException($"No usable public DoH A record for {requestUri.IdnHost}", lastError);
    }

    private async ValueTask<Stream> ConnectSocketAsync(string host, int port, CancellationToken ct)
    {
        var socket = new Socket(SocketType.Stream, ProtocolType.Tcp) { NoDelay = true };
        try
        {
            await socket.ConnectAsync(host, port, ct).ConfigureAwait(false);
            return new NetworkStream(socket, ownsSocket: true);
        }
        catch
        {
            socket.Dispose();
            throw;
        }
    }

    private async ValueTask<Stream> ConnectSocketAsync(IPAddress address, int port, CancellationToken ct)
    {
        var socket = new Socket(address.AddressFamily, SocketType.Stream, ProtocolType.Tcp) { NoDelay = true };
        try
        {
            // Panel connections leave through the physical NIC: force the
            // outgoing interface before the connect so the TUN default route
            // is never consulted.
            _configureSocket?.Invoke(socket);
            await socket.ConnectAsync(address, port, ct).ConfigureAwait(false);
            return new NetworkStream(socket, ownsSocket: true);
        }
        catch
        {
            socket.Dispose();
            throw;
        }
    }
}
