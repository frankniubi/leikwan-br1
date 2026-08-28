//go:build darwin

package main

import (
	"net"
	"os"

	"golang.org/x/sys/unix"
)

func sameUserPeer(connection *net.UnixConn) bool {
	raw, err := connection.SyscallConn()
	if err != nil {
		return false
	}
	var peerUID uint32
	var peerErr error
	if err := raw.Control(func(fd uintptr) {
		credentials, err := unix.GetsockoptXucred(int(fd), unix.SOL_LOCAL, unix.LOCAL_PEERCRED)
		if err != nil {
			peerErr = err
			return
		}
		peerUID = credentials.Uid
	}); err != nil || peerErr != nil {
		return false
	}
	return peerUID == uint32(os.Geteuid())
}
