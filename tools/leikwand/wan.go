package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"math/rand"
	"net"
	"sort"
	"strings"
	"sync"
	"syscall"
	"time"
)

// Layer-2 high availability (uplink side).
//
// OpenWrt already ships mwan3 for multi-WAN, and fighting it produces two
// routing authorities on one box. This implementation therefore:
//   - defers entirely when mwan3 is installed and running;
//   - otherwise owns only the default routes of the configured uplinks,
//     expressed as per-uplink metrics in the main table.
//
// Failover is a metric flip: the kernel already prefers the lowest metric, so
// removing a dead uplink from the preference order is atomic and needs no
// packet marking, no conntrack surgery and no policy routing.
type UplinkView struct {
	Interface  string `json:"interface"`
	Up         bool   `json:"up"`
	Healthy    bool   `json:"healthy"`
	Active     bool   `json:"active"`
	Gateway    string `json:"gateway,omitempty"`
	Metric     int    `json:"metric,omitempty"`
	LatencyMS  int64  `json:"latency_ms"`
	LossPct    int    `json:"loss_percent"`
	Successes  int    `json:"consecutive_successes"`
	Failures   int    `json:"consecutive_failures"`
	LastError  string `json:"last_error,omitempty"`
	LastSwitch string `json:"last_switch,omitempty"`
}

type uplinkState struct {
	name      string
	gateway   string
	originals []routeEntry
	health    UplinkHealth
}

// routeEntry is one default route we may rewrite. Keeping the original lets a
// stop or a daemon restart restore the operator's configuration exactly.
type routeEntry struct {
	Gateway string `json:"gateway"`
	Device  string `json:"device"`
	Metric  int    `json:"metric"`
}

type wanManager struct {
	agent *Agent

	mu      sync.Mutex
	uplinks map[string]*uplinkState
	order   []string
	active  string
	mwan3   bool
	applied bool
	lastLog map[string]time.Time
}

func newWanManager(agent *Agent) *wanManager {
	return &wanManager{agent: agent, uplinks: map[string]*uplinkState{}, lastLog: map[string]time.Time{}}
}

func (a *Agent) uplinkLoop(ctx context.Context) {
	manager := newWanManager(a)
	a.wan = manager
	if manager.mwan3Running() {
		a.log.Warn("uplink", "mwan3 is running; leaving multi-WAN routing to mwan3 and monitoring read-only")
		manager.mwan3 = true
	}
	manager.discover()
	if !manager.mwan3 {
		if err := manager.applyPreferences(); err != nil {
			a.log.Warn("uplink", "initial uplink preference apply failed: %v", err)
		}
	}
	interval := time.Duration(a.cfg.UplinkIntervalSecs) * time.Second
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	defer manager.restore()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
		manager.probeAll(ctx)
		if !manager.mwan3 {
			if err := manager.applyPreferences(); err != nil {
				a.log.Warn("uplink", "uplink preference apply failed: %v", err)
			}
		}
	}
}

// mwan3Running checks for both the init script state and the nftables table
// mwan3 installs, so a manually started mwan3 is detected too.
func (m *wanManager) mwan3Running() bool {
	if fileExists("/etc/init.d/mwan3") {
		if out, err := runCommandFunc(3*time.Second, "/etc/init.d/mwan3", "running"); err == nil {
			if strings.Contains(strings.ToLower(out), "running") {
				return true
			}
		}
	}

	// mwan3 installs its own nftables table; its presence is the strongest
	// evidence that something else already owns multi-WAN routing here, and it
	// is detected even when the init script lives elsewhere.
	if out, err := runCommandFunc(3*time.Second, "nft", "list", "tables"); err == nil {
		if strings.Contains(out, "mwan3") {
			return true
		}
	}
	return false
}

