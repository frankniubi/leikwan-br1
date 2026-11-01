package main

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// recordRuntimeCommands replaces the control-socket sender for one test and
// returns the commands it was asked to deliver.
func recordRuntimeCommands(t *testing.T, reply error) *[]map[string]any {
	t.Helper()
	var sent []map[string]any
	previous := sendRuntimeCommand
	sendRuntimeCommand = func(_ context.Context, _ string, command map[string]any) error {
		sent = append(sent, command)
		return reply
	}
	t.Cleanup(func() { sendRuntimeCommand = previous })
	return &sent
}

// graceState is an installed grant that expires in expiresIn, with the
// runtime process a real install would have.
func graceState(expiresIn time.Duration) *runtimeState {
	expiry := time.Now().Add(expiresIn).UTC()
	return &runtimeState{
		proc:             &runtimeProcess{paths: runtimePaths{controlSocket: "/run/test/control.sock"}},
		forward:          ForwardConfig{ForwardID: 7},
		policy:           &UltraPolicy{PolicyGeneration: "abc", ExpiresAt: formatRFC3339(expiry)},
		leaseInstalledAt: time.Now().Add(-time.Minute),
	}
}

// A network failure close to expiry keeps the grant serving, with the grace
// measured from the issued expiry.
func TestNetworkFailureNearExpiryExtendsTheGrant(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	sent := recordRuntimeCommands(t, nil)
	state := graceState(30 * time.Second)
	base, _ := parseRFC3339(state.policy.ExpiresAt)

	a.extendRuntimeGrace(state, errors.New("dial tcp: i/o timeout"))

	if len(*sent) != 1 {
		t.Fatalf("commands sent = %d, want 1", len(*sent))
	}
	command := (*sent)[0]
	if command["op"] != "extend_grace" || command["policy_generation"] != "abc" {
		t.Fatalf("unexpected command %v", command)
	}
	if got := command["grace_until"]; got != formatRFC3339(base.Add(runtimeLeaseGrace)) {
		t.Fatalf("grace_until = %v, want %s", got, formatRFC3339(base.Add(runtimeLeaseGrace)))
	}
	if state.graceUntil.IsZero() {
		t.Fatal("state must remember the extension")
	}
}

// A Panel refusal is never a reason to keep serving.
func TestPanelRefusalDoesNotExtendTheGrant(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	sent := recordRuntimeCommands(t, nil)
	state := graceState(30 * time.Second)
	a.extendRuntimeGrace(state, &panelError{Status: http.StatusConflict, Code: "policy_expired"})
	if len(*sent) != 0 || !state.graceUntil.IsZero() {
		t.Fatalf("a refusal must not extend: sent=%v graceUntil=%s", *sent, state.graceUntil)
	}
}

// Far from expiry a failed renewal is an ordinary retry, not a reason to ask
// the runtime to outlive its grant.
func TestFailureFarFromExpiryDoesNotExtend(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	sent := recordRuntimeCommands(t, nil)
	state := graceState(20 * time.Minute)
	a.extendRuntimeGrace(state, errors.New("timeout"))
	if len(*sent) != 0 {
		t.Fatalf("far from expiry must not extend: %v", *sent)
	}
}

// Once the bound has passed, the router stops asking; the runtime would refuse
// anyway, and the next fresh grant replaces the state.
func TestNoExtensionAfterTheBound(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	sent := recordRuntimeCommands(t, nil)
	state := graceState(-(runtimeLeaseGrace + time.Minute))
	a.extendRuntimeGrace(state, errors.New("timeout"))
	if len(*sent) != 0 {
		t.Fatalf("past the bound must not extend: %v", *sent)
	}
}

// The same extension is not re-sent on every failed attempt.
func TestExtensionIsSentOnce(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	sent := recordRuntimeCommands(t, nil)
	state := graceState(30 * time.Second)
	a.extendRuntimeGrace(state, errors.New("timeout"))
	a.extendRuntimeGrace(state, errors.New("timeout"))
	if len(*sent) != 1 {
		t.Fatalf("commands sent = %d, want 1", len(*sent))
	}
}

// A failed control send leaves no grace behind, so the next attempt retries.
func TestFailedExtensionIsRetried(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	recordRuntimeCommands(t, errors.New("ultra control socket: refused"))
	state := graceState(30 * time.Second)
	a.extendRuntimeGrace(state, errors.New("timeout"))
	if !state.graceUntil.IsZero() {
		t.Fatal("a failed extension must not be recorded as held")
	}
}

// A Panel refusal while the runtime holds grace ends it immediately.
func TestPanelRefusalStopsHeldGrace(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	sent := recordRuntimeCommands(t, nil)
	state := graceState(30 * time.Second)
	a.extendRuntimeGrace(state, errors.New("timeout"))
	a.stopRuntimeGrace(state, &panelError{Status: http.StatusConflict, Code: "quota_exhausted"})
	if len(*sent) != 2 || (*sent)[1]["op"] != "stop_grace" {
		t.Fatalf("expected extend then stop, got %v", *sent)
	}
	if !state.graceUntil.IsZero() {
		t.Fatal("grace must be cleared after a stop")
	}
}

// Without a held grace a refusal sends nothing.
func TestRefusalWithoutGraceSendsNothing(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	sent := recordRuntimeCommands(t, nil)
	a.stopRuntimeGrace(graceState(time.Minute), &panelError{Status: http.StatusConflict, Code: "quota_exhausted"})
	if len(*sent) != 0 {
		t.Fatalf("nothing to stop, but sent %v", *sent)
	}
}

// The Panel keeps returning a diagnostics token until its result is stored; a
// token the router already answered must not be queued again through a pull.
func TestAnsweredDiagnosticsTokenIsNotQueuedAgain(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		writeJSON(t, w, map[string]any{
			"ok": true, "config_revision": "rev-1", "forwards": []any{},
			"client": map[string]any{"id": 1, "diag_request_token": "tok-done"},
		})
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	a := newTestAgent(t, cfg)
	a.panel = newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")
	a.diagDoneToken = "tok-done"
	if err := a.pullOnce(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	a.mu.Lock()
	queued := a.diagToken
	a.mu.Unlock()
	if queued != "" {
		t.Fatalf("answered token was queued again: %q", queued)
	}
}
