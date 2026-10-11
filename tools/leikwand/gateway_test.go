package main

import (
	"encoding/json"
	"strings"
	"testing"
)

func renderConfig(t *testing.T, scope, policy string, bypassCN bool) map[string]any {
	t.Helper()
	cfg := defaultConfig()
	cfg.GatewayRouteScope = scope
	cfg.GatewayRoutePolicy = policy
	cfg.GatewayBypassCN = bypassCN
	agent := &Agent{cfg: cfg, log: newLogger("error")}
	rendered, err := agent.renderSingBoxConfig(scope, policy)
	if err != nil {
		t.Fatalf("render(%s/%s): %v", scope, policy, err)
	}
	// The config must survive a JSON round trip: it is written to disk and read
	// by sing-box, so a value that only exists as a Go type is not enough.
	raw, err := json.Marshal(rendered)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	var decoded map[string]any
	if err := json.Unmarshal(raw, &decoded); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	return decoded
}

func routeOf(t *testing.T, config map[string]any) map[string]any {
	t.Helper()
	route, ok := config["route"].(map[string]any)
	if !ok {
		t.Fatal("config has no route section")
	}
	return route
}

// sing-box 1.12 (the version OpenWrt 24.10 ships) removed the per-rule "geoip"
// field. A config that still uses it fails to load with a FATAL, which would
// make gateway mode silently unusable on a fresh router.
func TestGatewayConfigAvoidsRemovedGeoIPField(t *testing.T) {
	for _, scope := range []string{"global", "cn_only", "intl_only"} {
		config := renderConfig(t, scope, "restricted", true)
		raw, _ := json.Marshal(config)
		if strings.Contains(string(raw), "\"geoip\"") {
			t.Fatalf("%s: config still uses the removed per-rule geoip field", scope)
		}
		ruleSets, ok := routeOf(t, config)["rule_set"].([]any)
		if !ok || len(ruleSets) == 0 {
			t.Fatalf("%s: mainland matching must be expressed with rule_set", scope)
		}
		tags := map[string]bool{}
		for _, entry := range ruleSets {
			if object, ok := entry.(map[string]any); ok {
				if tag, _ := object["tag"].(string); tag != "" {
					tags[tag] = true
				}
			}
		}
		for _, want := range []string{gatewayCNRuleSet, gatewayCNIPRuleSet} {
			if !tags[want] {
				t.Fatalf("%s: rule_set %q is missing (tags: %v)", scope, want, tags)
			}
		}
	}
}

// The scope decides where mainland traffic goes. Getting this backwards would
// send a cn_only router's domestic traffic through the relay, or an intl_only
// router's foreign traffic direct — both silent, expensive mistakes.
func TestGatewayScopeSemantics(t *testing.T) {
	cases := []struct {
		scope     string
		final     string
		cnOutbond string
	}{
		{"global", "proxy", "direct"},
		{"cn_only", "direct", "proxy"},
		{"intl_only", "proxy", "direct"},
	}
	for _, tc := range cases {
		config := renderConfig(t, tc.scope, "restricted", true)
		route := routeOf(t, config)
		if got, _ := route["final"].(string); got != tc.final {
			t.Fatalf("%s: final outbound = %q, want %q", tc.scope, got, tc.final)
		}
		rules, _ := route["rules"].([]any)
		cnOutbound := ""
		for _, entry := range rules {
			object, ok := entry.(map[string]any)
			if !ok {
				continue
			}
			sets, _ := object["rule_set"].([]any)
			for _, tag := range sets {
				if tag == gatewayCNRuleSet {
					cnOutbound, _ = object["outbound"].(string)
				}
			}
		}
		if cnOutbound != tc.cnOutbond {
			t.Fatalf("%s: mainland rule outbound = %q, want %q", tc.scope, cnOutbound, tc.cnOutbond)
		}
	}
}

