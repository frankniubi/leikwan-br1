package main

import (
	"context"
	"path/filepath"
	"testing"
	"time"
)

func TestExternalUoTBlockedEnqueueStopsOnShutdown(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	a := &externalUoTAssociation{ctx: ctx, cancel: cancel, outbound: make(chan externalUoTQueued, 1), budget: newDatagramBudget(32)}
	if !a.enqueue([]byte("one")) {
		t.Fatal("initial admission")
	}
	done := make(chan bool)
	go func() { done <- a.enqueue([]byte("two")) }()
	time.Sleep(10 * time.Millisecond)
	cancel()
	select {
	case ok := <-done:
		if ok {
			t.Fatal("admitted after cancel")
		}
	case <-time.After(time.Second):
		t.Fatal("shutdown blocked")
	}
	if a.budget.bytes() != 3 {
		t.Fatal("failed admission leaked bytes")
	}
}
func TestExternalUoTQueueTimeoutCounters(t *testing.T) {
	w := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 170, time.Hour)
	a := &externalUoTAssociation{ctx: context.Background(), outbound: make(chan externalUoTQueued, 1), budget: newDatagramBudget(32), status: w}
	if !a.enqueue([]byte("one")) || a.enqueue([]byte("two")) {
		t.Fatal("admission result")
	}
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.status.UoTQueueTimeout != 1 || w.status.UoTQueueBlocked != 1 || w.status.UoTQueueDrop != 1 {
		t.Fatalf("%+v", w.status)
	}
}
func TestGuaranteedModeFailsClosed(t *testing.T) {
	_, e := (ExternalUoTConfig{Enabled: true, DeliveryMode: "guaranteed_delivery", SidecarTarget: "127.0.0.1:1234", Token: "fixture"}).normalized()
	if e == nil {
		t.Fatal("claimed guaranteed delivery without application ACK endpoint")
	}
}
