package main

import (
	"context"
	"io"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// TestLocalAbortIsNotEvidenceAboutTheRelay reproduces the 2026-09-14
// experiment: a fake provider that stays open, a local client that writes a
// request and immediately hangs up, and a runtime that quarantined the
// perfectly healthy path because the finished stream showed upstream bytes,
// zero downstream bytes and a lifetime inside haDeadStreamMaxAge.
//
// The 2026-09-14 experiment logged:
//
//	tcp stream ended ... direction=upstream   reason=eof      duration_ms=2  bytes=33
//	tcp stream ended ... direction=downstream reason=io_error duration_ms=60 bytes=0
//	tcp candidate quarantined ... reason=no_downstream_bytes lifetime_ms=62 upstream_bytes=33
//
// This test drives the same shape through relayTCP. Before the ordering signal
// existed the first abort already failed it with "attempt 1 counted a relay
// strike for a client-side abort (streak=1)", and the second abort crossed
// pathIsolationMinStrikes and quarantined the healthy path.
//
// A stream the LOCAL side gave up on says nothing about the relay: the relay
// was never asked to answer, and the zero-byte downstream read is a
// consequence of our own hang-up. The local abort must therefore leave both
// the isolation table and the escalation streak untouched.
func TestLocalAbortIsNotEvidenceAboutTheRelay(t *testing.T) {
	now := time.Now()
	policy := testPolicy(now, strings.Repeat("a", 64))
	lease := testLease(policy, strings.Repeat("b", 64))
	lease.CandidateKeyHash = strings.Repeat("c", 64)
	lease.LeaseHash = strings.Repeat("d", 64)
	store := NewPolicyStore(testPolicyConfig())
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	st := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer st.Close()
	limiter := NewPolicyLimiter()
	if err := limiter.Replace(20, 1024, time.Now().Add(time.Minute), "test"); err != nil {
		t.Fatal(err)
	}
	ident := relayIdentity{
		forward: 42, generation: policy.PolicyGeneration, selection: lease.SelectionRef,
		pathKey: haPathKey(lease), candidateKey: lease.CandidateKeyHash, leaseHash: lease.LeaseHash,
	}
	request := []byte("GET /health HTTP/1.1\r\nHost: example.test\r\n\r\n")
	// Two aborts are exactly pathIsolationMinStrikes, so the old behaviour
	// turns into an isolation the path does not deserve; the third attempt
	// also makes the difference visible in the escalating streak.
	for attempt := 0; attempt < 3; attempt++ {
		client, local := tcpPair(t)
		remote, provider := tcpPair(t)
		relayDone := make(chan struct{})
		go func() {
			relayTCP(context.Background(), local, remote, limiter, st, store, ident)
			close(relayDone)
		}()
		if _, err := client.Write(request); err != nil {
			t.Fatal(err)
		}
		// The provider is healthy: it takes the request and has simply not
		// answered yet.
		got := make([]byte, len(request))
		if _, err := io.ReadFull(provider, got); err != nil {
			t.Fatalf("attempt %d: provider never received the request: %v", attempt+1, err)
		}
		if string(xorOne(got)) != string(request) {
			t.Fatalf("attempt %d: provider received %q", attempt+1, xorOne(got))
		}
		// The local side gives up while the provider is still open.
		client.Close()
		// The relay propagates the half-close to the provider; the provider
		// stays open after it, so the downstream read cannot end before the
		// upstream one did.
		if trailing, err := io.ReadAll(provider); err != nil {
			t.Fatalf("attempt %d: provider read after the local abort: %v", attempt+1, err)
		} else if len(trailing) != 0 {
			t.Fatalf("attempt %d: provider received %d unexpected bytes", attempt+1, len(trailing))
		}
		time.Sleep(150 * time.Millisecond)
		provider.Close()
		select {
		case <-relayDone:
		case <-time.After(3 * time.Second):
			t.Fatal("relay did not finish after the provider closed")
		}
		store.mu.RLock()
		streak := store.pathFailures["tcp:"+ident.pathKey]
		store.mu.RUnlock()
		if streak != 0 {
			t.Fatalf("attempt %d counted a relay strike for a client-side abort (streak=%d)", attempt+1, streak)
		}
	}
	if remaining, isolated := isPathIsolated(store, "tcp", ident.pathKey); isolated {
		t.Fatalf("client-side aborts quarantined a healthy path for %s", remaining)
	}
}
