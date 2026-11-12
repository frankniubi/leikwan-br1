package main

import (
	"context"
	"errors"
	"fmt"
	"net"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

// fourCandidateStore authorizes four distinct relay paths, each with its own
// candidate and lease hash, like a Panel batch with max_candidates=4.
func fourCandidateStore(t *testing.T) (*PolicyStore, Policy, []ProviderLease) {
	t.Helper()
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	leases := make([]ProviderLease, 4)
	for i := range leases {
		leases[i] = testLease(policy, strings.Repeat(string(rune('b'+i)), 64))
		leases[i].IP = fmt.Sprintf("203.0.113.%d", 21+i)
		leases[i].SelectionRef = fmt.Sprintf("selection-reference-path-%d", i)
		leases[i].CandidateKeyHash = strings.Repeat(string(rune('1'+i)), 64)
		leases[i].LeaseHash = strings.Repeat(string(rune('6'+i)), 64)
	}
	store := NewPolicyStore(testPolicyConfig())
	if err := store.ReplaceCandidates(policy, leases[0], leases); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { store.Revoke("") })
	return store, policy, leases
}

func candidateFeedbackOf(status *StatusWriter) []CandidateFeedback {
	status.mu.Lock()
	defer status.mu.Unlock()
	return append([]CandidateFeedback(nil), status.status.CandidateFeedback...)
}

// The 2026-11-12 OpenWrt outage: the hosts pass a 5 s connect timeout, and the
// even split gave each of four candidates 1.25 s. Relays that need 2 s for
// connect + greeting + reply (one lost SYN is 1 s by itself) all timed out,
// every path was isolated and streams failed for minutes. With staggered setup
// each attempt keeps a usable budget and the total stays bounded.
func TestCandidateSetupFourSlowCandidatesKeepUsableBudget(t *testing.T) {
	store, _, _ := fourCandidateStore(t)
	const handshake = 2 * time.Second
	var mu sync.Mutex
	budgets := map[string]time.Duration{}
	dial := func(lease ProviderLease, _ string, _ int, budget time.Duration) (net.Conn, error) {
		mu.Lock()
		budgets[lease.SelectionRef] = budget
		mu.Unlock()
		if budget < handshake {
			time.Sleep(budget)
			return nil, fmt.Errorf("stream handshake: %w", context.DeadlineExceeded)
		}
		time.Sleep(handshake)
		local, peer := net.Pipe()
		go func() { time.Sleep(5 * time.Second); peer.Close() }()
		return local, nil
	}
	started := time.Now()
	conn, selected, err := dialCandidateStream(context.Background(), store, "example.test", 443, 5*time.Second, dial)
	elapsed := time.Since(started)
	if err != nil {
		t.Fatalf("four slow but healthy candidates failed: %v (budgets=%v)", err, budgets)
	}
	defer conn.Close()
	defer selected.Lease.wipe()
	mu.Lock()
	first := budgets["selection-reference-path-0"]
	mu.Unlock()
	if first < 2500*time.Millisecond {
		t.Fatalf("first candidate budget=%s, want at least 2.5s", first)
	}
	if elapsed > 5*time.Second {
		t.Fatalf("setup took %s, beyond the 5s total", elapsed)
	}
	// The overtaken second attempt finishes later and succeeds: it must not be
	// recorded as a failure, so all four paths stay usable.
	time.Sleep(2 * time.Second)
	candidates := store.CandidateSnapshots("tcp")
	defer wipeSnapshots(candidates)
	if len(candidates) != 4 {
		t.Fatalf("eligible tcp paths=%d after a successful setup, want 4", len(candidates))
	}
}

// Every attempt of a blackholed pool still ends at the one total deadline.
func TestCandidateSetupTotalStaysBoundedWithStaggeredAttempts(t *testing.T) {
	store, _, _ := fourCandidateStore(t)
	var mu sync.Mutex
	calls := 0
	started := time.Now()
	_, _, err := dialCandidateStream(context.Background(), store, "example.test", 443, 1200*time.Millisecond,
		func(_ ProviderLease, _ string, _ int, budget time.Duration) (net.Conn, error) {
			mu.Lock()
			calls++
			mu.Unlock()
			if budget > 1200*time.Millisecond {
				t.Errorf("attempt budget %s exceeds the total", budget)
			}
			time.Sleep(budget)
			return nil, context.DeadlineExceeded
		})
	if err == nil {
		t.Fatal("blackholed pool succeeded")
	}
	if elapsed := time.Since(started); elapsed > 1600*time.Millisecond {
		t.Fatalf("blackholed setup took %s, total was 1.2s", elapsed)
	}
	mu.Lock()
	defer mu.Unlock()
	if calls < 2 {
		t.Fatalf("calls=%d: a running attempt held every sibling back", calls)
	}
}

