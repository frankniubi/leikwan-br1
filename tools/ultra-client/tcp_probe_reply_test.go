package main

import (
	"context"
	"errors"
	"io"
	"testing"
	"time"
)

func TestTCPProbeClientHelloIsATLSHandshakeRecord(t *testing.T) {
	hello := tcpProbeClientHello()
	if len(hello) < 50 || hello[0] != 0x16 || hello[5] != 0x01 {
		t.Fatalf("not a TLS ClientHello record: % x", hello[:min(len(hello), 8)])
	}
}

// The relay behind IKK-785135 completed the stream handshake and then closed
// every first flight with zero bytes. That, and only that, must flag the probe.
func TestTCPProbeReplyClassification(t *testing.T) {
	cases := map[string]struct {
		provider func(*testing.T, io.ReadWriteCloser)
		want     error
	}{
		"empty_close": {func(t *testing.T, p io.ReadWriteCloser) {
			readHello(t, p)
			p.Close()
		}, errProbeRelayClosedEmpty},
		"reply": {func(t *testing.T, p io.ReadWriteCloser) {
			readHello(t, p)
			p.Write([]byte{0x15, 0x03, 0x03, 0x00, 0x02, 0x02, 0x28})
		}, nil},
		"silent_until_timeout": {func(t *testing.T, p io.ReadWriteCloser) {
			readHello(t, p)
		}, nil},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			local, provider := tcpPair(t)
			defer local.Close()
			defer provider.Close()
			go tc.provider(t, provider)
			err := checkTCPProbeReply(context.Background(), local, 300*time.Millisecond)
			if !errors.Is(err, tc.want) && !(tc.want == nil && err == nil) {
				t.Fatalf("got %v, want %v", err, tc.want)
			}
		})
	}
}

func TestTCPProbeReplyResetIsNotAnEmptyClose(t *testing.T) {
	local, provider := tcpPair(t)
	defer local.Close()
	go func() {
		readHello(t, provider)
		provider.SetLinger(0)
		provider.Close()
	}()
	if err := checkTCPProbeReply(context.Background(), local, 300*time.Millisecond); err != nil {
		t.Fatalf("a reset was classified as an empty close: %v", err)
	}
}

func readHello(t *testing.T, r io.Reader) {
	t.Helper()
	buf := make([]byte, len(tcpProbeClientHello()))
	if _, err := io.ReadFull(r, buf); err != nil {
		t.Errorf("provider did not receive the ClientHello: %v", err)
	}
}

// Ranking: a relay that closed the probe empty loses to one that answered, but
// stays selectable when it is the only kind left.
func TestTCPEmptyCloseDemotesWithoutExcluding(t *testing.T) {
	good := udpProbeResult{Index: 1, TCPSent: 1, TCPReceived: 1, TCPRTT: 80 * time.Millisecond}
	empty := udpProbeResult{Index: 0, TCPSent: 3, TCPReceived: 3, TCPRTT: 10 * time.Millisecond, TCPEmptyClose: true}
	ranked := rankProbeResults([]udpProbeResult{empty, good}, "tcp")
	if ranked[0].Index != 1 {
		t.Fatal("a relay that closed the probe empty outranked one that answered")
	}
	if !empty.usableFor("tcp") {
		t.Fatal("an empty-close relay must stay selectable")
	}
	ranked = rankProbeResults([]udpProbeResult{empty}, "tcp")
	if len(ranked) != 1 || !ranked[0].usableFor("tcp") {
		t.Fatal("the only candidate became unusable")
	}
	merged := dualProbeResult(udpProbeResult{Sent: 1, Received: 1}, empty)
	if !merged.TCPEmptyClose {
		t.Fatal("dual probe dropped the empty-close verdict")
	}
}
