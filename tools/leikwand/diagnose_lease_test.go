package main

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// The field failure (forward 354, 2026-11-04) passed every diagnostics check
// while each lease request failed: the runtime was up, the Panel reachable,
// but no lease was ever installed. The lease plane needs its own check.
func TestDiagnosticsReportsAForwardThatCannotLease(t *testing.T) {
	panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		writeJSON(t, w, map[string]any{"ok": true, "config_revision": "rev", "forwards": []any{}})
	}))
	defer panel.Close()
	cfg := defaultConfig()
	cfg.PanelURL = panel.URL
	cfg.FallbackURL = ""
	agent := newTestAgent(t, cfg)
	agent.panel = newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	failing := ForwardConfig{ForwardID: 354}
	agent.runtimes[354] = &runtimeState{
		proc:         &runtimeProcess{forwardID: 354},
		forward:      failing,
		lastLeaseErr: `Post "https://v3.leikwanhost.com/api/client/ultra/lease": context deadline exceeded`,
	}
	leased := ForwardConfig{ForwardID: 7}
	agent.runtimes[7] = &runtimeState{
		proc:        &runtimeProcess{forwardID: 7},
		forward:     leased,
		leaseExpiry: time.Now().Add(10 * time.Minute),
		candidates:  3,
	}

	report := agent.diagnose()
	checks := map[string]DiagnosticCheck{}
	for _, check := range report.Checks {
		checks[check.Name] = check
	}

	bad, ok := checks["forward_354_lease"]
	if !ok {
		t.Fatalf("no lease check for forward 354: %+v", report.Checks)
	}
	if bad.OK {
		t.Fatal("a forward whose leases fail must fail its lease check")
	}
	if !strings.Contains(bad.Detail, "context deadline exceeded") {
		t.Fatalf("the check should carry the lease error, got %q", bad.Detail)
	}

	good := checks["forward_7_lease"]
	if !good.OK || !strings.Contains(good.Detail, "3 candidates") {
		t.Fatalf("a leased forward should pass with its candidates: %+v", good)
	}

	pending := ForwardConfig{ForwardID: 9}
	agent.runtimes[9] = &runtimeState{proc: &runtimeProcess{forwardID: 9}, forward: pending}
	for _, check := range agent.diagnose().Checks {
		if check.Name == "forward_9_lease" && (check.OK || check.Detail != "no lease yet") {
			t.Fatalf("a forward with no lease yet must not pass: %+v", check)
		}
	}
}
