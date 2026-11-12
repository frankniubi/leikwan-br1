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
		// Console-requested self-update rides the heartbeat cadence: the
		// desired_* fields arrive with the pull, and opkg must not race a
		// heartbeat that is still streaming usage.
		a.maybeSelfUpdate(ctx)
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
	gateway := a.gateway
	a.mu.Unlock()

	for _, state := range states {
		generation := ""
		if state.status != nil {
			generation = state.status.PolicyGeneration
		}
		if generation == "" {
			generation = policyGeneration(state.forward.UltraAccess)
		}
		a.recordUsage(state.forward.ForwardID, generation, state.status, &state.lastCounters)
	}
	// The native relay is billed like any other Ultra forward (the desktop
	// reports its native-usage snapshots the same way).
	if gateway != nil {
		gateway.collectRelayUsage()
	}
}

const (
	feedbackMaxAge       = 5 * time.Minute
	maxAckedFeedbackKeys = 512
)

// recordUsage turns one status document into a usage delta and candidate
// feedback, advancing last. Feedback is collected even when bytes are idle so
// setup/handshake failures (e.g. credential_rejected before payload flows)
// reach the Panel promptly, while acknowledged or stale entries in the
// runtime's rolling list are never requeued.
func (a *Agent) recordUsage(forwardID int, generation string, status *RuntimeStatus, last *counterPair) {
	if status == nil {
		return
	}
	deltaIn := status.BytesIn - last.in
	deltaOut := status.BytesOut - last.out
	if deltaIn < 0 {
		deltaIn = status.BytesIn
	}
	if deltaOut < 0 {
		deltaOut = status.BytesOut
	}
	hasDelta := deltaIn > 0 || deltaOut > 0
	if hasDelta {
		*last = counterPair{in: status.BytesIn, out: status.BytesOut}
	}

	now := time.Now()
	candidates := make([]UltraCandidateFeedback, 0, len(status.CandidateFeedback))
	for _, feedback := range status.CandidateFeedback {
		if !feedbackIsFresh(feedback.ObservedAt, now) {
			continue
		}
		candidates = append(candidates, UltraCandidateFeedback{
			ForwardID:        forwardID,
			CandidateKeyHash: feedback.CandidateKeyHash,
			LeaseHash:        feedback.LeaseHash,
			Protocol:         feedback.Protocol,
			Outcome:          feedback.Outcome,
			Reason:           feedback.Reason,
			EvidenceID:       feedback.EvidenceID,
			ObservedAt:       feedback.ObservedAt,
		})
	}
	if !hasDelta && len(candidates) == 0 {
		return
	}

	a.store.UpdateUsage(func(usage *UsageState) {
		if hasDelta {
			usage.Sequence++
			usage.PendingUltra = append(usage.PendingUltra, UltraUsageSnapshot{
				ForwardID:        forwardID,
				PolicyGeneration: generation,
				Sequence:         usage.Sequence,
				BytesIn:          deltaIn,
				BytesOut:         deltaOut,
			})
		}
		seen := make(map[string]struct{}, len(usage.AcknowledgedFeedback)+len(usage.PendingFeedback))
		for _, k := range usage.AcknowledgedFeedback {
			seen[k] = struct{}{}
		}
		for _, pending := range usage.PendingFeedback {
			if k := feedbackDedupeKey(pending); k != "" {
				seen[k] = struct{}{}
			}
		}
		for _, item := range candidates {
			key := feedbackDedupeKey(item)
			if key != "" {
				if _, ok := seen[key]; ok {
					continue
				}
				seen[key] = struct{}{}
			}
			usage.PendingFeedback = append(usage.PendingFeedback, item)
		}
		usage.PendingFeedback = trimFeedback(usage.PendingFeedback, 512)
	})
}

func feedbackDedupeKey(item UltraCandidateFeedback) string {
	id := strings.TrimSpace(item.EvidenceID)
	if id != "" {
		return itoa(item.ForwardID) + ":" + id
	}
	cand := strings.TrimSpace(item.CandidateKeyHash)
	obs := strings.TrimSpace(item.ObservedAt)
	if cand == "" && obs == "" {
		return ""
	}
	return itoa(item.ForwardID) + ":" + cand + ":" + strings.TrimSpace(item.Reason) + ":" + obs
}

