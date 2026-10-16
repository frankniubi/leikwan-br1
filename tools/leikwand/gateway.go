package main

import (
	"context"
	"encoding/json"
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

// Gateway mode turns the router into a transparent egress for the whole LAN.
//
// Data path: LAN client -> router (default gateway) -> sing-box TUN inbound
// -> SOCKS5 facade of a native ultra-client (the relay) -> Panel-selected
// Ultra line -> the line's exit.
//
// Two supervised processes, two independent lifecycles:
//   - the relay holds the native lease batch. A renewal is delivered to the
//     running relay over its control socket; the relay is only restarted when
//     it or its control channel is gone, or the plan changes.
//   - sing-box owns auto_route (its own ip rules and routes) and is only
//     restarted when its generated configuration changes, never on a lease
//     rotation, so LAN sessions survive renewals.
//
// The relay's own connections are kept out of the TUN by bypassRoutes.

type GatewayView struct {
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
}

type GatewayCarrierView struct {
	ForwardID         int      `json:"forward_id"`
	Direction         string   `json:"direction"`
	RouteScopes       []string `json:"route_scopes"`
	DefaultRouteScope string   `json:"default_route_scope"`
	UnrestrictedOK    bool     `json:"unrestricted_allowed"`
	Default           bool     `json:"default"`
}

type gatewayManager struct {
	agent *Agent

	mu   sync.Mutex
	plan gatewayPlan

	relay          *runtimeProcess
	relayKey       string
	relayStoppedAt time.Time
	leaseExpiry    time.Time
	lastLeaseAt    time.Time
	leaseFailures  int
	leaseErr       string
	candidates     int

	singbox          *runtimeProcess
	singboxGen       string
	singboxStoppedAt time.Time
	ruleSetSource    string

	// usageMu serialises relay accounting between the heartbeat and a stop.
	usageMu       sync.Mutex
	relayCounters counterPair

	lastErr string
}

// gatewayRelayOwner is the bypassRoutes owner key of the native relay.
const gatewayRelayOwner = "gateway"

func (a *Agent) triggerGatewayReconcile() {
	select {
	case a.gatewaySignal <- struct{}{}:
	default:
	}
}

// gatewayLoop always runs so that enabling the gateway from LuCI takes effect
// on "reload" without a service restart; it idles while the mode is off.
func (a *Agent) gatewayLoop(ctx context.Context) {
	manager := &gatewayManager{agent: a}
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
	if !cfg.gatewayActive() {
		m.stopAll("disabled")
		m.setErr("")
		return
	}
	if !a.enrolled() {
		m.stopAll("not_enrolled")
		m.setErr("")
		return
	}
	if !fileExists(cfg.GatewaySingBoxBin) {
		m.setErr("sing-box is not installed at %s (opkg install sing-box kmod-tun)", cfg.GatewaySingBoxBin)
		m.stopAll("singbox_missing")
		return
	}
	a.mu.Lock()
	pulled := !a.lastPullAt.IsZero()
	a.mu.Unlock()
	if !pulled {
		// Not a fault: the capability arrives with the first config pull.
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
		return
	}
	m.mu.Lock()
	planChanged := m.relayKey != "" && m.relayKey != plan.key()
	m.plan = plan
	m.mu.Unlock()
	if planChanged {
		a.log.Info("gateway", "plan changed to carrier=%d scope=%s policy=%s; restarting", plan.Carrier.ForwardID, plan.Scope, plan.Policy)
		m.stopAll("plan_changed")
		// An operator-driven change is not a crash; no restart cooldown.
		m.mu.Lock()
		m.relayStoppedAt = time.Time{}
		m.singboxStoppedAt = time.Time{}
		m.mu.Unlock()
	}

	if m.relayDead() {
		m.stopRelay("relay_exited")
	}
	if m.leaseDue(time.Now(), cfg) {
		if err := m.renewLease(ctx, plan); err != nil {
			m.mu.Lock()
			m.leaseFailures++
			m.leaseErr = redactSecrets(err.Error())
			m.mu.Unlock()
			m.setErr("%v", err)
		}
	}
	if !m.leaseValid(time.Now()) {
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
	if err := m.ensureSingBox(ctx, cfg, plan); err != nil {
		m.setErr("%v", err)
		return
	}
	m.setErr("")
}

func (m *gatewayManager) relayDead() bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.relay != nil && !m.relay.alive()
}

func (m *gatewayManager) singboxRunning() bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.singbox != nil && m.singbox.alive()
}

