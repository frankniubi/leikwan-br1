using System.Net;
using System.Text.Json;
using System.Text.Json.Nodes;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Ultra;

namespace LeikwanBridge.Mac.Services;

public static class MacConnectionConfigBuilder
{
    public const int DefaultProxyPort = 2080;
    public const int DefaultTunMtu = 1280;
    public const string TunAddress = "172.19.0.1/30";
    public const string TunGateway = "172.19.0.2";
    public const string TunIpv6Address = "fdfe:dcba:9876::1/126";
    public const int NativePortMin = 1000;
    private const string TunIpv6Gateway = "fdfe:dcba:9876::2";

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = false,
    };

    public static MacConnectionConfigResult Build(
        MacConnectionProfile profile,
        MacConnectionMode mode,
        IEnumerable<string> bypassHosts,
        int proxyPort = DefaultProxyPort,
        int tunMtu = DefaultTunMtu,
        int measuredPayloadCeiling = 0)
    {
        ArgumentNullException.ThrowIfNull(profile);
        profile = profile.Clone();
        profile.Protocol = profile.Protocol.Trim().ToLowerInvariant();
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
            ["dns"] = BuildDns(profile, bypassDomains, systemTun: mode == MacConnectionMode.Vpn),
            ["inbounds"] = BuildInbounds(mode, proxyPort, bypassIps, tunMtu, measuredPayloadCeiling),
            ["outbounds"] = outbounds,
            ["route"] = BuildRoute(bypassIps, bypassDomains, mode: mode),
        };
        if (string.Equals(profile.Protocol, MacConnectionProtocol.WireGuard, StringComparison.Ordinal))
            root["endpoints"] = new JsonArray(BuildWireGuardEndpoint(profile));

        return new MacConnectionConfigResult(
            root.ToJsonString(JsonOptions),
            bypassIps,
            bypassDomains,
            proxyPort);
    }

    public static MacConnectionConfigResult BuildNative(
        NativeOutboundLease lease,
        MacConnectionMode mode,
        IEnumerable<string>? bypassHosts,
        int proxyPort = DefaultProxyPort,
        bool bypassChinaMainland = false,
        string? nativeProxyHost = null,
        int? nativeProxyPort = null,
        MacNativeRuleSetPaths? ruleSetPaths = null,
        int tunMtu = DefaultTunMtu,
        int measuredPayloadCeiling = 0)
    {
        ArgumentNullException.ThrowIfNull(lease);
        if (!lease.IsUsable(DateTimeOffset.UtcNow))
            throw new InvalidOperationException("native_lease_invalid");
        if (mode is not (MacConnectionMode.Proxy or MacConnectionMode.Vpn))
            throw new InvalidOperationException("connection_mode_invalid");
        if (proxyPort is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(proxyPort));

        var bypassIps = new List<string>();
        var bypassDomains = new List<string>();
        // The app-private relay still opens the provider connection outside
        // sing-box. Keep that endpoint on the direct path even when the
        // generated SOCKS outbound points at the local relay.
        AddBypass(lease.EndpointHost, bypassIps, bypassDomains);
        foreach (var value in bypassHosts ?? [])
            AddBypass(value, bypassIps, bypassDomains);

        var fragment = JsonNode.Parse(
            NativeOutboundConfigBuilder.Build(
                lease,
                bypassChina: false,
                localPort: proxyPort,
                localProxyHost: nativeProxyHost,
                localProxyPort: nativeProxyPort).Json)?.AsObject()
            ?? throw new InvalidOperationException("native_outbound_config_invalid");
        var nativeOutbound = fragment["outbound"]?.DeepClone()
            ?? throw new InvalidOperationException("native_outbound_config_invalid");
        var bootstrapProfile = new MacConnectionProfile
        {
            Server = lease.EndpointHost,
            Port = lease.EndpointPort,
        };
        var root = new JsonObject
        {
            ["log"] = new JsonObject { ["level"] = "error" },
            ["dns"] = BuildDns(
                bootstrapProfile,
                bypassDomains,
                lease.RouteScope == "cn_only" ? "direct" : "proxy",
                lease.RouteScope,
                lease.RoutePolicy,
                systemTun: mode == MacConnectionMode.Vpn),
            ["inbounds"] = BuildInbounds(mode, proxyPort, bypassIps, tunMtu, measuredPayloadCeiling),
            ["outbounds"] = new JsonArray
            {
                nativeOutbound,
                new JsonObject { ["type"] = "direct", ["tag"] = "direct" },
                new JsonObject { ["type"] = "block", ["tag"] = "block" },
            },
            ["route"] = BuildRoute(
                bypassIps,
                bypassDomains,
                lease.RouteScope,
                bypassChinaMainland,
                mode,
                lease.RoutePolicy),
        };
        AddNativeChinaRuleSets(
            root["route"]!.AsObject(),
            lease.RouteScope,
            bypassChinaMainland,
            lease.RouteScope == "cn_only" ? "direct" : "proxy",
            ruleSetPaths);
        return new MacConnectionConfigResult(
            root.ToJsonString(JsonOptions),
            bypassIps,
            bypassDomains,
            proxyPort);
    }

    private static JsonObject BuildDns(
        MacConnectionProfile profile,
        IReadOnlyList<string> bypassDomains,
        string remoteDetour = "proxy",
        string? nativeRouteScope = null,
        string? nativeRoutePolicy = null,
        bool systemTun = false)
    {
        remoteDetour = string.Equals(remoteDetour, "direct", StringComparison.OrdinalIgnoreCase)
            ? "direct"
            : "proxy";
        var bootstrapDomains = new List<string>();
        if (!IPAddress.TryParse(profile.Server, out _)
            && Uri.CheckHostName(profile.Server) is UriHostNameType.Dns)
            bootstrapDomains.Add(profile.Server.ToLowerInvariant());
        bootstrapDomains.AddRange(bypassDomains);
        var isChinaOnly = string.Equals(nativeRouteScope, "cn_only", StringComparison.OrdinalIgnoreCase);
        var isInternationalOnly = string.Equals(nativeRouteScope, "intl_only", StringComparison.OrdinalIgnoreCase);
        // Restricted native leases reject every port below 1000, including
        // DoH's 443. DNS must follow the same direct low-port policy.
        var restricted = !string.IsNullOrWhiteSpace(nativeRouteScope)
            && NativeOutboundLease.NormalizeRoutePolicy(nativeRoutePolicy) == "restricted";
        var remoteServer = new JsonObject
        {
            ["type"] = "https",
            ["tag"] = "remote",
            ["server"] = restricted && !isChinaOnly ? "223.5.5.5" : "1.1.1.1",
            ["server_port"] = 443,
            ["path"] = "/dns-query",
            ["tls"] = new JsonObject
            {
                ["enabled"] = true,
                ["server_name"] = restricted && !isChinaOnly ? "dns.alidns.com" : "cloudflare-dns.com",
            },
        };
        if (remoteDetour == "proxy" && !restricted)
            remoteServer["detour"] = "proxy";
        var servers = new JsonArray { remoteServer };
        if (isChinaOnly)
        {
            var chinaServer = new JsonObject
            {
                ["type"] = "https",
                ["tag"] = "china",
                ["server"] = "223.5.5.5",
                ["server_port"] = 443,
                ["path"] = "/dns-query",
                ["tls"] = new JsonObject
                {
                    ["enabled"] = true,
                    ["server_name"] = "dns.alidns.com",
                },
            };
            if (!restricted) chinaServer["detour"] = "proxy";
            servers.Add(chinaServer);
        }
        // The system resolver points at our TUN while connected. A "local"
        // transport here would recursively query the same DNS listener.
        servers.Add(systemTun ? new JsonObject
        {
            ["type"] = "udp", ["tag"] = "local", ["server"] = "223.5.5.5", ["server_port"] = 53,
        } : new JsonObject
        {
            ["type"] = "local",
            ["tag"] = "local",
        });
        var dns = new JsonObject
        {
            ["servers"] = servers,
            ["final"] = "remote",
        };
        if (!string.IsNullOrWhiteSpace(nativeRouteScope)) dns["strategy"] = "ipv4_only";
        var dnsRules = new JsonArray();
        if (bootstrapDomains.Count > 0)
        {
            dnsRules.Add(new JsonObject
            {
                ["domain"] = new JsonArray(bootstrapDomains
                    .Distinct(StringComparer.OrdinalIgnoreCase)
                    .Select(item => JsonValue.Create(item))
                    .ToArray()),
                ["server"] = "local",
            });
        }
        if (isChinaOnly)
        {
            dnsRules.Add(new JsonObject
            {
                ["rule_set"] = new JsonArray("geosite-cn"),
                ["server"] = "china",
            });
        }
        else if (isInternationalOnly)
        {
            dnsRules.Add(new JsonObject
            {
                ["rule_set"] = new JsonArray("geosite-cn"),
                ["server"] = "local",
            });
        }
        if (dnsRules.Count > 0)
            dns["rules"] = dnsRules;
        return dns;
    }

    private static JsonArray BuildInbounds(
        MacConnectionMode mode,
        int proxyPort,
        IReadOnlyList<string>? bypassIps,
        int tunMtu,
        int measuredPayloadCeiling = 0)
    {
        if (mode == MacConnectionMode.Vpn)
        {
            var routeExcludes = new List<string>
            {
                "127.0.0.0/8",
                "255.255.255.255/32",
                "::1/128",
                "ff00::/8",
            };
            routeExcludes.AddRange(bypassIps ?? []);
            return new JsonArray
            {
                new JsonObject
                {
                    ["type"] = "tun",
                    ["tag"] = "tun-in",
                    // Let sing-box allocate a free utunN; an app display name
                    // or the bare prefix "utun" is not a valid Darwin name.
                    ["address"] = new JsonArray(TunAddress, TunIpv6Address),
                    // Clamp to the runtime-measured native-UDP payload ceiling instead of
                    // emitting frames the relay association will drop.
                    ["mtu"] = UltraRuntimeMtu.Resolve(tunMtu, measuredPayloadCeiling),
                    ["auto_route"] = true,
                    ["strict_route"] = true,
                    ["stack"] = "mixed",
                    ["endpoint_independent_nat"] = true,
                    ["route_exclude_address"] = new JsonArray(routeExcludes
                        .Distinct(StringComparer.OrdinalIgnoreCase)
                        .Select(item => JsonValue.Create(item))
                        .ToArray()),
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

    private static JsonObject BuildRoute(
        IReadOnlyList<string> bypassIps,
        IReadOnlyList<string> bypassDomains,
        string? nativeRouteScope = null,
        bool bypassChinaMainland = false,
        MacConnectionMode? mode = null,
        string? nativeRoutePolicy = null)
    {
        var rules = new JsonArray { new JsonObject { ["action"] = "sniff" } };
        var nativeMode = !string.IsNullOrWhiteSpace(nativeRouteScope);
        var restrictedNative = nativeMode
            && string.Equals(
                NativeOutboundLease.NormalizeRoutePolicy(nativeRoutePolicy),
                "restricted",
                StringComparison.Ordinal);
        var resolveNativeDomains = nativeMode && (!restrictedNative || nativeRouteScope != "global");
        var nativePortRange = restrictedNative ? $"{NativePortMin}:65535" : "1:65535";
        if (mode == MacConnectionMode.Vpn)
        {
            rules.Add(new JsonObject
            {
                ["inbound"] = new JsonArray("tun-in"),
                ["protocol"] = "dns",
                ["action"] = "hijack-dns",
            });
            rules.Add(new JsonObject
            {
                ["inbound"] = new JsonArray("tun-in"),
                ["network"] = new JsonArray("tcp", "udp"),
                ["ip_cidr"] = new JsonArray($"{TunGateway}/32", $"{TunIpv6Gateway}/128"),
                ["action"] = "reject",
                ["method"] = "drop",
            });
        }
        if (bypassIps.Count > 0)
            rules.Add(new JsonObject
            {
                ["ip_cidr"] = new JsonArray(bypassIps.Select(item => JsonValue.Create(item)).ToArray()),
                ["outbound"] = "direct",
            });
        if (bypassDomains.Count > 0)
            rules.Add(new JsonObject
            {
                ["domain"] = new JsonArray(bypassDomains.Select(item => JsonValue.Create(item)).ToArray()),
                ["outbound"] = "direct",
            });
        if (resolveNativeDomains)
        {
            // Resolve the destination before the SOCKS handoff so the relay
            // cannot perform a second lookup on a different DNS path.
            rules.Add(new JsonObject
            {
                ["action"] = "resolve",
                ["strategy"] = "ipv4_only",
            });
        }
        if (restrictedNative)
            rules.Add(new JsonObject
            {
                ["network"] = new JsonArray("tcp", "udp"),
                ["port_range"] = "1:999",
                ["outbound"] = "direct",
            });
        if (string.Equals(nativeRouteScope, "cn_only", StringComparison.OrdinalIgnoreCase))
            rules.Add(new JsonObject
            {
                ["rule_set"] = new JsonArray("geoip-cn", "geosite-cn"),
                ["network"] = new JsonArray("tcp", "udp"),
                ["port_range"] = nativePortRange,
                ["outbound"] = "proxy",
            });
        else if (string.Equals(nativeRouteScope, "intl_only", StringComparison.OrdinalIgnoreCase))
            rules.Add(new JsonObject
            {
                ["rule_set"] = new JsonArray("geoip-cn", "geosite-cn"),
                ["network"] = new JsonArray("tcp", "udp"),
                ["port_range"] = nativePortRange,
                ["outbound"] = "direct",
            });
        else if (string.Equals(nativeRouteScope, "global", StringComparison.OrdinalIgnoreCase) && bypassChinaMainland)
            rules.Add(new JsonObject
            {
                ["rule_set"] = new JsonArray("geoip-cn", "geosite-cn"),
                ["network"] = new JsonArray("tcp", "udp"),
                ["port_range"] = nativePortRange,
                ["outbound"] = "direct",
            });
        rules.Add(new JsonObject
        {
            ["ip_is_private"] = true,
            ["outbound"] = "direct",
        });
        return new JsonObject
        {
            ["auto_detect_interface"] = true,
            ["default_domain_resolver"] = resolveNativeDomains ? "remote" : "local",
            ["rules"] = rules,
            ["final"] = string.Equals(nativeRouteScope, "cn_only", StringComparison.OrdinalIgnoreCase) ? "direct" : "proxy",
        };
    }

    private static void AddNativeChinaRuleSets(
        JsonObject route,
        string routeScope,
        bool bypassChinaMainland,
        string downloadDetour = "proxy",
        MacNativeRuleSetPaths? ruleSetPaths = null)
    {
        if (!string.Equals(routeScope, "cn_only", StringComparison.OrdinalIgnoreCase)
            && !string.Equals(routeScope, "intl_only", StringComparison.OrdinalIgnoreCase)
            && !(string.Equals(routeScope, "global", StringComparison.OrdinalIgnoreCase) && bypassChinaMainland))
            return;
        if (ruleSetPaths is not null)
        {
            route["rule_set"] = new JsonArray
            {
                new JsonObject
                {
                    ["tag"] = "geoip-cn", ["type"] = "local", ["format"] = "binary",
                    ["path"] = ruleSetPaths.GeoIpPath,
                },
                new JsonObject
                {
                    ["tag"] = "geosite-cn", ["type"] = "local", ["format"] = "binary",
                    ["path"] = ruleSetPaths.GeoSitePath,
                },
            };
            return;
        }
        downloadDetour = string.Equals(downloadDetour, "direct", StringComparison.OrdinalIgnoreCase)
            ? "direct"
            : "proxy";
        route["rule_set"] = new JsonArray
        {
            new JsonObject
            {
                ["tag"] = "geoip-cn",
                ["type"] = "remote",
                ["format"] = "binary",
                ["url"] = "https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/geoip-cn.srs",
                ["download_detour"] = downloadDetour,
            },
            new JsonObject
            {
                ["tag"] = "geosite-cn",
                ["type"] = "remote",
                ["format"] = "binary",
                ["url"] = "https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-cn.srs",
                ["download_detour"] = downloadDetour,
            },
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
            case MacConnectionProtocol.WireGuard when string.IsNullOrWhiteSpace(profile.PrivateKey)
                || string.IsNullOrWhiteSpace(profile.PeerPublicKey)
                || string.IsNullOrWhiteSpace(profile.LocalAddress):
                throw new ArgumentException("WireGuard keys and local address are required.", nameof(profile));
        }
    }
}
