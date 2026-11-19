package main

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeNativePanel serves config pulls listing the native carriers 180, 326
// and 330 under one grant generation, and mints a valid native lease for
// whatever the lease request asks.
type fakeNativePanel struct {
	t          *testing.T
	mu         sync.Mutex
	generation string
	pulls      int
	leases     map[int]int
}

var fakeNativeDirections = map[int]string{180: testDirection, 326: splitDirection, 330: pudongDirection}

func newFakeNativePanel(t *testing.T) (*fakeNativePanel, *httptest.Server) {
	t.Helper()
	panel := &fakeNativePanel{t: t, generation: strings.Repeat("a", 64), leases: map[int]int{}}
	server := httptest.NewServer(http.HandlerFunc(panel.serve))
	t.Cleanup(server.Close)
	return panel, server
}

func (p *fakeNativePanel) serve(w http.ResponseWriter, r *http.Request) {
	p.mu.Lock()
	defer p.mu.Unlock()
	now := time.Now()
	switch r.URL.Path {
	case "/api/client/configs/pull":
		p.pulls++
		forwards := []any{}
		all := testSplitForwards(now)
		for _, id := range []int{180, 326, 330} {
			forward := all[id]
			forward.UltraAccess.PolicyGeneration = p.generation
			forward.UltraAccess.SessionTicket = fmt.Sprintf("ticket-%d-%d", id, p.pulls)
			forwards = append(forwards, forward)
		}
		writeJSON(p.t, w, map[string]any{"ok": true, "config_revision": "rev-" + p.generation[:4],
			"forwards": forwards, "native_outbound": testSplitCapability()})
	case "/api/client/ultra/lease":
		var request LeaseRequest
		_ = json.NewDecoder(r.Body).Decode(&request)
		frames := []LeaseFrame{}
		for _, forward := range request.Forwards {
			p.leases[forward.ForwardID]++
			frames = append(frames, LeaseFrame{
				Protocol: nativeLeaseProtocol, Purpose: nativeLeasePurpose, ForwardID: forward.ForwardID,
				LeaseID: fmt.Sprintf("lease-%d-%d", forward.ForwardID, p.leases[forward.ForwardID]), PolicyGeneration: forward.PolicyGeneration,
				ExpiresAt: formatRFC3339(now.Add(4 * time.Minute)), Transport: "tcp+udp",
				Direction: fakeNativeDirections[forward.ForwardID], RouteScope: forward.RouteScope, RoutePolicy: forward.RoutePolicy,
				ScopeDigest: forward.TargetDigest, Endpoint: LeaseEndpoint{IP: "203.0.113.10", Port: 5438},
				Credential: LeaseCred{User: "user", Password: "password"}, DirectIPCidrs: []string{"203.0.113.10/32"},
			})
		}
		writeJSON(p.t, w, map[string]any{"ok": true, "leases": frames})
	default:
		http.NotFound(w, r)
	}
}

func (p *fakeNativePanel) leaseCount(id int) int {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.leases[id]
}

func (p *fakeNativePanel) setGeneration(generation string) {
	p.mu.Lock()
	p.generation = generation
	p.mu.Unlock()
}

// slotHarness is an agent whose native relays (and, in gateway mode,
// sing-box) are already running with valid leases: each relay is a live
// child process with a fake ultra-client control socket that counts the
// lease batches it receives.
type slotHarness struct {
	t       *testing.T
	agent   *Agent
	manager *gatewayManager
	panel   *fakeNativePanel
	relays  map[int]*runtimeProcess // by carrier
	batches map[int]func() []int    // by carrier
	singbox *runtimeProcess
}

