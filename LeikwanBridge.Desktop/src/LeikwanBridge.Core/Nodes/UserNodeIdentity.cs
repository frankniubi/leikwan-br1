namespace LeikwanBridge.Core.Nodes;

/// <summary>
/// Connection identity of a node, independent of its name and of how its share link
/// spelled it (same rules as Android's HostedNodeIdentity).
///
/// Used for dedup in both directions:
/// <list type="bullet">
/// <item>cloud → local: a hosted node equal to a node already on this device adopts that
/// row (keeps its local id, so forward pairings and the selection stay valid);</item>
/// <item>local imports: a pasted link equal to a hosted node is skipped.</item>
/// </list>
/// TLS cosmetics (uTLS fingerprint, ALPN, allowInsecure) are not part of the identity:
/// the panel fills defaults for them and they do not change which server and account a
/// node reaches. The panel dedups uploads separately with its own keyed fingerprint.
/// </summary>
public static class UserNodeIdentity
{
    /// <summary>Null for protocols the platform does not host.</summary>
    public static string? Key(UserNode node)
    {
        ArgumentNullException.ThrowIfNull(node);
        var protocol = UserNodeProtocol.Normalize(node.Protocol);
        if (protocol.Length == 0)
            return null;
        var server = node.Server.Trim();
        if (server.StartsWith('[') && server.EndsWith(']'))
            server = server[1..^1];
        var parts = new List<string> { protocol, server.ToLowerInvariant(), node.Port.ToString(System.Globalization.CultureInfo.InvariantCulture) };
        switch (protocol)
        {
            case UserNodeProtocol.Shadowsocks:
            {
                var plugin = UserNodeShareLink.NormalizePlugin(node.Plugin);
                parts.Add(node.Method.Trim().ToLowerInvariant());
                parts.Add(node.Password.Trim());
                parts.Add(plugin);
                parts.Add(plugin.Length == 0 ? "" : node.PluginOpts.Trim());
                break;
            }
            case UserNodeProtocol.Socks5:
                parts.Add(node.Username.Trim());
                parts.Add(node.Password.Trim());
                break;
            case UserNodeProtocol.Vless:
            {
                var security = UserNodeShareLink.NormalizeSecurity(node.Security);
                var transport = UserNodeShareLink.NormalizeTransport(node.Transport);
                parts.Add(node.Uuid.Trim().ToLowerInvariant());
                parts.Add(node.Flow.Trim().ToLowerInvariant());
                parts.Add(security);
                parts.Add(security == "none" ? "" : node.Sni.Trim().ToLowerInvariant());
                parts.Add(security == "reality" ? node.PublicKey.Trim() : "");
                parts.Add(security == "reality" ? node.ShortId.Trim().ToLowerInvariant() : "");
                parts.Add(transport);
                if (transport is "ws" or "httpupgrade")
                {
                    var path = node.Path.Trim();
                    parts.Add(path.Length == 0 ? "/" : path);
                    parts.Add(node.Host.Trim().ToLowerInvariant());
                }
                else if (transport == "grpc")
                {
                    parts.Add(node.ServiceName.Trim());
                }
                break;
            }
        }
        return string.Join('\0', parts);
    }

    /// <summary>Drops nodes equal to an already hosted node, so an import never duplicates a cloud node.</summary>
    public static IReadOnlyList<UserNode> WithoutHostedDuplicates(IEnumerable<UserNode> incoming, IEnumerable<UserNode> existing)
    {
        var hostedKeys = existing.Where(node => node.IsHosted)
            .Select(Key)
            .Where(key => key is not null)
            .ToHashSet(StringComparer.Ordinal);
        return incoming.Where(node => Key(node) is not { } key || !hostedKeys.Contains(key)).ToList();
    }
}
