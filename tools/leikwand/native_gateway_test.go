package main

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
	"time"
)

const testDirection = "CAN -> HKG"

func testCapability(unrestricted bool, scopes ...string) *NativeOutboundCapability {
	if len(scopes) == 0 {
		scopes = []string{"intl_only", "global"}
	}
	id := 180
	return &NativeOutboundCapability{
		Enabled: true, Protocol: "socks5", LeaseProtocol: nativeLeaseProtocol, Transport: "tcp+udp",
		ForwardIDs: []int{180}, DefaultForwardID: &id, RouteScopes: scopes, DefaultRouteScope: "global",
		UnrestrictedOK: unrestricted,
		Forwards: []NativeOutboundForward{{
			ForwardID: 180, Direction: testDirection, Transport: "tcp+udp",
			RouteScopes: scopes, DefaultRouteScope: "global", UnrestrictedOK: unrestricted,
		}},
	}
}

func testGrant(now time.Time) *UltraPolicy {
	return &UltraPolicy{
		Protocol: "leikwan-ultra-policy-v2", ForwardID: 180, PolicyGeneration: strings.Repeat("a", 64),
		SessionTicket: "ticket-180", ExpiresAt: formatRFC3339(now.Add(5 * time.Minute)),
		RenewAfter: formatRFC3339(now.Add(3 * time.Minute)), ServiceState: "active", Transport: "tcp+udp",
		QuotaRemainingBytes: 1 << 30, TargetDigest: targetDigest("127.0.0.1:41000"),
	}
}

func testCarrierForwards(now time.Time) map[int]ForwardConfig {
	return map[int]ForwardConfig{180: {
		ForwardID: 180, BackendMode: "ultra", ServiceStatus: "active", Transport: "tcp+udp",
		Target: "127.0.0.1:41000", NativeOutboundEnabled: true, NativeDirection: "can_hkg",
		UltraAccess: testGrant(now),
	}}
}

func testPlan(t *testing.T, cfg Config, capability *NativeOutboundCapability) gatewayPlan {
	t.Helper()
	plan, err := resolveGatewayPlan(cfg, capability, testCarrierForwards(time.Now()))
	if err != nil {
		t.Fatalf("plan: %v", err)
	}
	return plan
}

func TestGatewayPlanAutoFollowsTheLineDirection(t *testing.T) {
	cfg := defaultConfig()
	cases := []struct {
		scopes []string
		want   string
	}{
		{[]string{"intl_only", "global"}, "intl_only"},
		{[]string{"cn_only", "global"}, "cn_only"},
		{[]string{"global"}, "global"},
		{nil, "intl_only"},
	}
	for _, tc := range cases {
		if plan := testPlan(t, cfg, testCapability(false, tc.scopes...)); plan.Scope != tc.want {
			t.Fatalf("scopes %v: auto = %s, want %s", tc.scopes, plan.Scope, tc.want)
		}
	}
	cfg.GatewayRouteScope = "global"
	if plan := testPlan(t, cfg, testCapability(false)); plan.Scope != "global" || len(plan.Notes) != 0 {
		t.Fatalf("explicit global = %s %v", plan.Scope, plan.Notes)
	}
	cfg.GatewayRouteScope = "cn_only"
	if plan := testPlan(t, cfg, testCapability(false)); plan.Scope != "intl_only" || len(plan.Notes) != 1 {
		t.Fatalf("a scope the line does not offer must fall back with a note: %s %v", plan.Scope, plan.Notes)
	}
}

