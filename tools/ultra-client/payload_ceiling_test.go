package main

import (
	"encoding/json"
	"net"
	"path/filepath"
	"sync"
	"testing"
	"time"
)

func TestMeasurePayloadCeilingPicksLargestAcceptedSize(t *testing.T) {
	ceiling := 1440
	echo := func(payload []byte, _ time.Duration) bool { return len(payload) <= ceiling }
	size, ok := measurePayloadCeiling(echo)
	if !ok {
		t.Fatal("expected a measured ceiling")
	}
	if size != 1440 {
		t.Fatalf("ceiling = %d, want 1440", size)
	}
}

func TestMeasurePayloadCeilingRejectsFlappingSize(t *testing.T) {
	// Field sweep: 1440 flapped 1-in-12, so a size must echo on every attempt.
	calls := map[int]int{}
	echo := func(payload []byte, _ time.Duration) bool {
		calls[len(payload)]++
		if len(payload) == 1472 {
			return calls[1472] == 1
		}
		return len(payload) <= 1440
	}
	size, ok := measurePayloadCeiling(echo)
	if !ok || size != 1440 {
		t.Fatalf("size = %d ok = %v, want 1440/true", size, ok)
	}
	if calls[1472] != payloadCeilingAttempts {
		t.Fatalf("flapping size probed %d times, want %d", calls[1472], payloadCeilingAttempts)
	}
}

func TestMeasurePayloadCeilingReportsUnusableWhenNothingEchoes(t *testing.T) {
	size, ok := measurePayloadCeiling(func([]byte, time.Duration) bool { return false })
	if ok || size != 0 {
		t.Fatalf("size = %d ok = %v, want 0/false (association unusable)", size, ok)
	}
}

func TestAllowsPayloadKeepsSubFloorDatagramsProbeFree(t *testing.T) {
	session := &DatagramSession{}
	if !session.allowsPayload(floorPayloadCeiling) {
		t.Fatal("sub-floor datagrams must always pass")
	}
	if session.payloadCeiling != 0 {
		t.Fatal("sub-floor datagrams must not trigger a probe")
	}
}

func TestAllowsPayloadRefusesWhenAssociationIsUnusable(t *testing.T) {
	session := &DatagramSession{}
	// No socket: the ladder cannot echo, so the association is unusable.
	if session.allowsPayload(defaultPayloadCeiling) {
		t.Fatal("an unmeasurable association must not accept path-sized datagrams")
	}
	if session.payloadCeiling != 0 {
		t.Fatalf("payloadCeiling = %d, want 0", session.payloadCeiling)
	}
	if !session.payloadUnusable() {
		t.Fatal("a measured ceiling of 0 must report the association as unusable")
	}
}

func TestAllowsPayloadAcceptsMeasuredCeiling(t *testing.T) {
	session := &DatagramSession{}
	session.payloadCeiling = 1440
	session.ceilingSource = payloadCeilingSourceMeasured
	session.ceilingMeasured = true
	if !session.allowsPayload(1440) || session.allowsPayload(1472) {
		t.Fatal("a measured ceiling must gate larger datagrams")
	}
}

// ceilingRelay answers association echo probes with the identical payload until
// a configured wall and silently drops anything larger, mirroring the per-relay
// size walls the 2026-09-12 sweep found in production.
type ceilingRelay struct {
	mu   sync.Mutex
	seen int
	wall int
}

func (relay *ceilingRelay) packets() int {
	relay.mu.Lock()
	defer relay.mu.Unlock()
	return relay.seen
}

