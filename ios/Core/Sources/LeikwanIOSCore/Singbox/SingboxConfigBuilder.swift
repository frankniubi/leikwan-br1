import Foundation

/// Port of `singbox/SingboxConfigBuilder.kt` for sing-box 1.13.x on iOS.
///
/// iOS differences: shadowsocks + socks nodes only; SERVICES / DIRECT modes only (native outbound is
/// `buildNative` in SingboxNativeConfig.swift;
/// no client route-direction rules); TUN stack/MTU configurable (default mixed / 9000); `log.level`
/// configurable; `experimental.cache_file`; the profile's panel hosts protected; custom rules and app
/// presets; rule-sets are the bundled `local` ones (`BundledRuleSets`, nothing is downloaded);
/// `route.default_domain_resolver` = "direct-dns". Output is deterministic JSON (sorted keys).
public struct SingboxConfigBuilder {
    public static let tunIPv4Address = "172.19.0.1/30"
    public static let tunIPv6Address = "fdfe:dcba:9876::1/126"

    public let settings: SingboxSettings
    public let safePathRegistry: SafePathRegistry
    public let routingEngine: RoutingPolicyEngine

    public init(
        settings: SingboxSettings = SingboxSettings(),
        safePathRegistry: SafePathRegistry = .iosBaseline(),
        categoryCatalog: RoutingCategoryCatalog = .standard
    ) {
        self.settings = settings
        self.safePathRegistry = safePathRegistry
        self.routingEngine = RoutingPolicyEngine(safePathRegistry: safePathRegistry, categoryCatalog: categoryCatalog)
    }

    /// - Parameters:
    ///   - proxyNodes: user nodes (disabled ones are ignored).
    ///   - runtime: the running forward runtime; required in `.services` mode.
    ///   - routing: compiled routing snapshot (nil = rule mode, TUNNEL default, no user rules).
    ///   - runtimeOnlyProxy: Ultra with no selected node; all remaining traffic is bridged to the runtime.
    public func build(
        proxyNodes: [ProxyNode],
        runtime: SingboxRuntimeContext?,
        routing: RoutingPolicySnapshot? = nil,
        connectionMode: ProxyConnectionMode = .services,
        runtimeOnlyProxy: Bool = false
    ) throws -> CompiledSingboxConfig {
        if connectionMode == .services && runtime == nil {
            throw SingboxConfigError("services_mode_requires_runtime")
        }
        var warnings = routing?.warnings ?? []
        let snapshot = routing ?? routingEngine.compile()
        let mode = snapshot.globalMode
        let activeNodes = SingboxConfigBuilder.activeProxyNodes(proxyNodes, runtime: runtime, connectionMode: connectionMode)
        let protectedConnectivity = (runtime?.allProtectedConnectivity ?? .empty)
            .merge(ProtectedConnectivitySet.fromEndpoints(activeNodes.map { $0.server }.filter { !$0.isEmpty }))

        let finalProxyTag: String
        if activeNodes.count == 1 {
            finalProxyTag = activeNodes[0].outboundTag
        } else {
            finalProxyTag = "proxy"
        }
        let nodeTags = Set(activeNodes.map { $0.outboundTag })
        let hasProxy = !activeNodes.isEmpty
        let bypassChinaActive = settings.bypassChina && mode == .rule

        var root: [String: Any] = [:]
        root["log"] = ["level": settings.logLevel]
        root["dns"] = buildDns(
            proxyDetourTag: hasProxy ? finalProxyTag : nil,
            bootstrapDomains: protectedConnectivity.hosts + customBypassDomains(),
            bypassChina: bypassChinaActive
        )
        root["inbounds"] = [buildTunInbound()]
        root["outbounds"] = buildOutbounds(activeNodes, runtime: runtime, connectionMode: connectionMode)

        var bridgeOverride: Int?
        if runtimeOnlyProxy && connectionMode == .services && activeNodes.isEmpty && mode != .direct, let runtime = runtime {
            bridgeOverride = runtime.localListenPort
        }

        var route: [String: Any] = [:]
        route["rules"] = buildRouteRules(
            snapshot: snapshot,
            protectedConnectivity: protectedConnectivity,
            finalProxyTag: finalProxyTag,
            nodeTags: nodeTags,
            bypassChina: bypassChinaActive,
            bridgeOverridePort: bridgeOverride,
            warnings: &warnings
        )
        route["final"] = finalOutbound(snapshot: snapshot, finalProxyTag: finalProxyTag, nodeTags: nodeTags, warnings: &warnings)
        route["default_domain_resolver"] = "direct-dns"
        let ruleSets = buildRuleSets(snapshot: snapshot, bypassChina: bypassChinaActive)
        if !ruleSets.isEmpty {
            route["rule_set"] = ruleSets
        }
        root["route"] = route

        if let cachePath = settings.cacheFilePath, !cachePath.isEmpty {
            root["experimental"] = ["cache_file": ["enabled": true, "path": cachePath] as [String: Any]]
        }

        let json = try CanonicalJSON.string(root)
        return CompiledSingboxConfig(configJSON: json, finalProxyTag: finalProxyTag, warnings: warnings)
    }

