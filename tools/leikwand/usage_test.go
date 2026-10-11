package main

import (
	"testing"
	"time"
)

// ultra-client counters are process-scoped. When the runtime restarts they go
// back to zero, and a naive delta would under-report by the whole previous
// total. This is the accounting bug that loses billable traffic.
func TestCollectUsageHandlesCounterReset(t *testing.T) {
	agent := newTestAgent(t, defaultConfig())
	state := &runtimeState{
		forward: ForwardConfig{ForwardID: 1, BackendMode: "ultra"},
		proc:    &runtimeProcess{forwardID: 1, startedAt: time.Now()},
	}
	agent.runtimes[1] = state

	state.status = &RuntimeStatus{ForwardID: 1, BytesIn: 1000, BytesOut: 500}
	agent.collectUsage()

	state.status = &RuntimeStatus{ForwardID: 1, BytesIn: 1500, BytesOut: 900}
	agent.collectUsage()

	// Runtime restarted: counters drop back below the previous reading.
	state.status = &RuntimeStatus{ForwardID: 1, BytesIn: 200, BytesOut: 100}
	agent.collectUsage()

	pending := agent.store.Usage().PendingUltra
	if len(pending) != 3 {
		t.Fatalf("expected 3 usage snapshots, got %d", len(pending))
	}
	if pending[0].BytesIn != 1000 || pending[0].BytesOut != 500 {
		t.Fatalf("first delta = %d/%d", pending[0].BytesIn, pending[0].BytesOut)
	}
	if pending[1].BytesIn != 500 || pending[1].BytesOut != 400 {
		t.Fatalf("second delta = %d/%d", pending[1].BytesIn, pending[1].BytesOut)
	}
	if pending[2].BytesIn != 200 || pending[2].BytesOut != 100 {
		t.Fatalf("post-reset delta = %d/%d, want the new absolute values", pending[2].BytesIn, pending[2].BytesOut)
	}
}

func TestCollectUsageSkipsUnchangedCounters(t *testing.T) {
	agent := newTestAgent(t, defaultConfig())
	state := &runtimeState{
		forward: ForwardConfig{ForwardID: 2, BackendMode: "ultra"},
		proc:    &runtimeProcess{forwardID: 2, startedAt: time.Now()},
		status:  &RuntimeStatus{ForwardID: 2, BytesIn: 10, BytesOut: 20},
	}
	agent.runtimes[2] = state
	agent.collectUsage()
	agent.collectUsage()
	if got := len(agent.store.Usage().PendingUltra); got != 1 {
		t.Fatalf("idle runtime produced %d snapshots, want 1", got)
	}
}

func TestTrimFeedbackBoundsPayload(t *testing.T) {
	items := make([]UltraCandidateFeedback, 10)
	trimmed := trimFeedback(items, 4)
	if len(trimmed) != 4 {
		t.Fatalf("trimmed length = %d", len(trimmed))
	}
	if len(trimFeedback(items, 100)) != 10 {
		t.Fatal("short feedback must pass through unchanged")
	}
}
