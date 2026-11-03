package main

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func offlineLeaseFrame(expiresAt time.Time) map[string]any {
	target := testPolicyConfig().Target
	return map[string]any{
		"protocol":              policyProtocol,
		"purpose":               "ultra",
		"forward_id":            42,
		"session_ticket":        "ticket",
		"policy_generation":     strings.Repeat("a", 64),
		"lease_id":              "ul2_offline",
		"expires_at":            expiresAt.UTC().Format(time.RFC3339),
		"transport":             "tcp",
		"rate_limit_mbps":       20,
		"quota_remaining_bytes": 1024,
		"target_digest":         targetDigest(target),
		"selection_ref":         "selection-reference-abcdefghijklmnop",
		"selection_tier":        selectionTierPaid,
		"nonce_hash":            strings.Repeat("c", 64),
		"endpoint":              map[string]any{"ip": "203.0.113.20", "port": 5438},
		"credential":            map[string]any{"user": "user", "password": "password"},
	}
}

// W1: the panel issues up to 30 min (ceiling 3600 s) of offline validity to a
// client that asks for it. The runtime must accept it and still refuse
// anything longer.
func TestPolicyAcceptsOfflineValidityUpToOneHour(t *testing.T) {
	now := time.Now()
	config := testPolicyConfig()
	for _, validity := range []time.Duration{5 * time.Minute, 30 * time.Minute, time.Hour, time.Hour + 20*time.Second} {
		policy := testPolicy(now, strings.Repeat("a", 64))
		policy.ExpiresAt = now.Add(validity)
		if err := policy.Validate(config, now); err != nil {
			t.Fatalf("validity %s rejected: %v", validity, err)
		}
	}
	for _, validity := range []time.Duration{time.Hour + time.Minute, 2 * time.Hour} {
		policy := testPolicy(now, strings.Repeat("a", 64))
		policy.ExpiresAt = now.Add(validity)
		if err := policy.Validate(config, now); !errors.Is(err, ErrPolicyExpired) {
			t.Fatalf("validity %s accepted: %v", validity, err)
		}
	}

	raw, _ := json.Marshal(offlineLeaseFrame(now.Add(30 * time.Minute)))
	policy, lease, err := decodeLeaseFrame(raw, config)
	if err != nil {
		t.Fatalf("30 min lease frame rejected: %v", err)
	}
	store := NewPolicyStore(config)
	defer store.Revoke("")
	if err := store.Replace(policy, lease); err != nil {
		t.Fatalf("30 min grant not installed: %v", err)
	}
	lease.wipe()
	if !store.HasValid() {
		t.Fatal("30 min grant not usable")
	}

	raw, _ = json.Marshal(offlineLeaseFrame(now.Add(61 * time.Minute)))
	if _, _, err := decodeLeaseFrame(raw, config); !errors.Is(err, ErrPolicyExpired) {
		t.Fatalf("61 min lease frame accepted: %v", err)
	}

	// A lease still must not outlive its policy, whatever the validity.
	long := testPolicy(now, strings.Repeat("a", 64))
	long.ExpiresAt = now.Add(30 * time.Minute)
	outliving := testLease(long, strings.Repeat("b", 64))
	outliving.ExpiresAt = long.ExpiresAt.Add(2 * time.Second)
	if err := outliving.Validate(long, now); !errors.Is(err, ErrLeaseInvalid) {
		t.Fatalf("lease outliving its policy accepted: %v", err)
	}
}

func shortPolicy(generation string, validity time.Duration, transport string) (Policy, ProviderLease) {
	now := time.Now()
	policy := testPolicy(now, generation)
	policy.ExpiresAt = now.Add(validity)
	policy.Transport = transport
	lease := testLease(policy, strings.Repeat(generation[:1], 63)+"0")
	return policy, lease
}

func statusSnapshot(writer *StatusWriter) RuntimeStatus {
	writer.mu.Lock()
	defer writer.mu.Unlock()
	return writer.status
}

func waitFor(t *testing.T, timeout time.Duration, what string, condition func() bool) {
	t.Helper()
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if condition() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("timed out waiting for %s", what)
}