// discover resolves the configured uplinks to concrete device + gateway pairs.
func (m *wanManager) discover() {
	names := m.agent.cfg.UplinkInterfaces
	if len(names) == 0 {
		names = detectDefaultRouteDevices()
	}
	seen := map[string]bool{}
	for _, name := range names {
		if seen[name] {
			continue
		}
		seen[name] = true
		device := resolveLogicalInterface(name)
		if device == "" {
			m.agent.log.Warn("uplink", "interface %s has no backing device yet", name)
			continue
		}
		state := &uplinkState{name: name, health: UplinkHealth{Interface: name}}
		state.gateway, state.originals = defaultRoutesFor(device)
		state.health.Gateway = state.gateway
		m.mu.Lock()
		m.uplinks[name] = state
		m.order = append(m.order, name)
		m.mu.Unlock()
		m.agent.log.Info("uplink", "tracking %s (device=%s gateway=%s routes=%d)", name, device, state.gateway, len(state.originals))
	}
}

// resolveLogicalInterface maps a UCI network name (e.g. "wan") to a kernel
// device, using ubus first because that is authoritative on OpenWrt.
func resolveLogicalInterface(name string) string {
	if name == "" {
		return ""
	}
	if out, err := runCommandFunc(4*time.Second, "ubus", "call", "network.interface."+name, "status"); err == nil {
		var status struct {
			L3Device string `json:"l3_device"`
			Device   string `json:"device"`
		}
		if json.Unmarshal([]byte(out), &status) == nil {
			if status.L3Device != "" {
				return status.L3Device
			}
			if status.Device != "" {
				return status.Device
			}
		}
	}
	if dirExists("/sys/class/net/" + name) {
		return name
	}
	return ""
}

func detectDefaultRouteDevices() []string {
	out, err := runCommandFunc(4*time.Second, "ip", "-4", "route", "show", "default")
	if err != nil {
		return nil
	}
	seen := map[string]bool{}
	devices := []string{}
	for _, line := range strings.Split(out, "\n") {
		fields := strings.Fields(line)
		for index, field := range fields {
			if field == "dev" && index+1 < len(fields) && !seen[fields[index+1]] {
				seen[fields[index+1]] = true
				devices = append(devices, fields[index+1])
			}
		}
	}
	return devices
}

func defaultRoutesFor(device string) (string, []routeEntry) {
	out, err := runCommandFunc(4*time.Second, "ip", "-4", "route", "show", "default", "dev", device)
	if err != nil {
		return "", nil
	}
	entries := []routeEntry{}
	gateway := ""
	for _, line := range strings.Split(out, "\n") {
		fields := strings.Fields(line)
		entry := routeEntry{Device: device}
		for index, field := range fields {
			switch field {
			case "via":
				if index+1 < len(fields) {
					entry.Gateway = fields[index+1]
				}
			case "metric":
				if index+1 < len(fields) {
					entry.Metric = atoiDefault(fields[index+1], 0)
				}
			}
		}
		if entry.Gateway == "" {
			continue
		}
		if gateway == "" {
			gateway = entry.Gateway
		}
		entries = append(entries, entry)
	}
	return gateway, entries
}

// probeAll measures every uplink and updates the persisted health mirror.
func (m *wanManager) probeAll(ctx context.Context) {
	m.mu.Lock()
	names := append([]string(nil), m.order...)
	m.mu.Unlock()

	timeout := time.Duration(m.agent.cfg.UplinkTimeoutMS) * time.Millisecond
	for _, name := range names {
		m.mu.Lock()
		state := m.uplinks[name]
		m.mu.Unlock()
		if state == nil {
			continue
		}
		device := resolveLogicalInterface(name)
		if device == "" {
			m.recordFailure(name, "device_absent")
			continue
		}
		latency, loss, err := probeThroughInterface(ctx, device, m.agent.cfg.UplinkProbeTargets, timeout)
		if err != nil {
			m.recordFailure(name, err.Error())
			continue
		}
		m.recordSuccess(name, latency, loss)
	}
}