    // MARK: Nodes

    /// Android `activeProxyNodes` (WireGuard clause dropped).
    public static func activeProxyNodes(_ nodes: [ProxyNode], runtime: SingboxRuntimeContext?, connectionMode: ProxyConnectionMode) -> [ProxyNode] {
        return nodes.filter { node in
            guard node.enabled else { return false }
            guard connectionMode == .services, let runtime = runtime else { return true }
            if !runtime.nodeListenPorts.isEmpty || !runtime.directNodeIds.isEmpty {
                return runtime.nodeListenPorts[node.id] != nil || runtime.directNodeIds.contains(node.id)
            }
            return node.forwardId == nil || node.forwardId == runtime.forwardId
        }
    }

    func buildOutbounds(_ nodes: [ProxyNode], runtime: SingboxRuntimeContext?, connectionMode: ProxyConnectionMode) -> [[String: Any]] {
        var outbounds: [[String: Any]] = []
        var proxyTags: [String] = []
        for node in nodes {
            let server: String
            let port: Int
            if connectionMode == .services, let runtime = runtime, runtime.directNodeIds.contains(node.id) {
                // Multi-binding: a node bound without a forward is dialled directly.
                server = node.server
                port = node.port
            } else if connectionMode == .services {
                guard let runtime = runtime else { continue }
                server = "127.0.0.1"
                port = runtime.relayListenPort(forNode: node.id)
            } else {
                server = node.server
                port = node.port
            }
            outbounds.append(proxyOutbound(node, server: server, port: port))
            proxyTags.append(node.outboundTag)
        }
        if proxyTags.count > 1 {
            outbounds.append([
                "type": "selector",
                "tag": "proxy",
                "outbounds": proxyTags,
                "default": proxyTags[0],
            ])
        } else if proxyTags.isEmpty {
            outbounds.append(["type": "direct", "tag": "proxy"])
        }
        outbounds.append(["type": "direct", "tag": "direct"])
        return outbounds
    }

    func proxyOutbound(_ node: ProxyNode, server: String, port: Int) -> [String: Any] {
        switch node.settings {
        case .shadowsocks(let ss):
            var outbound: [String: Any] = [
                "type": "shadowsocks",
                "tag": node.outboundTag,
                "server": server,
                "server_port": port,
                "method": ss.method,
                "password": ss.password,
            ]
            if let plugin = ss.plugin, !plugin.trimmingCharacters(in: .whitespaces).isEmpty { outbound["plugin"] = plugin }
            if let opts = ss.pluginOpts, !opts.trimmingCharacters(in: .whitespaces).isEmpty { outbound["plugin_opts"] = opts }
            if let network = ss.network, !network.trimmingCharacters(in: .whitespaces).isEmpty { outbound["network"] = network }
            if let uot = ss.udpOverTcp {
                outbound["udp_over_tcp"] = uot
            } else if ss.multiplexEnabled == true {
                outbound["multiplex"] = ["enabled": true]
            }
            return outbound
        case .socks(let socks):
            var outbound: [String: Any] = [
                "type": "socks",
                "tag": node.outboundTag,
                "server": server,
                "server_port": port,
                "version": "5",
            ]
            if let user = socks.username, !user.trimmingCharacters(in: .whitespaces).isEmpty { outbound["username"] = user }
            if let pass = socks.password, !pass.trimmingCharacters(in: .whitespaces).isEmpty { outbound["password"] = pass }
            return outbound
        }
    }

