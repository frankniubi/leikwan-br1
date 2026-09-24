//go:build linux || darwin

package main

import (
	"net"

	"golang.org/x/sys/unix"
)

// tuneExternalUoTCarrier caps the bytes the kernel may hold unsent for the
// carrier (TCP_NOTSENT_LOWAT) and reports the send buffer and the cap it set.
//
// Linux, Android and macOS autotune the send buffer (tcp_wmem reaches 4 MiB by
// default, 64 MiB on the SHA Linux test host), and that stays on: only unsent
// data is capped, what is in flight still follows the congestion window. A
// datagram the kernel holds unsent is invisible to the lag detector and lost
// to a carrier handover; kept in the association queue instead, it is measured
// and moves to the next carrier.
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
		if unix.SetsockoptInt(int(fd), unix.IPPROTO_TCP, unix.TCP_NOTSENT_LOWAT, externalUoTCarrierNotSentLowat) == nil {
			if value, getErr := unix.GetsockoptInt(int(fd), unix.IPPROTO_TCP, unix.TCP_NOTSENT_LOWAT); getErr == nil {
				notSentLowat = value
			}
		}
		if value, getErr := unix.GetsockoptInt(int(fd), unix.SOL_SOCKET, unix.SO_SNDBUF); getErr == nil {
			sendBuffer = value
		}
	})
	return sendBuffer, notSentLowat
}
