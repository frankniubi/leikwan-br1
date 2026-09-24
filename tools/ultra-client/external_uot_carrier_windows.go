//go:build windows

package main

import (
	"net"

	"golang.org/x/sys/windows"
)

// tuneExternalUoTCarrier raises the carrier's send buffer and reports the size
// the socket accepted (0 when it could not be read).
//
// Go keeps one overlapped send outstanding per socket, and a carrier datagram
// is about 1.2 KB, so on Windows what can be in flight is essentially the socket
// send buffer. With the default buffer the SHA->LAX carriers (306 ms RTT) moved
// about 2.2 Mbit/s each against 2.5 Mbit/s offered, queued for the rest of the
// 180 s run and dropped (queue_blocked=1994, queue_timeout=296), while the TCP
// forward on the same relay, writing 32 KB at a time, kept up. Linux and macOS
// autotune the buffer and were not affected.
func tuneExternalUoTCarrier(connection net.Conn) int {
	tcp := carrierTCPConn(connection)
	if tcp == nil {
		return 0
	}
	_ = tcp.SetWriteBuffer(externalUoTCarrierSendBuffer)
	raw, err := tcp.SyscallConn()
	if err != nil {
		return 0
	}
	size := 0
	_ = raw.Control(func(fd uintptr) {
		if value, getErr := windows.GetsockoptInt(windows.Handle(fd), windows.SOL_SOCKET, windows.SO_SNDBUF); getErr == nil {
			size = value
		}
	})
	return size
}
