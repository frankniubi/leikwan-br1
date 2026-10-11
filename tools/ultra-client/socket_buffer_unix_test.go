//go:build !windows

package main

import "golang.org/x/sys/unix"

func setRawSocketBuffers(fd uintptr, receive, send int) error {
	if receive > 0 {
		if err := unix.SetsockoptInt(int(fd), unix.SOL_SOCKET, unix.SO_RCVBUF, receive); err != nil {
			return err
		}
	}
	if send > 0 {
		return unix.SetsockoptInt(int(fd), unix.SOL_SOCKET, unix.SO_SNDBUF, send)
	}
	return nil
}