// "auto" uses the unrestricted full route exactly when the plan grants it;
// asking for it without the grant degrades visibly instead of failing.
func TestGatewayPlanRoutePolicy(t *testing.T) {
	cfg := defaultConfig()
	if plan := testPlan(t, cfg, testCapability(true)); plan.Policy != "unrestricted" {
		t.Fatalf("auto with grant = %s", plan.Policy)
	}
	if plan := testPlan(t, cfg, testCapability(false)); plan.Policy != "restricted" || len(plan.Notes) != 0 {
		t.Fatalf("auto without grant = %s %v", plan.Policy, plan.Notes)
	}
	cfg.GatewayRoutePolicy = "unrestricted"
	if plan := testPlan(t, cfg, testCapability(false)); plan.Policy != "restricted" || len(plan.Notes) != 1 {
		t.Fatalf("unrestricted without grant = %s %v", plan.Policy, plan.Notes)
	}
	cfg.GatewayRoutePolicy = "restricted"
	if plan := testPlan(t, cfg, testCapability(true)); plan.Policy != "restricted" {
		t.Fatalf("explicit restricted must stay restricted, got %s", plan.Policy)
	}
}

func TestGatewayPlanRefusesWhatThePanelDidNotGrant(t *testing.T) {
	cfg := defaultConfig()
	forwards := testCarrierForwards(time.Now())
	if _, err := resolveGatewayPlan(cfg, nil, forwards); !errors.Is(err, errNativeNotGranted) {
		t.Fatalf("no capability: %v", err)
	}
	wrong := testCapability(false)
	wrong.LeaseProtocol = "something-else"
	if _, err := resolveGatewayPlan(cfg, wrong, forwards); err == nil {
		t.Fatal("an unknown lease protocol must be refused")
	}
	cfg.GatewayForwardID = 999
	if _, err := resolveGatewayPlan(cfg, testCapability(false), forwards); err == nil {
		t.Fatal("an explicit carrier the Panel does not offer must be an error, not a silent switch")
	}
	cfg.GatewayForwardID = 0
	if _, err := resolveGatewayPlan(cfg, testCapability(false), map[int]ForwardConfig{}); err == nil {
		t.Fatal("a carrier missing from the forward list must be an error")
	}
}

// The request shape is the desktop's: purpose, the carrier's ticket and
// generation, a fresh nonce, and the v1 digest for restricted / v2 for
// unrestricted.
func TestNativeLeaseRequestMatchesTheDesktopContract(t *testing.T) {
	for _, policy := range allPolicies {
		cfg := defaultConfig()
		cfg.GatewayRoutePolicy = policy
		plan := testPlan(t, cfg, testCapability(true))
		request, err := buildNativeLeaseRequest("device-1", plan, plan.Carrier.UltraAccess, 4)
		if err != nil {
			t.Fatal(err)
		}
		item := request.Forwards[0]
		if item.Purpose != "native_outbound" || item.ForwardID != 180 || item.SessionTicket != "ticket-180" ||
			item.PolicyGeneration != strings.Repeat("a", 64) || item.Transport != "tcp+udp" ||
			item.RouteScope != "intl_only" || item.RoutePolicy != policy || item.SelectionMode != "auto" || item.MaxCandidates != 4 {
			t.Fatalf("%s: request = %+v", policy, item)
		}
		if len(item.Nonce) != 32 || !isLowerHexString(item.Nonce) {
			t.Fatalf("%s: nonce %q", policy, item.Nonce)
		}
		want := nativeScopeDigestV1(180, testDirection, "tcp+udp", "intl_only")
		if policy == "unrestricted" {
			want = nativeScopeDigestV2(180, testDirection, "tcp+udp", "intl_only", "unrestricted")
		}
		if item.TargetDigest != want {
			t.Fatalf("%s: digest = %s, want %s", policy, item.TargetDigest, want)
		}
		raw, _ := json.Marshal(request)
		if !strings.Contains(string(raw), "\"session_ticket\":\"ticket-180\"") {
			t.Fatalf("the Panel refuses a native lease without the session ticket: %s", raw)
		}
	}
}

func isLowerHexString(value string) bool {
	for _, c := range value {
		if (c < '0' || c > '9') && (c < 'a' || c > 'f') {
			return false
		}
	}
	return value != ""
}

