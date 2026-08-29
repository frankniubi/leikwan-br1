package main

import (
	"testing"
	"time"
)

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
