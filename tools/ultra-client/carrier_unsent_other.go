//go:build !darwin

package main

import "syscall"

// carrierUnsentBytes is only needed where the kernel does not cap the unsent
// backlog itself: Linux and Android enforce TCP_NOTSENT_LOWAT on write.
func carrierUnsentBytes(syscall.RawConn) (int, bool) {
	return 0, false
}
