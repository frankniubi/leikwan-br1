package main

import (
	"context"
	"errors"
	"io"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestExternalUoTRejectedProbeNeverEntersFallbackPool(t *testing.T) {
	config := testPolicyConfig()
	config.ExternalUoT.Enabled = true
	config.ProbeWorkers = 2
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	leases := []ProviderLease{
		testLease(policy, strings.Repeat("b", 64)),
		testLease(policy, strings.Repeat("c", 64)),
	}
	leases[1].IP = "203.0.113.21"
	leases[1].SelectionRef = "selection-reference-second-path"
	var calls atomic.Int32
	probe := func(_ context.Context, lease ProviderLease, _ Config, _ Policy) udpProbeResult {
		calls.Add(1)
		if lease.IP == leases[0].IP {
			return udpProbeResult{TCPSent: 1, TCPError: io.EOF, Err: io.EOF}
		}
		return udpProbeResult{TCPSent: 1, TCPReceived: 1, MedianRTT: time.Millisecond}
	}
	previous := RuntimeSnapshot{Policy: policy, Lease: leases[0]}
	selected, _, eligible, err := selectVerifiedExternalUoTCandidates(
		context.Background(), config, policy, leases, previous, true, nil, probe)
	if err != nil || calls.Load() != 2 || len(eligible) != 1 || selected.IP != leases[1].IP {
		t.Fatalf("unproven sticky path admitted: eligible=%d calls=%d error=%v", len(eligible), calls.Load(), err)
	}
	store := NewPolicyStore(config)
	defer store.Revoke("")
	if err := store.ReplaceCandidates(policy, selected, eligible); err != nil {
		t.Fatal(err)
	}
	pool := store.CandidateSnapshots("tcp")
	defer wipeSnapshots(pool)
	if len(pool) != 1 || pool[0].Lease.IP != leases[1].IP {
		t.Fatal("probe rejection was reintroduced as a TCP fallback")
	}
}

func TestExternalUoTRenewalRevalidatesStickyCredentials(t *testing.T) {
	config := testPolicyConfig()
	config.ExternalUoT.Enabled = true
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	lease := testLease(policy, strings.Repeat("b", 64))
	previous := RuntimeSnapshot{Policy: policy, Lease: lease}
	var calls atomic.Int32
	probe := func(_ context.Context, _ ProviderLease, _ Config, _ Policy) udpProbeResult {
		calls.Add(1)
		return udpProbeResult{TCPSent: 1, TCPError: io.EOF, Err: io.EOF}
	}
	_, _, eligible, err := selectVerifiedExternalUoTCandidates(
		context.Background(), config, policy, []ProviderLease{lease}, previous, true, nil, probe)
	if !errors.Is(err, errNoHealthyCandidate) || len(eligible) != 0 || calls.Load() != 1 {
		t.Fatalf("same generation skipped proof: eligible=%d calls=%d error=%v", len(eligible), calls.Load(), err)
	}
}

func TestExternalUoTGenerationRotationKeepsRevalidatedAuthorizedPath(t *testing.T) {
	config := testPolicyConfig()
	config.ExternalUoT.Enabled = true
	oldPolicy := testPolicy(time.Now(), strings.Repeat("a", 64))
	oldLease := testLease(oldPolicy, strings.Repeat("b", 64))
	policy := testPolicy(time.Now(), strings.Repeat("c", 64))
	sticky := testLease(policy, strings.Repeat("d", 64))
	other := testLease(policy, strings.Repeat("e", 64))
	other.IP = "203.0.113.21"
	other.SelectionRef = "selection-reference-second-path"
	probe := func(_ context.Context, lease ProviderLease, _ Config, _ Policy) udpProbeResult {
		rtt := time.Millisecond
		if lease.IP == sticky.IP {
			rtt = 2 * time.Millisecond
		}
		return udpProbeResult{TCPSent: 1, TCPReceived: 1, MedianRTT: rtt}
	}
	previous := RuntimeSnapshot{Policy: oldPolicy, Lease: oldLease}
	selected, _, eligible, err := selectVerifiedExternalUoTCandidates(
		context.Background(), config, policy, []ProviderLease{other, sticky}, previous, true, nil, probe)
	if err != nil || len(eligible) != 2 || selected.IP != sticky.IP ||
		selected.PolicyGeneration != policy.PolicyGeneration || selected.LeaseID != sticky.LeaseID {
		t.Fatalf("rotation changed a healthy path or reused old authorization: selected=%s generation=%s err=%v",
			selected.IP, selected.PolicyGeneration, err)
	}
	// A path withdrawn from the fresh grant cannot be retained.
	selected, _, _, err = selectVerifiedExternalUoTCandidates(
		context.Background(), config, policy, []ProviderLease{other}, previous, true, nil, probe)
	if err != nil || selected.IP != other.IP {
		t.Fatalf("withdrawn path retained: selected=%s err=%v", selected.IP, err)
	}
	// A scope change is not an authorization-only rotation.
	previous.Policy.TargetDigest = strings.Repeat("f", 64)
	selected, _, _, err = selectVerifiedExternalUoTCandidates(
		context.Background(), config, policy, []ProviderLease{other, sticky}, previous, true, nil, probe)
	if err != nil || selected.IP != other.IP {
		t.Fatalf("old target scope influenced selection: selected=%s err=%v", selected.IP, err)
	}
}

func TestExternalUoTProbeFailureBelongsToLogicalUDP(t *testing.T) {
	config := testPolicyConfig()
	config.ExternalUoT.Enabled = true
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	lease := testLease(policy, strings.Repeat("b", 64))
	lease.CandidateKeyHash = strings.Repeat("c", 64)
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	defer status.Close()
	_, _, _, err := selectVerifiedExternalUoTCandidates(context.Background(), config, policy,
		[]ProviderLease{lease}, RuntimeSnapshot{}, false, status.RecordProbeFailure,
		func(context.Context, ProviderLease, Config, Policy) udpProbeResult {
			return udpProbeResult{TCPSent: 1, TCPError: io.EOF, Err: io.EOF}
		})
	if err == nil {
		t.Fatal("failed HELLO admitted")
	}
	status.mu.Lock()
	defer status.mu.Unlock()
	if len(status.status.CandidateFeedback) != 1 || status.status.CandidateFeedback[0].Protocol != "udp" {
		t.Fatal("UoT failure polluted user-TCP reputation")
	}
}

func TestExternalUoTRenewalKeepsLineWhenProviderCredentialsRotate(t *testing.T) {
	config := testPolicyConfig()
	config.ExternalUoT.Enabled = true
	oldPolicy := testPolicy(time.Now(), strings.Repeat("a", 64))
	oldLease := testLease(oldPolicy, strings.Repeat("b", 64))
	oldLease.CandidateKeyHash = strings.Repeat("1", 64)
	oldLease.User = []byte("previous-ephemeral-user")
	policy := testPolicy(time.Now(), strings.Repeat("c", 64))
	refreshed := testLease(policy, strings.Repeat("d", 64))
	refreshed.CandidateKeyHash = oldLease.CandidateKeyHash
	refreshed.SelectionRef = "fresh-selection-reference-abcdefghijkl"
	refreshed.User = []byte("new-ephemeral-user")
	refreshed.Password = []byte("new-password")
	other := testLease(policy, strings.Repeat("e", 64))
	other.CandidateKeyHash = strings.Repeat("2", 64)
	other.IP = "203.0.113.21"
	other.SelectionRef = "other-selection-reference-abcdefghijkl"
	probe := func(_ context.Context, lease ProviderLease, _ Config, _ Policy) udpProbeResult {
		rtt := time.Millisecond
		if lease.CandidateKeyHash == refreshed.CandidateKeyHash {
			rtt = 2 * time.Millisecond
		}
		return udpProbeResult{TCPSent: 1, TCPReceived: 1, MedianRTT: rtt}
	}
	selected, _, _, err := selectVerifiedExternalUoTCandidates(
		context.Background(), config, policy, []ProviderLease{other, refreshed},
		RuntimeSnapshot{Policy: oldPolicy, Lease: oldLease}, true, nil, probe)
	if err != nil || selected.CandidateKeyHash != oldLease.CandidateKeyHash ||
		string(selected.User) != string(refreshed.User) || selected.NonceHash != refreshed.NonceHash {
		t.Fatalf("credential rotation changed the revalidated line or reused credentials: key=%s userFresh=%t err=%v",
			selected.CandidateKeyHash, string(selected.User) == string(refreshed.User), err)
	}
}
