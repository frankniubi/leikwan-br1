//go:build linux

package main

import "golang.org/x/sys/unix"

// sendQueuedBytes is the TCP send queue the peer has not acknowledged yet
// (write_seq - snd_una).
func sendQueuedBytes(fd uintptr) (int, error) {
	return unix.IoctlGetInt(int(fd), unix.SIOCOUTQ)
}
