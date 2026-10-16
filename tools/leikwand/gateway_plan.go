package main

import (
	"errors"
	"fmt"
	"strings"
)

// gatewayPlan is everything that decides what the native relay and sing-box
// run: which carrier, with which scope and route policy.
type gatewayPlan struct {
	Carrier ForwardConfig
	Native  NativeOutboundForward
	Scope   string
	Policy  string
	// Notes explain a downgrade from what the operator asked for.
	Notes []string
}

func (p gatewayPlan) key() string {
	return strings.Join([]string{itoa(p.Carrier.ForwardID), p.Native.Direction, p.Scope, p.Policy}, "|")
}

var errNativeNotGranted = errors.New("panel has not granted native outbound for this device")

// gatewayPlan resolves the operator's UCI choice against what the Panel
// granted. An explicit carrier that the Panel does not offer is an error, not
// a silent switch; an unavailable scope or policy falls back and says so.
func (a *Agent) gatewayPlan() (gatewayPlan, error) {
	a.mu.Lock()
	capability := a.nativeCap
	forwards := make(map[int]ForwardConfig, len(a.nativeForwards))
	for id, forward := range a.nativeForwards {
		forwards[id] = forward
	}
	cfg := a.cfg
	a.mu.Unlock()
	return resolveGatewayPlan(cfg, capability, forwards)
}

func resolveGatewayPlan(cfg Config, capability *NativeOutboundCapability, forwards map[int]ForwardConfig) (gatewayPlan, error) {
	if capability == nil || !capability.Enabled {
		return gatewayPlan{}, errNativeNotGranted
	}
	if !strings.EqualFold(strings.TrimSpace(capability.Protocol), nativeCapabilityProtocol) ||
		strings.TrimSpace(capability.LeaseProtocol) != nativeLeaseProtocol ||
		!strings.EqualFold(strings.TrimSpace(capability.Transport), "tcp+udp") {
		return gatewayPlan{}, fmt.Errorf("unsupported native outbound capability (protocol=%s lease_protocol=%s)",
			capability.Protocol, capability.LeaseProtocol)
	}

	id := cfg.GatewayForwardID
	if id > 0 {
		if _, ok := capability.carrier(id); !ok {
			return gatewayPlan{}, fmt.Errorf("configured carrier #%d is not a native outbound line on this account", id)
		}
	} else {
		if capability.DefaultForwardID != nil {
			if _, ok := capability.carrier(*capability.DefaultForwardID); ok {
				id = *capability.DefaultForwardID
			}
		}
		if id <= 0 && len(capability.Forwards) > 0 {
			id = capability.Forwards[0].ForwardID
		}
		if id <= 0 {
			return gatewayPlan{}, errors.New("no native outbound carrier assigned")
		}
	}
	native, _ := capability.carrier(id)
	carrier, ok := forwards[id]
	if !ok {
		return gatewayPlan{}, fmt.Errorf("native carrier #%d is not in this device's forward list", id)
	}
	if strings.TrimSpace(native.Direction) == "" || !strings.EqualFold(strings.TrimSpace(native.Transport), "tcp+udp") {
		return gatewayPlan{}, fmt.Errorf("native carrier #%d has no usable direction", id)
	}

	plan := gatewayPlan{Carrier: carrier, Native: native}
	plan.Scope, plan.Notes = resolveGatewayScope(cfg.GatewayRouteScope, native, plan.Notes)
	plan.Policy, plan.Notes = resolveGatewayPolicy(cfg.GatewayRoutePolicy, native, plan.Notes)
	return plan, nil
}

func nativeScopesOf(native NativeOutboundForward) []string {
	if len(native.RouteScopes) == 0 {
		return []string{"global"}
	}
	return native.RouteScopes
}

// resolveGatewayScope: "auto" picks the split the line's direction implies.
// An international->mainland line splits cn_only (a router abroad reaching
// China), a mainland->international line splits intl_only (a router in China
// reaching the world); a line with neither only offers global.
func resolveGatewayScope(want string, native NativeOutboundForward, notes []string) (string, []string) {
	allowed := nativeScopesOf(native)
	want = strings.ToLower(strings.TrimSpace(want))
	if want != "" && want != "auto" {
		if containsString(allowed, want) {
			return want, notes
		}
		notes = append(notes, fmt.Sprintf("route scope %s is not offered by this line (%s); using auto", want, strings.Join(allowed, ",")))
	}
	for _, preferred := range []string{"cn_only", "intl_only"} {
		if containsString(allowed, preferred) {
			return preferred, notes
		}
	}
	if native.DefaultRouteScope != "" && containsString(allowed, native.DefaultRouteScope) {
		return native.DefaultRouteScope, notes
	}
	return allowed[0], notes
}

// resolveGatewayPolicy: "auto" uses the unrestricted full route whenever the
// plan grants it (allow_native_outbound_unrestricted).
func resolveGatewayPolicy(want string, native NativeOutboundForward, notes []string) (string, []string) {
	switch strings.ToLower(strings.TrimSpace(want)) {
	case "unrestricted":
		if native.UnrestrictedOK {
			return "unrestricted", notes
		}
		return "restricted", append(notes, "the plan does not grant the unrestricted full route; using restricted")
	case "restricted":
		return "restricted", notes
	default:
		if native.UnrestrictedOK {
			return "unrestricted", notes
		}
		return "restricted", notes
	}
}

func containsString(values []string, wanted string) bool {
	for _, value := range values {
		if value == wanted {
			return true
		}
	}
	return false
}
