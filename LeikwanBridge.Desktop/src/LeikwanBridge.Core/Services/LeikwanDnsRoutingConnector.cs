using System.Net;
using System.Net.Sockets;

namespace LeikwanBridge.Core.Services;

public sealed class LeikwanDnsRoutingConnector
{
    private readonly ILeikwanDnsResolver _resolver;
    private readonly Func<string, int, CancellationToken, ValueTask<Stream>> _connectToHostAsync;
    private readonly Func<IPAddress, int, CancellationToken, ValueTask<Stream>> _connectToIpAsync;

    public LeikwanDnsRoutingConnector(ILeikwanDnsResolver resolver)
        : this(resolver, ConnectSocketAsync, ConnectSocketAsync)
    {
    }

    public LeikwanDnsRoutingConnector(
        ILeikwanDnsResolver resolver,
        Func<string, int, CancellationToken, ValueTask<Stream>> connectToHostAsync,
        Func<IPAddress, int, CancellationToken, ValueTask<Stream>> connectToIpAsync)
    {
        _resolver = resolver;
        _connectToHostAsync = connectToHostAsync;
        _connectToIpAsync = connectToIpAsync;
    }

    public async ValueTask<Stream> ConnectAsync(Uri requestUri, string host, int port, CancellationToken ct)
    {
        if (!LeikwanHttpHostPolicy.ShouldResolveWithDoh(requestUri))
            return await _connectToHostAsync(host, port, ct).ConfigureAwait(false);

        var addresses = await _resolver.ResolveAAsync(requestUri.IdnHost, ct).ConfigureAwait(false);
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

    private static async ValueTask<Stream> ConnectSocketAsync(string host, int port, CancellationToken ct)
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

    private static async ValueTask<Stream> ConnectSocketAsync(IPAddress address, int port, CancellationToken ct)
    {
        var socket = new Socket(address.AddressFamily, SocketType.Stream, ProtocolType.Tcp) { NoDelay = true };
        try
        {
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
