package main

import (
	"net"
	"net/netip"
	"sort"
	"strings"
	"sync"
	"time"
)

// Relay addresses must never enter the gateway's own TUN.
//
// sing-box auto_route captures every unbound socket on the router, including
// the ones ultra-client opens to its relays. Without an exception the native
// relay would dial the relay through the TUN, i.e. through itself, and an
// ordinary forward's runtime would be pushed through the native exit. The
// Panel names exactly the addresses a candidate must be reached on directly
// (direct_ip_cidrs, which always include the endpoint), so each one gets an
// "ip rule to <cidr> lookup main" just ahead of sing-box's own rules.
//
// Rules live only while the gateway runs. If leikwand dies, the rules only
// ever point at the main table, so a leftover rule is harmless.
const (
	gatewayBypassRulePref = 8970
	// Bounds the rule set: a Panel bug must not be able to fill the policy
	// routing database, nor exempt a whole address family.
	gatewayBypassMaxRules   = 256
	gatewayBypassMinPrefix4 = 8
	gatewayBypassMinPrefix6 = 16
)

type bypassRoutes struct {
	mu sync.Mutex
	// current and previous lease generation per owner. The previous set is
	// kept so flows to a just-rotated relay drain instead of being yanked
	// into the TUN mid-connection.
	current   map[string][]string
	previous  map[string][]string
	installed map[string]bool
	active    bool
	lastErr   string
}

func newBypassRoutes() *bypassRoutes {
	return &bypassRoutes{
		current:   map[string][]string{},
		previous:  map[string][]string{},
		installed: map[string]bool{},
	}
}

// normalizeBypassCIDR accepts an address or a prefix and returns its
// canonical prefix, or false for anything too broad to be a relay.
func normalizeBypassCIDR(value string) (string, bool) {
	value = strings.TrimSpace(value)
	if value == "" {
		return "", false
	}
	if !strings.Contains(value, "/") {
		address, err := netip.ParseAddr(strings.Trim(value, "[]"))
		if err != nil {
			return "", false
		}
		address = address.Unmap()
		if address.IsUnspecified() || address.IsLoopback() {
			return "", false
		}
		return netip.PrefixFrom(address, address.BitLen()).String(), true
	}
	prefix, err := netip.ParsePrefix(value)
	if err != nil {
		return "", false
	}
	prefix = netip.PrefixFrom(prefix.Addr().Unmap(), prefix.Bits()).Masked()
	minimum := gatewayBypassMinPrefix4
	if prefix.Addr().Is6() {
		minimum = gatewayBypassMinPrefix6
	}
	if prefix.Bits() < minimum || prefix.Addr().IsUnspecified() || prefix.Addr().IsLoopback() {
		return "", false
	}
	return prefix.String(), true
}

// leaseBypassCIDRs is the direct set a lease batch needs: every endpoint and
// every Panel-declared direct CIDR.
func leaseBypassCIDRs(leases []LeaseFrame) []string {
	seen := map[string]bool{}
	out := []string{}
	add := func(value string) {
		if cidr, ok := normalizeBypassCIDR(value); ok && !seen[cidr] {
			seen[cidr] = true
			out = append(out, cidr)
		}
	}
	for _, lease := range leases {
		add(lease.Endpoint.IP)
		for _, cidr := range lease.DirectIPCidrs {
			add(cidr)
		}
	}
	sort.Strings(out)
	return out
}

