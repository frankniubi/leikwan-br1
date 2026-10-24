package main

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
)

// The LAN-facing sing-box configuration.
//
// The DNS and routing policy is the desktop clients' native-outbound policy
// (LeikwanBridge.Mac MacConnectionConfigBuilder.BuildNative and the Windows
// WindowsProxyConfigBuilder.BuildNative), adapted to a router:
//
//   - Resolve over DoH, never over plain UDP to an ISP resolver. Unrestricted
//     routes carry DoH through the relay; restricted routes cannot (the relay
//     refuses every port below 1000, 443 included), so they use mainland DoH
//     on the direct path instead.
//   - cn_only resolves mainland names with AliDNS through the relay (the exit
//     is in China, so answers are unpolluted and geographically right) and
//     everything else with Cloudflare directly.
//   - Mainland names resolve locally whenever mainland traffic is routed
//     direct, so a split tunnel actually splits.
//   - The "local" resolver is an explicit mainland server, not the system
//     resolver: on the router that is dnsmasq, whose upstream query would be
//     hijacked back into this same DNS module.
//   - Every DNS packet entering the TUN is hijacked, including dnsmasq's own
//     upstream queries, so LAN clients that use the router as their resolver
//     get the protected answers without touching the dnsmasq configuration.
//   - Resolve before the SOCKS handoff on unrestricted or split routes, so
//     the relay never performs a second lookup on another DNS path.
//   - The China rule-sets ship in the package: sing-box keeps its inbounds
//     closed until a remote rule-set's first download finishes, and GitHub is
//     slow or unreachable from the mainland.
const (
	gatewayTunName   = "lkwtun0"
	gatewaySocksPort = 2080
	// The split carrier's relay; only its CIDR list is routed to it.
	gatewaySplitSocksPort = 2081
	gatewayConfigName     = "sing-box.json"
	gatewayCNRuleSet      = "geosite-cn"
	gatewayCNIPRuleSet    = "geoip-cn"
	gatewayTableIndex     = 2022
	gatewayRuleIndex      = 9000
	gatewayLowPortRange   = "1:999"
	gatewayRuleSetHost    = "raw.githubusercontent.com"

	cloudflareDoHAddress = "1.1.1.1"
	cloudflareDoHName    = "cloudflare-dns.com"
	aliDoHAddress        = "223.5.5.5"
	aliDoHName           = "dns.alidns.com"
	gatewayLocalDNS      = "223.5.5.5"
)

// gatewayRuleSetFiles reports the bundled rule-sets, or "" when either is
// missing or is not a sing-box binary rule-set.
func gatewayRuleSetFiles(dir string) (geoIP, geoSite string) {
	geoIP = filepath.Join(dir, gatewayCNIPRuleSet+".srs")
	geoSite = filepath.Join(dir, gatewayCNRuleSet+".srs")
	if isBinaryRuleSet(geoIP) && isBinaryRuleSet(geoSite) {
		return geoIP, geoSite
	}
	return "", ""
}

func isBinaryRuleSet(path string) bool {
	file, err := os.Open(path)
	if err != nil {
		return false
	}
	defer file.Close()
	magic := make([]byte, 3)
	if _, err := file.Read(magic); err != nil {
		return false
	}
	return bytes.Equal(magic, []byte("SRS"))
}

func gatewayRuleSetDefinitions(cfg Config, scope string) ([]map[string]any, string) {
	if geoIP, geoSite := gatewayRuleSetFiles(cfg.GatewayRuleSetDir); geoIP != "" {
		return []map[string]any{
			{"type": "local", "tag": gatewayCNIPRuleSet, "format": "binary", "path": geoIP},
			{"type": "local", "tag": gatewayCNRuleSet, "format": "binary", "path": geoSite},
		}, "bundled"
	}
	// Fallback for a hand-assembled install. The download follows the
	// direction's usable international path, like the desktop.
	detour := "proxy"
	if scope == "cn_only" || scope == "intl_only" {
		detour = "direct"
	}
	return []map[string]any{
		{"type": "remote", "tag": gatewayCNIPRuleSet, "format": "binary", "download_detour": detour,
			"url": "https://" + gatewayRuleSetHost + "/SagerNet/sing-geoip/rule-set/geoip-cn.srs"},
		{"type": "remote", "tag": gatewayCNRuleSet, "format": "binary", "download_detour": detour,
			"url": "https://" + gatewayRuleSetHost + "/SagerNet/sing-geosite/rule-set/geosite-cn.srs"},
	}, "remote"
}

func dohServer(tag, address, serverName string) map[string]any {
	return map[string]any{
		"type": "https", "tag": tag, "server": address, "server_port": 443, "path": "/dns-query",
		"tls": map[string]any{"enabled": true, "server_name": serverName},
	}
}

