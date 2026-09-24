package main

import "net"

// externalUoTCarrierSendBuffer is the send buffer an External-UoT carrier asks
// for where the platform does not size it well by itself (Windows, see
// external_uot_carrier_windows.go). 2 MiB keeps 26 Mbit/s in flight at a 640 ms
// RTT and 52 Mbit/s at 320 ms, several times the 10 Mbit/s acceptance rate on
// the longest measured paths, while bounding what the kernel may hold for one
// carrier.
const externalUoTCarrierSendBuffer = 2 << 20

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
