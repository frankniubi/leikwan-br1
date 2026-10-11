//go:build darwin

package main

import "golang.org/x/sys/unix"

// sendQueuedBytes is the data still held in the socket send buffer, which on
// macOS includes bytes sent but not yet acknowledged.
func sendQueuedBytes(fd uintptr) (int, error) {
	return unix.GetsockoptInt(int(fd), unix.SOL_SOCKET, unix.SO_NWRITE)
}
