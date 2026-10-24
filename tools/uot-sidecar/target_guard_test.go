package main

// Coverage for the hostname target guard: the address classifier, config
// switch, and end-to-end HELLO handling for hostname targets. IP-literal
// targets (127.0.0.1 in every other test) must stay unaffected.

import (
	"context"
	"errors"
	"net"
	"net/netip"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestIsNonGlobalAddrClassifier(t *testing.T) {
	nonGlobal := []string{
		"127.0.0.1", "127.255.255.254", "10.0.0.5", "172.16.0.1", "172.31.255.255",
		"192.168.1.1", "169.254.169.254", "169.254.0.1", "0.0.0.0", "0.1.2.3",
		"100.64.0.1", "100.127.255.255", "100.100.100.200", "192.0.0.1", "198.18.0.1",
		"198.19.255.255", "224.0.0.1", "239.255.255.250", "240.0.0.1", "255.255.255.255",
		"::", "::1", "fc00::1", "fd12:3456::1", "fe80::1", "fe80::1%eth0", "febf::1",
		"fec0::1", "ff02::1", "::ffff:127.0.0.1", "::ffff:169.254.169.254",
		"::ffff:10.1.2.3", "::127.0.0.1", "64:ff9b::7f00:1", "64:ff9b::a9fe:a9fe",
		"2002:7f00:1::1", "2002:a00:1::",
	}
	for _, text := range nonGlobal {
		if !isNonGlobalAddr(netip.MustParseAddr(text)) {
			t.Errorf("%s must be classified non-global", text)
		}
	}
	global := []string{
		"1.1.1.1", "8.8.8.8", "203.0.113.10", "198.51.100.7", "172.32.0.1", "172.15.255.255",
		"100.63.255.255", "100.128.0.1", "169.253.0.1", "192.0.1.1", "192.169.0.1",
		"198.17.255.255", "198.20.0.1", "223.255.255.255", "11.0.0.1", "126.255.255.255",
		"2001:4860:4860::8888", "2606:4700:4700::1111", "::ffff:8.8.8.8",
		"64:ff9b::808:808", "2002:808:808::1",
	}
	for _, text := range global {
		if isNonGlobalAddr(netip.MustParseAddr(text)) {
			t.Errorf("%s must be classified global", text)
		}
	}
	if !isNonGlobalAddr(netip.Addr{}) {
		t.Error("an invalid address must be classified non-global")
	}
}

func TestTargetIsHostname(t *testing.T) {
	for _, host := range []string{"127.0.0.1", "::1", "203.0.113.10", "2001:db8::5"} {
		if targetIsHostname(host) {
			t.Errorf("%s is an IP literal", host)
		}
	}
	for _, host := range []string{"localhost", "backend.example.com", "metadata.google.internal"} {
		if !targetIsHostname(host) {
			t.Errorf("%s is a hostname", host)
		}
	}
}

func TestResolveHostnameTargetRefusesAnyNonGlobalAnswer(t *testing.T) {
	mixed := func(context.Context, string) ([]netip.Addr, error) {
		return []netip.Addr{netip.MustParseAddr("203.0.113.10"), netip.MustParseAddr("10.0.0.1")}, nil
	}
	_, err := resolveHostnameTarget(context.Background(), mixed, "mixed.example", false)
	var refused nonGlobalTargetError
	if !errors.As(err, &refused) || refused.addr.String() != "10.0.0.1" {
		t.Fatalf("a mixed public/private answer must be refused, got %v", err)
	}
	addrs, err := resolveHostnameTarget(context.Background(), mixed, "mixed.example", true)
	if err != nil || len(addrs) != 2 {
		t.Fatalf("allow_private_hostname_targets must accept the answer, got %v %v", addrs, err)
	}
	mapped := func(context.Context, string) ([]netip.Addr, error) {
		return []netip.Addr{netip.MustParseAddr("::ffff:203.0.113.10")}, nil
	}
	addrs, err = resolveHostnameTarget(context.Background(), mapped, "mapped.example", false)
	if err != nil || len(addrs) != 1 || addrs[0].String() != "203.0.113.10" {
		t.Fatalf("a mapped public answer must be dialled as IPv4, got %v %v", addrs, err)
	}
	empty := func(context.Context, string) ([]netip.Addr, error) { return nil, nil }
	if _, err := resolveHostnameTarget(context.Background(), empty, "empty.example", false); err == nil || isProtocolFailure(err) {
		t.Fatalf("an empty answer must be a plain dial failure, got %v", err)
	}
}

func TestParseConfigAllowPrivateHostnameTargets(t *testing.T) {
	config, err := parseConfig([]byte(configFixture))
	if err != nil {
		t.Fatalf("parseConfig failed: %v", err)
	}
	if config.allowPrivateHostnameTargets {
		t.Fatal("allow_private_hostname_targets must default to false")
	}
	for raw, want := range map[string]bool{`true`: true, `false`: false, `"yes"`: true, `"0"`: false, `1`: true, `null`: false} {
		document := strings.Replace(configFixture, `"listen_host"`, `"allow_private_hostname_targets": `+raw+`, "listen_host"`, 1)
		parsed, err := parseConfig([]byte(document))
		if err != nil || parsed.allowPrivateHostnameTargets != want {
			t.Fatalf("allow_private_hostname_targets=%s: got %v %v", raw, parsed.allowPrivateHostnameTargets, err)
		}
	}
	for _, raw := range []string{`"maybe"`, `2`, `[]`} {
		document := strings.Replace(configFixture, `"listen_host"`, `"allow_private_hostname_targets": `+raw+`, "listen_host"`, 1)
		if _, err := parseConfig([]byte(document)); err == nil {
			t.Fatalf("allow_private_hostname_targets=%s must reject the file", raw)
		}
	}
}

// hostnameFixtureServer starts a sidecar whose forward 170 is bound to target.
// lookup, when non-nil, replaces the system resolver before the server starts.
func hostnameFixtureServer(t *testing.T, target string, allowPrivate bool, lookup hostLookup) (*sidecarServer, string, *syncBuffer) {
	t.Helper()
	config := sidecarConfig{
		listenHost: "127.0.0.1",
		forwards: map[int64]forwardBinding{170: {
			forwardID: 170,
			secret:    []byte("fixture-secret"),
			targets:   map[string]struct{}{target: {}},
		}},
		maxFrameBytes:               defaultMaxFrameBytes,
		framePayloadMax:             maxDatagram,
		queuePackets:                16,
		keepaliveSeconds:            0.05,
		idleTimeoutSeconds:          2,
		sessionGraceSeconds:         0.35,
		sessionLimit:                4,
		udpConnectTimeoutSeconds:    2,
		allowPrivateHostnameTargets: allowPrivate,
	}
	logs := &syncBuffer{}
	server := newSidecarServer(newConfigStore("", &config), newSidecarLogger("INFO", logs))
	if lookup != nil {
		server.lookupHost = lookup
	}
	if err := server.start(); err != nil {
		t.Fatalf("server start failed: %v", err)
	}
	t.Cleanup(server.close)
	return server, server.address(), logs
}

func TestHostnameTargetResolvingToLoopbackIsRefusedByDefault(t *testing.T) {
	echo := startEchoServer(t)
	_, port, _ := net.SplitHostPort(echo.address())
	target := "localhost:" + port
	// Real resolution of "localhost" (hosts file, never the network).
	server, address, logs := hostnameFixtureServer(t, target, false, nil)
	carrier := dialCarrier(t, address, randomSession(t))
	carrier.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, target, 1200))
	ack := decodeAck(t, carrier.mustRead(t, 3*time.Second).payload)
	if ack["ok"] != false || ack["error"] != errNonGlobalHostnameTarget {
		t.Fatalf("a hostname resolving to loopback must be refused, ack = %v", ack)
	}
	if registeredSessions(server) != 0 {
		t.Fatal("a refused hostname target left a registered session")
	}
	if echo.receivedCount() != 0 {
		t.Fatal("a refused hostname target reached the UDP target")
	}
	logged := logs.String()
	if !strings.Contains(logged, "WARNING external UoT session target refused") || !strings.Contains(logged, "forward=170") {
		t.Fatalf("refusal must be logged at WARNING with the forward id, logs:\n%s", logged)
	}
}

