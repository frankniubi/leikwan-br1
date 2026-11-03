//go:build darwin

package main

import (
	"fmt"
	"net"
	"strings"
	"syscall"

	"golang.org/x/sys/unix"
)

const (
	darwinIPBoundIf   = 25
	darwinIPv6BoundIf = 125
)

func configureProviderDialer(dialer *net.Dialer, network string, config Config) error {
	if config.EgressInterfaceIndex <= 0 && strings.TrimSpace(config.EgressInterface) == "" {
		return nil
	}
	if config.EgressInterfaceIndex <= 0 {
		return fmt.Errorf("egress interface index unavailable")
	}
	if address := net.ParseIP(config.EgressLocalAddress); address != nil {
		if strings.HasSuffix(network, "6") {
			if address.To4() == nil {
				if strings.HasPrefix(network, "udp") {
					dialer.LocalAddr = &net.UDPAddr{IP: address}
				} else {
					dialer.LocalAddr = &net.TCPAddr{IP: address}
				}
			}
		} else if address.To4() != nil {
			if strings.HasPrefix(network, "udp") {
				dialer.LocalAddr = &net.UDPAddr{IP: address.To4()}
			} else {
				dialer.LocalAddr = &net.TCPAddr{IP: address.To4()}
			}
		}
	}
	dialer.Control = func(_network, _address string, raw syscall.RawConn) error {
		var bindErr error
		if err := raw.Control(func(fd uintptr) {
			if strings.HasSuffix(network, "6") {
				bindErr = unix.SetsockoptInt(int(fd), unix.IPPROTO_IPV6, darwinIPv6BoundIf, config.EgressInterfaceIndex)
				return
			}
			bindErr = unix.SetsockoptInt(int(fd), unix.IPPROTO_IP, darwinIPBoundIf, config.EgressInterfaceIndex)
		}); err != nil {
			return err
		}
		return bindErr
	}
	return nil
}