// A blackholed first candidate must not cost every stream a full split share,
// and it must still be isolated and reported once its own budget expires even
// though a sibling won the race.
func TestCandidateSetupOvertakenBlackHoleIsIsolatedAndReported(t *testing.T) {
	store, _, leases := fourCandidateStore(t)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	dial := func(lease ProviderLease, _ string, _ int, budget time.Duration) (net.Conn, error) {
		if lease.SelectionRef == leases[0].SelectionRef {
			time.Sleep(budget)
			return nil, fmt.Errorf("stream handshake: %w", context.DeadlineExceeded)
		}
		local, peer := net.Pipe()
		go func() { time.Sleep(5 * time.Second); peer.Close() }()
		return local, nil
	}
	started := time.Now()
	conn, selected, err := dialCandidateStreamReporting(context.Background(), store, "tcp", "example.test", 443, 5*time.Second, dial, fixedTargetFailureFeedback(status, "tcp"))
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	defer selected.Lease.wipe()
	if elapsed := time.Since(started); elapsed > candidateStaggerMax+200*time.Millisecond {
		t.Fatalf("blackholed first candidate delayed setup by %s", elapsed)
	}
	if selected.Lease.SelectionRef != leases[1].SelectionRef {
		t.Fatalf("selected %q, want the second candidate", selected.Lease.SelectionRef)
	}
	waitFor(t, 5*time.Second, "overtaken attempt settled", func() bool {
		return len(candidateFeedbackOf(status)) > 0
	})
	feedback := candidateFeedbackOf(status)
	if len(feedback) != 1 || feedback[0].CandidateKeyHash != leases[0].CandidateKeyHash ||
		feedback[0].Reason != "relay_connect_timeout" || feedback[0].Protocol != "tcp" ||
		feedback[0].LeaseHash != leases[0].LeaseHash {
		t.Fatalf("feedback=%+v, want one relay_connect_timeout for the blackholed candidate", feedback)
	}
	candidates := store.CandidateSnapshots("tcp")
	defer wipeSnapshots(candidates)
	for _, candidate := range candidates {
		if candidate.Lease.SelectionRef == leases[0].SelectionRef {
			t.Fatal("blackholed candidate was not isolated after its attempt expired")
		}
	}
}

// The greeting fallback used to report "fallback=context deadline exceeded"
// when the first greeting had already used the whole attempt. The real first
// failure is what the logs and the Panel need.
func TestGreetingFallbackSkippedWithoutBudgetKeepsInitialError(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	var mu sync.Mutex
	accepted := 0
	go func() {
		for {
			conn, err := listener.Accept()
			if err != nil {
				return
			}
			mu.Lock()
			accepted++
			mu.Unlock()
			go func() { time.Sleep(2 * time.Second); conn.Close() }() // Never replies.
		}
	}()
	address := listener.Addr().(*net.TCPAddr)
	lease := ProviderLease{IP: address.IP.String(), Port: address.Port, User: []byte("user"), Password: []byte("password")}
	_, err = dialStreamWithGreetingOrder(lease, "example.test", 443, 300*time.Millisecond, handshakeGreetingOrder(lease))
	if err == nil {
		t.Fatal("silent relay accepted")
	}
	var fallback *greetingFallbackError
	if errors.As(err, &fallback) || strings.Contains(err.Error(), "fallback") {
		t.Fatalf("got a fake fallback failure: %v", err)
	}
	if haErrorClass(err) != "timeout" {
		t.Fatalf("class=%s err=%v, want the initial timeout", haErrorClass(err), err)
	}
	mu.Lock()
	defer mu.Unlock()
	if accepted != 1 {
		t.Fatalf("relay saw %d connections, want only the initial greeting", accepted)
	}
}