func newSlotHarness(t *testing.T, cfg Config) *slotHarness {
	t.Helper()
	original := gatewayStopGrace
	gatewayStopGrace = 200 * time.Millisecond
	t.Cleanup(func() { gatewayStopGrace = original })
	t.Setenv("LEIKWAND_FAKE_SINGBOX", "1")
	withFakeIP(t)

	panel, server := newFakeNativePanel(t)
	cfg.PanelURL = server.URL
	cfg.FallbackURL = ""
	cfg.RuntimeBin = "/nonexistent/ultra-client"
	cfg.GatewaySingBoxBin = os.Args[0]
	cfg.GatewayRuleSetDir = t.TempDir()
	agent := newTestAgent(t, cfg)
	agent.panel.setToken("token", 3)
	if err := agent.freshPull(context.Background()); err != nil {
		t.Fatalf("first pull: %v", err)
	}
	manager := newGatewayManager(agent)
	agent.gateway = manager
	t.Cleanup(func() {
		manager.mu.Lock()
		singbox := manager.singbox
		manager.mu.Unlock()
		if singbox != nil && singbox.cmd != nil && singbox.cmd.Process != nil {
			_ = singbox.cmd.Process.Kill()
		}
	})

	plan, err := agent.gatewayPlan()
	if err != nil {
		t.Fatalf("plan: %v", err)
	}
	h := &slotHarness{t: t, agent: agent, manager: manager, panel: panel, relays: map[int]*runtimeProcess{}, batches: map[int]func() []int{}}
	now := time.Now()
	install := func(slot *relaySlot, carrier carrierPlan) {
		dir, err := os.MkdirTemp("", "lkw")
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() { os.RemoveAll(dir) })
		control := filepath.Join(dir, "c.sock")
		h.batches[carrier.Carrier.ForwardID] = serveFakeControl(t, control)
		relay := startTestChild(t)
		relay.native = true
		relay.forwardID = carrier.Carrier.ForwardID
		relay.paths = runtimePaths{dir: dir, controlSocket: control, statusPath: filepath.Join(dir, "status.json")}
		slot.relay = relay
		slot.relayKey = carrier.key()
		slot.plan = carrier
		slot.leaseExpiry = now.Add(4 * time.Minute)
		slot.renewNotBefore = now.Add(3 * time.Minute)
		slot.candidates = 1
		h.relays[carrier.Carrier.ForwardID] = relay
	}
	manager.mode = "relay"
	if cfg.gatewayActive() {
		manager.mode = "gateway"
	}
	manager.plan = plan
	install(&manager.primary, plan.carrierPlan)
	for _, split := range plan.activeSplits() {
		install(manager.splitSlot(split.Index), *split.Plan)
	}
	if manager.mode == "gateway" {
		encoded, source, err := renderSingBox(agent.config(), plan)
		if err != nil {
			t.Fatal(err)
		}
		h.singbox = startTestChild(t)
		manager.singbox = h.singbox
		manager.singboxGen = sha256Hex(string(encoded))
		manager.ruleSetSource = source
		manager.activeSplits = len(plan.activeSplits())
	}
	return h
}

func (h *slotHarness) reconcile() {
	h.t.Helper()
	h.manager.reconcile(context.Background())
}

// batchCounts is the number of lease batches each carrier's relay received.
func (h *slotHarness) batchCounts() map[int]int {
	out := map[int]int{}
	for id, batches := range h.batches {
		out[id] = len(batches())
	}
	return out
}

func (h *slotHarness) expectBatches(step string, want map[int]int) {
	h.t.Helper()
	got := h.batchCounts()
	for id, n := range want {
		if got[id] != n {
			h.t.Fatalf("%s: lease batches = %v, want %v", step, got, want)
		}
	}
}

// expectSingBoxUntouched: the same sing-box process with the same config.
func (h *slotHarness) expectSingBoxUntouched(step, generation string) {
	h.t.Helper()
	h.manager.mu.Lock()
	defer h.manager.mu.Unlock()
	if h.manager.singbox != h.singbox || !h.singbox.alive() || h.manager.singboxGen != generation {
		h.t.Fatalf("%s: sing-box was restarted or reconfigured", step)
	}
}

func (h *slotHarness) expectRelay(step string, slot *relaySlot, id int) {
	h.t.Helper()
	h.manager.mu.Lock()
	defer h.manager.mu.Unlock()
	if slot.relay != h.relays[id] || !h.relays[id].alive() {
		h.t.Fatalf("%s: the relay of carrier %d was restarted or stopped", step, id)
	}
}

func (h *slotHarness) slotView(role string) GatewaySlotView {
	h.t.Helper()
	for _, slot := range h.agent.gatewayView().Slots {
		if slot.Role == role {
			return slot
		}
	}
	h.t.Fatalf("no %s slot in the status view", role)
	return GatewaySlotView{}
}

