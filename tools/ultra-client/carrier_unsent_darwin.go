//go:build darwin

package main

import (
	"syscall"

	"golang.org/x/sys/unix"
)

// carrierUnsentBytes estimates what the kernel holds unsent for a carrier.
//
// macOS honours TCP_NOTSENT_LOWAT only for writability: a write() that finds
// room still fills the whole send buffer, so a carrier that slows down hides
// seconds of datagrams in the kernel where the lag detector cannot see them
// (4.6 s on a GitHub arm64 runner). The send buffer holds what is in flight
// plus what is unsent, and at most min(cwnd, peer window) can be in flight.
func carrierUnsentBytes(raw syscall.RawConn) (int, bool) {
	var info *unix.TCPConnectionInfo
	var err error
	if controlErr := raw.Control(func(fd uintptr) {
		info, err = unix.GetsockoptTCPConnectionInfo(int(fd), unix.IPPROTO_TCP, unix.TCP_CONNECTION_INFO)
	}); controlErr != nil || err != nil || info == nil {
		return 0, false
	}
	window := info.Snd_cwnd
	if info.Snd_wnd < window {
		window = info.Snd_wnd
	}
	if info.Snd_sbbytes <= window {
		return 0, true
	}
	return int(info.Snd_sbbytes - window), true
}
