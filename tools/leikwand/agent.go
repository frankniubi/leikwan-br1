package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"runtime"
	"sort"
	"strings"
	"sync"
	"time"
)

// runtimeState is the full picture of one supervised forward: the process, the
// contract that produced it, the live lease, and the most recent health view.
type runtimeState struct {
	proc     *runtimeProcess
	forward  ForwardConfig
	native   bool
	contract string
	policy   *UltraPolicy

	leaseExpiry   time.Time
	leaseIDs      []string
	candidates    int
	lastLeaseAt   time.Time
	leaseFailures int
	lastLeaseErr  string

	status       *RuntimeStatus
	statusAt     time.Time
	lastCounters counterPair
	userStopped  bool

	// leaseInstalledAt is when the runtime last accepted a candidate batch.
	// Before that moment the runtime has no listener at all, because
	// ultra-client blocks in WaitValid() until a lease exists.
	leaseInstalledAt time.Time
}

// listenReadyGrace absorbs scheduler jitter between accepting a lease and
// binding the listener.
const listenReadyGrace = 10 * time.Second

type counterPair struct {
	in  int64
	out int64
}

// Agent owns every long-running loop. Loops communicate through the store and
// a single reconcile channel, so a Panel change never races a health restart.
type Agent struct {
	cfg      Config
	store    *Store
	secrets  SecretState
	panel    *PanelClient
	deviceID string
	deviceNm string
	log      *Logger

	mu          sync.Mutex
	runtimes    map[int]*runtimeState
	forwards    map[int]ForwardConfig
	nativeCap   *NativeOutboundCapability
	lastPullAt  time.Time
	lastPullErr string
	clientMeta  ClientMeta
	diagToken   string

	// lastPullCode is the machine-readable code of lastPullErr (e.g.
	// client_online_limit_reached); pullLimit is set only for that code.
	lastPullCode string
	pullLimit    *panelError

	reconcile chan string
	startedAt time.Time

	forceStatusFlush chan struct{}
	offline          bool

	// wan and gateway are set by their own loops; they are nil when the
	// corresponding feature is disabled.
	wan           *wanManager
	gateway       *gatewayManager
	gatewaySignal chan struct{}

	// secretStamp tracks the credential file so an operator (or a restored
	// backup, or an out-of-band pairing) can enrol a running agent without
	// restarting it.
	secretStamp  time.Time
	secretSeenAt time.Time
}

func newAgent(cfg Config, store *Store, secrets SecretState, log *Logger) *Agent {
	deviceID := deviceIdentity(cfg, secrets)
	deviceNm := deviceDisplayName(cfg, secrets)
	panel := newPanelClient(cfg, secrets, deviceID, deviceNm)
	panel.egress.log = log
	panel.leaseKeys = newLeaseKeyStore(cfg.DataDir, log)
	return &Agent{
		cfg:              cfg,
		store:            store,
		secrets:          secrets,
		panel:            panel,
		deviceID:         deviceID,
		deviceNm:         deviceNm,
		log:              log,
		runtimes:         map[int]*runtimeState{},
		forwards:         map[int]ForwardConfig{},
		reconcile:        make(chan string, 16),
		forceStatusFlush: make(chan struct{}, 1),
		gatewaySignal:    make(chan struct{}, 1),
		startedAt:        time.Now(),
	}
}

func (a *Agent) triggerReconcile(reason string) {
	select {
	case a.reconcile <- reason:
	default:
	}
}

func (a *Agent) enrolled() bool { return a.panel.hasToken() }

// Run starts every loop and blocks until the context is cancelled.
func (a *Agent) Run(ctx context.Context) error {
	if err := ensurePrivateDir(a.cfg.RunDir); err != nil {
		return fmt.Errorf("prepare run directory: %w", err)
	}
	if err := ensurePrivateDir(a.cfg.DataDir); err != nil {
		return fmt.Errorf("prepare data directory: %w", err)
	}

	var wg sync.WaitGroup
	start := func(fn func(context.Context)) {
		wg.Add(1)
		go func() {
			defer wg.Done()
			fn(ctx)
		}()
	}

	start(a.pullLoop)
	start(a.heartbeatLoop)
	start(a.healthLoop)
	start(a.leaseLoop)
	start(a.reconcileLoop)
	if a.cfg.UplinkEnabled {
		start(a.uplinkLoop)
	}
	if a.cfg.gatewayActive() {
		start(a.gatewayLoop)
	}

	if !a.enrolled() {
		a.log.Warn("agent", "no agent token yet; open LuCI and complete onboarding (mode=%s)", a.cfg.Mode)
	}

	wg.Wait()
	a.shutdownRuntimes()
	return nil
}

