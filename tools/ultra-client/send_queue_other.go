//go:build !linux && !darwin

package main

func sendQueuedBytes(uintptr) (int, error) {
	return 0, errSendQueueUnsupported
}
