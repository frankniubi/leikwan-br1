using System.Net;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.PanelEgress;
using Xunit;

namespace LeikwanBridge.Core.Tests.PanelEgress;

public sealed class PanelDirectEgressHostSetTests
{
    [Fact]
    public void WellKnownHosts_ContainPanelEndpointsAndDohProviders()
    {
        Assert.Contains("v3.leikwanhost.com", PanelDirectEgress.WellKnownHosts);
        Assert.Contains("api-leikwanbridge.nbwly.com", PanelDirectEgress.WellKnownHosts);
        Assert.Contains("dns.alidns.com", PanelDirectEgress.WellKnownHosts);
        Assert.Contains("doh.pub", PanelDirectEgress.WellKnownHosts);
        Assert.Contains("doh.360.cn", PanelDirectEgress.WellKnownHosts);
    }

    [Fact]
    public void IsWellKnownHost_IsCaseInsensitiveAndTrims()
    {
        Assert.True(PanelDirectEgress.IsWellKnownHost("V3.LEIKWANHOST.COM"));
        Assert.True(PanelDirectEgress.IsWellKnownHost(" v3.leikwanhost.com "));
        Assert.False(PanelDirectEgress.IsWellKnownHost("cdn.example.com"));
        Assert.False(PanelDirectEgress.IsWellKnownHost(null));
    }

    [Fact]
    public void EngineHostCollection_CoversPanelSuffixWellKnownRegisteredAndLiterals()
    {
        var engine = new PanelDirectEgressEngine(
            new FakeDnsResolver(IPAddress.Parse("203.0.113.7")),
            new FakeInterfaceProvider());

        // Well-known panel hosts.
        Assert.True(engine.IsDirectEgressHost("v3.leikwanhost.com"));
        Assert.True(engine.IsDirectEgressHost("api-leikwanbridge.nbwly.com"));
        // *.leikwanhost.com subdomains (artifacts, mirrors), but not the apex.
        Assert.True(engine.IsDirectEgressHost("artifacts.leikwanhost.com"));
        Assert.True(engine.IsDirectEgressHost("v3-mirror.leikwanhost.com"));
        Assert.False(engine.IsDirectEgressHost("leikwanhost.com"));
        // Dynamic registration (update CDNs discovered at runtime).
        Assert.False(engine.IsDirectEgressHost("update-cdn.example.net"));
        engine.RegisterHost("update-cdn.example.net");
        Assert.True(engine.IsDirectEgressHost("update-cdn.example.net"));
        Assert.True(engine.IsDirectEgressHost("UPDATE-CDN.EXAMPLE.NET"));
        // Public IP literals are direct; loopback/private are not.
        Assert.True(engine.IsDirectEgressHost("203.0.113.5"));
        Assert.False(engine.IsDirectEgressHost("127.0.0.1"));
        Assert.False(engine.IsDirectEgressHost("192.168.1.1"));
        // Localhost names and unknowns are excluded.
        Assert.False(engine.IsDirectEgressHost("localhost"));
        Assert.False(engine.IsDirectEgressHost("foo.localhost"));
        Assert.False(engine.IsDirectEgressHost("cdn.example.com"));
    }

    [Fact]
    public void EngineHostCollection_RegisteredHostsPrewarmAndResolveThroughDoh()
    {
        var resolver = new FakeDnsResolver(IPAddress.Parse("203.0.113.7"));
        var engine = new PanelDirectEgressEngine(resolver, new FakeInterfaceProvider());

        engine.RegisterHost("update-cdn.example.net");

        // Prewarm is fire-and-forget; spin until the resolver observed it.
        var deadline = DateTime.UtcNow.AddSeconds(5);
        while (resolver.Hosts.Count == 0 && DateTime.UtcNow < deadline)
            Thread.Sleep(20);
        Assert.Contains("update-cdn.example.net", resolver.Hosts);
        Assert.Contains("update-cdn.example.net", engine.RegisteredHosts);
    }

    [Fact]
    public void RegisterRequestHost_OnlyHttpsNamedHosts()
    {
        var resolver = new FakeDnsResolver(IPAddress.Parse("203.0.113.7"));
        var engine = new PanelDirectEgressEngine(resolver, new FakeInterfaceProvider());

        engine.RegisterRequestHost(new Uri("https://packages.example.org/setup.exe"));
        engine.RegisterRequestHost(new Uri("http://plain.example.org/setup.exe"));
        engine.RegisterRequestHost(new Uri("https://127.0.0.1/x"));
        engine.RegisterRequestHost(new Uri("https://localhost/x"));
        engine.RegisterRequestHost(new Uri("https://svc.internal.localhost/x"));

        Assert.True(engine.IsDirectEgressHost("packages.example.org"));
        Assert.False(engine.IsDirectEgressHost("plain.example.org"));
        Assert.False(engine.IsDirectEgressHost("127.0.0.1"));
        Assert.False(engine.IsDirectEgressHost("localhost"));
        Assert.False(engine.IsDirectEgressHost("svc.internal.localhost"));
        Assert.DoesNotContain("localhost", engine.RegisteredHosts);
        Assert.DoesNotContain("svc.internal.localhost", engine.RegisteredHosts);
    }

    [Fact]
    public void IsDirectEgressUri_RequiresAbsoluteUri()
    {
        var engine = new PanelDirectEgressEngine(
            new FakeDnsResolver(IPAddress.Parse("203.0.113.7")),
            new FakeInterfaceProvider());

        Assert.True(engine.IsDirectEgressUri(new Uri("https://v3.leikwanhost.com/api")));
        Assert.False(engine.IsDirectEgressUri(new Uri("/api", UriKind.Relative)));
    }
}
