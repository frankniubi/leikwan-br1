package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"time"
)

// Native outbound relays and the transparent gateway.
//
// When the Panel grants native outbound for this device, the agent keeps an
// ultra-client process running as a loopback SOCKS5 facade on 127.0.0.1:2080
// whose egress is the Panel-selected native line (the primary relay). This is
// the same relay the desktop clients expose.
//
// The manager runs in one of two modes, chosen by UCI:
//
//   - relay (the router edition default): only the primary relay. Routing
//     LAN traffic through it is the operator's decision (firewall redirects,
//     a separate sing-box, ...). This is exactly what 1.2.x ran.
//   - gateway (mode=gateway or hybrid, with gateway_enabled=1): the router
//     becomes a transparent egress. Data path: LAN client -> router (default
//     gateway) -> sing-box TUN inbound -> SOCKS5 facade of a native relay ->
//     Panel-selected Ultra line -> the line's exit.
//
// Supervised processes with independent lifecycles:
//   - the primary relay holds the primary carrier's native lease batch. A
//     renewal is delivered to the running relay over its control socket; the
//     relay is only restarted when it or its control channel is gone, or the
//     plan changes.
//   - in gateway mode, each split relay (127.0.0.1:2081, 2082, ...) does the
//     same for a carrier that only its CIDR list uses (e.g. SHIXP members).
//     A split's sing-box rule stays in place while its relay restarts or
//     renews, so a split failure only affects the listed destinations and
//     never restarts sing-box (which would drop every LAN session, the
//     primary carrier's included).
//   - in gateway mode, sing-box owns auto_route (its own ip rules and routes)
//     and is only restarted when its generated configuration changes, never on
//     a lease rotation, so LAN sessions survive renewals.
//
// The relays' own connections are kept out of the TUN by bypassRoutes.
//
// A Panel refresh/renew for a relay's carrier re-leases that relay in place
// (control socket, no relay or sing-box restart), at most once per slot per
// gatewayForcedReleaseInterval; a Panel stop/disable/revoke stops the slot and
// keeps it stopped until the carrier's grant generation changes.

type GatewayView struct {
	// Mode is "relay" (router edition: the SOCKS relay only) or "gateway"
	// (transparent gateway with sing-box).
	Mode          string               `json:"mode"`
	Enabled       bool                 `json:"enabled"`
	Active        bool                 `json:"active"`
	Running       bool                 `json:"running"`
	RelayRunning  bool                 `json:"relay_running"`
	SingBoxRun    bool                 `json:"singbox_running"`
	CarrierID     int                  `json:"carrier_forward_id"`
	Direction     string               `json:"direction,omitempty"`
	RouteScope    string               `json:"route_scope"`
	RoutePolicy   string               `json:"route_policy"`
	WantScope     string               `json:"configured_route_scope"`
	WantPolicy    string               `json:"configured_route_policy"`
	Unrestricted  bool                 `json:"unrestricted_allowed"`
	TunAddress    string               `json:"tun_address,omitempty"`
	TunAddress6   string               `json:"tun_address6,omitempty"`
	SocksPort     int                  `json:"socks_port"`
	BypassCN      bool                 `json:"bypass_cn"`
	DNSMode       string               `json:"dns_mode"`
	RuleSets      string               `json:"rule_sets,omitempty"`
	Candidates    int                  `json:"candidates"`
	LeaseExpiry   string               `json:"lease_expires_at,omitempty"`
	LeaseError    string               `json:"lease_error,omitempty"`
	BypassRules   int                  `json:"bypass_rules"`
	BypassError   string               `json:"bypass_error,omitempty"`
	Notes         []string             `json:"notes,omitempty"`
	LastError     string               `json:"last_error,omitempty"`
	Carriers      []GatewayCarrierView `json:"carriers"`
	NativeGranted bool                 `json:"native_granted"`
	// Split is the first split carrier (the 1.1.x view); Splits lists all.
	Split        *GatewaySplitView  `json:"split,omitempty"`
	Splits       []GatewaySplitView `json:"splits,omitempty"`
	Capture      string             `json:"capture"`
	CaptureCIDRs int                `json:"capture_cidrs,omitempty"`
	// Slots is every relay slot (primary first, then the splits in
	// configured order) in one uniform shape for status, ctl and LuCI.
	Slots []GatewaySlotView `json:"slots"`
}

type GatewayCarrierView struct {
	ForwardID         int      `json:"forward_id"`
	Direction         string   `json:"direction"`
	RouteScopes       []string `json:"route_scopes"`
	DefaultRouteScope string   `json:"default_route_scope"`
	UnrestrictedOK    bool     `json:"unrestricted_allowed"`
	Default           bool     `json:"default"`
}

// GatewaySplitView is a split carrier's state. Active means sing-box routes
// the CIDR list to it and its relay holds a valid lease.
type GatewaySplitView struct {
	Name         string `json:"name"`
	CarrierID    int    `json:"carrier_forward_id"`
	Direction    string `json:"direction,omitempty"`
	RoutePolicy  string `json:"route_policy,omitempty"`
	WantPolicy   string `json:"configured_route_policy"`
	CIDRFile     string `json:"cidr_file"`
	CIDRs        int    `json:"cidrs"`
	SocksPort    int    `json:"socks_port"`
	RelayRunning bool   `json:"relay_running"`
	Active       bool   `json:"active"`
	Candidates   int    `json:"candidates"`
	LeaseExpiry  string `json:"lease_expires_at,omitempty"`
	LeaseError   string `json:"lease_error,omitempty"`
	// Off says why the split is not routed (empty while it is).
	Off string `json:"off,omitempty"`
}

// GatewaySlotView is one relay slot. State is one of running (relay up with a
// valid lease), starting (relay up, no lease yet), error (the last lease
// attempt failed), stopped, off (a split that is not routed; LastError says
// why) and panel_stopped (the Panel stopped the carrier; it stays stopped
// until the grant generation changes).
type GatewaySlotView struct {
	Name           string `json:"name"`
	Role           string `json:"role"`
	CarrierID      int    `json:"carrier_forward_id"`
	SocksPort      int    `json:"socks_port"`
	State          string `json:"state"`
	RelayRunning   bool   `json:"relay_running"`
	RouteScope     string `json:"route_scope,omitempty"`
	RoutePolicy    string `json:"route_policy,omitempty"`
	CIDRFile       string `json:"cidr_file,omitempty"`
	CIDRs          int    `json:"cidrs,omitempty"`
	Candidates     int    `json:"candidates"`
	LeaseExpiry    string `json:"lease_expires_at,omitempty"`
	LeaseError     string `json:"lease_error,omitempty"`
	LastError      string `json:"last_error,omitempty"`
	PanelStopped   bool   `json:"panel_stopped,omitempty"`
	RefreshPending bool   `json:"refresh_pending,omitempty"`
}

