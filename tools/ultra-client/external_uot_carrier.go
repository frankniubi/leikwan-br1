package main

import "net"

// externalUoTCarrierNotSentLowat is the unsent backlog a carrier socket may
// hold where the platform can cap it (see external_uot_carrier_unix.go). The
// carrier leg ends at a provider relay about 20 ms away, so 128 KiB keeps the
// socket busy far beyond any association's rate while leaving the backlog
// where the association can measure it.
const externalUoTCarrierNotSentLowat = 128 << 10

// externalUoTCarrierDarwinSendBuffer is the fixed macOS carrier send buffer. It
// stays below externalUoTResendBytes so a handover can always resend whatever
// the old carrier still held; at 100 ms RTT it still carries about 120 Mbit/s.
const externalUoTCarrierDarwinSendBuffer = 1536 << 10

// carrierTCPConn returns the TCP socket under a provider stream, which the
// handshake wraps in a bufferedConn.
func carrierTCPConn(connection net.Conn) *net.TCPConn {
	for connection != nil {
		switch typed := connection.(type) {
		case *net.TCPConn:
			return typed
		case *bufferedConn:
			connection = typed.Conn
		default:
			return nil
		}
	}
	return nil
}
