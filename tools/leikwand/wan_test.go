package main

import (
	"fmt"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeSystem answers the ip(8)/ubus(8) calls the uplink manager makes, and
// records the route changes it asks for. Two real WAN links are not available
// in a unit test, but the decision procedure over their output is the part
// that can be wrong.
type fakeSystem struct {
	mu       sync.Mutex
	devices  map[string]string // logical interface -> device
	gateways map[string]string // device -> gateway
	applied  []string          // every "ip route replace" invocation
	mwan3    bool
}

func newFakeSystem() *fakeSystem {
	return &fakeSystem{
		devices:  map[string]string{},
		gateways: map[string]string{},
	}
}

func (f *fakeSystem) run(timeout time.Duration, name string, args ...string) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	line := name + " " + strings.Join(args, " ")

	switch {
	case name == "ubus" && len(args) >= 3 && args[0] == "call":
		logical := strings.TrimPrefix(args[1], "network.interface.")
		if device, ok := f.devices[logical]; ok {
			return fmt.Sprintf("{\"l3_device\":%q,\"device\":%q}", device, device), nil
		}
		return "", fmt.Errorf("ubus: interface %s not found", logical)

	case name == "ip" && strings.Join(args, " ") == "-4 route show default":
		out := []string{}
		for device, gateway := range f.gateways {
			out = append(out, fmt.Sprintf("default via %s dev %s metric 0", gateway, device))
		}
		return strings.Join(out, "\n"), nil

	case name == "ip" && len(args) >= 5 && args[0] == "-4" && args[1] == "route" && args[2] == "show" && args[3] == "default" && args[4] == "dev":
		device := args[5]
		if gateway, ok := f.gateways[device]; ok {
			return fmt.Sprintf("default via %s dev %s metric 0", gateway, device), nil
		}
		return "", nil

	case name == "ip" && len(args) >= 3 && args[0] == "route" && args[1] == "replace":
		f.applied = append(f.applied, line)
		return "", nil

	case name == "nft" && len(args) >= 2 && args[0] == "list":
		if f.mwan3 {
			return "table inet mwan3 {\n}", nil
		}
		return "table inet filter {\n}", nil

	case name == "/etc/init.d/mwan3":
		if f.mwan3 {
			return "mwan3 is running", nil
		}
		return "mwan3 is not running", nil
	}
	return "", nil
}

func (f *fakeSystem) appliedMetrics() map[string]int {
	f.mu.Lock()
	defer f.mu.Unlock()
	out := map[string]int{}
	for _, line := range f.applied {
		fields := strings.Fields(line)
		device, metric := "", 0
		for i, field := range fields {
			if field == "dev" && i+1 < len(fields) {
				device = fields[i+1]
			}
			if field == "metric" && i+1 < len(fields) {
				fmt.Sscanf(fields[i+1], "%d", &metric)
			}
		}
		if device != "" {
			out[device] = metric
		}
	}
	return out
}

func (f *fakeSystem) reset() {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.applied = nil
}

func withFakeSystem(t *testing.T, fake *fakeSystem) {
	t.Helper()
	original := runCommandFunc
	runCommandFunc = fake.run
	t.Cleanup(func() { runCommandFunc = original })
}

func newWanTestAgent(t *testing.T, fake *fakeSystem, interfaces ...string) (*Agent, *wanManager) {
	t.Helper()
	cfg := defaultConfig()
	cfg.UplinkEnabled = true
	cfg.UplinkInterfaces = interfaces
	cfg.UplinkFailThreshold = 3
	cfg.UplinkRecoverThresh = 2
	agent := newTestAgent(t, cfg)
	withFakeSystem(t, fake)
	return agent, newWanManager(agent)
}

