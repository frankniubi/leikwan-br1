package main

import (
	"context"
	"fmt"
	"net"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// A Panel line contributes up to three relays (speed, test and spare IP) that
// share its user, credential and candidate_key_hash, and the Panel interleaves
// candidates line-first. lineSiblingStore installs that shape for two lines
// with two relays each: line 41 relay A, line 916 relay A, line 41 relay B,
// line 916 relay B.
func lineSiblingStore(t *testing.T, config Config) (*PolicyStore, []ProviderLease) {
	t.Helper()
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	lines := []struct {
		id   int
		hash string
		user string
	}{{41, strings.Repeat("4", 64), "line-41-user"}, {916, strings.Repeat("9", 64), "line-916-user"}}
	var leases []ProviderLease
	for relay := 0; relay < 2; relay++ {
		for _, line := range lines {
			lease := testLease(policy, fmt.Sprintf("%02d%s", len(leases), strings.Repeat("e", 62)))
			lease.IP = fmt.Sprintf("203.0.113.%d", 10*(relay+1)+len(leases))
			lease.User = []byte(line.user)
			lease.CandidateKeyHash = line.hash
			lease.LeaseHash = strings.Repeat(string(rune('a'+len(leases))), 64)
			lease.SelectionRef = fmt.Sprintf("selection-line-%d-relay-%c", line.id, 'A'+relay)
			leases = append(leases, lease)
		}
	}
	store := NewPolicyStore(config)
	if err := store.ReplaceCandidates(policy, leases[0], leases); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { store.Revoke("") })
	return store, leases
}

func TestCandidateFailureIsolatesOnlyTheFailingSiblingRelay(t *testing.T) {
	store, leases := lineSiblingStore(t, testPolicyConfig())
	line41A, line41B := leases[0], leases[2]
	// A line hash names two relays here; it must not pick one of them.
	if store.MarkCandidateFailure(line41A.CandidateKeyHash) {
		t.Fatal("an ambiguous line hash isolated a relay")
	}
	store.MarkCandidateLeaseFailure(line41B)
	if !store.MarkCandidateLeaseFailure(line41B) {
		t.Fatal("second failure on relay B did not isolate it")
	}
	store.mu.Lock()
	now := time.Now()
	isolatedA := store.candidateIsolatedLocked(providerLeaseIdentity(line41A), now)
	isolatedB := store.candidateIsolatedLocked(providerLeaseIdentity(line41B), now)
	store.mu.Unlock()
	if isolatedA || !isolatedB {
		t.Fatalf("isolated relay A=%t relay B=%t, want only the failing relay B", isolatedA, isolatedB)
	}
	// The healthy sibling stays selectable for new streams.
	next, ok := store.SnapshotNextCandidate(providerLeaseIdentity(leases[1]), providerLeaseIdentity(leases[3]))
	if !ok || next.Lease.SelectionRef != line41A.SelectionRef {
		t.Fatalf("next=%q ok=%t, want the healthy sibling relay A", next.Lease.SelectionRef, ok)
	}
	next.Lease.wipe()
}

// Native CONNECT: a failed relay used to put its whole line in the attempted
// set, so once one relay of each line failed, the healthy siblings were never
// tried. Feedback must keep naming the line.
func TestNativeCandidateRetryReachesSiblingRelayOfFailedLine(t *testing.T) {
	store, leases := lineSiblingStore(t, testPolicyConfig())
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	snapshot, ok := store.Snapshot()
	if !ok {
		t.Fatal("no snapshot")
	}
	var attempted []string
	conn, selected, err := dialNativeStreamWithCandidates(context.Background(), store, snapshot, "example.test", 443, time.Second,
		func(lease ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
			attempted = append(attempted, lease.SelectionRef)
			if strings.HasSuffix(lease.SelectionRef, "relay-A") {
				return nil, fmt.Errorf("stream handshake: %w", context.DeadlineExceeded)
			}
			local, peer := net.Pipe()
			go func() { time.Sleep(time.Second); peer.Close() }()
			return local, nil
		}, status)
	if err != nil {
		t.Fatalf("attempted=%v err=%v", attempted, err)
	}
	defer conn.Close()
	defer selected.wipe()
	if want := "selection-line-41-relay-B"; selected.SelectionRef != want || len(attempted) != 3 {
		t.Fatalf("selected=%q attempted=%v, want %s after both relay A failures", selected.SelectionRef, attempted, want)
	}
	feedback := candidateFeedbackOf(status)
	if len(feedback) != 2 || feedback[0].CandidateKeyHash != leases[0].CandidateKeyHash ||
		feedback[1].CandidateKeyHash != leases[1].CandidateKeyHash ||
		feedback[0].LeaseHash != leases[0].LeaseHash || feedback[0].Reason != "relay_connect_timeout" {
		t.Fatalf("feedback=%+v, want line-level hashes for both failed relays", feedback)
	}
}

// Port-forward mode isolates by relay path already; a failed relay must leave
// its line's sibling eligible while the Panel hears the line hash.
func TestFixedTargetFailureKeepsSiblingRelayAndReportsLine(t *testing.T) {
	store, leases := lineSiblingStore(t, testPolicyConfig())
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	conn, selected, err := dialCandidateStreamReporting(context.Background(), store, "tcp", "example.test", 443, 5*time.Second,
		func(lease ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
			if lease.SelectionRef == "selection-line-41-relay-A" {
				return nil, fmt.Errorf("%w: refused", errProviderDial)
			}
			local, peer := net.Pipe()
			go func() { time.Sleep(time.Second); peer.Close() }()
			return local, nil
		}, fixedTargetFailureFeedback(status, "tcp"))
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	defer selected.Lease.wipe()
	if selected.Lease.SelectionRef != "selection-line-916-relay-A" {
		t.Fatalf("selected %q, want the next interleaved candidate", selected.Lease.SelectionRef)
	}
	candidates := store.CandidateSnapshots("tcp")
	defer wipeSnapshots(candidates)
	eligible := map[string]bool{}
	for _, candidate := range candidates {
		eligible[candidate.Lease.SelectionRef] = true
	}
	if eligible["selection-line-41-relay-A"] || !eligible["selection-line-41-relay-B"] || len(eligible) != 3 {
		t.Fatalf("eligible=%v, want only line 41 relay A isolated", eligible)
	}
	feedback := candidateFeedbackOf(status)
	if len(feedback) != 1 || feedback[0].CandidateKeyHash != leases[0].CandidateKeyHash ||
		feedback[0].LeaseHash != leases[0].LeaseHash {
		t.Fatalf("feedback=%+v, want the line-level hash of the failed relay", feedback)
	}
}