func (a *Agent) shutdownRuntimes() {
	a.mu.Lock()
	states := make([]*runtimeState, 0, len(a.runtimes))
	for _, state := range a.runtimes {
		states = append(states, state)
	}
	a.runtimes = map[int]*runtimeState{}
	a.mu.Unlock()
	for _, state := range states {
		a.stopRuntime(state.proc, "shutdown")
	}
}

// ---------------------------------------------------------------------------
// Pull loop: the only channel through which the Panel drives desired state.
// ---------------------------------------------------------------------------

func (a *Agent) pullLoop(ctx context.Context) {
	timer := time.NewTimer(0)
	defer timer.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-timer.C:
		}
		a.watchCredentials()
		delay := time.Duration(a.cfg.PollIntervalSecs) * time.Second
		if !a.enrolled() {
			// Not onboarded yet: poll slowly so a LuCI enrolment is picked up
			// within a few seconds without hammering the Panel.
			timer.Reset(5 * time.Second)
			continue
		}
		pullCtx, cancel := context.WithTimeout(ctx, 40*time.Second)
		err := a.pullOnce(pullCtx)
		cancel()
		switch {
		case err == nil:
			delay = time.Duration(a.cfg.PollIntervalSecs) * time.Second
		case errors.Is(err, errNotModified):
			delay = time.Duration(a.cfg.PollIntervalSecs) * time.Second
		default:
			delay = a.notePullFailure(err)
		}
		timer.Reset(delay)
	}
}

// notePullFailure records a failed pull where LuCI and "ctl status" can see it
// and returns the delay before the next attempt. The currently applied
// forwards are deliberately left running: a control-plane refusal is not a
// reason to tear down the data plane.
func (a *Agent) notePullFailure(err error) time.Duration {
	message, code := describePanelFailure(err)
	limit, limited := asOnlineLimitError(err)
	a.store.UpdatePull(func(state *PullState) {
		state.Failures++
		state.LastError = message
		state.LastStatus = "error"
	})
	a.mu.Lock()
	a.lastPullErr = message
	a.lastPullCode = code
	a.pullLimit = nil
	if limited {
		copied := *limit
		a.pullLimit = &copied
	}
	a.mu.Unlock()
	if limited {
		// A slot only frees after another device has been silent for 180s, so
		// a fast backoff would just hammer the Panel. Retry at a steady pace.
		a.log.Warn("panel", "%s (retry in %s)", message, onlineLimitRetryInterval)
		return onlineLimitRetryInterval
	}
	backoff := time.Duration(clampInt(a.store.Pull().Failures*3, 5, 60)) * time.Second
	a.log.Warn("panel", "config pull failed: %s (retry in %s)", message, backoff)
	return backoff
}

// clearPullFailureLocked is called with a.mu held after any successful pull.
func (a *Agent) clearPullFailureLocked() {
	a.lastPullErr = ""
	a.lastPullCode = ""
	a.pullLimit = nil
}

