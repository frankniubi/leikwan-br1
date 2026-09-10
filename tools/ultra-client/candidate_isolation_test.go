package main

import (
	"strings"
	"testing"
	"time"
)

func TestPolicyStoreIsolatesFailedCandidateAndKeepsHealthySibling(t *testing.T) {
	now := time.Now()
	policy := testPolicy(now, strings.Repeat("a", 64))
	first := testLease(policy, strings.Repeat("b", 64))
	first.CandidateKeyHash = strings.Repeat("c", 64)
	first.LeaseHash = strings.Repeat("d", 64)
	second := testLease(policy, strings.Repeat("e", 64))
	second.SelectionRef = "selection-reference-second-abcdefghijkl"
	second.CandidateKeyHash = strings.Repeat("f", 64)
	second.LeaseHash = strings.Repeat("1", 64)

	store := NewPolicyStore(testPolicyConfig())
	if err := store.ReplaceBatch(policy, first, []ProviderLease{first, second}); err != nil {
		t.Fatalf("replace batch: %v", err)
	}
	snapshot, ok := store.Snapshot()
	if !ok || snapshot.Lease.CandidateKeyHash != first.CandidateKeyHash {
		t.Fatalf("initial candidate=%+v, ok=%v", snapshot.Lease, ok)
	}
	snapshot.Lease.wipe()

	if store.MarkCandidateFailure(first.CandidateKeyHash) {
		t.Fatal("one failure isolated candidate too early")
	}
	snapshot, ok = store.Snapshot()
	if !ok || snapshot.Lease.CandidateKeyHash != first.CandidateKeyHash {
		t.Fatalf("candidate changed after one failure: %+v, ok=%v", snapshot.Lease, ok)
	}
	snapshot.Lease.wipe()
	if !store.MarkCandidateFailure(first.CandidateKeyHash) {
		t.Fatal("second failure did not isolate candidate")
	}
	store.mu.RLock()
	isolatedUntil := store.candidateIsolatedTill[providerLeaseIdentity(first)]
	store.mu.RUnlock()
	if remaining := time.Until(isolatedUntil); remaining < 29*time.Second || remaining > 31*time.Second {
		t.Fatalf("candidate isolation ttl=%s, want about 30s", remaining)
	}
	snapshot, ok = store.Snapshot()
	if !ok || snapshot.Lease.CandidateKeyHash != second.CandidateKeyHash {
		t.Fatalf("healthy sibling was not selected: %+v, ok=%v", snapshot.Lease, ok)
	}
	snapshot.Lease.wipe()

	store.MarkCandidateSuccess(first.CandidateKeyHash)
	snapshot, ok = store.Snapshot()
	if !ok || snapshot.Lease.CandidateKeyHash != first.CandidateKeyHash {
		t.Fatalf("successful candidate was not restored: %+v, ok=%v", snapshot.Lease, ok)
	}
	snapshot.Lease.wipe()
}

func TestPolicyStoreSnapshotNextCandidateExcludesAttemptsAndPreservesHealthySibling(t *testing.T) {
	now := time.Now()
	policy := testPolicy(now, strings.Repeat("a", 64))
	first := testLease(policy, strings.Repeat("b", 64))
	first.CandidateKeyHash = strings.Repeat("c", 64)
	second := testLease(policy, strings.Repeat("e", 64))
	second.SelectionRef = "selection-reference-second-abcdefghijkl"
	second.CandidateKeyHash = strings.Repeat("f", 64)
	third := testLease(policy, strings.Repeat("1", 64))
	third.SelectionRef = "selection-reference-third-abcdefghijkl"
	third.CandidateKeyHash = strings.Repeat("2", 64)

	store := NewPolicyStore(testPolicyConfig())
	if err := store.ReplaceBatch(policy, first, []ProviderLease{first, second, third}); err != nil {
		t.Fatalf("replace batch: %v", err)
	}
	next, ok := store.SnapshotNextCandidate(first.CandidateKeyHash, second.CandidateKeyHash)
	if !ok || next.Lease.CandidateKeyHash != third.CandidateKeyHash {
		t.Fatalf("next candidate=%+v ok=%v, want third candidate", next.Lease, ok)
	}
	next.Lease.wipe()
	if _, ok := store.SnapshotNextCandidate(first.CandidateKeyHash, second.CandidateKeyHash, third.CandidateKeyHash); ok {
		t.Fatal("returned a candidate after all attempts were excluded")
	}
	first.wipe()
	second.wipe()
	third.wipe()
}
