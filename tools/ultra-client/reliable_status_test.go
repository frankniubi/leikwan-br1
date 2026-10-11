package main

import (
	"path/filepath"
	"testing"
)

func TestReliableStatusAggregatesAndRetainsFailures(t *testing.T) {
	w := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 170, 0)
	defer w.Close()
	busy, probe := [16]byte{1}, [16]byte{2}
	w.SetReliableStats(busy, reliableStats{Delivered: 2000, QueueTimeout: 1, WindowPackets: 2, Failed: "test"}, false)
	w.SetReliableStats(probe, reliableStats{Delivered: 1}, false)
	if s := w.status.UoTReliable; s.Delivered != 2001 || s.QueueTimeout != 1 || s.WindowPackets != 2 || s.Failed == "" {
		t.Fatalf("probe erased data evidence: %+v", s)
	}
	w.SetReliableStats(busy, reliableStats{Delivered: 2010, QueueTimeout: 1, WindowPackets: 2, Failed: "test"}, true)
	w.SetReliableStats(probe, reliableStats{Delivered: 2}, false)
	if s := w.status.UoTReliable; s.Delivered != 2012 || s.QueueTimeout != 1 || s.WindowPackets != 0 || s.Failed == "" {
		t.Fatalf("retirement erased evidence: %+v", s)
	}
	if len(w.reliableLive) != 1 {
		t.Fatal("retired association leaked")
	}
}
