package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
)

// A control-plane endpoint that answers 5xx is a transport failure: the agent
// must move to the mirror rather than stall the whole device.
func TestPanelFailsOverOnServerError(t *testing.T) {
	var primaryHits, mirrorHits int32
	primary := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&primaryHits, 1)
		w.WriteHeader(http.StatusBadGateway)
	}))
	defer primary.Close()
	mirror := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&mirrorHits, 1)
		writeJSON(t, w, map[string]any{"ok": true, "config_revision": "rev-2", "forwards": []any{}})
	}))
	defer mirror.Close()

	cfg := defaultConfig()
	cfg.PanelURL = primary.URL
	cfg.FallbackURL = mirror.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	response, err := client.Pull(context.Background(), "", 0)
	if err != nil {
		t.Fatalf("pull after failover: %v", err)
	}
	if response.ConfigRevision != "rev-2" {
		t.Fatalf("revision = %q", response.ConfigRevision)
	}
	if atomic.LoadInt32(&mirrorHits) == 0 {
		t.Fatal("mirror was never contacted")
	}
}

// A business error is a decision, not a transport fault. Rotating the endpoint
// on a 4xx would hide the real cause and double the load on the mirror.
func TestPanelDoesNotFailOverOnBusinessError(t *testing.T) {
	var primaryHits, mirrorHits int32
	primary := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&primaryHits, 1)
		w.WriteHeader(http.StatusConflict)
		writeJSON(t, w, map[string]any{"ok": false, "error": "no_approved_candidate"})
	}))
	defer primary.Close()
	mirror := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&mirrorHits, 1)
		writeJSON(t, w, map[string]any{"ok": true, "leases": []any{}})
	}))
	defer mirror.Close()

	cfg := defaultConfig()
	cfg.PanelURL = primary.URL
	cfg.FallbackURL = mirror.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	_, err := client.Lease(context.Background(), LeaseRequest{DeviceID: "dev-1"})
	if err == nil {
		t.Fatal("expected the panel error to surface")
	}
	var panelErr *panelError
	if !asPanelError(err, &panelErr) || panelErr.Code != "no_approved_candidate" {
		t.Fatalf("error = %v, want no_approved_candidate", err)
	}
	if atomic.LoadInt32(&mirrorHits) != 0 {
		t.Fatal("mirror must not be used for a business error")
	}
	if atomic.LoadInt32(&primaryHits) != 1 {
		t.Fatalf("primary hits = %d, want exactly 1", primaryHits)
	}
}

func TestPanelReportsNotModified(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("since_revision") == "rev-1" {
			w.WriteHeader(http.StatusNotModified)
			return
		}
		writeJSON(t, w, map[string]any{"ok": true, "config_revision": "rev-1"})
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	if _, err := client.Pull(context.Background(), "rev-1", 0); err != errNotModified {
		t.Fatalf("err = %v, want errNotModified", err)
	}
	if _, err := client.Pull(context.Background(), "", 0); err != nil {
		t.Fatalf("unconditional pull: %v", err)
	}
}

// An older panel rejects the openwrt platform tag. The agent must degrade to
// the generic Linux tag exactly once and then succeed, so a router still works
// against a panel that has not been updated yet.
func TestPanelDegradesPlatformOnceOnRejection(t *testing.T) {
	var seen []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		platform := r.Header.Get("X-Device-Platform")
		seen = append(seen, platform)
		if platform != "linux" {
			w.WriteHeader(http.StatusUnprocessableEntity)
			writeJSON(t, w, map[string]any{"ok": false, "error": "location_platform_invalid"})
			return
		}
		writeJSON(t, w, map[string]any{"ok": true, "config_revision": "rev-ok"})
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	response, err := client.Pull(context.Background(), "", 0)
	if err != nil {
		t.Fatalf("pull after platform degradation: %v", err)
	}
	if response.ConfigRevision != "rev-ok" {
		t.Fatalf("revision = %q", response.ConfigRevision)
	}
	if len(seen) != 2 || seen[0] != "openwrt" || seen[1] != "linux" {
		t.Fatalf("platform sequence = %v, want [openwrt linux]", seen)
	}

	// A second request must go straight to the working tag.
	if _, err := client.Pull(context.Background(), "", 0); err != nil {
		t.Fatalf("second pull: %v", err)
	}
	if seen[len(seen)-1] != "linux" {
		t.Fatalf("platform tag did not stay at linux: %v", seen)
	}
}

// The deployed Panel's generic exception handler masks location_platform_invalid
// as {"error":{"code":"validation_failed",...}}. The agent must still decode the
// object envelope and degrade, or a router never completes its first pull.
func TestPanelDegradesPlatformOnMaskedValidationError(t *testing.T) {
	var seen []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		platform := r.Header.Get("X-Device-Platform")
		seen = append(seen, platform)
		if platform != "linux" {
			w.WriteHeader(http.StatusUnprocessableEntity)
			writeJSON(t, w, map[string]any{"error": map[string]any{
				"code":         "validation_failed",
				"message":      "The request data is invalid.",
				"reference_id": "c53e0b1d5023dff2",
			}})
			return
		}
		writeJSON(t, w, map[string]any{"ok": true, "config_revision": "rev-ok"})
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	cfg.FallbackURL = "" // a single endpoint: the replay must not need a failover slot
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")
	if _, err := client.Pull(context.Background(), "", 0); err != nil {
		t.Fatalf("pull after masked platform rejection: %v", err)
	}
	if len(seen) != 2 || seen[0] != "openwrt" || seen[1] != "linux" {
		t.Fatalf("platform sequence = %v, want [openwrt linux]", seen)
	}
}

