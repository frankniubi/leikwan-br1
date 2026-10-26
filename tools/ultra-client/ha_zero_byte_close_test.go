package main

import (
	"context"
	"io"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// zeroByteOrder is what relayTCP observes when the relay accepted the stream,
// took the first flight and then closed cleanly without returning a byte.
func zeroByteOrder() relayStreamOrder {
	now := time.Now()
	return relayStreamOrder{upstreamEnded: now.Add(time.Millisecond), downstreamEnded: now,
		upstreamOrder: 2, downstreamOrder: 1, remoteReadError: io.EOF}
}

func zeroByteIdent(lease ProviderLease, target string) relayIdentity {
	return relayIdentity{
		forward: 263, generation: lease.PolicyGeneration, selection: lease.SelectionRef,
		pathKey: haPathKey(lease), candidateKey: lease.CandidateKeyHash, leaseHash: lease.LeaseHash,
		targetHash: tcpTargetHash(target),
	}
}

func tcpCandidateKeys(store *PolicyStore) []string {
	candidates := store.CandidateSnapshots("tcp")
	defer wipeSnapshots(candidates)
	keys := make([]string, 0, len(candidates))
	for _, candidate := range candidates {
		keys = append(keys, haPathKey(candidate.Lease))
	}
	return keys
}

// IKK-785135: a relay that lost its upstream ACKed every stream, took the first
// flight and closed ~10ms later with zero bytes. The runtime kept it active for
// three minutes, until the next policy generation. A short run of such closes
// must now move new streams to the healthy sibling.
func TestZeroByteClosesIsolateThePathAndMoveNewStreams(t *testing.T) {
	store, first, second := twoPathCandidateStore(t)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 263, time.Millisecond)
	defer status.Close()
	ident := zeroByteIdent(first, "boilhkt99u.imgamer.top:10016")
	for stream := 1; stream <= zeroByteCloseStrikes; stream++ {
		noteRelayOutcome(store, status, ident, time.Now(), 517, 0, zeroByteOrder())
		_, isolated := isPathIsolated(store, "tcp", ident.pathKey)
		if isolated != (stream == zeroByteCloseStrikes) {
			t.Fatalf("stream %d: isolated=%v", stream, isolated)
		}
	}
	keys := tcpCandidateKeys(store)
	if len(keys) != 1 || keys[0] != haPathKey(second) {
		t.Fatal("new TCP streams were not moved to the healthy sibling")
	}
	if !store.businessPathIsIsolated("tcp", ident.pathKey) {
		t.Fatal("zero-byte isolation must refuse the active-path rescue")
	}
	if remaining, _ := isPathIsolated(store, "tcp", ident.pathKey); remaining > pathIsolationWindows[0]+time.Second {
		t.Fatalf("first zero-byte isolation lasts %s, want the first window", remaining)
	}
	if store.isolated["udp:"+ident.pathKey].After(time.Now()) {
		t.Fatal("TCP zero-byte closes isolated UDP")
	}
	status.mu.Lock()
	defer status.mu.Unlock()
	if len(status.status.CandidateFeedback) != 0 {
		t.Fatal("zero-byte closes produced global candidate feedback")
	}
	last := status.status.HAEvents[len(status.status.HAEvents)-1]
	if last.LocalPathAvoided == nil || !*last.LocalPathAvoided || last.RemoteReadReason != "eof" {
		t.Fatalf("status did not record the avoided path: %+v", last)
	}
}

// Isolating the only path cannot move anything; it would only fail the next
// streams closed.
func TestZeroByteClosesNeedAnAlternativePath(t *testing.T) {
	now := time.Now()
	policy := testPolicy(now, strings.Repeat("a", 64))
	lease := testLease(policy, strings.Repeat("b", 64))
	store := NewPolicyStore(testPolicyConfig())
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	ident := zeroByteIdent(lease, "203.0.113.50:443")
	for range zeroByteCloseStrikes * 2 {
		noteRelayOutcome(store, nil, ident, time.Now(), 64, 0, zeroByteOrder())
	}
	if _, isolated := isPathIsolated(store, "tcp", ident.pathKey); isolated {
		t.Fatal("the sole authorized path was isolated")
	}
}