// set records the direct set an owner (a forward runtime or the native relay)
// currently depends on. It does not touch the kernel; sync does.
func (b *bypassRoutes) set(owner string, cidrs []string) {
	if b == nil {
		return
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	if !equalStrings(b.current[owner], cidrs) {
		b.previous[owner] = b.current[owner]
		b.current[owner] = append([]string(nil), cidrs...)
	}
}

func (b *bypassRoutes) remove(owner string) {
	if b == nil {
		return
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	delete(b.current, owner)
	delete(b.previous, owner)
}

func (b *bypassRoutes) desiredLocked() map[string]bool {
	desired := map[string]bool{}
	for _, group := range []map[string][]string{b.current, b.previous} {
		for _, cidrs := range group {
			for _, cidr := range cidrs {
				if len(desired) >= gatewayBypassMaxRules {
					return desired
				}
				desired[cidr] = true
			}
		}
	}
	return desired
}

// activate clears rules a crashed predecessor left behind and installs the
// current set. Call it before sing-box starts so the relay never sees a
// window in which its own traffic is captured.
func (b *bypassRoutes) activate() error {
	if b == nil {
		return nil
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	flushBypassRules()
	b.installed = map[string]bool{}
	b.active = true
	return b.syncLocked()
}

// deactivate removes every rule this process installed.
func (b *bypassRoutes) deactivate() {
	if b == nil {
		return
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	if !b.active {
		return
	}
	for cidr := range b.installed {
		_ = bypassRuleCommand("del", cidr)
	}
	flushBypassRules()
	b.installed = map[string]bool{}
	b.active = false
	b.lastErr = ""
}

// sync adds missing rules before removing stale ones, so a rotation never
// opens a gap.
func (b *bypassRoutes) sync() error {
	if b == nil {
		return nil
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	if !b.active {
		return nil
	}
	return b.syncLocked()
}

func (b *bypassRoutes) syncLocked() error {
	desired := b.desiredLocked()
	var firstErr error
	for _, cidr := range sortedKeys(desired) {
		if b.installed[cidr] {
			continue
		}
		if err := bypassRuleCommand("add", cidr); err != nil {
			if firstErr == nil {
				firstErr = err
			}
			continue
		}
		b.installed[cidr] = true
	}
	for _, cidr := range sortedKeys(b.installed) {
		if desired[cidr] {
			continue
		}
		_ = bypassRuleCommand("del", cidr)
		delete(b.installed, cidr)
	}
	if firstErr != nil {
		b.lastErr = firstErr.Error()
	} else {
		b.lastErr = ""
	}
	return firstErr
}

func (b *bypassRoutes) view() (count int, lastErr string) {
	if b == nil {
		return 0, ""
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	return len(b.installed), b.lastErr
}

func bypassFamily(cidr string) string {
	if prefix, err := netip.ParsePrefix(cidr); err == nil && prefix.Addr().Is6() {
		return "-6"
	}
	return "-4"
}

func bypassRuleCommand(action, cidr string) error {
	_, err := runCommandFunc(4*time.Second, "ip", bypassFamily(cidr), "rule", action,
		"to", cidr, "lookup", "main", "pref", itoa(gatewayBypassRulePref))
	return err
}

// flushBypassRules deletes every rule at the reserved preference. The loop is
// bounded because "ip rule del pref N" removes one rule per call.
func flushBypassRules() {
	for _, family := range []string{"-4", "-6"} {
		for i := 0; i < gatewayBypassMaxRules+8; i++ {
			if _, err := runCommandFunc(4*time.Second, "ip", family, "rule", "del", "pref", itoa(gatewayBypassRulePref)); err != nil {
				break
			}
		}
	}
}

func sortedKeys(values map[string]bool) []string {
	out := make([]string, 0, len(values))
	for key := range values {
		out = append(out, key)
	}
	sort.Strings(out)
	return out
}

func equalStrings(left, right []string) bool {
	if len(left) != len(right) {
		return false
	}
	for i := range left {
		if left[i] != right[i] {
			return false
		}
	}
	return true
}

// hostOfURL returns the host of a URL, or "" for an IP literal or garbage.
func hostOfURL(raw string) string {
	raw = strings.TrimSpace(raw)
	if i := strings.Index(raw, "://"); i >= 0 {
		raw = raw[i+3:]
	}
	if i := strings.IndexAny(raw, "/?#"); i >= 0 {
		raw = raw[:i]
	}
	if i := strings.LastIndex(raw, "@"); i >= 0 {
		raw = raw[i+1:]
	}
	host := raw
	if h, _, err := net.SplitHostPort(raw); err == nil {
		host = h
	}
	host = strings.ToLower(strings.Trim(host, "[]."))
	if host == "" || net.ParseIP(host) != nil {
		return ""
	}
	return host
}