// A lapsed policy never closes the listeners (the runtime context stays alive),
// the status says policy_lapsed, the secrets are wiped, existing streams are
// cut by the limiter, and a new lease resumes service. Only a revocation
// issued after the runtime started tears it down.
func TestPolicyLapseKeepsRuntimeAndOnlyRevocationStopsIt(t *testing.T) {
	store := NewPolicyStore(testPolicyConfig())
	defer store.Revoke("")
	// A revocation from before this runtime started must not turn a later
	// lapse into a teardown.
	store.Revoke(strings.Repeat("9", 64))
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer status.Close()

	first, firstLease := shortPolicy(strings.Repeat("a", 64), 600*time.Millisecond, "tcp")
	if err := store.Replace(first, firstLease); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go watchRuntimePolicyStatus(ctx, cancel, store, status, "tcp")
	waitFor(t, time.Second, "active state", func() bool { return statusSnapshot(status).PolicyState == policyStateActive })

	waitFor(t, 3*time.Second, "lapsed state", func() bool { return statusSnapshot(status).PolicyState == policyStateLapsed })
	snapshot := statusSnapshot(status)
	if snapshot.ErrorCode != "policy_lapsed" || snapshot.PolicyExpiresAt != first.ExpiresAt.UTC().Format(time.RFC3339) {
		t.Fatalf("lapsed status = %+v", snapshot)
	}
	store.mu.RLock()
	wiped := store.policy == nil && store.lease == nil && len(store.candidates) == 0
	store.mu.RUnlock()
	if !wiped {
		t.Fatal("lease secrets survived the policy expiry")
	}
	if err := store.Limiter().Consume(1); !errors.Is(err, ErrPolicyStopped) {
		t.Fatalf("existing streams not cut at policy expiry: %v", err)
	}
	if _, ok := store.Snapshot(); ok {
		t.Fatal("a lapsed policy still yields a data-plane snapshot")
	}
	// Refused connections while lapsed must not bury the root cause.
	status.UpdateError("tcp_connect_failed")
	status.UpdateError("policy_stopped")
	if code := statusSnapshot(status).ErrorCode; code != "policy_lapsed" {
		t.Fatalf("error_code = %q while lapsed", code)
	}
	// Well past the old 90 s grace would be too slow for a unit test; the
	// point is that nothing but a revocation cancels. Give it a few ticks.
	select {
	case <-ctx.Done():
		t.Fatal("a policy lapse cancelled the runtime")
	case <-time.After(5 * runtimePolicyTick):
	}

	next, nextLease := shortPolicy(strings.Repeat("c", 64), time.Minute, "tcp")
	if err := store.Replace(next, nextLease); err != nil {
		t.Fatal(err)
	}
	waitFor(t, time.Second, "resumed state", func() bool {
		s := statusSnapshot(status)
		return s.PolicyState == policyStateActive && s.ErrorCode == "" && s.State == "ready"
	})
	if ctx.Err() != nil {
		t.Fatal("resume restarted the runtime")
	}

	store.Revoke(next.PolicyGeneration)
	select {
	case <-ctx.Done():
	case <-time.After(time.Second):
		t.Fatal("revocation did not stop the runtime")
	}
	if s := statusSnapshot(status); s.PolicyState != policyStateRevoked {
		t.Fatalf("revoked status = %+v", s)
	}
}

// The listener set depends on the authorized transport, so a transport change
// restarts the runtime even though a plain lapse does not.
func TestPolicyTransportChangeRestartsRuntime(t *testing.T) {
	store := NewPolicyStore(testPolicyConfig())
	defer store.Revoke("")
	first, firstLease := shortPolicy(strings.Repeat("a", 64), time.Minute, "tcp")
	if err := store.Replace(first, firstLease); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go watchRuntimePolicyStatus(ctx, cancel, store, nil, "tcp")
	next, nextLease := shortPolicy(strings.Repeat("c", 64), time.Minute, "tcp+udp")
	if err := store.Replace(next, nextLease); err != nil {
		t.Fatal(err)
	}
	select {
	case <-ctx.Done():
	case <-time.After(time.Second):
		t.Fatal("transport change did not restart the runtime")
	}
}

