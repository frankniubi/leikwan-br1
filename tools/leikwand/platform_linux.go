//go:build linux

package main

import "syscall"

// detachedProcAttr starts each supervised runtime in its own session so a
// console signal (for example Ctrl-C on the serial console) cannot tear down
// children behind the supervisor's back.
func detachedProcAttr() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{Setsid: true}
}

// bindToDevice pins a socket to one uplink. This is what makes a multi-WAN
// health probe authoritative: the kernel routes the probe through the device
// under test regardless of the current default route.
func bindToDevice(fd int, device string) error {
	return syscall.SetsockoptString(fd, syscall.SOL_SOCKET, syscall.SO_BINDTODEVICE, device)
}
