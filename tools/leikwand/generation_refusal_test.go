package main

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// The Panel rotates a forward's policy generation whenever the line material
// behind it rotates (its credential and relays change together). A router
// holding the previous generation keeps asking with a value the lease plane
// has already refused, and its next full pull is minutes away: the forward
// sits with no lease until then, which the user sees as the connection
// dropping and coming back. The desktop client records the refused generation
// and re-pulls at once; the router must do the same.
func TestGenerationRefusalForcesAFreshPull(t *testing.T) {
	refusal := &panelError{Status: http.StatusConflict, Code: codePolicyGenerationInvalid}
	if !generationRefused(refusal) {
		t.Fatal("policy_generation_invalid must count as a generation refusal")
	}
	for _, other := range []*panelError{
		{Status: http.StatusConflict, Code: "lease_scope_invalid"},
		{Status: http.StatusConflict, Code: "policy_expired"},
		{Status: http.StatusConflict, Code: "quota_exhausted"},
		{Status: http.StatusConflict, Code: codeLeaseKeyInvalid},
	} {
		if generationRefused(other) {
			t.Fatalf("%s must not force a fresh pull", other.Code)
		}
	}
	if generationRefused(nil) {
		t.Fatal("nil must not force a fresh pull")
	}
	if generationRefused(errors.New("transport down")) {
		t.Fatal("a transport error must not force a fresh pull")
	}
}

// A refused generation has to end the renewal hold, or the lease loop keeps
// skipping the forward until the old grant's renew window opens.
func TestRefusedGenerationEndsRenewalHold(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	state := &runtimeState{
		forward:        ForwardConfig{ForwardID: 7, UltraAccess: &UltraPolicy{PolicyGeneration: "abc"}},
		renewNotBefore: time.Now().Add(4 * time.Minute),
	}
	a.noteGenerationRefused(state, &panelError{Status: http.StatusConflict, Code: codePolicyGenerationInvalid})
	if !state.renewNotBefore.IsZero() {
		t.Fatal("a refused generation must not keep holding renewals back")
	}
	if !a.grantRefreshDue(time.Now()) {
		t.Fatal("a refused generation must make the next pull a full one")
	}
}

// The refusal is only recorded when it names the generation the forward is
// actually running; an unrelated 409 must not disturb the pull cadence.
func TestNoteGenerationRefusedIgnoresOtherErrors(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	state := &runtimeState{forward: ForwardConfig{ForwardID: 7, UltraAccess: &UltraPolicy{PolicyGeneration: "b"}}}
	a.noteGenerationRefused(state, errors.New("boom"))
	if len(a.refusedGenerations) != 0 {
		t.Fatalf("unexpected refusals recorded: %v", a.refusedGenerations)
	}
	if state.renewNotBefore.After(time.Now()) {
		t.Fatal("an unrelated error must not change the renewal hold")
	}
}

// The pull loop must actually reach the Panel: a full pull carries the new
// generation, which is what clears the refusal.
func TestGenerationRefusalTriggersAFullPull(t *testing.T) {
	var since []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		since = append(since, r.URL.Query().Get("since_revision"))
		writeJSON(t, w, map[string]any{
			"ok": true, "config_revision": "rev-2", "forwards": []any{},
			"client": map[string]any{"id": 1},
		})
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	cfg.PollIntervalSecs = 60
	a := newTestAgent(t, cfg)
	a.panel = newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")
	a.clientMeta = ClientMeta{ID: 1}
	a.configLoaded = true
	a.lastPullAt = time.Now()
	// The long poll reuses the revision the last full pull stored.
	a.store.UpdatePull(func(state *PullState) {
		state.ConfigRevision = "rev-2"
		state.LastPullAt = time.Now()
	})

	// While the grant is healthy the loop long-polls with the revision.
	if err := a.pullOnce(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	if got := since[len(since)-1]; got != "rev-2" {
		t.Fatalf("since_revision = %q, want rev-2", got)
	}

	// After a refusal the next pull must be full, not a long poll that can
	// answer 304 and leave the dead generation in place.
	a.noteGenerationRefused(&runtimeState{forward: ForwardConfig{ForwardID: 7, UltraAccess: &UltraPolicy{PolicyGeneration: "c"}}}, &panelError{Status: http.StatusConflict, Code: codePolicyGenerationInvalid})
	if err := a.pullOnce(context.Background()); err != nil {
		t.Fatalf("pull after refusal: %v", err)
	}
	if got := since[len(since)-1]; got != "" {
		t.Fatalf("since_revision after refusal = %q, want a full pull", got)
	}
}

// The Panel can only request diagnostics through the pull. The token was
// parsed into ClientMeta but never stored, so a console diagnostics request
// never ran on a router: the panel stayed at "requested" for ever.
func TestPullStoresDiagnosticsToken(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		writeJSON(t, w, map[string]any{
			"ok": true, "config_revision": "rev-1", "forwards": []any{},
			"client": map[string]any{"id": 1, "diag_request_token": "tok-abc"},
		})
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	a := newTestAgent(t, cfg)
	a.panel = newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")
	if err := a.pullOnce(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	a.mu.Lock()
	token := a.diagToken
	a.mu.Unlock()
	if token != "tok-abc" {
		t.Fatalf("diagToken = %q, want tok-abc", token)
	}
}
