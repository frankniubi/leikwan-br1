//go:build !windows && !linux && !darwin

package main

import "net"

func sameUserPeer(connection *net.UnixConn) bool {
	return false
}
