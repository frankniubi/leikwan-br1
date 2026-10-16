package main

import (
	"context"
	"errors"
	"strings"
	"time"
)

// Layer-1 high availability (relay side).
//
// The router does not implement candidate ranking itself. It asks the Panel
// for up to MaxCandidates leases in one batch and hands the whole batch to
// ultra-client, which owns probing, ranking, isolation and fast failover.
// The daemon's job is to keep that batch fresh and to notice when it cannot.
//
// Refresh semantics that this code depends on (verified against
// tools/ultra-client/selection.go):
//   - Same policy generation: a refresh is accepted only when it still proves
//     the already-selected network path. A rejected refresh leaves the existing
//     lease in place, so it never degrades a healthy runtime.
//   - New policy generation: the runtime replaces the whole candidate set and
//     re-selects. This is how a Panel-driven line rotation reaches the router.
const leaseTickInterval = 5 * time.Second

func (a *Agent) leaseLoop(ctx context.Context) {
	ticker := time.NewTicker(leaseTickInterval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
		a.leaseOnce(ctx)
	}
}

func (a *Agent) leaseOnce(ctx context.Context) {
	a.mu.Lock()
	states := make([]*runtimeState, 0, len(a.runtimes))
	for _, state := range a.runtimes {
		states = append(states, state)
	}
	a.mu.Unlock()

	policyExpired := false
	for _, state := range states {
		if state.userStopped || !state.proc.alive() {
			continue
		}
		policy := state.forward.UltraAccess
		if policy == nil || strings.TrimSpace(policy.SessionTicket) == "" {
			// The Panel has not granted Ultra access for this forward yet. The
			// runtime keeps listening but serves nothing, which is the correct
			// fail-closed behaviour.
			a.log.Debug("lease", "forward %d has no Ultra grant yet", state.forward.ForwardID)
			policyExpired = true
			continue
		}
		if failure := validatePolicy(policy, state.forward, time.Now()); failure != "" {
			a.log.Warn("lease", "forward %d grant unusable: %s", state.forward.ForwardID, failure)
			policyExpired = true
			a.handlePolicyUnusable(state, failure)
			continue
		}
		if state.lastLeaseAt.IsZero() || a.leaseRefreshDue(state, time.Now()) {
			a.acquireLease(ctx, state)
		}
	}
	// A short pull interval is what keeps the five-minute policy TTL alive.
	// Nudge the pull loop instead of polling the Panel from two places.
	if policyExpired {
		a.triggerReconcile("policy_refresh")
	}
}

func (a *Agent) leaseRefreshDue(state *runtimeState, now time.Time) bool {
	if state.leaseExpiry.IsZero() {
		return true
	}
	lead := time.Duration(a.cfg.LeaseRenewLeadSecs) * time.Second
	if now.Add(lead).After(state.leaseExpiry) {
		return true
	}
	// Back off failed attempts so a persistent Panel 409 does not turn into a
	// request storm.
	if state.leaseFailures > 0 {
		backoff := time.Duration(clampInt(state.leaseFailures*5, 5, 60)) * time.Second
		return now.Sub(state.lastLeaseAt) >= backoff
	}
	return false
}

// validatePolicy mirrors the desktop client's UltraPolicy validation. A grant
// that fails any check must not be used, and must not be silently downgraded.
func validatePolicy(policy *UltraPolicy, forward ForwardConfig, now time.Time) string {
	if !strings.EqualFold(strings.TrimSpace(policy.Protocol), "leikwan-ultra-policy-v2") {
		return "protocol"
	}
	if policy.ForwardID != forward.ForwardID {
		return "forward_id"
	}
	if !strings.EqualFold(strings.TrimSpace(policy.ServiceState), "active") {
		return "service_state"
	}
	if strings.TrimSpace(policy.SessionTicket) == "" {
		return "session_ticket"
	}
	if !isHex64(strings.TrimSpace(policy.PolicyGeneration)) {
		return "policy_generation"
	}
	// Format is checked strictly (lowercase hex64) exactly as the desktop
	// client does, so a malformed frame is rejected rather than silently
	// normalised; the comparison itself is case-insensitive.
	digest := strings.TrimSpace(policy.TargetDigest)
	if !isHex64(digest) {
		return "target_digest_format"
	}
	if !strings.EqualFold(digest, targetDigest(forward.Target)) {
		return "target_digest_mismatch"
	}
	expiresAt, err := parseRFC3339(policy.ExpiresAt)
	if err != nil {
		return "expires_at"
	}
	if !expiresAt.After(now) {
		return "expired"
	}
	if expiresAt.After(now.Add(5*time.Minute + 30*time.Second)) {
		return "expiry_window"
	}
	if policy.QuotaRemainingBytes <= 0 {
		return "quota"
	}
	if policy.RateLimitMbps < 0 || policy.RateLimitMbps > 100000 {
		return "rate_limit"
	}
	switch strings.ToLower(strings.TrimSpace(policy.Transport)) {
	case "tcp", "udp", "tcp+udp":
	default:
		return "transport"
	}
	return ""
}