func newCeilingRelay(t *testing.T, wall int) (*ceilingRelay, *DatagramSession) {
	t.Helper()
	server, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	relay := &ceilingRelay{wall: wall}
	go func() {
		buffer := make([]byte, 65535)
		for {
			count, client, readErr := server.ReadFromUDP(buffer)
			if readErr != nil {
				return
			}
			if count <= datagramEnvelopeSize {
				continue
			}
			payload := append([]byte(nil), buffer[datagramEnvelopeSize:count]...)
			relay.mu.Lock()
			relay.seen++
			relay.mu.Unlock()
			if len(payload) > relay.wall {
				continue
			}
			response := append([]byte{1, 2, 0, 0, 0, 0, 0}, payload...)
			_, _ = server.WriteToUDP(response, client)
		}
	}()
	client, err := net.DialUDP("udp4", nil, server.LocalAddr().(*net.UDPAddr))
	if err != nil {
		_ = server.Close()
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = client.Close(); _ = server.Close() })
	return relay, &DatagramSession{remote: client, mode: 0x11, sid: []byte{1, 2, 3}}
}

// fastCeilingProbe shrinks the per-echo timeout so ladder tests do not spend the
// production budget on sockets that intentionally never answer.
func fastCeilingProbe(t *testing.T) {
	t.Helper()
	previous := payloadCeilingTimeout
	payloadCeilingTimeout = 25 * time.Millisecond
	t.Cleanup(func() { payloadCeilingTimeout = previous })
}

func TestDeclaredRelayCeilingSkipsTheLadderProbe(t *testing.T) {
	relay, session := newCeilingRelay(t, 1440)
	session.declaredCeiling = 1400
	published := map[string][]int{}
	session.onCeilingMeasured = func(ceiling int, source string) {
		published[source] = append(published[source], ceiling)
	}
	if !session.allowsPayload(1400) {
		t.Fatal("a datagram inside the declared ceiling must be accepted")
	}
	if relay.packets() != 0 {
		t.Fatalf("declared ceiling still paid for %d ladder probes", relay.packets())
	}
	if session.payloadCeiling != 1400 {
		t.Fatalf("ceiling = %d, want the declared 1400", session.payloadCeiling)
	}
	if len(published[payloadCeilingSourceDeclared]) != 1 || len(published[payloadCeilingSourceMeasured]) != 0 {
		t.Fatalf("published ceilings = %+v, want one declared and no measurement", published)
	}
}

func TestDeclaredRelayCeilingBelowThePathIsCorrectedUpward(t *testing.T) {
	relay, session := newCeilingRelay(t, 1440)
	session.declaredCeiling = floorPayloadCeiling
	if !session.allowsPayload(1440) {
		t.Fatalf("path carries 1440 but the 1280 hint refused it (probes=%d)", relay.packets())
	}
	if relay.packets() == 0 {
		t.Fatal("a datagram above the declared ceiling must verify it against the path")
	}
	if session.payloadCeiling != 1440 || session.ceilingSource != payloadCeilingSourceMeasured {
		t.Fatalf("ceiling = %d source = %q, want 1440/measured", session.payloadCeiling, session.ceilingSource)
	}
	if session.allowsPayload(1472) {
		t.Fatal("a verified wall must keep refusing larger datagrams")
	}
}

func TestUnusableRelayAssociationPublishesCeilingZero(t *testing.T) {
	fastCeilingProbe(t)
	_, session := newCeilingRelay(t, 0)
	published := map[string][]int{}
	session.onCeilingMeasured = func(ceiling int, source string) {
		published[source] = append(published[source], ceiling)
	}
	if session.allowsPayload(defaultPayloadCeiling) {
		t.Fatal("a relay that never echoes must not accept path-sized datagrams")
	}
	if !session.payloadUnusable() {
		t.Fatal("the association must report itself unusable so HA migrates")
	}
	if got := published[payloadCeilingSourceMeasured]; len(got) != 1 || got[0] != 0 {
		t.Fatalf("published measurements = %+v, want a single 0", published)
	}
}

func TestNormalizeRelayPayloadCeilingBoundsTheHint(t *testing.T) {
	tests := []struct {
		name  string
		value int
		want  int
	}{
		{name: "unknown zero", value: 0, want: 0},
		{name: "negative is unknown", value: -8, want: 0},
		{name: "sweep wall", value: 1440, want: 1440},
		{name: "largest envelope payload", value: maxPayloadCeilingHint, want: maxPayloadCeilingHint},
		{name: "beyond one datagram is unknown", value: maxPayloadCeilingHint + 1, want: 0},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := normalizeRelayPayloadCeiling(test.value); got != test.want {
				t.Fatalf("normalizeRelayPayloadCeiling(%d) = %d, want %d", test.value, got, test.want)
			}
		})
	}
}

