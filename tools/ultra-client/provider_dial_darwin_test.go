//go:build darwin

package main

import (
	"net"
	"syscall"
	"testing"

	"golang.org/x/sys/unix"
)

func TestDarwinDirectEgressRequiresPhysicalInterface(t *testing.T) {
	for _, config := range []Config{
		{DirectEgressRequired: true},
		{DirectEgressRequired: true, EgressInterface: "utun404", EgressInterfaceIndex: 999999},
	} {
		if _, err := configureProviderDialer(&net.Dialer{}, "tcp", config); err == nil {
			t.Fatal("strict egress accepted an absent interface")
		}
	}
	if _, err := configureProviderDialer(&net.Dialer{}, "tcp", Config{}); err != nil {
		t.Fatal("legacy VPN mode changed:", err)
	}
	iface, err := net.InterfaceByName("lo0")
	if err == nil {
		if _, err := configureProviderDialer(&net.Dialer{}, "tcp", Config{DirectEgressRequired: true, EgressInterface: iface.Name, EgressInterfaceIndex: iface.Index}); err == nil {
			t.Fatal("strict egress accepted loopback")
		}
	}
}

func TestDarwinBoundInterfaceUsesActualSocketFamily(t *testing.T) {
	iface, err := net.InterfaceByName("lo0")
	if err != nil {
		t.Skip("Darwin loopback unavailable")
	}
	for _, family := range []struct {
		network               string
		domain, level, option int
	}{
		{"tcp4", unix.AF_INET, unix.IPPROTO_IP, darwinIPBoundIf},
		{"tcp6", unix.AF_INET6, unix.IPPROTO_IPV6, darwinIPv6BoundIf},
	} {
		t.Run(family.network, func(t *testing.T) {
			dialer := &net.Dialer{}
			// Generic caller network; Control receives the resolved family.
			if _, err := configureProviderDialer(dialer, "tcp", Config{EgressInterface: iface.Name, EgressInterfaceIndex: iface.Index}); err != nil {
				t.Fatal(err)
			}
			fd, err := unix.Socket(family.domain, unix.SOCK_STREAM, 0)
			if err != nil {
				t.Fatal(err)
			}
			defer unix.Close(fd)
			if err := dialer.Control(family.network, "", testDarwinRawConn{fd}); err != nil {
				t.Fatal(err)
			}
			index, err := unix.GetsockoptInt(fd, family.level, family.option)
			if err != nil || index != iface.Index {
				t.Fatalf("bound interface %d, error %v", index, err)
			}
		})
	}
}

type testDarwinRawConn struct{ fd int }

var _ syscall.RawConn = testDarwinRawConn{}

func (r testDarwinRawConn) Control(f func(uintptr)) error    { f(uintptr(r.fd)); return nil }
func (r testDarwinRawConn) Read(f func(uintptr) bool) error  { f(uintptr(r.fd)); return nil }
func (r testDarwinRawConn) Write(f func(uintptr) bool) error { f(uintptr(r.fd)); return nil }
