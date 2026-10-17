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
// With a split carrier configured, its CIDR list is rendered as if its relay
// held a lease (LEIKWAN_GATEWAY_SPLIT_POLICY pins its policy).
func printGatewayConfig() int {
	cfg := loadConfig()
	scope := offlineGatewayChoice(os.Getenv("LEIKWAN_GATEWAY_SCOPE"), cfg.GatewayRouteScope, "global")
	policy := offlineGatewayChoice(os.Getenv("LEIKWAN_GATEWAY_POLICY"), cfg.GatewayRoutePolicy, "unrestricted")
	if cfg.GatewayCapture == "cidr" {
		cidrs, err := loadSplitCIDRs(cfg.GatewaySplitCIDRFile)
		if err != nil || len(cidrs) == 0 {
			fmt.Fprintf(os.Stderr, "cidr capture: no usable prefixes in %s (%v)\n", cfg.GatewaySplitCIDRFile, err)
			return 1
		}
		encoded, err := json.MarshalIndent(renderGatewayCIDRConfig(cfg, policy, cidrs), "", "  ")
		if err != nil {
			fmt.Fprintf(os.Stderr, "could not encode the gateway configuration: %v\n", err)
			return 1
		}
		fmt.Println(string(encoded))
		return 0
	}
	var split *gatewaySplitRender
	if cfg.GatewaySplitForwardID > 0 {
		cidrs, err := loadSplitCIDRs(cfg.GatewaySplitCIDRFile)
		if err != nil {
			fmt.Fprintf(os.Stderr, "split carrier #%d is off: %v\n", cfg.GatewaySplitForwardID, err)
		} else if len(cidrs) > 0 {
			split = &gatewaySplitRender{
				Policy: offlineGatewayChoice(os.Getenv("LEIKWAN_GATEWAY_SPLIT_POLICY"), cfg.GatewaySplitRoutePolicy, "unrestricted"),
				CIDRs:  cidrs,
			}
		}
	}
	rendered, _ := renderGatewayConfig(cfg, scope, policy, split)
	encoded, err := json.MarshalIndent(rendered, "", "  ")
	if err != nil {
		fmt.Fprintf(os.Stderr, "could not encode the gateway configuration: %v\n", err)
		return 1
	}
	fmt.Println(string(encoded))
	return 0
}

func offlineGatewayChoice(override, configured, fallback string) string {
	for _, value := range []string{override, configured} {
		if value != "" && value != "auto" {
			return value
		}
	}
	return fallback
}
