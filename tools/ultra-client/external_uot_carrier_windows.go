//go:build windows

package main

import (
	"net"

	"golang.org/x/sys/windows"
)

// tuneExternalUoTCarrier reports the carrier's send buffer (0 when it cannot be
// read) and deliberately leaves it to Windows' dynamic send buffering, which
// sizes the send backlog to the connection (ideal send backlog). Windows has no
// TCP_NOTSENT_LOWAT, so the second result is always 0.
//
// A fixed 2 MiB SO_SNDBUF was set here for a 306 ms carrier RTT, but that RTT
// is the HELLO round trip through the relay (which connects upstream lazily).
// The carrier TCP connection itself ends at the provider relay, 19-20 ms from
// the SHA test host (TCP connect 19 ms), and the relay terminates it, so the
// client's send window never limited the carrier. The fixed buffer only hid up
// to 2 MiB (6.7 s at 2.5 Mbit/s) of backlog in the kernel, where the lag
// detector cannot see it and a carrier handover cannot recover it.
func tuneExternalUoTCarrier(connection net.Conn) (sendBuffer, notSentLowat int) {
	tcp := carrierTCPConn(connection)
	if tcp == nil {
		return 0, 0
	}
	raw, err := tcp.SyscallConn()
	if err != nil {
		return 0, 0
	}
	_ = raw.Control(func(fd uintptr) {
		if value, getErr := windows.GetsockoptInt(windows.Handle(fd), windows.SOL_SOCKET, windows.SO_SNDBUF); getErr == nil {
			sendBuffer = value
		}
	})
	return sendBuffer, 0
}