func TestLocationDoesNotOverrideLapsedStatus(t *testing.T) {
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer status.Close()
	status.UpdatePolicyState(policyStateLapsed, time.Now())
	status.UpdateLocation(LocationResult{Code: "shanghai", Source: "network"})
	if s := statusSnapshot(status); s.ErrorCode != "policy_lapsed" || s.State != "degraded" || s.DetectedIngressZone != "shanghai" {
		t.Fatalf("status = %+v", s)
	}
	status.UpdateError("runtime_stopped")
	if s := statusSnapshot(status); s.ErrorCode != "runtime_stopped" {
		t.Fatalf("lifecycle error hidden by lapse: %+v", s)
	}
}

func TestDefaultLocationPaths(t *testing.T) {
	cache, lock := defaultLocationPaths("")
	if cache != "/var/lib/ultra/location.json" || lock != "/run/ultra/location.lock" {
		t.Fatalf("no status path: %q %q", cache, lock)
	}
	directory := filepath.Join(t.TempDir(), "state")
	cache, lock = defaultLocationPaths(filepath.Join(directory, "forward-42.status.json"))
	if runtime.GOOS == "linux" {
		if cache != "/var/lib/ultra/location.json" || lock != "/run/ultra/location.lock" {
			t.Fatalf("linux layout changed: %q %q", cache, lock)
		}
		return
	}
	if cache != filepath.Join(directory, "location.json") || lock != filepath.Join(directory, "location.lock") {
		t.Fatalf("paths not beside the status file: %q %q", cache, lock)
	}
}

// freeLoopbackPort returns a loopback port that is free for both TCP and UDP.
// Windows reserves UDP port blocks that the TCP ephemeral allocator does not
// avoid (and the other way round), so both allocation orders are tried.
func freeLoopbackPort(t *testing.T) int {
	t.Helper()
	loopback := net.IPv4(127, 0, 0, 1)
	var last error
	for attempt := 0; attempt < 100; attempt++ {
		if attempt%2 == 0 {
			tcp, err := net.ListenTCP("tcp", &net.TCPAddr{IP: loopback})
			if err != nil {
				t.Fatal(err)
			}
			port := tcp.Addr().(*net.TCPAddr).Port
			udp, udpErr := net.ListenUDP("udp", &net.UDPAddr{IP: loopback, Port: port})
			tcp.Close()
			if udpErr == nil {
				udp.Close()
				return port
			}
			last = udpErr
			continue
		}
		udp, err := net.ListenUDP("udp", &net.UDPAddr{IP: loopback})
		if err != nil {
			t.Fatal(err)
		}
		port := udp.LocalAddr().(*net.UDPAddr).Port
		tcp, tcpErr := net.ListenTCP("tcp", &net.TCPAddr{IP: loopback, Port: port})
		udp.Close()
		if tcpErr == nil {
			tcp.Close()
			return port
		}
		last = tcpErr
	}
	t.Fatalf("no loopback port free for both TCP and UDP: %v", last)
	return 0
}

