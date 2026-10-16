package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// The split carrier is a second native line next to the primary one; the
// fixtures add carrier 326 (SHA -> SHIXP, global only) to the 180 primary.
func testSplitCapability() *NativeOutboundCapability {
	capability := testCapability(true)
	capability.ForwardIDs = append(capability.ForwardIDs, 326)
	capability.Forwards = append(capability.Forwards, NativeOutboundForward{
		ForwardID: 326, Direction: "SHA -> SHIXP", Transport: "tcp+udp",
		RouteScopes: []string{"global"}, DefaultRouteScope: "global", UnrestrictedOK: true,
	})
	return capability
}

func testSplitForwards(now time.Time) map[int]ForwardConfig {
	forwards := testCarrierForwards(now)
	grant := testGrant(now)
	grant.ForwardID = 326
	forwards[326] = ForwardConfig{
		ForwardID: 326, BackendMode: "ultra", ServiceStatus: "active", Transport: "tcp+udp",
		Target: "127.0.0.1:38555", NativeOutboundEnabled: true, NativeDirection: "sha_shixp",
		UltraAccess: grant,
	}
	return forwards
}

// The list steers public destinations only: comments, IPv6, malformed,
// private, loopback and anything broader than /8 are dropped, duplicates
// collapse and host bits are cleared.
func TestParseSplitCIDRs(t *testing.T) {
	got := parseSplitCIDRs(strings.Join([]string{
		"# SHIXP members",
		"1.92.0.0/14",
		"  163.53.55.9/22  # host bits",
		"163.53.52.0/22",
		"10.0.0.0/8",
		"192.168.1.0/24",
		"127.0.0.0/8",
		"0.0.0.0/0",
		"4.0.0.0/7",
		"2400:3200::/32",
		"not-a-cidr",
		"",
	}, "\r\n"))
	want := []string{"1.92.0.0/14", "163.53.52.0/22"}
	if strings.Join(got, ",") != strings.Join(want, ",") {
		t.Fatalf("parsed = %v, want %v", got, want)
	}
}

// The packaged SHIXP list must parse completely: every entry is a public
// IPv4 prefix, so nothing in it may be silently dropped.
func TestPackagedSHIXPListParses(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join("..", "..", "openwrt", "leikwand", "files", "split", "shixp-v4.txt"))
	if err != nil {
		t.Fatalf("packaged list: %v", err)
	}
	entries := 0
	for _, line := range strings.Split(string(raw), "\n") {
		if line = strings.TrimSpace(line); line != "" && !strings.HasPrefix(line, "#") {
			entries++
		}
	}
	if got := parseSplitCIDRs(string(raw)); len(got) != entries || entries < 300 {
		t.Fatalf("parsed %d of %d entries", len(got), entries)
	}
}

func TestGatewaySplitPlan(t *testing.T) {
	now := time.Now()
	cfg := defaultConfig()
	cfg.GatewaySplitForwardID = 326
	base, err := resolveGatewayPlan(cfg, testSplitCapability(), testSplitForwards(now))
	if err != nil {
		t.Fatal(err)
	}
	cidrs := []string{"163.53.52.0/22"}

	plan := resolveGatewaySplit(cfg, testSplitCapability(), testSplitForwards(now), base, cidrs)
	if plan.Split == nil || plan.Split.Carrier.ForwardID != 326 || plan.Split.Scope != "global" || plan.Split.Policy != "unrestricted" {
		t.Fatalf("split = %+v", plan.Split)
	}
	if plan.Carrier.ForwardID != 180 || plan.key() != base.key() || len(plan.SplitCIDRs) != 1 {
		t.Fatal("the split carrier must not change the primary plan")
	}

	cfg.GatewaySplitRoutePolicy = "restricted"
	if plan := resolveGatewaySplit(cfg, testSplitCapability(), testSplitForwards(now), base, cidrs); plan.Split.Policy != "restricted" {
		t.Fatalf("explicit restricted split = %s", plan.Split.Policy)
	}
	cfg.GatewaySplitRoutePolicy = "auto"

	samePrimary := cfg
	samePrimary.GatewaySplitForwardID = 180
	// Every failure is a note and leaves the primary plan intact.
	for name, tc := range map[string]struct {
		cfg        Config
		capability *NativeOutboundCapability
		cidrs      []string
		wantInNote string
	}{
		"same as primary": {samePrimary, testSplitCapability(), cidrs, "already the primary"},
		"not granted":     {cfg, testCapability(true), cidrs, "not a native outbound line"},
		"empty list":      {cfg, testSplitCapability(), nil, "empty"},
	} {
		plan := resolveGatewaySplit(tc.cfg, tc.capability, testSplitForwards(now), base, tc.cidrs)
		if plan.Split != nil || len(plan.Notes) != 1 || !strings.Contains(plan.Notes[0], tc.wantInNote) || plan.key() != base.key() {
			t.Fatalf("%s: split=%v notes=%v", name, plan.Split, plan.Notes)
		}
	}
}

