package main

import (
	"errors"
	"net"
	"net/http"
	"strings"
	"syscall"
	"testing"
	"time"
)

// fakeEgressHost is a router as the chooser sees it: default routes, the
// devices that carry tun_flags, the devices that exist, and UCI interfaces.
type fakeEgressHost struct {
	routes   []defaultRoute
	tunFlags map[string]bool
	devices  map[string]bool
	logical  map[string]string
}

func (h fakeEgressHost) env() egressEnv {
	return egressEnv{
		routes:        func() []defaultRoute { return h.routes },
		hasTunFlags:   func(device string) bool { return h.tunFlags[device] },
		deviceExists:  func(device string) bool { return h.devices[device] },
		logicalDevice: func(name string) string { return h.logical[name] },
	}
}

func TestChooseEgressDevice(t *testing.T) {
	cases := []struct {
		name     string
		override string
		host     fakeEgressHost
		device   string
		reason   string
	}{
		{
			name:   "eth0 default route beats a higher-metric tun0",
			host:   fakeEgressHost{routes: []defaultRoute{{"eth0", 0}, {"tun0", 50}}},
			device: "eth0", reason: egressReasonDefaultRoute,
		},
		{
			name:   "default via utun falls back to the next physical default route",
			host:   fakeEgressHost{routes: []defaultRoute{{"utun", 0}, {"eth1", 10}}},
			device: "eth1", reason: egressReasonDefaultRouteAlt,
		},
		{
			name: "default via Meta with no other default route falls back to UCI wan",
			host: fakeEgressHost{
				routes:  []defaultRoute{{"Meta", 0}},
				logical: map[string]string{"wan": "eth0.2"},
			},
			device: "eth0.2", reason: egressReasonWANInterface,
		},
		{
			name:   "pppoe-wan is the real WAN and is kept",
			host:   fakeEgressHost{routes: []defaultRoute{{"pppoe-wan", 0}, {"wg0", 5}}},
			device: "pppoe-wan", reason: egressReasonDefaultRoute,
		},
		{
			name: "a device carrying tun_flags is skipped whatever its name",
			host: fakeEgressHost{
				routes:   []defaultRoute{{"proxy0", 0}, {"eth0", 20}},
				tunFlags: map[string]bool{"proxy0": true},
			},
			device: "eth0", reason: egressReasonDefaultRouteAlt,
		},
		{
			name: "proxy and overlay names are all skipped",
			host: fakeEgressHost{routes: []defaultRoute{
				{"mihomo", 0}, {"clash0", 1}, {"singbox_tun", 2}, {"xray0", 3}, {"v2ray", 4},
				{"passwall", 5}, {"tailscale0", 6}, {"zt5u4y6hh2", 7}, {"ipsec0", 8}, {"tap0", 9},
				{"lkwtun0", 10}, {"wan", 11},
			}},
			device: "wan", reason: egressReasonDefaultRouteAlt,
		},
		{
			name:     "an existing device override is honoured",
			override: "eth1",
			host: fakeEgressHost{
				routes:  []defaultRoute{{"eth0", 0}},
				devices: map[string]bool{"eth1": true},
			},
			device: "eth1", reason: egressReasonOverride,
		},
		{
			name:     "a UCI interface override resolves to its device",
			override: "wanb",
			host: fakeEgressHost{
				routes:  []defaultRoute{{"eth0", 0}},
				logical: map[string]string{"wanb": "pppoe-wanb"},
			},
			device: "pppoe-wanb", reason: egressReasonOverride,
		},
		{
			name:     "an override naming nothing falls back to auto",
			override: "eth9",
			host:     fakeEgressHost{routes: []defaultRoute{{"eth0", 0}}},
			device:   "eth0", reason: egressReasonInvalidPrefix + egressReasonDefaultRoute,
		},
		{
			name:     "a malformed override falls back to auto",
			override: "eth0; reboot",
			host:     fakeEgressHost{routes: []defaultRoute{{"eth0", 0}}, devices: map[string]bool{"eth0; reboot": true}},
			device:   "eth0", reason: egressReasonInvalidPrefix + egressReasonDefaultRoute,
		},
		{
			name:     "none disables pinning",
			override: "none",
			host:     fakeEgressHost{routes: []defaultRoute{{"eth0", 0}}},
			device:   "", reason: egressReasonDisabled,
		},
		{
			name:   "only tunnels and no UCI wan leaves the socket unbound",
			host:   fakeEgressHost{routes: []defaultRoute{{"tun0", 0}}, logical: map[string]string{"wan": "wg0"}},
			device: "", reason: egressReasonNoRoute,
		},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := chooseEgressDevice(tc.override, tc.host.env())
			if got.Device != tc.device || got.Reason != tc.reason {
				t.Fatalf("got device=%q reason=%q, want device=%q reason=%q", got.Device, got.Reason, tc.device, tc.reason)
			}
		})
	}
}

