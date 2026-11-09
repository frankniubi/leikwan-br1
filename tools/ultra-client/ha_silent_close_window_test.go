package main

import (
	"path/filepath"
	"testing"
	"time"
)

// 2026-10-26: forward 292 (combination 53, Guangzhou lines) saw the relay close
// cleanly 1.07 to 2.08 s after a 1.8 to 2.2 KB first flight, with no downstream
// byte. The 500 ms zero-byte bound treated those as ordinary exchanges, so the
// path was never isolated and new streams kept using it for minutes.
func TestZeroByteClosesUpToTheSilenceBoundCountTowardIsolation(t *testing.T) {
	for name, age := range map[string]time.Duration{
		"one_second":  1070 * time.Millisecond,
		"two_seconds": 2080 * time.Millisecond,
	} {
		t.Run(name, func(t *testing.T) {
			store, first, second := twoPathCandidateStore(t)
			status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 292, time.Millisecond)
			defer status.Close()
			ident := zeroByteIdent(first, "tw.example.net:8443")
			for stream := 1; stream <= zeroByteCloseStrikes; stream++ {
				noteRelayOutcome(store, status, ident, time.Now().Add(-age), 1900, 0, zeroByteOrder())
				_, isolated := isPathIsolated(store, "tcp", ident.pathKey)
				if isolated != (stream == zeroByteCloseStrikes) {
					t.Fatalf("stream %d after %v: isolated=%v", stream, age, isolated)
				}
			}
			keys := tcpCandidateKeys(store)
			if len(keys) != 1 || keys[0] != haPathKey(second) {
				t.Fatal("new TCP streams were not moved to the healthy sibling")
			}
		})
	}
}

// A clean close after the silence bound is still an ordinary short exchange,
// so it must not isolate the path however often it repeats.
func TestZeroByteClosesBeyondTheSilenceBoundStayEligible(t *testing.T) {
	store, first, _ := twoPathCandidateStore(t)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 292, time.Millisecond)
	defer status.Close()
	ident := zeroByteIdent(first, "tw.example.net:8443")
	for range zeroByteCloseStrikes * 2 {
		noteRelayOutcome(store, status, ident, time.Now().Add(-(haDeadStreamMaxAge + 500*time.Millisecond)), 1900, 0, zeroByteOrder())
	}
	if _, isolated := isPathIsolated(store, "tcp", ident.pathKey); isolated {
		t.Fatal("a slow clean close was counted as a zero-byte close")
	}
}
