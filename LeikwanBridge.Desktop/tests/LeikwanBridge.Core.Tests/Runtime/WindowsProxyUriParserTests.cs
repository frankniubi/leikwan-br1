using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsProxyUriParserTests
{
    [Fact]
    public void ParsesPlainSs2022UserInfoWithoutTruncatingPassword()
    {
        const string uri =
            "ss://2022-blake3-aes-256-gcm:server-key=:client-secret=@edge.example.test:30526#HKG-UDP";

        var profile = WindowsProxyUriParser.Parse(uri);

        Assert.Equal(WindowsProxyProtocol.Shadowsocks2022, profile.Protocol);
        Assert.Equal("2022-blake3-aes-256-gcm", profile.Method);
        Assert.Equal("server-key=:client-secret=", profile.Password);
        Assert.Equal("edge.example.test", profile.Server);
        Assert.Equal(30526, profile.Port);
        Assert.Equal("HKG-UDP", profile.Name);
    }

    [Fact]
    public void ParsesBase64SsUserInfoAndQueryOptions()
    {
        // base64url("aes-256-gcm:secret")
        const string uri = "ss://YWVzLTI1Ni1nY206c2VjcmV0@198.51.100.7:443?udp_over_tcp=0#Tokyo";

        var profile = WindowsProxyUriParser.Parse(uri);

        Assert.Equal(WindowsProxyProtocol.Shadowsocks, profile.Protocol);
        Assert.Equal("aes-256-gcm", profile.Method);
        Assert.Equal("secret", profile.Password);
        Assert.Equal("Tokyo", profile.Name);
        Assert.False(profile.UdpOverTcp);
    }

    [Theory]
    [InlineData("none")]
    [InlineData("aes-128-ctr")]
    public void ParsesClassicShadowsocksMethods(string method)
    {
        var profile = WindowsProxyUriParser.Parse(
            $"ss://{method}:secret@example.test:443#classic");

        Assert.Equal(WindowsProxyProtocol.Shadowsocks, profile.Protocol);
        Assert.Equal(method, profile.Method);
        Assert.Equal("secret", profile.Password);
    }

    [Fact]
    public void ParsesPasswordlessNoneShadowsocksLink()
    {
        var profile = WindowsProxyUriParser.Parse("ss://none@example.test:443#none");

        Assert.Equal("none", profile.Method);
        Assert.Empty(profile.Password);
    }

    [Fact]
    public void AcceptsSip002TrailingSlashAndPercentEscapedFullAuthority()
    {
        // base64url("aes-256-gcm:secret@example.test:443") with its padding
        // percent-escaped by a subscription provider.
        const string uri = "ss://YWVzLTI1Ni1nY206c2VjcmV0QGV4YW1wbGUudGVzdDo0NDM%3D/#escaped";

        var profile = WindowsProxyUriParser.Parse(uri);

        Assert.Equal("example.test", profile.Server);
        Assert.Equal(443, profile.Port);
        Assert.Equal("secret", profile.Password);
        Assert.Equal("escaped", profile.Name);

        var userInfoProfile = WindowsProxyUriParser.Parse(
            "ss://YWVzLTI1Ni1nY206c2VjcmV0@example.test:443/#slash");
        Assert.Equal(443, userInfoProfile.Port);
        Assert.Equal("slash", userInfoProfile.Name);
    }

    [Fact]
    public void RejectsUnsupportedShadowsocksPluginWithStableCode()
    {
        var ok = WindowsProxyUriParser.TryParse(
            "ss://aes-256-gcm:secret@example.test:443/?plugin=v2ray-plugin%3Bmode%3Dwebsocket",
            out _,
            out var error);

        Assert.False(ok);
        Assert.Equal("proxy_plugin_unsupported", error);
    }

    [Fact]
    public void ParsesVlessSelectorsAndTlsQuery()
    {
        const string uri =
            "vless://00000000-0000-0000-0000-000000000001@vless.example.test:443" +
            "?type=ws&security=reality&sni=cdn.example.test&host=cdn.example.test" +
            "&path=%2Fedge&flow=xtls-rprx-vision&fp=chrome&pbk=public-key&sid=abcd#VLESS";

        var profile = WindowsProxyUriParser.Parse(uri);

        Assert.Equal(WindowsProxyProtocol.Vless, profile.Protocol);
        Assert.Equal("ws", profile.Transport);
        Assert.Equal("/edge", profile.Path);
        Assert.Equal("cdn.example.test", profile.Host);
        Assert.Equal("cdn.example.test", profile.ServerName);
        Assert.True(profile.Tls);
        Assert.Equal("xtls-rprx-vision", profile.Flow);
        Assert.Equal("chrome", profile.Fingerprint);
        Assert.Equal("public-key", profile.RealityPublicKey);
        Assert.Equal("abcd", profile.RealityShortId);
    }

    [Fact]
    public void TreatsVlessNoneTransportAsTcp()
    {
        var profile = WindowsProxyUriParser.Parse(
            "vless://00000000-0000-0000-0000-000000000001@example.test:443?type=none");

        Assert.Equal("tcp", profile.Transport);
    }

    [Fact]
    public void RejectsUnsupportedOrMalformedLinksWithoutExposingSecrets()
    {
        var exception = Assert.Throws<FormatException>(() =>
            WindowsProxyUriParser.Parse("vmess://not-supported-secret"));

        Assert.DoesNotContain("not-supported-secret", exception.Message, StringComparison.Ordinal);
        Assert.Throws<FormatException>(() =>
            WindowsProxyUriParser.Parse("ss://aes-256-gcm:secret@edge.example.test:70000"));
    }
}
