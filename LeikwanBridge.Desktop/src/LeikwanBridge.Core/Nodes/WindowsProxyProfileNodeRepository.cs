using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Nodes;

/// <summary>The part of <see cref="Services.WindowsProxyConnectionService"/> the node repository needs.</summary>
public interface IWindowsProxyProfileHost
{
    IReadOnlyList<WindowsProxyProfile> Profiles { get; }
    Task SaveProfileAsync(WindowsProxyProfile profile, CancellationToken ct = default);
    Task DeleteProfileAsync(int profileId, CancellationToken ct = default);
}

/// <summary>
/// Windows node repository: the user's nodes ARE the saved proxy profiles the Windows
/// app already runs with sing-box (DPAPI-protected in <see cref="Services.WindowsProxyProfileStore"/>).
/// A hosted node is a profile with <see cref="WindowsProxyProfile.HostedId"/> set, so it
/// pairs with forwards and connects exactly like a pasted node. "External" profiles are
/// not nodes and are invisible to sync.
/// </summary>
public sealed class WindowsProxyProfileNodeRepository : IUserNodeRepository
{
    private readonly IWindowsProxyProfileHost _host;

    public WindowsProxyProfileNodeRepository(IWindowsProxyProfileHost host)
        => _host = host ?? throw new ArgumentNullException(nameof(host));

    public Task<IReadOnlyList<UserNode>> LoadAsync(CancellationToken ct = default)
        => Task.FromResult<IReadOnlyList<UserNode>>(_host.Profiles
            .Select(ToNode)
            .Where(node => node is not null)
            .Select(node => node!)
            .ToList());

    public async Task<UserNode> SaveAsync(UserNode node, CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(node);
        var existing = node.LocalId > 0 ? _host.Profiles.FirstOrDefault(profile => profile.Id == node.LocalId) : null;
        var profile = ToProfile(node, existing);
        await _host.SaveProfileAsync(profile, ct).ConfigureAwait(false);
        var saved = _host.Profiles.FirstOrDefault(item => item.Id == profile.Id) ?? profile;
        return ToNode(saved) ?? node.Clone();
    }

    public Task DeleteAsync(int localId, CancellationToken ct = default)
        => _host.DeleteProfileAsync(localId, ct);

    /// <summary>Null for profiles that are not nodes (External IP + port).</summary>
    public static UserNode? ToNode(WindowsProxyProfile profile)
    {
        ArgumentNullException.ThrowIfNull(profile);
        var protocol = WindowsProxyProtocol.Normalize(profile.Protocol);
        var node = new UserNode
        {
            LocalId = profile.Id,
            HostedId = profile.HostedId > 0 ? profile.HostedId : null,
            Name = profile.Name,
            Server = profile.Server,
            Port = profile.Port,
        };
        switch (protocol)
        {
            case WindowsProxyProtocol.Shadowsocks:
            case WindowsProxyProtocol.Shadowsocks2022:
                node.Protocol = UserNodeProtocol.Shadowsocks;
                node.Method = profile.Method;
                node.Password = profile.Password;
                node.Plugin = profile.Plugin;
                node.PluginOpts = profile.PluginOpts;
                node.UdpOverTcp = profile.UdpOverTcp;
                return node;
            case WindowsProxyProtocol.Socks5:
                node.Protocol = UserNodeProtocol.Socks5;
                node.Username = profile.Username;
                node.Password = profile.Password;
                return node;
            case WindowsProxyProtocol.Vless:
                node.Protocol = UserNodeProtocol.Vless;
                node.Uuid = profile.Uuid;
                node.Flow = profile.Flow;
                node.Security = !string.IsNullOrWhiteSpace(profile.RealityPublicKey) ? "reality" : profile.Tls ? "tls" : "none";
                node.Sni = profile.ServerName;
                node.Fingerprint = profile.Fingerprint;
                node.Alpn = profile.Alpn;
                node.AllowInsecure = profile.AllowInsecure;
                node.PublicKey = profile.RealityPublicKey;
                node.ShortId = profile.RealityShortId;
                node.Transport = string.IsNullOrWhiteSpace(profile.Transport) ? "tcp" : profile.Transport;
                node.Path = node.Transport is "ws" or "httpupgrade" ? profile.Path : string.Empty;
                node.Host = node.Transport is "ws" or "httpupgrade" ? profile.Host : string.Empty;
                node.ServiceName = node.Transport == "grpc" ? profile.ServiceName : string.Empty;
                return node;
            default:
                return null;
        }
    }

    /// <summary>
    /// Profile for a node. Windows-only fields of <paramref name="existing"/> (enabled) are kept.
    /// </summary>
    public static WindowsProxyProfile ToProfile(UserNode node, WindowsProxyProfile? existing = null)
    {
        ArgumentNullException.ThrowIfNull(node);
        var profile = new WindowsProxyProfile
        {
            Id = node.LocalId,
            Name = string.IsNullOrWhiteSpace(node.Name) ? node.DisplayEndpoint : node.Name,
            Server = node.Server,
            Port = node.Port,
            HostedId = node.HostedId is > 0 ? node.HostedId.Value : 0,
            Enabled = existing?.Enabled ?? true,
        };
        switch (UserNodeProtocol.Normalize(node.Protocol))
        {
            case UserNodeProtocol.Shadowsocks:
                profile.Protocol = WindowsProxyProtocol.IsShadowsocks2022Method(node.Method)
                    ? WindowsProxyProtocol.Shadowsocks2022
                    : WindowsProxyProtocol.Shadowsocks;
                profile.Method = node.Method;
                profile.Password = node.Password;
                profile.Plugin = UserNodeShareLink.NormalizePlugin(node.Plugin);
                profile.PluginOpts = profile.Plugin.Length == 0 ? string.Empty : node.PluginOpts;
                profile.UdpOverTcp = node.UdpOverTcp;
                break;
            case UserNodeProtocol.Socks5:
                profile.Protocol = WindowsProxyProtocol.Socks5;
                profile.Username = node.Username;
                profile.Password = node.Password;
                break;
            case UserNodeProtocol.Vless:
            {
                var security = UserNodeShareLink.NormalizeSecurity(node.Security);
                var transport = UserNodeShareLink.NormalizeTransport(node.Transport);
                profile.Protocol = WindowsProxyProtocol.Vless;
                profile.Uuid = node.Uuid;
                profile.Flow = node.Flow;
                profile.Transport = transport;
                profile.Path = transport is "ws" or "httpupgrade" ? (string.IsNullOrWhiteSpace(node.Path) ? "/" : node.Path) : "/";
                profile.Host = transport is "ws" or "httpupgrade" ? node.Host : string.Empty;
                profile.ServiceName = transport == "grpc" ? node.ServiceName : string.Empty;
                profile.Tls = security is "tls" or "reality";
                profile.ServerName = security == "none" ? string.Empty : node.Sni;
                profile.Fingerprint = security == "none" ? string.Empty : node.Fingerprint;
                profile.Alpn = security == "none" ? string.Empty : node.Alpn;
                profile.AllowInsecure = security == "tls" && node.AllowInsecure;
                profile.RealityPublicKey = security == "reality" ? node.PublicKey : string.Empty;
                profile.RealityShortId = security == "reality" ? node.ShortId : string.Empty;
                break;
            }
            default:
                throw new InvalidOperationException("node_protocol_unsupported");
        }
        return profile;
    }
}
