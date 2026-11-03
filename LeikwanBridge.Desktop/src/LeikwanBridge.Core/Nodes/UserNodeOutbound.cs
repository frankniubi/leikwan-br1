using System.Net;
using System.Text.Json.Nodes;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Core.Nodes;

/// <summary>
/// How a paired node is used on desktop: the local sing-box dials the node at
/// <c>127.0.0.1:&lt;forward listen port&gt;</c> and the forward carries the bytes to the node's
/// real address. Only the dial endpoint changes; the node's TLS server name and the HTTP Host
/// of a ws / httpupgrade transport keep the real host, otherwise the node would see
/// "127.0.0.1" (same rule as Android's SingboxConfigBuilder).
/// </summary>
public static class UserNodeOutbound
{
    /// <summary>Copy of <paramref name="profile"/> that dials the forward's local listener.</summary>
    public static WindowsProxyProfile RewriteForForward(WindowsProxyProfile profile, int forwardListenPort)
    {
        ArgumentNullException.ThrowIfNull(profile);
        if (forwardListenPort is < 1 or > 65535)
            throw new InvalidOperationException("proxy_forward_listener_invalid");
        var copy = profile.Clone();
        // Preserve implicit TLS identity before replacing only the dial endpoint.
        if ((copy.Tls || !string.IsNullOrWhiteSpace(copy.RealityPublicKey)) && string.IsNullOrWhiteSpace(copy.ServerName))
            copy.ServerName = copy.Server;
        if (copy.IsVless && copy.Transport is "ws" or "httpupgrade"
            && string.IsNullOrWhiteSpace(copy.Host)
            && !IPAddress.TryParse(copy.Server, out _))
            copy.Host = copy.Server;
        copy.Server = "127.0.0.1";
        copy.Port = forwardListenPort;
        return copy;
    }

    /// <summary>
    /// The sing-box outbound (tag "proxy") for <paramref name="node"/> riding the forward that
    /// listens on <paramref name="forwardListenPort"/>. For a platform that builds its own
    /// sing-box config (macOS) but wants the same SS / SOCKS5 / VLESS outbound as Windows.
    /// Throws ArgumentException / InvalidOperationException for an invalid node.
    /// </summary>
    public static JsonObject ForForward(UserNode node, int forwardListenPort)
    {
        ArgumentNullException.ThrowIfNull(node);
        var profile = RewriteForForward(WindowsProxyProfileNodeRepository.ToProfile(node), forwardListenPort);
        return WindowsProxyConfigBuilder.BuildProxyOutbound(profile);
    }
}
