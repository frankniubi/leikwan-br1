//go:build windows

package main

import (
	"fmt"
	"math/bits"
	"net"
	"strings"
	"syscall"

	"golang.org/x/sys/windows"
)

const (
	windowsIPUnicastIf   = 31
	windowsIPv6UnicastIf = 31
)

func configureProviderDialer(dialer *net.Dialer, network string, config Config) error {
	if config.EgressInterfaceIndex <= 0 && strings.TrimSpace(config.EgressInterface) == "" {
		return nil
	}
	if config.EgressInterfaceIndex <= 0 {
		return fmt.Errorf("egress interface index unavailable")
	}
	address := net.ParseIP(config.EgressLocalAddress)
	if address == nil {
		return fmt.Errorf("egress interface address unavailable")
	}
	isIPv6 := network == "tcp6" || network == "udp6"
	if isIPv6 {
		if address.To4() == nil {
			if network == "udp6" {
				dialer.LocalAddr = &net.UDPAddr{IP: address}
			} else {
				dialer.LocalAddr = &net.TCPAddr{IP: address}
			}
		}
	} else {
		if address.To4() != nil {
			if network == "udp4" {
				dialer.LocalAddr = &net.UDPAddr{IP: address.To4()}
			} else {
				dialer.LocalAddr = &net.TCPAddr{IP: address.To4()}
			}
		}
	}
	dialer.Control = func(_network, _address string, raw syscall.RawConn) error {
		var bindErr error
		if err := raw.Control(func(fd uintptr) {
			if isIPv6 {
				// IPV6_UNICAST_IF expects the interface index in host byte order.
				bindErr = windows.SetsockoptInt(
					windows.Handle(fd),
					windows.IPPROTO_IPV6,
					windowsIPv6UnicastIf,
					config.EgressInterfaceIndex,
				)
				return
			}
			// IP_UNICAST_IF expects the interface index in network byte order.
			value := int(bits.ReverseBytes32(uint32(config.EgressInterfaceIndex)))
			bindErr = windows.SetsockoptInt(
				windows.Handle(fd),
				windows.IPPROTO_IP,
				windowsIPUnicastIf,
				value,
			)
		}); err != nil {
			return err
		}
		return bindErr
	}
	return nil
}
