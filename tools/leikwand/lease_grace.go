package main

import (
	"context"
	"errors"
	"time"
)

// Network grace keeps an installed grant serving for a bounded time past its
// Panel expiry, when the router cannot reach the Panel to renew it. The
// runtime enforces the bound (ultra-client maxLeaseGrace) and refuses to move
// the expiry any further; the router only asks for the extension, and only
// after a network failure near expiry. An explicit Panel refusal always stops
// the grace, so a revoked or lapsed subscription is not carried on.
const runtimeLeaseGrace = 10 * time.Minute

// sendRuntimeCommand is a variable so tests can observe the commands without a
// live runtime socket.
var sendRuntimeCommand = func(ctx context.Context, socketPath string, command map[string]any) error {
	_, err := ultraControl{socketPath: socketPath}.sendCommand(ctx, command)
	return err
}

func isPanelRefusal(err error) bool {
	var pe *panelError
	return errors.As(err, &pe)
}

// extendRuntimeGrace is called when a lease request failed without a Panel
// answer. It only acts for a grant that was installed and is within its renewal
// lead time, and never extends past the bound measured from the issued expiry.
func (a *Agent) extendRuntimeGrace(state *runtimeState, err error) {
	if state == nil || err == nil || isPanelRefusal(err) || state.leaseInstalledAt.IsZero() || state.policy == nil {
		return
	}
	base, parseErr := parseRFC3339(state.policy.ExpiresAt)
	if parseErr != nil {
		return
	}
	now := time.Now()
	lead := time.Duration(a.cfg.LeaseRenewLeadSecs) * time.Second
	if now.Before(base.Add(-lead)) || !now.Before(base.Add(runtimeLeaseGrace)) {
		return
	}
	until := base.Add(runtimeLeaseGrace)
	if !state.graceUntil.IsZero() && !until.After(state.graceUntil) {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	command := map[string]any{
		"op":                "extend_grace",
		"policy_generation": state.policy.PolicyGeneration,
		"grace_until":       formatRFC3339(until),
	}
	if sendErr := sendRuntimeCommand(ctx, state.proc.paths.controlSocket, command); sendErr != nil {
		a.log.Warn("lease", "forward %d could not extend the grant while the panel is unreachable: %v", state.forward.ForwardID, sendErr)
		return
	}
	state.graceUntil = until
	a.log.Warn("lease", "forward %d panel unreachable near expiry; keeping the installed grant serving until %s (bounded)",
		state.forward.ForwardID, formatRFC3339(until))
}

// stopRuntimeGrace ends a grace the runtime is holding when the Panel answers
// with an explicit refusal.
func (a *Agent) stopRuntimeGrace(state *runtimeState, err error) {
	if state == nil || state.graceUntil.IsZero() || state.policy == nil || !isPanelRefusal(err) {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	command := map[string]any{
		"op":                "stop_grace",
		"policy_generation": state.policy.PolicyGeneration,
	}
	if sendErr := sendRuntimeCommand(ctx, state.proc.paths.controlSocket, command); sendErr != nil {
		a.log.Warn("lease", "forward %d could not stop the grace after a panel refusal: %v", state.forward.ForwardID, sendErr)
		return
	}
	state.graceUntil = time.Time{}
	a.log.Warn("lease", "forward %d panel refused the grant; grace stopped", state.forward.ForwardID)
}
