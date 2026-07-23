using System.Net;

namespace LeikwanBridge.Core.Services;

public enum LeikwanHttpClientKind
{
    Normal,
    AntiPoison,
}

public static class LeikwanHttpClientFactory
{
    private static readonly Lazy<StrictDohResolver> SharedResolver = new(() => new StrictDohResolver());

    public static HttpClient CreateDefault()
    {
        return CreateAntiPoison(SharedResolver.Value);
    }

    public static HttpClient CreateFor(Uri uri)
    {
        return LeikwanHttpHostPolicy.ShouldResolveWithDoh(uri)
            ? CreateDefault()
            : CreateNormal();
    }

    public static LeikwanHttpClientKind SelectKind(Uri uri)
    {
        return LeikwanHttpHostPolicy.ShouldResolveWithDoh(uri)
            ? LeikwanHttpClientKind.AntiPoison
            : LeikwanHttpClientKind.Normal;
    }

    public static HttpClient Create(LeikwanHttpClientKind kind)
    {
        return kind == LeikwanHttpClientKind.AntiPoison
            ? CreateDefault()
            : CreateNormal();
    }

    internal static HttpClient CreateAntiPoison(ILeikwanDnsResolver resolver)
    {
        var connector = new LeikwanDnsRoutingConnector(resolver);
        var handler = new SocketsHttpHandler
        {
            AutomaticDecompression = DecompressionMethods.GZip | DecompressionMethods.Deflate,
            ConnectTimeout = TimeSpan.FromSeconds(10),
            PooledConnectionLifetime = TimeSpan.FromMinutes(5),
            UseProxy = false,
        };
        handler.ConnectCallback = (context, ct) =>
        {
            var scheme = context.InitialRequestMessage?.RequestUri?.Scheme ?? Uri.UriSchemeHttps;
            var uri = new UriBuilder(scheme, context.DnsEndPoint.Host, context.DnsEndPoint.Port).Uri;
            if (context.InitialRequestMessage?.RequestUri is { } requestUri)
                uri = requestUri;
            return connector.ConnectAsync(uri, context.DnsEndPoint.Host, context.DnsEndPoint.Port, ct);
        };

        return new HttpClient(handler, disposeHandler: true)
        {
            Timeout = TimeSpan.FromSeconds(30),
        };
    }

    private static HttpClient CreateNormal()
    {
        return new HttpClient
        {
            Timeout = TimeSpan.FromSeconds(30),
        };
    }
}
