package main

import (
	"context"
	"strings"
	"time"
)

// heartbeatLoop reports health and byte usage, and drains the Panel's
// out-of-band commands. Usage is only cleared when the Panel acknowledges the
// batch, so a failed heartbeat never loses billable traffic.
func (a *Agent) heartbeatLoop(ctx context.Context) {
	interval := time.Duration(a.cfg.HeartbeatIntervalSecs) * time.Second
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
		if !a.enrolled() {
			continue
		}
		beatCtx, cancel := context.WithTimeout(ctx, 20*time.Second)
		a.collectUsage()
		err := a.sendHeartbeat(beatCtx)
		cancel()
		if err != nil {
			if _, limited := asOnlineLimitError(err); limited {
				// The pull loop already explains the device limit once a
				// minute; repeating it every heartbeat would bury other logs.
				a.log.Debug("heartbeat", "heartbeat refused: %s", codeClientOnlineLimit)
			} else {
				a.log.Warn("heartbeat", "heartbeat failed: %v", err)
			}
		}
	}
}

// collectUsage folds the runtime's cumulative counters into pending snapshots.
// ultra-client counters live in the process, so they restart at zero whenever
// the runtime does; a decrease therefore means "counter reset", not negative
// traffic.
func (a *Agent) collectUsage() {
	a.mu.Lock()
	states := make([]*runtimeState, 0, len(a.runtimes))
	for _, state := range a.runtimes {
		states = append(states, state)
	}
	a.mu.Unlock()

	for _, state := range states {
		status := state.status
		if status == nil {
			continue
		}
		previous := state.lastCounters
		deltaIn := status.BytesIn - previous.in
		deltaOut := status.BytesOut - previous.out
		if deltaIn < 0 {
			deltaIn = status.BytesIn
		}
		if deltaOut < 0 {
			deltaOut = status.BytesOut
		}
		if deltaIn == 0 && deltaOut == 0 {
			continue
		}
		state.lastCounters = counterPair{in: status.BytesIn, out: status.BytesOut}

		forwardID := state.forward.ForwardID
		generation := status.PolicyGeneration
		if generation == "" {
			generation = policyGeneration(state.forward.UltraAccess)
		}
		a.store.UpdateUsage(func(usage *UsageState) {
			usage.Sequence++
			usage.PendingUltra = append(usage.PendingUltra, UltraUsageSnapshot{
				ForwardID:        forwardID,
				PolicyGeneration: generation,
				Sequence:         usage.Sequence,
				BytesIn:          deltaIn,
				BytesOut:         deltaOut,
			})
		})

		for _, feedback := range status.CandidateFeedback {
			item := UltraCandidateFeedback{
				ForwardID:        forwardID,
				CandidateKeyHash: feedback.CandidateKeyHash,
				LeaseHash:        feedback.LeaseHash,
				Protocol:         feedback.Protocol,
				Outcome:          feedback.Outcome,
				Reason:           feedback.Reason,
				EvidenceID:       feedback.EvidenceID,
				ObservedAt:       feedback.ObservedAt,
			}
			a.store.UpdateUsage(func(usage *UsageState) {
				usage.PendingFeedback = append(usage.PendingFeedback, item)
			})
		}
	}
}

func (a *Agent) sendHeartbeat(ctx context.Context) error {
	usage := a.store.Usage()
	payload := HeartbeatPayload{
		AgentVersion:  agentVersion,
		SupportsMPTCP: false,
		UltraUsage:    usage.PendingUltra,
		UltraFeedback: trimFeedback(usage.PendingFeedback, 512),
	}
	response, err := a.panel.Heartbeat(ctx, payload)
	if err != nil {
		return err
	}
	if response.UsageAcknowledged != nil && *response.UsageAcknowledged {
		reportedIn := int64(0)
		reportedOut := int64(0)
		for _, item := range usage.PendingUltra {
			reportedIn += item.BytesIn
			reportedOut += item.BytesOut
		}
		a.store.UpdateUsage(func(state *UsageState) {
			state.PendingUltra = nil
			state.PendingFeedback = nil
			state.ReportedIn += reportedIn
			state.ReportedOut += reportedOut
			state.LastHeartbeatAt = time.Now()
		})
	}
	for _, action := range response.UltraActions {
		a.applyPolicyAction(action)
	}
	if token := strings.TrimSpace(a.diagToken); token != "" {
		a.submitDiagnostics(ctx, token)
	}
	return nil
}

// trimFeedback keeps the heartbeat payload bounded. The Panel accepts a list,
// but a router should never post an unbounded body.
func trimFeedback(items []UltraCandidateFeedback, limit int) []UltraCandidateFeedback {
	if len(items) <= limit {
		return items
	}
	return items[len(items)-limit:]
}
