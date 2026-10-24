package main

import (
	"errors"
	"fmt"
	"net"
	"os"
	"strings"
)

// carrierPlan is what one native relay runs: which carrier, with which scope
// and route policy.
type carrierPlan struct {
	Carrier ForwardConfig
	Native  NativeOutboundForward
	Scope   string
	Policy  string
}

func (p carrierPlan) key() string {
	return strings.Join([]string{itoa(p.Carrier.ForwardID), p.Native.Direction, p.Scope, p.Policy}, "|")
}

// gatewayPlan is everything that decides what the native relays and sing-box
// run: the primary carrier and, optionally, a split carrier that only the
// destinations in SplitCIDRs use.
type gatewayPlan struct {
	carrierPlan
	Split      *carrierPlan
	SplitCIDRs []string
	// CaptureCIDRs is set in cidr capture mode: the primary carrier carries
	// only these destinations and nothing else on the box is touched.
	CaptureCIDRs []string
	// Notes explain a downgrade from what the operator asked for.
	Notes []string
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
	plan, err := resolveGatewayPlan(cfg, capability, forwards)
	if err != nil {
		return plan, err
	}
	if cfg.GatewayCapture == "cidr" {
		return resolveCIDRCapture(cfg, plan)
	}
	if cfg.GatewaySplitForwardID <= 0 {
		return plan, nil
	}
	cidrs, loadErr := loadSplitCIDRs(cfg.GatewaySplitCIDRFile)
	if loadErr != nil {
		plan.Notes = append(plan.Notes, fmt.Sprintf("split carrier #%d is off: %v", cfg.GatewaySplitForwardID, loadErr))
		return plan, nil
	}
	return resolveGatewaySplit(cfg, capability, forwards, plan, cidrs), nil
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

	plan := gatewayPlan{carrierPlan: carrierPlan{Carrier: carrier, Native: native}}
	plan.Scope, plan.Notes = resolveGatewayScope(cfg.GatewayRouteScope, native, plan.Notes)
	plan.Policy, plan.Notes = resolveGatewayPolicy(cfg.GatewayRoutePolicy, native, plan.Notes)
	return plan, nil
}

// resolveCIDRCapture turns the plan into a CIDR-only capture: the list is
// required (an empty or unreadable list would capture nothing, which is an
// error rather than a silently idle gateway), the scope is global whenever the
// line offers it (the router, not the line, narrows the destinations), and a
// split carrier has no meaning here.
func resolveCIDRCapture(cfg Config, plan gatewayPlan) (gatewayPlan, error) {
	cidrs, err := loadSplitCIDRs(cfg.GatewaySplitCIDRFile)
	if err != nil {
		return plan, fmt.Errorf("cidr capture: %w", err)
	}
	if len(cidrs) == 0 {
		return plan, fmt.Errorf("cidr capture: %s has no usable prefixes", cfg.GatewaySplitCIDRFile)
	}
	plan.CaptureCIDRs = cidrs
	want := strings.ToLower(strings.TrimSpace(cfg.GatewayRouteScope))
	if (want == "" || want == "auto") && containsString(nativeScopesOf(plan.Native), "global") {
		plan.Scope = "global"
	}
	if cfg.GatewaySplitForwardID > 0 {
		plan.Notes = append(plan.Notes, fmt.Sprintf("split carrier #%d is ignored in cidr capture mode", cfg.GatewaySplitForwardID))
	}
	return plan, nil
}

// resolveGatewaySplit adds the split carrier. Any problem with it is a note,
// never an error: the primary carrier keeps the LAN online on its own. The
// split relay asks for the global scope because the router, not the line,
// narrows it to the CIDR list.
func resolveGatewaySplit(cfg Config, capability *NativeOutboundCapability, forwards map[int]ForwardConfig, plan gatewayPlan, cidrs []string) gatewayPlan {
	id := cfg.GatewaySplitForwardID
	off := func(reason string) gatewayPlan {
		plan.Notes = append(plan.Notes, fmt.Sprintf("split carrier #%d is off: %s", id, reason))
		return plan
	}
	if id == plan.Carrier.ForwardID {
		return off("it is already the primary carrier")
	}
	native, ok := capability.carrier(id)
	if !ok {
		return off("it is not a native outbound line on this account")
	}
	carrier, ok := forwards[id]
	if !ok {
		return off("it is not in this device's forward list")
	}
	if strings.TrimSpace(native.Direction) == "" || !strings.EqualFold(strings.TrimSpace(native.Transport), "tcp+udp") {
		return off("it has no usable direction")
	}
	if len(cidrs) == 0 {
		return off("the CIDR list is empty")
	}
	split := carrierPlan{Carrier: carrier, Native: native}
	split.Scope, plan.Notes = resolveGatewayScope("global", native, plan.Notes)
	split.Policy, plan.Notes = resolveGatewayPolicy(cfg.GatewaySplitRoutePolicy, native, plan.Notes)
	plan.Split = &split
	plan.SplitCIDRs = cidrs
	return plan
}

// loadSplitCIDRs reads one IPv4 CIDR per line (# comments allowed). Entries
// broader than /8, private, loopback or unspecified ranges are dropped: the
// list steers public destinations, it must never capture the LAN or the
// whole Internet by accident.
func loadSplitCIDRs(path string) ([]string, error) {
	path = strings.TrimSpace(path)
	if path == "" {
		return nil, errors.New("no CIDR file is configured")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("CIDR file %s: %w", path, err)
	}
	return parseSplitCIDRs(string(raw)), nil
}

func parseSplitCIDRs(text string) []string {
	seen := map[string]bool{}
	out := []string{}
	for _, line := range strings.Split(text, "\n") {
		if hash := strings.IndexByte(line, '#'); hash >= 0 {
			line = line[:hash]
		}
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		ip, network, err := net.ParseCIDR(line)
		if err != nil || ip.To4() == nil {
			continue
		}
		if ones, _ := network.Mask.Size(); ones < 8 {
			continue
		}
		if network.IP.IsPrivate() || network.IP.IsLoopback() || network.IP.IsUnspecified() || network.IP.IsLinkLocalUnicast() {
			continue
		}
		canonical := network.String()
		if !seen[canonical] {
			seen[canonical] = true
			out = append(out, canonical)
		}
	}
	return out
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
