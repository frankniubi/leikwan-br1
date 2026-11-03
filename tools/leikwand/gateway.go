package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sync"
	"syscall"
	"time"
)

// Native outbound relay.
//
// When the Panel grants native outbound for this device, the agent keeps one
// ultra-client process running as a loopback SOCKS5 facade on 127.0.0.1:2080
// whose egress is the Panel-selected native line. This is the same relay the
// desktop clients expose; the router edition deliberately does NOT run a
// transparent proxy on top of it. Routing LAN traffic through it is the
// operator's decision (firewall redirects, a separate sing-box, ...).
//
// The relay is managed like any other supervised process with an independent
// lifecycle: a renewal is delivered to the running relay over its control
// socket, and the relay is only restarted when it or its control channel is
// gone, or the plan changes.

const gatewaySocksPort = 2080

type GatewayView struct {
	Enabled       bool                 `json:"enabled"`
	Active        bool                 `json:"active"`
	Running       bool                 `json:"running"`
	RelayRunning  bool                 `json:"relay_running"`
	CarrierID     int                  `json:"carrier_forward_id"`
	Direction     string               `json:"direction,omitempty"`
	RouteScope    string               `json:"route_scope"`
	RoutePolicy   string               `json:"route_policy"`
	WantScope     string               `json:"configured_route_scope"`
	WantPolicy    string               `json:"configured_route_policy"`
	Unrestricted  bool                 `json:"unrestricted_allowed"`
	SocksPort     int                  `json:"socks_port"`
	Candidates    int                  `json:"candidates"`
	LeaseExpiry   string               `json:"lease_expires_at,omitempty"`
	LeaseError    string               `json:"lease_error,omitempty"`
	Notes         []string             `json:"notes,omitempty"`
	LastError     string               `json:"last_error,omitempty"`
	Carriers      []GatewayCarrierView `json:"carriers"`
	NativeGranted bool                 `json:"native_granted"`
}

type GatewayCarrierView struct {
	ForwardID         int      `json:"forward_id"`
	Direction         string   `json:"direction"`
	RouteScopes       []string `json:"route_scopes"`
	DefaultRouteScope string   `json:"default_route_scope"`
	UnrestrictedOK    bool     `json:"unrestricted_allowed"`
	Default           bool     `json:"default"`
}

// relaySlot is the native relay and its lease state.
type relaySlot struct {
	role string // "primary", for logs
	port int    // SOCKS5 facade on 127.0.0.1
	dir  string // run directory below <run_dir>/gateway

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

	// counters is guarded by gatewayManager.usageMu.
	counters counterPair
}

type gatewayManager struct {
	agent   *Agent
	mu      sync.Mutex
	plan    gatewayPlan
	primary relaySlot

	// usageMu serialises relay accounting between the heartbeat and a stop.
	usageMu sync.Mutex

	lastErr string
}

func newGatewayManager(a *Agent) *gatewayManager {
	return &gatewayManager{
		agent:   a,
		primary: relaySlot{role: "primary", port: gatewaySocksPort, dir: "relay"},
	}
}

func (a *Agent) triggerGatewayReconcile() {
	select {
	case a.gatewaySignal <- struct{}{}:
	default:
	}
}

// gatewayLoop always runs so the relay comes up (and tears down) purely from
// what the Panel grants, without a mode switch or a service restart.
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

func (m *gatewayManager) reconcile(ctx context.Context) {
	a := m.agent
	cfg := a.config()
	if ctx.Err() != nil {
		return
	}
	if !a.enrolled() {
		m.stopAll("not_enrolled")
		m.setErr("")
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
		// A revoked or never-granted native outbound stops the relay; status
		// names the reason.
		m.setErr("%v", err)
		m.stopAll("no_carrier")
		m.mu.Lock()
		m.plan = gatewayPlan{}
		m.mu.Unlock()
		return
	}
	m.mu.Lock()
	planChanged := m.primary.relayKey != "" && m.primary.relayKey != plan.key()
	m.plan = plan
	m.primary.plan = plan.carrierPlan
	m.mu.Unlock()
	if planChanged {
		a.log.Info("gateway", "plan changed to carrier=%d scope=%s policy=%s; restarting", plan.Carrier.ForwardID, plan.Scope, plan.Policy)
		m.stopAll("plan_changed")
		// An operator-driven change is not a crash; no restart cooldown.
		m.mu.Lock()
		m.primary.relayStoppedAt = time.Time{}
		m.mu.Unlock()
	}

	if m.relayDead(&m.primary) {
		m.stopSlot(&m.primary, "relay_exited")
	}
	if m.leaseDue(&m.primary, time.Now(), cfg) {
		if err := m.renewLease(ctx, &m.primary, plan.carrierPlan); err != nil {
			m.noteLeaseFailure(&m.primary, err)
			m.setErr("%v", err)
		}
	}
	m.setErr("")
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

func (m *gatewayManager) leaseValid(slot *relaySlot, now time.Time) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return slot.relay != nil && slot.relay.alive() && !slot.leaseExpiry.IsZero() && now.Before(slot.leaseExpiry)
}

