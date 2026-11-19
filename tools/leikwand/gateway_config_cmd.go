package main

import (
	"encoding/json"
	"fmt"
	"os"
)

// printGatewayConfig writes the sing-box configuration gateway mode would
// install, without touching the running system.
//
// It exists for two reasons. An operator can see exactly what the router is
// about to run before enabling transparent routing for the whole LAN, and the
// configuration can be validated by the real sing-box binary
// ("sing-box check -c") in tests instead of only by inspecting a Go struct.
//
// The live scope and policy depend on the Panel grant. Offline, "auto" is
// shown as the most permissive explicit value it can resolve to; the
// LEIKWAN_GATEWAY_SCOPE / LEIKWAN_GATEWAY_POLICY variables pin either one.
// Every configured split carrier is rendered as if its relay held a lease
// (LEIKWAN_GATEWAY_SPLIT_POLICY pins their policy).
func printGatewayConfig() int {
	cfg := loadConfig()
	scope := offlineGatewayChoice(os.Getenv("LEIKWAN_GATEWAY_SCOPE"), cfg.GatewayRouteScope, "global")
	policy := offlineGatewayChoice(os.Getenv("LEIKWAN_GATEWAY_POLICY"), cfg.GatewayRoutePolicy, "unrestricted")
	cidrMode := cfg.GatewayCapture == "cidr"
	splits, notes := offlineSplitRenders(cfg, cidrMode)
	for _, note := range notes {
		fmt.Fprintln(os.Stderr, note)
	}
	var rendered map[string]any
	if cidrMode {
		cidrs, err := loadSplitCIDRs(cfg.GatewaySplitCIDRFile)
		if err != nil || len(cidrs) == 0 {
			fmt.Fprintf(os.Stderr, "cidr capture: no usable prefixes in %s (%v)\n", cfg.GatewaySplitCIDRFile, err)
			return 1
		}
		rendered = renderGatewayCIDRConfig(cfg, policy, cidrs, splits)
	} else {
		rendered, _ = renderGatewayConfig(cfg, scope, policy, splits)
	}
	encoded, err := json.MarshalIndent(rendered, "", "  ")
	if err != nil {
		fmt.Fprintf(os.Stderr, "could not encode the gateway configuration: %v\n", err)
		return 1
	}
	fmt.Println(string(encoded))
	return 0
}

// offlineSplitRenders mirrors resolveGatewaySplits without a Panel grant: a
// split keeps its configured position (and with it its port and tag) even when
// it is off, the legacy split is the capture list in cidr mode, and a split is
// off when its list is unusable or its carrier repeats the primary or an
// earlier split.
func offlineSplitRenders(cfg Config, cidrMode bool) ([]gatewaySplitRender, []string) {
	out := []gatewaySplitRender{}
	notes := []string{}
	used := map[int]bool{}
	index := 0
	for _, split := range cfg.gatewaySplitConfigs() {
		if split.Legacy && cidrMode {
			notes = append(notes, fmt.Sprintf("split carrier #%d is ignored in cidr capture mode", split.ForwardID))
			continue
		}
		label := fmt.Sprintf("split %s (carrier #%d)", split.Name, split.ForwardID)
		if split.Legacy {
			label = fmt.Sprintf("split carrier #%d", split.ForwardID)
		}
		if index >= maxGatewaySplits {
			notes = append(notes, fmt.Sprintf("%s is ignored: at most %d splits are supported", label, maxGatewaySplits))
			continue
		}
		position := index
		index++
		cidrs, err := loadSplitCIDRs(split.CIDRFile)
		reason := ""
		switch {
		case err != nil:
			reason = err.Error()
		case split.ForwardID <= 0:
			reason = "it has no forward_id"
		case cfg.GatewayForwardID > 0 && split.ForwardID == cfg.GatewayForwardID:
			reason = "it is already the primary carrier"
		case used[split.ForwardID]:
			reason = "its carrier is already used by an earlier split"
		case len(cidrs) == 0:
			reason = "the CIDR list is empty"
		}
		if reason != "" {
			notes = append(notes, fmt.Sprintf("%s is off: %s", label, reason))
			continue
		}
		used[split.ForwardID] = true
		out = append(out, gatewaySplitRender{
			Tag:    splitTag(position),
			Port:   splitPort(position),
			Policy: offlineGatewayChoice(os.Getenv("LEIKWAN_GATEWAY_SPLIT_POLICY"), split.RoutePolicy, "unrestricted"),
			CIDRs:  cidrs,
		})
	}
	return out, notes
}

func offlineGatewayChoice(override, configured, fallback string) string {
	for _, value := range []string{override, configured} {
		if value != "" && value != "auto" {
			return value
		}
	}
	return fallback
}
