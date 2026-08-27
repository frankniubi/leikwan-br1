using System.Net;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace LeikwanBridge.Mac.Services;

public static class MacConnectionConfigBuilder
{
    public const int DefaultProxyPort = 2080;

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = false,
    };

    public static MacConnectionConfigResult Build(
        MacConnectionProfile profile,
        MacConnectionMode mode,
        IEnumerable<string> bypassHosts,
        int proxyPort = DefaultProxyPort)
    {
        ArgumentNullException.ThrowIfNull(profile);
        profile = profile.Clone();
        profile.Protocol = profile.Protocol.Trim().ToLowerInvariant();
        profile.Transport = profile.Transport.Trim().ToLowerInvariant();
        Validate(profile, proxyPort);
        var bypassIps = new List<string>();
        var bypassDomains = new List<string>();
        foreach (var value in bypassHosts ?? [])
            AddBypass(value, bypassIps, bypassDomains);

        var outbounds = new JsonArray();
        if (string.Equals(profile.Protocol, MacConnectionProtocol.WireGuard, StringComparison.Ordinal))
        {
            outbounds.Add(new JsonObject { ["type"] = "direct", ["tag"] = "direct" });
            outbounds.Add(new JsonObject { ["type"] = "block", ["tag"] = "block" });
        }
        else
        {
            outbounds.Add(BuildOutbound(profile));
            outbounds.Add(new JsonObject { ["type"] = "direct", ["tag"] = "direct" });
            outbounds.Add(new JsonObject { ["type"] = "block", ["tag"] = "block" });
        }

        var root = new JsonObject
        {
            ["log"] = new JsonObject { ["level"] = "error" },
            ["dns"] = BuildDns(profile, bypassDomains),
            ["inbounds"] = BuildInbounds(mode, proxyPort),
            ["outbounds"] = outbounds,
            ["route"] = BuildRoute(bypassIps, bypassDomains),
        };
        if (string.Equals(profile.Protocol, MacConnectionProtocol.WireGuard, StringComparison.Ordinal))
            root["endpoints"] = new JsonArray(BuildWireGuardEndpoint(profile));

        return new MacConnectionConfigResult(
            root.ToJsonString(JsonOptions),
            bypassIps,
            bypassDomains,
            proxyPort);
    }

    private static JsonObject BuildDns(MacConnectionProfile profile, IReadOnlyList<string> bypassDomains)
    {
        var bootstrapDomains = new List<string>();
        if (!IPAddress.TryParse(profile.Server, out _)
            && Uri.CheckHostName(profile.Server) is UriHostNameType.Dns)
            bootstrapDomains.Add(profile.Server.ToLowerInvariant());
        bootstrapDomains.AddRange(bypassDomains);
        var dns = new JsonObject
        {
            ["servers"] = new JsonArray
            {
                new JsonObject
                {
                    ["tag"] = "remote",
                    ["address"] = "https://1.1.1.1/dns-query",
                    ["detour"] = "proxy",
                },
                new JsonObject
                {
                    ["tag"] = "local",
                    ["address"] = "local",
                },
            },
            ["final"] = "remote",
            ["independent_cache"] = true,
        };
        if (bootstrapDomains.Count > 0)
        {
            dns["rules"] = new JsonArray
            {
                new JsonObject
                {
                    ["domain"] = new JsonArray(bootstrapDomains
                        .Distinct(StringComparer.OrdinalIgnoreCase)
                        .Select(item => JsonValue.Create(item))
                        .ToArray()),
                    ["server"] = "local",
                },
            };
        }
        return dns;
    }

    private static JsonArray BuildInbounds(MacConnectionMode mode, int proxyPort)
    {
        if (mode == MacConnectionMode.Vpn)
        {
            return new JsonArray
            {
                new JsonObject
                {
                    ["type"] = "tun",
                    ["tag"] = "tun-in",
                    ["interface_name"] = "LeiKwan Bridge",
                    ["address"] = new JsonArray("172.19.0.1/30"),
                    ["auto_route"] = true,
                    ["strict_route"] = true,
                    ["stack"] = "mixed",
                },
            };
        }

        return new JsonArray
        {
            new JsonObject
            {
                ["type"] = "mixed",
                ["tag"] = "mixed-in",
                ["listen"] = "127.0.0.1",
                ["listen_port"] = proxyPort,
                ["set_system_proxy"] = true,
            },
        };
    }

    private static JsonObject BuildOutbound(MacConnectionProfile profile)
    {
        return profile.Protocol switch
        {
            MacConnectionProtocol.Shadowsocks => BuildShadowsocks(profile),
            MacConnectionProtocol.Vless => BuildVless(profile),
            MacConnectionProtocol.WireGuard => throw new InvalidOperationException("wireguard_uses_endpoint"),
            _ => throw new ArgumentException("Unsupported connection protocol.", nameof(profile)),
        };
    }

    private static JsonObject BuildWireGuardEndpoint(MacConnectionProfile profile)
    {
        var peer = new JsonObject
        {
            ["address"] = profile.Server,
            ["port"] = profile.Port,
            ["public_key"] = profile.PeerPublicKey,
            ["allowed_ips"] = SplitCsv(profile.AllowedIps),
        };
        if (!string.IsNullOrWhiteSpace(profile.PreSharedKey))
            peer["pre_shared_key"] = profile.PreSharedKey;
        if (profile.PersistentKeepalive > 0)
            peer["persistent_keepalive_interval"] = profile.PersistentKeepalive;
        return new JsonObject
        {
            ["type"] = "wireguard",
            ["tag"] = "proxy",
            ["system"] = false,
            ["name"] = "LeiKwan Bridge",
            ["mtu"] = profile.Mtu,
            ["address"] = SplitCsv(profile.LocalAddress),
            ["private_key"] = profile.PrivateKey,
            ["peers"] = new JsonArray(peer),
        };
    }

    private static JsonObject BuildShadowsocks(MacConnectionProfile profile)
    {
        var outbound = new JsonObject
        {
            ["type"] = "shadowsocks",
            ["tag"] = "proxy",
            ["server"] = profile.Server,
            ["server_port"] = profile.Port,
            ["method"] = profile.Method,
            ["password"] = profile.Password,
        };
        return outbound;
    }

    private static JsonObject BuildVless(MacConnectionProfile profile)
    {
        var outbound = new JsonObject
        {
            ["type"] = "vless",
            ["tag"] = "proxy",
            ["server"] = profile.Server,
            ["server_port"] = profile.Port,
            ["uuid"] = profile.Uuid,
        };
        if (!string.IsNullOrWhiteSpace(profile.Flow))
            outbound["flow"] = profile.Flow;
        if (!string.Equals(profile.Transport, "tcp", StringComparison.OrdinalIgnoreCase))
        {
            var transport = new JsonObject { ["type"] = profile.Transport };
            if (string.Equals(profile.Transport, "ws", StringComparison.OrdinalIgnoreCase))
            {
                transport["path"] = string.IsNullOrWhiteSpace(profile.Path) ? "/" : profile.Path;
                if (!string.IsNullOrWhiteSpace(profile.Host))
                    transport["headers"] = new JsonObject { ["Host"] = profile.Host };
            }
            else if (string.Equals(profile.Transport, "grpc", StringComparison.OrdinalIgnoreCase))
            {
                transport["service_name"] = profile.ServiceName;
            }
            outbound["transport"] = transport;
        }
        if (profile.Tls || !string.IsNullOrWhiteSpace(profile.RealityPublicKey))
        {
            var tls = new JsonObject { ["enabled"] = true };
            if (!string.IsNullOrWhiteSpace(profile.ServerName))
                tls["server_name"] = profile.ServerName;
            if (!string.IsNullOrWhiteSpace(profile.RealityPublicKey))
            {
                tls["reality"] = new JsonObject
                {
                    ["enabled"] = true,
                    ["public_key"] = profile.RealityPublicKey,
                    ["short_id"] = profile.RealityShortId,
                };
            }
            outbound["tls"] = tls;
        }
        return outbound;
    }

    private static JsonObject BuildRoute(IReadOnlyList<string> bypassIps, IReadOnlyList<string> bypassDomains)
    {
        var rules = new JsonArray
        {
            new JsonObject
            {
                ["action"] = "sniff",
            },
            new JsonObject
            {
                ["ip_cidr"] = new JsonArray(bypassIps.Select(item => JsonValue.Create(item)).ToArray()),
                ["outbound"] = "direct",
            },
            new JsonObject
            {
                ["domain"] = new JsonArray(bypassDomains.Select(item => JsonValue.Create(item)).ToArray()),
                ["outbound"] = "direct",
            },
            new JsonObject
            {
                ["ip_is_private"] = true,
                ["outbound"] = "direct",
            },
        };
        if (bypassIps.Count == 0)
            rules.RemoveAt(0);
        if (bypassDomains.Count == 0)
            rules.RemoveAt(bypassIps.Count == 0 ? 0 : 1);
        return new JsonObject
        {
            ["auto_detect_interface"] = true,
            ["rules"] = rules,
            ["final"] = "proxy",
        };
    }

    private static void AddBypass(string value, ICollection<string> ips, ICollection<string> domains)
    {
        var normalized = value?.Trim().TrimEnd('.') ?? string.Empty;
        if (normalized.Length == 0)
            return;
        if (IPAddress.TryParse(normalized, out var address))
        {
            ips.Add(address.AddressFamily == System.Net.Sockets.AddressFamily.InterNetwork
                ? normalized + "/32"
                : normalized + "/128");
            return;
        }
        if (normalized.Contains('/'))
        {
            if (IPAddress.TryParse(normalized.Split('/')[0], out _))
                ips.Add(normalized);
            return;
        }
        if (Uri.CheckHostName(normalized) is UriHostNameType.Dns)
            domains.Add(normalized.ToLowerInvariant());
    }

    private static JsonArray SplitCsv(string value)
        => new(value.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Where(item => item.Length <= 64)
            .Select(item => JsonValue.Create(item))
            .ToArray());

    private static void Validate(MacConnectionProfile profile, int proxyPort)
    {
        if (!MacConnectionProtocol.IsSupported(profile.Protocol))
            throw new ArgumentException("Unsupported connection protocol.", nameof(profile));
        if (string.IsNullOrWhiteSpace(profile.Server))
            throw new ArgumentException("Connection server is required.", nameof(profile));
        if (profile.Port is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(profile.Port));
        if (proxyPort is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(proxyPort));
        switch (profile.Protocol)
        {
            case MacConnectionProtocol.Shadowsocks when string.IsNullOrWhiteSpace(profile.Method)
                || string.IsNullOrWhiteSpace(profile.Password):
                throw new ArgumentException("Shadowsocks method and password are required.", nameof(profile));
            case MacConnectionProtocol.Vless when string.IsNullOrWhiteSpace(profile.Uuid):
                throw new ArgumentException("VLESS UUID is required.", nameof(profile));
            case MacConnectionProtocol.WireGuard when string.IsNullOrWhiteSpace(profile.PrivateKey)
                || string.IsNullOrWhiteSpace(profile.PeerPublicKey)
                || string.IsNullOrWhiteSpace(profile.LocalAddress):
                throw new ArgumentException("WireGuard keys and local address are required.", nameof(profile));
        }
    }
}
