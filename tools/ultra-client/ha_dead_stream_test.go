package main

import (
	"strings"
	"testing"
	"time"
)

// twoPathCandidateStore installs a policy with two candidates whose endpoints
// differ, so path-level health (endpoint+user) can be told apart.
func twoPathCandidateStore(t *testing.T) (*PolicyStore, ProviderLease, ProviderLease) {
	t.Helper()
	now := time.Now()
	policy := testPolicy(now, strings.Repeat("a", 64))
	first := testLease(policy, strings.Repeat("b", 64))
	first.CandidateKeyHash = strings.Repeat("c", 64)
	first.LeaseHash = strings.Repeat("d", 64)
	second := testLease(policy, strings.Repeat("e", 64))
	second.IP = "203.0.113.21"
	second.Port = 5439
	second.SelectionRef = "selection-reference-second-abcdefghijkl"
	second.CandidateKeyHash = strings.Repeat("f", 64)
	second.LeaseHash = strings.Repeat("1", 64)
	store := NewPolicyStore(testPolicyConfig())
	if err := store.ReplaceBatch(policy, first, []ProviderLease{first, second}); err != nil {
		t.Fatalf("replace batch: %v", err)
	}
	if haPathKey(first) == haPathKey(second) {
		t.Fatal("test candidates share a path key")
	}
	return store, first, second
}

func isPathIsolated(store *PolicyStore, protocol, key string) (time.Duration, bool) {
	store.mu.RLock()
	defer store.mu.RUnlock()
	until, ok := store.isolated[protocol+":"+key]
	if !ok {
		return 0, false
	}
	return time.Until(until), true
}

// A relay that accepts the connection and returns nothing must be quarantined
// quickly: two silent streams, not "whenever the next lease lands elsewhere".
func TestSilentStreamsQuarantineThePath(t *testing.T) {
	store, first, _ := twoPathCandidateStore(t)
	ident := relayIdentity{
		forward: 42, generation: first.PolicyGeneration, selection: first.SelectionRef,
		pathKey: haPathKey(first), candidateKey: first.CandidateKeyHash, leaseHash: first.LeaseHash,
	}
	started := time.Now()
	noteRelayOutcome(store, nil, ident, started, 64, 0)
	if _, isolated := isPathIsolated(store, "tcp", ident.pathKey); isolated {
		t.Fatal("a single silent stream isolated the path")
	}
	noteRelayOutcome(store, nil, ident, started, 64, 0)
	remaining, isolated := isPathIsolated(store, "tcp", ident.pathKey)
	if !isolated || remaining < 10*time.Second {
		t.Fatalf("two silent streams did not isolate the path (isolated=%v remaining=%s)", isolated, remaining)
	}

	candidates := store.CandidateSnapshots("tcp")
	defer wipeSnapshots(candidates)
	if len(candidates) == 0 {
		t.Fatal("the healthy sibling disappeared with the dead path")
	}
	for _, snapshot := range candidates {
		if haPathKey(snapshot.Lease) == ident.pathKey {
			t.Fatal("an isolated path was still offered to new streams")
		}
	}
}

// Any stream that actually receives data proves the path works and must clear
// both the isolation and the escalation streak.
func TestHealthyStreamClearsIsolationAndStreak(t *testing.T) {
	store, first, _ := twoPathCandidateStore(t)
	ident := relayIdentity{
		forward: 42, generation: first.PolicyGeneration, selection: first.SelectionRef,
		pathKey: haPathKey(first), candidateKey: first.CandidateKeyHash, leaseHash: first.LeaseHash,
	}
	started := time.Now()
	noteRelayOutcome(store, nil, ident, started, 64, 0)
	noteRelayOutcome(store, nil, ident, started, 64, 0)
	if _, isolated := isPathIsolated(store, "tcp", ident.pathKey); !isolated {
		t.Fatal("path was not isolated before the healthy stream")
	}
	noteRelayOutcome(store, nil, ident, started, 64, 402)
	if remaining, isolated := isPathIsolated(store, "tcp", ident.pathKey); isolated {
		t.Fatalf("a stream that received data left the path isolated for %s", remaining)
	}
	store.mu.RLock()
	streak := store.pathFailures["tcp:"+ident.pathKey]
	store.mu.RUnlock()
	if streak != 0 {
		t.Fatalf("healthy stream left an escalation streak of %d", streak)
	}
}