    // MARK: DNS / inbound

    func buildDns(proxyDetourTag: String?, bootstrapDomains: [String], bypassChina: Bool) -> [String: Any] {
        var remote: [String: Any] = [
            "tag": "remote",
            "type": proxyDetourTag != nil ? "tcp" : "udp",
            "server": settings.dnsServer,
        ]
        if let detour = proxyDetourTag { remote["detour"] = detour }
        let direct: [String: Any] = ["tag": "direct-dns", "type": "udp", "server": settings.dnsFallback]

        var directDomains: [String] = []
        for domain in safePathRegistry.allDomains + bootstrapDomains where !domain.isEmpty && !directDomains.contains(domain) {
            directDomains.append(domain)
        }
        var rules: [[String: Any]] = []
        if !directDomains.isEmpty {
            rules.append(["domain": directDomains, "server": "direct-dns"])
        }
        if bypassChina {
            rules.append(["rule_set": ["geosite-cn"], "server": "direct-dns"])
        }
        return [
            "servers": [remote, direct],
            "rules": rules,
            "final": "remote",
            "strategy": settings.enableIPv6 ? settings.dnsStrategy : "ipv4_only",
        ]
    }

    /// The IPv6 address is always present, whatever `enableIPv6` says: with it the packet tunnel
    /// installs NEIPv6Settings with the `::/0` default route (TunnelPlatformInterface.openTun0);
    /// without it iOS keeps IPv6 on the physical interface and WebRTC / literal-v6 traffic leaks
    /// the real address. `enableIPv6` only controls whether DNS hands out AAAA answers.
    func buildTunInbound() -> [String: Any] {
        return [
            "type": "tun",
            "tag": "tun-in",
            "address": [SingboxConfigBuilder.tunIPv4Address, SingboxConfigBuilder.tunIPv6Address],
            "auto_route": true,
            "strict_route": true,
            "stack": settings.tunStack,
            "endpoint_independent_nat": true,
            "mtu": settings.tunMTU,
        ]
    }

    // MARK: Route

    func buildRouteRules(
        snapshot: RoutingPolicySnapshot,
        protectedConnectivity: ProtectedConnectivitySet,
        finalProxyTag: String,
        nodeTags: Set<String>,
        bypassChina: Bool,
        bridgeOverridePort: Int?,
        warnings: inout [String]
    ) -> [[String: Any]] {
        var rules: [[String: Any]] = []
        rules.append(["action": "sniff"])
        rules.append(["protocol": "dns", "action": "hijack-dns"])

        // (1) System-protected paths (non-overridable).
        for entry in snapshot.protectedPaths {
            if !entry.domains.isEmpty {
                rules.append(["domain": entry.domains, "outbound": "direct"])
            }
            if !entry.ipCidrs.isEmpty {
                rules.append(["ip_cidr": entry.ipCidrs.map(SingboxConfigBuilder.normalizeIpCidr), "outbound": "direct"])
            }
        }

        // (2) Runtime reachability (endpoint hosts/IPs, cdn_host, sni, lease endpoints): direct.
        if !protectedConnectivity.ipCidrs.isEmpty {
            rules.append(["ip_cidr": protectedConnectivity.ipCidrs, "outbound": "direct"])
        }
        if !protectedConnectivity.hosts.isEmpty {
            rules.append(["domain": protectedConnectivity.hosts, "outbound": "direct"])
        }

        if snapshot.globalMode == .rule {
            // (3) User custom rules, in user order.
            for rule in snapshot.customRules where rule.enabled {
                guard var match = rule.singboxMatch() else { continue }
                switch rule.outcome {
                case .proxy:
                    var outbound = finalProxyTag
                    if let target = rule.targetNodeId {
                        let tag = "proxy-\(target)"
                        if nodeTags.contains(tag) {
                            outbound = tag
                        } else {
                            warnings.append("Rule '\(rule.id)': target node \(target) unavailable; using default.")
                        }
                    }
                    match["outbound"] = outbound
                case .direct: match["outbound"] = "direct"
                case .reject: match["action"] = "reject"
                }
                rules.append(match)
            }

            // (4) App presets, countries, categories.
            for compiled in routingEngine.compileRouteRules(snapshot) {
                var outbound = compiled.outbound
                if outbound == RoutingPolicyEngine.proxyOutboundSentinel {
                    outbound = finalProxyTag
                } else if outbound.hasPrefix("<NODE:") && outbound.hasSuffix(">") {
                    let id = String(outbound.dropFirst("<NODE:".count).dropLast())
                    let tag = "proxy-" + id
                    if nodeTags.contains(tag) {
                        outbound = tag
                    } else {
                        warnings.append("Rule '\(compiled.sourceRuleId ?? "?")': target node \(id) unavailable; using default.")
                        outbound = finalProxyTag
                    }
                }
                rules.append(["rule_set": compiled.ruleSetTags, "outbound": outbound])
            }

            // (5) bypass-China toggle.
            if bypassChina {
                rules.append(["rule_set": ["geoip-cn", "geosite-cn"], "outbound": "direct"])
            }
        }

        // (6) Custom bypass list (always direct).
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

        // (7) Ultra without a node: bridge everything else to the runtime listener.
        if let port = bridgeOverridePort {
            rules.append([
                "action": "route",
                "outbound": finalProxyTag,
                "override_address": "127.0.0.1",
                "override_port": port,
            ])
        }
        return rules
    }

