//go:build windows

package main

import "golang.org/x/sys/windows"

func setRawSocketBuffers(fd uintptr, receive, send int) error {
	if receive > 0 {
		if err := windows.SetsockoptInt(windows.Handle(fd), windows.SOL_SOCKET, windows.SO_RCVBUF, receive); err != nil {
			return err
		}
	}
	if send > 0 {
		return windows.SetsockoptInt(windows.Handle(fd), windows.SOL_SOCKET, windows.SO_SNDBUF, send)
	}
	return nil
}