func goodNativeLease(plan gatewayPlan, now time.Time) LeaseFrame {
	return LeaseFrame{
		Protocol: nativeLeaseProtocol, Purpose: nativeLeasePurpose, ForwardID: 180, LeaseID: "lease-1",
		PolicyGeneration: strings.Repeat("a", 64), ExpiresAt: formatRFC3339(now.Add(4 * time.Minute)),
		Transport: "tcp+udp", Direction: testDirection, RouteScope: plan.Scope, RoutePolicy: plan.Policy,
		ScopeDigest:   nativeExpectedDigest(180, testDirection, plan.Scope, plan.Policy),
		Endpoint:      LeaseEndpoint{IP: "203.0.113.10", Port: 5438},
		Credential:    LeaseCred{User: "user", Password: "password"},
		DirectIPCidrs: []string{"203.0.113.10/32", "198.51.100.0/24"},
	}
}

// The Panel answers with scope_digest; ultra-client validates target_digest.
// Passing the frame through unchanged left the runtime with an empty digest
// and every native lease was rejected.
func TestNativeLeaseFramesCarryTheScopeDigestAsTargetDigest(t *testing.T) {
	now := time.Now()
	plan := testPlan(t, defaultConfig(), testCapability(true))
	lease := goodNativeLease(plan, now)
	lease.SessionTicket = "should-not-reach-the-runtime"
	frames, err := validateNativeLeases([]LeaseFrame{lease}, plan, plan.Carrier.UltraAccess, now)
	if err != nil {
		t.Fatal(err)
	}
	frame := frames[0]
	if frame.TargetDigest != lease.ScopeDigest || frame.ScopeDigest != "" || frame.SessionTicket != "" || frame.DirectIPCidrs != nil {
		t.Fatalf("runtime frame = %+v", frame)
	}
	if got := leaseBypassCIDRs([]LeaseFrame{lease}); strings.Join(got, ",") != "198.51.100.0/24,203.0.113.10/32" {
		t.Fatalf("bypass set = %v", got)
	}
}

func TestNativeLeaseValidationRejectsEachMismatch(t *testing.T) {
	now := time.Now()
	plan := testPlan(t, defaultConfig(), testCapability(false))
	mutations := map[string]func(*LeaseFrame){
		"protocol":      func(l *LeaseFrame) { l.Protocol = "leikwan-ultra-policy-v2" },
		"purpose":       func(l *LeaseFrame) { l.Purpose = "ultra" },
		"forward":       func(l *LeaseFrame) { l.ForwardID = 181 },
		"generation":    func(l *LeaseFrame) { l.PolicyGeneration = strings.Repeat("b", 64) },
		"transport":     func(l *LeaseFrame) { l.Transport = "tcp" },
		"direction":     func(l *LeaseFrame) { l.Direction = "SHA -> HKG" },
		"scope":         func(l *LeaseFrame) { l.RouteScope = "global" },
		"policy":        func(l *LeaseFrame) { l.RoutePolicy = "unrestricted" },
		"digest":        func(l *LeaseFrame) { l.ScopeDigest = strings.Repeat("c", 64) },
		"expired":       func(l *LeaseFrame) { l.ExpiresAt = formatRFC3339(now.Add(-time.Second)) },
		"after_grant":   func(l *LeaseFrame) { l.ExpiresAt = formatRFC3339(now.Add(5*time.Minute + 10*time.Second)) },
		"endpoint":      func(l *LeaseFrame) { l.Endpoint.IP = "relay.example" },
		"credential":    func(l *LeaseFrame) { l.Credential.Password = "" },
		"candidate_key": func(l *LeaseFrame) { l.CandidateKeyHash = "short" },
	}
	for name, mutate := range mutations {
		good := goodNativeLease(plan, now)
		bad := goodNativeLease(plan, now)
		mutate(&bad)
		if _, err := validateNativeLeases([]LeaseFrame{good, bad}, plan, plan.Carrier.UltraAccess, now); err == nil {
			t.Fatalf("%s: a bad lease must reject the whole batch", name)
		}
	}
	if _, err := validateNativeLeases(nil, plan, plan.Carrier.UltraAccess, now); err == nil {
		t.Fatal("an empty batch must be rejected")
	}
}