func (a *Agent) pullOnce(ctx context.Context) error {
	pullState := a.store.Pull()
	since := ""
	if !pullState.LastPullAt.IsZero() {
		since = pullState.ConfigRevision
	}
	// The Panel caps the long poll at 25s; ask for a little less so the
	// client-side timeout always fires first.
	response, err := a.panel.Pull(ctx, since, 20)
	if errors.Is(err, errNotModified) {
		a.store.UpdatePull(func(state *PullState) {
			state.LastPullAt = time.Now()
			state.LastStatus = "not_modified"
			state.LastError = ""
			state.Failures = 0
		})
		a.mu.Lock()
		a.lastPullAt = time.Now()
		a.clearPullFailureLocked()
		a.mu.Unlock()
		return errNotModified
	}
	if err != nil {
		return err
	}

	a.mu.Lock()
	a.clientMeta = response.Client
	a.lastPullAt = time.Now()
	a.clearPullFailureLocked()
	a.nativeCap = response.NativeOutbound
	a.mu.Unlock()

	a.store.UpdatePull(func(state *PullState) {
		state.ConfigRevision = response.ConfigRevision
		state.ForwardRev = response.ForwardRevision
		state.LastPullAt = time.Now()
		state.LastStatus = "ok"
		state.LastError = ""
		state.Failures = 0
	})

	reason := "config_changed"
	if response.ForceApply {
		reason = "force_apply"
	}
	a.log.Info("panel", "config revision=%s forwards=%d force=%v", shortRevision(response.ConfigRevision), len(response.Forwards), response.ForceApply)
	a.applyForwards(response.Forwards)
	a.evaluateRuntimeDrift()
	a.triggerReconcile(reason)
	if a.cfg.gatewayActive() {
		a.triggerGatewayReconcile()
	}
	return nil
}

// applyForwards replaces the desired-state map. It never touches a running
// runtime directly; reconcile decides what that means.
func (a *Agent) applyForwards(forwards []ForwardConfig) {
	incoming := map[int]ForwardConfig{}
	for _, forward := range forwards {
		if !acceptForward(forward) {
			a.log.Debug("policy", "forward %d rejected locally (backend=%s client_mode=%s sidecar=%v aggregation=%v)",
				forward.ForwardID, forward.BackendMode, forward.ClientMode, forward.SidecarRequired, forward.Aggregation)
			continue
		}
		incoming[forward.ForwardID] = forward
	}
	a.mu.Lock()
	a.forwards = incoming
	a.mu.Unlock()
}

// acceptForward mirrors the desktop client's UltraForwardPolicy: this runtime
// speaks the Ultra protocol only, so forwards that need the bond/MPTCP/sidecar
// data path are declined here rather than silently mis-served.
func acceptForward(forward ForwardConfig) bool {
	if forward.ForwardID <= 0 {
		return false
	}
	if !strings.EqualFold(strings.TrimSpace(forward.BackendMode), "ultra") {
		return false
	}
	if status := strings.ToLower(strings.TrimSpace(forward.ServiceStatus)); status != "" && status != "active" {
		return false
	}
	if forward.SidecarRequired || forward.Aggregation {
		return false
	}
	switch strings.ToLower(strings.TrimSpace(forward.ClientMode)) {
	case "", "single", "race", "failover", "auto":
		return true
	default:
		return false
	}
}

// ---------------------------------------------------------------------------
// Reconcile loop
// ---------------------------------------------------------------------------

func (a *Agent) reconcileLoop(ctx context.Context) {
	for {
		select {
		case <-ctx.Done():
			return
		case reason := <-a.reconcile:
			a.reconcileNow(ctx, reason)
		}
	}
}

func (a *Agent) reconcileNow(ctx context.Context, reason string) {
	if !a.enrolled() {
		return
	}
	a.mu.Lock()
	desired := make(map[int]ForwardConfig, len(a.forwards))
	for k, v := range a.forwards {
		desired[k] = v
	}
	a.mu.Unlock()

	// 1. Start or refresh everything the Panel wants running.
	for id, forward := range desired {
		a.reconcileForward(ctx, forward, reason)
		_ = id
	}

	// 2. Stop anything the Panel no longer lists.
	a.mu.Lock()
	stale := []*runtimeState{}
	for id, state := range a.runtimes {
		if _, keep := desired[id]; !keep {
			stale = append(stale, state)
			delete(a.runtimes, id)
		}
	}
	a.mu.Unlock()
	for _, state := range stale {
		a.log.Info("runtime", "forward %d withdrawn by panel; stopping", state.forward.ForwardID)
		a.stopRuntime(state.proc, "withdrawn")
		a.store.DeleteApplied(state.forward.ForwardID)
	}
}