func (m *gatewayManager) leaseDue(slot *relaySlot, now time.Time, cfg Config) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
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
// does the same) and delivers it to the slot's relay, starting the relay
// first when needed.
func (m *gatewayManager) renewLease(ctx context.Context, slot *relaySlot, plan carrierPlan) error {
	a := m.agent
	m.mu.Lock()
	slot.lastLeaseAt = time.Now()
	m.mu.Unlock()

	if err := a.freshPull(ctx); err != nil {
		return fmt.Errorf("refresh native grant: %w", err)
	}
	fresh, err := a.gatewayPlan()
	if err != nil {
		return err
	}
	current := fresh.carrierPlan
	if current.key() != plan.key() {
		// The next reconcile restarts with the new plan.
		return errors.New("native plan changed during renewal")
	}
	plan = current
	if failure := validatePolicy(plan.Carrier.UltraAccess, plan.Carrier, time.Now()); failure != "" {
		switch failure {
		case "service_state", "quota", "target_digest_mismatch", "forward_id", "protocol":
			m.stopAll("policy_" + failure)
		}
		return fmt.Errorf("native carrier #%d grant unusable: %s", plan.Carrier.ForwardID, failure)
	}

	frames, _, err := a.requestNativeLeases(ctx, plan)
	if err != nil {
		return err
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

// gatewayRelayPaths is the relay's run directory.
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
	if kind := portOwnerKind(slot.port); kind == "foreign" {
		return nil, fmt.Errorf("127.0.0.1:%d is held by another program; the %s native relay cannot start", slot.port, slot.role)
	} else if kind == "managed" {
		_ = reclaimManagedPort(slot.port)
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
	deadline := time.Now().Add(8 * time.Second)
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
	if proc != nil {
		slot.relayStoppedAt = time.Now()
	}
	m.mu.Unlock()
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

// collectRelayUsage reports the relay's traffic as Ultra usage of its native
// carrier, exactly like an ordinary runtime's.
func (m *gatewayManager) collectRelayUsage() {
	m.collectSlotUsage(&m.primary)
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
	if status.PolicyGeneration != "" {
		generation = status.PolicyGeneration
	}
	m.usageMu.Lock()
	defer m.usageMu.Unlock()
	m.agent.recordUsage(forwardID, generation, status, &slot.counters)
}

// stopAll tears the relay down.
func (m *gatewayManager) stopAll(reason string) {
	m.stopSlot(&m.primary, reason)
}

func (a *Agent) gatewayView() *GatewayView {
	cfg := a.config()
	view := &GatewayView{
		Active:     true,
		CarrierID:  cfg.GatewayForwardID,
		WantScope:  cfg.GatewayRouteScope,
		WantPolicy: cfg.GatewayRoutePolicy,
		SocksPort:  gatewaySocksPort,
		Carriers:   []GatewayCarrierView{},
	}
	a.mu.Lock()
	capability := a.nativeCap
	manager := a.gateway
	a.mu.Unlock()
	if capability != nil && capability.Enabled {
		view.Enabled = true
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
	if manager == nil {
		return view
	}
	manager.mu.Lock()
	defer manager.mu.Unlock()
	primary := &manager.primary
	view.RelayRunning = primary.relay != nil && primary.relay.alive()
	view.Running = view.RelayRunning
	if manager.plan.Carrier.ForwardID > 0 {
		view.CarrierID = manager.plan.Carrier.ForwardID
		view.Direction = manager.plan.Native.Direction
		view.RouteScope = manager.plan.Scope
		view.RoutePolicy = manager.plan.Policy
		view.Unrestricted = manager.plan.Native.UnrestrictedOK
		view.Notes = append([]string(nil), manager.plan.Notes...)
	}
	view.Candidates = primary.candidates
	if !primary.leaseExpiry.IsZero() {
		view.LeaseExpiry = formatRFC3339(primary.leaseExpiry)
	}
	view.LeaseError = primary.leaseErr
	view.LastError = manager.lastErr
	return view
}