// relaySlot is one native relay and its lease state.
type relaySlot struct {
	role  string // "primary", "split", "split-2", ... for logs
	name  string // "primary", "legacy" or the split section's name
	index int    // configured split position; -1 for the primary
	port  int    // SOCKS5 facade on 127.0.0.1
	dir   string // run directory below <run_dir>/gateway
	owner string // bypassRoutes owner key

	plan           carrierPlan
	relay          *runtimeProcess
	relayKey       string
	relayStoppedAt time.Time
	leaseExpiry    time.Time
	// renewNotBefore holds renewals back until the grant can be renewed.
	renewNotBefore time.Time
	lastLeaseAt    time.Time
	leaseFailures  int
	leaseErr       string
	candidates     int

	// forceRenew is a Panel refresh waiting to run: the first reconcile at or
	// after forceRenewAt re-leases the slot although its lease is valid.
	// lastForcedAt is when the last forced re-lease ran, which bounds them to
	// one per gatewayForcedReleaseInterval.
	forceRenew   bool
	forceRenewAt time.Time
	lastForcedAt time.Time

	// counters is guarded by gatewayManager.usageMu.
	counters counterPair

	// status is the relay's last parsed status document, read once per
	// heartbeat for billing and reused for the Panel status report.
	status   *RuntimeStatus
	statusAt time.Time
}

type gatewayManager struct {
	agent *Agent

	mu   sync.Mutex
	mode string // "relay" or "gateway"; "" before the first reconcile
	plan gatewayPlan
	// primary is the primary carrier's relay; splits[i] is the relay of the
	// split at configured position i.
	primary relaySlot
	splits  []*relaySlot
	// panelStopped holds native carriers the Panel stopped out of band, keyed
	// to the grant generation they were stopped under.
	panelStopped map[int]string

	singbox          *runtimeProcess
	singboxGen       string
	singboxStoppedAt time.Time
	ruleSetSource    string
	activeSplits     int

	// usageMu serialises relay accounting between the heartbeat and a stop.
	usageMu sync.Mutex

	lastErr string
}

// Bypass owner keys of the native relays.
const (
	gatewayRelayOwner      = "gateway"
	gatewaySplitRelayOwner = "gateway:split"
)

// gatewayForcedReleaseInterval bounds Panel-forced re-leases to one per slot
// in this interval; a refresh inside it is deferred to its end, not dropped.
const gatewayForcedReleaseInterval = 20 * time.Second

func newGatewayManager(a *Agent) *gatewayManager {
	return &gatewayManager{
		agent:        a,
		primary:      relaySlot{role: "primary", name: "primary", index: -1, port: gatewaySocksPort, dir: "relay", owner: gatewayRelayOwner},
		panelStopped: map[int]string{},
	}
}

func newSplitSlot(index int) *relaySlot {
	role := "split"
	if index > 0 {
		role = "split-" + itoa(index+1)
	}
	return &relaySlot{role: role, index: index, port: splitPort(index), dir: splitDir(index), owner: splitOwner(index)}
}

// splitSlot returns the relay slot of a configured split position, creating
// it on first use.
func (m *gatewayManager) splitSlot(index int) *relaySlot {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.splitSlotLocked(index)
}

func (m *gatewayManager) splitSlotLocked(index int) *relaySlot {
	for len(m.splits) <= index {
		m.splits = append(m.splits, newSplitSlot(len(m.splits)))
	}
	return m.splits[index]
}

func (m *gatewayManager) splitSlots() []*relaySlot {
	m.mu.Lock()
	defer m.mu.Unlock()
	return append([]*relaySlot(nil), m.splits...)
}

func (a *Agent) triggerGatewayReconcile() {
	select {
	case a.gatewaySignal <- struct{}{}:
	default:
	}
}

// gatewayLoop always runs, so the relay comes up (and tears down) purely from
// what the Panel grants, and enabling the gateway from LuCI takes effect on
// "reload" without a service restart.
func (a *Agent) gatewayLoop(ctx context.Context) {
	manager := newGatewayManager(a)
	a.mu.Lock()
	a.gateway = manager
	a.mu.Unlock()
	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()
	defer manager.stopAll("shutdown")
	for {
		manager.reconcile(ctx)
		select {
		case <-ctx.Done():
			return
		case <-a.gatewaySignal:
		case <-ticker.C:
		}
	}
}

func (m *gatewayManager) setErr(format string, args ...any) {
	message := ""
	if format != "" {
		message = redactSecrets(fmt.Sprintf(format, args...))
	}
	m.mu.Lock()
	changed := m.lastErr != message
	m.lastErr = message
	m.mu.Unlock()
	if changed && message != "" {
		m.agent.log.Warn("gateway", "%s", message)
	}
}

// clearCooldowns forgets the restart cooldowns: an operator-driven change is
// not a crash.
func (m *gatewayManager) clearCooldowns() {
	m.mu.Lock()
	m.primary.relayStoppedAt = time.Time{}
	for _, slot := range m.splits {
		slot.relayStoppedAt = time.Time{}
	}
	m.singboxStoppedAt = time.Time{}
	m.mu.Unlock()
}