func (a *Agent) reconcileForward(ctx context.Context, forward ForwardConfig, reason string) {
	contract := contractSignature(forward)

	a.mu.Lock()
	state, exists := a.runtimes[forward.ForwardID]
	a.mu.Unlock()

	if exists && !state.userStopped {
		state.forward = forward
		if state.contract == contract && state.proc.alive() {
			// Nothing structural changed; the lease loop will pick up any new
			// policy generation without a restart.
			return
		}
		if state.contract != contract {
			a.log.Info("runtime", "forward %d contract changed (%s); restarting", forward.ForwardID, reason)
		}
	}

	// Never replace a healthy runtime before the replacement is validated:
	// port ownership is checked first so we fail without breaking the old one.
	if kind := portOwnerKind(forward.ListenPort); kind == "foreign" {
		a.log.Error("runtime", "forward %d port %d is held by a foreign process; not starting", forward.ForwardID, forward.ListenPort)
		a.markForwardError(forward.ForwardID, "port_in_use")
		return
	} else if kind == "managed" {
		a.log.Warn("runtime", "forward %d reclaiming stale runtime on port %d", forward.ForwardID, forward.ListenPort)
		if err := reclaimManagedPort(forward.ListenPort); err != nil {
			a.log.Warn("runtime", "forward %d could not reclaim port %d: %v", forward.ForwardID, forward.ListenPort, err)
		}
		time.Sleep(500 * time.Millisecond)
	}

	// Validate the port before tearing down the incumbent.
	if portOwnerKind(forward.ListenPort) == "foreign" {
		a.log.Error("runtime", "forward %d port %d still busy after reclaim; aborting restart", forward.ForwardID, forward.ListenPort)
		a.markForwardError(forward.ForwardID, "port_in_use")
		return
	}

	if exists {
		a.stopRuntime(state.proc, "replace")
	}

	proc, err := a.startRuntime(ctx, forward, false, forward.UltraAccess, "", "")
	if err != nil {
		a.log.Error("runtime", "forward %d start failed: %v", forward.ForwardID, err)
		a.markForwardError(forward.ForwardID, "start_failed")
		return
	}

	// The Ultra runtime calls WaitValid() before it binds any listener, so a
	// cold runtime has no open port until a lease has been installed. The
	// control socket is therefore the only honest readiness signal here; the
	// listen port is verified after the lease manager succeeds (see
	// runtimeState.leaseInstalledAt).
	if err := waitForControlSocket(ctx, pathsForForward(a.cfg.RunDir, forward.ForwardID).controlSocket, 20*time.Second); err != nil {
		a.log.Error("runtime", "forward %d did not become ready: %v", forward.ForwardID, err)
		a.stopRuntime(proc, "readiness_failed")
		a.markForwardError(forward.ForwardID, "readiness_failed")
		return
	}

	newState := &runtimeState{
		proc:     proc,
		forward:  forward,
		native:   false,
		contract: contract,
		policy:   forward.UltraAccess,
	}
	a.mu.Lock()
	a.runtimes[forward.ForwardID] = newState
	a.mu.Unlock()

	a.store.PutApplied(AppliedForward{
		ForwardID:        forward.ForwardID,
		Contract:         contract,
		PolicyGeneration: policyGeneration(forward.UltraAccess),
		ListenPort:       forward.ListenPort,
		Target:           forward.Target,
		Transport:        forward.Transport,
		ConfigPath:       pathsForForward(a.cfg.RunDir, forward.ForwardID).configPath,
		ControlSocket:    pathsForForward(a.cfg.RunDir, forward.ForwardID).controlSocket,
		StatusPath:       pathsForForward(a.cfg.RunDir, forward.ForwardID).statusPath,
		LastApplyReason:  reason,
		UpdatedAt:        time.Now(),
	})
}

func (a *Agent) markForwardError(forwardID int, code string) {
	a.log.Warn("runtime", "forward %d unhealthy: %s", forwardID, code)
}

// contractSignature captures exactly the inputs that require a runtime
// restart. Policy generation and digest are excluded on purpose: a policy
// refresh is delivered over the control socket and must not drop sessions.
func contractSignature(forward ForwardConfig) string {
	payload, _ := json.Marshal([]any{
		forward.ForwardID,
		forward.ListenPort,
		strings.TrimSpace(forward.Target),
		strings.ToLower(strings.TrimSpace(forward.Transport)),
		strings.ToLower(strings.TrimSpace(forward.BackendMode)),
	})
	return sha256Hex(string(payload))
}

