package main

import (
	"net"
	"syscall"
)

func datagramSocketBuffers(c *net.UDPConn) (recv, send int, err error) {
	raw, err := c.SyscallConn()
	if err != nil {
		return 0, 0, err
	}
	controlErr := raw.Control(func(fd uintptr) {
		recv, err = syscall.GetsockoptInt(syscall.Handle(fd), syscall.SOL_SOCKET, syscall.SO_RCVBUF)
		if err == nil {
			send, err = syscall.GetsockoptInt(syscall.Handle(fd), syscall.SOL_SOCKET, syscall.SO_SNDBUF)
		}
	})
	if controlErr != nil {
		err = controlErr
	}
	return
}