func (m *gatewayManager) reconcile(ctx context.Context) {
	a := m.agent
	cfg := a.config()
	if ctx.Err() != nil {
		return
	}
	mode := "relay"
	if cfg.gatewayActive() {
		mode = "gateway"
	}
	m.mu.Lock()
	previous := m.mode
	m.mode = mode
	m.mu.Unlock()
	if previous != "" && previous != mode {
		a.log.Info("gateway", "mode changed from %s to %s; restarting the native relays", previous, mode)
		m.stopAll("mode_changed")
		m.clearCooldowns()
	}
	if !a.enrolled() {
		m.stopAll("not_enrolled")
		m.setErr("")
		return
	}
	if mode == "gateway" && !fileExists(cfg.GatewaySingBoxBin) {
		m.setErr("sing-box is not installed at %s (opkg install sing-box kmod-tun)", cfg.GatewaySingBoxBin)
		m.stopAll("singbox_missing")
		return
	}
	a.mu.Lock()
	pulled := a.configLoaded
	a.mu.Unlock()
	if !pulled {
		// Not a fault: the capability arrives with the first full config pull
		// (a 304 carries no capability, so it does not count).
		m.mu.Lock()
		m.lastErr = "waiting for the first panel config pull"
		m.mu.Unlock()
		return
	}
	plan, err := a.gatewayPlan()
	if err != nil {
		// A carrier the Panel no longer grants is a revocation: stop and let
		// the LAN fall back to ordinary routing rather than black-holing it.
		// The reason is recorded first so status never shows a silent stop.
		m.setErr("%v", err)
		m.stopAll("no_carrier")
		// Status must show the carrier the operator configured, not the one
		// that ran before.
		m.mu.Lock()
		m.plan = gatewayPlan{}
		m.mu.Unlock()
		return
	}
	m.prunePanelStops()
	if m.carrierPanelStopped(plan.Carrier.ForwardID) {
		m.mu.Lock()
		m.plan = plan
		m.primary.plan = plan.carrierPlan
		m.mu.Unlock()
		m.stopAll("panel_stopped")
		m.setErr("native carrier #%d was stopped by the panel; waiting for a new grant", plan.Carrier.ForwardID)
		return
	}
	for i := range plan.Splits {
		split := &plan.Splits[i]
		if split.Plan != nil && m.carrierPanelStopped(split.Plan.Carrier.ForwardID) {
			split.Plan = nil
			split.Off = "stopped by the panel"
			plan.Notes = append(plan.Notes, fmt.Sprintf("split %s (carrier #%d) is off: stopped by the panel", split.Name, split.ForwardID))
		}
	}

	m.mu.Lock()
	planChanged := m.primary.relayKey != "" && m.primary.relayKey != plan.key()
	m.mu.Unlock()
	if planChanged {
		a.log.Info("gateway", "plan changed to carrier=%d scope=%s policy=%s; restarting", plan.Carrier.ForwardID, plan.Scope, plan.Policy)
		// Stopped before the new plan is stored, so the relays' final usage
		// is billed to the carrier that carried it.
		m.stopAll("plan_changed")
		m.clearCooldowns()
	}
	m.mu.Lock()
	m.plan = plan
	m.primary.plan = plan.carrierPlan
	m.mu.Unlock()
	m.syncSplitSlots(plan)

	if m.relayDead(&m.primary) {
		m.stopSlot(&m.primary, "relay_exited")
	}
	if m.leaseDue(&m.primary, time.Now(), cfg) {
		if err := m.renewLease(ctx, &m.primary, plan.carrierPlan); err != nil {
			m.noteLeaseFailure(&m.primary, err)
			m.setErr("%v", err)
		}
	}
	if mode == "relay" {
		m.setErr("")
		return
	}
	if !m.leaseValid(&m.primary, time.Now()) {
		// No usable lease: the relay cannot carry anything. Taking sing-box
		// down lets the LAN keep ordinary connectivity while the reason is
		// shown in LuCI.
		if m.singboxRunning() {
			a.log.Warn("gateway", "native lease is not valid; stopping LAN capture until it recovers")
		}
		m.stopSingBox("lease_invalid")
		a.bypass.deactivate()
		return
	}

	for _, split := range plan.activeSplits() {
		slot := m.splitSlot(split.Index)
		if m.relayDead(slot) {
			m.stopSlot(slot, "relay_exited")
		}
		if m.leaseDue(slot, time.Now(), cfg) {
			if err := m.renewLease(ctx, slot, *split.Plan); err != nil {
				if m.noteLeaseFailure(slot, err) {
					a.log.Warn("gateway", "%s carrier #%d: %v", slot.role, split.Plan.Carrier.ForwardID, redactSecrets(err.Error()))
				}
			}
		}
	}
	if err := m.ensureSingBox(ctx, cfg, plan); err != nil {
		m.setErr("%v", err)
		return
	}
	m.setErr("")
}

// syncSplitSlots lines the split relays up with the plan: a split that went
// off (or was removed) stops, a split whose carrier, scope or policy changed
// restarts, and the others keep running untouched.
func (m *gatewayManager) syncSplitSlots(plan gatewayPlan) {
	type pending struct {
		slot   *relaySlot
		reason string
	}
	stops := []pending{}
	want := 0
	for _, split := range plan.Splits {
		if split.Index+1 > want {
			want = split.Index + 1
		}
	}
	m.mu.Lock()
	for len(m.splits) < want {
		m.splits = append(m.splits, newSplitSlot(len(m.splits)))
	}
	for i, slot := range m.splits {
		split, ok := plan.split(i)
		if ok {
			slot.name = split.Name
		}
		switch {
		case !ok || !split.active():
			if slot.relay != nil || slot.relayKey != "" {
				stops = append(stops, pending{slot, "split_off"})
			}
		case slot.relayKey != "" && slot.relayKey != split.Plan.key():
			stops = append(stops, pending{slot, "plan_changed"})
		}
	}
	m.mu.Unlock()
	for _, stop := range stops {
		if stop.reason == "plan_changed" {
			m.agent.log.Info("gateway", "%s carrier changed; restarting its relay", stop.slot.role)
		}
		m.stopSlot(stop.slot, stop.reason)
		if stop.reason == "plan_changed" {
			m.mu.Lock()
			stop.slot.relayStoppedAt = time.Time{}
			m.mu.Unlock()
		}
	}
	m.mu.Lock()
	for i, slot := range m.splits {
		if split, ok := plan.split(i); ok && split.Plan != nil {
			slot.plan = *split.Plan
		}
	}
	if len(m.splits) > want {
		m.splits = m.splits[:want]
	}
	m.mu.Unlock()
}

// prunePanelStops forgets a Panel stop once the carrier is no longer listed or
// is listed under a new grant generation, which is the Panel re-enabling it.
func (m *gatewayManager) prunePanelStops() {
	a := m.agent
	a.mu.Lock()
	listed := make(map[int]string, len(a.nativeForwards))
	for id, forward := range a.nativeForwards {
		listed[id] = policyGenerationOf(forward)
	}
	a.mu.Unlock()
	m.mu.Lock()
	reenabled := []int{}
	for id, generation := range m.panelStopped {
		current, ok := listed[id]
		if !ok || current != generation {
			delete(m.panelStopped, id)
			if ok {
				reenabled = append(reenabled, id)
			}
		}
	}
	m.mu.Unlock()
	for _, id := range reenabled {
		a.log.Info("gateway", "native carrier #%d has a new grant generation; the panel stop is lifted", id)
	}
}