func policyGeneration(policy *UltraPolicy) string {
	if policy == nil {
		return ""
	}
	return policy.PolicyGeneration
}

func shortRevision(value string) string {
	if len(value) <= 12 {
		return value
	}
	return value[:12]
}

// ---------------------------------------------------------------------------
// Health loop
// ---------------------------------------------------------------------------

func (a *Agent) healthLoop(ctx context.Context) {
	interval := time.Duration(a.cfg.HealthIntervalSecs) * time.Second
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
		a.healthOnce(ctx)
	}
}

func (a *Agent) healthOnce(ctx context.Context) {
	a.mu.Lock()
	states := make([]*runtimeState, 0, len(a.runtimes))
	for _, state := range a.runtimes {
		states = append(states, state)
	}
	a.mu.Unlock()

	for _, state := range states {
		pid, startedAt, failures, lastErr, running := state.proc.snapshot()
		if !running {
			cooldown := time.Duration(a.cfg.RestartCooldownSecs) * time.Second
			if time.Since(state.proc.stoppedAt) < cooldown {
				continue
			}
			a.log.Warn("runtime", "forward %d process is not running (pid=%d failures=%d last=%s); restarting",
				state.forward.ForwardID, pid, failures, lastErr)
			a.reconcileForward(ctx, state.forward, "process_dead")
			continue
		}

		// The listener check is a local TCP connect, never a business request
		// upstream, so it cannot be mistaken for customer traffic.
		//
		// A runtime with no installed lease has no listener by design: it is
		// blocked in WaitValid() waiting for the Panel's grant. Restarting it
		// there would loop forever and would also be wrong, because a missing
		// grant is a control-plane condition, not a data-plane fault.
		a.mu.Lock()
		live := state.leaseInstalledAt
		a.mu.Unlock()
		if live.IsZero() {
			if time.Since(startedAt) > time.Duration(a.cfg.ColdStartGraceSecs)*time.Second {
				a.log.Debug("runtime", "forward %d is waiting for a panel grant (no lease installed yet)", state.forward.ForwardID)
			}
			continue
		}
		if !portIsListening(state.forward.ListenPort) {
			// The runtime binds within milliseconds of accepting a lease, so a
			// short grace is enough to absorb scheduler jitter.
			if time.Since(live) < listenReadyGrace {
				continue
			}
			a.log.Warn("runtime", "forward %d listener on port %d did not come up after a lease; restarting", state.forward.ForwardID, state.forward.ListenPort)
			a.reconcileForward(ctx, state.forward, "port_not_listening")
			continue
		}

		if status, err := readRuntimeStatus(state.proc.paths.statusPath); err == nil {
			a.mu.Lock()
			state.status = status
			state.statusAt = time.Now()
			a.mu.Unlock()
			if code := strings.TrimSpace(status.ErrorCode); code != "" && code != "ok" {
				a.log.Debug("runtime", "forward %d runtime reports %s", state.forward.ForwardID, code)
			}
		}
	}
}

// ---------------------------------------------------------------------------
// Snapshot for LuCI and for the control socket
// ---------------------------------------------------------------------------

// ForwardView is the operator-facing view of one forward. It deliberately
// omits credentials, endpoints and lease identifiers.
type ForwardView struct {
	ForwardID  int    `json:"forward_id"`
	Status     string `json:"status"`
	Health     string `json:"health"`
	Mode       string `json:"mode"`
	Transport  string `json:"transport"`
	ListenPort int    `json:"listen_port"`
	Target     string `json:"target"`
	UptimeSecs int64  `json:"uptime_seconds"`
	Restarts   int    `json:"restarts"`
	LastError  string `json:"last_error"`

	Zone          string `json:"zone,omitempty"`
	SelectionTier string `json:"selection_tier,omitempty"`
	RTTMillis     int64  `json:"rtt_ms,omitempty"`
	P95RTTMS      int64  `json:"p95_rtt_ms,omitempty"`
	LossPercent   int64  `json:"loss_percent,omitempty"`
	Candidates    int    `json:"candidates"`
	HAFailures    int64  `json:"ha_failures,omitempty"`
	LeaseExpiry   string `json:"lease_expires_at,omitempty"`
	LeaseError    string `json:"lease_error,omitempty"`

	BytesIn  int64 `json:"bytes_in"`
	BytesOut int64 `json:"bytes_out"`
}

