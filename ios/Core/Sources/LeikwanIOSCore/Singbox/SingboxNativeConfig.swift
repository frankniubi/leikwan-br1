import Foundation

/// What the native-mode sing-box config needs (Android `NativeOutboundLocalProxy` + scope/policy).
public struct NativeSingboxOptions: Equatable {
    /// The runtime's SOCKS5 facade on 127.0.0.1.
    public var localProxyPort: Int
    public var routeScope: NativeRouteScope
    public var routePolicy: NativeRoutePolicy
    /// Bypass mainland China; only honoured for `.global`.
    public var bypassChina: Bool

    public init(localProxyPort: Int, routeScope: NativeRouteScope, routePolicy: NativeRoutePolicy, bypassChina: Bool = false) {
        self.localProxyPort = localProxyPort
        self.routeScope = routeScope
        self.routePolicy = routePolicy
        self.bypassChina = bypassChina
    }
}

/// Native outbound branch of Android `SingboxConfigBuilder`, with the DNS / resolve rules the
/// desktop clients use today (macOS 16dad261, Windows 03a3fc1f):
///
/// * one `socks` outbound `proxy-native` to the runtime facade, no user nodes, no selector;
/// * DNS over HTTPS. A restricted lease relays only ports >= 1000, so restricted configs never
///   send DoH (443) through the relay: global/intl_only use AliDNS direct, cn_only uses
///   Cloudflare direct plus AliDNS for China domains. Unrestricted configs send DoH through
///   the relay (except cn_only's Cloudflare server, which stays direct);
/// * `local` is plain UDP 223.5.5.5: the system resolver points at the TUN while connected, so a
///   `local` transport would query itself (same as the macOS system-TUN case);
/// * TUN peer 172.19.0.2 is rejected so gateway probes never loop into the tunnel;
/// * restricted: ports 1-999 go direct; cn_only/intl_only split by geoip/geosite-cn;
///   destinations are resolved before the SOCKS handoff for split scopes and for
///   unrestricted global (the relay's own lookup would use polluted DNS);
/// * route.final: direct for cn_only, otherwise proxy-native.
///
/// iOS drops Android's `route_exclude_address` / lease-endpoint direct rules: the tunnel
/// process's own sockets (the runtime's provider legs) never enter the tunnel, and the plan is
/// built before any lease exists.
extension SingboxConfigBuilder {
    public static let nativeDohRemoteIp = "1.1.1.1"
    public static let nativeDohRemoteSni = "cloudflare-dns.com"
    public static let nativeDohChinaIp = "223.5.5.5"
    public static let nativeDohChinaSni = "dns.alidns.com"
    public static let nativeLocalDnsServer = "223.5.5.5"
    public static let nativeTunPeerIPv4 = "172.19.0.2/32"
    public static let nativeTunPeerIPv6 = "fdfe:dcba:9876::2/128"

    public func buildNative(_ native: NativeSingboxOptions, routing: RoutingPolicySnapshot? = nil) throws -> CompiledSingboxConfig {
        if native.localProxyPort < 1 || native.localProxyPort > 65535 {
            throw SingboxConfigError("native_local_proxy_port_invalid")
        }
        var warnings = routing?.warnings ?? []
        let snapshot = routing ?? routingEngine.compile()
        let tag = NativeOutboundConstants.outboundTag
        let scope = native.routeScope
        let restricted = native.routePolicy == .restricted
        let bypassChina = native.bypassChina && scope == .global
        let resolveDomains = scope.isSplit || !restricted

        var root: [String: Any] = [:]
        root["log"] = ["level": settings.logLevel]
        root["dns"] = buildNativeDns(scope: scope, restricted: restricted, bypassChina: bypassChina)
        root["inbounds"] = [buildTunInbound()]
        let socks: [String: Any] = [
            "type": "socks",
            "tag": tag,
            "server": "127.0.0.1",
            "server_port": native.localProxyPort,
            "version": "5",
            // sing-box only accepts "tcp" or "udp" for `network`; leaving it out keeps both,
            // which the tcp+udp native lease needs.
            "udp_over_tcp": ["enabled": false],
        ]
        let direct: [String: Any] = ["type": "direct", "tag": "direct"]
        let outbounds: [[String: Any]] = [socks, direct]
        root["outbounds"] = outbounds

        var route: [String: Any] = [:]
        route["rules"] = buildNativeRouteRules(
            snapshot: snapshot,
            scope: scope,
            restricted: restricted,
            bypassChina: bypassChina,
            resolveDomains: resolveDomains,
            warnings: &warnings
        )
        route["final"] = scope == .cnOnly ? "direct" : tag
        route["default_domain_resolver"] = resolveDomains ? "remote" : "local"
        // A restricted relay refuses 443, so rule-sets download direct; unrestricted downloads
        // follow the exit except for cn_only, whose exit only serves China.
        let downloadDetour = (restricted || scope == .cnOnly) ? "direct" : tag
        let ruleSets = buildNativeRuleSets(snapshot: snapshot, needsChinaSets: scope.isSplit || bypassChina, downloadDetour: downloadDetour)
        if !ruleSets.isEmpty {
            route["rule_set"] = ruleSets
        }
        root["route"] = route

        if let cachePath = settings.cacheFilePath, !cachePath.isEmpty {
            root["experimental"] = ["cache_file": ["enabled": true, "path": cachePath] as [String: Any]]
        }
        let json = try CanonicalJSON.string(root)
        return CompiledSingboxConfig(configJSON: json, finalProxyTag: tag, warnings: warnings)
    }

