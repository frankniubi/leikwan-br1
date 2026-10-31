package main

import (
	"context"
	"encoding/json"
	"net"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

// Panel egress pinning.
//
// Routers very often run something that captures router-originated traffic:
// OpenClash/Mihomo/sing-box/Passwall in TUN mode, or a VPN (OpenVPN,
// WireGuard, Tailscale, ZeroTier). If the control plane followed the default
// route it could end up inside one of those tunnels, where it would depend on
// a third-party proxy being up and would present the proxy's exit IP to the
// Panel. The agent therefore talks to the Panel directly:
//
//   - the HTTP transport never consults HTTP(S)_PROXY (Proxy is nil);
//   - every Panel socket is pinned with SO_BINDTODEVICE to the physical WAN.
//
// Pinning is best effort by design. When no physical device can be found, or
// the kernel refuses the bind, the socket stays unbound and the agent keeps
// working exactly as it did before this existed.

const (
	egressAuto = "auto"
	egressNone = "none"

	// egressRecheckInterval bounds how long a WAN change (a PPPoE reconnect,
	// a failover) can go unnoticed when no dial happens to fail.
	egressRecheckInterval = 30 * time.Second
)

// Reasons reported in "ctl status" and LuCI.
const (
	egressReasonOverride        = "override"
	egressReasonDisabled        = "disabled"
	egressReasonDefaultRoute    = "default_route"
	egressReasonDefaultRouteAlt = "default_route_non_tun"
	egressReasonWANInterface    = "wan_interface"
	egressReasonNoRoute         = "no_physical_route"
	egressReasonInvalidPrefix   = "override_invalid:"
)

// tunLikePrefixes are device names used by proxies, VPNs and overlays. A
// device matching one of these is never chosen automatically. pppoe-*, l2tp-*
// and plain ppp* are deliberately absent: on OpenWrt those are the real WAN.
var tunLikePrefixes = []string{
	"tun", "tap", "utun", "wg", "mihomo", "clash", "sing", "xray", "v2ray",
	"passwall", "tailscale", "zt", "ipsec",
	// The agent's own gateway TUN (lkwtun0) and its helper interface.
	"lkw",
}

// tunLikeExact covers names that are too short to be a safe prefix. "Meta" is
// the default TUN name of Clash.Meta / Mihomo.
var tunLikeExact = map[string]bool{"meta": true}

// ifaceNamePattern matches a Linux interface name (IFNAMSIZ is 16 including
// the terminator) or a UCI logical interface name.
var ifaceNamePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$`)

func isTunLikeName(device string) bool {
	lower := strings.ToLower(strings.TrimSpace(device))
	if lower == "" {
		return false
	}
	if tunLikeExact[lower] {
		return true
	}
	for _, prefix := range tunLikePrefixes {
		if strings.HasPrefix(lower, prefix) {
			return true
		}
	}
	return false
}

// defaultRoute is one IPv4 default route from the main table.
type defaultRoute struct {
	Device string
	Metric int
}

// parseProcNetRoute extracts the usable default routes from /proc/net/route,
// lowest metric first. That file is the main table only, which is exactly the
// table the kernel falls back to once a socket is bound to a device.
func parseProcNetRoute(text string) []defaultRoute {
	const rtfUp = 0x1
	routes := []defaultRoute{}
	for index, line := range strings.Split(text, "\n") {
		fields := strings.Fields(line)
		if index == 0 || len(fields) < 8 {
			continue
		}
		if fields[1] != "00000000" || fields[7] != "00000000" {
			continue
		}
		flags, err := strconv.ParseUint(fields[3], 16, 32)
		if err != nil || flags&rtfUp == 0 {
			continue
		}
		routes = append(routes, defaultRoute{Device: fields[0], Metric: atoiDefault(fields[6], 0)})
	}
	sort.SliceStable(routes, func(i, j int) bool { return routes[i].Metric < routes[j].Metric })
	return routes
}

// egressEnv is the host view the chooser needs. Production reads /proc, /sys
// and ubus; tests supply tables.
type egressEnv struct {
	routes        func() []defaultRoute
	hasTunFlags   func(device string) bool
	deviceExists  func(device string) bool
	logicalDevice func(name string) string
}

// networkConfigPath is a variable so a test can supply /etc/config/network.
var networkConfigPath = "/etc/config/network"

func systemEgressEnv() egressEnv {
	return egressEnv{
		routes: func() []defaultRoute {
			raw, err := os.ReadFile("/proc/net/route")
			if err != nil {
				return nil
			}
			return parseProcNetRoute(string(raw))
		},
		// TUN and TAP devices expose tun_flags whatever they are called, which
		// catches proxies that pick an unusual interface name.
		hasTunFlags:  func(device string) bool { return fileExists("/sys/class/net/" + device + "/tun_flags") },
		deviceExists: func(device string) bool { return dirExists("/sys/class/net/" + device) },
		logicalDevice: func(name string) string {
			if device := resolveLogicalInterface(name); device != "" {
				return device
			}
			return uciNetworkDevice(networkConfigPath, name)
		},
	}
}

// resolveLogicalInterface maps a UCI network name (e.g. "wan") to a kernel
// device, using ubus first because that is authoritative on OpenWrt. The
// panel egress pinning accepts logical names.
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

func (env egressEnv) tunLike(device string) bool {
	return isTunLikeName(device) || (env.hasTunFlags != nil && env.hasTunFlags(device))
}

// egressChoice is the device Panel sockets are bound to ("" = unbound) and why.
type egressChoice struct {
	Device string
	Reason string
}

// chooseEgressDevice picks the Panel egress device.
//
//  1. "none" disables pinning; any other non-"auto" value is an override. It
//     may name a kernel device or a UCI logical interface. An override that
//     resolves to nothing falls back to auto and says so.
//  2. auto: the lowest-metric default route in the main table whose device is
//     not TUN/TAP-like.
//  3. Otherwise the device behind UCI network "wan".
//  4. Otherwise unbound.
func chooseEgressDevice(override string, env egressEnv) egressChoice {
	override = strings.TrimSpace(override)
	lower := strings.ToLower(override)
	prefix := ""
	switch {
	case lower == egressNone || lower == "off" || lower == "disabled":
		return egressChoice{Reason: egressReasonDisabled}
	case lower == "" || lower == egressAuto:
	case !ifaceNamePattern.MatchString(override):
		prefix = egressReasonInvalidPrefix
	default:
		if env.deviceExists != nil && env.deviceExists(override) {
			return egressChoice{Device: override, Reason: egressReasonOverride}
		}
		if env.logicalDevice != nil {
			if device := env.logicalDevice(override); device != "" && ifaceNamePattern.MatchString(device) {
				return egressChoice{Device: device, Reason: egressReasonOverride}
			}
		}
		prefix = egressReasonInvalidPrefix
	}

	choice := autoEgressDevice(env)
	choice.Reason = prefix + choice.Reason
	return choice
}

func autoEgressDevice(env egressEnv) egressChoice {
	var routes []defaultRoute
	if env.routes != nil {
		routes = env.routes()
	}
	for index, route := range routes {
		if route.Device == "" || env.tunLike(route.Device) {
			continue
		}
		reason := egressReasonDefaultRoute
		if index > 0 {
			// A proxy or VPN owns the preferred default route; skip past it.
			reason = egressReasonDefaultRouteAlt
		}
		return egressChoice{Device: route.Device, Reason: reason}
	}
	if env.logicalDevice != nil {
		if device := env.logicalDevice("wan"); device != "" && ifaceNamePattern.MatchString(device) && !env.tunLike(device) {
			return egressChoice{Device: device, Reason: egressReasonWANInterface}
		}
	}
	return egressChoice{Reason: egressReasonNoRoute}
}

// uciNetworkDevice reads the device of a named interface section from
// /etc/config/network, for systems where ubus is unavailable. For PPPoE the
// layer-3 device is pppoe-<name>, not the Ethernet port it runs over.
func uciNetworkDevice(path, name string) string {
	raw, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	inSection := false
	proto, device, ifname := "", "", ""
	for _, line := range strings.Split(string(raw), "\n") {
		fields := splitUCIFields(strings.TrimSpace(line))
		if len(fields) == 0 || strings.HasPrefix(fields[0], "#") {
			continue
		}
		if fields[0] == "config" {
			if inSection {
				break
			}
			inSection = len(fields) >= 3 && fields[1] == "interface" && fields[2] == name
			continue
		}
		if !inSection || fields[0] != "option" || len(fields) < 3 {
			continue
		}
		switch fields[1] {
		case "proto":
			proto = fields[2]
		case "device":
			device = fields[2]
		case "ifname":
			ifname = fields[2]
		}
	}
	if proto == "pppoe" || proto == "pppoa" {
		return "pppoe-" + name
	}
	for _, candidate := range []string{device, ifname} {
		if parts := strings.Fields(candidate); len(parts) > 0 {
			return parts[0]
		}
	}
	return ""
}

// fakeIPNets are the default fake-ip pools of Clash/Mihomo and sing-box. A
// Panel hostname resolving into one of these only works through the proxy's
// TUN, so it is re-resolved directly over the WAN instead.
var fakeIPNets = func() []*net.IPNet {
	out := []*net.IPNet{}
	for _, cidr := range []string{"198.18.0.0/15", "fc00::/18"} {
		if _, network, err := net.ParseCIDR(cidr); err == nil {
			out = append(out, network)
		}
	}
	return out
}()

func isFakeIP(ip net.IP) bool {
	for _, network := range fakeIPNets {
		if network.Contains(ip) {
			return true
		}
	}
	return false
}

// panelEgress owns the current egress choice and dials Panel connections.
type panelEgress struct {
	mu        sync.Mutex
	override  string
	choice    egressChoice
	checkedAt time.Time
	evaluated bool
	bindErr   string
	warned    map[string]bool

	log      *Logger
	env      egressEnv
	bind     func(fd int, device string) error
	now      func() time.Time
	lookupIP func(ctx context.Context, host string) ([]net.IPAddr, error)
	onChange func()

	dohCache map[string]dohCacheEntry
	// dohExchange replaces the network exchange in tests.
	dohExchange func(ctx context.Context, provider dohProvider, bootstrap, host, device string) ([]net.IP, error)
}

func newPanelEgress(override string) *panelEgress {
	return &panelEgress{
		override: strings.TrimSpace(override),
		warned:   map[string]bool{},
		env:      systemEgressEnv(),
		bind:     bindToDevice,
		now:      time.Now,
		lookupIP: net.DefaultResolver.LookupIPAddr,
	}
}

func (e *panelEgress) setOverride(override string) {
	e.mu.Lock()
	changed := e.override != strings.TrimSpace(override)
	e.override = strings.TrimSpace(override)
	if changed {
		e.evaluated = false
	}
	e.mu.Unlock()
}

func (e *panelEgress) overrideValue() string {
	e.mu.Lock()
	defer e.mu.Unlock()
	if e.override == "" {
		return egressAuto
	}
	return e.override
}

// invalidate forces the next dial to re-run device selection.
func (e *panelEgress) invalidate() {
	e.mu.Lock()
	e.evaluated = false
	e.mu.Unlock()
}

// current returns the egress choice, re-evaluating it when stale. Selection
// runs outside the lock because the ubus fallback can take a few seconds.
func (e *panelEgress) current() egressChoice {
	e.mu.Lock()
	if e.evaluated && e.now().Sub(e.checkedAt) < egressRecheckInterval {
		choice := e.choice
		e.mu.Unlock()
		return choice
	}
	override := e.override
	e.mu.Unlock()

	next := chooseEgressDevice(override, e.env)

	e.mu.Lock()
	previous := e.choice
	first := !e.evaluated && e.checkedAt.IsZero()
	e.choice = next
	e.evaluated = true
	e.checkedAt = e.now()
	if !first && previous.Device != next.Device {
		// A bind error belongs to the device it was reported for.
		e.bindErr = ""
	}
	onChange := e.onChange
	e.mu.Unlock()

	if first || previous != next {
		device := next.Device
		if device == "" {
			device = "(unbound)"
		}
		e.logf("info", "panel egress device=%s reason=%s (proxy environment ignored)", device, next.Reason)
		if !first && previous.Device != next.Device && onChange != nil {
			// Idle keep-alive connections are still pinned to the old device.
			onChange()
		}
	}
	return next
}

// view reports the egress state for "ctl status" and LuCI.
func (e *panelEgress) view() (device, reason, bindErr string) {
	choice := e.current()
	e.mu.Lock()
	defer e.mu.Unlock()
	return choice.Device, choice.Reason, e.bindErr
}

func (e *panelEgress) logf(level, format string, args ...any) {
	if e.log == nil {
		return
	}
	if level == "warn" {
		e.log.Warn("panel", format, args...)
		return
	}
	e.log.Info("panel", format, args...)
}

// warnOnce logs a condition the first time it is seen for a given key.
func (e *panelEgress) warnOnce(key, format string, args ...any) {
	e.mu.Lock()
	seen := e.warned[key]
	e.warned[key] = true
	e.mu.Unlock()
	if !seen {
		e.logf("warn", format, args...)
	}
}

// control binds a socket to device before it connects. It never fails the
// dial: a refused bind is logged once and the socket stays unbound. Loopback
// destinations (a local test Panel, a local mirror) are never bound because
// binding them to the WAN would make them unreachable.
func (e *panelEgress) control(device string) func(network, address string, c syscall.RawConn) error {
	return func(network, address string, c syscall.RawConn) error {
		if device == "" {
			return nil
		}
		if host, _, err := net.SplitHostPort(address); err == nil {
			if ip := net.ParseIP(host); ip != nil && ip.IsLoopback() {
				return nil
			}
		}
		var bindErr error
		if err := c.Control(func(fd uintptr) {
			bindErr = e.bind(int(fd), device)
		}); err != nil {
			bindErr = err
		}
		e.mu.Lock()
		if bindErr != nil {
			e.bindErr = bindErr.Error()
		} else {
			e.bindErr = ""
		}
		e.mu.Unlock()
		if bindErr != nil {
			e.warnOnce("bind:"+device, "cannot bind panel socket to %s (%v); continuing unbound", device, bindErr)
		}
		return nil
	}
}

func (e *panelEgress) dialer(device string) *net.Dialer {
	return &net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second, Control: e.control(device)}
}

// DialContext is the Panel transport's dialer. A bound dial that fails
// triggers re-selection (the WAN may have changed, e.g. a PPPoE reconnect);
// if the pinned path still fails, one unbound attempt keeps the control plane
// reachable rather than stranding the router.
func (e *panelEgress) DialContext(ctx context.Context, network, address string) (net.Conn, error) {
	choice := e.current()
	if choice.Device == "" {
		return e.dialBound(ctx, network, address, "")
	}
	conn, err := e.dialBound(ctx, network, address, choice.Device)
	if err == nil || ctx.Err() != nil {
		return conn, err
	}
	e.invalidate()
	if next := e.current(); next.Device != "" && next.Device != choice.Device {
		if conn, retryErr := e.dialBound(ctx, network, address, next.Device); retryErr == nil || ctx.Err() != nil {
			return conn, retryErr
		}
	}
	e.warnOnce("unbound:"+choice.Device, "panel dial via %s failed (%v); retrying without device binding", choice.Device, err)
	return e.dialBound(ctx, network, address, "")
}

// dialBound dials through device. Panel hostnames (*.leikwanhost.com) are
// always resolved with strict DoH over the device, because local DNS for them
// can be poisoned; any other hostname uses Go's own resolver unless it hands
// back a fake-ip address (a TUN proxy intercepting DNS), in which case it is
// resolved with strict DoH too. A failed DoH lookup falls back to the local
// answer rather than leaving the router unable to reach the Panel.
func (e *panelEgress) dialBound(ctx context.Context, network, address, device string) (net.Conn, error) {
	dialer := e.dialer(device)
	host, port, err := net.SplitHostPort(address)
	if err != nil || net.ParseIP(host) != nil {
		return dialer.DialContext(ctx, network, address)
	}
	var resolved []net.IP
	dohTried := false
	if isProtectedPanelHost(host) {
		dohTried = true
		ips, dohErr := e.strictDoH(ctx, host, device)
		if dohErr != nil {
			e.warnOnce("doh:"+host, "strict DoH for panel host %s over %s failed (%v); using the local resolver", host, device, dohErr)
		} else {
			resolved = ips
		}
	}
	if len(resolved) == 0 {
		if e.lookupIP == nil {
			return dialer.DialContext(ctx, network, address)
		}
		addrs, lookupErr := e.lookupIP(ctx, host)
		if lookupErr != nil || !allFakeIP(addrs) || dohTried {
			return dialer.DialContext(ctx, network, address)
		}
		ips, dohErr := e.strictDoH(ctx, host, device)
		if dohErr != nil || len(ips) == 0 {
			e.warnOnce("fakeip:"+host, "panel host %s resolves to a fake-ip address and strict DoH over %s failed: %v", host, device, dohErr)
			return dialer.DialContext(ctx, network, address)
		}
		e.warnOnce("fakeip-direct:"+host, "panel host %s resolves to a fake-ip address locally; using strict DoH over %s", host, device)
		resolved = ips
	}
	var lastErr error
	for _, ip := range resolved {
		conn, dialErr := dialer.DialContext(ctx, network, net.JoinHostPort(ip.String(), port))
		if dialErr == nil {
			return conn, nil
		}
		lastErr = dialErr
		if ctx.Err() != nil {
			break
		}
	}
	return nil, lastErr
}

func allFakeIP(addrs []net.IPAddr) bool {
	if len(addrs) == 0 {
		return false
	}
	for _, addr := range addrs {
		if !isFakeIP(addr.IP) {
			return false
		}
	}
	return true
}
