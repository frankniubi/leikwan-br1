package main

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"
)

func TestRetryActiveCandidateForwardUsesMatchingRefreshedLease(t *testing.T) {
	now := time.Now()
	previousPolicy := testPolicy(now, strings.Repeat("a", 64))
	previous := RuntimeSnapshot{
		Policy: previousPolicy,
		Lease:  testLease(previousPolicy, strings.Repeat("b", 64)),
	}
	refreshedPolicy := testPolicy(now, strings.Repeat("c", 64))
	refreshed := testLease(refreshedPolicy, strings.Repeat("d", 64))
	refreshed.SelectionRef = "selection-reference-refreshed-abcdefghijkl"
	refreshed.CandidateKeyHash = strings.Repeat("e", 64)
	other := testLease(refreshedPolicy, strings.Repeat("f", 64))
	other.IP = "203.0.113.21"

	var tested []string
	selected, result, err := retryActiveCandidateForward(
		context.Background(),
		testPolicyConfig(),
		refreshedPolicy,
		previous,
		[]ProviderLease{other, refreshed},
		func(_ context.Context, _ Config, _ Policy, lease ProviderLease) (udpProbeResult, error) {
			tested = append(tested, lease.SelectionRef)
			return udpProbeResult{TCPSent: 1, TCPReceived: 1, TCPRTT: time.Millisecond}, nil
		},
	)
	if err != nil {
		t.Fatal(err)
	}
	if selected.SelectionRef != refreshed.SelectionRef || result.Index != 1 || len(tested) != 1 {
		t.Fatalf("selected=%+v result=%+v tested=%v", selected, result, tested)
	}
}

func TestRetryActiveCandidateForwardDoesNotAuthorizeUnmatchedPath(t *testing.T) {
	now := time.Now()
	previousPolicy := testPolicy(now, strings.Repeat("a", 64))
	previous := RuntimeSnapshot{Policy: previousPolicy, Lease: testLease(previousPolicy, strings.Repeat("b", 64))}
	newPolicy := testPolicy(now, strings.Repeat("c", 64))
	candidate := testLease(newPolicy, strings.Repeat("d", 64))
	candidate.IP = "203.0.113.21"

	_, _, err := retryActiveCandidateForward(
		context.Background(), testPolicyConfig(), newPolicy, previous, []ProviderLease{candidate},
		func(context.Context, Config, Policy, ProviderLease) (udpProbeResult, error) {
			t.Fatal("forwarding test must not run for an unmatched path")
			return udpProbeResult{}, nil
		},
	)
	if !errors.Is(err, errNoHealthyCandidate) {
		t.Fatalf("err=%v, want no healthy candidate", err)
	}
}

func TestRetryActiveCandidateForwardKeepsFailureFailClosed(t *testing.T) {
	now := time.Now()
	previousPolicy := testPolicy(now, strings.Repeat("a", 64))
	previous := RuntimeSnapshot{Policy: previousPolicy, Lease: testLease(previousPolicy, strings.Repeat("b", 64))}
	newPolicy := testPolicy(now, strings.Repeat("c", 64))
	candidate := testLease(newPolicy, strings.Repeat("d", 64))

	_, _, err := retryActiveCandidateForward(
		context.Background(), testPolicyConfig(), newPolicy, previous, []ProviderLease{candidate},
		func(context.Context, Config, Policy, ProviderLease) (udpProbeResult, error) {
			return udpProbeResult{}, errProbeTCPFailed
		},
	)
	if !errors.Is(err, errNoHealthyCandidate) || !strings.Contains(err.Error(), "active candidate forwarding test") {
		t.Fatalf("err=%v, want wrapped active-candidate failure", err)
	}
}
