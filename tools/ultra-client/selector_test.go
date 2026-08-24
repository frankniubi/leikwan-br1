package main

import (
	"context"
	"errors"
	"fmt"
	"sync/atomic"
	"testing"
	"time"
)

func selectorEntries(count int) []Entry {
	entries := make([]Entry, 0, count)
	for index := 0; index < count; index++ {
		zone := "beijing"
		if index < 3 {
			zone = "shanghai"
		}
		entries = append(entries, Entry{
			EntryRef:        fmt.Sprintf("entry-reference-%02d-abcdefghijklmnop", index),
			IngressZone:     zone,
			DestinationZone: "tokyo",
			IP:              fmt.Sprintf("192.0.2.%d", index+1),
			Port:            4000 + index,
			User:            "user",
			Credential:      "1234567890123456",
		})
	}
	return entries
}

func TestRankEntriesBoundsWorkersAndPrefersDetectedZone(t *testing.T) {
	var active atomic.Int32
	var maximum atomic.Int32
	probe := func(_ context.Context, entry Entry) (time.Duration, error) {
		current := active.Add(1)
		defer active.Add(-1)
		for {
			seen := maximum.Load()
			if current <= seen || maximum.CompareAndSwap(seen, current) {
				break
			}
		}
		time.Sleep(10 * time.Millisecond)
		return time.Duration(20-entry.Port%10) * time.Millisecond, nil
	}
	policy := MaterialPolicy{DestinationZone: "tokyo", AllowedIngressZones: []string{"shanghai", "beijing"}, FallbackPolicy: "detected_then_any"}
	ranked := rankEntries(context.Background(), selectorEntries(10), policy, "shanghai", 4, probe)
	if len(ranked) != 8 {
		t.Fatalf("ranked count=%d", len(ranked))
	}
	if maximum.Load() > 4 {
		t.Fatalf("concurrent probes=%d", maximum.Load())
	}
	for index := 0; index < 3; index++ {
		if ranked[index].IngressZone != "shanghai" {
			t.Fatalf("detected zone not preferred: %+v", ranked)
		}
	}
}

func TestRankEntriesPoliciesAndStableTies(t *testing.T) {
	entries := selectorEntries(5)
	probe := func(_ context.Context, entry Entry) (time.Duration, error) {
		if entry.IP == "192.0.2.2" {
			return 0, errors.New("unreachable")
		}
		return 10 * time.Millisecond, nil
	}
	base := MaterialPolicy{DestinationZone: "tokyo", AllowedIngressZones: []string{"shanghai", "beijing"}}
	base.FallbackPolicy = "detected_only"
	detected := rankEntries(context.Background(), entries, base, "shanghai", 4, probe)
	if len(detected) != 3 || detected[0].IP != "192.0.2.1" || detected[1].IP != "192.0.2.3" || detected[2].IP != "192.0.2.2" {
		t.Fatalf("detected_only ranking=%+v", detected)
	}
	base.FallbackPolicy = "any"
	any := rankEntries(context.Background(), entries, base, "shanghai", 4, probe)
	if len(any) != 5 || any[0].IP != "192.0.2.1" || any[1].IP != "192.0.2.3" {
		t.Fatalf("any ranking=%+v", any)
	}
}

func TestSelectionPlanKeepsTCPAndUDPIndependent(t *testing.T) {
	ranked := selectorEntries(3)
	plan := buildSelectionPlan(ranked)
	plan.TCP[0].IP = "198.51.100.1"
	if plan.UDP[0].IP == plan.TCP[0].IP || ranked[0].IP == plan.TCP[0].IP {
		t.Fatal("TCP selection mutated UDP or source ordering")
	}
}