func feedbackIsFresh(observedAt string, now time.Time) bool {
	trimmed := strings.TrimSpace(observedAt)
	if trimmed == "" {
		return true
	}
	parsed, err := time.Parse(time.RFC3339Nano, trimmed)
	if err != nil {
		return false
	}
	if parsed.Before(now.Add(-feedbackMaxAge)) || parsed.After(now.Add(time.Minute)) {
		return false
	}
	return true
}

func appendAckedFeedback(existing []string, sent []UltraCandidateFeedback) []string {
	seen := make(map[string]struct{}, len(existing)+len(sent))
	out := make([]string, 0, len(existing)+len(sent))
	for _, k := range existing {
		if k == "" {
			continue
		}
		if _, ok := seen[k]; !ok {
			seen[k] = struct{}{}
			out = append(out, k)
		}
	}
	for _, item := range sent {
		k := feedbackDedupeKey(item)
		if k == "" {
			continue
		}
		if _, ok := seen[k]; !ok {
			seen[k] = struct{}{}
			out = append(out, k)
		}
	}
	if len(out) > maxAckedFeedbackKeys {
		out = out[len(out)-maxAckedFeedbackKeys:]
	}
	return out
}

func remainingPendingFeedback(current []UltraCandidateFeedback, sent []UltraCandidateFeedback) []UltraCandidateFeedback {
	if len(current) == 0 {
		return nil
	}
	sentKeys := make(map[string]struct{}, len(sent))
	for _, item := range sent {
		if k := feedbackDedupeKey(item); k != "" {
			sentKeys[k] = struct{}{}
		}
	}
	var kept []UltraCandidateFeedback
	for _, item := range current {
		k := feedbackDedupeKey(item)
		if k != "" {
			if _, ok := sentKeys[k]; ok {
				continue
			}
		} else if len(sent) > 0 {
			continue
		}
		kept = append(kept, item)
	}
	return kept
}

func (a *Agent) sendHeartbeat(ctx context.Context) error {
	usage := a.store.Usage()
	sentFeedback := trimFeedback(usage.PendingFeedback, 512)
	payload := HeartbeatPayload{
		AgentVersion:  agentVersion,
		SupportsMPTCP: false,
		UltraUsage:    usage.PendingUltra,
		UltraFeedback: sentFeedback,
		UltraStatus:   a.collectUltraStatus(time.Now()),
	}
	response, err := a.panel.Heartbeat(ctx, payload)
	if err != nil {
		return err
	}
	ackUsage := response.UsageAcknowledged != nil && *response.UsageAcknowledged
	ackFeedback := ackUsage || (response.OK && len(usage.PendingUltra) == 0 && len(sentFeedback) > 0)
	if ackUsage || ackFeedback {
		reportedIn := int64(0)
		reportedOut := int64(0)
		if ackUsage {
			for _, item := range usage.PendingUltra {
				reportedIn += item.BytesIn
				reportedOut += item.BytesOut
			}
		}
		a.store.UpdateUsage(func(state *UsageState) {
			if ackUsage {
				state.PendingUltra = nil
				state.ReportedIn += reportedIn
				state.ReportedOut += reportedOut
			}
			if ackFeedback {
				state.AcknowledgedFeedback = appendAckedFeedback(state.AcknowledgedFeedback, sentFeedback)
				state.PendingFeedback = remainingPendingFeedback(state.PendingFeedback, sentFeedback)
			}
			state.LastHeartbeatAt = time.Now()
		})
	}
	for _, action := range response.UltraActions {
		a.applyPolicyAction(action)
	}
	if token := strings.TrimSpace(a.diagToken); token != "" {
		a.submitDiagnostics(ctx, token)
	}
	a.mu.Lock()
	pendingDebugUUID := strings.TrimSpace(a.debugUUID)
	a.mu.Unlock()
	if pendingDebugUUID != "" {
		a.submitDebugLogs(ctx, pendingDebugUUID)
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
