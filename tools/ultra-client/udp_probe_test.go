package main

import (
	"context"
	"encoding/binary"
	"strings"
	"testing"
	"time"
)

func TestProbeTargetDefaultsToCloudflareDNS(t *testing.T) {
	host, port, err := probeTarget(Config{})
	if err != nil || host != "1.1.1.1" || port != 53 {
		t.Fatalf("target=%q:%d err=%v", host, port, err)
	}
	if _, _, err = probeTarget(Config{ProbeTarget: "9.9.9.9:53"}); err == nil {
		t.Fatal("accepted a non-Cloudflare probe target")
	}
}

func TestDNSProbePayloadAndResponseMatchTransactionID(t *testing.T) {
	payload, transactionID := buildDNSProbePayload(7)
	if len(payload) < 20 || binary.BigEndian.Uint16(payload[:2]) != transactionID {
		t.Fatalf("invalid dns probe payload: %x", payload)
	}
	if !strings.Contains(string(payload), "cloudflare") {
		t.Fatalf("probe query name missing: %x", payload)
	}
	response := append([]byte(nil), payload...)
	response[2] = 0x81
	if !probeResponseMatches(response, transactionID) {
		t.Fatal("expected matching DNS transaction")
	}
	response[1]++
	if probeResponseMatches(response, transactionID) {
		t.Fatal("accepted response with a different DNS transaction")
	}
}

func TestRankUDPProbeResultsPrefersDeliveryThenLatencyThenStableOrder(t *testing.T) {
	results := []udpProbeResult{
		{Index: 0, Sent: 20, Received: 20, MedianRTT: 40 * time.Millisecond, P95RTT: 50 * time.Millisecond, Jitter: 3 * time.Millisecond},
		{Index: 1, Sent: 20, Received: 18, MedianRTT: 8 * time.Millisecond, P95RTT: 12 * time.Millisecond, Jitter: 1 * time.Millisecond},
		{Index: 2, Sent: 20, Received: 20, MedianRTT: 40 * time.Millisecond, P95RTT: 50 * time.Millisecond, Jitter: 3 * time.Millisecond},
	}

	ranked := rankUDPProbeResults(results)
	if len(ranked) != 3 {
		t.Fatalf("ranked count=%d", len(ranked))
	}
	if ranked[0].Index != 0 || ranked[1].Index != 2 || ranked[2].Index != 1 {
		t.Fatalf("unexpected ranking: %+v", ranked)
	}
}

func TestRankUDPProbeResultsMarksUnusableCandidatesLast(t *testing.T) {
	results := []udpProbeResult{
		{Index: 0, Sent: 20, Received: 15, MedianRTT: 5 * time.Millisecond, P95RTT: 8 * time.Millisecond},
		{Index: 1, Sent: 20, Received: 0, Err: errProbeUnavailable},
		{Index: 2, Sent: 20, Received: 20, MedianRTT: 80 * time.Millisecond, P95RTT: 100 * time.Millisecond},
	}

	ranked := rankUDPProbeResults(results)
	if ranked[0].Index != 2 || ranked[1].Index != 0 || ranked[2].Index != 1 {
		t.Fatalf("unusable candidate ranking=%+v", ranked)
	}
}

func TestRankUDPProbeResultsRejectsHighLossCandidate(t *testing.T) {
	results := []udpProbeResult{
		{Index: 0, Sent: 20, Received: 1, MedianRTT: 5 * time.Millisecond, P95RTT: 5 * time.Millisecond},
		{Index: 1, Sent: 20, Received: 18, MedianRTT: 80 * time.Millisecond, P95RTT: 100 * time.Millisecond},
	}
	ranked := rankUDPProbeResults(results)
	if ranked[0].Index != 1 || ranked[1].usable() {
		t.Fatalf("high-loss candidate should be unusable: %+v", ranked)
	}
}

func TestRankUDPProbeResultsPrefersLowerJitterBeforeRawLatency(t *testing.T) {
	results := []udpProbeResult{
		{Index: 0, Sent: 20, Received: 20, MedianRTT: 8 * time.Millisecond, P95RTT: 80 * time.Millisecond, Jitter: 20 * time.Millisecond},
		{Index: 1, Sent: 20, Received: 20, MedianRTT: 12 * time.Millisecond, P95RTT: 90 * time.Millisecond, Jitter: 2 * time.Millisecond},
	}

	ranked := rankUDPProbeResults(results)
	if ranked[0].Index != 1 {
		t.Fatalf("low-jitter candidate should win: %+v", ranked)
	}
}

func TestSelectBestLeaseUsesProbeQualityBeforeActivating(t *testing.T) {
	leases := []ProviderLease{
		{SelectionRef: "selection-reference-000-abcdefghijklmnop"},
		{SelectionRef: "selection-reference-001-abcdefghijklmnop"},
	}
	probe := func(_ context.Context, lease ProviderLease, _ Config, _ Policy) udpProbeResult {
		if lease.SelectionRef == leases[0].SelectionRef {
			return udpProbeResult{Sent: 20, Received: 20, MedianRTT: 40 * time.Millisecond, P95RTT: 50 * time.Millisecond}
		}
		return udpProbeResult{Sent: 20, Received: 19, MedianRTT: 5 * time.Millisecond, P95RTT: 8 * time.Millisecond}
	}

	selected, result, err := selectBestLeaseWithProbe(context.Background(), Config{ProbeWorkers: 2}, Policy{Transport: "udp"}, leases, probe)
	if err != nil {
		t.Fatal(err)
	}
	if selected.SelectionRef != leases[0].SelectionRef || result.Received != 20 {
		t.Fatalf("selected=%+v result=%+v", selected, result)
	}
}
