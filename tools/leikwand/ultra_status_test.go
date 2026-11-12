package main

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"
)

// A router's runtime status must reach the Panel in the shape
// UltraClientStatusService::normalizeStatus reads, otherwise every OpenWrt
// device session stores an empty ultra_status_json and nobody can see why a
// router's Ultra forward fails.
func TestHeartbeatCarriesUltraStatusInPanelShape(t *testing.T) {
	var bodies []map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		var body map[string]any
		if err := json.Unmarshal(raw, &body); err != nil {
			t.Fatalf("decode heartbeat: %v", err)
		}
		for _, secret := range []string{"secret-password", "203.0.113.9", "runtime_attestation", "selection_tier", "location_source"} {
			if strings.Contains(string(raw), secret) {
				t.Errorf("heartbeat leaks %q: %s", secret, raw)
			}
		}
		bodies = append(bodies, body)
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true,"ultra_status_accepted":1,"ultra_usage_acknowledged":true}`))
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	agent := newTestAgent(t, cfg)
	agent.secrets = SecretState{AgentToken: "test-token", DeviceID: "device-117"}
	agent.panel = newPanelClient(agent.cfg, agent.secrets, "device-117", "router")

	// Exactly what tools/ultra-client writes, plus fields that must not travel.
	now := time.Now().UTC()
	candidate := strings.Repeat("c", 64)
	lease := strings.Repeat("d", 64)
	document := `{
		"forward_id": 117,
		"state": "degraded",
		"detected_ingress_zone": "CN-GD",
		"location_source": "probe",
		"udp_transport": "native_udp",
		"updated_at": "` + now.Format(time.RFC3339) + `",
		"error_code": "relay_handshake_timeout",
		"policy_generation": "` + strings.Repeat("a", 64) + `",
		"latest_tcp": {"selection_ref": "sel_0123456789abcdef", "selection_tier": "primary", "candidate_key_hash": "` + candidate + `", "lease_hash": "` + lease + `", "rtt_ms": 41, "p95_rtt_ms": 60, "selected_at": "` + now.Format(time.RFC3339) + `"},
		"ha_events": [
			{"protocol": "tcp", "reason": "relay_handshake_timeout", "generation": "g1", "at": "` + now.Format(time.RFC3339Nano) + `", "candidate_key_hash": "` + candidate + `", "lease_hash": "` + lease + `"},
			{"protocol": "tcp", "reason": "no_response", "generation": "g1", "at": "` + now.Format(time.RFC3339Nano) + `", "upstream_bytes": 517, "remote_read_bytes": 0, "remote_read_reason": "timeout", "remote_stopped_first": false, "local_path_avoided": true}
		],
		"ha_failures": 2,
		"udp_queue_drops": 3,
		"udp_setup_failures": 4,
		"bytes_in": 1000,
		"bytes_out": 200,
		"candidate_feedback": [{"candidate_key_hash": "` + candidate + `", "outcome": "failure", "reason": "relay_handshake_timeout", "evidence_id": "e1", "observed_at": "` + now.Format(time.RFC3339) + `"}],
		"runtime_attestation": {"fingerprint": "2026.11.12.1", "self_sha256": "` + strings.Repeat("e", 64) + `"},
		"endpoint": {"ip": "203.0.113.9", "port": 443},
		"credential": {"user": "u", "password": "secret-password"}
	}`
	path := t.TempDir() + "/status.json"
	if err := os.WriteFile(path, []byte(document), 0o600); err != nil {
		t.Fatal(err)
	}
	status, err := readRuntimeStatus(path)
	if err != nil {
		t.Fatalf("read status: %v", err)
	}
	agent.runtimes[117] = &runtimeState{
		forward:  ForwardConfig{ForwardID: 117, BackendMode: "ultra"},
		proc:     &runtimeProcess{forwardID: 117, startedAt: time.Now()},
		status:   status,
		statusAt: time.Now(),
	}

	agent.collectUsage()
	if err := agent.sendHeartbeat(context.Background()); err != nil {
		t.Fatalf("heartbeat: %v", err)
	}
	if len(bodies) != 1 {
		t.Fatalf("heartbeats = %d", len(bodies))
	}
	entries, ok := bodies[0]["ultra_status"].([]any)
	if !ok || len(entries) != 1 {
		t.Fatalf("ultra_status = %#v, want one entry", bodies[0]["ultra_status"])
	}
	entry := entries[0].(map[string]any)
	want := map[string]any{
		"forward_id":            float64(117),
		"state":                 "degraded",
		"udp_transport":         "native_udp",
		"detected_ingress_zone": "cn-gd",
		"ha_failures":           float64(2),
		"udp_queue_drops":       float64(3),
		"udp_setup_failures":    float64(4),
		"bytes_in":              float64(1000),
		"bytes_out":             float64(200),
		"error_code":            "relay_handshake_timeout",
	}
	for key, value := range want {
		if entry[key] != value {
			t.Errorf("%s = %#v, want %#v", key, entry[key], value)
		}
	}
	for _, key := range []string{"candidate_feedback", "policy_generation", "endpoint", "credential"} {
		if _, leaked := entry[key]; leaked {
			t.Errorf("status entry must not carry %s: %#v", key, entry)
		}
	}
	if entry["updated_at"] != now.Format(time.RFC3339) {
		t.Errorf("updated_at = %#v", entry["updated_at"])
	}
	tcp, ok := entry["latest_tcp"].(map[string]any)
	if !ok || tcp["selection_ref"] != "sel_0123456789abcdef" || tcp["rtt_ms"] != float64(41) || tcp["candidate_key_hash"] != candidate {
		t.Fatalf("latest_tcp = %#v", entry["latest_tcp"])
	}
	if _, leaked := tcp["lease_hash"]; leaked {
		t.Fatalf("selection lease hash must not be reported: %#v", tcp)
	}
	events, ok := entry["ha_events"].([]any)
	if !ok || len(events) != 2 {
		t.Fatalf("ha_events = %#v", entry["ha_events"])
	}
	first := events[0].(map[string]any)
	if first["protocol"] != "tcp" || first["reason"] != "relay_handshake_timeout" || first["candidate_key_hash"] != candidate || first["lease_hash"] != lease {
		t.Fatalf("first ha_event = %#v", first)
	}
	second := events[1].(map[string]any)
	if second["upstream_bytes"] != float64(517) || second["remote_read_bytes"] != float64(0) ||
		second["remote_read_reason"] != "timeout" || second["remote_stopped_first"] != false || second["local_path_avoided"] != true {
		t.Fatalf("second ha_event = %#v", second)
	}
}

// No runtime document (cold start, no grant yet) still sends a list, exactly
// like the desktop, so the Panel clears a stale row instead of keeping it.
func TestHeartbeatSendsEmptyUltraStatusListWithoutStatus(t *testing.T) {
	var raw []byte
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ = io.ReadAll(r.Body)
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true}`))
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	agent := newTestAgent(t, cfg)
	agent.secrets = SecretState{AgentToken: "test-token", DeviceID: "device-5"}
	agent.panel = newPanelClient(agent.cfg, agent.secrets, "device-5", "router")
	agent.runtimes[5] = &runtimeState{
		forward: ForwardConfig{ForwardID: 5, BackendMode: "ultra"},
		proc:    &runtimeProcess{forwardID: 5, startedAt: time.Now()},
	}

	agent.collectUsage()
	if err := agent.sendHeartbeat(context.Background()); err != nil {
		t.Fatalf("heartbeat: %v", err)
	}
	if !strings.Contains(string(raw), `"ultra_status":[]`) {
		t.Fatalf("heartbeat must carry an empty ultra_status list: %s", raw)
	}
}