func twoSplitGatewayConfig(t *testing.T) Config {
	cfg := splitGatewayConfig()
	cfg.GatewaySplitForwardID = 326
	cfg.GatewaySplitCIDRFile = writeTempFile(t, "shixp.txt", "163.53.52.0/22\n1.92.0.0/14\n")
	cfg.GatewaySplits = []SplitConfig{{Name: "pudong", ForwardID: 330, RoutePolicy: "auto",
		CIDRFile: writeTempFile(t, "pudong.txt", "114.111.176.0/22\n")}}
	return cfg
}

// A Panel refresh for the gateway primary re-leases it at once, in place: the
// new batch reaches the running relay over its control socket, the relay and
// sing-box keep running, and the other slots are not touched. A second
// refresh inside 20 s is deferred to the end of the interval, not dropped
// and not run early.
func TestPanelRefreshReleasesThePrimaryInPlace(t *testing.T) {
	h := newSlotHarness(t, twoSplitGatewayConfig(t))
	generation := h.manager.singboxGen

	h.reconcile()
	h.expectBatches("steady state", map[int]int{180: 0, 326: 0, 330: 0})
	h.expectSingBoxUntouched("steady state", generation)

	before := time.Now()
	if !h.manager.panelAction(UltraPolicyAction{ForwardID: 180, Action: "refresh", Reason: "test"}, before) {
		t.Fatal("the primary carrier is native; the gateway must take the action")
	}
	h.manager.mu.Lock()
	cleared := h.manager.primary.renewNotBefore.IsZero() && h.manager.primary.lastLeaseAt.IsZero()
	h.manager.mu.Unlock()
	if !cleared || !h.slotView("primary").RefreshPending {
		t.Fatal("a refresh must clear renewNotBefore/lastLeaseAt and show as pending")
	}
	h.reconcile()
	h.expectBatches("first refresh", map[int]int{180: 1, 326: 0, 330: 0})
	h.expectRelay("first refresh", &h.manager.primary, 180)
	h.expectRelay("first refresh", h.manager.splits[0], 326)
	h.expectSingBoxUntouched("first refresh", generation)
	if h.panel.leaseCount(180) != 1 || h.panel.leaseCount(326) != 0 {
		t.Fatalf("lease requests: 180=%d 326=%d", h.panel.leaseCount(180), h.panel.leaseCount(326))
	}
	h.manager.mu.Lock()
	forcedAt := h.manager.primary.lastForcedAt
	renewNotBefore := h.manager.primary.renewNotBefore
	h.manager.mu.Unlock()
	if forcedAt.Before(before) || renewNotBefore.IsZero() {
		t.Fatalf("forced re-lease bookkeeping: lastForcedAt=%v renewNotBefore=%v", forcedAt, renewNotBefore)
	}
	if view := h.slotView("primary"); view.State != "running" || view.RefreshPending || view.LeaseExpiry == "" {
		t.Fatalf("primary view after refresh = %+v", view)
	}

	// A second refresh inside the interval is deferred to its end.
	now := time.Now()
	if at := h.manager.requestForcedRelease(&h.manager.primary, now); !at.Equal(forcedAt.Add(gatewayForcedReleaseInterval)) {
		t.Fatalf("second refresh runs at %v, want %v", at, forcedAt.Add(gatewayForcedReleaseInterval))
	}
	h.manager.panelAction(UltraPolicyAction{ForwardID: 180, Action: "renew"}, now) // coalesces
	h.reconcile()
	h.expectBatches("deferred refresh", map[int]int{180: 1})
	if !h.slotView("primary").RefreshPending {
		t.Fatal("the deferred refresh must stay pending")
	}
	if h.manager.leaseDue(&h.manager.primary, now, h.agent.config()) ||
		!h.manager.leaseDue(&h.manager.primary, forcedAt.Add(gatewayForcedReleaseInterval), h.agent.config()) {
		t.Fatal("the deferred refresh must come due exactly at the end of the interval")
	}
	// Twenty seconds later (simulated) it runs, still in place.
	h.manager.mu.Lock()
	h.manager.primary.forceRenewAt = time.Now().Add(-time.Millisecond)
	h.manager.mu.Unlock()
	h.reconcile()
	h.expectBatches("deferred refresh due", map[int]int{180: 2, 326: 0, 330: 0})
	h.expectRelay("deferred refresh due", &h.manager.primary, 180)
	h.expectSingBoxUntouched("deferred refresh due", generation)
}

