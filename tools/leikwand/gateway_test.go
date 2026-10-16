package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func renderConfig(t *testing.T, scope, policy string, bypassCN bool) map[string]any {
	t.Helper()
	cfg := defaultConfig()
	cfg.GatewayBypassCN = bypassCN
	cfg.GatewayRuleSetDir = t.TempDir()
	return renderConfigWith(t, cfg, scope, policy)
}

func renderConfigWith(t *testing.T, cfg Config, scope, policy string) map[string]any {
	t.Helper()
	rendered, _ := renderGatewayConfig(cfg, scope, policy)
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

func section(t *testing.T, config map[string]any, name string) map[string]any {
	t.Helper()
	value, ok := config[name].(map[string]any)
	if !ok {
		t.Fatalf("config has no %s section", name)
	}
	return value
}

func objects(value any) []map[string]any {
	list, _ := value.([]any)
	out := []map[string]any{}
	for _, entry := range list {
		if object, ok := entry.(map[string]any); ok {
			out = append(out, object)
		}
	}
	return out
}

func dnsServer(t *testing.T, config map[string]any, tag string) map[string]any {
	t.Helper()
	for _, server := range objects(section(t, config, "dns")["servers"]) {
		if server["tag"] == tag {
			return server
		}
	}
	return nil
}

func ruleIndex(rules []map[string]any, match func(map[string]any) bool) int {
	for i, rule := range rules {
		if match(rule) {
			return i
		}
	}
	return -1
}

func usesCNRuleSet(rule map[string]any) bool {
	sets, _ := rule["rule_set"].([]any)
	for _, tag := range sets {
		if tag == gatewayCNRuleSet {
			return true
		}
	}
	return false
}

var allScopes = []string{"global", "cn_only", "intl_only"}
var allPolicies = []string{"restricted", "unrestricted"}

// sing-box 1.12 (OpenWrt 24.10) removed the per-rule "geoip" field; a config
// that still uses it fails to load with a FATAL.
func TestGatewayConfigAvoidsRemovedGeoIPField(t *testing.T) {
	for _, scope := range allScopes {
		raw, _ := json.Marshal(renderConfig(t, scope, "restricted", true))
		if strings.Contains(string(raw), "\"geoip\"") {
			t.Fatalf("%s: config still uses the removed per-rule geoip field", scope)
		}
	}
}

// The scope decides where mainland traffic goes. Getting this backwards would
// send a cn_only router's foreign traffic through a mainland exit, or an
// intl_only router's domestic traffic abroad.
func TestGatewayScopeSemantics(t *testing.T) {
	cases := []struct{ scope, final, mainland string }{
		{"global", "proxy", "direct"},
		{"cn_only", "direct", "proxy"},
		{"intl_only", "proxy", "direct"},
	}
	for _, policy := range allPolicies {
		for _, tc := range cases {
			route := section(t, renderConfig(t, tc.scope, policy, true), "route")
			if route["final"] != tc.final {
				t.Fatalf("%s/%s: final = %v, want %s", tc.scope, policy, route["final"], tc.final)
			}
			rules := objects(route["rules"])
			i := ruleIndex(rules, usesCNRuleSet)
			if i < 0 || rules[i]["outbound"] != tc.mainland {
				t.Fatalf("%s/%s: mainland rule = %v, want %s", tc.scope, policy, rules, tc.mainland)
			}
		}
	}
}

func TestGatewayBypassOnlyAffectsGlobalScope(t *testing.T) {
	without := objects(section(t, renderConfig(t, "global", "unrestricted", false), "route")["rules"])
	if ruleIndex(without, usesCNRuleSet) >= 0 {
		t.Fatal("bypass_cn disabled but a mainland rule was still emitted")
	}
	for _, scope := range []string{"cn_only", "intl_only"} {
		rules := objects(section(t, renderConfig(t, scope, "unrestricted", false), "route")["rules"])
		if ruleIndex(rules, usesCNRuleSet) < 0 {
			t.Fatalf("%s: bypass_cn=0 must not remove the scope's own mainland rule", scope)
		}
	}
}

// Restricted leases reject every port below 1000 at the relay, DoH's 443
// included. No resolver may detour through the relay, and low ports are
// routed direct. (MacNativeRuleSetStoreTests.RestrictedDnsDoesNotRequestBlockedLowPortsFromNativeRelay)
func TestRestrictedDNSNeverUsesTheRelay(t *testing.T) {
	for _, scope := range allScopes {
		config := renderConfig(t, scope, "restricted", true)
		for _, server := range objects(section(t, config, "dns")["servers"]) {
			if _, detour := server["detour"]; detour {
				t.Fatalf("%s: restricted resolver %v detours through the relay", scope, server["tag"])
			}
		}
		rules := objects(section(t, config, "route")["rules"])
		low := ruleIndex(rules, func(rule map[string]any) bool {
			ports, _ := rule["port_range"].([]any)
			return len(ports) == 1 && ports[0] == gatewayLowPortRange && rule["outbound"] == "direct"
		})
		if low < 0 {
			t.Fatalf("%s: restricted route must keep ports 1-999 direct", scope)
		}
		if i := ruleIndex(rules, usesCNRuleSet); i >= 0 && i < low {
			t.Fatalf("%s: the low-port rule must precede the scope rule", scope)
		}
	}
}

// The unrestricted route carries protected DoH through the relay; cn_only
// resolves mainland names through the relay's mainland exit instead.
func TestUnrestrictedDNSIsProtectedOverTheRelay(t *testing.T) {
	for _, scope := range []string{"global", "intl_only"} {
		remote := dnsServer(t, renderConfig(t, scope, "unrestricted", true), "remote")
		if remote == nil || remote["type"] != "https" || remote["server"] != cloudflareDoHAddress || remote["detour"] != "proxy" {
			t.Fatalf("%s: remote resolver = %v", scope, remote)
		}
	}
	config := renderConfig(t, "cn_only", "unrestricted", true)
	if remote := dnsServer(t, config, "remote"); remote == nil || remote["detour"] != nil || remote["server"] != cloudflareDoHAddress {
		t.Fatalf("cn_only: international names resolve directly abroad, got %v", remote)
	}
	china := dnsServer(t, config, "china")
	if china == nil || china["server"] != aliDoHAddress || china["detour"] != "proxy" {
		t.Fatalf("cn_only: mainland names must resolve via AliDNS through the relay, got %v", china)
	}
	rules := objects(section(t, config, "dns")["rules"])
	if ruleIndex(rules, func(r map[string]any) bool { return usesCNRuleSet(r) && r["server"] == "china" }) < 0 {
		t.Fatalf("cn_only: geosite-cn must use the china resolver: %v", rules)
	}
}

// Mainland names resolve locally exactly when mainland traffic is direct.
func TestGatewayDNSPolicyFollowsRoutingPolicy(t *testing.T) {
	cases := []struct {
		scope     string
		bypassCN  bool
		wantLocal bool
	}{
		{"global", true, true},
		{"global", false, false},
		{"intl_only", false, true},
		{"cn_only", true, false},
	}
	for _, tc := range cases {
		rules := objects(section(t, renderConfig(t, tc.scope, "unrestricted", tc.bypassCN), "dns")["rules"])
		got := ruleIndex(rules, func(r map[string]any) bool { return usesCNRuleSet(r) && r["server"] == "local" }) >= 0
		if got != tc.wantLocal {
			t.Fatalf("%s (bypass_cn=%v): mainland resolved locally = %v, want %v", tc.scope, tc.bypassCN, got, tc.wantLocal)
		}
	}
}

// On the router the system resolver is dnsmasq, whose upstream query is
// hijacked back into sing-box: a "local"-type resolver would loop.
func TestGatewayResolversAreExplicitAndModern(t *testing.T) {
	for _, scope := range allScopes {
		for _, policy := range allPolicies {
			config := renderConfig(t, scope, policy, true)
			dns := section(t, config, "dns")
			if dns["strategy"] != "ipv4_only" {
				t.Fatalf("%s/%s: strategy = %v", scope, policy, dns["strategy"])
			}
			for _, server := range objects(dns["servers"]) {
				if _, legacy := server["address"]; legacy {
					t.Fatal("dns server still uses the legacy \"address\" field")
				}
				if server["type"] == "local" {
					t.Fatalf("%s/%s: a system resolver would recurse through dnsmasq", scope, policy)
				}
			}
			for _, rule := range objects(dns["rules"]) {
				if _, deprecated := rule["outbound"]; deprecated {
					t.Fatalf("dns rule uses the deprecated outbound matcher: %v", rule)
				}
			}
			route := section(t, config, "route")
			if resolver, _ := route["default_domain_resolver"].(string); dnsServer(t, config, resolver) == nil {
				t.Fatalf("%s/%s: default_domain_resolver %q is not a dns server", scope, policy, resolver)
			}
		}
	}
}

// DNS from the LAN (and dnsmasq's upstream) must be hijacked before the
// private-address rule: the upstream is often a LAN-side modem.
func TestGatewayHijacksDNSBeforePrivateBypass(t *testing.T) {
	rules := objects(section(t, renderConfig(t, "intl_only", "unrestricted", true), "route")["rules"])
	hijack := ruleIndex(rules, func(r map[string]any) bool { return r["action"] == "hijack-dns" })
	private := ruleIndex(rules, func(r map[string]any) bool { return r["ip_is_private"] == true })
	sniff := ruleIndex(rules, func(r map[string]any) bool { return r["action"] == "sniff" })
	if sniff != 0 || hijack < 0 || private < 0 || hijack > private {
		t.Fatalf("rule order sniff=%d hijack=%d private=%d", sniff, hijack, private)
	}
}

// Resolve before the SOCKS handoff on unrestricted or split routes, like the
// desktop; a restricted global route hands the domain to the relay.
func TestGatewayResolvesBeforeHandoff(t *testing.T) {
	for _, scope := range allScopes {
		for _, policy := range allPolicies {
			rules := objects(section(t, renderConfig(t, scope, policy, true), "route")["rules"])
			got := ruleIndex(rules, func(r map[string]any) bool { return r["action"] == "resolve" }) >= 0
			want := policy == "unrestricted" || scope != "global"
			if got != want {
				t.Fatalf("%s/%s: resolve action present = %v, want %v", scope, policy, got, want)
			}
		}
	}
}

// The Panel and the runtime's ingress-zone probe must leave directly: the
// probe would otherwise report the exit's zone and skew line selection.
func TestGatewayKeepsControlPlaneAndZoneProbeDirect(t *testing.T) {
	config := renderConfig(t, "global", "unrestricted", true)
	want := []string{"v3.leikwanhost.com", "api-leikwanbridge.nbwly.com", "api.bilibili.com"}
	rules := objects(section(t, config, "route")["rules"])
	i := ruleIndex(rules, func(r map[string]any) bool { return r["domain"] != nil && r["outbound"] == "direct" })
	if i < 0 {
		t.Fatal("no direct rule for the control plane")
	}
	raw, _ := json.Marshal(rules[i]["domain"])
	for _, host := range want {
		if !strings.Contains(string(raw), "\""+host+"\"") {
			t.Fatalf("direct hosts %s miss %s", raw, host)
		}
	}
	if resolve := ruleIndex(rules, func(r map[string]any) bool { return r["action"] == "resolve" }); resolve < i {
		t.Fatal("control-plane bypass must precede the resolve action")
	}
	dnsRules := objects(section(t, config, "dns")["rules"])
	if ruleIndex(dnsRules, func(r map[string]any) bool { return r["domain"] != nil && r["server"] == "local" }) < 0 {
		t.Fatal("control-plane names must resolve locally")
	}
}

func TestGatewayCustomDNSUsesUDPOnTheAllowedPath(t *testing.T) {
	cfg := defaultConfig()
	cfg.GatewayRuleSetDir = t.TempDir()
	cfg.GatewayDNSMode = "custom"
	cfg.GatewayDNSServers = "8.8.8.8, 9.9.9.9:5353"
	config := renderConfigWith(t, cfg, "intl_only", "unrestricted")
	first := dnsServer(t, config, "custom-0")
	second := dnsServer(t, config, "custom-1")
	if first == nil || first["type"] != "udp" || first["detour"] != "proxy" || second == nil || second["server_port"] != float64(5353) {
		t.Fatalf("custom servers = %v %v", first, second)
	}
	if section(t, config, "dns")["final"] != "custom-0" {
		t.Fatal("custom mode must make the first custom server final")
	}
	if restricted := dnsServer(t, renderConfigWith(t, cfg, "intl_only", "restricted"), "custom-0"); restricted["detour"] != nil {
		t.Fatal("restricted custom DNS must not use the relay")
	}
	// cn_only keeps its two-resolver design, as on Windows.
	if dnsServer(t, renderConfigWith(t, cfg, "cn_only", "unrestricted"), "custom-0") != nil {
		t.Fatal("cn_only must ignore custom servers")
	}
}

func TestGatewayPrefersBundledRuleSets(t *testing.T) {
	cfg := defaultConfig()
	dir := t.TempDir()
	cfg.GatewayRuleSetDir = dir
	if _, source := renderGatewayConfig(cfg, "intl_only", "unrestricted"); source != "remote" {
		t.Fatalf("empty directory must fall back to remote, got %s", source)
	}
	for _, name := range []string{"geoip-cn.srs", "geosite-cn.srs"} {
		if err := os.WriteFile(filepath.Join(dir, name), []byte("SRS\x01payload"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	config := renderConfigWith(t, cfg, "intl_only", "unrestricted")
	for _, set := range objects(section(t, config, "route")["rule_set"]) {
		if set["type"] != "local" || set["url"] != nil || set["download_detour"] != nil {
			t.Fatalf("bundled rule-set must be local and never downloaded: %v", set)
		}
	}
}

func TestGatewayConfigBindsTheLocalFacade(t *testing.T) {
	config := renderConfig(t, "global", "unrestricted", true)
	outbounds := objects(config["outbounds"])
	socks := outbounds[ruleIndex(outbounds, func(o map[string]any) bool { return o["type"] == "socks" })]
	if socks["server"] != "127.0.0.1" || socks["server_port"] != float64(gatewaySocksPort) {
		t.Fatalf("SOCKS outbound = %v", socks)
	}
	if uot, _ := socks["udp_over_tcp"].(map[string]any); uot["enabled"] != false {
		t.Fatal("native UDP must stay real UDP")
	}
	inbounds := objects(config["inbounds"])
	if len(inbounds) != 1 {
		t.Fatalf("expected exactly one inbound, got %d", len(inbounds))
	}
	tun := inbounds[0]
	if tun["type"] != "tun" || tun["auto_route"] != true || tun["strict_route"] == true {
		t.Fatalf("the inbound must be a non-strict auto-routing tun: %v", tun)
	}
	// The relay bypass rules only work if they sit in front of sing-box's.
	if index, _ := tun["iproute2_rule_index"].(float64); int(index) <= gatewayBypassRulePref {
		t.Fatalf("iproute2_rule_index %v must exceed the bypass preference %d", tun["iproute2_rule_index"], gatewayBypassRulePref)
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
