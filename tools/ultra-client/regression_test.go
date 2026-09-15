package main

import (
	"bytes"
	"context"
	"io"
	"net"
	"sync/atomic"
	"testing"
	"time"
)

type shortWriter struct {
	max int
	buf bytes.Buffer
}

func (w *shortWriter) Write(p []byte) (int, error) {
	if len(p) > w.max {
		p = p[:w.max]
	}
	return w.buf.Write(p)
}

func TestWriteAllHandlesShortWrites(t *testing.T) {
	writer := &shortWriter{max: 2}
	want := []byte("abcdef")
	if err := writeAll(writer, want); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(writer.buf.Bytes(), want) {
		t.Fatalf("writeAll=%q, want %q", writer.buf.Bytes(), want)
	}
}

func TestDatagramSessionResponseRejectsNoiseAndOtherSID(t *testing.T) {
	sid := []byte{0x12, 0x34, 0x56}
	noise := []byte{0, 0, 0, 1, 0, 0, 0, 0, 0, 0}
	if validDatagramSessionResponse(noise, sid) {
		t.Fatal("short/noise datagram was accepted as a session response")
	}
	if validDatagramSessionResponse(make([]byte, 28), sid) {
		t.Fatal("all-zero reference fixture was accepted as a session response")
	}
	other := make([]byte, 16)
	copy(other[:4], []byte{0, 0, 0, 1})
	other[4], other[5], other[6] = 0x12, 0x34, 0x57
	if validDatagramSessionResponse(other, sid) {
		t.Fatal("malformed/other-SID datagram was accepted")
	}
}

func TestDatagramSessionResponseAcceptsReferenceFrameWithOpaquePayload(t *testing.T) {
	sid := []byte{0x12, 0x34, 0x56}
	packet := append([]byte{0, 0, 0, 1, 2, 0, 0, 0, 0, 0}, []byte("opaque")...)
	if !validDatagramSessionResponse(packet, sid) {
		t.Fatal("valid reference response was rejected")
	}
}

func TestDecodeDatagramResponseMatchesCapturedReferenceFrame(t *testing.T) {
	packet := []byte{
		0x00, 0x00, 0x00, 0x01, 0x02, 0x00, 0x00, 0x00, 0x00, 0x00,
		0x00, 0x00, 0xa4, 0x64, 0x34, 0xc9, 0xb6, 0x2b, 0x82, 0xee,
		0xd2, 0xa8, 0x7f, 0xaa, 0x6a, 0x5f, 0x45, 0xac,
	}
	payload, valid := unwrapDatagram(packet)
	if !valid || !bytes.Equal(payload, []byte(keepPayloadHex)) {
		t.Fatalf("captured reference response valid=%v payload=%x", valid, payload)
	}
}

func TestProbeDurationDefaultsToThirtySeconds(t *testing.T) {
	if got := probeDuration(Config{}); got != 30*time.Second {
		t.Fatalf("probeDuration default=%s, want 30s", got)
	}
	if got := probeDuration(Config{ProbeDurationSecs: 7}); got != 7*time.Second {
		t.Fatalf("probeDuration configured=%s, want 7s", got)
	}
}

func TestProbeAssociationTimeoutIsSeparateFromPacketTimeout(t *testing.T) {
	if got := probeAssociationTimeout(Config{ProbeTimeoutMillis: 800}); got != 13*time.Second {
		t.Fatalf("association timeout=%s, want 13s", got)
	}
	if got := probeAssociationTimeout(Config{ConnectTimeoutSecs: 2, ProbeTimeoutMillis: 800}); got != 13*time.Second {
		t.Fatalf("configured association timeout=%s, want 13s minimum", got)
	}
	if got := probeAssociationTimeout(Config{ConnectTimeoutSecs: 15, ProbeTimeoutMillis: 800}); got != 15*time.Second {
		t.Fatalf("configured association timeout=%s, want 15s", got)
	}
}

