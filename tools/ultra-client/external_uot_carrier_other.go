//go:build !windows

package main

import "net"

// Linux, Android and macOS autotune the TCP send buffer to the path (tcp_wmem /
// net.inet.tcp.autosndbuf), and a fixed SO_SNDBUF would switch that off. The
// carriers there already kept up at 10 Mbit/s on every measured path.
func tuneExternalUoTCarrier(connection net.Conn) int {
	_ = connection
	return 0
}
