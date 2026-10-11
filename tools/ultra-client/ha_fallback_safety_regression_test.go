package main

import (
	"context"
	"errors"
	"io"
	"net"
	"strings"
	"testing"
	"time"
)

func isolatedFallbackRegressionStore(t *testing.T) (*PolicyStore, Policy, ProviderLease) {
	t.Helper()
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	lease := testLease(policy, strings.Repeat("b", 64))
	store := NewPolicyStore(testPolicyConfig())
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { store.Revoke("") })
	key := haPathKey(lease)
	store.FailSilentPath("tcp", key, policy.PolicyGeneration)
	if !store.FailSilentPath("tcp", key, policy.PolicyGeneration) {
		t.Fatal("second suspicious failure did not isolate the sole path")
	}
	return store, policy, lease
}

func TestHAFallbackPreservesBusinessFailureIsolationWithoutReply(t *testing.T) {
	store, _, lease := isolatedFallbackRegressionStore(t)
	called := false
	conn, selected, err := dialCandidateStream(context.Background(), store, "owned.test", 443, time.Second,
		func(ProviderLease, string, int, time.Duration) (net.Conn, error) {
			called = true
			local, peer := net.Pipe()
			peer.Close()
			return local, nil // Handshake only; absolutely no business reply.
		})
	if conn != nil {
		conn.Close()
	}
	selected.Lease.wipe()
	if called || conn != nil || !errors.Is(err, errHACandidatesUnavailable) {
		t.Fatalf("business-isolated path bypassed: called=%t conn=%t err=%v", called, conn != nil, err)
	}
	store.mu.RLock()
	stillIsolated := store.isolated["tcp:"+haPathKey(lease)].After(time.Now())
	store.mu.RUnlock()
	if !stillIsolated {
		t.Fatal("FAIL: active fallback cleared business-failure isolation without any remote payload")
	}
}

func TestHAFallbackRechecksRevocationAfterDial(t *testing.T) {
	store, policy, lease := isolatedFallbackRegressionStore(t)
	// Probe-only isolation must still exercise the fallback. Blocking the
	// business-isolated path alone must not make this authorization test pass.
	store.ClearPathFailure("tcp", haPathKey(lease))
	store.FailCandidate("tcp", lease)
	called := false
	var peer net.Conn
	conn, selected, err := dialCandidateStream(context.Background(), store, "owned.test", 443, time.Second,
		func(ProviderLease, string, int, time.Duration) (net.Conn, error) {
			called = true
			store.Revoke(policy.PolicyGeneration)
			var local net.Conn
			local, peer = net.Pipe()
			return local, nil
		})
	if peer != nil {
		defer peer.Close()
	}
	if conn != nil {
		conn.Close()
	}
	selected.Lease.wipe()
	if !called || !errors.Is(err, ErrPolicyExpired) || conn != nil {
		t.Fatalf("FAIL: fallback returned a revoked connection: connection=%t err=%v", conn != nil, err)
	}
	peer.SetReadDeadline(time.Now().Add(time.Second))
	if _, readErr := peer.Read(make([]byte, 1)); !errors.Is(readErr, io.EOF) {
		t.Fatalf("revoked connection not closed: %v", readErr)
	}
}

func TestHAFallbackCancellationClosesLateDial(t *testing.T) {
	store, _, lease := isolatedFallbackRegressionStore(t)
	store.ClearPathFailure("tcp", haPathKey(lease))
	store.FailCandidate("tcp", lease)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	entered, release := make(chan struct{}), make(chan struct{})
	peerReady := make(chan net.Conn, 1)
	done := make(chan error, 1)
	go func() {
		conn, selected, err := dialCandidateStream(ctx, store, "owned.test", 443, time.Second,
			func(ProviderLease, string, int, time.Duration) (net.Conn, error) {
				close(entered)
				<-release // Simulate a dialer that ignores its timeout.
				local, peer := net.Pipe()
				peerReady <- peer
				return local, nil
			})
		if conn != nil {
			conn.Close()
		}
		selected.Lease.wipe()
		done <- err
	}()
	select {
	case <-entered:
	case <-time.After(2 * time.Second):
		close(release)
		t.Fatal("fallback was not attempted")
	}
	cancel()
	select {
	case err := <-done:
		if !errors.Is(err, context.Canceled) {
			close(release)
			t.Fatalf("got %v, want cancellation", err)
		}
	case <-time.After(2 * time.Second):
		close(release)
		t.Fatal("fallback blocked cancellation waiting for its dialer")
	}
	close(release)
	peer := <-peerReady
	defer peer.Close()
	peer.SetReadDeadline(time.Now().Add(time.Second))
	if _, err := peer.Read(make([]byte, 1)); !errors.Is(err, io.EOF) {
		t.Fatalf("late connection was not closed: %v", err)
	}
}

func TestHAFallbackBusinessIsolationExpiresWithoutPermanentLockout(t *testing.T) {
	store, _, lease := isolatedFallbackRegressionStore(t)
	store.mu.Lock()
	store.isolated["tcp:"+haPathKey(lease)] = time.Now().Add(-time.Second)
	store.mu.Unlock()
	called := false
	conn, selected, err := dialCandidateStream(context.Background(), store, "owned.test", 443, time.Second,
		func(ProviderLease, string, int, time.Duration) (net.Conn, error) {
			called = true
			local, peer := net.Pipe()
			peer.Close()
			return local, nil
		})
	if conn != nil {
		conn.Close()
	}
	selected.Lease.wipe()
	if !called || err != nil {
		t.Fatalf("expired isolation blocked recovery: called=%t err=%v", called, err)
	}
}

func TestHAFallbackTCPOutagePreservesUDPFailureStreak(t *testing.T) {
	store, first, second := twoPathCandidateStore(t)
	key := haPathKey(first)
	store.FailSilentPath("udp", key, first.PolicyGeneration)
	store.FailPath("tcp", key, first.PolicyGeneration)
	store.FailPath("tcp", haPathKey(second), second.PolicyGeneration)
	store.mu.RLock()
	streak := store.pathFailures["udp:"+key]
	store.mu.RUnlock()
	if streak != 1 {
		t.Fatalf("TCP outage reset unrelated UDP failure streak: %d", streak)
	}
}