// handlePolicyUnusable reacts to a revoked or expired grant. Revocation is a
// real control-plane decision and stops the forward; expiry alone only means
// the Panel has not refreshed yet, so the runtime is left to enforce its own
// lease expiry rather than being killed on a transient pull failure.
func (a *Agent) handlePolicyUnusable(state *runtimeState, failure string) {
	switch failure {
	case "service_state", "forward_id", "protocol", "quota", "target_digest_mismatch":
		a.log.Warn("lease", "forward %d revoked by panel (%s); stopping runtime", state.forward.ForwardID, failure)
		a.mu.Lock()
		delete(a.runtimes, state.forward.ForwardID)
		a.mu.Unlock()
		a.stopRuntime(state.proc, "policy_"+failure)
		a.store.DeleteApplied(state.forward.ForwardID)
	}
}

// acquireLease asks the Panel for a fresh candidate batch and delivers it to
// the running runtime over its control socket.
func (a *Agent) acquireLease(ctx context.Context, state *runtimeState) {
	policy := state.forward.UltraAccess
	if policy == nil {
		return
	}
	state.lastLeaseAt = time.Now()

	request := LeaseRequest{
		DeviceID: a.deviceID,
		Forwards: []LeaseRequestForward{{
			Purpose:          "ultra",
			ForwardID:        state.forward.ForwardID,
			SessionTicket:    policy.SessionTicket,
			PolicyGeneration: strings.ToLower(strings.TrimSpace(policy.PolicyGeneration)),
			TargetDigest:     strings.ToLower(strings.TrimSpace(policy.TargetDigest)),
			Transport:        strings.ToLower(strings.TrimSpace(policy.Transport)),
			SelectionMode:    "auto",
			MaxCandidates:    a.cfg.MaxCandidates,
		}},
	}

	leaseCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	response, err := a.panel.Lease(leaseCtx, request)
	cancel()
	if err != nil {
		state.leaseFailures++
		state.lastLeaseErr = redactSecrets(err.Error())
		a.log.Warn("lease", "forward %d lease request failed (%d): %v%s", state.forward.ForwardID, state.leaseFailures, err, leaseRefusalHint(err))
		return
	}
	a.noteUnsealFailures("lease", state.forward.ForwardID, response)
	if !response.OK || len(response.Leases) == 0 {
		state.leaseFailures++
		state.lastLeaseErr = firstNonEmpty(response.Error, "no_leases")
		a.log.Warn("lease", "forward %d panel returned no lease: %s", state.forward.ForwardID, state.lastLeaseErr)
		return
	}

	// In hybrid mode the gateway TUN would otherwise capture this runtime's
	// relay connections and push them through the native exit.
	a.bypass.set(forwardBypassOwner(state.forward.ForwardID), leaseBypassCIDRs(response.Leases))
	if err := a.bypass.sync(); err != nil {
		a.log.Warn("lease", "forward %d relay bypass rules incomplete: %v", state.forward.ForwardID, err)
	}

	control := ultraControl{socketPath: state.proc.paths.controlSocket}
	if err := waitForControlSocket(ctx, state.proc.paths.controlSocket, 15*time.Second); err != nil {
		state.leaseFailures++
		state.lastLeaseErr = err.Error()
		a.log.Warn("lease", "forward %d control socket unavailable: %v", state.forward.ForwardID, err)
		return
	}

	// The batch timeout has to cover the runtime's own UDP probe window, so it
	// is deliberately much longer than a normal request.
	batchCtx, batchCancel := context.WithTimeout(ctx, leaseBatchTimeout)
	defer batchCancel()
	reply, err := control.sendLeaseBatch(batchCtx, response.Leases)
	if err != nil {
		state.leaseFailures++
		state.lastLeaseErr = redactSecrets(err.Error())
		a.log.Warn("lease", "forward %d runtime rejected lease batch (%d candidates): %v",
			state.forward.ForwardID, len(response.Leases), err)
		return
	}

	expiry := earliestLeaseExpiry(response.Leases)
	state.leaseFailures = 0
	state.lastLeaseErr = ""
	state.candidates = len(response.Leases)
	state.leaseExpiry = expiry
	state.leaseIDs = leaseIDs(response.Leases)
	state.policy = policy
	// From here on the runtime is expected to hold an open listener; the
	// health loop uses this timestamp to distinguish "no grant yet" from
	// "listener lost".
	state.leaseInstalledAt = time.Now()

	selected, _ := reply["policy_generation"].(string)
	a.log.Info("lease", "forward %d installed %d candidates, generation=%s expires=%s",
		state.forward.ForwardID, len(response.Leases), shortRevision(selected), formatRFC3339(expiry))

	a.store.PutApplied(AppliedForward{
		ForwardID:        state.forward.ForwardID,
		Contract:         state.contract,
		PolicyGeneration: policy.PolicyGeneration,
		ListenPort:       state.forward.ListenPort,
		Target:           state.forward.Target,
		Transport:        state.forward.Transport,
		ConfigPath:       state.proc.paths.configPath,
		ControlSocket:    state.proc.paths.controlSocket,
		StatusPath:       state.proc.paths.statusPath,
		LastApplyReason:  "lease_installed",
		UpdatedAt:        time.Now(),
	})
}