// Native carriers never run on the ordinary path: the runtime contract says
// the client must not bind their listen_port or request an ordinary lease.
func TestNativeForwardsStayOffTheOrdinaryPath(t *testing.T) {
	agent := &Agent{cfg: defaultConfig(), log: newLogger("error")}
	ordinary := ForwardConfig{ForwardID: 1, BackendMode: "ultra", ServiceStatus: "active", ListenPort: 10001, Target: "example.com:443"}
	native := ForwardConfig{ForwardID: 2, BackendMode: "ultra", ServiceStatus: "active", ListenPort: 10002, Target: "127.0.0.1:41000", NativeOutboundEnabled: true}
	agent.applyForwards([]ForwardConfig{ordinary, native})
	if _, ok := agent.forwards[2]; ok {
		t.Fatal("a native carrier reached the ordinary forward map")
	}
	if _, ok := agent.forwards[1]; !ok {
		t.Fatal("the ordinary forward was dropped")
	}
	if _, ok := agent.nativeForwards[2]; !ok {
		t.Fatal("the native carrier is missing from the native map")
	}
}

// Grants are not part of the long-poll revision, so a 304 would let them
// expire; past renew_after the next pull must be a full one.
func TestGrantRefreshForcesAFullPull(t *testing.T) {
	now := time.Now()
	agent := &Agent{cfg: defaultConfig(), log: newLogger("error")}
	fresh := testGrant(now)
	agent.forwards = map[int]ForwardConfig{1: {ForwardID: 1, UltraAccess: fresh}}
	if agent.grantRefreshDue(now) {
		t.Fatal("a fresh grant must keep the long poll")
	}
	if !agent.grantRefreshDue(now.Add(3*time.Minute + time.Second)) {
		t.Fatal("past renew_after the pull must be full")
	}
	noRenew := testGrant(now)
	noRenew.RenewAfter = ""
	agent.forwards = map[int]ForwardConfig{1: {ForwardID: 1, UltraAccess: noRenew}}
	if agent.grantRefreshDue(now) || !agent.grantRefreshDue(now.Add(3*time.Minute+30*time.Second)) {
		t.Fatal("without renew_after the refresh starts two minutes before expiry")
	}
	agent.forwards = map[int]ForwardConfig{}
	agent.nativeForwards = testCarrierForwards(now.Add(-time.Hour))
	if agent.grantRefreshDue(now) {
		t.Fatal("native carriers are refreshed by the gateway, not by the long poll")
	}
}

type fakeIP struct {
	mu    sync.Mutex
	calls []string
	rules map[string]bool
}

func (f *fakeIP) run(_ time.Duration, name string, args ...string) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	line := name + " " + strings.Join(args, " ")
	f.calls = append(f.calls, line)
	if len(args) >= 4 && args[1] == "rule" && args[2] == "del" && args[3] == "pref" {
		// Flush: one rule per call, error when none are left.
		for key := range f.rules {
			if strings.HasPrefix(key, args[0]+" ") {
				delete(f.rules, key)
				return "", nil
			}
		}
		return "", errors.New("no such rule")
	}
	if len(args) >= 5 && args[1] == "rule" {
		key := args[0] + " " + args[4]
		if args[2] == "add" {
			f.rules[key] = true
		} else {
			delete(f.rules, key)
		}
	}
	return "", nil
}

func withFakeIP(t *testing.T) *fakeIP {
	t.Helper()
	fake := &fakeIP{rules: map[string]bool{}}
	original := runCommandFunc
	runCommandFunc = fake.run
	t.Cleanup(func() { runCommandFunc = original })
	return fake
}

