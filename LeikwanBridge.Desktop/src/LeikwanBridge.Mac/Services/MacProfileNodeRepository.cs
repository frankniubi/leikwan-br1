using LeikwanBridge.Core.Nodes;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// The macOS saved connection profiles seen as the Core node list, so
/// <see cref="HostedNodeSyncService"/> syncs the same nodes the routed connection runs
/// (the Windows counterpart is WindowsProxyProfileNodeRepository). Only Shadowsocks,
/// SOCKS5 and VLESS profiles are nodes here; WireGuard and external endpoints stay
/// Mac-only and are never touched by sync.
///
/// Plain Shadowsocks (no plugin) keeps the field layout the editor understands; every
/// other node is stored with its canonical share link, from which the outbound is built.
/// </summary>
public sealed class MacProfileNodeRepository : IUserNodeRepository
{
    private readonly IMacConnectionService _connections;

    public MacProfileNodeRepository(IMacConnectionService connections)
    {
        _connections = connections;
    }

    public Task<IReadOnlyList<UserNode>> LoadAsync(CancellationToken ct = default)
    {
        IReadOnlyList<UserNode> nodes = _connections.Profiles
            .Select(ToNode)
            .Where(node => node is not null)
            .Select(node => node!)
            .ToList();
        return Task.FromResult(nodes);
    }

    public async Task<UserNode> SaveAsync(UserNode node, CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(node);
        var existing = node.LocalId > 0
            ? _connections.Profiles.FirstOrDefault(profile => profile.Id == node.LocalId)
            : null;
        var profile = ToProfile(node, existing);
        var before = _connections.Profiles.Select(item => item.Id).ToHashSet();
        await _connections.SaveProfileAsync(profile, ct).ConfigureAwait(false);
        var saved = profile.Id > 0
            ? _connections.Profiles.FirstOrDefault(item => item.Id == profile.Id)
            : _connections.Profiles.FirstOrDefault(item => !before.Contains(item.Id));
        if (saved is null)
            throw new InvalidOperationException("node_save_failed");
        var copy = node.Clone();
        copy.LocalId = saved.Id;
        return copy;
    }

    public Task DeleteAsync(int localId, CancellationToken ct = default)
        => _connections.DeleteProfileAsync(localId, ct);

    /// <summary>The Core node for a Mac profile, or null for WireGuard / external / broken rows.</summary>
    public static UserNode? ToNode(MacConnectionProfile profile)
    {
        if (profile is null || profile.Id <= 0)
            return null;
        UserNode node;
        if (profile.HasShareLink)
        {
            if (!UserNodeShareLink.TryParse(profile.ShareLink, out node, out _))
                return null;
        }
        else if (string.Equals(profile.Protocol, MacConnectionProtocol.Shadowsocks, StringComparison.OrdinalIgnoreCase))
        {
            node = new UserNode
            {
                Protocol = UserNodeProtocol.Shadowsocks,
                Server = profile.Server,
                Port = profile.Port,
                Method = profile.Method,
                Password = profile.Password,
            };
        }
        else
        {
            return null;
        }
        node.LocalId = profile.Id;
        node.HostedId = profile.HostedId is > 0 ? profile.HostedId : null;
        if (!string.IsNullOrWhiteSpace(profile.Name))
            node.Name = profile.Name;
        return node;
    }

    /// <summary>The Mac profile for a Core node; keeps the id and Mac-only fields of <paramref name="existing"/>.</summary>
    public static MacConnectionProfile ToProfile(UserNode node, MacConnectionProfile? existing)
    {
        var profile = existing?.Clone() ?? new MacConnectionProfile();
        profile.Id = existing?.Id ?? 0;
        profile.Name = string.IsNullOrWhiteSpace(node.Name) ? node.DisplayEndpoint : node.Name.Trim();
        profile.Server = node.Server;
        profile.Port = node.Port;
        profile.HostedId = node.HostedId is > 0 ? node.HostedId : null;
        var protocol = UserNodeProtocol.Normalize(node.Protocol);
        if (protocol == UserNodeProtocol.Shadowsocks && string.IsNullOrWhiteSpace(node.Plugin) && !node.UdpOverTcp)
        {
            profile.Protocol = MacConnectionProtocol.Shadowsocks;
            profile.Method = node.Method;
            profile.Password = node.Password;
            profile.ShareLink = string.Empty;
        }
        else
        {
            profile.Protocol = protocol switch
            {
                UserNodeProtocol.Socks5 => MacConnectionProtocol.Socks5,
                UserNodeProtocol.Vless => MacConnectionProtocol.Vless,
                _ => MacConnectionProtocol.Shadowsocks,
            };
            profile.Method = protocol == UserNodeProtocol.Shadowsocks ? node.Method : string.Empty;
            profile.Password = string.Empty;
            profile.ShareLink = UserNodeShareLink.Format(node) ?? string.Empty;
        }
        return profile;
    }
}
