//go:build linux || darwin

package main

import "testing"

// The carrier caps its unsent kernel backlog; Linux keeps send-buffer autotuning
// while macOS uses a fixed buffer below the resend window.
func TestExternalUoTCarrierCapsUnsentBacklog(t *testing.T) {
	left, _ := tcpPair(t)
	sendBuffer, lowat := tuneExternalUoTCarrier(relayConn(left))
	if lowat != externalUoTCarrierNotSentLowat {
		t.Fatalf("TCP_NOTSENT_LOWAT=%d, want %d", lowat, externalUoTCarrierNotSentLowat)
	}
	if sendBuffer <= 0 {
		t.Fatalf("send buffer unreadable: %d", sendBuffer)
	}
	if size, other := tuneExternalUoTCarrier(&recordingCarrier{}); size != 0 || other != 0 {
		t.Fatal("a non-TCP carrier reported socket options")
	}
}