func TestParseProcNetRoute(t *testing.T) {
	table := strings.Join([]string{
		"Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT",
		"Meta\t00000000\t00000000\t0001\t0\t0\t0\t00000000\t0\t0\t0",
		"eth0\t00000000\t0101A8C0\t0003\t0\t0\t10\t00000000\t0\t0\t0",
		"eth0\t0001A8C0\t00000000\t0001\t0\t0\t0\t00FFFFFF\t0\t0\t0",
		"wg0\t00000000\t00000000\t0000\t0\t0\t1\t00000000\t0\t0\t0", // not RTF_UP
		"pppoe-wan\t00000000\t00000000\t0001\t0\t0\t5\t00000000\t0\t0\t0",
	}, "\n")
	routes := parseProcNetRoute(table)
	want := []defaultRoute{{"Meta", 0}, {"pppoe-wan", 5}, {"eth0", 10}}
	if len(routes) != len(want) {
		t.Fatalf("routes = %+v, want %+v", routes, want)
	}
	for i := range want {
		if routes[i] != want[i] {
			t.Fatalf("routes = %+v, want %+v", routes, want)
		}
	}
	got := autoEgressDevice(egressEnv{routes: func() []defaultRoute { return routes }})
	if got.Device != "pppoe-wan" || got.Reason != egressReasonDefaultRouteAlt {
		t.Fatalf("auto choice = %+v", got)
	}
}

func TestUCINetworkDevice(t *testing.T) {
	path := writeTempFile(t, "network", `
config interface 'lan'
	option device 'br-lan'
	option proto 'static'

config interface 'wan'
	option device 'eth0.2'
	option proto 'pppoe'

config interface 'wanb'
	option ifname 'eth1 eth2'
	option proto 'dhcp'
`)
	if got := uciNetworkDevice(path, "wan"); got != "pppoe-wan" {
		t.Fatalf("wan = %q, want pppoe-wan", got)
	}
	if got := uciNetworkDevice(path, "wanb"); got != "eth1" {
		t.Fatalf("wanb = %q, want eth1", got)
	}
	if got := uciNetworkDevice(path, "missing"); got != "" {
		t.Fatalf("missing = %q", got)
	}
}

// The Panel transport must never follow HTTP(S)_PROXY, and it must be a
// dedicated transport rather than http.DefaultTransport.
func TestPanelTransportIgnoresProxyEnvironment(t *testing.T) {
	t.Setenv("HTTPS_PROXY", "http://127.0.0.1:9")
	t.Setenv("HTTP_PROXY", "http://127.0.0.1:9")
	t.Setenv("ALL_PROXY", "socks5://127.0.0.1:9")
	client := newPanelClient(defaultConfig(), SecretState{}, "dev-1", "router")
	if client.http == http.DefaultClient {
		t.Fatal("panel client must not be http.DefaultClient")
	}
	transport, ok := client.http.Transport.(*http.Transport)
	if !ok || transport == http.DefaultTransport {
		t.Fatalf("panel transport = %T, want a dedicated *http.Transport", client.http.Transport)
	}
	if transport.Proxy != nil {
		t.Fatal("panel transport must not consult the proxy environment")
	}
	if transport.DialContext == nil {
		t.Fatal("panel transport must dial through the egress binder")
	}
}

type fakeRawConn struct{ fd uintptr }

func (f fakeRawConn) Control(fn func(fd uintptr)) error { fn(f.fd); return nil }
func (f fakeRawConn) Read(func(fd uintptr) bool) error  { return nil }
func (f fakeRawConn) Write(func(fd uintptr) bool) error { return nil }

var _ syscall.RawConn = fakeRawConn{}

func TestEgressControlBindsToDevice(t *testing.T) {
	egress := newPanelEgress("auto")
	var calls []string
	egress.bind = func(fd int, device string) error {
		calls = append(calls, device)
		return nil
	}
	if err := egress.control("eth0")("tcp4", "203.0.113.10:443", fakeRawConn{fd: 7}); err != nil {
		t.Fatalf("control: %v", err)
	}
	if len(calls) != 1 || calls[0] != "eth0" {
		t.Fatalf("bind calls = %v, want [eth0]", calls)
	}

	// A loopback Panel (tests, a local mirror) is never pinned to the WAN.
	if err := egress.control("eth0")("tcp4", "127.0.0.1:8080", fakeRawConn{}); err != nil {
		t.Fatalf("control loopback: %v", err)
	}
	if err := egress.control("")("tcp4", "203.0.113.10:443", fakeRawConn{}); err != nil {
		t.Fatalf("control unbound: %v", err)
	}
	if len(calls) != 1 {
		t.Fatalf("loopback or unbound dial was bound: %v", calls)
	}
}