// probeThroughInterface sends DNS queries bound to a specific device, which
// proves the whole path (route, NAT, upstream resolver) rather than just link
// state. SO_BINDTODEVICE is what makes the binding authoritative.
func probeThroughInterface(ctx context.Context, device string, targets []string, timeout time.Duration) (int64, int, error) {
	if len(targets) == 0 {
		targets = []string{"1.1.1.1"}
	}
	best := int64(0)
	sent := 0
	received := 0
	var lastErr error
	for _, target := range targets {
		sent++
		address := target
		if !strings.Contains(address, ":") {
			address = net.JoinHostPort(address, "53")
		}
		query := buildDNSQuery()
		dialer := net.Dialer{
			Timeout: timeout,
			Control: func(network, address string, c syscall.RawConn) error {
				var innerErr error
				if err := c.Control(func(fd uintptr) {
					innerErr = bindToDevice(int(fd), device)
				}); err != nil {
					return err
				}
				return innerErr
			},
		}
		conn, err := dialer.DialContext(ctx, "udp", address)
		if err != nil {
			lastErr = err
			continue
		}
		started := time.Now()
		_ = conn.SetDeadline(started.Add(timeout))
		if _, err := conn.Write(query); err != nil {
			conn.Close()
			lastErr = err
			continue
		}
		buf := make([]byte, 512)
		if _, err := conn.Read(buf); err != nil {
			conn.Close()
			lastErr = err
			continue
		}
		conn.Close()
		elapsed := time.Since(started).Milliseconds()
		received++
		if best == 0 || elapsed < best {
			best = elapsed
		}
	}
	if received == 0 {
		if lastErr == nil {
			lastErr = errors.New("no_probe_response")
		}
		return 0, 100, lastErr
	}
	loss := (sent - received) * 100 / sent
	return best, loss, nil
}

// buildDNSQuery emits a minimal A query for a fixed name. It is only ever used
// as a reachability probe; the answer content is irrelevant.
func buildDNSQuery() []byte {
	id := uint16(rand.Intn(65535))
	name := "one.one.one.one"
	buf := make([]byte, 0, 64)
	buf = append(buf, byte(id>>8), byte(id))
	buf = append(buf, 0x01, 0x00) // standard query, recursion desired
	buf = append(buf, 0x00, 0x01) // QDCOUNT
	buf = append(buf, 0x00, 0x00) // ANCOUNT
	buf = append(buf, 0x00, 0x00) // NSCOUNT
	buf = append(buf, 0x00, 0x00) // ARCOUNT
	for _, label := range strings.Split(name, ".") {
		buf = append(buf, byte(len(label)))
		buf = append(buf, label...)
	}
	buf = append(buf, 0x00)
	buf = append(buf, 0x00, 0x01) // QTYPE A
	buf = append(buf, 0x00, 0x01) // QCLASS IN
	return buf
}

func (m *wanManager) recordSuccess(name string, latency int64, loss int) {
	m.mu.Lock()
	state := m.uplinks[name]
	if state == nil {
		m.mu.Unlock()
		return
	}
	state.health.LatencyMS = latency
	state.health.LossPercent = loss
	state.health.Successes++
	state.health.Failures = 0
	state.health.LastError = ""
	wasHealthy := state.health.Healthy
	if state.health.Successes >= m.agent.cfg.UplinkRecoverThresh {
		state.health.Healthy = true
	}
	health := state.health
	m.mu.Unlock()
	if !wasHealthy && health.Healthy {
		m.agent.log.Info("uplink", "%s recovered (rtt=%dms loss=%d%%)", name, latency, loss)
	}
	m.agent.store.PutUplink(health)
}

func (m *wanManager) recordFailure(name string, reason string) {
	m.mu.Lock()
	state := m.uplinks[name]
	if state == nil {
		m.mu.Unlock()
		return
	}
	state.health.Failures++
	state.health.Successes = 0
	state.health.LastError = reason
	wasHealthy := state.health.Healthy
	if state.health.Failures >= m.agent.cfg.UplinkFailThreshold {
		state.health.Healthy = false
		state.health.LatencyMS = 0
		state.health.LossPercent = 100
	}
	health := state.health
	m.mu.Unlock()
	if wasHealthy && !health.Healthy {
		m.agent.log.Warn("uplink", "%s marked down after %d failures: %s", name, state.health.Failures, reason)
	}
	m.agent.store.PutUplink(health)
}

