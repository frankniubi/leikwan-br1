using System.Net;
using System.Text.Json;
using System.Text.Json.Nodes;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Ultra;

namespace LeikwanBridge.Core.Services;

public static class WindowsProxyConfigBuilder
{
    public const int DefaultProxyPort = 2080;
    public const int DefaultTunMtu = 1280;
    public const string TunInterfaceName = "LeiKwan Bridge";
    public const string TunAddress = "172.19.0.1/30";
    public const string TunGateway = "172.19.0.2";
    public const string TunIpv6Address = "fdfe:dcba:9876::1/126";
    private const string TunIpv6Gateway = "fdfe:dcba:9876::2";
    private const string NativeRuleSetDownloadHost = "raw.githubusercontent.com";

    public static string NormalizeTunStack(string? value)
    {
        var normalized = value?.Trim().ToLowerInvariant();
        return normalized is "system" or "gvisor" or "mixed" ? normalized : "gvisor";
    }

    public static WindowsProxyConfigResult Build(
        WindowsProxyProfile profile,
        WindowsProxyMode mode,
        IEnumerable<string>? bypassHosts,
        int proxyPort = DefaultProxyPort,
        int tunMtu = DefaultTunMtu,
        string tunStack = "gvisor",
        bool bypassChinaMainland = false,
        string? chinaIpListPath = null,
        WindowsProxyDnsMode dnsMode = WindowsProxyDnsMode.Remote,
        IEnumerable<string>? customDnsServers = null,
        int measuredPayloadCeiling = 0)
    {
        ArgumentNullException.ThrowIfNull(profile);
        if (profile.IsExternal)
            throw new InvalidOperationException("proxy_external_requires_forward");
        tunStack = NormalizeTunStack(tunStack);
        var copy = profile.Clone();
        copy.Protocol = WindowsProxyProtocol.Normalize(copy.Protocol);
        copy.Transport = copy.Transport.Trim().ToLowerInvariant();
        Validate(copy, proxyPort, tunMtu, tunStack);
        var chinaIpCidrs = bypassChinaMainland
            ? LoadChinaIpCidrs(chinaIpListPath)
            : [];

        var bypassIps = new List<string>();
        var bypassDomains = new List<string>();
        AddHost(copy.Server, bypassIps, bypassDomains);
        foreach (var host in bypassHosts ?? [])
            AddHost(host, bypassIps, bypassDomains);

        var root = new JsonObject
        {
            ["log"] = new JsonObject { ["level"] = "error" },
            ["dns"] = BuildDns(copy, bypassDomains, dnsMode, customDnsServers),
            ["inbounds"] = BuildInbounds(mode, proxyPort, tunMtu, tunStack, bypassIps, measuredPayloadCeiling),
            ["outbounds"] = new JsonArray
            {
                BuildOutbound(copy),
                new JsonObject { ["type"] = "direct", ["tag"] = "direct" },
                new JsonObject { ["type"] = "block", ["tag"] = "block" },
            },
            ["route"] = BuildRoute(mode, bypassIps, bypassDomains, chinaIpCidrs),
        };

        return new WindowsProxyConfigResult(
            root.ToJsonString(),
            bypassIps,
            bypassDomains,
            proxyPort,
            TunGateway);
    }

