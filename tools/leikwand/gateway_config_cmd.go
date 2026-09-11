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
func printGatewayConfig() int {
	cfg := loadConfig()
	// renderSingBoxConfig only reads policy and paths, so an agent shell is
	// enough; nothing here dials the panel or starts a runtime.
	agent := &Agent{cfg: cfg, log: newLogger("error")}

	scope := cfg.GatewayRouteScope
	policy := cfg.GatewayRoutePolicy
	rendered, err := agent.renderSingBoxConfig(scope, policy)
	if err != nil {
		fmt.Fprintf(os.Stderr, "could not render the gateway configuration: %v\n", err)
		return 1
	}
	encoded, err := json.MarshalIndent(rendered, "", "  ")
	if err != nil {
		fmt.Fprintf(os.Stderr, "could not encode the gateway configuration: %v\n", err)
		return 1
	}
	fmt.Println(string(encoded))
	return 0
}