// A refresh for a split carrier re-leases only that split's relay.
func TestPanelRefreshReleasesOnlyThatSplit(t *testing.T) {
	h := newSlotHarness(t, twoSplitGatewayConfig(t))
	generation := h.manager.singboxGen
	if !h.manager.panelAction(UltraPolicyAction{ForwardID: 330, Action: "refresh"}, time.Now()) {
		t.Fatal("a split carrier is native; the gateway must take the action")
	}
	h.reconcile()
	h.expectBatches("split refresh", map[int]int{180: 0, 326: 0, 330: 1})
	h.expectRelay("split refresh", h.manager.splits[1], 330)
	h.expectRelay("split refresh", &h.manager.primary, 180)
	h.expectSingBoxUntouched("split refresh", generation)
	if h.panel.leaseCount(330) != 1 || h.panel.leaseCount(180) != 0 {
		t.Fatal("only the split's carrier may be re-leased")
	}
}

// stop/disable/revoke for a split stops that split's relay alone; its rule
// leaves sing-box (one restart) so its list falls back to the primary path,
// and it stays stopped until the Panel issues a new grant generation.
func TestPanelStopStopsOnlyThatSplit(t *testing.T) {
	h := newSlotHarness(t, twoSplitGatewayConfig(t))
	generation := h.manager.singboxGen
	if !h.manager.panelAction(UltraPolicyAction{ForwardID: 326, Action: "disable", Reason: "test"}, time.Now()) {
		t.Fatal("the gateway must take the action")
	}
	h.manager.mu.Lock()
	stopped := h.manager.splits[0].relay == nil && !h.relays[326].alive()
	h.manager.mu.Unlock()
	if !stopped {
		t.Fatal("disable must stop the split relay immediately")
	}
	h.expectRelay("split stop", &h.manager.primary, 180)
	h.expectRelay("split stop", h.manager.splits[1], 330)

	h.reconcile()
	h.manager.mu.Lock()
	restarted := h.manager.splits[0].relay != nil
	newGeneration := h.manager.singboxGen
	singboxUp := h.manager.singbox != nil && h.manager.singbox.alive()
	activeSplits := h.manager.activeSplits
	h.manager.mu.Unlock()
	if restarted || h.panel.leaseCount(326) != 0 {
		t.Fatal("a panel-stopped split must not be re-leased")
	}
	if newGeneration == generation || !singboxUp || activeSplits != 1 {
		t.Fatalf("sing-box must drop the stopped split's rule (gen changed=%v up=%v splits=%d)", newGeneration != generation, singboxUp, activeSplits)
	}
	if view := h.slotView("split"); view.State != "panel_stopped" || !view.PanelStopped {
		t.Fatalf("split view = %+v", view)
	}
	if view := h.slotView("split-2"); view.State != "running" {
		t.Fatalf("split-2 view = %+v", view)
	}
	if view := h.slotView("primary"); view.State != "running" {
		t.Fatalf("primary view = %+v", view)
	}
	h.reconcile()
	if h.manager.carrierPanelStopped(326) != true || h.panel.leaseCount(326) != 0 {
		t.Fatal("the stop must stick across reconciles under the same generation")
	}

	// A new grant generation lifts the stop and the split comes back.
	h.panel.setGeneration(strings.Repeat("b", 64))
	if err := h.agent.freshPull(context.Background()); err != nil {
		t.Fatal(err)
	}
	h.manager.clearCooldowns()
	h.reconcile()
	if h.manager.carrierPanelStopped(326) {
		t.Fatal("a new grant generation must lift the panel stop")
	}
	if h.panel.leaseCount(326) != 1 {
		t.Fatalf("the lifted split must be re-leased (lease requests %d)", h.panel.leaseCount(326))
	}
	h.manager.mu.Lock()
	activeSplits = h.manager.activeSplits
	h.manager.mu.Unlock()
	if activeSplits != 2 {
		t.Fatalf("the lifted split's rule must return (active splits %d)", activeSplits)
	}
}

