package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestCandidateQuarantineFiltersFreshGrantsAndWipesExcludedSecrets(t *testing.T) {
	now := time.Now()
	config := testPolicyConfig()
	config.CandidateQuarantineHashes = []string{strings.Repeat("b", 64)}
	config.CandidateQuarantineUntil = now.Add(time.Hour).UTC().Format(time.RFC3339)
	policy := testPolicy(now, strings.Repeat("a", 64))
	bad := testLease(policy, strings.Repeat("c", 64))
	bad.CandidateKeyHash = strings.Repeat("b", 64)
	good := testLease(policy, strings.Repeat("d", 64))
	good.CandidateKeyHash = strings.Repeat("e", 64)
	good.IP = "203.0.113.21"
	badSecret := bad.Password
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	defer status.Close()
	allowed, err := applyCandidateQuarantine(config, []ProviderLease{bad, good}, now, status)
	if err != nil || len(allowed) != 1 || allowed[0].CandidateKeyHash != good.CandidateKeyHash {
		t.Fatalf("quarantine failed: allowed=%d err=%v", len(allowed), err)
	}
	for _, value := range badSecret {
		if value != 0 {
			t.Fatal("excluded credential retained")
		}
	}
	status.mu.Lock()
	if !status.status.OperatorQuarantineActive || status.status.OperatorQuarantineExcluded != 1 {
		t.Error("operator quarantine invisible in status")
	}
	status.mu.Unlock()
	store := NewPolicyStore(config)
	defer store.Revoke("")
	if err := store.ReplaceCandidates(policy, allowed[0], allowed); err != nil {
		t.Fatal(err)
	}
	pool := store.CandidateSnapshots("tcp")
	defer wipeSnapshots(pool)
	if len(pool) != 1 || pool[0].Lease.CandidateKeyHash != good.CandidateKeyHash {
		t.Fatal("excluded candidate returned in fallback pool")
	}
}

func TestCandidateQuarantineExpiresAndAllExcludedFailsClosed(t *testing.T) {
	now := time.Now()
	config := testPolicyConfig()
	config.CandidateQuarantineHashes = []string{strings.Repeat("b", 64)}
	config.CandidateQuarantineUntil = now.Add(time.Minute).UTC().Format(time.RFC3339)
	policy := testPolicy(now, strings.Repeat("a", 64))
	lease := testLease(policy, strings.Repeat("c", 64))
	lease.CandidateKeyHash = strings.Repeat("b", 64)
	allowed, err := applyCandidateQuarantine(config, []ProviderLease{cloneHALease(lease)}, now, nil)
	if !errors.Is(err, errOperatorQuarantine) || len(allowed) != 0 {
		t.Fatalf("quarantine silently bypassed: %v", err)
	}
	if controlErrorName(err) != "operator_quarantined" {
		t.Fatal("missing explicit control error")
	}
	allowed, err = applyCandidateQuarantine(config, []ProviderLease{lease}, now.Add(2*time.Minute), nil)
	if err != nil || len(allowed) != 1 {
		t.Fatalf("expired quarantine remained active: %v", err)
	}
	allowed[0].wipe()
}

func TestCandidateQuarantineConfigurationIsBounded(t *testing.T) {
	now := time.Now()
	for _, config := range []Config{
		{CandidateQuarantineHashes: []string{strings.Repeat("b", 64)}},
		{CandidateQuarantineUntil: now.Add(time.Hour).Format(time.RFC3339)},
		{CandidateQuarantineHashes: []string{"invalid"}, CandidateQuarantineUntil: now.Add(time.Hour).Format(time.RFC3339)},
		{CandidateQuarantineHashes: []string{strings.Repeat("b", 64)}, CandidateQuarantineUntil: now.Add(25 * time.Hour).Format(time.RFC3339)},
		{CandidateQuarantineProtocol: "unsupported"},
	} {
		if validateCandidateQuarantine(config, now) == nil {
			t.Fatal("invalid/unbounded quarantine accepted")
		}
		raw, err := json.Marshal(config)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := loadConfig("", true, bytes.NewReader(raw)); !errors.Is(err, ErrFrameInvalid) {
			t.Fatalf("startup accepted invalid quarantine: %v", err)
		}
	}
	if err := validateCandidateQuarantine(Config{}, now); err != nil {
		t.Fatal("legacy unset config rejected")
	}
}

