package main

import (
	"context"
	"net"
	"testing"
	"time"
)

func TestExternalUoTCarrierWriterStopsBeforeReplacement(t *testing.T) {
	associationCtx, cancelAssociation := context.WithCancel(context.Background())
	defer cancelAssociation()
	carrierCtx, cancelCarrier := context.WithCancel(associationCtx)
	association := &externalUoTAssociation{
		ctx: associationCtx, outbound: make(chan externalUoTQueued, 1), budget: newDatagramBudget(32),
	}
	client, peer := net.Pipe()
	defer client.Close()
	defer peer.Close()
	done := make(chan struct{})
	go func() {
		defer close(done)
		association.externalUoTWriter(carrierCtx, client, make(chan error, 1))
	}()
	cancelCarrier()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("disconnected writer survived to steal replacement carrier data")
	}
	if associationCtx.Err() != nil {
		t.Fatal("carrier stop canceled the logical association")
	}
	association.outbound <- externalUoTQueued{payload: []byte("next-carrier"), queuedAt: time.Now()}
	if len(association.outbound) != 1 {
		t.Fatal("old carrier consumed queued replacement data")
	}
}

func TestExternalUoTAssociationLimitsAreBoundedAndConfigurable(t *testing.T) {
	limit, idle := externalUoTAssociationLimits(Config{})
	if limit != 256 || idle != 120*time.Second {
		t.Fatalf("defaults limit=%d idle=%s", limit, idle)
	}
	limit, idle = externalUoTAssociationLimits(Config{UDPMaxAssociations: 16, UDPAssociationIdleSecs: 30})
	if limit != 16 || idle != 30*time.Second {
		t.Fatalf("configured limit=%d idle=%s", limit, idle)
	}
	limit, idle = externalUoTAssociationLimits(Config{UDPMaxAssociations: 99999, UDPAssociationIdleSecs: -1})
	if limit != 256 || idle != 120*time.Second {
		t.Fatal("invalid configuration escaped resource bounds")
	}
}

func TestExternalUoTIdleReapPreservesActivePeers(t *testing.T) {
	now := time.Now()
	oldCtx, cancelOld := context.WithCancel(context.Background())
	defer cancelOld()
	activeCtx, cancelActive := context.WithCancel(context.Background())
	defer cancelActive()
	old := &externalUoTAssociation{ctx: oldCtx, cancel: cancelOld, lastSeen: now.Add(-120 * time.Second)}
	active := &externalUoTAssociation{ctx: activeCtx, cancel: cancelActive, lastSeen: now.Add(-time.Second)}
	associations := map[string]*externalUoTAssociation{"old": old, "active": active}
	pruneIdleExternalUoTAssociations(associations, now, 120*time.Second)
	if len(associations) != 1 || associations["active"] != active || activeCtx.Err() != nil {
		t.Fatal("idle reap interrupted an active peer")
	}
	if oldCtx.Err() == nil {
		t.Fatal("expired peer retained its carrier")
	}
}
