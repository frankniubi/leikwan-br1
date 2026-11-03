//go:build linux || darwin

package main

import (
	"net"
	"syscall"
)

// Report effective kernel values, not the requested size. Linux may double the
// reported value for bookkeeping; do not reinterpret it as application capacity.
func datagramSocketBuffers(c *net.UDPConn) (recv, send int, err error) {
	raw, err := c.SyscallConn()
	if err != nil {
		return 0, 0, err
	}
	controlErr := raw.Control(func(fd uintptr) {
		recv, err = syscall.GetsockoptInt(int(fd), syscall.SOL_SOCKET, syscall.SO_RCVBUF)
		if err == nil {
			send, err = syscall.GetsockoptInt(int(fd), syscall.SOL_SOCKET, syscall.SO_SNDBUF)
		}
	})
	if controlErr != nil {
		err = controlErr
	}
	return
}