func TestTCPQuarantinePreservesUoTAndCannotBeBypassedByRescue(t *testing.T) {
	now := time.Now()
	config := testPolicyConfig()
	config.CandidateQuarantineHashes = []string{strings.Repeat("b", 64)}
	config.CandidateQuarantineUntil = now.Add(time.Hour).UTC().Format(time.RFC3339)
	config.CandidateQuarantineProtocol = "tcp"
	policy := testPolicy(now, strings.Repeat("a", 64))
	bad := testLease(policy, strings.Repeat("c", 64))
	bad.CandidateKeyHash = strings.Repeat("b", 64)
	good := testLease(policy, strings.Repeat("d", 64))
	good.CandidateKeyHash = strings.Repeat("e", 64)
	good.IP = "203.0.113.21"
	defer bad.wipe()
	defer good.wipe()
	leases, err := applyCandidateQuarantine(config, []ProviderLease{bad, good}, now, nil)
	if err != nil || len(leases) != 2 {
		t.Fatal("TCP quarantine removed logical UDP authorization")
	}
	store := NewPolicyStore(config)
	defer store.Revoke("")
	if err := store.ReplaceCandidates(policy, bad, leases); err != nil {
		t.Fatal(err)
	}
	tcp := store.CandidateSnapshots("tcp")
	defer wipeSnapshots(tcp)
	udp := store.CandidateSnapshots("udp")
	defer wipeSnapshots(udp)
	if len(tcp) != 1 || tcp[0].Lease.CandidateKeyHash != good.CandidateKeyHash ||
		len(udp) != 2 || udp[0].Lease.CandidateKeyHash != bad.CandidateKeyHash {
		t.Fatal("logical protocol pools were coupled")
	}
	var attempts []string
	_, _, err = dialCandidateStream(context.Background(), store, "127.0.0.1", 10001, time.Second,
		func(lease ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
			attempts = append(attempts, lease.CandidateKeyHash)
			return nil, net.ErrClosed
		})
	if err == nil || len(attempts) != 1 || attempts[0] != good.CandidateKeyHash {
		t.Fatal("TCP rescue bypassed quarantine")
	}
	store.mu.Lock()
	allDown := store.allPathsIsolatedLocked("tcp", time.Now())
	store.mu.Unlock()
	if !allDown {
		t.Fatal("quarantined path concealed a total TCP outage")
	}
	dial := func(_ ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
		left, right := net.Pipe()
		right.Close()
		return left, nil
	}
	conn, selected, err := dialCandidateStreamForProtocol(context.Background(), store, "udp", "127.0.0.1", 7445, time.Second, dial)
	if err != nil {
		t.Fatal(err)
	}
	conn.Close()
	defer selected.Lease.wipe()
	if selected.Lease.CandidateKeyHash != bad.CandidateKeyHash {
		t.Fatal("UoT lost its independent TCP-carried UDP path")
	}
	// Native CONNECT uses the older retry adapter: it must enforce the same
	// operator restriction without synthesizing failure evidence.
	var nativeAttempts []string
	conn, native, err := dialStreamWithCandidates(context.Background(), store, policy, cloneHALease(bad),
		"127.0.0.1", 10001, time.Second,
		func(lease ProviderLease, host string, port int, timeout time.Duration) (net.Conn, error) {
			nativeAttempts = append(nativeAttempts, lease.CandidateKeyHash)
			return dial(lease, host, port, timeout)
		}, nil)
	if err != nil {
		t.Fatal(err)
	}
	conn.Close()
	defer native.wipe()
	if len(nativeAttempts) != 1 || nativeAttempts[0] != good.CandidateKeyHash {
		t.Fatal("native CONNECT bypassed TCP quarantine")
	}
}