func TestHostnameTargetAllowedByConfigDialsVettedAddressOnce(t *testing.T) {
	echo := startEchoServer(t)
	_, port, _ := net.SplitHostPort(echo.address())
	target := "echo.test:" + port
	var lookups int32
	// The first answer is the echo server; any later lookup would "rebind" to
	// an address nothing listens on, so a second resolution breaks the relay.
	lookup := func(_ context.Context, host string) ([]netip.Addr, error) {
		if host != "echo.test" {
			return nil, errors.New("unexpected host " + host)
		}
		if atomic.AddInt32(&lookups, 1) == 1 {
			return []netip.Addr{netip.MustParseAddr("127.0.0.1")}, nil
		}
		return []netip.Addr{netip.MustParseAddr("127.0.0.2")}, nil
	}
	_, refusingAddress, _ := hostnameFixtureServer(t, target, false, lookup)
	refused := dialCarrier(t, refusingAddress, randomSession(t))
	refused.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, target, 1200))
	if ack := decodeAck(t, refused.mustRead(t, 3*time.Second).payload); ack["error"] != errNonGlobalHostnameTarget {
		t.Fatalf("without the config key the hostname must be refused, ack = %v", ack)
	}

	atomic.StoreInt32(&lookups, 0)
	_, address, _ := hostnameFixtureServer(t, target, true, lookup)
	carrier := dialCarrier(t, address, randomSession(t))
	carrier.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, target, 1200))
	if ack := decodeAck(t, carrier.mustRead(t, 3*time.Second).payload); ack["ok"] != true {
		t.Fatalf("allow_private_hostname_targets must admit the hostname, ack = %v", ack)
	}
	carrier.send(t, msgData, 1, []byte("via-hostname"))
	if got := carrier.mustReadUDPData(t, 3*time.Second); string(got.payload) != "via-hostname" {
		t.Fatalf("relay through the vetted address failed: %q", got.payload)
	}
	if count := atomic.LoadInt32(&lookups); count != 1 {
		t.Fatalf("hostname resolved %d times; the dial must reuse the vetted answer", count)
	}
}

func TestHostnameTargetResolutionFailureStaysSilent(t *testing.T) {
	target := "unresolvable.test:9000"
	failing := func(context.Context, string) ([]netip.Addr, error) {
		return nil, &net.DNSError{Err: "no such host", Name: "unresolvable.test", IsNotFound: true}
	}
	_, address, logs := hostnameFixtureServer(t, target, false, failing)
	carrier := dialCarrier(t, address, randomSession(t))
	carrier.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, target, 1200))
	if incoming, err := carrier.read(t, time.Second); err == nil {
		t.Fatalf("an unresolvable target must close silently like an unreachable one, got %+v", incoming)
	}
	if !strings.Contains(logs.String(), "unreachable") {
		t.Fatalf("an unresolvable target must be logged as unreachable, logs:\n%s", logs.String())
	}
}
