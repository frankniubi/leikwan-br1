package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
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

func TestFeedbackNotRequeuedAfterHeartbeatAcknowledgmentOrWhenStale(t *testing.T) {
	var heartbeats []HeartbeatPayload
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body HeartbeatPayload
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			t.Fatalf("decode heartbeat: %v", err)
		}
		heartbeats = append(heartbeats, body)
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true,"ultra_usage_acknowledged":true}`))
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	agent := newTestAgent(t, cfg)
	agent.secrets = SecretState{AgentToken: "test-token", DeviceID: "device-47"}
	agent.panel = newPanelClient(agent.cfg, agent.secrets, "device-47", "router")

	freshAt := time.Now().UTC().Format(time.RFC3339)
	staleAt := time.Now().UTC().Add(-10 * time.Minute).Format(time.RFC3339)
	state := &runtimeState{
		forward: ForwardConfig{ForwardID: 47, BackendMode: "ultra"},
		proc:    &runtimeProcess{forwardID: 47, startedAt: time.Now()},
		status: &RuntimeStatus{
			ForwardID: 47,
			BytesIn:   100,
			CandidateFeedback: []CandidateFeedbk{
				{
					EvidenceID: "fresh-evidence-1",
					Reason:     "credential_rejected",
					Outcome:    "failure",
					ObservedAt: freshAt,
				},
				{
					EvidenceID: "stale-historical-evidence",
					Reason:     "credential_rejected",
					Outcome:    "failure",
					ObservedAt: staleAt,
				},
			},
		},
	}
	agent.runtimes[47] = state

	agent.collectUsage()
	if got := len(agent.store.Usage().PendingFeedback); got != 1 {
		t.Fatalf("pending feedback count = %d, want 1 (stale filtered)", got)
	}
	if err := agent.sendHeartbeat(context.Background()); err != nil {
		t.Fatalf("first heartbeat: %v", err)
	}
	if len(heartbeats) != 1 || len(heartbeats[0].UltraFeedback) != 1 {
		t.Fatalf("first heartbeat feedback = %+v", heartbeats)
	}

	// Subsequent traffic while runtime keeps the same rolling feedback list must
	// NOT requeue the already-acknowledged evidence_id.
	state.status.BytesIn += 250
	agent.collectUsage()
	if got := len(agent.store.Usage().PendingFeedback); got != 0 {
		t.Fatalf("acknowledged feedback requeued on new traffic: %+v", agent.store.Usage().PendingFeedback)
	}

	// A new feedback entry on an idle (zero-byte-delta) forward still reaches the Panel.
	state.status.CandidateFeedback = append(state.status.CandidateFeedback, CandidateFeedbk{
		EvidenceID: "idle-handshake-failure-2",
		Reason:     "credential_rejected",
		Outcome:    "failure",
		ObservedAt: time.Now().UTC().Format(time.RFC3339),
	})
	agent.collectUsage()
	if got := len(agent.store.Usage().PendingFeedback); got != 1 || agent.store.Usage().PendingFeedback[0].EvidenceID != "idle-handshake-failure-2" {
		t.Fatalf("expected only new idle feedback queued, got %+v", agent.store.Usage().PendingFeedback)
	}
	if err := agent.sendHeartbeat(context.Background()); err != nil {
		t.Fatalf("second heartbeat: %v", err)
	}
	if got := len(agent.store.Usage().PendingFeedback); got != 0 {
		t.Fatalf("idle feedback not cleared after ok heartbeat: %+v", agent.store.Usage().PendingFeedback)
	}
}
