package main

import (
	"net"
	"testing"
)

func TestEnterpriseDatagramSocketBufferReadback(t *testing.T) {
	conn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	readErr := conn.SetReadBuffer(16 << 20)
	writeErr := conn.SetWriteBuffer(16 << 20)
	receive, send, err := datagramSocketBuffers(conn)
	if err != nil || receive <= 0 || send <= 0 {
		t.Fatalf("socket readback unavailable: receive=%d send=%d error=%v", receive, send, err)
	}
	// Kernels may clamp the request. Report the observed values rather than
	// treating a successful setsockopt as evidence of the requested capacity.
	t.Logf("requested=%d effective_receive=%d effective_send=%d read_error=%v write_error=%v",
		16<<20, receive, send, readErr, writeErr)
}