func TestLeaseFrameDecodesRelayPayloadCeiling(t *testing.T) {
	config := testPolicyConfig()
	policy := testPolicy(time.Now(), "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	frame := selectionTierLeaseFrame(policy)
	encoded, err := json.Marshal(frame)
	if err != nil {
		t.Fatal(err)
	}
	tests := []struct {
		name string
		raw  string
		want int
	}{
		{name: "absent is unknown", raw: "", want: 0},
		{name: "explicit zero is unknown", raw: "0", want: 0},
		{name: "panel measured wall", raw: "1440", want: 1440},
		{name: "negative is ignored", raw: "-5", want: 0},
		{name: "absurd is ignored", raw: "999999", want: 0},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			body := map[string]json.RawMessage{}
			if err := json.Unmarshal(encoded, &body); err != nil {
				t.Fatal(err)
			}
			if test.raw == "" {
				delete(body, "relay_payload_ceiling")
			} else {
				body["relay_payload_ceiling"] = json.RawMessage(test.raw)
			}
			raw, err := json.Marshal(body)
			if err != nil {
				t.Fatal(err)
			}
			_, lease, err := decodeLeaseFrame(raw, config)
			if err != nil {
				t.Fatal(err)
			}
			defer lease.wipe()
			if lease.RelayPayloadCeiling != test.want {
				t.Fatalf("relay_payload_ceiling = %d, want %d", lease.RelayPayloadCeiling, test.want)
			}
		})
	}
}

func TestStatusPublishesPayloadCeilingPerSelection(t *testing.T) {
	writer := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer writer.Close()
	writer.UpdateUDPProbe("selection-reference-abcdefghijklmnop", udpProbeResult{
		Sent: 30, Received: 30, MedianRTT: 10 * time.Millisecond,
		PayloadCeiling: 1472,
	})
	writer.mu.Lock()
	if writer.status.ActiveUDP == nil || writer.status.ActiveUDP.PayloadCeiling != 1472 ||
		writer.status.ActiveUDP.PayloadCeilingSource != payloadCeilingSourceDeclared {
		writer.mu.Unlock()
		t.Fatalf("probe selection ceiling = %+v", writer.status.ActiveUDP)
	}
	writer.mu.Unlock()

	// An unusable verdict replaces the declaration and stays visible through the
	// source key even though a 0 ceiling is omitted from JSON.
	writer.UpdateUDPPayloadCeiling(0, payloadCeilingSourceMeasured)
	writer.mu.Lock()
	defer writer.mu.Unlock()
	if writer.status.UDPPayloadCeiling != 0 || writer.status.UDPPayloadCeilingSource != payloadCeilingSourceMeasured {
		t.Fatalf("measured verdict = %d/%q", writer.status.UDPPayloadCeiling, writer.status.UDPPayloadCeilingSource)
	}
	if writer.status.ActiveUDP == nil || writer.status.ActiveUDP.PayloadCeiling != 0 ||
		writer.status.ActiveUDP.PayloadCeilingSource != payloadCeilingSourceMeasured {
		t.Fatalf("per-selection verdict = %+v", writer.status.ActiveUDP)
	}
}

func TestDeclaredCeilingUpdateIsIgnoredWhenUnknown(t *testing.T) {
	writer := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer writer.Close()
	writer.UpdateUDPPayloadCeiling(0, payloadCeilingSourceDeclared)
	writer.mu.Lock()
	defer writer.mu.Unlock()
	if writer.status.UDPPayloadCeilingSource != "" {
		t.Fatalf("an absent declaration must not publish a source, got %q", writer.status.UDPPayloadCeilingSource)
	}
}