// applyPreferences installs metrics so the best healthy uplink wins. A single
// "ip route replace" per uplink is atomic and idempotent.
func (m *wanManager) applyPreferences() error {
	m.mu.Lock()
	names := append([]string(nil), m.order...)
	if len(names) <= 1 {
		m.mu.Unlock()
		return nil
	}
	type candidate struct {
		name    string
		latency int64
		healthy bool
	}
	candidates := make([]candidate, 0, len(names))
	for _, name := range names {
		state := m.uplinks[name]
		if state == nil {
			continue
		}
		candidates = append(candidates, candidate{name: name, latency: state.health.LatencyMS, healthy: state.health.Healthy})
	}
	m.mu.Unlock()

	// Healthy uplinks first, then by measured latency. An uplink never probed
	// yet is treated as healthy so a cold start does not disable routing.
	sort.SliceStable(candidates, func(i, j int) bool {
		if candidates[i].healthy != candidates[j].healthy {
			return candidates[i].healthy
		}
		return candidates[i].latency < candidates[j].latency
	})

	selected := ""
	for index, item := range candidates {
		metric := 10 + index*10
		if !item.healthy && selected != "" {
			// Keep a down uplink configured but deprioritised, so recovery is
			// instant and the router still has a last-resort route.
			metric = 900 + index
		}
		if selected == "" && item.healthy {
			selected = item.name
		}
		if err := m.installMetric(item.name, metric); err != nil {
			return err
		}
	}

	m.mu.Lock()
	previous := m.active
	m.active = selected
	m.mu.Unlock()
	if previous != selected && selected != "" {
		m.agent.log.Warn("uplink", "active uplink switched %s -> %s", firstNonEmpty(previous, "none"), selected)
	}
	return nil
}

func (m *wanManager) installMetric(name string, metric int) error {
	m.mu.Lock()
	state := m.uplinks[name]
	m.mu.Unlock()
	if state == nil || state.gateway == "" {
		return nil
	}
	device := resolveLogicalInterface(name)
	if device == "" {
		return fmt.Errorf("uplink %s has no device", name)
	}
	m.mu.Lock()
	state.health.Metric = metric
	health := state.health
	m.mu.Unlock()
	m.agent.store.PutUplink(health)

	_, err := runCommandFunc(5*time.Second, "ip", "route", "replace", "default", "via", state.gateway, "dev", device, "metric", itoa(metric))
	return err
}

// restore puts the operator's original default routes back. It runs on
// shutdown so stopping the service never leaves the router with rewritten
// routing.
func (m *wanManager) restore() {
	if m.mwan3 {
		return
	}
	m.mu.Lock()
	states := make([]*uplinkState, 0, len(m.uplinks))
	for _, state := range m.uplinks {
		states = append(states, state)
	}
	m.mu.Unlock()
	for _, state := range states {
		for _, entry := range state.originals {
			metric := entry.Metric
			_, _ = runCommandFunc(5*time.Second, "ip", "route", "replace", "default", "via", entry.Gateway, "dev", entry.Device, "metric", itoa(metric))
		}
	}
	m.agent.log.Info("uplink", "restored original default routes")
}

func (a *Agent) uplinkViews() []UplinkView {
	manager := a.wan
	if manager == nil {
		mirror := a.store.Uplinks()
		views := make([]UplinkView, 0, len(mirror.Uplinks))
		for _, health := range mirror.Uplinks {
			views = append(views, UplinkView{
				Interface: health.Interface, Healthy: health.Healthy, Up: health.Healthy,
				Gateway: health.Gateway, LatencyMS: health.LatencyMS, LossPct: health.LossPercent,
				Successes: health.Successes, Failures: health.Failures, LastError: health.LastError,
			})
		}
		sort.Slice(views, func(i, j int) bool { return views[i].Interface < views[j].Interface })
		return views
	}
	manager.mu.Lock()
	defer manager.mu.Unlock()
	views := make([]UplinkView, 0, len(manager.order))
	for _, name := range manager.order {
		state := manager.uplinks[name]
		if state == nil {
			continue
		}
		views = append(views, UplinkView{
			Interface: state.name,
			Up:        state.health.Healthy || state.health.Successes > 0,
			Healthy:   state.health.Healthy,
			Active:    manager.active == state.name,
			Gateway:   state.gateway,
			Metric:    state.health.Metric,
			LatencyMS: state.health.LatencyMS,
			LossPct:   state.health.LossPercent,
			Successes: state.health.Successes,
			Failures:  state.health.Failures,
			LastError: state.health.LastError,
		})
	}
	return views
}
