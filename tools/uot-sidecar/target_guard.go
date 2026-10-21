package main

// Hostname target guard (SSRF).
//
// The panel rejects private and reserved IP-literal targets when a forward is
// created, but a DNS name is only resolved here, on the sidecar, when a session
// dials it. Without this guard a tenant could bind a forward to a name that
// resolves to 127.0.0.1, 10.x or 169.254.169.254 and use the sidecar as a UDP
// proxy into its own host and private networks.
//
// IP-literal targets are deliberately left alone: the panel already validated
// them, and legacy forwards that target 127.0.0.1 must keep working. Only a
// hostname target is resolved, vetted and then dialled by the vetted address
// itself, so a second resolution can never return a different (rebinding)
// answer between the check and the connect.

import (
	"context"
	"fmt"
	"net"
	"net/netip"
)

// errNonGlobalHostnameTarget is the HELLO rejection text for a hostname target
// that resolves to an address the sidecar must never dial for a tenant.
const errNonGlobalHostnameTarget = "target hostname resolves to a non-global address"

// hostLookup resolves a hostname target. sidecarServer.lookupHost defaults to
// the system resolver; tests substitute a fixed table so no real DNS is used.
type hostLookup func(ctx context.Context, host string) ([]netip.Addr, error)

func systemHostLookup(ctx context.Context, host string) ([]netip.Addr, error) {
	return net.DefaultResolver.LookupNetIP(ctx, "ip", host)
}

// isNonGlobalAddr reports whether addr must not be dialled for a hostname
// target: loopback, private (RFC 1918, fc00::/7), link-local (169.254.0.0/16
// incl. the 169.254.169.254 metadata service, fe80::/10), unspecified,
// multicast, 0.0.0.0/8, 100.64.0.0/10 (CGNAT), 192.0.0.0/24, 198.18.0.0/15,
// 240.0.0.0/4 incl. broadcast, and the IPv4-mapped, IPv4-compatible, NAT64
// (64:ff9b::/96) and 6to4 (2002::/16) forms of those. The panel applies the
// same list (ForwardLineApiContract::isNonGlobalAddress).
func isNonGlobalAddr(addr netip.Addr) bool {
	if !addr.IsValid() {
		return true
	}
	addr = addr.WithZone("").Unmap()
	if addr.Is4() {
		return isNonGlobalIPv4(addr.As4())
	}
	if addr.IsLoopback() || addr.IsUnspecified() || addr.IsMulticast() ||
		addr.IsPrivate() || addr.IsLinkLocalUnicast() {
		return true
	}
	b := addr.As16()
	// fec0::/10, the deprecated site-local range.
	if b[0] == 0xfe && b[1]&0xc0 == 0xc0 {
		return true
	}
	// ::/96, the deprecated IPv4-compatible form (covers :: and ::1 too).
	if isZero(b[:12]) {
		return true
	}
	// NAT64 well-known prefix: judge the embedded IPv4 address.
	if b[0] == 0x00 && b[1] == 0x64 && b[2] == 0xff && b[3] == 0x9b && isZero(b[4:12]) {
		return isNonGlobalIPv4([4]byte{b[12], b[13], b[14], b[15]})
	}
	// 6to4: judge the embedded IPv4 address.
	if b[0] == 0x20 && b[1] == 0x02 {
		return isNonGlobalIPv4([4]byte{b[2], b[3], b[4], b[5]})
	}
	return false
}

func isNonGlobalIPv4(b [4]byte) bool {
	switch {
	case b[0] == 0: // 0.0.0.0/8
		return true
	case b[0] == 10: // 10.0.0.0/8
		return true
	case b[0] == 127: // 127.0.0.0/8
		return true
	case b[0] == 100 && b[1]&0xc0 == 64: // 100.64.0.0/10
		return true
	case b[0] == 169 && b[1] == 254: // 169.254.0.0/16 (metadata 169.254.169.254)
		return true
	case b[0] == 172 && b[1]&0xf0 == 16: // 172.16.0.0/12
		return true
	case b[0] == 192 && b[1] == 0 && b[2] == 0: // 192.0.0.0/24
		return true
	case b[0] == 192 && b[1] == 168: // 192.168.0.0/16
		return true
	case b[0] == 198 && b[1]&0xfe == 18: // 198.18.0.0/15
		return true
	case b[0] >= 224: // 224.0.0.0/4 multicast, 240.0.0.0/4 incl. broadcast
		return true
	}
	return false
}

func isZero(b []byte) bool {
	for _, value := range b {
		if value != 0 {
			return false
		}
	}
	return true
}

// targetIsHostname reports whether host (already normalised by targetParts) is
// a DNS name rather than an IP literal.
func targetIsHostname(host string) bool {
	_, err := netip.ParseAddr(host)
	return err != nil
}

// resolveHostnameTarget resolves host once and returns the addresses the
// session may dial, in resolver order. It returns a nonGlobalTargetError when
// any resolved address is non-global and allowPrivate is false: one bad answer
// is enough, so a name cannot mix a public and a private record and hope the
// private one is dialled.
func resolveHostnameTarget(ctx context.Context, lookup hostLookup, host string, allowPrivate bool) ([]netip.Addr, error) {
	addrs, err := lookup(ctx, host)
	if err != nil {
		return nil, err
	}
	if len(addrs) == 0 {
		return nil, fmt.Errorf("target hostname %s resolved to no addresses", host)
	}
	vetted := make([]netip.Addr, 0, len(addrs))
	for _, addr := range addrs {
		if !allowPrivate && isNonGlobalAddr(addr) {
			return nil, nonGlobalTargetError{host: host, addr: addr}
		}
		vetted = append(vetted, addr.Unmap())
	}
	return vetted, nil
}

// nonGlobalTargetError keeps the offending address for the operator log line;
// uotSession.open turns it into the protocolError the HELLO is rejected with.
type nonGlobalTargetError struct {
	host string
	addr netip.Addr
}

func (e nonGlobalTargetError) Error() string {
	return fmt.Sprintf("%s: %s -> %s", errNonGlobalHostnameTarget, e.host, e.addr)
}