func (m *gatewayManager) leaseValid(now time.Time) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.relay != nil && m.relay.alive() && !m.leaseExpiry.IsZero() && now.Before(m.leaseExpiry)
}

func (m *gatewayManager) leaseDue(now time.Time, cfg Config) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.relay == nil || m.leaseExpiry.IsZero() {
		if m.leaseFailures > 0 {
			return now.Sub(m.lastLeaseAt) >= time.Duration(clampInt(m.leaseFailures*5, 5, 60))*time.Second
		}
		// A relay that just died gets the normal restart cooldown.
		return m.relayStoppedAt.IsZero() || now.Sub(m.relayStoppedAt) >= time.Duration(clampInt(cfg.RestartCooldownSecs, 5, 60))*time.Second
	}
	if m.leaseFailures > 0 && now.Sub(m.lastLeaseAt) < time.Duration(clampInt(m.leaseFailures*5, 5, 60))*time.Second {
		return false
	}
	return now.Add(time.Duration(cfg.LeaseRenewLeadSecs) * time.Second).After(m.leaseExpiry)
}

// renewLease mints a native batch from a freshly pulled grant (the session
// ticket rotates independently of the stable config revision, so the desktop
// does the same), installs the bypass set, then delivers it to the relay,
// starting the relay first when needed.
func (m *gatewayManager) renewLease(ctx context.Context, plan gatewayPlan) error {
	a := m.agent
	m.mu.Lock()
	m.lastLeaseAt = time.Now()
	m.mu.Unlock()

	if err := a.freshPull(ctx); err != nil {
		return fmt.Errorf("refresh native grant: %w", err)
	}
	fresh, err := a.gatewayPlan()
	if err != nil {
		return err
	}
	if fresh.key() != plan.key() {
		// The next reconcile restarts with the new plan.
		return errors.New("native plan changed during renewal")
	}
	plan = fresh
	if failure := validatePolicy(plan.Carrier.UltraAccess, plan.Carrier, time.Now()); failure != "" {
		switch failure {
		case "service_state", "quota", "target_digest_mismatch", "forward_id", "protocol":
			m.stopAll("policy_" + failure)
		}
		return fmt.Errorf("native carrier #%d grant unusable: %s", plan.Carrier.ForwardID, failure)
	}

	frames, bypass, err := a.requestNativeLeases(ctx, plan)
	if err != nil {
		return err
	}
	a.bypass.set(gatewayRelayOwner, bypass)
	if err := a.bypass.sync(); err != nil {
		a.log.Warn("gateway", "relay bypass rules incomplete: %v", err)
	}

	m.mu.Lock()
	relay := m.relay
	m.mu.Unlock()
	startedHere := relay == nil || !relay.alive()
	if startedHere {
		relay, err = m.startRelay(ctx, plan, frames[0])
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
			m.stopRelay("lease_rejected")
		}
		return fmt.Errorf("native relay rejected the lease batch: %v", err)
	}
	if startedHere {
		// The relay blocks in WaitValid() until a lease exists and only then
		// opens the SOCKS5 facade, so the port is checked after delivery.
		if err := waitForListenPort(ctx, gatewaySocksPort, 20*time.Second); err != nil {
			m.stopRelay("socks_not_ready")
			return err
		}
	}
	expiry := earliestLeaseExpiry(frames)
	m.mu.Lock()
	m.leaseExpiry = expiry
	m.leaseFailures = 0
	m.leaseErr = ""
	m.candidates = len(frames)
	m.mu.Unlock()
	a.log.Info("gateway", "native lease installed (carrier=%d candidates=%d scope=%s policy=%s expires=%s)",
		plan.Carrier.ForwardID, len(frames), plan.Scope, plan.Policy, formatRFC3339(expiry))
	return nil
}

