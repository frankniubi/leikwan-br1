package main

import (
	"context"
	"errors"
	"io"
	"net"
	"sync/atomic"
	"testing"
	"time"
)

func TestSingleCandidateSetupTimeoutRetriesBeforePayload(t *testing.T) {
	_, p, leases := haStore(t)
	s := NewPolicyStore(testPolicyConfig())
	t.Cleanup(func() { s.Revoke("") })
	if err := s.ReplaceCandidates(p, leases[0], leases[:1]); err != nil {
		t.Fatal(err)
	}
	a, b := net.Pipe()
	defer b.Close()
	var calls atomic.Int32
	c, chosen, err := dialCandidateStream(context.Background(), s, "example.test", 443, time.Second,
		func(_ ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
			if calls.Add(1) == 1 {
				return nil, context.DeadlineExceeded
			}
			return a, nil
		})
	if err != nil {
		a.Close()
		t.Fatalf("transient setup timeout not recovered: calls=%d err=%v", calls.Load(), err)
	}
	defer c.Close()
	defer chosen.Lease.wipe()
	if calls.Load() != 2 {
		t.Fatalf("calls=%d", calls.Load())
	}
}

func TestSingleCandidateSetupRetryBoundedAndNoApplicationReplay(t *testing.T) {
	for _, failure := range []error{context.DeadlineExceeded, io.EOF} {
		_, p, leases := haStore(t)
		s := NewPolicyStore(testPolicyConfig())
		t.Cleanup(func() { s.Revoke("") })
		if err := s.ReplaceCandidates(p, leases[0], leases[:1]); err != nil {
			t.Fatal(err)
		}
		var calls atomic.Int32
		_, _, err := dialCandidateStream(context.Background(), s, "example.test", 443, time.Second,
			func(_ ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
				calls.Add(1)
				return nil, failure
			})
		want := int32(1)
		if errors.Is(failure, context.DeadlineExceeded) {
			want = 2
		}
		if err == nil || calls.Load() != want || !errors.Is(err, failure) {
			t.Fatalf("failure=%v calls=%d want=%d err=%v", failure, calls.Load(), want, err)
		}
	}
}

func TestSingleCandidateSetupRetryHonorsRevocation(t *testing.T) {
	_, p, leases := haStore(t)
	s := NewPolicyStore(testPolicyConfig())
	t.Cleanup(func() { s.Revoke("") })
	if err := s.ReplaceCandidates(p, leases[0], leases[:1]); err != nil {
		t.Fatal(err)
	}
	var calls atomic.Int32
	_, _, err := dialCandidateStream(context.Background(), s, "example.test", 443, time.Second,
		func(_ ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
			calls.Add(1)
			s.Revoke(p.PolicyGeneration)
			return nil, context.DeadlineExceeded
		})
	if err == nil || calls.Load() != 1 {
		t.Fatalf("revoked lease retried: calls=%d err=%v", calls.Load(), err)
	}
}