// gatewayBypassHosts are names the router itself must reach directly: the
// Panel (control plane) and the runtime's ingress-zone probe, whose answer
// would otherwise describe the exit instead of the router.
func gatewayBypassHosts(cfg Config) []string {
	seen := map[string]bool{}
	out := []string{}
	for _, raw := range []string{cfg.PanelURL, cfg.FallbackURL, runtimeLocationEndpoint} {
		if host := hostOfURL(raw); host != "" && !seen[host] {
			seen[host] = true
			out = append(out, host)
		}
	}
	return out
}

// runtimeLocationEndpoint mirrors tools/ultra-client/location.go.
const runtimeLocationEndpoint = "https://api.bilibili.com/x/web-interface/zone"

func renderGatewayDNS(cfg Config, scope, policy string, bypassHosts []string) map[string]any {
	restricted := policy != "unrestricted"
	cnOnly := scope == "cn_only"
	intlOnly := scope == "intl_only"
	viaRelay := !restricted && !cnOnly

	servers := []map[string]any{}
	final := "remote"
	custom := []string{}
	if cfg.GatewayDNSMode == "custom" && !cnOnly {
		for _, server := range splitList(cfg.GatewayDNSServers) {
			if host, port := splitHostPortDefault(server, 53); host != "" {
				custom = append(custom, host+"|"+itoa(port))
			}
		}
	}
	if len(custom) > 0 {
		for i, entry := range custom {
			host, port, _ := strings.Cut(entry, "|")
			// Native SOCKS5 carries DNS as real UDP; TCP DNS through the relay
			// can be accepted and then closed before the answer (Windows
			// 0.1.20.68 fix).
			server := map[string]any{"type": "udp", "tag": "custom-" + itoa(i), "server": host, "server_port": atoiDefault(port, 53)}
			if viaRelay {
				server["detour"] = "proxy"
			}
			servers = append(servers, server)
		}
		final = "custom-0"
	} else {
		remote := dohServer("remote", cloudflareDoHAddress, cloudflareDoHName)
		if restricted && !cnOnly {
			remote = dohServer("remote", aliDoHAddress, aliDoHName)
		}
		if viaRelay {
			remote["detour"] = "proxy"
		}
		servers = append(servers, remote)
	}
	if cnOnly {
		china := dohServer("china", aliDoHAddress, aliDoHName)
		if !restricted {
			china["detour"] = "proxy"
		}
		servers = append(servers, china)
	}
	servers = append(servers, map[string]any{"type": "udp", "tag": "local", "server": gatewayLocalDNS, "server_port": 53})

	rules := []map[string]any{}
	if len(bypassHosts) > 0 {
		rules = append(rules, map[string]any{"domain": bypassHosts, "server": "local"})
	}
	switch {
	case cnOnly:
		rules = append(rules, map[string]any{"rule_set": []string{gatewayCNRuleSet}, "server": "china"})
	case intlOnly || cfg.GatewayBypassCN:
		rules = append(rules, map[string]any{"rule_set": []string{gatewayCNRuleSet}, "server": "local"})
	}
	return map[string]any{
		"servers":  servers,
		"rules":    rules,
		"final":    final,
		"strategy": "ipv4_only",
	}
}

// gatewaySplitRender is the split carrier as sing-box sees it: a second SOCKS
// outbound that only the listed destinations use.
type gatewaySplitRender struct {
	Policy string
	CIDRs  []string
}

