package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// A relay that answers TCP and then closes the datagram handshake used to leave
// no trace: the runtime dropped it locally, picked another candidate and probed
// the same relay again on the next batch, so the Panel could never learn the
// endpoint cannot carry UDP. These tests pin the evidence that now comes out of
// a rejected candidate.
func TestRejectedCandidatesAreReportedThroughTheFailureSink(t *testing.T) {
	healthy := ProviderLease{
		IP: "1.2.3.4", Port: 5438, SelectionRef: "healthy",
		CandidateKeyHash: strings.Repeat("a", 64), LeaseHash: strings.Repeat("1", 64),
	}
	deadDatagram := ProviderLease{
		IP: "5.6.7.8", Port: 5438, SelectionRef: "dead-datagram",
		CandidateKeyHash: strings.Repeat("b", 64), LeaseHash: strings.Repeat("2", 64),
	}
	probe := func(_ context.Context, lease ProviderLease, _ Config, _ Policy) udpProbeResult {
		if lease.SelectionRef == "dead-datagram" {
			return udpProbeResult{
				Sent:     1,
				TCPSent:  1,
				Err:      fmt.Errorf("datagram handshake failed after greeting fallback: initial=datagram handshake: %w; legacy=datagram handshake: %w", io.EOF, io.EOF),
				TCPError: nil,
			}
		}
		return udpProbeResult{Sent: 20, Received: 20, TCPSent: 20, TCPReceived: 20, MedianRTT: time.Millisecond}
	}

	var reported []string
	selected, _, err := selectBestLeaseWithProbeReporting(
		context.Background(),
		Config{ProbeWorkers: 2},
		Policy{Transport: "tcp+udp"},
		[]ProviderLease{healthy, deadDatagram},
		probe,
		func(lease ProviderLease, _ udpProbeResult) { reported = append(reported, lease.SelectionRef) },
	)
	if err != nil {
		t.Fatalf("selection failed: %v", err)
	}
	if selected.SelectionRef != "healthy" {
		t.Fatalf("selected %q, want the healthy candidate", selected.SelectionRef)
	}
	if len(reported) != 1 || reported[0] != "dead-datagram" {
		t.Fatalf("reported candidates = %v, want exactly the rejected one", reported)
	}
}

func TestProbeFailureBecomesUdpEvidenceWithTheRelayIdentity(t *testing.T) {
	path := filepath.Join(t.TempDir(), "status.json")
	writer := newStatusWriter(path, 170, time.Millisecond)
	lease := ProviderLease{
		IP: "106.75.227.147", Port: 5438,
		CandidateKeyHash: strings.Repeat("c", 64),
		LeaseHash:        strings.Repeat("4", 64),
	}
	writer.RecordProbeFailure(lease, udpProbeResult{
		Sent: 1,
		Err:  fmt.Errorf("datagram handshake failed after greeting fallback: initial=datagram handshake: %w; legacy=datagram handshake: %w", io.EOF, io.EOF),
	})
	writer.Close()

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var status RuntimeStatus
	if err := json.Unmarshal(raw, &status); err != nil {
		t.Fatal(err)
	}
	if len(status.CandidateFeedback) != 1 {
		t.Fatalf("feedback entries = %d, want 1 (%+v)", len(status.CandidateFeedback), status.CandidateFeedback)
	}
	entry := status.CandidateFeedback[0]
	if entry.Outcome != "failure" || entry.Reason != "probe_udp_failed" || entry.Protocol != "udp" {
		t.Fatalf("feedback = %+v, want a udp probe_udp_failed failure", entry)
	}
	if entry.CandidateKeyHash != lease.CandidateKeyHash || entry.LeaseHash != lease.LeaseHash {
		t.Fatalf("feedback identity = %s/%s, want the rejected relay", entry.CandidateKeyHash, entry.LeaseHash)
	}
	if entry.EvidenceID == "" || entry.ObservedAt == "" {
		t.Fatalf("feedback must carry evidence identity: %+v", entry)
	}
}

// A relay whose TCP path is broken too has to show both halves; reporting only
// the UDP half would hide an endpoint that carries neither protocol.
func TestProbeFailureReportsBothHalvesWhenBothFail(t *testing.T) {
	path := filepath.Join(t.TempDir(), "status.json")
	writer := newStatusWriter(path, 170, time.Millisecond)
	writer.RecordProbeFailure(ProviderLease{
		CandidateKeyHash: strings.Repeat("d", 64),
		LeaseHash:        strings.Repeat("5", 64),
	}, udpProbeResult{Sent: 1, TCPSent: 1, Err: io.EOF, TCPError: io.EOF})
	writer.Close()

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var status RuntimeStatus
	if err := json.Unmarshal(raw, &status); err != nil {
		t.Fatal(err)
	}
	reasons := make(map[string]bool, len(status.CandidateFeedback))
	for _, entry := range status.CandidateFeedback {
		reasons[entry.Protocol+":"+entry.Reason] = true
	}
	if !reasons["udp:probe_udp_failed"] || !reasons["tcp:probe_tcp_failed"] {
		t.Fatalf("feedback reasons = %v, want both probe failures", reasons)
	}
}

// A candidate that produced no failure must not be reported; otherwise every
// batch would accuse three healthy relays.
func TestHealthyCandidatesAreNotReported(t *testing.T) {
	path := filepath.Join(t.TempDir(), "status.json")
	writer := newStatusWriter(path, 170, time.Millisecond)
	writer.RecordProbeFailure(ProviderLease{
		CandidateKeyHash: strings.Repeat("e", 64),
		LeaseHash:        strings.Repeat("6", 64),
	}, udpProbeResult{Sent: 20, Received: 20, TCPSent: 20, TCPReceived: 20})
	writer.Close()

	raw, err := os.ReadFile(path)
	if err != nil {
		// Nothing was reported, so the writer had no reason to publish a status.
		return
	}
	var status RuntimeStatus
	if err := json.Unmarshal(raw, &status); err != nil {
		t.Fatal(err)
	}
	if len(status.CandidateFeedback) != 0 {
		t.Fatalf("a candidate with no failure produced feedback: %+v", status.CandidateFeedback)
	}
}