    func buildNativeDns(scope: NativeRouteScope, restricted: Bool, bypassChina: Bool) -> [String: Any] {
        let tag = NativeOutboundConstants.outboundTag
        let remoteIsChina = restricted && scope != .cnOnly
        var remote = nativeDohServer(
            tag: "remote",
            server: remoteIsChina ? SingboxConfigBuilder.nativeDohChinaIp : SingboxConfigBuilder.nativeDohRemoteIp,
            serverName: remoteIsChina ? SingboxConfigBuilder.nativeDohChinaSni : SingboxConfigBuilder.nativeDohRemoteSni
        )
        if scope != .cnOnly && !restricted {
            remote["detour"] = tag
        }
        var servers: [[String: Any]] = [remote]
        if scope == .cnOnly {
            var china = nativeDohServer(tag: "china", server: SingboxConfigBuilder.nativeDohChinaIp, serverName: SingboxConfigBuilder.nativeDohChinaSni)
            if !restricted {
                china["detour"] = tag
            }
            servers.append(china)
        }
        servers.append(["tag": "local", "type": "udp", "server": SingboxConfigBuilder.nativeLocalDnsServer])

        var directDomains: [String] = []
        for domain in safePathRegistry.allDomains + customBypassDomains() where !domain.isEmpty && !directDomains.contains(domain) {
            directDomains.append(domain)
        }
        var rules: [[String: Any]] = []
        if !directDomains.isEmpty {
            rules.append(["domain": directDomains, "server": "local"])
        }
        switch scope {
        case .cnOnly:
            rules.append(["rule_set": ["geosite-cn"], "server": "china"])
        case .intlOnly:
            rules.append(["rule_set": ["geosite-cn"], "server": "local"])
        case .global:
            if bypassChina {
                rules.append(["rule_set": ["geosite-cn"], "server": "local"])
            }
        }
        return [
            "servers": servers,
            "rules": rules,
            "final": "remote",
            // Native relays carry IPv4 destinations (Android/macOS native: ipv4_only).
            "strategy": "ipv4_only",
        ]
    }

    func nativeDohServer(tag: String, server: String, serverName: String) -> [String: Any] {
        return [
            "tag": tag,
            "type": "https",
            "server": server,
            "server_port": 443,
            "path": "/dns-query",
            "tls": ["enabled": true, "server_name": serverName] as [String: Any],
        ]
    }