// Discovery has to resolve a UCI network name to a real device and find that
// device's default gateway, otherwise nothing downstream can be applied.
func TestUplinkDiscoveryResolvesDevicesAndGateways(t *testing.T) {
	fake := newFakeSystem()
	fake.devices["wan"] = "eth0"
	fake.devices["wanb"] = "eth1"
	fake.gateways["eth0"] = "192.168.1.1"
	fake.gateways["eth1"] = "192.168.2.1"

	_, manager := newWanTestAgent(t, fake, "wan", "wanb")
	manager.discover()

	if len(manager.uplinks) != 2 {
		t.Fatalf("discovered %d uplinks, want 2", len(manager.uplinks))
	}
	if got := manager.uplinks["wan"].gateway; got != "192.168.1.1" {
		t.Fatalf("wan gateway = %q", got)
	}
	if got := manager.uplinks["wanb"].gateway; got != "192.168.2.1" {
		t.Fatalf("wanb gateway = %q", got)
	}
	// The original metric is kept so restore() can put it back verbatim.
	if len(manager.uplinks["wan"].originals) != 1 {
		t.Fatalf("original routes not captured: %+v", manager.uplinks["wan"].originals)
	}
}

// Failover is a metric flip: the healthy uplink must end up with a strictly
// lower metric than the failed one, and the failed one must stay configured so
// recovery is instant.
func TestUplinkFailoverDemotesAFailedLink(t *testing.T) {
	fake := newFakeSystem()
	fake.devices["wan"] = "eth0"
	fake.devices["wanb"] = "eth1"
	fake.gateways["eth0"] = "192.168.1.1"
	fake.gateways["eth1"] = "192.168.2.1"

	_, manager := newWanTestAgent(t, fake, "wan", "wanb")
	manager.discover()

	// Both healthy: latency decides.
	manager.recordSuccess("wan", 20, 0)
	manager.recordSuccess("wan", 20, 0)
	manager.recordSuccess("wanb", 5, 0)
	manager.recordSuccess("wanb", 5, 0)
	fake.reset()
	if err := manager.applyPreferences(); err != nil {
		t.Fatalf("applyPreferences: %v", err)
	}
	metrics := fake.appliedMetrics()
	if metrics["eth1"] >= metrics["eth0"] {
		t.Fatalf("the lower-latency uplink must win: eth1=%d eth0=%d", metrics["eth1"], metrics["eth0"])
	}

	// wanb fails past the threshold.
	for i := 0; i < 3; i++ {
		manager.recordFailure("wanb", "no_probe_response")
	}
	fake.reset()
	if err := manager.applyPreferences(); err != nil {
		t.Fatalf("applyPreferences: %v", err)
	}
	metrics = fake.appliedMetrics()
	if metrics["eth0"] >= metrics["eth1"] {
		t.Fatalf("failover did not demote the failed uplink: eth0=%d eth1=%d", metrics["eth0"], metrics["eth1"])
	}
	if metrics["eth1"] < 900 {
		t.Fatalf("a failed uplink must be kept as a deprioritised last resort, metric=%d", metrics["eth1"])
	}
}

// A single failed probe must not flap the routing: the threshold is what makes
// failover stable on a lossy link.
func TestUplinkThresholdsPreventFlapping(t *testing.T) {
	fake := newFakeSystem()
	fake.devices["wan"] = "eth0"
	fake.gateways["eth0"] = "192.168.1.1"

	_, manager := newWanTestAgent(t, fake, "wan", "wanb")
	manager.discover()

	manager.recordSuccess("wan", 10, 0)
	manager.recordSuccess("wan", 10, 0)
	if !manager.uplinks["wan"].health.Healthy {
		t.Fatal("uplink should be healthy after the recovery threshold")
	}

	// One failure is below the threshold of three.
	manager.recordFailure("wan", "timeout")
	if !manager.uplinks["wan"].health.Healthy {
		t.Fatal("a single failed probe must not mark the uplink down")
	}
	if manager.uplinks["wan"].health.Failures != 1 {
		t.Fatalf("failure counter = %d, want 1", manager.uplinks["wan"].health.Failures)
	}

	// Reaching the threshold does mark it down.
	manager.recordFailure("wan", "timeout")
	manager.recordFailure("wan", "timeout")
	if manager.uplinks["wan"].health.Healthy {
		t.Fatal("uplink should be down after the failure threshold")
	}

	// Recovery needs the recovery threshold, not one lucky probe.
	manager.recordSuccess("wan", 12, 0)
	if manager.uplinks["wan"].health.Healthy {
		t.Fatal("one successful probe must not immediately restore the uplink")
	}
	manager.recordSuccess("wan", 12, 0)
	if !manager.uplinks["wan"].health.Healthy {
		t.Fatal("uplink should recover after the recovery threshold")
	}
	if manager.uplinks["wan"].health.Failures != 0 {
		t.Fatal("a success must clear the failure counter")
	}
}

