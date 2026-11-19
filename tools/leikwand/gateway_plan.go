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
// run: the primary carrier and the split carriers that only the destinations
// in their CIDR lists use.
type gatewayPlan struct {
	carrierPlan
	// Splits holds every configured split in configured order, the ones that
	// are off included (Plan is nil and Off says why). A split's position is
	// its identity: it fixes the relay port, the run directory and the
	// sing-box outbound tag, so a neighbour going off never moves it.
	Splits []splitPlan
	// CaptureCIDRs is set in cidr capture mode: the primary carrier carries
	// only these destinations and nothing else on the box is touched.
	CaptureCIDRs []string
	// Notes explain a downgrade from what the operator asked for.
	Notes []string
}

// splitPlan is one split carrier: the destinations in CIDRs leave over Plan's
// carrier instead of the primary.
type splitPlan struct {
	Index      int
	Name       string
	ForwardID  int
	CIDRFile   string
	WantPolicy string
	Plan       *carrierPlan
	CIDRs      []string
	Off        string
}

// active reports whether sing-box routes the split's list to its relay.
func (s splitPlan) active() bool { return s.Plan != nil && len(s.CIDRs) > 0 }

// maxGatewaySplits bounds the split relays (ports 2081-2088).
const maxGatewaySplits = 8

// Each split's fixed identity, derived from its configured position. The
// first split keeps the 1.1.x names (port 2081, tag "split", directory
// relay-split, bypass owner gateway:split) so an upgraded router renders the
// same sing-box configuration as before.
func splitTag(index int) string {
	if index == 0 {
		return "split"
	}
	return "split-" + itoa(index+1)
}

func splitPort(index int) int { return gatewaySplitSocksPort + index }

func splitDir(index int) string {
	if index == 0 {
		return "relay-split"
	}
	return "relay-split-" + itoa(index+1)
}

func splitOwner(index int) string {
	if index == 0 {
		return gatewaySplitRelayOwner
	}
	return gatewaySplitRelayOwner + ":" + itoa(index+1)
}

// split returns the split at a configured position.
func (p gatewayPlan) split(index int) (splitPlan, bool) {
	for _, split := range p.Splits {
		if split.Index == index {
			return split, true
		}
	}
	return splitPlan{}, false
}

// activeSplits is the splits sing-box routes, in configured order.
func (p gatewayPlan) activeSplits() []splitPlan {
	out := []splitPlan{}
	for _, split := range p.Splits {
		if split.active() {
			out = append(out, split)
		}
	}
	return out
}

var errNativeNotGranted = errors.New("panel has not granted native outbound for this device")

// gatewayPlan resolves the operator's UCI choice against what the Panel
// granted. An explicit carrier that the Panel does not offer is an error, not
// a silent switch; an unavailable scope or policy falls back and says so.
// Without mode=gateway this is the router edition's single relay: no capture
// and no splits, exactly as 1.2.x ran it.
func (a *Agent) gatewayPlan() (gatewayPlan, error) {
	a.mu.Lock()
	capability := a.nativeCap
	forwards := make(map[int]ForwardConfig, len(a.nativeForwards))
	for id, forward := range a.nativeForwards {
		forwards[id] = forward
	}
	cfg := a.cfg
	a.mu.Unlock()
	return buildGatewayPlan(cfg, capability, forwards)
}

func buildGatewayPlan(cfg Config, capability *NativeOutboundCapability, forwards map[int]ForwardConfig) (gatewayPlan, error) {
	plan, err := resolveGatewayPlan(cfg, capability, forwards)
	if err != nil || !cfg.gatewayActive() {
		return plan, err
	}
	if cfg.GatewayCapture == "cidr" {
		if plan, err = resolveCIDRCapture(cfg, plan); err != nil {
			return plan, err
		}
	}
	return resolveGatewaySplits(cfg, capability, forwards, plan), nil
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
// error rather than a silently idle gateway), and the scope is global whenever
// the line offers it (the router, not the line, narrows the destinations).
// gateway_split_cidr_file is the capture list itself in this mode, so the
// legacy gateway_split_forward_id has no list of its own and is ignored; named
// "config split" sections keep their own lists and still apply.
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

// resolveGatewaySplits adds the split carriers in configured order. Any
// problem with one is a note, never an error: the primary carrier keeps the
// LAN online on its own, and the other splits are unaffected. A split relay
// asks for the global scope because the router, not the line, narrows it to
// the CIDR list.
func resolveGatewaySplits(cfg Config, capability *NativeOutboundCapability, forwards map[int]ForwardConfig, plan gatewayPlan) gatewayPlan {
	used := map[int]string{}
	index := 0
	for _, split := range cfg.gatewaySplitConfigs() {
		if split.Legacy && plan.CaptureCIDRs != nil {
			continue // its file is the capture list (resolveCIDRCapture noted it)
		}
		label := fmt.Sprintf("split %s (carrier #%d)", split.Name, split.ForwardID)
		if split.Legacy {
			label = fmt.Sprintf("split carrier #%d", split.ForwardID)
		}
		if index >= maxGatewaySplits {
			plan.Notes = append(plan.Notes, fmt.Sprintf("%s is ignored: at most %d splits are supported", label, maxGatewaySplits))
			continue
		}
		entry := splitPlan{
			Index:      index,
			Name:       split.Name,
			ForwardID:  split.ForwardID,
			CIDRFile:   split.CIDRFile,
			WantPolicy: split.RoutePolicy,
		}
		index++
		off := func(reason string) {
			entry.Off = reason
			plan.Notes = append(plan.Notes, fmt.Sprintf("%s is off: %s", label, reason))
			plan.Splits = append(plan.Splits, entry)
		}
		cidrs, err := loadSplitCIDRs(split.CIDRFile)
		if err != nil {
			off(err.Error())
			continue
		}
		id := split.ForwardID
		if id <= 0 {
			off("it has no forward_id")
			continue
		}
		if id == plan.Carrier.ForwardID {
			off("it is already the primary carrier")
			continue
		}
		if other, dup := used[id]; dup {
			off("its carrier is already used by split " + other)
			continue
		}
		native, ok := capability.carrier(id)
		if !ok {
			off("it is not a native outbound line on this account")
			continue
		}
		carrier, ok := forwards[id]
		if !ok {
			off("it is not in this device's forward list")
			continue
		}
		if strings.TrimSpace(native.Direction) == "" || !strings.EqualFold(strings.TrimSpace(native.Transport), "tcp+udp") {
			off("it has no usable direction")
			continue
		}
		if len(cidrs) == 0 {
			off("the CIDR list is empty")
			continue
		}
		carrierPlan := carrierPlan{Carrier: carrier, Native: native}
		carrierPlan.Scope, plan.Notes = resolveGatewayScope("global", native, plan.Notes)
		carrierPlan.Policy, plan.Notes = resolveGatewayPolicy(split.RoutePolicy, native, plan.Notes)
		entry.Plan = &carrierPlan
		entry.CIDRs = cidrs
		used[id] = split.Name
		plan.Splits = append(plan.Splits, entry)
	}
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
