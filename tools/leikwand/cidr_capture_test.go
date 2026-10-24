package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// cidr capture is the server/PVE mode: the primary carrier carries only the
// listed destinations, the scope is global when the line offers it, and the
// list is mandatory.
func TestCIDRCapturePlan(t *testing.T) {
	now := time.Now()
	list := filepath.Join(t.TempDir(), "shixp.txt")
	if err := os.WriteFile(list, []byte("# SHIXP\n163.53.52.0/22\n211.161.96.0/24\n10.0.0.0/8\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	cfg := defaultConfig()
	cfg.GatewayCapture = "cidr"
	cfg.GatewayForwardID = 326
	cfg.GatewaySplitCIDRFile = list
	base, err := resolveGatewayPlan(cfg, testSplitCapability(), testSplitForwards(now))
	if err != nil {
		t.Fatal(err)
	}
	plan, err := resolveCIDRCapture(cfg, base)
	if err != nil {
		t.Fatal(err)
	}
	if plan.Carrier.ForwardID != 326 || plan.Scope != "global" || strings.Join(plan.CaptureCIDRs, ",") != "163.53.52.0/22,211.161.96.0/24" {
		t.Fatalf("plan = carrier %d scope %s cidrs %v", plan.Carrier.ForwardID, plan.Scope, plan.CaptureCIDRs)
	}

	// A line offering intl_only still captures with the global scope.
	cfg.GatewayForwardID = 180
	base, _ = resolveGatewayPlan(cfg, testSplitCapability(), testSplitForwards(now))
	if plan, _ := resolveCIDRCapture(cfg, base); plan.Scope != "global" {
		t.Fatalf("auto scope in cidr mode = %s, want global", plan.Scope)
	}

	cfg.GatewaySplitForwardID = 326
	if plan, _ := resolveCIDRCapture(cfg, base); len(plan.Notes) != 1 || !strings.Contains(plan.Notes[0], "ignored") {
		t.Fatalf("split carrier in cidr mode must be noted as ignored: %v", plan.Notes)
	}

	cfg.GatewaySplitCIDRFile = filepath.Join(t.TempDir(), "missing.txt")
	if _, err := resolveCIDRCapture(cfg, base); err == nil {
		t.Fatal("a missing list must be an error")
	}
	empty := filepath.Join(t.TempDir(), "empty.txt")
	_ = os.WriteFile(empty, []byte("# nothing\n10.0.0.0/8\n"), 0o600)
	cfg.GatewaySplitCIDRFile = empty
	if _, err := resolveCIDRCapture(cfg, base); err == nil {
		t.Fatal("a list with no usable prefixes must be an error")
	}

	if normalizeGatewayCapture("CIDR") != "cidr" || normalizeGatewayCapture("") != "full" || normalizeGatewayCapture("other") != "full" {
		t.Fatal("capture normalisation")
	}
}

// The cidr config routes only the list into the TUN and leaves DNS alone.
func TestRenderGatewayCIDRConfig(t *testing.T) {
	cidrs := []string{"163.53.52.0/22", "211.161.96.0/24"}
	for _, tc := range []struct{ policy, ports string }{{"unrestricted", "1:65535"}, {"restricted", "1000:65535"}} {
		raw, err := json.Marshal(renderGatewayCIDRConfig(defaultConfig(), tc.policy, cidrs))
		if err != nil {
			t.Fatal(err)
		}
		var config map[string]any
		if err := json.Unmarshal(raw, &config); err != nil {
			t.Fatal(err)
		}
		if _, ok := config["dns"]; ok {
			t.Fatal("cidr capture must not carry a DNS block")
		}
		tun := objects(config["inbounds"])[0]
		routes, _ := tun["route_address"].([]any)
		if tun["auto_route"] != true || len(routes) != 2 || routes[0] != cidrs[0] {
			t.Fatalf("tun = %v", tun)
		}
		route := section(t, config, "route")
		if route["final"] != "direct" {
			t.Fatalf("final = %v", route["final"])
		}
		rules := objects(route["rules"])
		if len(rules) != 1 || rules[0]["outbound"] != "proxy" || singlePort(rules[0]) != tc.ports {
			t.Fatalf("%s rules = %v", tc.policy, rules)
		}
		for _, rule := range rules {
			if rule["action"] == "hijack-dns" || rule["action"] == "sniff" {
				t.Fatal("cidr capture must not hijack DNS or sniff")
			}
		}
	}
}