func gatewayRelayPaths(runDir string) runtimePaths {
	dir := filepath.Join(runDir, "gateway", "relay")
	return runtimePaths{
		dir:           dir,
		configPath:    filepath.Join(dir, "runtime.json"),
		controlSocket: filepath.Join(dir, "control.sock"),
		statusPath:    filepath.Join(dir, "status.json"),
	}
}

func (m *gatewayManager) startRelay(ctx context.Context, plan gatewayPlan, first LeaseFrame) (*runtimeProcess, error) {
	a := m.agent
	cfg := a.config()
	if !fileExists(cfg.RuntimeBin) {
		return nil, fmt.Errorf("ultra runtime %s is not installed", cfg.RuntimeBin)
	}
	if err := ensurePrivateDir(filepath.Join(cfg.RunDir, "gateway")); err != nil {
		return nil, err
	}
	paths := gatewayRelayPaths(cfg.RunDir)
	cleanupRuntimeDir(paths)
	runtime := buildNativeRuntimeConfig(cfg, plan.Carrier.ForwardID, paths, first.PolicyGeneration, first.TargetDigest, plan.Policy)
	if err := writeRuntimeConfig(paths, runtime); err != nil {
		return nil, err
	}
	if kind := portOwnerKind(gatewaySocksPort); kind == "foreign" {
		return nil, fmt.Errorf("127.0.0.1:%d is held by another program; the native relay cannot start", gatewaySocksPort)
	} else if kind == "managed" {
		_ = reclaimManagedPort(gatewaySocksPort)
		time.Sleep(500 * time.Millisecond)
	}
	cmd := exec.Command(cfg.RuntimeBin, "--config", paths.configPath)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stdout
	cmd.Env = append(os.Environ(), "LEIKWAN_OPENWRT_FORWARD_ID="+itoa(plan.Carrier.ForwardID))
	cmd.SysProcAttr = detachedProcAttr()
	if err := cmd.Start(); err != nil {
		return nil, fmt.Errorf("start native relay: %w", err)
	}
	proc := &runtimeProcess{forwardID: plan.Carrier.ForwardID, native: true, paths: paths, cmd: cmd, pid: cmd.Process.Pid, startedAt: time.Now()}
	go watchGatewayProcess(a, proc, "native relay")
	m.mu.Lock()
	m.relay = proc
	m.relayKey = plan.key()
	m.mu.Unlock()
	a.log.Info("gateway", "native relay started pid=%d (carrier=%d socks=127.0.0.1:%d)", proc.pid, plan.Carrier.ForwardID, gatewaySocksPort)
	if err := waitForControlSocket(ctx, paths.controlSocket, 20*time.Second); err != nil {
		m.stopRelay("readiness_failed")
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
	rendered, source := renderGatewayConfig(cfg, plan.Scope, plan.Policy)
	encoded, err := json.MarshalIndent(rendered, "", "  ")
	if err != nil {
		return err
	}
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
	if out, err := runCommand(10*time.Second, cfg.GatewaySingBoxBin, "check", "-c", configPath); err != nil {
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
	m.mu.Unlock()
	a.log.Info("gateway", "sing-box started pid=%d (scope=%s policy=%s dns=%s rule_sets=%s)",
		proc.pid, plan.Scope, plan.Policy, firstNonEmpty(cfg.GatewayDNSMode, "auto"), source)
	return nil
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

func (m *gatewayManager) stopSingBox(reason string) {
	m.mu.Lock()
	proc := m.singbox
	m.singbox = nil
	m.singboxGen = ""
	if proc != nil {
		m.singboxStoppedAt = time.Now()
	}
	m.mu.Unlock()
	if proc != nil {
		m.agent.log.Info("gateway", "stopping sing-box (%s)", reason)
		stopGatewayProcess(proc, "sing-box", m.agent.log)
	}
}

func (m *gatewayManager) stopRelay(reason string) {
	// Bill what the relay carried before its counters disappear with it.
	m.collectRelayUsage()
	m.mu.Lock()
	proc := m.relay
	m.relay = nil
	m.relayKey = ""
	m.leaseExpiry = time.Time{}
	m.candidates = 0
	if proc != nil {
		m.relayStoppedAt = time.Now()
	}
	m.mu.Unlock()
	m.agent.bypass.remove(gatewayRelayOwner)
	if proc != nil {
		m.agent.log.Info("gateway", "stopping native relay (%s)", reason)
		stopGatewayProcess(proc, "native relay", m.agent.log)
		cleanupRuntimeDir(proc.paths)
	}
	// A new relay counts from zero again.
	m.usageMu.Lock()
	m.relayCounters = counterPair{}
	m.usageMu.Unlock()
}

// collectRelayUsage reports the relay's traffic as Ultra usage of the native
// carrier, exactly like an ordinary runtime's.
func (m *gatewayManager) collectRelayUsage() {
	m.mu.Lock()
	relay := m.relay
	forwardID := m.plan.Carrier.ForwardID
	generation := policyGeneration(m.plan.Carrier.UltraAccess)
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
	m.agent.recordUsage(forwardID, generation, status, &m.relayCounters)
}

// stopAll tears down in dependency order: sing-box first (its routes go with
// its TUN), then the bypass rules, then the relay.
func (m *gatewayManager) stopAll(reason string) {
	m.stopSingBox(reason)
	m.agent.bypass.deactivate()
	m.stopRelay(reason)
}

func (a *Agent) gatewayView() *GatewayView {
	cfg := a.config()
	view := &GatewayView{
		Enabled:    cfg.GatewayEnabled,
		Active:     cfg.gatewayActive(),
		CarrierID:  cfg.GatewayForwardID,
		WantScope:  cfg.GatewayRouteScope,
		WantPolicy: cfg.GatewayRoutePolicy,
		TunAddress: cfg.GatewayTunAddress,
		SocksPort:  gatewaySocksPort,
		BypassCN:   cfg.GatewayBypassCN,
		DNSMode:    firstNonEmpty(cfg.GatewayDNSMode, "auto"),
		Carriers:   []GatewayCarrierView{},
	}
	a.mu.Lock()
	capability := a.nativeCap
	manager := a.gateway
	a.mu.Unlock()
	if capability != nil && capability.Enabled {
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
	view.BypassRules, view.BypassError = a.bypass.view()
	if manager == nil {
		return view
	}
	manager.mu.Lock()
	defer manager.mu.Unlock()
	view.RelayRunning = manager.relay != nil && manager.relay.alive()
	view.SingBoxRun = manager.singbox != nil && manager.singbox.alive()
	view.Running = view.RelayRunning && view.SingBoxRun
	if manager.plan.Carrier.ForwardID > 0 {
		view.CarrierID = manager.plan.Carrier.ForwardID
		view.Direction = manager.plan.Native.Direction
		view.RouteScope = manager.plan.Scope
		view.RoutePolicy = manager.plan.Policy
		view.Unrestricted = manager.plan.Native.UnrestrictedOK
		view.Notes = append([]string(nil), manager.plan.Notes...)
	}
	view.RuleSets = manager.ruleSetSource
	view.Candidates = manager.candidates
	if !manager.leaseExpiry.IsZero() {
		view.LeaseExpiry = formatRFC3339(manager.leaseExpiry)
	}
	view.LeaseError = manager.leaseErr
	view.LastError = manager.lastErr
	return view
}