// A masked 422 after the degrade is a real validation failure: it is surfaced
// with its decoded code and message instead of degrading again.
func TestDecodePanelErrorObjectEnvelope(t *testing.T) {
	err := decodePanelError(http.StatusUnprocessableEntity,
		[]byte(`{"error":{"code":"validation_failed","message":"The request data is invalid.","reference_id":"x"}}`))
	pe, ok := err.(*panelError)
	if !ok || pe.Code != "validation_failed" || pe.Msg != "The request data is invalid." {
		t.Fatalf("decoded = %#v", err)
	}
	if !platformRejected(pe) {
		t.Fatal("masked 422 must count as a platform rejection")
	}
	if platformRejected(&panelError{Status: http.StatusBadRequest, Code: "validation_failed"}) {
		t.Fatal("a non-422 validation failure is not a platform rejection")
	}
}

// Every request must carry the identity headers the panel binds device
// sessions to; a missing one silently breaks the online-device accounting.
func TestPanelSendsDeviceIdentityHeaders(t *testing.T) {
	var captured http.Header
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		captured = r.Header.Clone()
		writeJSON(t, w, map[string]any{"ok": true})
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "secret-token"}, "dev-42", "Leikwan Bridge on router")

	if _, err := client.Pull(context.Background(), "", 0); err != nil {
		t.Fatalf("pull: %v", err)
	}
	checks := map[string]string{
		"X-Device-Id":          "dev-42",
		"X-Device-UUID":        "dev-42",
		"X-Device-Name":        "Leikwan Bridge on router",
		"X-Device-Form-Factor": "router",
		"Authorization":        "Bearer secret-token",
	}
	for header, want := range checks {
		if got := captured.Get(header); got != want {
			t.Fatalf("%s = %q, want %q", header, got, want)
		}
	}
	if captured.Get("X-Client-Version") == "" || captured.Get("X-Device-Architecture") == "" {
		t.Fatal("version/architecture headers are missing")
	}
}

// The lease response is consumed by ultra-client, so the decode has to be
// lossless. This test pins the exact field names.
func TestPanelDecodesLeaseFrames(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw := `{"ok":true,"leases":[{
			"protocol":"leikwan-ultra-policy-v2","purpose":"ultra","forward_id":7,
			"session_ticket":"ticket-1","policy_generation":"gen-1","lease_id":"lease-1",
			"expires_at":"2026-01-01T00:05:00Z","transport":"tcp+udp",
			"rate_limit_mbps":100,"quota_remaining_bytes":1000,"target_digest":"digest-1",
			"selection_ref":"ref-1","selection_tier":"primary","candidate_key_hash":"keyhash",
			"nonce_hash":"nonce","endpoint":{"ip":"203.0.113.9","port":24443},
			"credential":{"user":"u1","password":"p1"}}]}`
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(raw))
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	response, err := client.Lease(context.Background(), LeaseRequest{DeviceID: "dev-1"})
	if err != nil {
		t.Fatalf("lease: %v", err)
	}
	if len(response.Leases) != 1 {
		t.Fatalf("leases = %d", len(response.Leases))
	}
	lease := response.Leases[0]
	if lease.ForwardID != 7 || lease.PolicyGeneration != "gen-1" || lease.LeaseID != "lease-1" {
		t.Fatalf("identity fields decoded wrong: %+v", lease)
	}
	if lease.Endpoint.IP != "203.0.113.9" || lease.Endpoint.Port != 24443 {
		t.Fatalf("endpoint decoded wrong: %+v", lease.Endpoint)
	}
	if lease.Credential.User != "u1" || lease.Credential.Password != "p1" {
		t.Fatalf("credential decoded wrong: %+v", lease.Credential)
	}
	// Round-tripping must reproduce the wire format ultra-client expects.
	encoded, err := json.Marshal(lease)
	if err != nil {
		t.Fatalf("re-encode lease: %v", err)
	}
	var generic map[string]any
	if err := json.Unmarshal(encoded, &generic); err != nil {
		t.Fatalf("re-decode lease: %v", err)
	}
	for _, key := range []string{"protocol", "forward_id", "session_ticket", "policy_generation", "lease_id", "expires_at", "transport", "target_digest", "selection_ref", "selection_tier", "endpoint", "credential"} {
		if _, ok := generic[key]; !ok {
			t.Fatalf("lease frame lost field %q", key)
		}
	}
}

func TestRedactSecrets(t *testing.T) {
	input := "GET /x Authorization: Bearer abc123def failed agent_token=deadbeef&x=1"
	output := redactSecrets(input)
	for _, secret := range []string{"abc123def", "deadbeef"} {
		if contains(output, secret) {
			t.Fatalf("secret %q survived redaction: %s", secret, output)
		}
	}
}

func writeJSON(t *testing.T, w http.ResponseWriter, payload any) {
	t.Helper()
	w.Header().Set("Content-Type", "application/json")
	if err := json.NewEncoder(w).Encode(payload); err != nil {
		t.Fatalf("encode response: %v", err)
	}
}

func asPanelError(err error, target **panelError) bool {
	typed, ok := err.(*panelError)
	if ok {
		*target = typed
	}
	return ok
}

func contains(haystack, needle string) bool {
	return len(needle) > 0 && len(haystack) >= len(needle) && indexOf(haystack, needle) >= 0
}

func indexOf(haystack, needle string) int {
	for i := 0; i+len(needle) <= len(haystack); i++ {
		if haystack[i:i+len(needle)] == needle {
			return i
		}
	}
	return -1
}
