package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

// limitPayload is the exact envelope the Panel returns on any /api/client/*
// endpoint when the client's online device limit is full.
const limitPayload = `{"ok":false,"error":"client_online_limit_reached","protocol_version":1,` +
	`"online_device_limit":2,"active_online_devices":2,` +
	`"details":{"online_device_limit":2,"active_online_devices":2}}`

const limitMessage = "Device limit reached (2 of 2 devices online). Disconnect another device or ask your provider " +
	"to raise the device limit. This router reconnects automatically about 3 minutes after another device disconnects."

func writeLimit(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusTooManyRequests)
	_, _ = w.Write([]byte(limitPayload))
}

func TestDecodeOnlineLimitFlatFields(t *testing.T) {
	err := decodePanelError(http.StatusTooManyRequests, []byte(limitPayload))
	pe, ok := asOnlineLimitError(err)
	if !ok {
		t.Fatalf("error %v is not recognised as the online limit", err)
	}
	if pe.Status != 429 || pe.OnlineDeviceLimit != 2 || pe.ActiveOnlineDevices != 2 {
		t.Fatalf("decoded %+v", pe)
	}
	if got := onlineLimitMessage(pe); got != limitMessage {
		t.Fatalf("message = %q", got)
	}
}

// The nested details object is the fallback for a Panel that only fills it.
func TestDecodeOnlineLimitNestedDetails(t *testing.T) {
	payload := `{"ok":false,"error":"client_online_limit_reached","details":{"online_device_limit":5,"active_online_devices":6}}`
	pe, ok := asOnlineLimitError(decodePanelError(http.StatusTooManyRequests, []byte(payload)))
	if !ok || pe.OnlineDeviceLimit != 5 || pe.ActiveOnlineDevices != 6 {
		t.Fatalf("decoded %+v ok=%v", pe, ok)
	}
	if got := onlineLimitMessage(pe); !contains(got, "(6 of 5 devices online)") {
		t.Fatalf("message = %q", got)
	}
}

// Without counts the message still explains the refusal instead of printing
// a zero quota.
func TestOnlineLimitMessageWithoutCounts(t *testing.T) {
	pe, ok := asOnlineLimitError(decodePanelError(429, []byte(`{"ok":false,"error":"client_online_limit_reached"}`)))
	if !ok {
		t.Fatal("code not recognised")
	}
	got := onlineLimitMessage(pe)
	if contains(got, "0 of 0") || !contains(got, "Device limit reached. Disconnect another device") {
		t.Fatalf("message = %q", got)
	}
}

// A full device quota is a business decision: moving to the mirror would not
// change the answer, so a 429 must never be treated like a 5xx.
func TestPanelDoesNotFailOverOnOnlineLimit(t *testing.T) {
	var primaryHits, mirrorHits int32
	primary := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&primaryHits, 1)
		writeLimit(w)
	}))
	defer primary.Close()
	mirror := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&mirrorHits, 1)
		writeJSON(t, w, map[string]any{"ok": true, "config_revision": "rev-2"})
	}))
	defer mirror.Close()

	cfg := defaultConfig()
	cfg.PanelURL = primary.URL
	cfg.FallbackURL = mirror.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	_, err := client.Pull(context.Background(), "", 0)
	if _, ok := asOnlineLimitError(err); !ok {
		t.Fatalf("err = %v, want client_online_limit_reached", err)
	}
	if atomic.LoadInt32(&mirrorHits) != 0 || atomic.LoadInt32(&primaryHits) != 1 {
		t.Fatalf("primary=%d mirror=%d, want 1/0", primaryHits, mirrorHits)
	}
	if client.baseURL() != primary.URL {
		t.Fatal("endpoint rotated on a 429")
	}
}