    func finalOutbound(snapshot: RoutingPolicySnapshot, finalProxyTag: String, nodeTags: Set<String>, warnings: inout [String]) -> String {
        var defaultTunnelTag = finalProxyTag
        if let nodeId = snapshot.globalDefaultNodeId {
            let tag = "proxy-\(nodeId)"
            if nodeTags.contains(tag) {
                defaultTunnelTag = tag
            } else {
                warnings.append("Default node \(nodeId) unavailable; using default proxy.")
            }
        }
        switch snapshot.globalMode {
        case .direct: return "direct"
        case .global: return defaultTunnelTag
        case .rule: return snapshot.globalDefaultOutcome == .direct ? "direct" : defaultTunnelTag
        }
    }

    func buildRuleSets(snapshot: RoutingPolicySnapshot, bypassChina: Bool) -> [[String: Any]] {
        var tags: [String] = []
        if bypassChina {
            tags.append(contentsOf: BundledRuleSets.chinaTags)
        }
        tags.append(contentsOf: routingEngine.requiredRuleSets(snapshot).map { $0.tag })
        return localRuleSets(tags)
    }

    /// Bundled rule-set entries for `tags`, de-duplicated in order.
    func localRuleSets(_ tags: [String]) -> [[String: Any]] {
        var result: [[String: Any]] = []
        var seen = Set<String>()
        for tag in tags where seen.insert(tag).inserted {
            result.append(BundledRuleSets.singboxEntry(tag: tag, directory: settings.ruleSetDirectory))
        }
        return result
    }

    // MARK: Helpers

    func customBypassDomains() -> [String] {
        var result: [String] = []
        for line in ProxyUriParser.lines(of: settings.customBypassRules) where !SingboxConfigBuilder.isIpLike(line) {
            var host = line.lowercased()
            if host.hasSuffix(".") { host.removeLast() }
            if ProtectedConnectivitySet.isHostname(host) && !result.contains(host) {
                result.append(host)
            }
        }
        return result
    }

    /// A real IP literal (optionally `/prefix`). Stricter than Android's regex, which treated
    /// hex-only domains such as `cafe.de` as IPs and produced an invalid CIDR.
    static func isIpLike(_ value: String) -> Bool {
        let parts = value.split(separator: "/", maxSplits: 1, omittingEmptySubsequences: false).map(String.init)
        guard IPLiteral.normalize(parts[0]) != nil else { return false }
        if parts.count == 2 {
            return Int(parts[1]) != nil
        }
        return true
    }

    static func normalizeIpCidr(_ value: String) -> String {
        if value.contains("/") { return value }
        return value.contains(":") ? value + "/128" : value + "/32"
    }
}
