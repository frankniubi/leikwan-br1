package main

import (
	"bytes"
	"context"
	"io"
	"path/filepath"
	"testing"
	"time"
)

func TestHATCPObservedOrderDoesNotDependOnClockResolution(t *testing.T) {
	now := time.Now()
	for _, tc := range []struct {
		name     string
		up, down uint64
		want     bool
	}{
		{"remote_first_equal_timestamps", 2, 1, true},
		{"local_first_equal_timestamps", 1, 2, false},
		{"ambiguous_no_ordinals", 0, 0, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			order := relayStreamOrder{upstreamEnded: now, downstreamEnded: now,
				upstreamOrder: tc.up, downstreamOrder: tc.down}
			if got := order.remoteStoppedFirst(); got != tc.want {
				t.Fatalf("remoteStoppedFirst=%t, want %t", got, tc.want)
			}
		})
	}
}

// Exercise kernel-generated resets rather than injecting ECONNRESET into the
// scoring function: the provider receives a complete request, then aborts.
func TestHATCPLoopbackProviderResetsAvoidOnlyLocalTCPPath(t *testing.T) {
	store, policy, leases := haStore(t)
	siblingStore, _, _ := haStore(t)
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer status.Close()
	limiter := NewPolicyLimiter()
	if err := limiter.Replace(0, 1<<20, time.Now().Add(time.Minute), "loopback-reset"); err != nil {
		t.Fatal(err)
	}
	ident := relayIdentity{
		forward: 42, generation: policy.PolicyGeneration, selection: leases[0].SelectionRef,
		pathKey: haPathKey(leases[0]), candidateKey: leases[0].CandidateKeyHash, leaseHash: leases[0].LeaseHash,
	}
	request := []byte("controlled-request-before-provider-reset")
	for attempt := 1; attempt <= 2; attempt++ {
		client, local := tcpPair(t)
		remote, provider := tcpPair(t)
		ctx, cancel := context.WithCancel(context.Background())
		done := make(chan struct{})
		go func() {
			relayTCP(ctx, local, remote, limiter, status, store, ident)
			close(done)
		}()
		t.Cleanup(cancel)
		if _, err := client.Write(request); err != nil {
			t.Fatal(err)
		}
		got := make([]byte, len(request))
		if _, err := io.ReadFull(provider, got); err != nil {
			t.Fatalf("attempt %d: request did not reach provider: %v", attempt, err)
		}
		if !bytes.Equal(xorOne(got), request) {
			t.Fatalf("attempt %d: upstream payload mismatch", attempt)
		}
		if err := provider.SetLinger(0); err != nil {
			t.Fatal(err)
		}
		if err := provider.Close(); err != nil {
			t.Fatal(err)
		}
		select {
		case <-done:
		case <-time.After(2 * time.Second):
			cancel()
			<-done
			t.Fatal("provider reset did not terminate relay promptly")
		}
		cancel()
		store.mu.RLock()
		strikes := store.pathFailures["tcp:"+ident.pathKey]
		isolated := store.isolated["tcp:"+ident.pathKey].After(time.Now())
		store.mu.RUnlock()
		if strikes != attempt || isolated != (attempt == 2) {
			t.Fatalf("attempt %d: strikes=%d isolated=%v", attempt, strikes, isolated)
		}
	}
	tcpCandidates := store.CandidateSnapshots("tcp")
	defer wipeSnapshots(tcpCandidates)
	udpCandidates := store.CandidateSnapshots("udp")
	defer wipeSnapshots(udpCandidates)
	if len(tcpCandidates) != 1 || haPathKey(tcpCandidates[0].Lease) != haPathKey(leases[1]) {
		t.Fatal("new TCP streams did not avoid the resetting provider")
	}
	if len(udpCandidates) != 2 {
		t.Fatal("TCP resets affected UDP candidates")
	}
	siblingCandidates := siblingStore.CandidateSnapshots("tcp")
	defer wipeSnapshots(siblingCandidates)
	if len(siblingCandidates) != 2 {
		t.Fatal("one forward's resets affected an independent runtime")
	}
	status.mu.Lock()
	defer status.mu.Unlock()
	if len(status.status.CandidateFeedback) != 0 {
		t.Fatal("target-specific TCP resets produced global candidate feedback")
	}
}

// A real local abort can surface as ECONNRESET or EPIPE on the later write,
// depending on whether the socket's read side consumed the pending reset.
// Synchronize by reading the kernel's reset first, not by sleeping and hoping
// the reset has arrived. Then drive the real inbound copier and its health
// observation: a reply was received even though the local application rejects it.
func TestHATCPLoopbackLocalResetAfterReplyDoesNotAvoidPath(t *testing.T) {
	store, policy, leases := haStore(t)
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer status.Close()
	limiter := NewPolicyLimiter()
	if err := limiter.Replace(0, 1<<20, time.Now().Add(time.Minute), "loopback-local-reset"); err != nil {
		t.Fatal(err)
	}
	ident := relayIdentity{forward: 42, generation: policy.PolicyGeneration, pathKey: haPathKey(leases[0])}
	for attempt := 0; attempt < 3; attempt++ {
		client, local := tcpPair(t)
		remote, provider := tcpPair(t)
		if err := client.SetLinger(0); err != nil {
			t.Fatal(err)
		}
		if err := client.Close(); err != nil {
			t.Fatal(err)
		}
		var single [1]byte
		if _, err := local.Read(single[:]); !isTCPReset(err) {
			t.Fatalf("local peer abort did not produce a kernel reset: %v", err)
		}
		reply := []byte("provider-replied-before-local-write-failed")
		if _, err := provider.Write(reply); err != nil {
			t.Fatal(err)
		}
		observed := copyInboundObserved(local, remote, limiter, status)
		if observed.ReadBytes != int64(len(reply)) || observed.WriteError == nil || observed.ReadError != nil {
			t.Fatalf("local write failure lost remote reply evidence: %+v", observed)
		}
		t.Logf("actual local write error after kernel reset: %v", observed.WriteError)
		ended := time.Now()
		// Even an apparently remote-first scheduling order must not score a
		// local write failure or discard the remote bytes already read.
		noteRelayOutcome(store, status, ident, ended, 7, observed.ReadBytes, relayStreamOrder{
			downstreamEnded: ended, upstreamEnded: ended.Add(time.Millisecond), remoteReadError: observed.ReadError,
		})
	}
	store.mu.RLock()
	strikes := store.pathFailures["tcp:"+ident.pathKey]
	isolated := store.isolated["tcp:"+ident.pathKey].After(time.Now())
	store.mu.RUnlock()
	if strikes != 0 || isolated {
		t.Fatalf("local write failures penalized provider: strikes=%d isolated=%v", strikes, isolated)
	}
	status.mu.Lock()
	defer status.mu.Unlock()
	if len(status.status.CandidateFeedback) != 0 {
		t.Fatal("local write failures produced global candidate feedback")
	}
}