func TestFixedTargetFailureReasonVocabulary(t *testing.T) {
	refused := fmt.Errorf("stream handshake: %w", markHandshakePhase(errStreamAuthenticationRejected))
	for _, tc := range []struct {
		name     string
		protocol string
		err      error
		reason   string
		report   bool
	}{
		{"timeout", "tcp", fmt.Errorf("stream handshake: %w", context.DeadlineExceeded), "relay_connect_timeout", true},
		{"unreachable", "tcp", fmt.Errorf("%w: refused", errProviderDial), "relay_connect_timeout", true},
		{"credential", "tcp", &greetingFallbackError{initial: refused, fallback: context.DeadlineExceeded}, credentialRejectedReason, true},
		{"target_refused", "tcp", fmt.Errorf("stream handshake: %w", errStreamCommandRejected), "", false},
		{"canceled", "tcp", context.Canceled, "", false},
		{"expired", "tcp", ErrPolicyExpired, "", false},
		{"uot_unreachable", "udp", fmt.Errorf("%w: refused", errProviderDial), "udp_endpoint_unreachable", true},
		{"uot_handshake", "udp", fmt.Errorf("stream handshake: %w", context.DeadlineExceeded), "udp_arm_failed", true},
		{"uot_credential", "udp", refused, credentialRejectedReason, true},
	} {
		reason, report := fixedTargetFailureReason(tc.protocol, tc.err)
		if reason != tc.reason || report != tc.report {
			t.Errorf("%s: reason=%q report=%t, want %q %t", tc.name, reason, report, tc.reason, tc.report)
		}
	}
}

// Port-forward mode used to record only a local tcp_connect_failed when a relay
// failed the handshake; the Panel never learned which relay it was. serveTCP
// must now report each failed candidate the way the native gateway does.
func TestServeTCPSetupFailureProducesCandidateFeedback(t *testing.T) {
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	leases := make([]ProviderLease, 2)
	for i := range leases {
		leases[i] = testLease(policy, strings.Repeat(string(rune('b'+i)), 64))
		leases[i].SelectionRef = fmt.Sprintf("selection-reference-dead-%d", i)
		leases[i].CandidateKeyHash = strings.Repeat(string(rune('1'+i)), 64)
		leases[i].LeaseHash = strings.Repeat(string(rune('6'+i)), 64)
		// Accepts the relay connection and closes it without any reply.
		leases[i].IP, leases[i].Port = fakeStreamRelay(t, nil, time.Millisecond)
	}
	port := freeLoopbackPort(t)
	config := testPolicyConfig()
	config.ListenHost = "127.0.0.1"
	config.ListenPort = port
	config.ConnectTimeoutSecs = 5
	store := NewPolicyStore(config)
	if err := store.ReplaceCandidates(policy, leases[0], leases); err != nil {
		t.Fatal(err)
	}
	defer store.Revoke("")
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	ctx, cancel := context.WithCancel(context.Background())
	served := make(chan error, 1)
	go func() { served <- serveTCP(ctx, config, store, status) }()
	defer func() {
		cancel()
		<-served
	}()
	var local net.Conn
	waitFor(t, 3*time.Second, "port-forward listener", func() bool {
		conn, err := net.DialTimeout("tcp", net.JoinHostPort("127.0.0.1", strconv.Itoa(port)), 200*time.Millisecond)
		if err != nil {
			return false
		}
		local = conn
		return true
	})
	defer local.Close()
	waitFor(t, 6*time.Second, "candidate feedback", func() bool {
		return len(candidateFeedbackOf(status)) >= 2
	})
	seen := map[string]CandidateFeedback{}
	for _, feedback := range candidateFeedbackOf(status) {
		seen[feedback.CandidateKeyHash] = feedback
	}
	for _, lease := range leases {
		feedback, ok := seen[lease.CandidateKeyHash]
		if !ok || feedback.Protocol != "tcp" || feedback.Outcome != "failure" ||
			feedback.Reason != "relay_connect_timeout" || feedback.LeaseHash != lease.LeaseHash || feedback.EvidenceID == "" {
			t.Fatalf("feedback for %s = %+v (all=%+v)", lease.SelectionRef, feedback, candidateFeedbackOf(status))
		}
	}
	// The setup keeps its one stream-level HA event; candidate evidence goes to
	// candidate_feedback only, so ha_failures does not count each relay twice.
	if s := statusSnapshot(status); s.ErrorCode != "tcp_connect_failed" || s.HAFailures > 1 {
		t.Fatalf("status error=%q ha_failures=%d, want tcp_connect_failed and at most one setup event", s.ErrorCode, s.HAFailures)
	}
}