func TestBypassRoutesLifecycle(t *testing.T) {
	fake := withFakeIP(t)
	fake.rules["-4 192.0.2.99/32"] = true // left behind by a crashed predecessor
	routes := newBypassRoutes()
	routes.set("gateway", []string{"203.0.113.10/32"})
	if err := routes.sync(); err != nil || len(fake.rules) != 1 {
		t.Fatalf("an inactive set must not touch the kernel: %v %v", err, fake.rules)
	}
	if err := routes.activate(); err != nil {
		t.Fatal(err)
	}
	if fake.rules["-4 192.0.2.99/32"] || !fake.rules["-4 203.0.113.10/32"] {
		t.Fatalf("activate must flush stale rules and install the set: %v", fake.rules)
	}
	for _, call := range fake.calls {
		if strings.Contains(call, " add ") && !strings.Contains(call, "lookup main pref 8970") {
			t.Fatalf("rule must point at main at the reserved preference: %s", call)
		}
	}
	// A rotation keeps the previous generation so its flows can drain.
	routes.set("gateway", []string{"198.51.100.7/32"})
	routes.set("forward:3", []string{"2001:db8::1/128"})
	_ = routes.sync()
	for _, want := range []string{"-4 203.0.113.10/32", "-4 198.51.100.7/32", "-6 2001:db8::1/128"} {
		if !fake.rules[want] {
			t.Fatalf("missing %s in %v", want, fake.rules)
		}
	}
	routes.set("gateway", []string{"198.51.100.8/32"})
	_ = routes.sync()
	if fake.rules["-4 203.0.113.10/32"] {
		t.Fatal("two generations back must be removed")
	}
	routes.deactivate()
	if len(fake.rules) != 0 {
		t.Fatalf("deactivate must remove every rule: %v", fake.rules)
	}
}

func TestBypassCIDRNormalization(t *testing.T) {
	cases := map[string]string{
		"203.0.113.10":       "203.0.113.10/32",
		"203.0.113.10/32":    "203.0.113.10/32",
		"198.51.100.77/24":   "198.51.100.0/24",
		"::ffff:203.0.113.1": "203.0.113.1/32",
		"2001:db8::1":        "2001:db8::1/128",
		"0.0.0.0/0":          "",
		"10.0.0.0/4":         "",
		"127.0.0.1":          "",
		"0.0.0.0":            "",
		"relay.example":      "",
	}
	for in, want := range cases {
		got, ok := normalizeBypassCIDR(in)
		if (want == "") == ok || got != want {
			t.Fatalf("normalizeBypassCIDR(%q) = %q,%v want %q", in, got, ok, want)
		}
	}
}