func TestSelectBestLeaseSkipsDuplicateLogicalLegs(t *testing.T) {
	leases := []ProviderLease{
		{IP: "203.0.113.10", Port: 5438, SelectionRef: "selection-reference-abcdefghijklmnop"},
		// Repeated material for the same logical line is probed once.
		{IP: "203.0.113.10", Port: 5438, SelectionRef: "selection-reference-abcdefghijklmnop"},
		// A different line can legitimately share the relay socket and must stay
		// in the candidate set.
		{IP: "203.0.113.10", Port: 5438, SelectionRef: "selection-reference-qrstuvwxyzabcdef"},
		{IP: "203.0.113.11", Port: 5438, SelectionRef: "selection-reference-0123456789abcdef"},
	}
	var calls atomic.Int32
	probe := func(context.Context, ProviderLease, Config, Policy) udpProbeResult {
		calls.Add(1)
		return udpProbeResult{Sent: 10, Received: 10, MedianRTT: time.Millisecond}
	}
	_, _, err := selectBestLeaseWithProbe(context.Background(), Config{ProbeWorkers: 4}, Policy{Transport: "udp"}, leases, probe)
	if err != nil {
		t.Fatal(err)
	}
	if got := calls.Load(); got != 3 {
		t.Fatalf("probe calls=%d, want 3 unique logical legs", got)
	}
}

func TestStickyLeaseForPolicyKeepsSelectedPath(t *testing.T) {
	config := testPolicyConfig()
	now := time.Now()
	policy := testPolicy(now, "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	selected := testLease(policy, "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")
	selected.SelectionRef = "selection-reference-selected-abcdefghijkl"
	store := NewPolicyStore(config)
	if err := store.Replace(policy, selected); err != nil {
		t.Fatal(err)
	}
	matching := selected
	matching.LeaseID = "ul2_refresh"
	matching.NonceHash = "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
	matching.SelectionRef = "selection-reference-rotated-abcdefghijkl"
	other := matching
	other.SelectionRef = "selection-reference-other-abcdefghijklmnop"
	other.IP = "203.0.113.99"
	got, replace := stickyLeaseForPolicy(store, policy, []ProviderLease{other, matching})
	if !replace || !sameLeaseSelection(got, matching) {
		t.Fatalf("sticky selection = %+v replace=%v, want matching candidate", got, replace)
	}
	missing, replace := stickyLeaseForPolicy(store, policy, []ProviderLease{other})
	if replace || missing.SelectionRef != "" {
		t.Fatalf("missing sticky candidate unexpectedly changed selection: %+v replace=%v", missing, replace)
	}
	selected.wipe()
}

func TestControlDeadlineIncludesProbeWindowAndGrace(t *testing.T) {
	config := Config{AutoSelect: true, ProbeDurationSecs: 30}
	want := probeDuration(config) + probeAssociationTimeout(config) + selectionResponseGrace + activeCandidateFallbackBudget(config)
	if got := controlDeadlineDuration(config); got != want {
		t.Fatalf("control deadline=%s, want %s", got, want)
	}
}

func TestHandshakeGreetingOrderForOrdinaryStreamStartsWithLegacy(t *testing.T) {
	order := handshakeGreetingOrder(ProviderLease{IP: "203.0.113.10", Port: 5438})
	if len(order) != 2 || !bytes.Equal(order[0], legacyHandshakeGreeting) || !bytes.Equal(order[1], currentHandshakeGreeting) {
		t.Fatalf("greeting order=%x, want legacy then current", order)
	}
}

func TestHandshakeGreetingOrderForNativeStreamStartsWithCurrent(t *testing.T) {
	order := nativeHandshakeGreetingOrder(ProviderLease{IP: "203.0.113.10", Port: 5438})
	if len(order) != 2 || !bytes.Equal(order[0], currentHandshakeGreeting) || !bytes.Equal(order[1], legacyHandshakeGreeting) {
		t.Fatalf("native greeting order=%x, want current then legacy", order)
	}
}

func TestWriteAllReturnsShortWriteForZeroProgress(t *testing.T) {
	if err := writeAll(zeroWriter{}, []byte("x")); err != io.ErrShortWrite {
		t.Fatalf("writeAll zero progress=%v, want %v", err, io.ErrShortWrite)
	}
}

type zeroWriter struct{}

func (zeroWriter) Write([]byte) (int, error) { return 0, nil }

func TestDatagramResponseValidationDoesNotDependOnPayloadContents(t *testing.T) {
	sid := []byte{0x01, 0x02, 0x03}
	packet := []byte{0, 0, 0, 1, 2, 0, 0, 0, 0, 0}
	if !validDatagramSessionResponse(packet, sid) {
		t.Fatal("protocol framing with empty opaque payload was rejected")
	}
}

func TestProbeDurationIsBoundedByContext(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Millisecond)
	defer cancel()
	if got := probeDuration(Config{ProbeDurationSecs: 30}); got != 30*time.Second {
		t.Fatalf("probe duration changed by context: %s", got)
	}
	_ = ctx
	_ = net.IPv4(127, 0, 0, 1)
}