// bypass_cn only refines the global scope; it must not change what cn_only and
// intl_only mean.
func TestGatewayBypassOnlyAffectsGlobalScope(t *testing.T) {
	withBypass := routeOf(t, renderConfig(t, "global", "restricted", true))
	without := routeOf(t, renderConfig(t, "global", "restricted", false))
	if withBypass["final"] != without["final"] {
		t.Fatal("bypass_cn must not change the catch-all outbound")
	}
	countCN := func(route map[string]any) int {
		rules, _ := route["rules"].([]any)
		found := 0
		for _, entry := range rules {
			object, ok := entry.(map[string]any)
			if !ok {
				continue
			}
			if sets, ok := object["rule_set"].([]any); ok && len(sets) > 0 {
				found++
			}
		}
		return found
	}
	if countCN(withBypass) == 0 {
		t.Fatal("bypass_cn enabled but no mainland bypass rule was emitted")
	}
	if countCN(without) != 0 {
		t.Fatal("bypass_cn disabled but a mainland bypass rule was still emitted")
	}

	// Private destinations are never tunnelled, in any scope.
	for _, scope := range []string{"global", "cn_only", "intl_only"} {
		rules, _ := routeOf(t, renderConfig(t, scope, "restricted", true))["rules"].([]any)
		found := false
		for _, entry := range rules {
			if object, ok := entry.(map[string]any); ok && object["ip_is_private"] == true {
				found = true
			}
		}
		if !found {
			t.Fatalf("%s: private destinations must bypass the relay", scope)
		}
	}
}

// The DNS block must use the modern server form; the legacy string "address"
// field parses in 1.12 but is removed in 1.14.
func TestGatewayDNSUsesModernServerForm(t *testing.T) {
	config := renderConfig(t, "global", "restricted", true)
	dns, ok := config["dns"].(map[string]any)
	if !ok {
		t.Fatal("config has no dns section")
	}
	servers, _ := dns["servers"].([]any)
	if len(servers) < 2 {
		t.Fatalf("expected a remote and a local resolver, got %d", len(servers))
	}
	detours := map[string]bool{}
	for _, entry := range servers {
		object, ok := entry.(map[string]any)
		if !ok {
			continue
		}
		if _, legacy := object["address"]; legacy {
			t.Fatal("dns server still uses the legacy \"address\" field")
		}
		if object["type"] != "udp" || object["server"] == nil {
			t.Fatalf("dns server is not in the modern form: %v", object)
		}
		if detour, _ := object["detour"].(string); detour != "" {
			detours[detour] = true
		}
	}
	if !detours["proxy"] || !detours["direct"] {
		t.Fatalf("both a proxied and a direct resolver are required, got %v", detours)
	}
}

// The DNS policy has to follow the routing policy. Resolving a mainland name
// through the relay while routing mainland traffic direct hands the client an
// address that then takes the other path.
func TestGatewayDNSPolicyFollowsRoutingPolicy(t *testing.T) {
	dnsRoutesMainlandLocally := func(config map[string]any) bool {
		dns, _ := config["dns"].(map[string]any)
		rules, _ := dns["rules"].([]any)
		for _, entry := range rules {
			object, ok := entry.(map[string]any)
			if !ok {
				continue
			}
			if object["server"] == "local" && object["rule_set"] != nil {
				return true
			}
		}
		return false
	}

	cases := []struct {
		scope     string
		bypassCN  bool
		wantLocal bool
	}{
		{"global", true, true},    // mainland routed direct -> resolve locally
		{"global", false, false},  // everything proxied -> resolve remotely
		{"intl_only", true, true}, // mainland routed direct
		{"cn_only", true, false},  // mainland routed via the relay
	}
	for _, tc := range cases {
		config := renderConfig(t, tc.scope, "restricted", tc.bypassCN)
		if got := dnsRoutesMainlandLocally(config); got != tc.wantLocal {
			t.Fatalf("%s (bypass_cn=%v): mainland resolved locally = %v, want %v",
				tc.scope, tc.bypassCN, got, tc.wantLocal)
		}
	}
}

