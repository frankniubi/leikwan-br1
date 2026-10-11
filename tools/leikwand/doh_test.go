package main

import (
	"context"
	"encoding/binary"
	"errors"
	"net"
	"strconv"
	"testing"
	"time"
)

func dnsAnswer(t *testing.T, id uint16, rcode uint16, records ...net.IP) []byte {
	t.Helper()
	query, err := buildDNSAQuery("v3.leikwanhost.com", id)
	if err != nil {
		t.Fatal(err)
	}
	message := append([]byte(nil), query...)
	binary.BigEndian.PutUint16(message[2:], 0x8180|rcode)
	// One CNAME that must be skipped, then the A records; every owner name
	// is a compression pointer to the question.
	binary.BigEndian.PutUint16(message[6:], uint16(len(records)+1))
	message = append(message, 0xc0, 12, 0, 5, 0, 1, 0, 0, 0, 60, 0, 2, 0xc0, 12)
	for _, ip := range records {
		message = append(message, 0xc0, 12, 0, 1, 0, 1, 0, 0, 0, 60, 0, 4)
		message = append(message, ip.To4()...)
	}
	return message
}

func TestDNSAQueryRoundTrip(t *testing.T) {
	query, err := buildDNSAQuery("V3.LeikwanHost.com.", 0x1234)
	if err != nil {
		t.Fatal(err)
	}
	want := "\x02v3\x0bleikwanhost\x03com\x00\x00\x01\x00\x01"
	if string(query[12:]) != want || binary.BigEndian.Uint16(query) != 0x1234 {
		t.Fatalf("unexpected query encoding: %q", query)
	}
	ips, err := parseDNSAResponse(dnsAnswer(t, 0x1234, 0, net.ParseIP("117.55.193.164"), net.ParseIP("198.18.0.9")), 0x1234)
	if err != nil {
		t.Fatal(err)
	}
	if len(ips) != 1 || !ips[0].Equal(net.ParseIP("117.55.193.164")) {
		t.Fatalf("want the real A record only (fake-ip dropped), got %v", ips)
	}
	if _, err := parseDNSAResponse(dnsAnswer(t, 0x1234, 0), 0x9999); err == nil {
		t.Fatal("an id mismatch must be rejected")
	}
	if _, err := parseDNSAResponse(dnsAnswer(t, 0x1234, 3), 0x1234); err == nil {
		t.Fatal("NXDOMAIN must be an error")
	}
	if _, err := parseDNSAResponse([]byte{1, 2, 3}, 0); err == nil {
		t.Fatal("a truncated message must be rejected")
	}
	if _, err := buildDNSAQuery("bad..host", 1); err == nil {
		t.Fatal("an empty label must be rejected")
	}
}

func TestProtectedPanelHost(t *testing.T) {
	for host, want := range map[string]bool{
		"v3.leikwanhost.com":      true,
		"LEIKWANHOST.COM.":        true,
		"cdn.v3.leikwanhost.com":  true,
		"leikwanhost.com.evil.io": false,
		"notleikwanhost.com":      false,
		"117.55.193.164":          false,
		"":                        false,
	} {
		if got := isProtectedPanelHost(host); got != want {
			t.Errorf("isProtectedPanelHost(%q) = %v, want %v", host, got, want)
		}
	}
}

func TestStrictDoHFallsBackAcrossProvidersAndCaches(t *testing.T) {
	egress := newPanelEgress("none")
	var tried []string
	egress.dohExchange = func(_ context.Context, provider dohProvider, bootstrap, host, device string) ([]net.IP, error) {
		tried = append(tried, provider.name+"@"+bootstrap)
		if provider.name == "AliDNS" {
			return nil, errors.New("blocked")
		}
		return []net.IP{net.ParseIP("117.55.193.164")}, nil
	}
	ips, err := egress.strictDoH(context.Background(), "v3.leikwanhost.com", "eth0")
	if err != nil || len(ips) != 1 {
		t.Fatalf("want the DNSPod answer, got %v %v", ips, err)
	}
	if len(tried) != 3 || tried[2] != "DNSPod@1.12.12.12" {
		t.Fatalf("providers must be tried in order with both AliDNS bootstraps first: %v", tried)
	}
	if _, err := egress.strictDoH(context.Background(), "v3.leikwanhost.com", "eth0"); err != nil || len(tried) != 3 {
		t.Fatalf("a second lookup inside the TTL must come from the cache (tried %v)", tried)
	}
	base := time.Now()
	egress.now = func() time.Time { return base.Add(2 * strictDoHCacheTTL) }
	if _, err := egress.strictDoH(context.Background(), "v3.leikwanhost.com", "eth0"); err != nil || len(tried) != 6 {
		t.Fatalf("an expired entry must be looked up again (tried %v)", tried)
	}
}