// The native relay's document is parsed once per heartbeat for billing and
// reused for the status report; a missing or empty file reports nothing.
func TestGatewayRelayStatusReusesBillingRead(t *testing.T) {
	store, err := newStore(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	agent := &Agent{cfg: defaultConfig(), log: newLogger("error"), store: store, runtimes: map[int]*runtimeState{}}
	paths := gatewayRelayPaths(t.TempDir())
	if err := os.MkdirAll(paths.dir, 0o700); err != nil {
		t.Fatal(err)
	}
	manager := newGatewayManager(agent)
	manager.plan = testPlan(t, defaultConfig(), testCapability(true))
	manager.primary.plan = manager.plan.carrierPlan
	manager.primary.relay = &runtimeProcess{native: true, paths: paths}
	agent.gateway = manager
	carrier := manager.primary.plan.Carrier.ForwardID

	agent.collectUsage() // no status file yet
	if got := agent.collectUltraStatus(time.Now()); got == nil || len(got) != 0 {
		t.Fatalf("missing relay status = %#v, want an empty list", got)
	}

	if err := os.WriteFile(paths.statusPath, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	agent.collectUsage() // empty status file
	if got := agent.collectUltraStatus(time.Now()); len(got) != 0 {
		t.Fatalf("empty relay status = %#v", got)
	}

	raw, _ := json.Marshal(RuntimeStatus{
		ForwardID: carrier, State: "ready", UpdatedAt: time.Now().UTC().Format(time.RFC3339),
		HAFailures: 1, HAEvents: []HAEvent{{Protocol: "udp", Reason: "association_timeout", At: time.Now().UTC().Format(time.RFC3339)}},
	})
	if err := os.WriteFile(paths.statusPath, raw, 0o600); err != nil {
		t.Fatal(err)
	}
	agent.collectUsage()
	// Removing the file proves the report comes from the cached billing read.
	_ = os.Remove(paths.statusPath)
	got := agent.collectUltraStatus(time.Now())
	if len(got) != 1 || got[0].ForwardID != carrier || got[0].HAFailures != 1 || len(got[0].HAEvents) != 1 {
		t.Fatalf("relay status = %#v", got)
	}

	manager.stopSlot(&manager.primary, "test")
	if got := agent.collectUltraStatus(time.Now()); len(got) != 0 {
		t.Fatalf("a stopped relay must not keep reporting: %#v", got)
	}
}

func TestUltraStatusReportsSkipStaleStoppedAndForeignDocuments(t *testing.T) {
	now := time.Now().UTC()
	fresh := now.Format(time.RFC3339)
	stale := now.Add(-10 * time.Minute).Format(time.RFC3339)
	sources := []ultraStatusSource{
		{forwardID: 1, status: &RuntimeStatus{ForwardID: 1, State: "ready", UpdatedAt: fresh}},
		{forwardID: 2, status: &RuntimeStatus{ForwardID: 2, State: "ready", UpdatedAt: stale}},
		{forwardID: 3, status: &RuntimeStatus{ForwardID: 3, State: "stopped", UpdatedAt: fresh}},
		{forwardID: 4, status: &RuntimeStatus{ForwardID: 4, State: "error", ErrorCode: "runtime_stopped", UpdatedAt: fresh}},
		{forwardID: 5, status: &RuntimeStatus{ForwardID: 99, State: "ready", UpdatedAt: fresh}},
		{forwardID: 6, status: nil},
		// No updated_at: the time leikwand read it decides.
		{forwardID: 7, status: &RuntimeStatus{ForwardID: 7, State: "wedged"}, readAt: now.Add(-10 * time.Minute)},
		{forwardID: 8, status: &RuntimeStatus{ForwardID: 8, State: "wedged"}, readAt: now},
	}
	got := buildUltraStatusReports(sources, now)
	if len(got) != 2 {
		t.Fatalf("reports = %#v, want forwards 1 and 8", got)
	}
	ids := map[int]UltraStatusReport{}
	for _, report := range got {
		ids[report.ForwardID] = report
	}
	if _, ok := ids[1]; !ok {
		t.Fatalf("fresh forward 1 missing: %#v", got)
	}
	if ids[8].State != "error" || ids[8].DetectedIngressZone != "unknown" || ids[8].HAEvents == nil {
		t.Fatalf("unknown state must normalize like the Panel: %#v", ids[8])
	}
}

func TestUltraStatusReportsNewestPerForwardAndLeaseFailure(t *testing.T) {
	now := time.Now().UTC()
	older := now.Add(-time.Minute).Format(time.RFC3339)
	newer := now.Format(time.RFC3339)
	sources := []ultraStatusSource{
		{forwardID: 9, status: &RuntimeStatus{ForwardID: 9, State: "ready", UpdatedAt: older, HAFailures: 1}},
		{forwardID: 9, status: &RuntimeStatus{ForwardID: 9, State: "ready", UpdatedAt: newer, HAFailures: 5},
			leaseFailures: 2, leaseErr: "panel error 403: runtime_unattested"},
		{forwardID: 10, status: &RuntimeStatus{ForwardID: 10, State: "ready", UpdatedAt: older},
			leaseFailures: 1, leaseErr: `Post "https://panel/api/client/ultra/lease": dial tcp 203.0.113.9:443: i/o timeout`},
	}
	got := buildUltraStatusReports(sources, now)
	if len(got) != 2 || got[0].ForwardID != 9 || got[1].ForwardID != 10 {
		t.Fatalf("reports = %#v, want newest first", got)
	}
	if got[0].HAFailures != 5 || got[0].State != "degraded" || got[0].ErrorCode != "runtime_unattested" {
		t.Fatalf("forward 9 = %#v", got[0])
	}
	if got[1].State != "degraded" || got[1].ErrorCode != "lease_refresh_failed" {
		t.Fatalf("free-text lease errors must collapse to a code: %#v", got[1])
	}
}

func TestUltraStatusReportsAreBounded(t *testing.T) {
	now := time.Now().UTC()
	hash := strings.Repeat("f", 64)
	events := make([]HAEvent, 16)
	for i := range events {
		bytes := int64(i)
		events[i] = HAEvent{
			Protocol: "TCP", Reason: "relay_handshake_timeout", Generation: strings.Repeat("a", 200),
			At: now.Format(time.RFC3339Nano), CandidateKeyHash: hash, LeaseHash: "not-a-hash", UpstreamBytes: &bytes,
			RemoteReadReason: "Bad Reason!",
		}
	}
	events = append(events, HAEvent{Protocol: "quic", Reason: "x"}, HAEvent{Protocol: "udp", Reason: " "})
	selection := &SelectionStatus{SelectionRef: "sel_0123456789abcdef", CandidateKeyHash: hash, RTTMillis: -5}
	sources := make([]ultraStatusSource, 0, 64)
	for id := 1; id <= 64; id++ {
		sources = append(sources, ultraStatusSource{forwardID: id, status: &RuntimeStatus{
			ForwardID: id, State: "degraded", UpdatedAt: now.Format(time.RFC3339),
			ErrorCode: strings.Repeat("e", 300) + "\x00\n", DetectedIngressZone: "zone with spaces",
			LatestTCP: selection, ActiveUDP: selection, HAEvents: events, BytesIn: -1,
		}})
	}
	got := buildUltraStatusReports(sources, now)
	if len(got) == 0 || len(got) > maxUltraStatusEntries {
		t.Fatalf("entries = %d", len(got))
	}
	raw, _ := json.Marshal(got)
	if len(raw) > maxUltraStatusBytes {
		t.Fatalf("encoded ultra_status = %d bytes, budget %d", len(raw), maxUltraStatusBytes)
	}
	for _, report := range got {
		if len(report.HAEvents) > maxUltraStatusEvents || len(report.ErrorCode) != 64 || report.DetectedIngressZone != "unknown" ||
			report.BytesIn != 0 || report.LatestTCP.RTTMillis != 0 {
			t.Fatalf("unbounded report: %#v", report)
		}
		for _, event := range report.HAEvents {
			// The two invalid events at the tail are dropped, so the last 8
			// slots hold 6 valid ones.
			if event.Protocol != "tcp" || len(event.Generation) != 64 || event.LeaseHash != "" || event.RemoteReadReason != "" {
				t.Fatalf("unsanitized event: %#v", event)
			}
		}
	}
	if len(got[0].HAEvents) != 6 {
		t.Fatalf("first report events = %d, want the 6 valid of the last 8", len(got[0].HAEvents))
	}
}