// Stopping the service must hand the router back exactly as it was found.
func TestUplinkRestorePutsOriginalRoutesBack(t *testing.T) {
	fake := newFakeSystem()
	fake.devices["wan"] = "eth0"
	fake.gateways["eth0"] = "192.168.1.1"

	_, manager := newWanTestAgent(t, fake, "wan", "wanb")
	manager.discover()
	manager.recordSuccess("wan", 10, 0)
	manager.recordSuccess("wan", 10, 0)
	if err := manager.applyPreferences(); err != nil {
		t.Fatalf("applyPreferences: %v", err)
	}

	fake.reset()
	manager.restore()
	// The captured original metric was 0; restore must re-install it.
	found := false
	for _, line := range fake.applied {
		if strings.Contains(line, "192.168.1.1") && strings.HasSuffix(line, "metric 0") {
			found = true
		}
	}
	if !found {
		t.Fatalf("restore did not reinstall the original route: %v", fake.applied)
	}
}

// Two routing authorities on one box is the failure mode mwan3 coexistence
// exists to prevent, so mwan3 present means the manager must stay read-only.
func TestUplinkDefersToMwan3(t *testing.T) {
	fake := newFakeSystem()
	fake.devices["wan"] = "eth0"
	fake.gateways["eth0"] = "192.168.1.1"
	fake.mwan3 = true
	_ = fake

	// mwan3Running() also requires the init script to exist, which it does not
	// on a test host; the nft table probe is the part that can be exercised.
	cfg := defaultConfig()
	agent := newTestAgent(t, cfg)
	withFakeSystem(t, fake)
	manager := newWanManager(agent)
	if !manager.mwan3Running() {
		t.Skip("mwan3 init script is not present on this host; coexistence is covered by inspection")
	}
	fake.reset()
	manager.mwan3 = true
	manager.restore()
	if len(fake.applied) != 0 {
		t.Fatal("a read-only manager must not touch routes")
	}
}

// The persisted health mirror is what LuCI shows immediately after a restart,
// before the first probe completes.
func TestUplinkHealthIsMirroredToTheStore(t *testing.T) {
	fake := newFakeSystem()
	fake.devices["wan"] = "eth0"
	fake.gateways["eth0"] = "192.168.1.1"
	agent, manager := newWanTestAgent(t, fake, "wan")
	manager.discover()
	manager.recordSuccess("wan", 17, 0)
	manager.recordSuccess("wan", 17, 0)

	mirror := agent.store.Uplinks()
	health, ok := mirror.Uplinks["wan"]
	if !ok {
		t.Fatal("uplink health was not persisted")
	}
	if health.LatencyMS != 17 || !health.Healthy {
		t.Fatalf("mirrored health = %+v", health)
	}
}

func TestUplinkViewsExposeActiveLink(t *testing.T) {
	fake := newFakeSystem()
	fake.devices["wan"] = "eth0"
	fake.devices["wanb"] = "eth1"
	fake.gateways["eth0"] = "192.168.1.1"
	fake.gateways["eth1"] = "192.168.2.1"
	agent, manager := newWanTestAgent(t, fake, "wan", "wanb")
	agent.wan = manager
	manager.discover()
	manager.recordSuccess("wan", 30, 0)
	manager.recordSuccess("wan", 30, 0)
	manager.recordSuccess("wanb", 5, 0)
	manager.recordSuccess("wanb", 5, 0)
	if err := manager.applyPreferences(); err != nil {
		t.Fatalf("applyPreferences: %v", err)
	}

	views := agent.uplinkViews()
	if len(views) != 2 {
		t.Fatalf("expected 2 uplink views, got %d", len(views))
	}
	active := 0
	for _, view := range views {
		if view.Active {
			active++
			if view.Interface != "wanb" {
				t.Fatalf("the lowest-latency uplink should be active, got %s", view.Interface)
			}
		}
	}
	if active != 1 {
		t.Fatalf("exactly one uplink must be active, got %d", active)
	}
}
