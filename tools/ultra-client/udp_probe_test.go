package main

import (
	"context"
	"encoding/binary"
	"errors"
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

func TestDNSProbeResponseRejectsMalformedOrNonAuthoritativeFrames(t *testing.T) {
	query, transactionID := buildDNSProbePayload(8)
	valid := append([]byte(nil), query...)
	valid[2] = 0x81
	valid[3] = 0x80
	if !probeResponseMatches(valid, transactionID) {
		t.Fatal("expected valid DNS response")
	}

	cases := []struct {
		name string
		edit func([]byte) []byte
	}{
		{name: "truncated header", edit: func(frame []byte) []byte { return frame[:11] }},
		{name: "query flag", edit: func(frame []byte) []byte { frame[2] = 0x01; return frame }},
		{name: "truncated response", edit: func(frame []byte) []byte { frame[2] = 0x81; frame[3] = 0x80; frame[12] = 0; return frame }},
		{name: "wrong opcode", edit: func(frame []byte) []byte { frame[2] = 0x89; frame[3] = 0x80; return frame }},
		{name: "truncated bit", edit: func(frame []byte) []byte { frame[2] = 0x83; frame[3] = 0x80; return frame }},
		{name: "server failure", edit: func(frame []byte) []byte { frame[2] = 0x81; frame[3] = 0x82; return frame }},
	}
	for _, test := range cases {
		t.Run(test.name, func(t *testing.T) {
			frame := append([]byte(nil), valid...)
			frame = test.edit(frame)
			if probeResponseMatches(frame, transactionID) {
				t.Fatalf("accepted malformed DNS response: %x", frame)
			}
		})
	}
}

func TestSelectBestLeaseStopsDispatchWhenQualityWindowIsCancelled(t *testing.T) {
	leases := []ProviderLease{
		{IP: "203.0.113.10", Port: 5438, SelectionRef: "selection-reference-000-abcdefghijklmnop"},
		{IP: "203.0.113.11", Port: 5438, SelectionRef: "selection-reference-001-abcdefghijklmnop"},
		{IP: "203.0.113.12", Port: 5438, SelectionRef: "selection-reference-002-abcdefghijklmnop"},
		{IP: "203.0.113.13", Port: 5438, SelectionRef: "selection-reference-003-abcdefghijklmnop"},
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	started := make(chan struct{}, len(leases))
	probe := func(ctx context.Context, _ ProviderLease, _ Config, _ Policy) udpProbeResult {
		started <- struct{}{}
		<-ctx.Done()
		return udpProbeResult{Err: ctx.Err()}
	}
	result := make(chan error, 1)
	go func() {
		_, _, err := selectBestLeaseWithProbe(ctx, Config{ProbeWorkers: 4}, Policy{Transport: "udp"}, leases, probe)
		result <- err
	}()
	for len(started) < 4 {
		select {
		case <-time.After(time.Second):
			t.Fatal("workers did not start")
		default:
			time.Sleep(time.Millisecond)
		}
	}
	cancel()
	select {
	case <-result:
	case <-time.After(time.Second):
		t.Fatal("selection workers did not stop after cancellation")
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

func TestProbeResultUsesBothProtocolsForTCPAndUDP(t *testing.T) {
	dual := udpProbeResult{
		Sent: 10, Received: 10,
		TCPSent:     1,
		TCPReceived: 1,
	}
	if !dual.usableFor("tcp+udp") {
		t.Fatal("dual candidate with both paths should be usable")
	}
	dual.TCPError = errProbeTCPFailed
	if dual.usableFor("tcp+udp") {
		t.Fatal("dual candidate with a TCP failure must be unusable")
	}
	if !dual.usableFor("udp") {
		t.Fatal("UDP result should remain usable for UDP transport")
	}
}

func TestRankProbeResultsRequiresDualProtocolAndPreservesStableOrder(t *testing.T) {
	results := []udpProbeResult{
		{Index: 0, Sent: 10, Received: 10, MedianRTT: 5 * time.Millisecond, TCPSent: 1, TCPReceived: 0, TCPError: errProbeTCPFailed},
		{Index: 1, Sent: 10, Received: 10, MedianRTT: 30 * time.Millisecond, TCPSent: 1, TCPReceived: 1, TCPRTT: 30 * time.Millisecond},
		{Index: 2, Sent: 10, Received: 10, MedianRTT: 30 * time.Millisecond, TCPSent: 1, TCPReceived: 1, TCPRTT: 30 * time.Millisecond},
	}
	ranked := rankProbeResults(results, "tcp+udp")
	if ranked[0].Index != 1 || ranked[1].Index != 2 || ranked[2].Index != 0 {
		t.Fatalf("dual ranking=%+v", ranked)
	}
}

func TestProbeFailureNamesAreProtocolSpecific(t *testing.T) {
	if controlErrorName(errProbeTCPFailed) != "probe_tcp_failed" {
		t.Fatalf("TCP probe error name=%q", controlErrorName(errProbeTCPFailed))
	}
	if controlErrorName(errProbeUDPFailed) != "probe_udp_failed" {
		t.Fatalf("UDP probe error name=%q", controlErrorName(errProbeUDPFailed))
	}
	if controlErrorName(errNoHealthyCandidate) != "no_healthy_candidate" {
		t.Fatalf("all-fail error name=%q", controlErrorName(errNoHealthyCandidate))
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

// ceilingProbeResult builds a candidate with fixed quality so a table test can
// vary only the payload ceiling.
func ceilingProbeResult(index, ceiling int) udpProbeResult {
	return udpProbeResult{
		Index: index, Sent: 20, Received: 20,
		MedianRTT: 40 * time.Millisecond, P95RTT: 50 * time.Millisecond, Jitter: 3 * time.Millisecond,
		PayloadCeiling: ceiling,
	}
}

func TestRankUDPProbeResultsOrdersByPayloadCeilingAtEqualLoss(t *testing.T) {
	unusable := ceilingProbeResult(0, 0)
	unusable.PayloadCeilingMeasured = true

	tests := []struct {
		name    string
		results []udpProbeResult
		want    []int
	}{
		{
			name: "higher ceiling wins at equal loss",
			results: []udpProbeResult{
				ceilingProbeResult(0, 1400), ceilingProbeResult(1, 1472), ceilingProbeResult(2, 1440),
			},
			want: []int{1, 2, 0},
		},
		{
			name: "unknown ceiling ranks as the conservative default",
			results: []udpProbeResult{
				ceilingProbeResult(0, 1360), ceilingProbeResult(1, 0), ceilingProbeResult(2, 1500),
			},
			want: []int{2, 1, 0},
		},
		{
			name: "a measured unusable ceiling is never selected",
			results: []udpProbeResult{
				unusable, ceilingProbeResult(1, 1280),
			},
			want: []int{1, 0},
		},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			ranked := rankUDPProbeResults(test.results)
			if len(ranked) != len(test.want) {
				t.Fatalf("ranked count=%d", len(ranked))
			}
			for position, index := range test.want {
				if ranked[position].Index != index {
					t.Fatalf("ranking=%v, want %v", ranked, test.want)
				}
			}
			if ranked[len(ranked)-1].PayloadCeilingMeasured && !ranked[len(ranked)-1].ceilingUnusable() {
				t.Fatalf("measured ceiling 0 must stay unusable: %+v", ranked[len(ranked)-1])
			}
		})
	}
}

func TestSelectBestLeaseSkipsUnusableCeilingCandidate(t *testing.T) {
	leases := []ProviderLease{
		{SelectionRef: "selection-reference-000-abcdefghijklmnop"},
		{SelectionRef: "selection-reference-001-abcdefghijklmnop", RelayPayloadCeiling: 1440},
	}
	probe := func(_ context.Context, lease ProviderLease, _ Config, _ Policy) udpProbeResult {
		if lease.SelectionRef == leases[0].SelectionRef {
			// Best delivery quality, but the association was measured as unable to
			// carry a floor-sized payload.
			return udpProbeResult{Sent: 20, Received: 20, MedianRTT: time.Millisecond, PayloadCeilingMeasured: true}
		}
		return udpProbeResult{Sent: 20, Received: 18, MedianRTT: 80 * time.Millisecond}
	}
	selected, result, err := selectBestLeaseWithProbe(context.Background(), Config{ProbeWorkers: 2}, Policy{Transport: "udp"}, leases, probe)
	if err != nil {
		t.Fatal(err)
	}
	if selected.SelectionRef != leases[1].SelectionRef {
		t.Fatalf("selected an unusable-ceiling candidate: %+v", selected)
	}
	if result.PayloadCeiling != 1440 {
		t.Fatalf("declared relay ceiling = %d, want the lease hint 1440", result.PayloadCeiling)
	}
}

func TestSelectBestLeaseRejectsOnlyUnusableCeilingCandidates(t *testing.T) {
	lease := ProviderLease{SelectionRef: "selection-reference-000-abcdefghijklmnop"}
	probe := func(context.Context, ProviderLease, Config, Policy) udpProbeResult {
		return udpProbeResult{Sent: 20, Received: 20, MedianRTT: time.Millisecond, PayloadCeilingMeasured: true}
	}
	_, result, err := selectBestLeaseWithProbe(context.Background(), Config{ProbeWorkers: 1}, Policy{Transport: "udp"}, []ProviderLease{lease}, probe)
	if !errors.Is(err, errNoHealthyCandidate) {
		t.Fatalf("err = %v, want %v", err, errNoHealthyCandidate)
	}
	if !result.ceilingUnusable() {
		t.Fatalf("reported result must keep the unusable verdict: %+v", result)
	}
}