    func buildNativeRouteRules(
        snapshot: RoutingPolicySnapshot,
        scope: NativeRouteScope,
        restricted: Bool,
        bypassChina: Bool,
        resolveDomains: Bool,
        warnings: inout [String]
    ) -> [[String: Any]] {
        let tag = NativeOutboundConstants.outboundTag
        var rules: [[String: Any]] = []
        rules.append(["action": "sniff"])
        rules.append(["protocol": "dns", "action": "hijack-dns"])

        // Reject packets aimed at the TUN peer before the private-range direct rule, so gateway
        // discovery is never fed back into the tunnel.
        var peers = [SingboxConfigBuilder.nativeTunPeerIPv4]
        if settings.enableIPv6 { peers.append(SingboxConfigBuilder.nativeTunPeerIPv6) }
        for network in ["tcp", "udp"] {
            rules.append([
                "inbound": ["tun-in"],
                "network": network,
                "ip_cidr": peers,
                "action": "reject",
                "method": "drop",
            ])
        }

        // (1) System-protected paths (panel, mirror, private ranges): always direct.
        for entry in snapshot.protectedPaths {
            if !entry.domains.isEmpty {
                rules.append(["domain": entry.domains, "outbound": "direct"])
            }
            if !entry.ipCidrs.isEmpty {
                rules.append(["ip_cidr": entry.ipCidrs.map(SingboxConfigBuilder.normalizeIpCidr), "outbound": "direct"])
            }
        }

        // (2) Restricted relays only carry ports >= 1000; lower ports stay on the direct path.
        if restricted {
            rules.append(["network": ["tcp", "udp"], "port_range": "1:999", "outbound": "direct"])
        }

        // (3) Resolve once with the config's protected DNS before the SOCKS handoff, so the relay
        //     cannot replace the answer with a polluted or region-wrong one.
        if resolveDomains {
            rules.append(["action": "resolve", "strategy": "ipv4_only"])
        }

        // (4) Scope split by the China rule-sets.
        if scope.isSplit {
            let portRange: String = restricted ? "\(NativeOutboundConstants.restrictedPortMin):65535" : "1:65535"
            let chinaOutbound: String = scope == .cnOnly ? tag : "direct"
            let split: [String: Any] = [
                "rule_set": ["geoip-cn", "geosite-cn"],
                "network": ["tcp", "udp"],
                "port_range": portRange,
                "outbound": chinaOutbound,
            ]
            rules.append(split)
        }

        if snapshot.globalMode == .rule {
            // (5) User custom rules, then app presets / countries / categories. Every "proxy"
            //     target (including a specific node) is the native exit.
            for rule in snapshot.customRules where rule.enabled {
                guard var match = rule.singboxMatch() else { continue }
                switch rule.outcome {
                case .proxy: match["outbound"] = tag
                case .direct: match["outbound"] = "direct"
                case .reject: match["action"] = "reject"
                }
                rules.append(match)
            }
            for compiled in routingEngine.compileRouteRules(snapshot) {
                var outbound = compiled.outbound
                if outbound == RoutingPolicyEngine.proxyOutboundSentinel || (outbound.hasPrefix("<NODE:") && outbound.hasSuffix(">")) {
                    outbound = tag
                }
                rules.append(["rule_set": compiled.ruleSetTags, "outbound": outbound])
            }
        }

        // (6) Global scope with bypass-China.
        if bypassChina {
            rules.append(["rule_set": ["geoip-cn", "geosite-cn"], "outbound": "direct"])
        }

        // (7) Custom bypass list (always direct).
        let custom = settings.customBypassRules.trimmingCharacters(in: .whitespacesAndNewlines)
        if !custom.isEmpty {
            var ips: [String] = []
            var domains: [String] = []
            for line in ProxyUriParser.lines(of: custom) {
                if SingboxConfigBuilder.isIpLike(line) {
                    ips.append(SingboxConfigBuilder.normalizeIpCidr(line))
                } else {
                    domains.append(line)
                }
            }
            if !ips.isEmpty { rules.append(["ip_cidr": ips, "outbound": "direct"]) }
            if !domains.isEmpty { rules.append(["domain": domains, "outbound": "direct"]) }
        }
        return rules
    }

    func buildNativeRuleSets(snapshot: RoutingPolicySnapshot, needsChinaSets: Bool, downloadDetour: String) -> [[String: Any]] {
        var result: [[String: Any]] = []
        var seen = Set<String>()
        func add(_ tag: String, _ url: String) {
            guard seen.insert(tag).inserted else { return }
            result.append([
                "tag": tag,
                "type": "remote",
                "format": "binary",
                "url": url,
                "download_detour": downloadDetour,
            ])
        }
        if needsChinaSets {
            add("geoip-cn", SingboxConfigBuilder.chinaGeoipUrl)
            add("geosite-cn", SingboxConfigBuilder.chinaGeositeUrl)
        }
        if snapshot.globalMode == .rule {
            for ref in routingEngine.requiredRuleSets(snapshot) {
                add(ref.tag, ref.url)
            }
        }
        return result
    }
}