// The relay's bytes are billed as usage of the native carrier, including the
// last stretch before the relay stops.
func TestNativeRelayUsageIsReported(t *testing.T) {
	store, err := newStore(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	agent := &Agent{cfg: defaultConfig(), log: newLogger("error"), store: store}
	paths := gatewayRelayPaths(t.TempDir())
	if err := os.MkdirAll(paths.dir, 0o700); err != nil {
		t.Fatal(err)
	}
	writeStatus := func(in, out int64) {
		raw, _ := json.Marshal(RuntimeStatus{ForwardID: 180, BytesIn: in, BytesOut: out, PolicyGeneration: strings.Repeat("a", 64)})
		if err := os.WriteFile(paths.statusPath, raw, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	manager := &gatewayManager{agent: agent, plan: testPlan(t, defaultConfig(), testCapability(true))}
	manager.relay = &runtimeProcess{native: true, paths: paths}
	agent.gateway = manager

	writeStatus(1000, 200)
	agent.collectUsage()
	writeStatus(1500, 260)
	manager.stopRelay("test") // must bill the final 500/60 before forgetting the relay
	pending := store.Usage().PendingUltra
	if len(pending) != 2 || pending[0].ForwardID != 180 || pending[0].BytesIn != 1000 || pending[1].BytesIn != 500 || pending[1].BytesOut != 60 {
		t.Fatalf("relay usage = %+v", pending)
	}
	if manager.relayCounters != (counterPair{}) {
		t.Fatal("a new relay must count from zero")
	}
}

// Gateway mode carries the LAN over the native carrier alone. The client's
// ordinary forwards must not get a listener or a lease on the router: a
// runtime left over from forward mode is stopped, and none is started.
func TestGatewayModeDoesNotServeOrdinaryForwards(t *testing.T) {
	cfg := defaultConfig()
	cfg.Mode = "gateway"
	cfg.GatewayEnabled = true
	cfg.RuntimeBin = "/nonexistent/ultra-client"
	agent := newTestAgent(t, cfg)
	agent.panel.setToken("token", 3)

	ordinary := ForwardConfig{ForwardID: 256, BackendMode: "ultra", ListenPort: 10001, ClientMode: "race"}
	native := ForwardConfig{ForwardID: 325, BackendMode: "ultra", ListenPort: 44626, NativeOutboundEnabled: true}
	agent.applyForwards([]ForwardConfig{ordinary, native})
	agent.runtimes[256] = &runtimeState{proc: &runtimeProcess{forwardID: 256}, forward: ordinary}

	agent.reconcileNow(context.Background(), "test")
	if len(agent.runtimes) != 0 {
		t.Fatalf("gateway mode kept ordinary runtimes: %v", agent.runtimes)
	}
	if _, ok := agent.nativeForwards[325]; !ok {
		t.Fatal("the native carrier must stay available to the gateway")
	}

	// Hybrid serves both again: reconcile attempts the ordinary forward (and
	// fails here only because the test has no runtime binary).
	agent.mu.Lock()
	agent.cfg.Mode = "hybrid"
	agent.mu.Unlock()
	if !agent.config().forwardActive() {
		t.Fatal("hybrid must serve ordinary forwards")
	}
}

// A restarted agent inherits the previous process's revision on disk but holds
// no config in memory. Its first pull must be unconditional; sending the stale
// revision earns a 304 that leaves the forwards and the native capability empty
// for good (gateway mode has no ordinary grant to force a refresh later).
func TestFirstPullOfAProcessIgnoresThePersistedRevision(t *testing.T) {
	var sinces []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		since := r.URL.Query().Get("since_revision")
		sinces = append(sinces, since)
		if since == "rev-1" {
			w.WriteHeader(http.StatusNotModified)
			return
		}
		writeJSON(t, w, map[string]any{"ok": true, "config_revision": "rev-1", "forwards": []any{}})
	}))
	defer server.Close()

	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	cfg.FallbackURL = ""
	agent := newTestAgent(t, cfg)
	agent.panel = newPanelClient(agent.cfg, SecretState{AgentToken: "token"}, "dev-1", "router")
	agent.store.UpdatePull(func(state *PullState) {
		state.ConfigRevision = "rev-1"
		state.LastPullAt = time.Now()
	})

	if err := agent.pullOnce(context.Background()); err != nil {
		t.Fatalf("first pull: %v", err)
	}
	if err := agent.pullOnce(context.Background()); err != errNotModified {
		t.Fatalf("second pull = %v, want errNotModified", err)
	}
	if len(sinces) != 2 || sinces[0] != "" || sinces[1] != "rev-1" {
		t.Fatalf("since_revision sequence = %q, want [\"\" \"rev-1\"]", sinces)
	}
	if !agent.configLoaded {
		t.Fatal("a full pull must mark the config as loaded")
	}
}