func TestStrictDoHReportsEveryFailure(t *testing.T) {
	egress := newPanelEgress("none")
	egress.dohExchange = func(context.Context, dohProvider, string, string, string) ([]net.IP, error) {
		return nil, errors.New("timeout")
	}
	if _, err := egress.strictDoH(context.Background(), "v3.leikwanhost.com", ""); err == nil {
		t.Fatal("all providers failing must be an error")
	}
}

func localListener(t *testing.T) (string, func()) {
	t.Helper()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	go func() {
		for {
			conn, err := listener.Accept()
			if err != nil {
				return
			}
			conn.Close()
		}
	}()
	_, port, _ := net.SplitHostPort(listener.Addr().String())
	return port, func() { listener.Close() }
}

// Panel hostnames never touch the local resolver when DoH answers.
func TestDialBoundResolvesPanelHostWithDoH(t *testing.T) {
	port, stop := localListener(t)
	defer stop()
	egress := newPanelEgress("none")
	egress.lookupIP = func(context.Context, string) ([]net.IPAddr, error) {
		t.Fatal("the local resolver must not be asked for a panel host")
		return nil, nil
	}
	egress.dohExchange = func(context.Context, dohProvider, string, string, string) ([]net.IP, error) {
		return []net.IP{net.ParseIP("127.0.0.1")}, nil
	}
	conn, err := egress.DialContext(context.Background(), "tcp", net.JoinHostPort("v3.leikwanhost.com", port))
	if err != nil {
		t.Fatalf("dial through the DoH answer: %v", err)
	}
	conn.Close()
}

// A non-panel host (a CDN mirror) resolving to fake-ip is re-resolved by DoH.
func TestDialBoundUsesDoHForFakeIPAnswers(t *testing.T) {
	port, stop := localListener(t)
	defer stop()
	egress := newPanelEgress("none")
	egress.lookupIP = func(context.Context, string) ([]net.IPAddr, error) {
		return []net.IPAddr{{IP: net.ParseIP("198.18.0.20")}}, nil
	}
	calls := 0
	egress.dohExchange = func(context.Context, dohProvider, string, string, string) ([]net.IP, error) {
		calls++
		return []net.IP{net.ParseIP("127.0.0.1")}, nil
	}
	conn, err := egress.DialContext(context.Background(), "tcp", net.JoinHostPort("mirror.example-cdn.net", port))
	if err != nil {
		t.Fatalf("dial through the DoH answer: %v", err)
	}
	conn.Close()
	if calls != 1 {
		t.Fatalf("want one DoH lookup for the fake-ip answer, got %d", calls)
	}
}

// A real local answer for a non-panel host is used as-is, without DoH.
func TestDialBoundKeepsLocalAnswerForOtherHosts(t *testing.T) {
	port, stop := localListener(t)
	defer stop()
	egress := newPanelEgress("none")
	egress.lookupIP = func(context.Context, string) ([]net.IPAddr, error) {
		return []net.IPAddr{{IP: net.ParseIP("127.0.0.1")}}, nil
	}
	egress.dohExchange = func(context.Context, dohProvider, string, string, string) ([]net.IP, error) {
		t.Fatal("DoH must not run for a normal answer")
		return nil, nil
	}
	conn, err := egress.DialContext(context.Background(), "tcp", net.JoinHostPort("127.0.0.1", strconv.Itoa(mustAtoi(t, port))))
	if err != nil {
		t.Fatal(err)
	}
	conn.Close()
}

func mustAtoi(t *testing.T, value string) int {
	t.Helper()
	number, err := strconv.Atoi(value)
	if err != nil {
		t.Fatal(err)
	}
	return number
}