// renderGatewayConfig builds the whole sing-box configuration for one plan.
// split is nil when no split carrier is configured or granted.
func renderGatewayConfig(cfg Config, scope, policy string, split *gatewaySplitRender) (map[string]any, string) {
	scope = normalizeRouteScope(scope)
	policy = normalizeRoutePolicy(policy)
	restricted := policy != "unrestricted"
	bypassHosts := gatewayBypassHosts(cfg)
	ruleSets, ruleSetSource := gatewayRuleSetDefinitions(cfg, scope)
	dns := renderGatewayDNS(cfg, scope, policy, bypassHosts)

	nativePorts := "1:65535"
	if restricted {
		nativePorts = itoa(nativePortMin) + ":65535"
	}
	cnMatch := func(outbound string) map[string]any {
		return map[string]any{
			"rule_set":   []string{gatewayCNIPRuleSet, gatewayCNRuleSet},
			"network":    []string{"tcp", "udp"},
			"port_range": []string{nativePorts},
			"outbound":   outbound,
		}
	}

	rules := []map[string]any{
		{"action": "sniff"},
		// Before the private rule: dnsmasq's upstream is often a LAN-side
		// modem, and that query must be protected too.
		{"protocol": "dns", "action": "hijack-dns"},
		{"ip_is_private": true, "outbound": "direct"},
	}
	if len(bypassHosts) > 0 {
		rules = append(rules, map[string]any{"domain": bypassHosts, "outbound": "direct"})
	}
	resolveFirst := !restricted || scope != "global" || split != nil
	resolved := false
	if split != nil && len(split.CIDRs) > 0 {
		// The split list is matched by IP, so resolve first; it sits ahead of
		// the primary policy because its own policy decides its ports.
		splitPorts := "1:65535"
		if normalizeRoutePolicy(split.Policy) != "unrestricted" {
			splitPorts = itoa(nativePortMin) + ":65535"
		}
		rules = append(rules,
			map[string]any{"action": "resolve", "strategy": "ipv4_only"},
			map[string]any{"ip_cidr": split.CIDRs, "network": []string{"tcp", "udp"}, "port_range": []string{splitPorts}, "outbound": "split"},
		)
		resolved = true
	}
	if restricted {
		// The relay is a game path under the restricted policy: privileged
		// and control-plane ports stay local, exactly as the relay enforces.
		rules = append(rules, map[string]any{"network": []string{"tcp", "udp"}, "port_range": []string{gatewayLowPortRange}, "outbound": "direct"})
	}
	if resolveFirst && !resolved {
		rules = append(rules, map[string]any{"action": "resolve", "strategy": "ipv4_only"})
	}
	final := "proxy"
	switch scope {
	case "cn_only":
		rules = append(rules, cnMatch("proxy"))
		final = "direct"
	case "intl_only":
		rules = append(rules, cnMatch("direct"))
	default:
		if cfg.GatewayBypassCN {
			rules = append(rules, cnMatch("direct"))
		}
	}
	domainResolver := "local"
	if resolveFirst {
		domainResolver, _ = dns["final"].(string)
	}

	outbounds := []map[string]any{
		{
			"type": "socks", "tag": "proxy", "server": "127.0.0.1", "server_port": gatewaySocksPort, "version": "5",
			// Keep the native UDP leg as real UDP.
			"udp_over_tcp": map[string]any{"enabled": false},
		},
		{"type": "direct", "tag": "direct"},
	}
	if resolved {
		outbounds = append(outbounds, map[string]any{
			"type": "socks", "tag": "split", "server": "127.0.0.1", "server_port": gatewaySplitSocksPort, "version": "5",
			"udp_over_tcp": map[string]any{"enabled": false},
		})
	}

	config := map[string]any{
		"log": map[string]any{"level": "warn", "timestamp": true},
		"dns": dns,
		"inbounds": []map[string]any{{
			"type":                     "tun",
			"tag":                      "tun-in",
			"interface_name":           gatewayTunName,
			"address":                  []string{cfg.GatewayTunAddress},
			"mtu":                      cfg.GatewayTunMTU,
			"auto_route":               true,
			"strict_route":             false,
			"stack":                    "mixed",
			"endpoint_independent_nat": true,
			// Pinned so the relay bypass rules (pref 8970) always sit in
			// front of sing-box's own rules.
			"iproute2_table_index": gatewayTableIndex,
			"iproute2_rule_index":  gatewayRuleIndex,
		}},
		"outbounds": outbounds,
		"route": map[string]any{
			"rule_set":                ruleSets,
			"rules":                   rules,
			"final":                   final,
			"default_domain_resolver": domainResolver,
			"auto_detect_interface":   true,
		},
	}
	return config, ruleSetSource
}

// nativePortMin mirrors ultra-client's restricted-policy floor.
const nativePortMin = 1000

// renderGatewayCIDRConfig is the cidr capture mode: the TUN routes only the
// listed prefixes (route_address), so the rest of the box - its DNS, its
// default route, its VMs' other traffic - never enters sing-box. Everything
// that does enter goes to the carrier; there is no DNS block and no sniffing.
func renderGatewayCIDRConfig(cfg Config, policy string, cidrs []string) map[string]any {
	ports := "1:65535"
	if normalizeRoutePolicy(policy) != "unrestricted" {
		ports = itoa(nativePortMin) + ":65535"
	}
	return map[string]any{
		"log": map[string]any{"level": "warn", "timestamp": true},
		"inbounds": []map[string]any{{
			"type":                     "tun",
			"tag":                      "tun-in",
			"interface_name":           gatewayTunName,
			"address":                  []string{cfg.GatewayTunAddress},
			"mtu":                      cfg.GatewayTunMTU,
			"auto_route":               true,
			"strict_route":             false,
			"route_address":            cidrs,
			"stack":                    "mixed",
			"endpoint_independent_nat": true,
			"iproute2_table_index":     gatewayTableIndex,
			"iproute2_rule_index":      gatewayRuleIndex,
		}},
		"outbounds": []map[string]any{
			{
				"type": "socks", "tag": "proxy", "server": "127.0.0.1", "server_port": gatewaySocksPort, "version": "5",
				"udp_over_tcp": map[string]any{"enabled": false},
			},
			{"type": "direct", "tag": "direct"},
		},
		"route": map[string]any{
			"rules": []map[string]any{
				{"ip_cidr": cidrs, "network": []string{"tcp", "udp"}, "port_range": []string{ports}, "outbound": "proxy"},
			},
			"final":                 "direct",
			"auto_detect_interface": true,
		},
	}
}