    public static WindowsProxyConfigResult BuildNative(
        NativeOutboundLease lease,
        WindowsProxyMode mode,
        IEnumerable<string>? bypassHosts,
        int proxyPort = DefaultProxyPort,
        int tunMtu = DefaultTunMtu,
        string tunStack = "gvisor",
        bool bypassChinaMainland = false,
        string? chinaIpListPath = null,
        WindowsProxyDnsMode dnsMode = WindowsProxyDnsMode.Remote,
        IEnumerable<string>? customDnsServers = null,
        string? nativeProxyHost = null,
        int? nativeProxyPort = null,
        string? nativeRoutePolicy = null,
        int measuredPayloadCeiling = 0)
    {
        ArgumentNullException.ThrowIfNull(lease);
        if (!lease.IsUsable(DateTimeOffset.UtcNow))
            throw new InvalidOperationException("native_lease_invalid");
        if (mode is not (WindowsProxyMode.Http or WindowsProxyMode.Tun or WindowsProxyMode.Process))
            throw new InvalidOperationException("proxy_mode_invalid");

        nativeRoutePolicy = NormalizeNativeRoutePolicy(nativeRoutePolicy ?? lease.RoutePolicy);
        if (!string.Equals(nativeRoutePolicy, lease.RoutePolicy, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("native_route_policy_mismatch");

        tunStack = NormalizeTunStack(tunStack);
        var chinaIpCidrs = lease.RouteScope == "global" && bypassChinaMainland
            ? LoadChinaIpCidrs(chinaIpListPath)
            : [];
        var bypassIps = new List<string>();
        var bypassDomains = new List<string>();
        // The app-private relay still opens the provider connection outside
        // sing-box. Keep that endpoint on the direct path even when the
        // generated SOCKS outbound points at the local relay.
        AddHost(lease.EndpointHost, bypassIps, bypassDomains);
        foreach (var host in bypassHosts ?? [])
            AddHost(host, bypassIps, bypassDomains);
        if (IsNativeRestrictedScope(lease.RouteScope))
            AddHost(NativeRuleSetDownloadHost, bypassIps, bypassDomains);

        using var fragment = JsonDocument.Parse(
            NativeOutboundConfigBuilder.Build(
                lease,
                bypassChina: false,
                localPort: proxyPort,
                localProxyHost: nativeProxyHost,
                localProxyPort: nativeProxyPort).Json);
        var nativeOutbound = JsonNode.Parse(
            fragment.RootElement.GetProperty("outbound").GetRawText())?.AsObject()
            ?? throw new InvalidOperationException("native_outbound_config_invalid");
        var bootstrapProfile = new WindowsProxyProfile
        {
            Server = lease.EndpointHost,
            Port = lease.EndpointPort,
        };
        var root = new JsonObject
        {
            ["log"] = new JsonObject { ["level"] = "error" },
            // Restricted native scopes must not carry bootstrap DNS through the
            // relay. Keep metadata and rule-set bootstrap on the normal path.
            ["dns"] = BuildDns(
                bootstrapProfile,
                bypassDomains,
                dnsMode,
                customDnsServers,
                lease.RouteScope == "cn_only" ? "direct" : "proxy",
                lease.RouteScope),
            ["inbounds"] = BuildInbounds(mode, proxyPort, tunMtu, tunStack, bypassIps, measuredPayloadCeiling),
            ["outbounds"] = new JsonArray
            {
                nativeOutbound,
                new JsonObject { ["type"] = "direct", ["tag"] = "direct" },
                new JsonObject { ["type"] = "block", ["tag"] = "block" },
            },
            ["route"] = BuildRoute(
                mode,
                bypassIps,
                bypassDomains,
                chinaIpCidrs,
                lease.RouteScope,
                nativeRoutePolicy,
                dnsMode == WindowsProxyDnsMode.Custom && lease.RouteScope == "intl_only"
                    ? "custom-0"
                    : null),
        };
        AddNativeChinaRuleSets(
            root["route"]!.AsObject(),
            lease.RouteScope,
            chinaIpCidrs.Count > 0,
            lease.RouteScope is "cn_only" or "intl_only" ? "direct" : "proxy");

        return new WindowsProxyConfigResult(
            root.ToJsonString(),
            bypassIps,
            bypassDomains,
            proxyPort,
            TunGateway);
    }

    private static JsonObject BuildDns(
        WindowsProxyProfile profile,
        IReadOnlyList<string> bypassDomains,
        WindowsProxyDnsMode dnsMode,
        IEnumerable<string>? customDnsServers,
        string remoteDetour = "proxy",
        string? nativeRouteScope = null)
    {
        remoteDetour = string.Equals(remoteDetour, "direct", StringComparison.OrdinalIgnoreCase)
            ? "direct"
            : "proxy";
        var bootstrap = new List<string>();
        if (!IPAddress.TryParse(profile.Server, out _)
            && Uri.CheckHostName(profile.Server) is UriHostNameType.Dns)
            bootstrap.Add(profile.Server.ToLowerInvariant());
        bootstrap.AddRange(bypassDomains);

        var servers = new JsonArray();
        var finalTag = "remote";
        var isChinaOnly = string.Equals(
            nativeRouteScope,
            "cn_only",
            StringComparison.OrdinalIgnoreCase);
        // cn_only needs two independent resolvers: normal international names
        // stay on the direct path, while Chinese names use mainland DoH over
        // the native relay. This also keeps a custom/local resolver from
        // reintroducing polluted answers for the protected Chinese path.
        if (isChinaOnly)
        {
            servers.Add(new JsonObject
            {
                ["type"] = "https",
                ["tag"] = "remote",
                ["server"] = "1.1.1.1",
                ["server_port"] = 443,
                ["path"] = "/dns-query",
                ["tls"] = new JsonObject
                {
                    ["enabled"] = true,
                    ["server_name"] = "cloudflare-dns.com",
                },
            });
            servers.Add(new JsonObject
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
                ["detour"] = "proxy",
            });
        }
        else if (dnsMode == WindowsProxyDnsMode.Custom && remoteDetour != "direct")
        {
            var custom = WindowsProxyDns.NormalizeServers(customDnsServers);
            if (custom.Count == 0)
                throw new InvalidOperationException("proxy_dns_server_required");
            for (var index = 0; index < custom.Count; index++)
            {
                var tag = $"custom-{index}";
                var customServer = new JsonObject
                {
                    // Native SOCKS5 carries DNS as real UDP. TCP DNS through
                    // the provider can be accepted at the SOCKS layer while
                    // the upstream resolver closes the stream before replying.
                    ["type"] = IsNativeRestrictedScope(nativeRouteScope) ? "udp" : "tcp",
                    ["tag"] = tag,
                    ["server"] = custom[index],
                    ["server_port"] = 53,
                };
                // sing-box treats a direct DNS detour as an empty outbound;
                // omitting the field is the supported default direct path.
                if (remoteDetour == "proxy")
                    customServer["detour"] = "proxy";
                servers.Add(customServer);
            }
            finalTag = "custom-0";
        }
        else
        {
            var remoteServer = new JsonObject
            {
                ["type"] = "https",
                ["tag"] = "remote",
                ["server"] = "1.1.1.1",
                ["server_port"] = 443,
                ["path"] = "/dns-query",
                ["tls"] = new JsonObject
                {
                    ["enabled"] = true,
                    ["server_name"] = "cloudflare-dns.com",
                },
            };
            if (remoteDetour == "proxy")
                remoteServer["detour"] = "proxy";
            servers.Add(remoteServer);
        }
        servers.Add(new JsonObject { ["type"] = "local", ["tag"] = "local" });
        var dns = new JsonObject
        {
            ["servers"] = servers,
            ["final"] = finalTag,
        };
        if (IsNativeRestrictedScope(nativeRouteScope))
            dns["strategy"] = "ipv4_only";
        var dnsRules = new JsonArray();
        var domains = bootstrap.Distinct(StringComparer.OrdinalIgnoreCase).ToArray();
        if (domains.Length > 0)
        {
            var values = new JsonArray();
            foreach (var domain in domains)
                values.Add(domain);
            dnsRules.Add(
                new JsonObject { ["domain"] = values, ["server"] = "local" });
        }
        if (string.Equals(nativeRouteScope, "intl_only", StringComparison.OrdinalIgnoreCase))
        {
            dnsRules.Add(
                new JsonObject
                {
                    ["rule_set"] = new JsonArray("geosite-cn"),
                    ["server"] = "local",
                });
        }
        else if (isChinaOnly)
        {
            dnsRules.Add(
                new JsonObject
                {
                    ["rule_set"] = new JsonArray("geosite-cn"),
                    ["server"] = "china",
                });
        }
        if (dnsRules.Count > 0)
        {
            dns["rules"] = dnsRules;
        }
        return dns;
    }

    private static JsonArray BuildInbounds(
        WindowsProxyMode mode,
        int proxyPort,
        int tunMtu,
        string tunStack,
        IReadOnlyList<string> bypassIps,
        int measuredPayloadCeiling = 0)
    {
        if (mode != WindowsProxyMode.Tun)
        {
            return new JsonArray
            {
                new JsonObject
                {
                    ["type"] = "mixed",
                    ["tag"] = "mixed-in",
                    ["listen"] = "127.0.0.1",
                    ["listen_port"] = proxyPort,
                },
            };
        }

        var tun = new JsonObject
        {
            ["type"] = "tun",
            ["tag"] = "tun-in",
            ["interface_name"] = TunInterfaceName,
            ["address"] = new JsonArray(TunAddress),
            // The runtime measures the native-UDP payload ceiling per association; an
            // MTU above it would only produce frames the path silently drops.
            ["mtu"] = UltraRuntimeMtu.Resolve(tunMtu, measuredPayloadCeiling),
            ["auto_route"] = true,
            ["strict_route"] = true,
            ["stack"] = tunStack,
        };
        // The mixed stack uses gVisor for UDP.  Endpoint-independent NAT is
        // important for SS2022/QUIC flows that change their peer tuple while
        // a TUN socket remains open; it is unavailable/needless on system.
        if (tunStack is "mixed" or "gvisor")
            tun["endpoint_independent_nat"] = true;

        // Keep local loopback and broadcast traffic outside the default route.
        // The gateway itself is deliberately *not* excluded: the route rule
        // below rejects packets addressed to it before the private-direct rule,
        // preventing a strict-route UDP loop.
        var routeExcludes = new List<string>
        {
            "127.0.0.0/8",
            "255.255.255.255/32",
        };
        routeExcludes.AddRange(bypassIps);
        if (routeExcludes.Count > 0)
        {
            var excludes = new JsonArray();
            foreach (var ip in routeExcludes.Distinct(StringComparer.OrdinalIgnoreCase))
                excludes.Add(ip);
            tun["route_exclude_address"] = excludes;
        }
        return new JsonArray(tun);
    }

    private static JsonObject BuildOutbound(WindowsProxyProfile profile)
        => profile.IsShadowsocks ? BuildShadowsocks(profile) : BuildVless(profile);

    private static JsonObject BuildShadowsocks(WindowsProxyProfile profile)
    {
        var outbound = new JsonObject
        {
            ["type"] = "shadowsocks",
            ["tag"] = "proxy",
            ["server"] = profile.Server,
            ["server_port"] = profile.Port,
            ["method"] = profile.Method,
            ["password"] = profile.Password,
            ["domain_resolver"] = "local",
        };
        if (profile.IsShadowsocks2022)
        {
            // Pin the SS2022 UDP-over-TCP dialect even when native UDP is
            // selected.  Leaving this object out lets sing-box inherit a
            // transport default that can drop UDP packets from a TUN.
            outbound["udp_over_tcp"] = new JsonObject
            {
                ["enabled"] = profile.UdpOverTcp,
                ["version"] = 2,
            };
        }
        else if (profile.UdpOverTcp)
        {
            // sing-box 1.10+ uses an object for the opt-in SS transport.
            outbound["udp_over_tcp"] = new JsonObject { ["enabled"] = true };
        }
        return outbound;
    }

    private static JsonObject BuildVless(WindowsProxyProfile profile)
    {
        var outbound = new JsonObject
        {
            ["type"] = "vless",
            ["tag"] = "proxy",
            ["server"] = profile.Server,
            ["server_port"] = profile.Port,
            ["uuid"] = profile.Uuid,
            ["domain_resolver"] = "local",
        };
        if (!string.IsNullOrWhiteSpace(profile.Flow))
            outbound["flow"] = profile.Flow;

        if (profile.Transport != "tcp")
        {
            var transport = new JsonObject { ["type"] = profile.Transport };
            if (profile.Transport == "ws")
            {
                transport["path"] = string.IsNullOrWhiteSpace(profile.Path) ? "/" : profile.Path;
                if (!string.IsNullOrWhiteSpace(profile.Host))
                    transport["headers"] = new JsonObject { ["Host"] = profile.Host };
            }
            else if (profile.Transport == "grpc" && !string.IsNullOrWhiteSpace(profile.ServiceName))
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
            if (!string.IsNullOrWhiteSpace(profile.Fingerprint))
                tls["utls"] = new JsonObject { ["enabled"] = true, ["fingerprint"] = profile.Fingerprint };
            if (profile.AllowInsecure)
                tls["insecure"] = true;
            if (!string.IsNullOrWhiteSpace(profile.Alpn))
            {
                var alpn = new JsonArray();
                foreach (var value in profile.Alpn.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
                    alpn.Add(value);
                if (alpn.Count > 0)
                    tls["alpn"] = alpn;
            }
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

    private static JsonObject BuildRoute(
        WindowsProxyMode mode,
        IReadOnlyList<string> bypassIps,
        IReadOnlyList<string> bypassDomains,
        IReadOnlyList<string> chinaIpCidrs,
        string? nativeRouteScope = null,
        string? nativeRoutePolicy = null,
        string? defaultDomainResolver = null)
    {
        var rules = new JsonArray
        {
            new JsonObject { ["action"] = "sniff" },
        };

        if (mode == WindowsProxyMode.Tun)
        {
            rules.Add(new JsonObject
            {
                ["inbound"] = new JsonArray("tun-in"),
                ["protocol"] = "dns",
                ["action"] = "hijack-dns",
            });

            // Windows strict-route can feed discovery/DNS packets addressed to
            // the TUN peer back into the tunnel.  Reject before the private-IP
            // direct rule so SS2022 UDP does not disappear in a loop.
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
        {
            var values = new JsonArray();
            foreach (var ip in bypassIps.Distinct(StringComparer.OrdinalIgnoreCase))
                values.Add(ip);
            rules.Add(new JsonObject { ["ip_cidr"] = values, ["outbound"] = "direct" });
        }
        if (bypassDomains.Count > 0)
        {
            var values = new JsonArray();
            foreach (var domain in bypassDomains.Distinct(StringComparer.OrdinalIgnoreCase))
                values.Add(domain);
            rules.Add(new JsonObject { ["domain"] = values, ["outbound"] = "direct" });
        }

        if (IsNativeRestrictedPolicy(nativeRoutePolicy))
        {
            // Native relay is a game-oriented path. Keep privileged and
            // control-plane ports local; only the high-port range enters the
            // native SOCKS relay. The rule is shared by HTTP, Process and TUN.
            rules.Add(new JsonObject
            {
                ["network"] = new JsonArray("tcp", "udp"),
                ["port_range"] = "1:999",
                ["outbound"] = "direct",
            });
        }

        if (IsNativeRestrictedScope(nativeRouteScope))
        {
            // Resolve before handing the destination to SOCKS. Otherwise its
            // upstream can perform a second lookup and replace the protected
            // split-DNS result with a polluted or region-wrong answer.
            rules.Add(new JsonObject
            {
                ["action"] = "resolve",
                ["strategy"] = "ipv4_only",
            });
        }

        if (nativeRouteScope == "cn_only")
        {
            rules.Add(new JsonObject
            {
                ["rule_set"] = new JsonArray("geoip-cn", "geosite-cn"),
                ["network"] = new JsonArray("tcp", "udp"),
                ["port_range"] = IsNativeRestrictedPolicy(nativeRoutePolicy) ? "1000:65535" : "1:65535",
                ["outbound"] = "proxy",
            });
        }
        else if (nativeRouteScope == "intl_only")
        {
            rules.Add(new JsonObject
            {
                ["rule_set"] = new JsonArray("geoip-cn", "geosite-cn"),
                ["network"] = new JsonArray("tcp", "udp"),
                ["port_range"] = IsNativeRestrictedPolicy(nativeRoutePolicy) ? "1000:65535" : "1:65535",
                ["outbound"] = "direct",
            });
        }
        else if (chinaIpCidrs.Count > 0)
        {
            var values = new JsonArray();
            foreach (var cidr in chinaIpCidrs)
                values.Add(cidr);
            rules.Add(new JsonObject { ["ip_cidr"] = values, ["outbound"] = "direct" });
        }

        rules.Add(new JsonObject { ["ip_is_private"] = true, ["outbound"] = "direct" });
        return new JsonObject
        {
            ["auto_detect_interface"] = true,
            ["default_domain_resolver"] = IsNativeRestrictedScope(nativeRouteScope)
                ? defaultDomainResolver ?? "remote"
                : "local",
            ["rules"] = rules,
            ["final"] = nativeRouteScope == "cn_only" ? "direct" : "proxy",
        };
    }

    private static void AddNativeChinaRuleSets(
        JsonObject route,
        string routeScope,
        bool includeChinaBypass,
        string downloadDetour = "proxy")
    {
        if (!IsNativeRestrictedScope(routeScope) && !includeChinaBypass)
            return;
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
                ["url"] = $"https://{NativeRuleSetDownloadHost}/SagerNet/sing-geoip/rule-set/geoip-cn.srs",
                ["download_detour"] = downloadDetour,
            },
            new JsonObject
            {
                ["tag"] = "geosite-cn",
                ["type"] = "remote",
                ["format"] = "binary",
                ["url"] = $"https://{NativeRuleSetDownloadHost}/SagerNet/sing-geosite/rule-set/geosite-cn.srs",
                ["download_detour"] = downloadDetour,
            },
        };
    }

    private static bool IsNativeRestrictedScope(string? routeScope)
        => string.Equals(routeScope, "cn_only", StringComparison.OrdinalIgnoreCase)
            || string.Equals(routeScope, "intl_only", StringComparison.OrdinalIgnoreCase);

    public static string NormalizeNativeRoutePolicy(string? value)
        => string.Equals(value?.Trim(), "unrestricted", StringComparison.OrdinalIgnoreCase)
            ? "unrestricted"
            : "restricted";

    private static bool IsNativeRestrictedPolicy(string? value)
        => value is not null
            && !string.Equals(NormalizeNativeRoutePolicy(value), "unrestricted", StringComparison.OrdinalIgnoreCase);

    private static IReadOnlyList<string> LoadChinaIpCidrs(string? path)
    {
        if (string.IsNullOrWhiteSpace(path) || !File.Exists(path))
            throw new InvalidOperationException("proxy_china_ip_list_missing");

        try
        {
            var info = new FileInfo(path);
            if (info.Length == 0 || info.Length > 4 * 1024 * 1024)
                throw new InvalidOperationException("proxy_china_ip_list_invalid");

            var result = new List<string>(8192);
            var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            foreach (var sourceLine in File.ReadLines(path))
            {
                var line = sourceLine.Trim();
                if (line.Length == 0 || line[0] == '#')
                    continue;
                var comment = line.IndexOf('#');
                if (comment >= 0)
                    line = line[..comment].Trim();
                var parts = line.Split(' ', StringSplitOptions.RemoveEmptyEntries);
                if (parts.Length < 2 || !string.Equals(parts[0], "route", StringComparison.OrdinalIgnoreCase))
                    continue;
                var cidr = parts[1].Trim();
                if (TryValidateCidr(cidr) && seen.Add(cidr))
                    result.Add(cidr);
            }

            if (result.Count == 0)
                throw new InvalidOperationException("proxy_china_ip_list_invalid");
            return result;
        }
        catch (InvalidOperationException)
        {
            throw;
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            throw new InvalidOperationException("proxy_china_ip_list_unreadable", exception);
        }
    }

    private static bool TryValidateCidr(string value)
    {
        var slash = value.LastIndexOf('/');
        if (slash <= 0 || slash == value.Length - 1
            || !IPAddress.TryParse(value[..slash], out var address)
            || !int.TryParse(value[(slash + 1)..], out var prefix))
            return false;
        var maximum = address.AddressFamily == System.Net.Sockets.AddressFamily.InterNetwork ? 32 : 128;
        return prefix >= 0 && prefix <= maximum;
    }

    private static void AddHost(string? value, ICollection<string> ips, ICollection<string> domains)
    {
        var normalized = value?.Trim().TrimEnd('.') ?? string.Empty;
        if (normalized.Length == 0)
            return;
        if (IPAddress.TryParse(normalized, out var address))
        {
            ips.Add(address.AddressFamily == System.Net.Sockets.AddressFamily.InterNetwork
                ? $"{normalized}/32"
                : $"{normalized}/128");
            return;
        }
        if (normalized.Contains('/'))
        {
            var slash = normalized.IndexOf('/');
            if (IPAddress.TryParse(normalized[..slash], out _))
                ips.Add(normalized);
            return;
        }
        if (Uri.CheckHostName(normalized) is UriHostNameType.Dns)
            domains.Add(normalized.ToLowerInvariant());
    }

    private static void Validate(WindowsProxyProfile profile, int proxyPort, int tunMtu, string tunStack)
    {
        if (!WindowsProxyProtocol.IsSupported(profile.Protocol))
            throw new ArgumentException("Unsupported proxy protocol.", nameof(profile));
        if (string.IsNullOrWhiteSpace(profile.Server))
            throw new ArgumentException("Proxy server is required.", nameof(profile));
        if (profile.Port is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(profile.Port));
        if (proxyPort is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(proxyPort));
        if (tunMtu is < 576 or > 9000)
            throw new ArgumentOutOfRangeException(nameof(tunMtu));
        if (profile.IsShadowsocks)
        {
            if (!WindowsProxyProtocol.IsSupportedShadowsocksMethod(profile.Method))
                throw new ArgumentException("Unsupported Shadowsocks method.", nameof(profile.Method));
            if (profile.IsShadowsocks2022 && !WindowsProxyProtocol.IsShadowsocks2022Method(profile.Method))
                throw new ArgumentException("SS2022 requires a 2022 method.", nameof(profile.Method));
            if (!profile.IsShadowsocks2022 && WindowsProxyProtocol.IsShadowsocks2022Method(profile.Method))
                throw new ArgumentException("SS2022 method requires the SS2022 protocol option.", nameof(profile.Method));
            if (string.IsNullOrWhiteSpace(profile.Password)
                && !string.Equals(profile.Method.Trim(), "none", StringComparison.OrdinalIgnoreCase))
                throw new ArgumentException("Shadowsocks password is required.", nameof(profile.Password));
        }
        else
        {
            if (!Guid.TryParse(profile.Uuid, out _))
                throw new ArgumentException("VLESS UUID is invalid.", nameof(profile.Uuid));
            if (!WindowsProxyProtocol.VlessTransportOptions.Contains(profile.Transport, StringComparer.Ordinal))
                throw new ArgumentException("Unsupported VLESS transport.", nameof(profile.Transport));
        }
    }
}
