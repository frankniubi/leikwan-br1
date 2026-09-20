//go:build !linux

package main

import "net"

func datagramSocketDrops(c *net.UDPConn) *uint64 { return nil }