func (m *gatewayManager) carrierPanelStopped(forwardID int) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	_, stopped := m.panelStopped[forwardID]
	return stopped
}

// slotForCarrierLocked is the slot whose current plan uses the carrier.
func (m *gatewayManager) slotForCarrierLocked(forwardID int) *relaySlot {
	if forwardID <= 0 {
		return nil
	}
	if m.plan.Carrier.ForwardID == forwardID || (m.plan.Carrier.ForwardID == 0 && m.primary.plan.Carrier.ForwardID == forwardID) {
		return &m.primary
	}
	for _, split := range m.plan.Splits {
		if split.Plan != nil && split.Plan.Carrier.ForwardID == forwardID && split.Index < len(m.splits) {
			return m.splits[split.Index]
		}
	}
	return nil
}

// panelAction applies a Panel out-of-band command (heartbeat ultra_actions)
// to a native relay. It reports false when the forward is not a native carrier
// of this device, so the caller handles it as an ordinary forward.
func (m *gatewayManager) panelAction(action UltraPolicyAction, now time.Time) bool {
	if m == nil || action.ForwardID <= 0 {
		return false
	}
	a := m.agent
	id := action.ForwardID
	a.mu.Lock()
	carrier, native := a.nativeForwards[id]
	a.mu.Unlock()
	m.mu.Lock()
	slot := m.slotForCarrierLocked(id)
	mode := m.mode
	m.mu.Unlock()
	if slot == nil && !native {
		return false
	}
	verb := strings.ToLower(strings.TrimSpace(action.Action))
	switch verb {
	case "stop", "disable", "revoke":
		m.mu.Lock()
		m.panelStopped[id] = policyGenerationOf(carrier)
		m.mu.Unlock()
		a.log.Warn("panel", "panel action=%s for native carrier %d (%s); stopping its relay", action.Action, id, action.Reason)
		if slot != nil {
			if slot.index < 0 && mode == "gateway" {
				// Without the primary relay sing-box would black-hole the
				// LAN; take the capture down with it.
				m.stopSingBox("panel_" + verb)
				a.bypass.deactivate()
			}
			m.stopSlot(slot, "panel_"+verb)
		}
		a.triggerGatewayReconcile()
		return true
	case "refresh", "renew":
		if slot == nil {
			a.log.Info("panel", "panel requested refresh for native carrier %d (%s); no relay uses it", id, action.Reason)
			return true
		}
		at := m.requestForcedRelease(slot, now)
		if at.After(now) {
			a.log.Info("panel", "panel requested refresh for native carrier %d (%s); deferred to %s (one re-lease per %s)",
				id, action.Reason, formatRFC3339(at), gatewayForcedReleaseInterval)
			time.AfterFunc(at.Sub(now), a.triggerGatewayReconcile)
		} else {
			a.log.Info("panel", "panel requested refresh for native carrier %d (%s); re-leasing the %s relay", id, action.Reason, slot.role)
		}
		a.triggerGatewayReconcile()
		return true
	default:
		a.log.Debug("panel", "ignoring panel action %q for native carrier %d", action.Action, id)
		return true
	}
}

// requestForcedRelease marks the slot for a re-lease and returns when it
// runs: now, or the end of the interval that the last forced re-lease opened.
// Refreshes that arrive while one is pending coalesce into it.
func (m *gatewayManager) requestForcedRelease(slot *relaySlot, now time.Time) time.Time {
	m.mu.Lock()
	defer m.mu.Unlock()
	at := now
	if !slot.lastForcedAt.IsZero() {
		if earliest := slot.lastForcedAt.Add(gatewayForcedReleaseInterval); now.Before(earliest) {
			at = earliest
		}
	}
	if slot.forceRenew && slot.forceRenewAt.Before(at) {
		at = slot.forceRenewAt
	}
	slot.forceRenew = true
	slot.forceRenewAt = at
	if !at.After(now) {
		slot.renewNotBefore = time.Time{}
		slot.lastLeaseAt = time.Time{}
	}
	return at
}

// noteLeaseFailure records a failed renewal and reports whether its message
// is new (so a persistent failure is logged once, not every reconcile).
func (m *gatewayManager) noteLeaseFailure(slot *relaySlot, err error) bool {
	message := redactSecrets(err.Error())
	m.mu.Lock()
	defer m.mu.Unlock()
	slot.leaseFailures++
	changed := slot.leaseErr != message
	slot.leaseErr = message
	return changed
}

func (m *gatewayManager) relayDead(slot *relaySlot) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return slot.relay != nil && !slot.relay.alive()
}

func (m *gatewayManager) singboxRunning() bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.singbox != nil && m.singbox.alive()
}

func (m *gatewayManager) leaseValid(slot *relaySlot, now time.Time) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return slot.relay != nil && slot.relay.alive() && !slot.leaseExpiry.IsZero() && now.Before(slot.leaseExpiry)
}

func (m *gatewayManager) leaseDue(slot *relaySlot, now time.Time, cfg Config) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	if slot.forceRenew && !now.Before(slot.forceRenewAt) {
		return true
	}
	if slot.relay == nil || slot.leaseExpiry.IsZero() {
		if slot.leaseFailures > 0 {
			return now.Sub(slot.lastLeaseAt) >= time.Duration(clampInt(slot.leaseFailures*5, 5, 60))*time.Second
		}
		// A relay that just died gets the normal restart cooldown.
		return slot.relayStoppedAt.IsZero() || now.Sub(slot.relayStoppedAt) >= time.Duration(clampInt(cfg.RestartCooldownSecs, 5, 60))*time.Second
	}
	if slot.leaseFailures > 0 && now.Sub(slot.lastLeaseAt) < time.Duration(clampInt(slot.leaseFailures*5, 5, 60))*time.Second {
		return false
	}
	if now.Before(slot.renewNotBefore) {
		return false
	}
	return now.Add(time.Duration(cfg.LeaseRenewLeadSecs) * time.Second).After(slot.leaseExpiry)
}