// The deprecated "outbound" DNS matcher is removed in sing-box 1.14.
func TestGatewayDNSAvoidsDeprecatedOutboundMatcher(t *testing.T) {
	config := renderConfig(t, "global", "restricted", true)
	dns, _ := config["dns"].(map[string]any)
	rules, _ := dns["rules"].([]any)
	for _, entry := range rules {
		if object, ok := entry.(map[string]any); ok {
			if _, deprecated := object["outbound"]; deprecated {
				t.Fatalf("dns rule still uses the deprecated outbound matcher: %v", object)
			}
		}
	}
}

// sing-box 1.12 requires route.default_domain_resolver; omitting it is
// deprecated and is removed in 1.14.
func TestGatewayConfigDeclaresDefaultDomainResolver(t *testing.T) {
	config := renderConfig(t, "global", "restricted", true)
	route := routeOf(t, config)
	resolver, ok := route["default_domain_resolver"]
	if !ok || resolver == nil || resolver == "" {
		t.Fatalf("route.default_domain_resolver is not set: %v", route["default_domain_resolver"])
	}
	// It must point at a resolver the dns section actually defines, or
	// sing-box would have nothing to fall back to.
	dns, _ := config["dns"].(map[string]any)
	servers, _ := dns["servers"].([]any)
	known := false
	for _, entry := range servers {
		if object, ok := entry.(map[string]any); ok && object["tag"] == resolver {
			known = true
		}
	}
	if !known {
		t.Fatalf("default_domain_resolver %q does not match any dns server tag", resolver)
	}
}

func TestGatewayConfigBindsTheLocalFacade(t *testing.T) {
	config := renderConfig(t, "global", "restricted", true)
	outbounds, _ := config["outbounds"].([]any)
	socksFound := false
	for _, entry := range outbounds {
		object, ok := entry.(map[string]any)
		if !ok || object["type"] != "socks" {
			continue
		}
		socksFound = true
		if object["server"] != "127.0.0.1" {
			t.Fatalf("the SOCKS outbound must stay on loopback, got %v", object["server"])
		}
		if int(object["server_port"].(float64)) != gatewaySocksPort {
			t.Fatalf("SOCKS port = %v, want %d", object["server_port"], gatewaySocksPort)
		}
	}
	if !socksFound {
		t.Fatal("no SOCKS outbound: LAN traffic would have nowhere to go")
	}

	inbounds, _ := config["inbounds"].([]any)
	if len(inbounds) != 1 {
		t.Fatalf("expected exactly one inbound, got %d", len(inbounds))
	}
	tun, _ := inbounds[0].(map[string]any)
	if tun["type"] != "tun" || tun["auto_route"] != true {
		t.Fatalf("the inbound must be an auto-routing tun: %v", tun)
	}
	if tun["strict_route"] == true {
		t.Fatal("strict_route would break clients that pin their own routes")
	}
}

func TestSplitHostPortDefault(t *testing.T) {
	cases := []struct {
		in       string
		wantHost string
		wantPort int
	}{
		{"1.1.1.1", "1.1.1.1", 53},
		{"1.1.1.1:5353", "1.1.1.1", 5353},
		{"223.5.5.5:53", "223.5.5.5", 53},
		{"", "", 53},
		{"[::1]", "::1", 53},
		{"[::1]:5353", "::1", 5353},
		{"2606:4700:4700::1111", "2606:4700:4700::1111", 53},
		{"1.1.1.1:not-a-port", "1.1.1.1", 53},
		{"1.1.1.1:99999", "1.1.1.1", 53},
	}
	for _, tc := range cases {
		host, port := splitHostPortDefault(tc.in, 53)
		if host != tc.wantHost || port != tc.wantPort {
			t.Fatalf("splitHostPortDefault(%q) = (%q,%d), want (%q,%d)", tc.in, host, port, tc.wantHost, tc.wantPort)
		}
	}
}
