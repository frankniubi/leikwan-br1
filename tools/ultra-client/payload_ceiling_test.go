package main

import (
	"testing"
	"time"
)

func TestMeasurePayloadCeilingPicksLargestAcceptedSize(t *testing.T) {
	ceiling := 1440
	echo := func(payload []byte, _ time.Duration) bool { return len(payload) <= ceiling }
	size, ok := measurePayloadCeiling(echo)
	if !ok {
		t.Fatal("expected a measured ceiling")
	}
	if size != 1440 {
		t.Fatalf("ceiling = %d, want 1440", size)
	}
}

func TestMeasurePayloadCeilingRejectsFlappingSize(t *testing.T) {
	// Field sweep: 1440 flapped 1-in-12, so a size must echo on every attempt.
	calls := map[int]int{}
	echo := func(payload []byte, _ time.Duration) bool {
		calls[len(payload)]++
		if len(payload) == 1472 {
			return calls[1472] == 1
		}
		return len(payload) <= 1440
	}
	size, ok := measurePayloadCeiling(echo)
	if !ok || size != 1440 {
		t.Fatalf("size = %d ok = %v, want 1440/true", size, ok)
	}
	if calls[1472] != payloadCeilingAttempts {
		t.Fatalf("flapping size probed %d times, want %d", calls[1472], payloadCeilingAttempts)
	}
}

func TestMeasurePayloadCeilingReportsUnusableWhenNothingEchoes(t *testing.T) {
	size, ok := measurePayloadCeiling(func([]byte, time.Duration) bool { return false })
	if ok || size != 0 {
		t.Fatalf("size = %d ok = %v, want 0/false (association unusable)", size, ok)
	}
}

func TestAllowsPayloadKeepsSubFloorDatagramsProbeFree(t *testing.T) {
	session := &DatagramSession{}
	if !session.allowsPayload(floorPayloadCeiling) {
		t.Fatal("sub-floor datagrams must always pass")
	}
	if session.payloadCeiling != 0 {
		t.Fatal("sub-floor datagrams must not trigger a probe")
	}
}

func TestAllowsPayloadRefusesWhenAssociationIsUnusable(t *testing.T) {
	session := &DatagramSession{}
	// No socket: the ladder cannot echo, so the association is unusable.
	if session.allowsPayload(defaultPayloadCeiling) {
		t.Fatal("an unmeasurable association must not accept path-sized datagrams")
	}
	if session.payloadCeiling != 0 {
		t.Fatalf("payloadCeiling = %d, want 0", session.payloadCeiling)
	}
}

func TestAllowsPayloadAcceptsMeasuredCeiling(t *testing.T) {
	session := &DatagramSession{}
	session.payloadCeiling = 1440
	session.ceilingOnce.Do(func() {})
	if !session.allowsPayload(1440) || session.allowsPayload(1472) {
		t.Fatal("a measured ceiling must gate larger datagrams")
	}
}