// nextLeaseRenewal is the earliest time a renewal can extend the lease. The
// Panel caps a lease at its grant's expiry and keeps serving the same
// grant until renew_after, so renewing earlier only re-mints a lease with the
// same expiry (a tight loop once the renew lead is reached). The wait never
// runs past ten seconds before the lease expires.
func nextLeaseRenewal(grant *UltraPolicy, leaseExpiry time.Time) time.Time {
	if grant == nil || leaseExpiry.IsZero() {
		return time.Time{}
	}
	renewAfter, err := parseRFC3339(grant.RenewAfter)
	if err != nil {
		return time.Time{}
	}
	if latest := leaseExpiry.Add(-10 * time.Second); renewAfter.After(latest) {
		return latest
	}
	return renewAfter
}

// renewLease mints a native batch from a freshly pulled grant (the session
// ticket rotates independently of the stable config revision, so the desktop
// does the same), installs the bypass set, then delivers it to the slot's
// relay, starting the relay first when needed.
func (m *gatewayManager) renewLease(ctx context.Context, slot *relaySlot, plan carrierPlan) error {
	a := m.agent
	m.mu.Lock()
	now := time.Now()
	slot.lastLeaseAt = now
	if slot.forceRenew {
		// Any renewal answers a pending Panel refresh; only a forced one
		// opens a new interval.
		if !now.Before(slot.forceRenewAt) {
			slot.lastForcedAt = now
		}
		slot.forceRenew = false
		slot.forceRenewAt = time.Time{}
	}
	m.mu.Unlock()

	if err := a.freshPull(ctx); err != nil {
		return fmt.Errorf("refresh native grant: %w", err)
	}
	fresh, err := a.gatewayPlan()
	if err != nil {
		return err
	}
	current := fresh.carrierPlan
	if slot.index >= 0 {
		split, ok := fresh.split(slot.index)
		if !ok || !split.active() {
			return fmt.Errorf("%s carrier is no longer available", slot.role)
		}
		current = *split.Plan
	}
	if current.key() != plan.key() {
		// The next reconcile restarts with the new plan.
		return errors.New("native plan changed during renewal")
	}
	plan = current
	if m.carrierPanelStopped(plan.Carrier.ForwardID) {
		return fmt.Errorf("native carrier #%d was stopped by the panel", plan.Carrier.ForwardID)
	}
	if failure := validatePolicy(plan.Carrier.UltraAccess, plan.Carrier, time.Now()); failure != "" {
		switch failure {
		case "service_state", "quota", "target_digest_mismatch", "forward_id", "protocol":
			if slot.index >= 0 {
				m.stopSlot(slot, "policy_"+failure)
			} else {
				m.stopAll("policy_" + failure)
			}
		}
		return fmt.Errorf("native carrier #%d grant unusable: %s", plan.Carrier.ForwardID, failure)
	}

	frames, bypass, err := a.requestNativeLeases(ctx, plan)
	if err != nil {
		return err
	}
	a.bypass.set(slot.owner, bypass)
	if err := a.bypass.sync(); err != nil {
		a.log.Warn("gateway", "relay bypass rules incomplete: %v", err)
	}

	m.mu.Lock()
	relay := slot.relay
	m.mu.Unlock()
	startedHere := relay == nil || !relay.alive()
	if startedHere {
		relay, err = m.startRelay(ctx, slot, plan, frames[0])
		if err != nil {
			return err
		}
	}
	control := ultraControl{socketPath: relay.paths.controlSocket}
	batchCtx, cancel := context.WithTimeout(ctx, leaseBatchTimeout)
	_, err = control.sendLeaseBatch(batchCtx, frames)
	cancel()
	if err != nil {
		if startedHere {
			m.stopSlot(slot, "lease_rejected")
		}
		return fmt.Errorf("native relay rejected the lease batch: %v", err)
	}
	if startedHere {
		// The relay blocks in WaitValid() until a lease exists and only then
		// opens the SOCKS5 facade, so the port is checked after delivery.
		if err := waitForListenPort(ctx, slot.port, 20*time.Second); err != nil {
			m.stopSlot(slot, "socks_not_ready")
			return err
		}
	}
	expiry := earliestLeaseExpiry(frames)
	notBefore := nextLeaseRenewal(plan.Carrier.UltraAccess, expiry)
	m.mu.Lock()
	slot.leaseExpiry = expiry
	slot.renewNotBefore = notBefore
	slot.leaseFailures = 0
	slot.leaseErr = ""
	slot.candidates = len(frames)
	m.mu.Unlock()
	a.log.Info("gateway", "native lease installed (%s carrier=%d candidates=%d scope=%s policy=%s expires=%s)",
		slot.role, plan.Carrier.ForwardID, len(frames), plan.Scope, plan.Policy, formatRFC3339(expiry))
	return nil
}

// gatewayRelayPaths is the primary relay's run directory.
func gatewayRelayPaths(runDir string) runtimePaths {
	return gatewaySlotPaths(runDir, "relay")
}

func gatewaySlotPaths(runDir, name string) runtimePaths {
	dir := filepath.Join(runDir, "gateway", name)
	return runtimePaths{
		dir:           dir,
		configPath:    filepath.Join(dir, "runtime.json"),
		controlSocket: filepath.Join(dir, "control.sock"),
		statusPath:    filepath.Join(dir, "status.json"),
	}
}