func (a *Agent) forwardViews() []ForwardView {
	a.mu.Lock()
	states := make([]*runtimeState, 0, len(a.runtimes))
	for _, state := range a.runtimes {
		states = append(states, state)
	}
	a.mu.Unlock()

	views := make([]ForwardView, 0, len(states))
	for _, state := range states {
		pid, startedAt, failures, lastErr, running := state.proc.snapshot()
		view := ForwardView{
			ForwardID:  state.forward.ForwardID,
			Mode:       firstNonEmpty(state.forward.ClientMode, state.forward.Mode, "single"),
			Transport:  firstNonEmpty(state.forward.Transport, "tcp+udp"),
			ListenPort: state.forward.ListenPort,
			Target:     state.forward.Target,
			Restarts:   failures,
			Status:     "stopped",
			Health:     "unknown",
		}
		if running {
			view.Status = "running"
			view.UptimeSecs = int64(time.Since(startedAt).Seconds())
		} else if lastErr != "" {
			view.LastError = redactSecrets(lastErr)
		}
		_ = pid
		if !state.leaseExpiry.IsZero() {
			view.LeaseExpiry = formatRFC3339(state.leaseExpiry)
		}
		view.LeaseError = state.lastLeaseErr
		view.Candidates = state.candidates
		if status := state.status; status != nil {
			view.Zone = status.DetectedIngressZone
			view.HAFailures = status.HAFailures
			view.BytesIn = status.BytesIn
			view.BytesOut = status.BytesOut
			if pick := preferredSelection(status); pick != nil {
				view.SelectionTier = pick.SelectionTier
				view.RTTMillis = pick.RTTMillis
				view.P95RTTMS = pick.P95RTTMillis
				view.LossPercent = pick.LossPercent
			}
			switch strings.ToLower(status.State) {
			case "ready":
				view.Health = "good"
			case "degraded":
				view.Health = "degraded"
			case "error", "failed":
				view.Health = "unavailable"
			default:
				view.Health = "starting"
			}
			if status.ErrorCode != "" && status.ErrorCode != "ok" {
				view.Health = "degraded"
				view.LastError = status.ErrorCode
			}
		} else if running {
			view.Health = "starting"
		}
		views = append(views, view)
	}
	sort.Slice(views, func(i, j int) bool { return views[i].ForwardID < views[j].ForwardID })
	return views
}

// preferredSelection prefers the live UDP association, then TCP, then the
// warm-up session, mirroring what the desktop surfaces.
func preferredSelection(status *RuntimeStatus) *SelectionStatus {
	for _, candidate := range []*SelectionStatus{status.ActiveUDP, status.LatestTCP, status.UDPRuntimeSession} {
		if candidate != nil {
			return candidate
		}
	}
	return nil
}

func firstNonEmpty(values ...string) string {
	for _, value := range values {
		if strings.TrimSpace(value) != "" {
			return value
		}
	}
	return ""
}

