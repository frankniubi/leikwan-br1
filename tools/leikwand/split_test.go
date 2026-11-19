package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const (
	splitDirection  = "SHA -> SHIXP"
	pudongDirection = "SHA -> PUDONG"
)

// The split carriers are further native lines next to the primary one; the
// fixtures add carrier 326 (SHA -> SHIXP, global only, unrestricted) and 330
// (SHA -> PUDONG, global only, restricted) to the 180 primary.
func testSplitCapability() *NativeOutboundCapability {
	capability := testCapability(true)
	capability.ForwardIDs = append(capability.ForwardIDs, 326, 330)
	capability.Forwards = append(capability.Forwards,
		NativeOutboundForward{
			ForwardID: 326, Direction: splitDirection, Transport: "tcp+udp",
			RouteScopes: []string{"global"}, DefaultRouteScope: "global", UnrestrictedOK: true,
		},
		NativeOutboundForward{
			ForwardID: 330, Direction: pudongDirection, Transport: "tcp+udp",
			RouteScopes: []string{"global"}, DefaultRouteScope: "global", UnrestrictedOK: false,
		})
	return capability
}

func testSplitForwards(now time.Time) map[int]ForwardConfig {
	forwards := testCarrierForwards(now)
	for id, target := range map[int]string{326: "127.0.0.1:38555", 330: "127.0.0.1:38556"} {
		grant := testGrant(now)
		grant.ForwardID = id
		grant.SessionTicket = "ticket-" + itoa(id)
		grant.TargetDigest = targetDigest(target)
		forwards[id] = ForwardConfig{
			ForwardID: id, BackendMode: "ultra", ServiceStatus: "active", Transport: "tcp+udp",
			Target: target, NativeOutboundEnabled: true, NativeDirection: "sha_split",
			UltraAccess: grant,
		}
	}
	return forwards
}

// splitGatewayConfig is gateway mode with the primary on 180.
func splitGatewayConfig() Config {
	cfg := defaultConfig()
	cfg.Mode = "gateway"
	cfg.GatewayEnabled = true
	cfg.GatewayForwardID = 180
	return cfg
}