// The Panel keeps serving a native grant until its renew_after and caps the
// lease at the grant's expiry. Renewing inside the renew lead but before
// renew_after re-mints the same expiry, so the gateway must wait for
// renew_after (it hot-looped every ~1.5s on the live Panel without this).
func TestNativeRenewalWaitsForGrantRenewAfter(t *testing.T) {
	now := time.Now()
	grant := testGrant(now)
	grant.ExpiresAt = formatRFC3339(now.Add(80 * time.Second))
	grant.RenewAfter = formatRFC3339(now.Add(50 * time.Second))
	leaseExpiry := now.Add(80 * time.Second)

	notBefore := nextLeaseRenewal(grant, leaseExpiry)
	if !notBefore.Equal(now.Add(50 * time.Second).Truncate(time.Second)) {
		t.Fatalf("notBefore = %v, want renew_after", notBefore)
	}

	cfg := defaultConfig() // lease_renew_lead 90s: the lease is "due" immediately
	manager := &gatewayManager{
		relay:          &runtimeProcess{},
		leaseExpiry:    leaseExpiry,
		renewNotBefore: notBefore,
	}
	if manager.leaseDue(now, cfg) {
		t.Fatal("a renewal before renew_after cannot extend the lease and must wait")
	}
	if !manager.leaseDue(now.Add(51*time.Second), cfg) {
		t.Fatal("past renew_after the lease must renew")
	}

	// renew_after at or past the lease expiry still leaves a safety margin.
	late := testGrant(now)
	late.RenewAfter = formatRFC3339(now.Add(5 * time.Minute))
	if got := nextLeaseRenewal(late, leaseExpiry); !got.Equal(leaseExpiry.Add(-10 * time.Second)) {
		t.Fatalf("late renew_after clipped to %v, want 10s before expiry", got)
	}
	if !nextLeaseRenewal(&UltraPolicy{}, leaseExpiry).IsZero() || !nextLeaseRenewal(nil, leaseExpiry).IsZero() {
		t.Fatal("no renew_after means no extra hold-back")
	}
}

// Ordinary forwards share the cap: inside the renew lead the lease loop holds
// back until renew_after, and a newer grant from the pull loop lifts the hold.
func TestOrdinaryLeaseRenewalHoldsUntilGrantRefresh(t *testing.T) {
	now := time.Now()
	agent := &Agent{cfg: defaultConfig(), log: newLogger("error")}
	used := testGrant(now)
	state := &runtimeState{
		forward:        ForwardConfig{ForwardID: 180, UltraAccess: used},
		policy:         used,
		leaseExpiry:    now.Add(60 * time.Second),
		renewNotBefore: now.Add(30 * time.Second),
	}
	if agent.leaseRefreshDue(state, now) {
		t.Fatal("inside the lead but before renew_after the lease must wait")
	}
	if !agent.leaseRefreshDue(state, now.Add(31*time.Second)) {
		t.Fatal("past the hold the lease must renew")
	}
	newer := testGrant(now.Add(time.Minute))
	state.forward.UltraAccess = newer
	if !agent.leaseRefreshDue(state, now) {
		t.Fatal("a newer grant can extend the lease and lifts the hold")
	}
}

// A Panel stop action must stick: a full pull keeps listing the forward under
// the same grant, and restarting it there would undo the Panel's command. A
// new grant generation (or the forward leaving and returning) re-enables it.
func TestPanelStopActionSticksUntilNewGeneration(t *testing.T) {
	cfg := defaultConfig()
	cfg.RuntimeBin = "/nonexistent/ultra-client"
	agent := newTestAgent(t, cfg)
	agent.panel.setToken("token", 3)
	now := time.Now()
	forward := ForwardConfig{ForwardID: 3, BackendMode: "ultra", ListenPort: 10001, ClientMode: "race", UltraAccess: testGrant(now)}
	agent.applyForwards([]ForwardConfig{forward})
	agent.runtimes[3] = &runtimeState{proc: &runtimeProcess{forwardID: 3}, forward: forward}

	agent.applyPolicyAction(UltraPolicyAction{ForwardID: 3, Action: "stop", Reason: "test"})
	if _, running := agent.runtimes[3]; running {
		t.Fatal("stop must remove the runtime")
	}
	agent.reconcileNow(context.Background(), "config_changed")
	if _, held := agent.panelStopped[3]; !held {
		t.Fatal("the same generation must stay stopped across a reconcile")
	}

	renewed := forward
	renewed.UltraAccess = testGrant(now)
	renewed.UltraAccess.PolicyGeneration = strings.Repeat("b", 64)
	agent.applyForwards([]ForwardConfig{renewed})
	agent.reconcileNow(context.Background(), "config_changed")
	if _, held := agent.panelStopped[3]; held {
		t.Fatal("a new grant generation re-enables the forward")
	}

	(&Agent{log: newLogger("error")}).applyPolicyAction(UltraPolicyAction{ForwardID: 9, Action: "stop"})
}