func earliestLeaseExpiry(leases []LeaseFrame) time.Time {
	var earliest time.Time
	for _, lease := range leases {
		parsed, err := parseRFC3339(lease.ExpiresAt)
		if err != nil {
			continue
		}
		if earliest.IsZero() || parsed.Before(earliest) {
			earliest = parsed
		}
	}
	return earliest
}

// leaseIDs are kept only for change detection; they never leave the router.
func leaseIDs(leases []LeaseFrame) []string {
	out := make([]string, 0, len(leases))
	for _, lease := range leases {
		out = append(out, lease.LeaseID)
	}
	return out
}

// applyPolicyAction handles a remote command delivered on the heartbeat
// response. This is the Panel's out-of-band control channel for a single
// forward: it can force a stop without waiting for the next config pull.
func (a *Agent) applyPolicyAction(action UltraPolicyAction) {
	switch strings.ToLower(strings.TrimSpace(action.Action)) {
	case "stop", "disable", "revoke":
		a.mu.Lock()
		state, ok := a.runtimes[action.ForwardID]
		if ok {
			delete(a.runtimes, action.ForwardID)
		}
		a.mu.Unlock()
		if ok {
			a.log.Warn("panel", "panel action=%s for forward %d (%s); stopping", action.Action, action.ForwardID, action.Reason)
			a.stopRuntime(state.proc, "panel_action")
			a.store.DeleteApplied(action.ForwardID)
		}
	case "refresh", "renew":
		a.log.Info("panel", "panel requested refresh for forward %d (%s)", action.ForwardID, action.Reason)
		a.mu.Lock()
		if state, ok := a.runtimes[action.ForwardID]; ok {
			state.lastLeaseAt = time.Time{}
		}
		a.mu.Unlock()
		a.triggerReconcile("panel_refresh")
	default:
		a.log.Debug("panel", "ignoring panel action %q for forward %d", action.Action, action.ForwardID)
	}
}

var errPolicyMissing = errors.New("ultra grant missing")

// leaseRefusalHint adds operator context to the sealed-lease refusals. They
// are otherwise handled like any other lease error: counted, backed off and
// retried at the normal cadence. A key conflict deliberately does not rotate
// the device key, which would only fight the legitimate holder of the pin.
func leaseRefusalHint(err error) string {
	var pe *panelError
	if !errors.As(err, &pe) {
		return ""
	}
	switch pe.Code {
	case codeLeaseKeyInvalid:
		return " (panel rejected this device's lease key header; client bug)"
	case codeLeaseSealingRequired:
		return " (panel requires sealed leases but no device lease key was available)"
	case codeLeaseDeviceKeyConflict:
		return " (another lease key is active for this device id; keeping the current key and retrying)"
	}
	return ""
}

// noteUnsealFailures logs sealed leases that were dropped because they could
// not be opened. Only the non-sensitive reason is logged, never the payload.
func (a *Agent) noteUnsealFailures(scope string, forwardID int, response *LeaseResponse) {
	if response == nil || len(response.UnsealFailures) == 0 {
		return
	}
	a.log.Warn(scope, "forward %d: %d sealed lease(s) dropped (%s), %d usable: %v",
		forwardID, len(response.UnsealFailures), codeLeaseUnsealFailed, len(response.Leases), response.UnsealFailures[0])
}

func forwardBypassOwner(forwardID int) string { return "forward:" + itoa(forwardID) }
