//go:build !windows && !linux && !darwin

package main

import "net"

// Other platforms keep the operating system's carrier socket defaults.
func tuneExternalUoTCarrier(connection net.Conn) (sendBuffer, notSentLowat int) {
	_ = connection
	return 0, 0
}
