//go:build !linux && !darwin && !windows

package main

import (
	"errors"
	"net"
)

func datagramSocketBuffers(c *net.UDPConn) (int, int, error) {
	return 0, 0, errors.New("effective UDP buffer reporting unsupported on this OS")
}
