//go:build !windows

package main

import (
	"runtime"

	"golang.org/x/sys/unix"
)

func applyProcessSecurity() error {
	limit := &unix.Rlimit{Cur: 0, Max: 0}
	return unix.Setrlimit(unix.RLIMIT_CORE, limit)
}

func lockSecret(value []byte) bool {
	return len(value) == 0 || unix.Mlock(value) == nil
}

func clearSecret(value []byte, locked bool) {
	clearBytes(value)
	runtime.KeepAlive(value)
	if locked && len(value) > 0 {
		_ = unix.Munlock(value)
	}
}