func (m *gatewayManager) startRelay(ctx context.Context, slot *relaySlot, plan carrierPlan, first LeaseFrame) (*runtimeProcess, error) {
	a := m.agent
	cfg := a.config()
	if !fileExists(cfg.RuntimeBin) {
		return nil, fmt.Errorf("ultra runtime %s is not installed", cfg.RuntimeBin)
	}
	if err := ensurePrivateDir(filepath.Join(cfg.RunDir, "gateway")); err != nil {
		return nil, err
	}
	paths := gatewaySlotPaths(cfg.RunDir, slot.dir)
	cleanupRuntimeDir(paths)
	runtime := buildNativeRuntimeConfig(cfg, plan.Carrier.ForwardID, slot.port, paths, first.PolicyGeneration, first.TargetDigest, plan.Policy)
	if err := writeRuntimeConfig(paths, runtime); err != nil {
		return nil, err
	}
	// An ordinary forward that listens on the SOCKS port keeps it; reclaiming
	// would kill that forward's runtime.
	if holder := a.portHolder(-1, slot.port, "tcp"); holder != nil {
		return nil, fmt.Errorf("port %d is used by forward %d; the %s native relay cannot start", slot.port, holder.forward.ForwardID, slot.role)
	}
	if kind := portOwnerKind(slot.port, "tcp"); kind == "foreign" {
		return nil, fmt.Errorf("127.0.0.1:%d is held by another program; the %s native relay cannot start", slot.port, slot.role)
	} else if kind == "managed" {
		_ = reclaimManagedPort(slot.port, "tcp")
		time.Sleep(500 * time.Millisecond)
	}
	cmd := exec.Command(cfg.RuntimeBin, "--config", paths.configPath)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stdout
	cmd.Env = append(os.Environ(), "LEIKWAN_OPENWRT_FORWARD_ID="+itoa(plan.Carrier.ForwardID))
	cmd.SysProcAttr = detachedProcAttr()
	if err := cmd.Start(); err != nil {
		return nil, fmt.Errorf("start %s native relay: %w", slot.role, err)
	}
	proc := &runtimeProcess{forwardID: plan.Carrier.ForwardID, native: true, paths: paths, cmd: cmd, pid: cmd.Process.Pid, startedAt: time.Now()}
	go watchGatewayProcess(a, proc, slot.role+" native relay")
	m.mu.Lock()
	slot.relay = proc
	slot.relayKey = plan.key()
	m.mu.Unlock()
	a.log.Info("gateway", "%s native relay started pid=%d (carrier=%d socks=127.0.0.1:%d)", slot.role, proc.pid, plan.Carrier.ForwardID, slot.port)
	if err := waitForControlSocket(ctx, paths.controlSocket, 20*time.Second); err != nil {
		m.stopSlot(slot, "readiness_failed")
		return nil, err
	}
	return proc, nil
}

func watchGatewayProcess(a *Agent, proc *runtimeProcess, label string) {
	err := proc.cmd.Wait()
	proc.mu.Lock()
	proc.exitSeen = true
	intentional := proc.stopping
	proc.stoppedAt = time.Now()
	if err != nil && !intentional {
		proc.failures++
		proc.lastErr = err.Error()
	}
	proc.mu.Unlock()
	if !intentional {
		a.log.Warn("gateway", "%s exited unexpectedly: %v", label, err)
		a.triggerGatewayReconcile()
	}
}

// ensureSingBox renders the configuration and (re)starts sing-box only when
// it changed or the process is gone. The relay bypass rules are activated
// before sing-box installs its routes.
func (m *gatewayManager) ensureSingBox(ctx context.Context, cfg Config, plan gatewayPlan) error {
	a := m.agent
	encoded, source, err := renderSingBox(cfg, plan)
	if err != nil {
		return err
	}
	splits := splitRendersFor(plan)
	generation := sha256Hex(string(encoded))

	m.mu.Lock()
	running := m.singbox != nil && m.singbox.alive()
	unchanged := running && m.singboxGen == generation
	stoppedAt := m.singboxStoppedAt
	if m.singbox != nil && !running {
		// It died on its own: honour the cooldown instead of a crash loop.
		m.singbox.mu.Lock()
		if m.singbox.stoppedAt.After(stoppedAt) {
			stoppedAt = m.singbox.stoppedAt
		}
		m.singbox.mu.Unlock()
	}
	m.ruleSetSource = source
	m.mu.Unlock()
	if unchanged {
		return a.bypass.sync()
	}
	if !running && !stoppedAt.IsZero() && time.Since(stoppedAt) < time.Duration(clampInt(cfg.RestartCooldownSecs, 5, 60))*time.Second {
		return nil
	}

	configPath := filepath.Join(cfg.RunDir, "gateway", gatewayConfigName)
	if err := ensurePrivateDir(filepath.Dir(configPath)); err != nil {
		return err
	}
	if err := writeFileAtomic(configPath, encoded, 0o600); err != nil {
		return err
	}
	if out, err := runCommandFunc(10*time.Second, cfg.GatewaySingBoxBin, "check", "-c", configPath); err != nil {
		return fmt.Errorf("sing-box rejected the gateway configuration: %v %s", err, strings.TrimSpace(out))
	}

	m.stopSingBox("config_changed")
	if err := a.bypass.activate(); err != nil {
		a.log.Warn("gateway", "relay bypass rules incomplete: %v", err)
	}
	cmd := exec.Command(cfg.GatewaySingBoxBin, "run", "-c", configPath)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stdout
	cmd.SysProcAttr = detachedProcAttr()
	if err := cmd.Start(); err != nil {
		return fmt.Errorf("start sing-box: %w", err)
	}
	proc := &runtimeProcess{paths: runtimePaths{dir: filepath.Dir(configPath)}, cmd: cmd, pid: cmd.Process.Pid, startedAt: time.Now()}
	go watchGatewayProcess(a, proc, "sing-box")
	m.mu.Lock()
	m.singbox = proc
	m.singboxGen = generation
	m.activeSplits = len(splits)
	m.mu.Unlock()
	splitNote := "off"
	if len(splits) > 0 {
		notes := make([]string, 0, len(splits))
		for _, split := range plan.activeSplits() {
			notes = append(notes, fmt.Sprintf("#%d %d cidrs", split.Plan.Carrier.ForwardID, len(split.CIDRs)))
		}
		splitNote = strings.Join(notes, ", ")
	}
	a.log.Info("gateway", "sing-box started pid=%d (scope=%s policy=%s dns=%s rule_sets=%s split=%s)",
		proc.pid, plan.Scope, plan.Policy, firstNonEmpty(cfg.GatewayDNSMode, "auto"), source, splitNote)
	return nil
}

// tunIPv6Usable reports whether the TUN can take its IPv6 address. Without
// kernel IPv6, or below the IPv6 minimum MTU, adding it would make sing-box
// fail to start and take the whole gateway down; a box like that has no LAN
// IPv6 to leak either.
func tunIPv6Usable(cfg Config) bool {
	if cfg.GatewayTunAddress6 == "" || cfg.GatewayTunMTU < 1280 {
		return false
	}
	raw, err := os.ReadFile("/proc/sys/net/ipv6/conf/all/disable_ipv6")
	return err == nil && strings.TrimSpace(string(raw)) == "0"
}

// gatewayStopGrace is how long a relay or sing-box gets to exit on SIGTERM
// before it is killed.
var gatewayStopGrace = 8 * time.Second

