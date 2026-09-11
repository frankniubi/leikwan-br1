//go:build !linux

package main

import (
	"errors"
	"syscall"
)

// The daemon targets OpenWrt (Linux). These stubs exist so the source still
// builds and unit tests still run on a developer workstation.

func detachedProcAttr() *syscall.SysProcAttr { return &syscall.SysProcAttr{} }

func bindToDevice(fd int, device string) error {
	_ = fd
	_ = device
	return errors.New("SO_BINDTODEVICE is only available on Linux")
}