// A path that keeps failing after its first isolation must back off further, and
// a success must reset the schedule instead of leaving it escalated forever.
func TestPathIsolationEscalatesAndResetsOnSuccess(t *testing.T) {
	store, first, _ := twoPathCandidateStore(t)
	key := haPathKey(first)

	// A dial that never completed is unambiguous: it isolates right away.
	if !store.FailPath("tcp", key, first.PolicyGeneration) {
		t.Fatal("an unambiguous path failure did not isolate the path")
	}
	store.ClearPathFailure("tcp", key)

	// A silent path is only suspicious in repetition, and then it escalates.
	windows := make([]time.Duration, 0, 3)
	for attempt := 0; attempt < 5; attempt++ {
		if !store.FailSilentPath("tcp", key, first.PolicyGeneration) {
			// The first strike is recorded without isolating the path.
			continue
		}
		remaining, isolated := isPathIsolated(store, "tcp", key)
		if !isolated {
			t.Fatal("a crossed strike threshold did not isolate the path")
		}
		windows = append(windows, remaining)
	}
	if len(windows) < 3 {
		t.Fatalf("expected three escalating isolations, got %v", windows)
	}
	if !(windows[1] > windows[0] && windows[2] > windows[1]) {
		t.Fatalf("isolation windows did not escalate: %v", windows)
	}
	if windows[2] < 90*time.Second {
		t.Fatalf("third isolation is still a blip window: %s", windows[2])
	}

	store.ClearPathFailure("tcp", key)
	if _, isolated := isPathIsolated(store, "tcp", key); isolated {
		t.Fatal("successful path stayed isolated")
	}
	if store.FailSilentPath("tcp", key, first.PolicyGeneration) {
		t.Fatal("a single silent strike isolated the path after a reset")
	}
	if !store.FailSilentPath("tcp", key, first.PolicyGeneration) {
		t.Fatal("a fresh streak did not isolate the path again")
	}
	remaining, _ := isPathIsolated(store, "tcp", key)
	if remaining > 20*time.Second {
		t.Fatalf("escalation did not reset after success: %s", remaining)
	}
}

// When every path is down the runtime has no alternative, so the streaks must
// reset: otherwise a short outage escalates every path and the tunnels stay
// locked out long after the endpoint is reachable again.
func TestGlobalOutageDoesNotEscalateIsolation(t *testing.T) {
	store, first, second := twoPathCandidateStore(t)
	firstKey, secondKey := haPathKey(first), haPathKey(second)
	if !store.FailPath("tcp", firstKey, first.PolicyGeneration) {
		t.Fatal("first path was not isolated")
	}
	if !store.FailPath("tcp", secondKey, second.PolicyGeneration) {
		t.Fatal("second path was not isolated")
	}
	store.mu.RLock()
	streaks := len(store.pathFailures)
	store.mu.RUnlock()
	if streaks != 0 {
		t.Fatalf("a global outage left %d escalation streaks behind", streaks)
	}
	// After the outage the first failure must start from the short window again.
	store.ClearPathFailure("tcp", firstKey)
	store.FailPath("tcp", firstKey, first.PolicyGeneration)
	remaining, isolated := isPathIsolated(store, "tcp", firstKey)
	if !isolated || remaining > 20*time.Second {
		t.Fatalf("isolation window after a global outage is %s (isolated=%v)", remaining, isolated)
	}
}

// Isolation is only ever allowed for a path this lease actually authorizes.
func TestFailPathIgnoresPathsOutsideTheLease(t *testing.T) {
	store, first, _ := twoPathCandidateStore(t)
	if store.FailPath("tcp", strings.Repeat("9", 64), first.PolicyGeneration) {
		t.Fatal("isolated a path that is not part of the lease")
	}
}