func renderSplit(t *testing.T, scope, policy string, split *gatewaySplitRender) map[string]any {
	t.Helper()
	cfg := defaultConfig()
	cfg.GatewayRuleSetDir = t.TempDir()
	rendered, _ := renderGatewayConfig(cfg, scope, policy, split)
	raw, err := json.Marshal(rendered)
	if err != nil {
		t.Fatal(err)
	}
	var decoded map[string]any
	if err := json.Unmarshal(raw, &decoded); err != nil {
		t.Fatal(err)
	}
	return decoded
}

func singlePort(rule map[string]any) string {
	ports, _ := rule["port_range"].([]any)
	if len(ports) != 1 {
		return ""
	}
	value, _ := ports[0].(string)
	return value
}

// The split list goes to its own SOCKS outbound, matched by IP after a
// resolve and ahead of the primary scope, so SHIXP members (mostly mainland
// prefixes) do not fall into the cn-direct rule.
func TestRenderSplitCarrier(t *testing.T) {
	cidrs := []string{"163.53.52.0/22", "1.92.0.0/14"}
	for _, policy := range []string{"unrestricted", "restricted"} {
		config := renderSplit(t, "intl_only", policy, &gatewaySplitRender{Policy: "unrestricted", CIDRs: cidrs})
		rules := objects(section(t, config, "route")["rules"])
		splitAt := ruleIndex(rules, func(r map[string]any) bool { return r["outbound"] == "split" })
		resolveAt := ruleIndex(rules, func(r map[string]any) bool { return r["action"] == "resolve" })
		cnAt := ruleIndex(rules, func(r map[string]any) bool { _, ok := r["rule_set"]; return ok })
		lowAt := ruleIndex(rules, func(r map[string]any) bool { return singlePort(r) == gatewayLowPortRange })
		if splitAt < 0 || resolveAt < 0 || resolveAt > splitAt || cnAt < splitAt {
			t.Fatalf("%s: resolve=%d split=%d cn=%d", policy, resolveAt, splitAt, cnAt)
		}
		if policy == "restricted" && lowAt < splitAt {
			t.Fatalf("restricted primary: the low-port rule (%d) must not shadow the unrestricted split rule (%d)", lowAt, splitAt)
		}
		resolves := 0
		for _, rule := range rules {
			if rule["action"] == "resolve" {
				resolves++
			}
		}
		if resolves != 1 {
			t.Fatalf("%s: %d resolve actions", policy, resolves)
		}
		if got := singlePort(rules[splitAt]); got != "1:65535" {
			t.Fatalf("unrestricted split ports = %v", rules[splitAt]["port_range"])
		}
		var splitOutbound map[string]any
		for _, outbound := range objects(config["outbounds"]) {
			if outbound["tag"] == "split" {
				splitOutbound = outbound
			}
		}
		if splitOutbound == nil || splitOutbound["server_port"] != float64(gatewaySplitSocksPort) || splitOutbound["type"] != "socks" {
			t.Fatalf("split outbound = %v", splitOutbound)
		}
	}

	restricted := renderSplit(t, "global", "unrestricted", &gatewaySplitRender{Policy: "restricted", CIDRs: cidrs})
	rules := objects(section(t, restricted, "route")["rules"])
	at := ruleIndex(rules, func(r map[string]any) bool { return r["outbound"] == "split" })
	if at < 0 || singlePort(rules[at]) != "1000:65535" {
		t.Fatalf("restricted split rule = %v", rules)
	}

	// Without a valid split lease nothing about it is rendered.
	plain := renderSplit(t, "intl_only", "unrestricted", nil)
	for _, outbound := range objects(plain["outbounds"]) {
		if outbound["tag"] == "split" {
			t.Fatal("no split outbound without a split lease")
		}
	}
	if ruleIndex(objects(section(t, plain, "route")["rules"]), func(r map[string]any) bool { return r["outbound"] == "split" }) >= 0 {
		t.Fatal("no split rule without a split lease")
	}
}

// The two relays use their own ports, run directories and bypass owners, and
// an idle split slot never counts as holding a lease.
func TestGatewaySlotsAreIndependent(t *testing.T) {
	manager := newGatewayManager(nil)
	if manager.primary.port == manager.split.port || manager.primary.dir == manager.split.dir || manager.primary.owner == manager.split.owner {
		t.Fatalf("slots share resources: %+v / %+v", manager.primary, manager.split)
	}
	if gatewaySlotPaths("/run", manager.split.dir).controlSocket == gatewayRelayPaths("/run").controlSocket {
		t.Fatal("split relay must not reuse the primary control socket")
	}
	if manager.leaseValid(&manager.split, time.Now()) {
		t.Fatal("an idle split slot has no lease")
	}
}
