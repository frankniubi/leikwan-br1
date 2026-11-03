package main

import (
	"fmt"
	"path/filepath"
	"testing"
	"time"
)

func TestHAStatusFailureHistorySurvivesNormalStreamChurn(t *testing.T) {
	w := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer w.Close()
	w.RecordHAEvent("tcp", "reset", "reset-generation")
	for i := 0; i < 100; i++ {
		for _, reason := range []string{"eof", "complete", "closed", "canceled"} {
			w.RecordHAEvent("tcp", reason, "normal-generation")
		}
	}
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.status.HAFailures != 1 {
		t.Fatalf("ordinary completion changed failure count: %d", w.status.HAFailures)
	}
	if len(w.status.HAEvents) != 1 || w.status.HAEvents[0].Reason != "reset" ||
		w.status.HAEvents[0].Generation != "reset-generation" {
		t.Fatalf("ordinary completion evicted reset history: %+v", w.status.HAEvents)
	}
}

func TestHAStatusFailureHistoryRemainsBoundedAndCountsEveryFailure(t *testing.T) {
	w := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer w.Close()
	for i := 0; i < 100; i++ {
		w.RecordHAEvent("tcp", "reset", fmt.Sprintf("generation-%d", i))
		w.RecordHAEvent("tcp", "eof", "normal-generation")
	}
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.status.HAFailures != 100 {
		t.Fatalf("failure count changed: %d", w.status.HAFailures)
	}
	if len(w.status.HAEvents) != 16 {
		t.Fatalf("history is not bounded to 16 failures: %d", len(w.status.HAEvents))
	}
	for i, event := range w.status.HAEvents {
		if event.Reason != "reset" || event.Generation != fmt.Sprintf("generation-%d", i+84) {
			t.Fatalf("history[%d] is not the expected recent failure: %+v", i, event)
		}
	}
}