func stopGatewayProcess(proc *runtimeProcess, label string, log *Logger) {
	if proc == nil {
		return
	}
	proc.mu.Lock()
	if proc.cmd == nil || proc.cmd.Process == nil || proc.exitSeen {
		proc.stopping = true
		proc.mu.Unlock()
		return
	}
	proc.stopping = true
	cmd := proc.cmd
	proc.mu.Unlock()
	_ = cmd.Process.Signal(syscall.SIGTERM)
	deadline := time.Now().Add(gatewayStopGrace)
	for time.Now().Before(deadline) {
		proc.mu.Lock()
		exited := proc.exitSeen
		proc.mu.Unlock()
		if exited {
			return
		}
		time.Sleep(100 * time.Millisecond)
	}
	log.Warn("gateway", "%s ignored SIGTERM, sending SIGKILL", label)
	_ = cmd.Process.Kill()
}

func (m *gatewayManager) stopSingBox(reason string) {
	m.mu.Lock()
	proc := m.singbox
	m.singbox = nil
	m.singboxGen = ""
	m.activeSplits = 0
	if proc != nil {
		m.singboxStoppedAt = time.Now()
	}
	m.mu.Unlock()
	if proc != nil {
		m.agent.log.Info("gateway", "stopping sing-box (%s)", reason)
		stopGatewayProcess(proc, "sing-box", m.agent.log)
	}
}

// stopRelay stops the primary relay.
func (m *gatewayManager) stopRelay(reason string) { m.stopSlot(&m.primary, reason) }

func (m *gatewayManager) stopSlot(slot *relaySlot, reason string) {
	// Bill what the relay carried before its counters disappear with it.
	m.collectSlotUsage(slot)
	m.mu.Lock()
	proc := slot.relay
	slot.relay = nil
	slot.relayKey = ""
	slot.leaseExpiry = time.Time{}
	slot.renewNotBefore = time.Time{}
	slot.candidates = 0
	slot.status = nil
	slot.statusAt = time.Time{}
	slot.forceRenew = false
	slot.forceRenewAt = time.Time{}
	if proc != nil {
		slot.relayStoppedAt = time.Now()
	}
	m.mu.Unlock()
	m.agent.bypass.remove(slot.owner)
	if proc != nil {
		m.agent.log.Info("gateway", "stopping %s native relay (%s)", slot.role, reason)
		stopGatewayProcess(proc, slot.role+" native relay", m.agent.log)
		cleanupRuntimeDir(proc.paths)
	}
	// A new relay counts from zero again.
	m.usageMu.Lock()
	slot.counters = counterPair{}
	m.usageMu.Unlock()
}

// collectRelayUsage reports every relay's traffic as Ultra usage of its own
// native carrier, exactly like an ordinary runtime's.
func (m *gatewayManager) collectRelayUsage() {
	m.collectSlotUsage(&m.primary)
	for _, slot := range m.splitSlots() {
		m.collectSlotUsage(slot)
	}
}

func (m *gatewayManager) collectSlotUsage(slot *relaySlot) {
	m.mu.Lock()
	relay := slot.relay
	forwardID := slot.plan.Carrier.ForwardID
	generation := policyGeneration(slot.plan.Carrier.UltraAccess)
	m.mu.Unlock()
	if relay == nil || forwardID <= 0 {
		return
	}
	status, err := readRuntimeStatus(relay.paths.statusPath)
	if err != nil {
		return
	}
	m.mu.Lock()
	if slot.relay == relay {
		slot.status = status
		slot.statusAt = time.Now()
	}
	m.mu.Unlock()
	if status.PolicyGeneration != "" {
		generation = status.PolicyGeneration
	}
	m.usageMu.Lock()
	defer m.usageMu.Unlock()
	m.agent.recordUsage(forwardID, generation, status, &slot.counters)
}

// runningSlotPorts is the loopback ports the native relays hold right now.
func (m *gatewayManager) runningSlotPorts() []int {
	m.mu.Lock()
	defer m.mu.Unlock()
	ports := []int{}
	if m.primary.relay != nil && m.primary.relay.alive() {
		ports = append(ports, m.primary.port)
	}
	for _, slot := range m.splits {
		if slot.relay != nil && slot.relay.alive() {
			ports = append(ports, slot.port)
		}
	}
	return ports
}

// stopAll tears down in dependency order: sing-box first (its routes go with
// its TUN), then the bypass rules, then the relays.
func (m *gatewayManager) stopAll(reason string) {
	m.stopSingBox(reason)
	m.agent.bypass.deactivate()
	m.stopSlot(&m.primary, reason)
	for _, slot := range m.splitSlots() {
		m.stopSlot(slot, reason)
	}
}

// effectiveSplitConfigs is the configured splits at their positions, as
// resolveGatewaySplits numbers them: the legacy split is the capture list in
// cidr mode and takes no position, and positions stop at maxGatewaySplits.
func effectiveSplitConfigs(cfg Config) []SplitConfig {
	out := []SplitConfig{}
	for _, split := range cfg.gatewaySplitConfigs() {
		if split.Legacy && cfg.GatewayCapture == "cidr" {
			continue
		}
		if len(out) >= maxGatewaySplits {
			break
		}
		out = append(out, split)
	}
	return out
}

func slotState(slot *relaySlot, now time.Time, off string, panelStopped bool) string {
	switch {
	case panelStopped:
		return "panel_stopped"
	case off != "":
		return "off"
	case slot == nil:
		return "stopped"
	}
	alive := slot.relay != nil && slot.relay.alive()
	switch {
	case alive && !slot.leaseExpiry.IsZero() && now.Before(slot.leaseExpiry):
		return "running"
	case slot.leaseErr != "":
		return "error"
	case alive:
		return "starting"
	default:
		return "stopped"
	}
}

func fillSlotView(view *GatewaySlotView, slot *relaySlot) {
	if slot == nil {
		return
	}
	view.RelayRunning = slot.relay != nil && slot.relay.alive()
	view.Candidates = slot.candidates
	if !slot.leaseExpiry.IsZero() {
		view.LeaseExpiry = formatRFC3339(slot.leaseExpiry)
	}
	view.LeaseError = slot.leaseErr
	view.RefreshPending = slot.forceRenew
}