// End to end through runRuntimeLoop: the listener binds within 1 s of the
// lease even though the location lookup hangs; a lapse keeps the same
// listener bound while every connection and datagram fails closed; a new lease
// resumes service on that listener.
func TestRuntimeLoopBindsBeforeLocationAndSurvivesLapse(t *testing.T) {
	slow := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-r.Context().Done():
		case <-time.After(10 * time.Second):
		}
	}))
	defer slow.Close()
	previousEndpoint := locationEndpoint
	locationEndpoint = slow.URL
	defer func() { locationEndpoint = previousEndpoint }()

	port := freeLoopbackPort(t)
	listenAddress := net.JoinHostPort("127.0.0.1", strconv.Itoa(port))
	var binds atomic.Int32
	testHookTCPListening = func(listener net.Listener) {
		if listener.Addr().(*net.TCPAddr).Port == port {
			binds.Add(1)
		}
	}
	defer func() { testHookTCPListening = nil }()

	directory := t.TempDir()
	config := testPolicyConfig()
	config.ListenHost = "127.0.0.1"
	config.ListenPort = port
	config.ConnectTimeoutSecs = 1
	config.LocationCachePath = filepath.Join(directory, "location.json")
	config.LocationLockPath = filepath.Join(directory, "location.lock")
	store := NewPolicyStore(config)
	status := newTestStatusWriter(t, filepath.Join(directory, "status.json"), 42, time.Millisecond)
	defer status.Close()
	status.UpdatePolicyState(policyStateAwaiting, time.Time{})

	ctx, cancel := context.WithCancel(context.Background())
	stopped := make(chan struct{})
	go func() {
		defer close(stopped)
		runRuntimeLoop(ctx, config, store, status)
	}()
	defer func() {
		cancel()
		<-stopped
		store.Revoke("")
	}()

	if s := statusSnapshot(status); s.ErrorCode != "awaiting_policy" || s.PolicyState != policyStateAwaiting {
		t.Fatalf("status before the first policy = %+v", s)
	}
	refusedProvider := freeLoopbackPort(t)
	policy, lease := shortPolicy(strings.Repeat("a", 64), 1500*time.Millisecond, "tcp+udp")
	lease.IP, lease.Port = "127.0.0.1", refusedProvider
	delivered := time.Now()
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	var bound time.Duration
	for {
		connection, err := net.DialTimeout("tcp", listenAddress, 100*time.Millisecond)
		if err == nil {
			bound = time.Since(delivered)
			connection.Close()
			break
		}
		if time.Since(delivered) > 2*time.Second {
			t.Fatalf("listener not bound: %v", err)
		}
		time.Sleep(5 * time.Millisecond)
	}
	// The hanging lookup would hold the bind for at least locationRequestTimeout
	// (2.5 s); 1 s leaves a loaded runner room without hiding that.
	if bound > time.Second {
		t.Fatalf("listener bound %s after the lease; the location lookup blocked it", bound)
	}
	if s := statusSnapshot(status); s.LocationSource != "" {
		t.Fatalf("location finished before the slow lookup could: %+v", s)
	}

	waitFor(t, 4*time.Second, "policy lapse", func() bool { return statusSnapshot(status).PolicyState == policyStateLapsed })
	if code := statusSnapshot(status).ErrorCode; code != "policy_lapsed" {
		t.Fatalf("error_code = %q", code)
	}
	// Still bound, but every connection fails closed.
	connection, err := net.DialTimeout("tcp", listenAddress, time.Second)
	if err != nil {
		t.Fatalf("lapse closed the listener: %v", err)
	}
	_ = connection.SetReadDeadline(time.Now().Add(2 * time.Second))
	_, _ = connection.Write([]byte("request"))
	if n, readErr := connection.Read(make([]byte, 16)); n != 0 || readErr == nil || isTimeout(readErr) {
		t.Fatalf("lapsed connection was not closed: n=%d err=%v", n, readErr)
	}
	connection.Close()
	udp, err := net.Dial("udp", listenAddress)
	if err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 3; i++ {
		_, _ = udp.Write([]byte("datagram"))
	}
	udp.Close()
	time.Sleep(100 * time.Millisecond)
	if s := statusSnapshot(status); s.UDPActiveAssociations != 0 || s.UDPPendingAssociations != 0 || s.ErrorCode != "policy_lapsed" {
		t.Fatalf("lapsed datagrams were served: %+v", s)
	}

	next, nextLease := shortPolicy(strings.Repeat("c", 64), time.Minute, "tcp+udp")
	nextLease.IP, nextLease.Port = "127.0.0.1", refusedProvider
	if err := store.Replace(next, nextLease); err != nil {
		t.Fatal(err)
	}
	waitFor(t, time.Second, "resumed state", func() bool {
		s := statusSnapshot(status)
		return s.PolicyState == policyStateActive && s.ErrorCode == ""
	})
	connection, err = net.DialTimeout("tcp", listenAddress, time.Second)
	if err != nil {
		t.Fatalf("listener gone after resume: %v", err)
	}
	connection.Close()
	if got := binds.Load(); got != 1 {
		t.Fatalf("listener bound %d times; lapse or resume restarted it", got)
	}
}

func isTimeout(err error) bool {
	var netErr net.Error
	return errors.As(err, &netErr) && netErr.Timeout() && !errors.Is(err, io.EOF)
}