// Stopping the gateway primary takes the LAN capture down with it (sing-box
// would black-hole the LAN without the relay) and keeps the whole gateway
// stopped until a new generation.
func TestPanelStopOfThePrimaryStopsTheCapture(t *testing.T) {
	h := newSlotHarness(t, twoSplitGatewayConfig(t))
	h.manager.panelAction(UltraPolicyAction{ForwardID: 180, Action: "revoke"}, time.Now())
	h.manager.mu.Lock()
	primaryDown := h.manager.primary.relay == nil
	singboxDown := h.manager.singbox == nil
	h.manager.mu.Unlock()
	if !primaryDown || !singboxDown {
		t.Fatalf("revoke: primary down=%v sing-box down=%v", primaryDown, singboxDown)
	}
	h.reconcile()
	h.manager.mu.Lock()
	anyRelay := h.manager.primary.relay != nil
	for _, slot := range h.manager.splits {
		anyRelay = anyRelay || slot.relay != nil
	}
	lastErr := h.manager.lastErr
	h.manager.mu.Unlock()
	if anyRelay || h.panel.leaseCount(180) != 0 || !strings.Contains(lastErr, "stopped by the panel") {
		t.Fatalf("after reconcile: relays=%v leases=%d err=%q", anyRelay, h.panel.leaseCount(180), lastErr)
	}
	if view := h.slotView("primary"); view.State != "panel_stopped" {
		t.Fatalf("primary view = %+v", view)
	}
}

// The router edition (no mode=gateway) has one relay for the native carrier;
// refresh and stop act on it the same way, without any sing-box.
func TestPanelActionsInRelayMode(t *testing.T) {
	h := newSlotHarness(t, defaultConfig())
	if h.manager.mode != "relay" || h.manager.singbox != nil {
		t.Fatal("the router edition runs no sing-box")
	}
	h.reconcile()
	h.expectBatches("steady state", map[int]int{180: 0})

	// Through the heartbeat entry point.
	h.agent.applyPolicyAction(UltraPolicyAction{ForwardID: 180, Action: "refresh"})
	h.reconcile()
	h.expectBatches("refresh", map[int]int{180: 1})
	h.expectRelay("refresh", &h.manager.primary, 180)
	if h.manager.singbox != nil {
		t.Fatal("refresh must not start sing-box in relay mode")
	}

	h.agent.applyPolicyAction(UltraPolicyAction{ForwardID: 180, Action: "stop"})
	if h.manager.primary.relay != nil || h.relays[180].alive() {
		t.Fatal("stop must stop the relay")
	}
	h.manager.clearCooldowns()
	h.reconcile()
	if h.manager.primary.relay != nil || h.panel.leaseCount(180) != 1 {
		t.Fatalf("the stopped relay must stay down (lease requests %d)", h.panel.leaseCount(180))
	}
	if view := h.slotView("primary"); view.State != "panel_stopped" {
		t.Fatalf("relay view = %+v", view)
	}

	// An ordinary forward is not the gateway's business.
	if h.manager.panelAction(UltraPolicyAction{ForwardID: 3, Action: "stop"}, time.Now()) {
		t.Fatal("a forward that is not a native carrier must go to the ordinary path")
	}
	var nilManager *gatewayManager
	if nilManager.panelAction(UltraPolicyAction{ForwardID: 180, Action: "stop"}, time.Now()) {
		t.Fatal("no gateway manager, no gateway action")
	}
}