// StatusSnapshot is the whole daemon status as LuCI sees it.
type StatusSnapshot struct {
	Version        string        `json:"version"`
	Platform       string        `json:"platform"`
	Architecture   string        `json:"architecture"`
	UptimeSeconds  int64         `json:"uptime_seconds"`
	Mode           string        `json:"mode"`
	Enabled        bool          `json:"enabled"`
	Enrolled       bool          `json:"enrolled"`
	PanelURL       string        `json:"panel_url"`
	PanelPlatform  string        `json:"panel_platform_tag"`
	DeviceID       string        `json:"device_id"`
	DeviceName     string        `json:"device_name"`
	ClientID       int           `json:"client_id"`
	ClientName     string        `json:"client_name"`
	ConfigRevision string        `json:"config_revision"`
	LastPullAt     string        `json:"last_pull_at,omitempty"`
	LastPullError  string        `json:"last_pull_error,omitempty"`
	ForwardCount   int           `json:"forward_count"`
	Forwards       []ForwardView `json:"forwards"`
	Uplinks        []UplinkView  `json:"uplinks,omitempty"`
	Gateway        *GatewayView  `json:"gateway,omitempty"`
	RuntimeBin     string        `json:"runtime_bin"`
	RuntimeOK      bool          `json:"runtime_present"`
	SingBoxOK      bool          `json:"singbox_present"`

	// Runtime drift is reported, never auto-applied: on OpenWrt the runtime is
	// updated by opkg, so the router must have exactly one update authority.
	RuntimeVersion    string `json:"runtime_version,omitempty"`
	RuntimeSha256     string `json:"runtime_sha256,omitempty"`
	DesiredRuntimeSha string `json:"desired_runtime_sha256,omitempty"`
	RuntimeDrift      string `json:"runtime_drift,omitempty"`

	// LastPullErrorCode is the stable code behind LastPullError, e.g.
	// client_online_limit_reached, so LuCI can render it specially. The two
	// counts are only set for client_online_limit_reached.
	LastPullErrorCode   string `json:"last_pull_error_code,omitempty"`
	OnlineDeviceLimit   int    `json:"online_device_limit,omitempty"`
	ActiveOnlineDevices int    `json:"active_online_devices,omitempty"`

	// Panel traffic ignores HTTP(S)_PROXY and is pinned to the physical WAN
	// so a TUN proxy or VPN on the router cannot capture it. An empty device
	// means the sockets are unbound; the reason says why.
	PanelEgressDevice   string `json:"panel_egress_device"`
	PanelEgressReason   string `json:"panel_egress_reason"`
	PanelEgressOverride string `json:"panel_egress_override"`
	PanelEgressError    string `json:"panel_egress_error,omitempty"`
	ProxyIgnored        bool   `json:"proxy_ignored"`
}

func (a *Agent) snapshot() StatusSnapshot {
	a.mu.Lock()
	lastPull := a.lastPullAt
	lastErr := a.lastPullErr
	lastCode := a.lastPullCode
	limit := a.pullLimit
	meta := a.clientMeta
	a.mu.Unlock()

	pull := a.store.Pull()
	snapshot := StatusSnapshot{
		Version:        agentVersion,
		Platform:       platformName,
		Architecture:   runtime.GOARCH,
		UptimeSeconds:  int64(time.Since(a.startedAt).Seconds()),
		Mode:           a.cfg.Mode,
		Enabled:        a.cfg.Enabled,
		Enrolled:       a.enrolled(),
		PanelURL:       a.panel.endpointLabel(),
		PanelPlatform:  a.panel.platform(),
		DeviceID:       a.deviceID,
		DeviceName:     a.deviceNm,
		ClientID:       a.panel.clientIDValue(),
		ClientName:     meta.Name,
		ConfigRevision: pull.ConfigRevision,
		LastPullError:  lastErr,
		Forwards:       a.forwardViews(),
		RuntimeBin:     a.cfg.RuntimeBin,
		RuntimeOK:      fileExists(a.cfg.RuntimeBin),
		SingBoxOK:      fileExists(a.cfg.GatewaySingBoxBin),
	}
	if !lastPull.IsZero() {
		snapshot.LastPullAt = formatRFC3339(lastPull)
	}
	snapshot.LastPullErrorCode = lastCode
	if limit != nil {
		snapshot.OnlineDeviceLimit = limit.OnlineDeviceLimit
		snapshot.ActiveOnlineDevices = limit.ActiveOnlineDevices
	}
	snapshot.PanelEgressDevice, snapshot.PanelEgressReason, snapshot.PanelEgressOverride, snapshot.PanelEgressError = a.panel.egressView()
	snapshot.ProxyIgnored = true
	snapshot.ForwardCount = len(snapshot.Forwards)
	if a.cfg.UplinkEnabled {
		snapshot.Uplinks = a.uplinkViews()
	}
	if a.cfg.gatewayActive() {
		snapshot.Gateway = a.gatewayView()
	}
	a.mu.Lock()
	snapshot.DesiredRuntimeSha = strings.ToLower(strings.TrimSpace(meta.DesiredUltraSHA))
	a.mu.Unlock()
	snapshot.RuntimeVersion = runtimeInfo.version
	snapshot.RuntimeSha256 = runtimeInfo.sha256
	snapshot.RuntimeDrift = runtimeInfo.drift
	return snapshot
}
