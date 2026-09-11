//go:build !windows

package main

import (
	"net"

	"golang.org/x/sys/unix"
)

func gameSimUDPBufferSizes(conn *net.UDPConn) (rcv, snd int, err error) {
	raw, err := conn.SyscallConn()
	if err != nil {
		return 0, 0, err
	}
	var readBuf, writeBuf int
	var queryErr error
	if err := raw.Control(func(fd uintptr) {
		readBuf, queryErr = unix.GetsockoptInt(int(fd), unix.SOL_SOCKET, unix.SO_RCVBUF)
		if queryErr != nil {
			return
		}
		writeBuf, queryErr = unix.GetsockoptInt(int(fd), unix.SOL_SOCKET, unix.SO_SNDBUF)
	}); err != nil {
		return 0, 0, err
	}
	return readBuf, writeBuf, queryErr
}
