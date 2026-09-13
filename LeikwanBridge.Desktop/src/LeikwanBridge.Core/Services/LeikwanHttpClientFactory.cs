using System.Net;
using System.Net.Sockets;

namespace LeikwanBridge.Core.Services;

public enum LeikwanHttpClientKind
{
    Normal,
    AntiPoison,
    Plain,
}

/// <summary>
/// The only sanctioned way to obtain panel/update HTTP clients. Normal and
/// AntiPoison clients always ignore HTTP proxies and, when the destination
/// is a panel/update host, force kernel-level egress through the physical
/// NIC (raw socket interface binding plus optional host routes) so
/// third-party TUN tools cannot swallow lease, heartbeat, polling or update
/// traffic. Plain clients keep OS default routing (UseProxy=false only) and
/// are reserved for data-plane probes that must follow the user's routing.
/// </summary>
public static class LeikwanHttpClientFactory
{
    private static StrictDohResolver SharedResolver => PanelDirectEgressBootstrap.Resolver;

    public static HttpClient CreateDefault()
    {
        return CreateAntiPoison(SharedResolver);
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
        return kind switch
        {
            LeikwanHttpClientKind.AntiPoison => CreateDefault(),
            LeikwanHttpClientKind.Plain => CreatePlain(),
            _ => CreateNormal(),
        };
    }

    internal static HttpClient CreateAntiPoison(ILeikwanDnsResolver resolver)
    {
        var connector = new LeikwanDnsRoutingConnector(
            resolver,
            egress: PanelDirectEgressBootstrap.Engine,
            configureSocket: PanelDirectEgressBootstrap.BindSocket);
        var handler = BuildHandler();
        handler.ConnectCallback = (context, ct) =>
        {
            var uri = ResolveUri(context);
            PanelDirectEgress.RegisterRequestHost(uri);
            return connector.ConnectAsync(uri, context.DnsEndPoint.Host, context.DnsEndPoint.Port, ct);
        };

        return new HttpClient(handler, disposeHandler: true)
        {
            Timeout = TimeSpan.FromSeconds(30),
        };
    }

    private static HttpClient CreateNormal()
    {
        // Panel/control-plane traffic must stay on the normal international
        // route even while any TUN tool (Netch/Clash/v2rayN/NekoBox/...) is
        // enabled. HTTP proxies are unreachable by construction (UseProxy =
        // false), and the connect callback additionally forces the physical
        // egress interface for every panel/update host so a TUN default
        // route can never loop lease or heartbeat traffic back into the
        // data plane.
        var connector = new LeikwanDnsRoutingConnector(
            SharedResolver,
            egress: PanelDirectEgressBootstrap.Engine,
            configureSocket: PanelDirectEgressBootstrap.BindSocket);
        var handler = BuildHandler();
        handler.ConnectCallback = (context, ct) =>
        {
            var uri = ResolveUri(context);
            PanelDirectEgress.RegisterRequestHost(uri);
            return connector.ConnectAsync(uri, context.DnsEndPoint.Host, context.DnsEndPoint.Port, ct);
        };

        return new HttpClient(handler, disposeHandler: true)
        {
            Timeout = TimeSpan.FromSeconds(30),
        };
    }

    /// <summary>
    /// Data-plane probe client: no proxy, OS default routing, no direct
    /// egress. Used for relay health probes that must follow the user's
    /// current route table (including a TUN when one is active).
    /// </summary>
    public static HttpClient CreatePlain()
    {
        var handler = new SocketsHttpHandler
        {
            AutomaticDecompression = DecompressionMethods.GZip | DecompressionMethods.Deflate,
            ConnectTimeout = TimeSpan.FromSeconds(10),
            PooledConnectionLifetime = TimeSpan.FromMinutes(5),
            UseProxy = false,
        };
        return new HttpClient(handler, disposeHandler: true)
        {
            Timeout = TimeSpan.FromSeconds(30),
        };
    }

    private static SocketsHttpHandler BuildHandler()
    {
        return new SocketsHttpHandler
        {
            AutomaticDecompression = DecompressionMethods.GZip | DecompressionMethods.Deflate,
            ConnectTimeout = TimeSpan.FromSeconds(10),
            PooledConnectionLifetime = TimeSpan.FromMinutes(5),
            UseProxy = false,
        };
    }

    private static Uri ResolveUri(SocketsHttpConnectionContext context)
    {
        if (context.InitialRequestMessage?.RequestUri is { } requestUri)
            return requestUri;
        var scheme = context.InitialRequestMessage?.RequestUri?.Scheme ?? Uri.UriSchemeHttps;
        return new UriBuilder(scheme, context.DnsEndPoint.Host, context.DnsEndPoint.Port).Uri;
    }
}