// Status reports every slot: state, carrier, port, lease expiry, candidates
// and errors, under stable JSON names for ctl and LuCI.
func TestGatewayStatusReportsSlots(t *testing.T) {
	cfg := twoSplitGatewayConfig(t)
	cfg.GatewaySplits = append(cfg.GatewaySplits, SplitConfig{Name: "ghost", ForwardID: 999, CIDRFile: cfg.GatewaySplitCIDRFile})
	h := newSlotHarness(t, cfg)
	h.reconcile()
	view := h.agent.gatewayView()
	if view.Mode != "gateway" || len(view.Slots) != 4 || len(view.Splits) != 3 || view.Split == nil || view.Split.CarrierID != 326 {
		t.Fatalf("view = %+v", view)
	}
	want := []struct {
		role, state string
		carrier     int
		port        int
	}{
		{"primary", "running", 180, 2080},
		{"split", "running", 326, 2081},
		{"split-2", "running", 330, 2082},
		{"split-3", "off", 999, 2083},
	}
	for i, w := range want {
		slot := view.Slots[i]
		if slot.Role != w.role || slot.State != w.state || slot.CarrierID != w.carrier || slot.SocksPort != w.port {
			t.Fatalf("slot %d = %+v, want %+v", i, slot, w)
		}
		if w.state == "running" && (slot.LeaseExpiry == "" || slot.Candidates != 1 || !slot.RelayRunning) {
			t.Fatalf("running slot %d = %+v", i, slot)
		}
	}
	if !strings.Contains(view.Slots[3].LastError, "not a native outbound line") || view.Splits[2].Off == "" {
		t.Fatalf("off split must say why: %+v / %+v", view.Slots[3], view.Splits[2])
	}
	if !view.Splits[0].Active || !view.Splits[1].Active || view.Splits[2].Active {
		t.Fatalf("split activity = %v %v %v", view.Splits[0].Active, view.Splits[1].Active, view.Splits[2].Active)
	}
	raw, _ := json.Marshal(view.Slots[1])
	for _, key := range []string{`"state":"running"`, `"carrier_forward_id":326`, `"lease_expires_at":`, `"candidates":1`, `"socks_port":2081`} {
		if !strings.Contains(string(raw), key) {
			t.Fatalf("slot JSON %s lacks %s", raw, key)
		}
	}

	// ctl status (what the LuCI rpcd passes through) and the read-only ctl
	// gateway command carry the same slots, without the control secret.
	ctl := &ctlServer{agent: h.agent, secret: "s3cret"}
	for _, cmd := range []string{"status", "gateway"} {
		reply := ctl.dispatch(CtlRequest{Cmd: cmd})
		if !reply.OK {
			t.Fatalf("ctl %s = %+v", cmd, reply)
		}
		encoded, _ := json.Marshal(reply.Data)
		var decoded struct {
			Gateway *struct {
				Slots []GatewaySlotView `json:"slots"`
			} `json:"gateway"`
			Slots []GatewaySlotView `json:"slots"`
		}
		if err := json.Unmarshal(encoded, &decoded); err != nil {
			t.Fatal(err)
		}
		slots := decoded.Slots
		if decoded.Gateway != nil {
			slots = decoded.Gateway.Slots
		}
		if len(slots) != 4 || slots[0].Role != "primary" || slots[2].SocksPort != 2082 || slots[3].State != "off" {
			t.Fatalf("ctl %s slots = %+v", cmd, slots)
		}
	}
}

// Every relay's traffic is billed as Ultra usage of its own carrier.
func TestPerSlotUsageIsReported(t *testing.T) {
	h := newSlotHarness(t, twoSplitGatewayConfig(t))
	for id, bytes := range map[int]int64{180: 1000, 326: 2000, 330: 3000} {
		raw, _ := json.Marshal(RuntimeStatus{ForwardID: id, BytesIn: bytes, BytesOut: bytes / 10, PolicyGeneration: strings.Repeat("a", 64)})
		if err := os.WriteFile(h.relays[id].paths.statusPath, raw, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	h.agent.collectUsage()
	got := map[int]int64{}
	for _, entry := range h.agent.store.Usage().PendingUltra {
		got[entry.ForwardID] += entry.BytesIn
	}
	if got[180] != 1000 || got[326] != 2000 || got[330] != 3000 || len(got) != 3 {
		t.Fatalf("usage by carrier = %v", got)
	}
}

// A plan change restarts the relays before the new plan is stored, so the
// last stretch of usage is billed to the carrier that carried it.
func TestPlanChangeBillsTheOldCarrier(t *testing.T) {
	h := newSlotHarness(t, defaultConfig())
	raw, _ := json.Marshal(RuntimeStatus{ForwardID: 180, BytesIn: 700, BytesOut: 70, PolicyGeneration: strings.Repeat("a", 64)})
	if err := os.WriteFile(h.relays[180].paths.statusPath, raw, 0o600); err != nil {
		t.Fatal(err)
	}
	h.agent.mu.Lock()
	h.agent.cfg.GatewayForwardID = 326
	h.agent.mu.Unlock()
	h.reconcile()
	pending := h.agent.store.Usage().PendingUltra
	if len(pending) != 1 || pending[0].ForwardID != 180 || pending[0].BytesIn != 700 {
		t.Fatalf("usage = %+v, want the final 700 bytes on carrier 180", pending)
	}
}
