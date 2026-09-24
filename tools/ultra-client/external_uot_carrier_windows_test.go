//go:build windows

package main

import "testing"

// Windows keeps one small overlapped send in flight per carrier, so the carrier
// asks for a send buffer that covers 10 Mbit/s at the longest measured RTTs.
func TestExternalUoTCarrierSendBufferRaisedOnWindows(t *testing.T) {
	left, _ := tcpPair(t)
	if size := tuneExternalUoTCarrier(relayConn(left)); size < externalUoTCarrierSendBuffer {
		t.Fatalf("carrier SO_SNDBUF=%d, want at least %d", size, externalUoTCarrierSendBuffer)
	}
	if tuneExternalUoTCarrier(&recordingCarrier{}) != 0 {
		t.Fatal("a non-TCP carrier reported a send buffer")
	}
}
