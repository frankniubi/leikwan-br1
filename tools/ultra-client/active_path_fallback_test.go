package main

import (
	"context"
	"errors"
	"net"
	"testing"
	"time"
)

func TestDialCandidateStreamFallsBackToActivePathAfterIsolation(t *testing.T) {
	now := time.Now()
	config := testPolicyConfig()
	policy := testPolicy(now, "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	active := testLease(policy, "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")
	active.SelectionRef = "active-selection-reference-abcdefghijkl"
	sibling := testLease(policy, "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc")
	sibling.SelectionRef = "sibling-selection-reference-abcdefghi"
	sibling.IP = "203.0.113.21"
	store := NewPolicyStore(config)
	if err := store.ReplaceBatch(policy, active, []ProviderLease{active, sibling}); err != nil {
		t.Fatal(err)
	}
	store.FailCandidate("tcp", active)

	var attempted []string
	dial := func(lease ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
		attempted = append(attempted, lease.SelectionRef)
		if lease.SelectionRef == sibling.SelectionRef {
			return nil, errors.New("sibling probe failure")
		}
		left, right := net.Pipe()
		_ = right.Close()
		return left, nil
	}
	connection, snapshot, err := dialCandidateStream(
		context.Background(), store, config.Target, 443, 2*time.Second, dial,
	)
	if err != nil {
		t.Fatal(err)
	}
	defer connection.Close()
	defer snapshot.Lease.wipe()
	if snapshot.Lease.SelectionRef != active.SelectionRef {
		t.Fatalf("selected %q, want active %q", snapshot.Lease.SelectionRef, active.SelectionRef)
	}
	if len(attempted) != 2 || attempted[0] != sibling.SelectionRef || attempted[1] != active.SelectionRef {
		t.Fatalf("attempted=%v, want sibling then active fallback", attempted)
	}
	if candidates := store.CandidateSnapshots("tcp"); len(candidates) != 1 || candidates[0].Lease.SelectionRef != active.SelectionRef {
		wipeSnapshots(candidates)
		t.Fatalf("active path was not restored after fallback: %+v", candidates)
	} else {
		wipeSnapshots(candidates)
	}
}
