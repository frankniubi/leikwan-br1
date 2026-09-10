package main

import (
	"context"
	"errors"
	"net"
	"strings"
	"testing"
	"time"
)

func TestDialStreamWithCandidatesRetriesBeforePayloadRead(t *testing.T) {
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	first := testLease(policy, strings.Repeat("b", 64))
	first.CandidateKeyHash = strings.Repeat("c", 64)
	second := testLease(policy, strings.Repeat("d", 64))
	second.SelectionRef = "selection-reference-second-abcdefghijkl"
	second.CandidateKeyHash = strings.Repeat("e", 64)
	store := NewPolicyStore(testPolicyConfig())
	if err := store.ReplaceBatch(policy, first, []ProviderLease{first, second}); err != nil {
		t.Fatalf("replace batch: %v", err)
	}
	snapshot, ok := store.Snapshot()
	if !ok {
		t.Fatal("missing initial snapshot")
	}
	defer snapshot.Lease.wipe()

	var attempts []string
	connection, selected, err := dialStreamWithCandidates(
		context.Background(),
		store,
		policy,
		snapshot.Lease,
		"1.1.1.1",
		53,
		time.Second,
		func(lease ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
			attempts = append(attempts, lease.SelectionRef)
			if lease.CandidateKeyHash == first.CandidateKeyHash {
				return nil, errors.New("provider timeout")
			}
			left, right := net.Pipe()
			_ = right.Close()
			return left, nil
		},
		nil,
	)
	if err != nil {
		t.Fatal(err)
	}
	defer connection.Close()
	defer selected.wipe()
	if len(attempts) != 2 || attempts[0] != first.SelectionRef || attempts[1] != second.SelectionRef {
		t.Fatalf("candidate attempts=%v, want first then second", attempts)
	}
	if selected.CandidateKeyHash != second.CandidateKeyHash {
		t.Fatalf("selected candidate=%q, want second", selected.CandidateKeyHash)
	}
	selectedSnapshot, ok := store.Snapshot()
	if !ok {
		t.Fatal("missing selected snapshot")
	}
	defer selectedSnapshot.Lease.wipe()
	if selectedSnapshot.Lease.CandidateKeyHash != second.CandidateKeyHash {
		t.Fatalf("store selected=%q, want second", selectedSnapshot.Lease.CandidateKeyHash)
	}
}

func TestDialStreamWithCandidatesReturnsNoHealthyCandidateAfterExhaustion(t *testing.T) {
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	leases := make([]ProviderLease, 0, 4)
	for index := range 4 {
		lease := testLease(policy, strings.Repeat(string(rune('b'+index)), 64))
		lease.SelectionRef = "selection-reference-" + string(rune('0'+index)) + "-abcdefghijklmnop"
		lease.CandidateKeyHash = strings.Repeat(string(rune('c'+index)), 64)
		leases = append(leases, lease)
	}
	store := NewPolicyStore(testPolicyConfig())
	if err := store.ReplaceBatch(policy, leases[0], leases); err != nil {
		t.Fatalf("replace batch: %v", err)
	}
	snapshot, ok := store.Snapshot()
	if !ok {
		t.Fatal("missing initial snapshot")
	}
	defer snapshot.Lease.wipe()
	attempts := 0
	_, _, err := dialStreamWithCandidates(
		context.Background(), store, policy, snapshot.Lease, "1.1.1.1", 53, time.Second,
		func(ProviderLease, string, int, time.Duration) (net.Conn, error) {
			attempts++
			return nil, errors.New("provider timeout")
		}, nil)
	if !errors.Is(err, errNoHealthyCandidate) {
		t.Fatalf("error=%v, want no healthy candidate", err)
	}
	if attempts != 4 {
		t.Fatalf("attempts=%d, want four candidates", attempts)
	}
}