func splitPlanFor(t *testing.T, cfg Config) gatewayPlan {
	t.Helper()
	plan, err := buildGatewayPlan(cfg, testSplitCapability(), testSplitForwards(time.Now()))
	if err != nil {
		t.Fatalf("plan: %v", err)
	}
	return plan
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

// The legacy gateway_split_* keys are the implicit first split: position 0,
// port 2081, tag "split", exactly what 1.1.x ran.
func TestGatewaySplitPlan(t *testing.T) {
	list := writeTempFile(t, "shixp.txt", "163.53.52.0/22\n")
	cfg := splitGatewayConfig()
	cfg.GatewaySplitForwardID = 326
	cfg.GatewaySplitCIDRFile = list
	base, err := resolveGatewayPlan(cfg, testSplitCapability(), testSplitForwards(time.Now()))
	if err != nil {
		t.Fatal(err)
	}

	plan := splitPlanFor(t, cfg)
	if len(plan.Splits) != 1 {
		t.Fatalf("splits = %+v", plan.Splits)
	}
	split := plan.Splits[0]
	if !split.active() || split.Index != 0 || split.Name != legacySplitName || split.Plan.Carrier.ForwardID != 326 ||
		split.Plan.Scope != "global" || split.Plan.Policy != "unrestricted" || len(split.CIDRs) != 1 {
		t.Fatalf("split = %+v plan=%+v", split, split.Plan)
	}
	if plan.Carrier.ForwardID != 180 || plan.key() != base.key() {
		t.Fatal("a split carrier must not change the primary plan")
	}

	cfg.GatewaySplitRoutePolicy = "restricted"
	if plan := splitPlanFor(t, cfg); plan.Splits[0].Plan.Policy != "restricted" {
		t.Fatalf("explicit restricted split = %s", plan.Splits[0].Plan.Policy)
	}
	cfg.GatewaySplitRoutePolicy = "auto"

	samePrimary := cfg
	samePrimary.GatewaySplitForwardID = 180
	notGranted := cfg
	notGranted.GatewaySplitForwardID = 999
	emptyList := cfg
	emptyList.GatewaySplitCIDRFile = writeTempFile(t, "empty.txt", "# nothing public\n10.0.0.0/8\n")
	missing := cfg
	missing.GatewaySplitCIDRFile = filepath.Join(t.TempDir(), "missing.txt")
	// Every failure is a note, keeps the split's position and leaves the
	// primary plan intact.
	for name, tc := range map[string]struct {
		cfg        Config
		wantInNote string
	}{
		"same as primary": {samePrimary, "already the primary"},
		"not granted":     {notGranted, "not a native outbound line"},
		"empty list":      {emptyList, "empty"},
		"missing list":    {missing, "missing.txt"},
	} {
		plan := splitPlanFor(t, tc.cfg)
		if len(plan.Splits) != 1 || plan.Splits[0].active() || plan.Splits[0].Index != 0 || !strings.Contains(plan.Splits[0].Off, tc.wantInNote) {
			t.Fatalf("%s: splits=%+v", name, plan.Splits)
		}
		if len(plan.Notes) != 1 || !strings.Contains(plan.Notes[0], tc.wantInNote) || plan.key() != base.key() {
			t.Fatalf("%s: notes=%v", name, plan.Notes)
		}
	}

	// Without mode=gateway the router edition runs one relay and no split,
	// whatever the legacy keys say.
	router := cfg
	router.Mode = "forward"
	router.GatewayEnabled = false
	if plan := splitPlanFor(t, router); len(plan.Splits) != 0 || plan.CaptureCIDRs != nil {
		t.Fatalf("router edition plan grew splits: %+v", plan.Splits)
	}
}

// N splits: the legacy split first, then the 'config split' sections in file
// order. Positions are stable (an off split keeps its port and tag), a
// carrier is used once, and at most maxGatewaySplits are planned.
func TestGatewayNSplitPlan(t *testing.T) {
	shixp := writeTempFile(t, "shixp.txt", "163.53.52.0/22\n1.92.0.0/14\n")
	pudong := writeTempFile(t, "pudong.txt", "114.111.176.0/22\n")
	cfg := splitGatewayConfig()
	cfg.GatewaySplitForwardID = 326
	cfg.GatewaySplitCIDRFile = shixp
	cfg.GatewaySplits = []SplitConfig{
		{Name: "pudong", ForwardID: 330, RoutePolicy: "auto", CIDRFile: pudong},
		{Name: "dup", ForwardID: 326, RoutePolicy: "auto", CIDRFile: pudong},
		{Name: "foreign", ForwardID: 999, RoutePolicy: "auto", CIDRFile: pudong},
	}
	plan := splitPlanFor(t, cfg)
	if len(plan.Splits) != 4 {
		t.Fatalf("splits = %+v", plan.Splits)
	}
	for i, want := range []struct {
		name   string
		active bool
		policy string
		off    string
	}{
		{legacySplitName, true, "unrestricted", ""},
		{"pudong", true, "restricted", ""},
		{"dup", false, "", "already used by split legacy"},
		{"foreign", false, "", "not a native outbound line"},
	} {
		split := plan.Splits[i]
		if split.Index != i || split.Name != want.name || split.active() != want.active || !strings.Contains(split.Off, want.off) {
			t.Fatalf("split %d = %+v, want %+v", i, split, want)
		}
		if want.active && split.Plan.Policy != want.policy {
			t.Fatalf("split %d policy = %s, want %s", i, split.Plan.Policy, want.policy)
		}
	}
	active := plan.activeSplits()
	if len(active) != 2 || active[0].Plan.Carrier.ForwardID != 326 || active[1].Plan.Carrier.ForwardID != 330 {
		t.Fatalf("active splits = %+v", active)
	}

	// The first split going off does not move the second one.
	cfg.GatewaySplitCIDRFile = filepath.Join(t.TempDir(), "gone.txt")
	plan = splitPlanFor(t, cfg)
	if plan.Splits[0].active() || !plan.Splits[1].active() || plan.Splits[1].Index != 1 {
		t.Fatalf("positions moved: %+v", plan.Splits)
	}
	// With the legacy split off its carrier is free, so "dup" (the next split
	// naming 326) carries it from its own position.
	renders := splitRendersFor(plan)
	if len(renders) != 2 || renders[0].Tag != "split-2" || renders[0].Port != 2082 ||
		renders[1].Tag != "split-3" || renders[1].Port != 2083 || plan.Splits[2].Plan.Carrier.ForwardID != 326 {
		t.Fatalf("renders = %+v", renders)
	}

	// Cap: at most maxGatewaySplits positions.
	many := splitGatewayConfig()
	for i := 0; i < maxGatewaySplits+2; i++ {
		many.GatewaySplits = append(many.GatewaySplits, SplitConfig{Name: fmt.Sprintf("s%d", i), ForwardID: 1000 + i, CIDRFile: pudong})
	}
	plan = splitPlanFor(t, many)
	ignored := 0
	for _, note := range plan.Notes {
		if strings.Contains(note, "at most") {
			ignored++
		}
	}
	if len(plan.Splits) != maxGatewaySplits || ignored != 2 {
		t.Fatalf("splits=%d ignored=%d notes=%v", len(plan.Splits), ignored, plan.Notes)
	}
	if splitPort(maxGatewaySplits-1) != 2088 || splitTag(1) != "split-2" || splitDir(0) != "relay-split" || splitOwner(0) != gatewaySplitRelayOwner {
		t.Fatal("split identities")
	}
}

// In cidr capture mode gateway_split_cidr_file is the capture list itself, so
// the legacy split keys are ignored and take no position; named sections
// still add carriers. The capture is the union of every list.
func TestCIDRCaptureWithNamedSplits(t *testing.T) {
	primary := writeTempFile(t, "ixonly.txt", "163.53.52.0/22\n211.161.96.0/24\n")
	pudong := writeTempFile(t, "pudong.txt", "114.111.176.0/22\n163.53.52.0/22\n")
	cfg := splitGatewayConfig()
	cfg.GatewayForwardID = 326
	cfg.GatewayCapture = "cidr"
	cfg.GatewaySplitCIDRFile = primary
	cfg.GatewaySplitForwardID = 180 // legacy key: ignored in cidr mode
	cfg.GatewaySplits = []SplitConfig{{Name: "pudong", ForwardID: 330, RoutePolicy: "auto", CIDRFile: pudong}}
	plan := splitPlanFor(t, cfg)
	if plan.Carrier.ForwardID != 326 || len(plan.CaptureCIDRs) != 2 || len(plan.Splits) != 1 {
		t.Fatalf("plan = %+v", plan)
	}
	if split := plan.Splits[0]; !split.active() || split.Index != 0 || split.Plan.Carrier.ForwardID != 330 {
		t.Fatalf("named split in cidr mode = %+v", split)
	}

	encoded, source, err := renderSingBox(cfg, plan)
	if err != nil || source != "none" {
		t.Fatalf("render: %v source=%s", err, source)
	}
	var config map[string]any
	if err := json.Unmarshal(encoded, &config); err != nil {
		t.Fatal(err)
	}
	inbound := objects(config["inbounds"])[0]
	capture := []string{}
	for _, value := range inbound["route_address"].([]any) {
		capture = append(capture, value.(string))
	}
	if strings.Join(capture, ",") != "163.53.52.0/22,211.161.96.0/24,114.111.176.0/22" {
		t.Fatalf("capture = %v, want the de-duplicated union", capture)
	}
	rules := objects(section(t, config, "route")["rules"])
	if len(rules) != 2 || rules[0]["outbound"] != "split" || rules[1]["outbound"] != "proxy" {
		t.Fatalf("rules = %v", rules)
	}
	// The split is restricted: its rule leaves the low ports to the next
	// rule, here the primary, which is unrestricted.
	if singlePort(rules[0]) != "1000:65535" || singlePort(rules[1]) != "1:65535" {
		t.Fatalf("port ranges = %v / %v", rules[0]["port_range"], rules[1]["port_range"])
	}
	if section(t, config, "route")["final"] != "direct" {
		t.Fatal("cidr capture must fall through to direct")
	}
	if _, ok := config["dns"]; ok {
		t.Fatal("cidr capture must not install a DNS block")
	}
}

func renderSplit(t *testing.T, scope, policy string, splits ...gatewaySplitRender) map[string]any {
	t.Helper()
	cfg := defaultConfig()
	cfg.GatewayRuleSetDir = t.TempDir()
	rendered, _ := renderGatewayConfig(cfg, scope, policy, splits)
	return mustRoundTrip(t, rendered)
}

func singlePort(rule map[string]any) string {
	ports, _ := rule["port_range"].([]any)
	if len(ports) != 1 {
		return ""
	}
	value, _ := ports[0].(string)
	return value
}

func outboundByTag(config map[string]any, tag string) map[string]any {
	for _, outbound := range objects(config["outbounds"]) {
		if outbound["tag"] == tag {
			return outbound
		}
	}
	return nil
}

// The split list goes to its own SOCKS outbound, matched by IP after a
// resolve and ahead of the primary scope, so SHIXP members (mostly mainland
// prefixes) do not fall into the cn-direct rule.
func TestRenderSplitCarrier(t *testing.T) {
	cidrs := []string{"163.53.52.0/22", "1.92.0.0/14"}
	for _, policy := range []string{"unrestricted", "restricted"} {
		config := renderSplit(t, "intl_only", policy, gatewaySplitRender{Policy: "unrestricted", CIDRs: cidrs})
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
		outbound := outboundByTag(config, "split")
		if outbound == nil || outbound["server_port"] != float64(gatewaySplitSocksPort) || outbound["type"] != "socks" {
			t.Fatalf("split outbound = %v", outbound)
		}
	}

	restricted := renderSplit(t, "global", "unrestricted", gatewaySplitRender{Policy: "restricted", CIDRs: cidrs})
	rules := objects(section(t, restricted, "route")["rules"])
	at := ruleIndex(rules, func(r map[string]any) bool { return r["outbound"] == "split" })
	if at < 0 || singlePort(rules[at]) != "1000:65535" {
		t.Fatalf("restricted split rule = %v", rules)
	}

	// Without a split carrier nothing about it is rendered.
	for _, plain := range []map[string]any{
		renderSplit(t, "intl_only", "unrestricted"),
		renderSplit(t, "intl_only", "unrestricted", gatewaySplitRender{Policy: "unrestricted"}),
	} {
		if outboundByTag(plain, "split") != nil {
			t.Fatal("no split outbound without a split carrier")
		}
		if ruleIndex(objects(section(t, plain, "route")["rules"]), func(r map[string]any) bool { return r["outbound"] == "split" }) >= 0 {
			t.Fatal("no split rule without a split carrier")
		}
	}
}

// Two splits: each has its own outbound and port, the rules keep the
// configured order, and a destination in both lists leaves over the first
// split whose policy admits the port (sing-box takes the first match).
func TestRenderTwoSplitsInConfiguredOrder(t *testing.T) {
	shared := "114.111.176.0/22"
	config := renderSplit(t, "intl_only", "unrestricted",
		gatewaySplitRender{Tag: splitTag(0), Port: splitPort(0), Policy: "restricted", CIDRs: []string{"163.53.52.0/22", shared}},
		gatewaySplitRender{Tag: splitTag(1), Port: splitPort(1), Policy: "unrestricted", CIDRs: []string{shared}},
	)
	rules := objects(section(t, config, "route")["rules"])
	first := ruleIndex(rules, func(r map[string]any) bool { return r["outbound"] == "split" })
	second := ruleIndex(rules, func(r map[string]any) bool { return r["outbound"] == "split-2" })
	cnAt := ruleIndex(rules, func(r map[string]any) bool { _, ok := r["rule_set"]; return ok })
	if first < 0 || second != first+1 || cnAt < second {
		t.Fatalf("rule order: split=%d split-2=%d cn=%d", first, second, cnAt)
	}
	// Overlap: port 443 to the shared prefix matches the first rule; port 53
	// is below the restricted floor, so it falls through to split-2.
	if singlePort(rules[first]) != "1000:65535" || singlePort(rules[second]) != "1:65535" {
		t.Fatalf("port ranges = %v / %v", rules[first]["port_range"], rules[second]["port_range"])
	}
	for tag, port := range map[string]int{"split": 2081, "split-2": 2082} {
		if outbound := outboundByTag(config, tag); outbound == nil || outbound["server_port"] != float64(port) {
			t.Fatalf("%s outbound = %v", tag, outbound)
		}
	}
	if outbound := outboundByTag(config, "proxy"); outbound == nil || outbound["server_port"] != float64(gatewaySocksPort) {
		t.Fatalf("primary outbound = %v", outbound)
	}
}

// The relays use their own ports, run directories and bypass owners, and an
// idle split slot never counts as holding a lease.
func TestGatewaySlotsAreIndependent(t *testing.T) {
	manager := newGatewayManager(nil)
	slots := []*relaySlot{&manager.primary, newSplitSlot(0), newSplitSlot(1)}
	ports := map[int]bool{}
	dirs := map[string]bool{}
	owners := map[string]bool{}
	sockets := map[string]bool{}
	for _, slot := range slots {
		ports[slot.port] = true
		dirs[slot.dir] = true
		owners[slot.owner] = true
		sockets[gatewaySlotPaths("/run", slot.dir).controlSocket] = true
	}
	if len(ports) != 3 || len(dirs) != 3 || len(owners) != 3 || len(sockets) != 3 {
		t.Fatalf("slots share resources: %+v %+v %+v", *slots[0], *slots[1], *slots[2])
	}
	if slots[1].port != 2081 || slots[2].port != 2082 || slots[1].role != "split" || slots[2].role != "split-2" {
		t.Fatalf("split slots = %+v / %+v", *slots[1], *slots[2])
	}
	if gatewaySlotPaths("/run", slots[1].dir).controlSocket == gatewayRelayPaths("/run").controlSocket {
		t.Fatal("split relay must not reuse the primary control socket")
	}
	if manager.leaseValid(slots[1], time.Now()) {
		t.Fatal("an idle split slot has no lease")
	}
	if got := manager.splitSlot(2); got.index != 2 || len(manager.splits) != 3 || manager.splits[1].port != 2082 {
		t.Fatalf("splitSlot grows the slots in position order: %+v", manager.splits)
	}
}

// The split rules follow the plan, not the split relays' lease state: a relay
// restart must not change the rendered config (and so restart sing-box).
func TestSplitRenderIgnoresLeaseState(t *testing.T) {
	cfg := splitGatewayConfig()
	cfg.GatewayRuleSetDir = t.TempDir()
	cfg.GatewaySplitForwardID = 326
	cfg.GatewaySplitCIDRFile = writeTempFile(t, "shixp.txt", "163.53.52.0/22\n")
	plan := splitPlanFor(t, cfg)
	renders := splitRendersFor(plan)
	if len(renders) != 1 || renders[0].Policy != "unrestricted" || len(renders[0].CIDRs) != 1 || renders[0].Tag != "split" || renders[0].Port != 2081 {
		t.Fatalf("split render = %+v", renders)
	}
	before, _, err := renderSingBox(cfg, plan)
	if err != nil {
		t.Fatal(err)
	}
	manager := newGatewayManager(nil)
	manager.splitSlot(0).leaseErr = "relay down"
	after, _, _ := renderSingBox(cfg, plan)
	if string(before) != string(after) {
		t.Fatal("the rendered config must not depend on a relay's lease state")
	}

	base, _ := resolveGatewayPlan(cfg, testSplitCapability(), testSplitForwards(time.Now()))
	if len(splitRendersFor(base)) != 0 {
		t.Fatal("no split carrier, no split rule")
	}
	off := plan
	off.Splits = []splitPlan{plan.Splits[0]}
	off.Splits[0].Plan = nil
	off.Splits[0].Off = "stopped by the panel"
	if len(splitRendersFor(off)) != 0 {
		t.Fatal("an off split renders nothing")
	}
}

// gateway-config (offline) numbers the splits exactly like the live plan.
func TestOfflineSplitRendersKeepPositions(t *testing.T) {
	shixp := writeTempFile(t, "shixp.txt", "163.53.52.0/22\n")
	pudong := writeTempFile(t, "pudong.txt", "114.111.176.0/22\n")
	cfg := splitGatewayConfig()
	cfg.GatewaySplitForwardID = 326
	cfg.GatewaySplitCIDRFile = filepath.Join(t.TempDir(), "missing.txt")
	cfg.GatewaySplits = []SplitConfig{{Name: "pudong", ForwardID: 330, RoutePolicy: "restricted", CIDRFile: pudong}}
	renders, notes := offlineSplitRenders(cfg, false)
	if len(renders) != 1 || renders[0].Tag != "split-2" || renders[0].Port != 2082 || renders[0].Policy != "restricted" || len(notes) != 1 {
		t.Fatalf("renders=%+v notes=%v", renders, notes)
	}
	cfg.GatewaySplitCIDRFile = shixp
	renders, _ = offlineSplitRenders(cfg, false)
	if len(renders) != 2 || renders[0].Tag != "split" || renders[1].Tag != "split-2" {
		t.Fatalf("renders = %+v", renders)
	}
	// cidr mode: the legacy list is the capture list, the named split moves
	// to position 0 exactly as resolveGatewaySplits numbers it.
	renders, notes = offlineSplitRenders(cfg, true)
	if len(renders) != 1 || renders[0].Tag != "split" || renders[0].Port != 2081 || len(notes) != 1 || !strings.Contains(notes[0], "ignored") {
		t.Fatalf("cidr renders=%+v notes=%v", renders, notes)
	}
}