// A refused bind must not break connectivity: the dial proceeds unbound, the
// error is surfaced in status, and it is logged once rather than per dial.
func TestEgressControlFailureFallsBackUnbound(t *testing.T) {
	egress := newPanelEgress("auto")
	egress.log = newLogger("error")
	egress.env = fakeEgressHost{routes: []defaultRoute{{"eth0", 0}}}.env()
	egress.bind = func(int, string) error { return errors.New("operation not permitted") }
	for i := 0; i < 3; i++ {
		if err := egress.control("eth0")("tcp4", "203.0.113.10:443", fakeRawConn{}); err != nil {
			t.Fatalf("control must not fail the dial: %v", err)
		}
	}
	device, reason, bindErr := egress.view()
	if device != "eth0" || reason != egressReasonDefaultRoute {
		t.Fatalf("view = %q/%q", device, reason)
	}
	if !strings.Contains(bindErr, "not permitted") {
		t.Fatalf("bind error = %q", bindErr)
	}
	warnings := 0
	for _, event := range egress.log.ring {
		if strings.Contains(event.Message, "cannot bind panel socket") {
			warnings++
		}
	}
	if warnings != 1 {
		t.Fatalf("bind failure logged %d times, want 1", warnings)
	}
}

// The choice is cached, re-evaluated after the recheck interval (a PPPoE
// reconnect can rename nothing yet move the route) and immediately after an
// invalidation, and a device change drops idle connections.
func TestEgressReevaluatesWhenStale(t *testing.T) {
	host := &fakeEgressHost{routes: []defaultRoute{{"eth0", 0}}}
	egress := newPanelEgress("auto")
	egress.env = egressEnv{routes: func() []defaultRoute { return host.routes }}
	clock := time.Unix(1700000000, 0)
	egress.now = func() time.Time { return clock }
	changes := 0
	egress.onChange = func() { changes++ }

	if got := egress.current(); got.Device != "eth0" {
		t.Fatalf("first choice = %+v", got)
	}
	host.routes = []defaultRoute{{"pppoe-wan", 0}}
	if got := egress.current(); got.Device != "eth0" {
		t.Fatalf("cached choice = %+v, want eth0 until the recheck interval", got)
	}
	clock = clock.Add(egressRecheckInterval + time.Second)
	if got := egress.current(); got.Device != "pppoe-wan" {
		t.Fatalf("rechecked choice = %+v", got)
	}
	host.routes = []defaultRoute{{"eth1", 0}}
	egress.invalidate()
	if got := egress.current(); got.Device != "eth1" {
		t.Fatalf("invalidated choice = %+v", got)
	}
	if changes != 2 {
		t.Fatalf("idle connections dropped %d times, want 2", changes)
	}

	egress.setOverride("none")
	if got := egress.current(); got.Device != "" || got.Reason != egressReasonDisabled {
		t.Fatalf("override not applied on reload: %+v", got)
	}
}

func TestFakeIPDetection(t *testing.T) {
	fake := []net.IPAddr{{IP: net.ParseIP("198.18.0.7")}, {IP: net.ParseIP("198.19.255.1")}}
	if !allFakeIP(fake) {
		t.Fatal("198.18.0.0/15 must be recognised as fake-ip")
	}
	mixed := append(fake, net.IPAddr{IP: net.ParseIP("117.55.193.164")})
	if allFakeIP(mixed) || allFakeIP(nil) {
		t.Fatal("a real address must disable the fake-ip workaround")
	}
}

// The status snapshot always reports the egress decision and that the proxy
// environment is ignored, so LuCI and "ctl status" can show it.
func TestSnapshotReportsPanelEgress(t *testing.T) {
	cfg := defaultConfig()
	cfg.PanelEgressInterface = "none"
	agent := newTestAgent(t, cfg)
	snapshot := agent.snapshot()
	if !snapshot.ProxyIgnored {
		t.Fatal("proxy_ignored must be true")
	}
	if snapshot.PanelEgressReason != egressReasonDisabled || snapshot.PanelEgressOverride != "none" {
		t.Fatalf("egress = %q/%q/%q", snapshot.PanelEgressDevice, snapshot.PanelEgressReason, snapshot.PanelEgressOverride)
	}
}

func TestLoadConfigReadsPanelEgressInterface(t *testing.T) {
	path := writeTempFile(t, "leikwan", "config leikwan 'main'\n\toption panel_egress_interface 'wanb'\n")
	t.Setenv("LEIKWAN_UCI_PATH", path)
	defer func(previous string) { uciConfigPath = previous }(uciConfigPath)
	if got := loadConfig().PanelEgressInterface; got != "wanb" {
		t.Fatalf("panel_egress_interface = %q", got)
	}
	if got := defaultConfig().PanelEgressInterface; got != egressAuto {
		t.Fatalf("default = %q, want auto", got)
	}
}