// When the alternative path shows the same zero-byte closes for the same target,
// the target is closing the streams: neither relay may stay isolated, and the
// pattern must not bounce traffic between healthy relays.
func TestZeroByteClosesOnBothPathsAreTargetSide(t *testing.T) {
	store, first, second := twoPathCandidateStore(t)
	target := "203.0.113.50:443"
	a, b := zeroByteIdent(first, target), zeroByteIdent(second, target)
	for range zeroByteCloseStrikes {
		noteRelayOutcome(store, nil, a, time.Now(), 64, 0, zeroByteOrder())
	}
	if _, isolated := isPathIsolated(store, "tcp", a.pathKey); !isolated {
		t.Fatal("first path was not isolated")
	}
	for range zeroByteCloseStrikes {
		noteRelayOutcome(store, nil, b, time.Now(), 64, 0, zeroByteOrder())
	}
	for name, key := range map[string]string{"first": a.pathKey, "second": b.pathKey} {
		if _, isolated := isPathIsolated(store, "tcp", key); isolated {
			t.Fatalf("%s path stayed isolated for a target-side close", name)
		}
	}
	for range zeroByteCloseStrikes * 2 {
		noteRelayOutcome(store, nil, a, time.Now(), 64, 0, zeroByteOrder())
	}
	if _, isolated := isPathIsolated(store, "tcp", a.pathKey); isolated {
		t.Fatal("zero-byte isolation resumed during the target-side hold")
	}
	if len(tcpCandidateKeys(store)) != 2 {
		t.Fatal("both paths must stay eligible")
	}
}

// Closes spread over different targets are one bad target each, not a dead
// relay; a stream that read anything, came slowly, or ended locally first is
// not a zero-byte close at all.
func TestZeroByteCloseRequiresTheExactSignal(t *testing.T) {
	now := time.Now()
	cases := map[string]func(*PolicyStore, ProviderLease){
		"different_targets": func(store *PolicyStore, lease ProviderLease) {
			for index := range zeroByteCloseStrikes * 2 {
				target := "203.0.113." + string(rune('a'+index)) + ":443"
				noteRelayOutcome(store, nil, zeroByteIdent(lease, target), time.Now(), 64, 0, zeroByteOrder())
				noteRelayOutcome(store, nil, zeroByteIdent(lease, target), time.Now(), 64, 0, zeroByteOrder())
			}
		},
		"read_bytes": func(store *PolicyStore, lease ProviderLease) {
			order := zeroByteOrder()
			order.remoteReadBytes = 10
			for range zeroByteCloseStrikes * 2 {
				noteRelayOutcome(store, nil, zeroByteIdent(lease, "t:1"), time.Now(), 64, 0, order)
			}
		},
		"slow": func(store *PolicyStore, lease ProviderLease) {
			for range zeroByteCloseStrikes * 2 {
				noteRelayOutcome(store, nil, zeroByteIdent(lease, "t:1"), time.Now().Add(-(haZeroByteCloseMaxAge + 500*time.Millisecond)), 64, 0, zeroByteOrder())
			}
		},
		"local_first": func(store *PolicyStore, lease ProviderLease) {
			order := relayStreamOrder{upstreamEnded: now, downstreamEnded: now.Add(time.Millisecond),
				upstreamOrder: 1, downstreamOrder: 2, remoteReadError: io.EOF}
			for range zeroByteCloseStrikes * 2 {
				noteRelayOutcome(store, nil, zeroByteIdent(lease, "t:1"), time.Now(), 64, 0, order)
			}
		},
		"nothing_sent": func(store *PolicyStore, lease ProviderLease) {
			for range zeroByteCloseStrikes * 2 {
				noteRelayOutcome(store, nil, zeroByteIdent(lease, "t:1"), time.Now(), 0, 0, zeroByteOrder())
			}
		},
	}
	for name, drive := range cases {
		t.Run(name, func(t *testing.T) {
			store, first, _ := twoPathCandidateStore(t)
			drive(store, first)
			if _, isolated := isPathIsolated(store, "tcp", haPathKey(first)); isolated {
				t.Fatal("path was isolated without the zero-byte close signal")
			}
		})
	}
}

