package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

func TestRedactDebugTextScrubsSecretsAndIPs(t *testing.T) {
	got := redactDebugText("Bearer secret-tok peer 203.0.113.42 and [2001:db8::1] ok")
	for _, leak := range []string{"secret-tok", "203.0.113.42", "2001:db8::1"} {
		if strings.Contains(got, leak) {
			t.Fatalf("redacted text still leaked %q: %s", leak, got)
		}
	}
	if !strings.Contains(got, "ok") {
		t.Fatalf("expected non-sensitive text preserved: %s", got)
	}
}

func TestUploadLogsNowAndPullTriggeredUpload(t *testing.T) {
	var (
		mu       sync.Mutex
		started  int
		uploaded []string
	)
	const wantUUID = "11223344-5566-4777-8888-99aabbccddee"
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/client/debug/start":
			mu.Lock()
			started++
			mu.Unlock()
			writeJSON(t, w, map[string]any{
				"ok":             true,
				"reference_code": "11223344",
				"debug_collection": map[string]any{
					"uuid":   wantUUID,
					"status": "active",
				},
			})
		case "/api/client/debug/log":
			var body map[string]any
			if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
				t.Fatalf("decode chunk: %v", err)
			}
			raw, err := base64.StdEncoding.DecodeString(body["chunk_base64"].(string))
			if err != nil {
				t.Fatalf("base64: %v", err)
			}
			mu.Lock()
			uploaded = append(uploaded, string(raw))
			mu.Unlock()
			writeJSON(t, w, map[string]any{"ok": true, "accepted": true})
		case "/api/client/configs/pull":
			writeJSON(t, w, map[string]any{
				"ok": true, "config_revision": "rev-1", "forwards": []any{},
				"client": map[string]any{
					"id": 1,
					"debug_collection": map[string]any{
						"uuid":   "aabbccdd-5566-4777-8888-99aabbccddee",
						"status": "active",
					},
				},
			})
		default:
			t.Fatalf("unexpected path %s", r.URL.Path)
		}
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	a := newTestAgent(t, cfg)
	a.panel = newPanelClient(cfg, SecretState{AgentToken: "tok"}, "dev-1", "router")
	a.log.Error("lease", "relay 198.51.100.77 refused token=topsecret")

	res, err := a.uploadLogsNow(context.Background())
	if err != nil {
		t.Fatalf("uploadLogsNow: %v", err)
	}
	if res["reference_code"] != "11223344" {
		t.Fatalf("reference_code = %v, want 11223344", res["reference_code"])
	}
	mu.Lock()
	if started != 1 || len(uploaded) == 0 {
		t.Fatalf("expected 1 start + >=1 chunk, got started=%d chunks=%d", started, len(uploaded))
	}
	joined := strings.Join(uploaded, "")
	mu.Unlock()
	if strings.Contains(joined, "198.51.100.77") || strings.Contains(joined, "topsecret") {
		t.Fatalf("uploaded bundle leaked sensitive data: %s", joined)
	}

	// Pull-triggered passive upload stores debugUUID and clears it after submitDebugLogs.
	if err := a.pullOnce(context.Background()); err != nil {
		t.Fatalf("pullOnce: %v", err)
	}
	a.mu.Lock()
	pullUUID := a.debugUUID
	a.mu.Unlock()
	if pullUUID != "aabbccdd-5566-4777-8888-99aabbccddee" {
		t.Fatalf("debugUUID = %q", pullUUID)
	}
	a.submitDebugLogs(context.Background(), pullUUID)
	a.mu.Lock()
	doneUUID := a.debugDoneUUID
	remUUID := a.debugUUID
	a.mu.Unlock()
	if doneUUID != pullUUID || remUUID != "" {
		t.Fatalf("expected debugUUID cleared and debugDoneUUID recorded, got rem=%q done=%q", remUUID, doneUUID)
	}
}
