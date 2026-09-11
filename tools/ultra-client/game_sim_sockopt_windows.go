//go:build windows

package main

import (
	"net"

	"golang.org/x/sys/windows"
)

func gameSimUDPBufferSizes(conn *net.UDPConn) (rcv, snd int, err error) {
	raw, err := conn.SyscallConn()
	if err != nil {
		return 0, 0, err
	}
	var readBuf, writeBuf int
	var queryErr error
	if err := raw.Control(func(fd uintptr) {
		readBuf, queryErr = windows.GetsockoptInt(windows.Handle(fd), windows.SOL_SOCKET, windows.SO_RCVBUF)
		if queryErr != nil {
			return
		}
		writeBuf, queryErr = windows.GetsockoptInt(windows.Handle(fd), windows.SOL_SOCKET, windows.SO_SNDBUF)
	}); err != nil {
		return 0, 0, err
	}
	return readBuf, writeBuf, queryErr
}