func TestPullOnlineLimitReachesStatusAndSlowsRetry(t *testing.T) {
	var refuse atomic.Bool
	refuse.Store(true)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if refuse.Load() {
			writeLimit(w)
			return
		}
		writeJSON(t, w, map[string]any{"ok": true, "config_revision": "rev-9", "forwards": []any{}})
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	cfg.FallbackURL = ""
	agent := newTestAgent(t, cfg)
	// Desired state from an earlier successful pull must survive the refusal.
	agent.forwards[7] = ForwardConfig{ForwardID: 7, BackendMode: "ultra"}

	err := agent.pullOnce(context.Background())
	if err == nil {
		t.Fatal("expected the pull to be refused")
	}
	delay := agent.notePullFailure(err)
	if delay != 60*time.Second {
		t.Fatalf("retry delay = %s, want 60s", delay)
	}
	// A second refusal keeps the steady cadence instead of a growing backoff.
	if again := agent.notePullFailure(err); again != 60*time.Second {
		t.Fatalf("second retry delay = %s", again)
	}

	snap := agent.snapshot()
	if snap.LastPullError != limitMessage {
		t.Fatalf("last_pull_error = %q", snap.LastPullError)
	}
	if snap.LastPullErrorCode != codeClientOnlineLimit || snap.OnlineDeviceLimit != 2 || snap.ActiveOnlineDevices != 2 {
		t.Fatalf("snapshot code=%q limit=%d active=%d", snap.LastPullErrorCode, snap.OnlineDeviceLimit, snap.ActiveOnlineDevices)
	}
	if stored := agent.store.Pull(); stored.LastError != limitMessage || stored.LastStatus != "error" {
		t.Fatalf("stored pull state = %+v", stored)
	}
	if _, kept := agent.forwards[7]; !kept {
		t.Fatal("applied forwards were torn down by a control-plane refusal")
	}

	refuse.Store(false)
	if err := agent.pullOnce(context.Background()); err != nil {
		t.Fatalf("pull after a slot freed: %v", err)
	}
	snap = agent.snapshot()
	if snap.LastPullError != "" || snap.LastPullErrorCode != "" || snap.OnlineDeviceLimit != 0 || snap.ActiveOnlineDevices != 0 {
		t.Fatalf("error not cleared on success: %+v", snap)
	}
	if stored := agent.store.Pull(); stored.LastError != "" || stored.Failures != 0 {
		t.Fatalf("stored pull state not cleared: %+v", stored)
	}
}

// Any pull error, not only the device limit, must reach the status snapshot.
func TestPullGenericErrorReachesStatus(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusForbidden)
		writeJSON(t, w, map[string]any{"ok": false, "error": "agent_token_invalid"})
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	cfg.FallbackURL = ""
	agent := newTestAgent(t, cfg)

	delay := agent.notePullFailure(agent.pullOnce(context.Background()))
	if delay != 5*time.Second {
		t.Fatalf("first backoff = %s, want 5s", delay)
	}
	snap := agent.snapshot()
	if snap.LastPullError != "panel error 403: agent_token_invalid" || snap.LastPullErrorCode != "agent_token_invalid" {
		t.Fatalf("snapshot error=%q code=%q", snap.LastPullError, snap.LastPullErrorCode)
	}
	if snap.OnlineDeviceLimit != 0 {
		t.Fatalf("limit counts leaked into a non-limit error: %d", snap.OnlineDeviceLimit)
	}
}

// Registration is local-only: the operator pastes credentials the panel
// already issued, so no panel call may happen here. The device limit is
// enforced by the next config pull, whose human message reaches LuCI through
// the pull status (see TestPullOnlineLimitReachesStatusAndSlowsRetry).
func TestRegisterIsLocalOnlyAndValidatesInput(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Errorf("unexpected panel call %s: registration is local-only", r.URL.Path)
		writeLimit(w)
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	cfg.FallbackURL = ""
	agent := newTestAgent(t, cfg)
	ctl := &ctlServer{agent: agent, secret: "s3cret"}

	if reply := ctl.dispatch(CtlRequest{Cmd: "register", Args: map[string]string{"client_id": "42", "agent_token": "tok", "token": "s3cret"}}); !reply.OK {
		t.Fatalf("valid registration refused: %s", reply.Error)
	}
	if !agent.enrolled() {
		t.Fatal("a valid registration must enrol the device")
	}
	if agent.secrets.ClientID != 42 {
		t.Fatalf("client id = %d", agent.secrets.ClientID)
	}
	if reply := ctl.dispatch(CtlRequest{Cmd: "register", Args: map[string]string{"client_id": "42", "token": "s3cret"}}); reply.OK || reply.Error != "agent_token_required" {
		t.Fatalf("missing token: %+v", reply)
	}
	if reply := ctl.dispatch(CtlRequest{Cmd: "register", Args: map[string]string{"agent_token": "tok", "token": "s3cret"}}); reply.OK || reply.Error != "invalid_client_id" {
		t.Fatalf("missing client id: %+v", reply)
	}
	if reply := ctl.dispatch(CtlRequest{Cmd: "register", Args: map[string]string{"client_id": "42", "agent_token": "tok"}}); reply.OK || reply.Error != "unauthorized" {
		t.Fatalf("missing control token: %+v", reply)
	}
}
