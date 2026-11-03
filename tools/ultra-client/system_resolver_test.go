package main

import (
	"context"
	"encoding/binary"
	"errors"
	"net"
	"os"
	"sync/atomic"
	"testing"
	"time"
)

func TestNeedsFallbackResolverOnlyOnAndroidWithoutResolvConf(t *testing.T) {
	missing := func(string) (os.FileInfo, error) { return nil, os.ErrNotExist }
	present := func(string) (os.FileInfo, error) { return nil, nil }
	if !needsFallbackResolver("android", missing) {
		t.Fatal("android without resolv.conf must use the fallback resolver")
	}
	if needsFallbackResolver("android", present) {
		t.Fatal("android with resolv.conf must keep the system resolver")
	}
	if needsFallbackResolver("linux", missing) {
		t.Fatal("non-android hosts must keep the system resolver")
	}
}

// startTestDNSServer answers every A query with answer and every other query
// with an empty NOERROR response. It counts the queries it receives.
func startTestDNSServer(t *testing.T, answer net.IP) (string, *atomic.Int32) {
	t.Helper()
	conn, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { conn.Close() })
	var queries atomic.Int32
	go func() {
		buffer := make([]byte, 512)
		for {
			count, peer, err := conn.ReadFrom(buffer)
			if err != nil {
				return
			}
			queries.Add(1)
			query := buffer[:count]
			questionEnd, ok := skipDNSName(query, 12)
			if !ok || questionEnd+4 > len(query) {
				continue
			}
			questionEnd += 4
			qtype := binary.BigEndian.Uint16(query[questionEnd-4 : questionEnd-2])
			response := append([]byte(nil), query[:questionEnd]...)
			binary.BigEndian.PutUint16(response[2:4], 0x8180)
			binary.BigEndian.PutUint16(response[6:8], 0)
			binary.BigEndian.PutUint16(response[8:10], 0)
			binary.BigEndian.PutUint16(response[10:12], 0)
			if qtype == 1 {
				binary.BigEndian.PutUint16(response[6:8], 1)
				response = append(response, 0xc0, 0x0c, 0, 1, 0, 1, 0, 0, 0, 60, 0, 4)
				response = append(response, answer.To4()...)
			}
			_, _ = conn.WriteTo(response, peer)
		}
	}()
	return conn.LocalAddr().String(), &queries
}

func TestFallbackResolverQueriesConfiguredServerInsteadOfLocalhost(t *testing.T) {
	server, queries := startTestDNSServer(t, net.ParseIP("192.0.2.7"))
	resolver := newFallbackResolver(Config{}, []string{server})
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	addresses, err := resolver.LookupIP(ctx, "ip4", "ddns.example.test")
	if err != nil {
		t.Fatalf("lookup through fallback resolver: %v", err)
	}
	if len(addresses) != 1 || !addresses[0].Equal(net.ParseIP("192.0.2.7")) {
		t.Fatalf("unexpected answer %v", addresses)
	}
	if queries.Load() == 0 {
		t.Fatal("fallback server received no query")
	}
}

func TestFallbackResolverRotatesPastAnUnresponsiveServer(t *testing.T) {
	silent, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer silent.Close()
	live, liveQueries := startTestDNSServer(t, net.ParseIP("192.0.2.9"))
	resolver := newFallbackResolver(Config{}, []string{silent.LocalAddr().String(), live})
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	addresses, err := resolver.LookupIP(ctx, "ip4", "rotate.example.test")
	if err != nil {
		t.Fatalf("lookup must fall through to the live server: %v", err)
	}
	if len(addresses) != 1 || !addresses[0].Equal(net.ParseIP("192.0.2.9")) {
		t.Fatalf("unexpected answer %v", addresses)
	}
	if liveQueries.Load() == 0 {
		t.Fatal("live server was never tried")
	}
}

func shortenUDPTargetRetry(t *testing.T) {
	t.Helper()
	initial, maximum := udpTargetResolveInitialDelay, udpTargetResolveMaxDelay
	udpTargetResolveInitialDelay, udpTargetResolveMaxDelay = 10*time.Millisecond, 20*time.Millisecond
	t.Cleanup(func() { udpTargetResolveInitialDelay, udpTargetResolveMaxDelay = initial, maximum })
}

func TestResolveUDPTargetRetriesUntilTheTargetResolves(t *testing.T) {
	shortenUDPTargetRetry(t)
	var attempts int
	resolve := func(context.Context, string) (string, error) {
		attempts++
		if attempts < 3 {
			return "", errors.New("lookup failed")
		}
		return "198.51.100.4", nil
	}
	address, err := resolveUDPTargetIPv4(context.Background(), "ddns.example.test", resolve)
	if err != nil || address != "198.51.100.4" || attempts != 3 {
		t.Fatalf("address=%q err=%v attempts=%d", address, err, attempts)
	}
}

func TestResolveUDPTargetStopsWhenTheRuntimeStops(t *testing.T) {
	shortenUDPTargetRetry(t)
	ctx, cancel := context.WithCancel(context.Background())
	resolve := func(context.Context, string) (string, error) { return "", errors.New("lookup failed") }
	done := make(chan error, 1)
	go func() {
		_, err := resolveUDPTargetIPv4(ctx, "ddns.example.test", resolve)
		done <- err
	}()
	time.Sleep(50 * time.Millisecond)
	cancel()
	select {
	case err := <-done:
		if !errors.Is(err, context.Canceled) {
			t.Fatalf("expected context cancellation, got %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("resolution retry did not stop with its context")
	}
}

// An unresolvable UDP target used to make serveUDP return at once, which
// cancelled the shared runtime and took the TCP listener down with it.
func TestServeUDPKeepsRunningWhileTargetDoesNotResolve(t *testing.T) {
	shortenUDPTargetRetry(t)
	previous := net.DefaultResolver
	net.DefaultResolver = &net.Resolver{
		PreferGo: true,
		Dial: func(context.Context, string, string) (net.Conn, error) {
			return nil, errors.New("dns unavailable")
		},
	}
	defer func() { net.DefaultResolver = previous }()

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	config := Config{ListenHost: "127.0.0.1", ListenPort: 0, Target: "ddns.example.test:10016"}
	done := make(chan error, 1)
	go func() { done <- serveUDP(ctx, config, nil, nil) }()
	select {
	case err := <-done:
		t.Fatalf("serveUDP returned while the target was unresolved: %v", err)
	case <-time.After(300 * time.Millisecond):
	}
	cancel()
	select {
	case err := <-done:
		if !errors.Is(err, context.Canceled) {
			t.Fatalf("expected context cancellation, got %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("serveUDP did not stop with its context")
	}
}
