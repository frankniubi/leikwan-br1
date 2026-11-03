using System.Text;
using LeikwanBridge.Core.Nodes;
using Xunit;

namespace LeikwanBridge.Core.Tests.Nodes;

public sealed class UserNodeShareLinkTests
{
    private static string B64Url(string value)
        => Convert.ToBase64String(Encoding.UTF8.GetBytes(value)).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    [Fact]
    public void ShadowsocksSip002Base64UserInfoWithPluginAndUot()
    {
        var node = UserNodeShareLink.Parse("ss://" + B64Url("aes-256-gcm:p@ss:word") + "@ss.example.com:8388/?plugin=simple-obfs%3Bobfs%3Dhttp%3Bobfs-host%3Dcdn.example.com&uot=1#Home%20SS");

        Assert.Equal(UserNodeProtocol.Shadowsocks, node.Protocol);
        Assert.Equal("Home SS", node.Name);
        Assert.Equal("ss.example.com", node.Server);
        Assert.Equal(8388, node.Port);
        Assert.Equal("aes-256-gcm", node.Method);
        Assert.Equal("p@ss:word", node.Password);
        Assert.Equal("obfs-local", node.Plugin);
        Assert.Equal("obfs=http;obfs-host=cdn.example.com", node.PluginOpts);
        Assert.True(node.UdpOverTcp);
        Assert.True(node.IsCloudSupported);
    }

    [Fact]
    public void ShadowsocksPercentEncodedAndLegacyForms()
    {
        var plain = UserNodeShareLink.Parse("ss://2022-blake3-aes-256-gcm:aXBzaw%3D%3D:dXBzaw%3D%3D@[2001:db8::1]:443#ss22");
        Assert.Equal("2022-blake3-aes-256-gcm", plain.Method);
        Assert.Equal("aXBzaw==:dXBzaw==", plain.Password);
        Assert.Equal("2001:db8::1", plain.Server);
        Assert.Equal("SS2022", plain.DisplayProtocol);

        var legacy = UserNodeShareLink.Parse("ss://" + Convert.ToBase64String(Encoding.UTF8.GetBytes("chacha20-ietf-poly1305:pw@198.51.100.4:9000")));
        Assert.Equal("chacha20-ietf-poly1305", legacy.Method);
        Assert.Equal("pw", legacy.Password);
        Assert.Equal("198.51.100.4", legacy.Server);
        Assert.Equal(9000, legacy.Port);
        Assert.Equal("198.51.100.4:9000", legacy.Name);
    }

    [Fact]
    public void LegacyStreamCipherParsesButStaysLocal()
    {
        var node = UserNodeShareLink.Parse("ss://" + B64Url("rc4-md5:pw") + "@a.example.com:1#old");
        Assert.False(node.IsCloudSupported);
    }

    [Theory]
    [InlineData("socks5://alice:wonder%40land@198.51.100.7:1080#Home", "alice", "wonder@land")]
    [InlineData("socks://198.51.100.7:1080", "", "")]
    [InlineData("SOCKS5://bob:x@198.51.100.7:1080/", "bob", "x")]
    public void Socks5Forms(string uri, string user, string pass)
    {
        var node = UserNodeShareLink.Parse(uri);
        Assert.Equal(UserNodeProtocol.Socks5, node.Protocol);
        Assert.Equal("198.51.100.7", node.Server);
        Assert.Equal(1080, node.Port);
        Assert.Equal(user, node.Username);
        Assert.Equal(pass, node.Password);
    }

    [Fact]
    public void Socks5V2rayNBase64UserInfo()
    {
        var node = UserNodeShareLink.Parse("socks://" + Convert.ToBase64String(Encoding.UTF8.GetBytes("carol:secret")) + "@h.example.com:7890#v2rayN");
        Assert.Equal("carol", node.Username);
        Assert.Equal("secret", node.Password);
    }

    [Fact]
    public void VlessQueryAndFragmentDecodedOnceAndIpv6Stripped()
    {
        var node = UserNodeShareLink.Parse(
            "vless://0F1E2D3C-4B5A-6978-8796-A5B4C3D2E1F0@[2001:db8::2]:443?encryption=none&type=ws&security=tls&sni=cdn.example.com&host=cdn.example.com&path=%2Fray%3Fed%3D2048&alpn=h2%2Chttp%2F1.1&fp=chrome#My%20VLESS%25");

        Assert.Equal("0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0", node.Uuid);
        Assert.Equal("2001:db8::2", node.Server);
        Assert.Equal("ws", node.Transport);
        Assert.Equal("/ray?ed=2048", node.Path);
        Assert.Equal("tls", node.Security);
        Assert.Equal("h2,http/1.1", node.Alpn);
        Assert.Equal("My VLESS%", node.Name);
    }

