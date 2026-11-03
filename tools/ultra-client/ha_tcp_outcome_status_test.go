package main

import (
	"encoding/json"
	"fmt"
	"io"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"
)

func TestHATCPOutcomeRetainsCandidateAndLocalAvoidance(t *testing.T) {
	store, policy, leases := haStore(t)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer status.Close()
	ident := relayIdentity{
		forward: 42, generation: policy.PolicyGeneration, pathKey: haPathKey(leases[0]),
		candidateKey: strings.Repeat("b", 64), leaseHash: strings.Repeat("c", 64),
		selection: "must-not-be-exported",
	}
	for attempt := 0; attempt < 2; attempt++ {
		ended := time.Now()
		noteRelayOutcome(store, status, ident, ended.Add(-time.Second), 117, 0, relayStreamOrder{
			downstreamEnded: ended, upstreamEnded: ended.Add(time.Millisecond), remoteReadError: syscall.ECONNRESET,
		})
	}
	for i := 0; i < 100; i++ {
		status.RecordHAEvent("tcp", "eof", policy.PolicyGeneration)
	}
	status.mu.Lock()
	defer status.mu.Unlock()
	if len(status.status.HAEvents) != 2 || status.status.HAFailures != 3 {
		t.Fatalf("unexpected event/counter semantics: events=%+v failures=%d", status.status.HAEvents, status.status.HAFailures)
	}
	for i, event := range status.status.HAEvents {
		if event.CandidateKeyHash != ident.candidateKey || event.LeaseHash != ident.leaseHash ||
			event.Reason != "no_response" || event.RemoteReadReason != "reset" ||
			event.UpstreamBytes == nil || *event.UpstreamBytes != 117 ||
			event.RemoteReadBytes == nil || *event.RemoteReadBytes != 0 ||
			event.RemoteStoppedFirst == nil || !*event.RemoteStoppedFirst ||
			event.LocalPathAvoided == nil || *event.LocalPathAvoided != (i == 1) {
			t.Fatalf("event %d lost outcome context: %+v", i, event)
		}
	}
	if len(status.status.CandidateFeedback) != 0 {
		t.Fatal("diagnostic outcome produced global feedback")
	}
	encoded, err := json.Marshal(status.status.HAEvents[0])
	if err != nil {
		t.Fatal(err)
	}
	for _, fragment := range []string{`"remote_read_bytes":0`, `"local_path_avoided":false`, `"remote_stopped_first":true`} {
		if !strings.Contains(string(encoded), fragment) {
			t.Fatalf("JSON omitted known zero/false evidence: %s", encoded)
		}
	}
	if strings.Contains(string(encoded), ident.selection) || strings.Contains(string(encoded), ident.pathKey) {
		t.Fatalf("outcome leaked internal routing identity: %s", encoded)
	}
}

func TestHATCPOutcomePreservesRemoteErrorSourceWithoutScoring(t *testing.T) {
	for _, tc := range []struct {
		name       string
		err        error
		reason     string
		localFirst bool
	}{
		// A remote-first clean EOF inside the silence bound is a zero-byte close
		// now, so the ordinary case is a local client that hung up first.
		{"local_first_eof", io.EOF, "eof", true},
		{"local_write_failure_without_read_error", nil, "complete", false},
		{"provider_target_rejected", fmt.Errorf("wrapped: %w", errStreamCommandRejected), "target_connect_rejected", false},
		{"local_first_remote_reset", syscall.ECONNRESET, "reset", true},
	} {
		t.Run(tc.name, func(t *testing.T) {
			store, policy, leases := haStore(t)
			status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
			defer status.Close()
			ident := relayIdentity{generation: policy.PolicyGeneration, pathKey: haPathKey(leases[0])}
			ended := time.Now()
			upstreamEnded := ended.Add(time.Millisecond)
			if tc.localFirst {
				upstreamEnded = ended.Add(-time.Millisecond)
			}
			for i := 0; i < 3; i++ {
				noteRelayOutcome(store, status, ident, ended.Add(-time.Second), 117, 0, relayStreamOrder{
					downstreamEnded: ended, upstreamEnded: upstreamEnded, remoteReadError: tc.err,
				})
			}
			status.mu.Lock()
			defer status.mu.Unlock()
			if len(status.status.HAEvents) != 3 {
				t.Fatalf("missing diagnostic outcomes: %+v", status.status.HAEvents)
			}
			for _, event := range status.status.HAEvents {
				if event.RemoteReadReason != tc.reason || event.LocalPathAvoided == nil || *event.LocalPathAvoided ||
					event.RemoteStoppedFirst == nil || *event.RemoteStoppedFirst == tc.localFirst {
					t.Fatalf("wrong remote evidence or false avoidance: %+v", event)
				}
			}
			if len(status.status.CandidateFeedback) != 0 {
				t.Fatal("unscored outcome produced global feedback")
			}
			store.mu.RLock()
			defer store.mu.RUnlock()
			if store.pathFailures["tcp:"+ident.pathKey] != 0 {
				t.Fatal("diagnostic-only outcome changed path failure count")
			}
		})
	}
}
