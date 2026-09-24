//go:build windows

package main

import "testing"

// The carrier leg ends at a nearby provider relay, so the carrier keeps
// Windows' dynamic send buffering instead of a fixed buffer that would hide a
// lagging carrier's backlog in the kernel.
func TestExternalUoTCarrierKeepsWindowsDynamicSendBuffer(t *testing.T) {
	left, _ := tcpPair(t)
	before, lowat := tuneExternalUoTCarrier(relayConn(left))
	if before <= 0 || lowat != 0 {
		t.Fatalf("carrier SO_SNDBUF unreadable: %d", before)
	}
	if before >= 2<<20 {
		t.Fatalf("carrier SO_SNDBUF=%d: a fixed multi-megabyte buffer is set again", before)
	}
	if after, _ := tuneExternalUoTCarrier(relayConn(left)); after != before {
		t.Fatalf("reporting the send buffer changed it: %d -> %d", before, after)
	}
	if size, _ := tuneExternalUoTCarrier(&recordingCarrier{}); size != 0 {
		t.Fatal("a non-TCP carrier reported a send buffer")
	}
}
