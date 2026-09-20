package main

import (
	"context"
	"errors"
	"net"
	"testing"
	"time"
)

// TestExternalUoTCarrierUsesLogicalUDPDomain pins the health-domain design the
// external UoT carrier depends on. The carrier is TCP on the wire, but it must
// select and score candidates in the logical-UDP domain. If it were dialled in
// the "tcp" domain, a sidecar failure could evict a working user-TCP path, and
// a user-TCP reset could evict a working UoT carrier.
func TestExternalUoTCarrierUsesLogicalUDPDomain(t *testing.T) {
	store, _, leases := haStore(t)
	key := haPathKey(leases[0])

	// User TCP carries a real failure streak on this same path.
	store.FailPath("tcp", key, leases[0].PolicyGeneration)
	store.FailPath("tcp", key, leases[0].PolicyGeneration)

	store.mu.RLock()
	tcpIsolated := store.isolated["tcp:"+key].After(time.Now())
	tcpStreak := store.pathFailures["tcp:"+key]
	store.mu.RUnlock()
	if !tcpIsolated || tcpStreak < 2 {
		t.Fatalf("setup: expected isolated user TCP path, isolated=%t streak=%d", tcpIsolated, tcpStreak)
	}

	called := false
	conn, selected, err := dialCandidateStreamForProtocol(context.Background(), store, "udp",
		"192.0.2.40", 7443, time.Second,
		func(ProviderLease, string, int, time.Duration) (net.Conn, error) {
			called = true
			local, peer := net.Pipe()
			peer.Close()
			return local, nil
		})
	if conn != nil {
		conn.Close()
	}
	selected.Lease.wipe()
	if err != nil || !called {
		t.Fatalf("UoT carrier was blocked by user-TCP health: called=%t err=%v", called, err)
	}

	// The carrier's success must not have healed the user-TCP path either.
	store.mu.RLock()
	tcpStillIsolated := store.isolated["tcp:"+key].After(time.Now())
	store.mu.RUnlock()
	if !tcpStillIsolated {
		t.Error("UoT carrier activity cleared isolated user-TCP health")
	}
}

// TestExternalUoTCarrierRespectsOperatorQuarantine keeps the operator kill
// switch effective on the carrier path: an excluded candidate must not become
// reachable again through logical UDP.
func TestExternalUoTCarrierRespectsOperatorQuarantine(t *testing.T) {
	store, _, leases := haStore(t)
	store.mu.Lock()
	store.config.CandidateQuarantineHashes = []string{leases[0].CandidateKeyHash}
	store.config.CandidateQuarantineProtocol = "tcp+udp"
	store.config.CandidateQuarantineUntil = time.Now().Add(time.Hour).UTC().Format(time.RFC3339)
	store.mu.Unlock()

	dialed := false
	conn, selected, err := dialCandidateStreamForProtocol(context.Background(), store, "udp",
		"192.0.2.40", 7443, time.Second,
		func(ProviderLease, string, int, time.Duration) (net.Conn, error) {
			dialed = true
			return nil, errors.New("quarantined candidate was dialled")
		})
	if conn != nil {
		conn.Close()
	}
	selected.Lease.wipe()
	if dialed && err == nil {
		t.Fatal("operator-quarantined candidate was reachable through the UoT carrier")
	}
}