func (a *Agent) gatewayView() *GatewayView {
	cfg := a.config()
	gatewayMode := cfg.gatewayActive()
	view := &GatewayView{
		Mode:       "relay",
		Active:     true,
		CarrierID:  cfg.GatewayForwardID,
		WantScope:  cfg.GatewayRouteScope,
		WantPolicy: cfg.GatewayRoutePolicy,
		SocksPort:  gatewaySocksPort,
		BypassCN:   cfg.GatewayBypassCN,
		DNSMode:    firstNonEmpty(cfg.GatewayDNSMode, "auto"),
		Carriers:   []GatewayCarrierView{},
		Capture:    cfg.GatewayCapture,
		Slots:      []GatewaySlotView{},
	}
	splitConfigs := []SplitConfig{}
	if gatewayMode {
		view.Mode = "gateway"
		view.Enabled = cfg.GatewayEnabled
		view.TunAddress = cfg.GatewayTunAddress
		if cfg.GatewayCapture != "cidr" && tunIPv6Usable(cfg) {
			view.TunAddress6 = cfg.GatewayTunAddress6
		}
		splitConfigs = effectiveSplitConfigs(cfg)
	}
	a.mu.Lock()
	capability := a.nativeCap
	manager := a.gateway
	a.mu.Unlock()
	if capability != nil && capability.Enabled {
		if !gatewayMode {
			// The router edition's relay is on whenever the Panel grants it.
			view.Enabled = true
		}
		view.NativeGranted = true
		for _, carrier := range capability.Forwards {
			view.Carriers = append(view.Carriers, GatewayCarrierView{
				ForwardID:         carrier.ForwardID,
				Direction:         carrier.Direction,
				RouteScopes:       nativeScopesOf(carrier),
				DefaultRouteScope: carrier.DefaultRouteScope,
				UnrestrictedOK:    carrier.UnrestrictedOK,
				Default:           capability.DefaultForwardID != nil && *capability.DefaultForwardID == carrier.ForwardID,
			})
		}
	}
	if gatewayMode {
		view.BypassRules, view.BypassError = a.bypass.view()
	}
	now := time.Now()
	primaryView := GatewaySlotView{Name: "primary", Role: "primary", CarrierID: view.CarrierID, SocksPort: gatewaySocksPort, State: "stopped"}
	splitViews := make([]GatewaySplitView, 0, len(splitConfigs))
	slotViews := make([]GatewaySlotView, 0, len(splitConfigs))
	for i, split := range splitConfigs {
		splitViews = append(splitViews, GatewaySplitView{
			Name:       split.Name,
			CarrierID:  split.ForwardID,
			WantPolicy: split.RoutePolicy,
			CIDRFile:   split.CIDRFile,
			SocksPort:  splitPort(i),
		})
		slotViews = append(slotViews, GatewaySlotView{
			Name: split.Name, Role: splitTag(i), CarrierID: split.ForwardID, SocksPort: splitPort(i),
			CIDRFile: split.CIDRFile, State: "stopped",
		})
	}
	if manager != nil {
		manager.mu.Lock()
		primary := &manager.primary
		view.RelayRunning = primary.relay != nil && primary.relay.alive()
		view.SingBoxRun = manager.singbox != nil && manager.singbox.alive()
		view.Running = view.RelayRunning
		if gatewayMode {
			view.Running = view.RelayRunning && view.SingBoxRun
		}
		plan := manager.plan
		if plan.Carrier.ForwardID > 0 {
			view.CarrierID = plan.Carrier.ForwardID
			view.Direction = plan.Native.Direction
			view.RouteScope = plan.Scope
			view.RoutePolicy = plan.Policy
			view.Unrestricted = plan.Native.UnrestrictedOK
			view.Notes = append([]string(nil), plan.Notes...)
			view.CaptureCIDRs = len(plan.CaptureCIDRs)
		}
		view.RuleSets = manager.ruleSetSource
		view.Candidates = primary.candidates
		if !primary.leaseExpiry.IsZero() {
			view.LeaseExpiry = formatRFC3339(primary.leaseExpiry)
		}
		view.LeaseError = primary.leaseErr
		view.LastError = manager.lastErr

		primaryView.CarrierID = view.CarrierID
		primaryView.RouteScope = plan.Scope
		primaryView.RoutePolicy = plan.Policy
		primaryView.CIDRs = len(plan.CaptureCIDRs)
		if len(plan.CaptureCIDRs) > 0 {
			primaryView.CIDRFile = cfg.GatewaySplitCIDRFile
		}
		fillSlotView(&primaryView, primary)
		_, primaryStopped := manager.panelStopped[view.CarrierID]
		primaryView.PanelStopped = primaryStopped
		primaryView.State = slotState(primary, now, "", primaryStopped)
		primaryView.LastError = manager.lastErr

		for i := range splitViews {
			var slot *relaySlot
			if i < len(manager.splits) {
				slot = manager.splits[i]
			}
			split, planned := plan.split(i)
			sv := &splitViews[i]
			lv := &slotViews[i]
			off := ""
			if planned {
				off = split.Off
				sv.CIDRs = len(split.CIDRs)
				lv.CIDRs = len(split.CIDRs)
				if split.Plan != nil {
					sv.Direction = split.Plan.Native.Direction
					sv.RoutePolicy = split.Plan.Policy
					lv.RouteScope = split.Plan.Scope
					lv.RoutePolicy = split.Plan.Policy
				}
			} else if plan.Carrier.ForwardID == 0 {
				off = "the primary carrier is not available"
			}
			sv.Off = off
			fillSlotView(lv, slot)
			if slot != nil {
				sv.RelayRunning = lv.RelayRunning
				sv.Candidates = slot.candidates
				sv.LeaseExpiry = lv.LeaseExpiry
				sv.LeaseError = slot.leaseErr
				sv.Active = planned && split.active() && i < len(manager.splits) && manager.activeSplits > 0 &&
					view.SingBoxRun && sv.RelayRunning && !slot.leaseExpiry.IsZero() && now.Before(slot.leaseExpiry)
			}
			_, stopped := manager.panelStopped[sv.CarrierID]
			lv.PanelStopped = stopped
			lv.State = slotState(slot, now, off, stopped)
			lv.LastError = off
		}
		manager.mu.Unlock()
	} else {
		for i := range splitViews {
			slotViews[i].State = "stopped"
		}
	}
	view.Slots = append([]GatewaySlotView{primaryView}, slotViews...)
	if len(splitViews) > 0 {
		view.Splits = splitViews
		first := splitViews[0]
		view.Split = &first
	}
	return view
}