// A long download opened while the relay was healthy can finish after the relay
// began closing every new stream. Its bytes say nothing about the path now.
func TestStreamStartedBeforeZeroByteTripDoesNotClearIt(t *testing.T) {
	store, first, _ := twoPathCandidateStore(t)
	ident := zeroByteIdent(first, "203.0.113.50:443")
	oldStream := time.Now().Add(-time.Minute)
	for range zeroByteCloseStrikes {
		noteRelayOutcome(store, nil, ident, time.Now(), 64, 0, zeroByteOrder())
	}
	noteRelayOutcome(store, nil, ident, oldStream, 64, 1<<20)
	if _, isolated := isPathIsolated(store, "tcp", ident.pathKey); !isolated {
		t.Fatal("a stream older than the isolation cleared it")
	}
	noteRelayOutcome(store, nil, ident, time.Now(), 64, 512)
	if _, isolated := isPathIsolated(store, "tcp", ident.pathKey); isolated {
		t.Fatal("a fresh stream that carried data did not clear the isolation")
	}
}

// A relay that is still dead when its isolation ends must be held off longer.
func TestZeroByteIsolationEscalates(t *testing.T) {
	store, first, _ := twoPathCandidateStore(t)
	ident := zeroByteIdent(first, "203.0.113.50:443")
	windows := make([]time.Duration, 0, 3)
	for round := 0; round < 3; round++ {
		for range zeroByteCloseStrikes {
			noteRelayOutcome(store, nil, ident, time.Now(), 64, 0, zeroByteOrder())
		}
		remaining, isolated := isPathIsolated(store, "tcp", ident.pathKey)
		if !isolated {
			t.Fatalf("round %d did not isolate", round)
		}
		windows = append(windows, remaining)
		store.mu.Lock()
		store.isolated["tcp:"+ident.pathKey] = time.Now().Add(-time.Millisecond)
		store.mu.Unlock()
	}
	if !(windows[1] > windows[0] && windows[2] > windows[1]) {
		t.Fatalf("zero-byte isolation did not escalate: %v", windows)
	}
}

// End to end over kernel sockets: the provider reads the first flight and sends
// FIN. The relay must classify that as a zero-byte close and, after the strike
// count, steer new streams to the sibling.
func TestHATCPLoopbackProviderFinAfterFirstFlightAvoidsPath(t *testing.T) {
	store, policy, leases := haStore(t)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer status.Close()
	limiter := NewPolicyLimiter()
	if err := limiter.Replace(0, 1<<20, time.Now().Add(time.Minute), "loopback-zero-byte"); err != nil {
		t.Fatal(err)
	}
	ident := relayIdentity{
		forward: 42, generation: policy.PolicyGeneration, selection: leases[0].SelectionRef,
		pathKey: haPathKey(leases[0]), candidateKey: leases[0].CandidateKeyHash, leaseHash: leases[0].LeaseHash,
		targetHash: tcpTargetHash("203.0.113.50:443"),
	}
	request := []byte("first-flight-the-dead-relay-swallows")
	for attempt := 1; attempt <= zeroByteCloseStrikes; attempt++ {
		client, local := tcpPair(t)
		remote, provider := tcpPair(t)
		ctx, cancel := context.WithCancel(context.Background())
		done := make(chan struct{})
		go func() {
			relayTCP(ctx, local, remote, limiter, status, store, ident)
			close(done)
		}()
		if _, err := client.Write(request); err != nil {
			t.Fatal(err)
		}
		if _, err := io.ReadFull(provider, make([]byte, len(request))); err != nil {
			t.Fatalf("attempt %d: request did not reach provider: %v", attempt, err)
		}
		if err := provider.Close(); err != nil {
			t.Fatal(err)
		}
		// The local client sees the relay's FIN and hangs up, as an application
		// whose request went unanswered does.
		if n, err := client.Read(make([]byte, 1)); n != 0 || err != io.EOF {
			t.Fatalf("attempt %d: client read n=%d err=%v, want EOF", attempt, n, err)
		}
		client.Close()
		select {
		case <-done:
		case <-time.After(2 * time.Second):
			cancel()
			<-done
			t.Fatal("relay did not finish after the provider FIN")
		}
		cancel()
		_, isolated := isPathIsolated(store, "tcp", ident.pathKey)
		if isolated != (attempt == zeroByteCloseStrikes) {
			t.Fatalf("attempt %d: isolated=%v", attempt, isolated)
		}
	}
	keys := tcpCandidateKeys(store)
	if len(keys) != 1 || keys[0] != haPathKey(leases[1]) {
		t.Fatal("new TCP streams did not avoid the relay that closes with zero bytes")
	}
}
