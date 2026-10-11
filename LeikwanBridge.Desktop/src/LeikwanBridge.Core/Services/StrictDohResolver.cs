using System.Net;
using System.Net.Http.Headers;
using System.Net.Security;
using System.Net.Sockets;

namespace LeikwanBridge.Core.Services;

public sealed class StrictDohResolver : ILeikwanDnsResolver, IDisposable
{
    private readonly IReadOnlyList<DohProvider> _providers;
    private readonly Dictionary<DohProvider, HttpClient> _clients;
    private readonly Action<Socket>? _configureSocket;
    private bool _disposed;

    public static IReadOnlyList<DohProvider> DefaultProviders { get; } =
    [
        new DohProvider(
            "AliDNS",
            new Uri("https://dns.alidns.com/dns-query"),
            [IPAddress.Parse("223.5.5.5"), IPAddress.Parse("223.6.6.6")]),
        new DohProvider(
            "DNSPod",
            new Uri("https://doh.pub/dns-query"),
            [IPAddress.Parse("1.12.12.12"), IPAddress.Parse("120.53.53.53")]),
        new DohProvider(
            "360",
            new Uri("https://doh.360.cn/dns-query"),
            [IPAddress.Parse("101.226.4.6"), IPAddress.Parse("218.30.118.6")]),
    ];

    public StrictDohResolver()
        : this(DefaultProviders)
    {
    }

    public StrictDohResolver(IReadOnlyList<DohProvider> providers)
        : this(providers, null, null)
    {
    }

    internal StrictDohResolver(
        IReadOnlyList<DohProvider> providers,
        Func<DohProvider, HttpClient?>? clientFactory = null,
        Action<Socket>? configureSocket = null)
    {
        _providers = providers.Count == 0 ? throw new ArgumentException("At least one DoH provider is required", nameof(providers)) : providers;
        _configureSocket = configureSocket;
        _clients = _providers.ToDictionary(provider => provider, provider => clientFactory?.Invoke(provider) ?? CreateBootstrapHttpClient(provider));
    }

    public async Task<IReadOnlyList<IPAddress>> ResolveAAsync(string host, CancellationToken ct = default)
    {
        ObjectDisposedException.ThrowIf(_disposed, this);

        var errors = new List<Exception>();
        foreach (var provider in _providers)
        {
            try
            {
                var transactionId = GenerateTransactionId();
                var query = DnsWireMessage.EncodeAQuery(host, transactionId);
                using var request = new HttpRequestMessage(HttpMethod.Post, provider.Endpoint)
                {
                    Content = new ByteArrayContent(query),
                };
                request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/dns-message");
                request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/dns-message"));

                var client = _clients[provider];
                using var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, ct).ConfigureAwait(false);
                response.EnsureSuccessStatusCode();
                var payload = await response.Content.ReadAsByteArrayAsync(ct).ConfigureAwait(false);
                return DnsWireMessage.ParseAResponse(payload, transactionId);
            }
            catch (Exception ex) when (ex is not OperationCanceledException)
            {
                errors.Add(ex);
            }
        }

        throw new InvalidOperationException($"Strict DoH resolution failed for {host}", new AggregateException(errors));
    }

    private HttpClient CreateBootstrapHttpClient(DohProvider provider)
    {
        var handler = new SocketsHttpHandler
        {
            AllowAutoRedirect = false,
            AutomaticDecompression = DecompressionMethods.None,
            ConnectTimeout = TimeSpan.FromSeconds(10),
            PooledConnectionLifetime = TimeSpan.FromMinutes(5),
            UseProxy = false,
            SslOptions = new SslClientAuthenticationOptions
            {
                TargetHost = provider.Endpoint.Host,
            },
        };

        var nextBootstrapIndex = 0;
        handler.ConnectCallback = async (context, ct) =>
        {
            if (!string.Equals(context.DnsEndPoint.Host, provider.Endpoint.Host, StringComparison.OrdinalIgnoreCase))
                return await ConnectSocketAsync(context.DnsEndPoint.Host, context.DnsEndPoint.Port, ct).ConfigureAwait(false);

            Exception? lastError = null;
            for (var attempt = 0; attempt < provider.BootstrapAddresses.Count; attempt++)
            {
                var address = provider.BootstrapAddresses[(nextBootstrapIndex + attempt) % provider.BootstrapAddresses.Count];
                try
                {
                    var stream = await ConnectSocketAsync(address, context.DnsEndPoint.Port, ct).ConfigureAwait(false);
                    nextBootstrapIndex = (nextBootstrapIndex + attempt + 1) % provider.BootstrapAddresses.Count;
                    return stream;
                }
                catch (Exception ex) when (ex is not OperationCanceledException)
                {
                    lastError = ex;
                }
            }

            throw new InvalidOperationException($"Unable to connect DoH provider {provider.Name} via bootstrap IP", lastError);
        };

        return new HttpClient(handler, disposeHandler: true)
        {
            Timeout = TimeSpan.FromSeconds(15),
        };
    }

    private static ushort GenerateTransactionId()
    {
        return BitConverter.ToUInt16(Guid.NewGuid().ToByteArray(), 0);
    }

    private async ValueTask<Stream> ConnectSocketAsync(string host, int port, CancellationToken ct)
    {
        var socket = new Socket(SocketType.Stream, ProtocolType.Tcp) { NoDelay = true };
        try
        {
            _configureSocket?.Invoke(socket);
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
            // DoH bootstrap connections also egress through the physical NIC
            // so a TUN tool can never intercept the resolver itself.
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

    public void Dispose()
    {
        if (_disposed)
            return;
        _disposed = true;
        foreach (var client in _clients.Values)
            client.Dispose();
        _clients.Clear();
    }
}