    [Fact]
    public void VlessRealityVision()
    {
        var key = new string('A', 43);
        var node = UserNodeShareLink.Parse($"vless://11111111-2222-3333-4444-555555555555@r.example.com:443?type=raw&security=reality&flow=xtls-rprx-vision&sni=www.example.com&pbk={key}&sid=ab12#R");
        Assert.Equal("tcp", node.Transport);
        Assert.Equal("reality", node.Security);
        Assert.Equal("xtls-rprx-vision", node.Flow);
        Assert.Equal(key, node.PublicKey);
        Assert.Equal("ab12", node.ShortId);
    }

    [Theory]
    [InlineData("vless://not-a-uuid@a.example.com:443", "node_uuid_invalid")]
    [InlineData("vless://11111111-2222-3333-4444-555555555555@a.example.com:443?encryption=aes", "node_encryption_unsupported")]
    [InlineData("vmess://e30=", "node_protocol_unsupported")]
    [InlineData("socks5://a.example.com:99999", "node_port_invalid")]
    [InlineData("", "node_uri_required")]
    public void ErrorsAreStableCodes(string uri, string code)
    {
        Assert.False(UserNodeShareLink.TryParse(uri, out _, out var error));
        Assert.Equal(code, error);
    }

    [Fact]
    public void ErrorsNeverEchoTheLink()
    {
        Assert.False(UserNodeShareLink.TryParse("ss://" + B64Url("aes-256-gcm") + "@a.example.com:1#topsecret", out _, out var error));
        Assert.DoesNotContain("topsecret", error, StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("socks5://alice:wonder@198.51.100.7:1080#Home")]
    [InlineData("socks5://198.51.100.7:1080#No%20auth")]
    [InlineData("ss://YWVzLTI1Ni1nY206cHc@ss.example.com:8388?plugin=obfs-local%3Bobfs%3Dhttp&uot=1#SS")]
    [InlineData("vless://11111111-2222-3333-4444-555555555555@v.example.com:443?encryption=none&type=ws&security=tls&sni=v.example.com&fp=chrome&path=%2Fray%3Fed%3D2048&host=v.example.com&alpn=h2#V")]
    [InlineData("vless://11111111-2222-3333-4444-555555555555@v.example.com:443?encryption=none&type=grpc&security=none&serviceName=svc#G")]
    public void FormatIsTheInverseOfParseForCanonicalLinks(string uri)
    {
        var node = UserNodeShareLink.Parse(uri);
        Assert.Equal(uri, UserNodeShareLink.Format(node));
        var again = UserNodeShareLink.Parse(UserNodeShareLink.Format(node)!);
        Assert.Equal(UserNodeIdentity.Key(node), UserNodeIdentity.Key(again));
    }

    [Fact]
    public void FormatsIpv6WithBrackets()
    {
        var node = new UserNode { Protocol = "socks5", Server = "2001:db8::9", Port = 1080, Name = "v6" };
        Assert.Equal("socks5://[2001:db8::9]:1080#v6", UserNodeShareLink.Format(node));
    }

    [Fact]
    public void ParseManyAcceptsLinesAndBase64Subscriptions()
    {
        var text = "socks5://198.51.100.7:1080#A\nnot a link\nss://" + B64Url("aes-128-gcm:x") + "@b.example.com:1#B";
        Assert.Equal(2, UserNodeShareLink.ParseMany(text).Count);
        var subscription = Convert.ToBase64String(Encoding.UTF8.GetBytes(text));
        Assert.Equal(2, UserNodeShareLink.ParseMany(subscription).Count);
    }

    [Fact]
    public void IdentityIgnoresNameAndTlsCosmetics()
    {
        var a = UserNodeShareLink.Parse("vless://11111111-2222-3333-4444-555555555555@V.example.com:443?type=ws&security=tls&sni=v.example.com&fp=chrome&alpn=h2#One");
        var b = UserNodeShareLink.Parse("vless://11111111-2222-3333-4444-555555555555@v.example.com:443?type=ws&security=tls&sni=v.example.com&path=%2F#Two");
        Assert.Equal(UserNodeIdentity.Key(a), UserNodeIdentity.Key(b));

        var obfs = UserNodeShareLink.Parse("ss://" + B64Url("aes-256-gcm:pw") + "@a.example.com:1?plugin=simple-obfs%3Bobfs%3Dhttp");
        var local = UserNodeShareLink.Parse("ss://" + B64Url("aes-256-gcm:pw") + "@a.example.com:1?plugin=obfs-local%3Bobfs%3Dhttp");
        Assert.Equal(UserNodeIdentity.Key(obfs), UserNodeIdentity.Key(local));

        var other = UserNodeShareLink.Parse("ss://" + B64Url("aes-256-gcm:pw2") + "@a.example.com:1");
        Assert.NotEqual(UserNodeIdentity.Key(local), UserNodeIdentity.Key(other));
    }
}
