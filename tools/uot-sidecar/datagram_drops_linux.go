package main

import (
	"fmt"
	"net"
	"os"
	"strconv"
	"strings"
)

// Per-socket drops, not host-wide /proc/net/snmp counters. A nil return means
// unavailable/closed; it must never be serialized as a measured zero.
func datagramSocketDrops(c *net.UDPConn) *uint64 {
	raw, e := c.SyscallConn()
	if e != nil {
		return nil
	}
	inode := ""
	e = raw.Control(func(fd uintptr) {
		link, e := os.Readlink(fmt.Sprintf("/proc/self/fd/%d", fd))
		if e == nil && strings.HasPrefix(link, "socket:[") {
			inode = strings.TrimSuffix(strings.TrimPrefix(link, "socket:["), "]")
		}
	})
	if e != nil || inode == "" {
		return nil
	}
	for _, path := range []string{"/proc/net/udp", "/proc/net/udp6"} {
		b, e := os.ReadFile(path)
		if e != nil {
			continue
		}
		for _, line := range strings.Split(string(b), "\n") {
			fields := strings.Fields(line)
			if len(fields) >= 13 && fields[9] == inode {
				n, e := strconv.ParseUint(fields[len(fields)-1], 10, 64)
				if e == nil {
					return &n
				}
			}
		}
	}
	return nil
}
