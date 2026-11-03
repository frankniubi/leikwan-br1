package main

import (
	"fmt"
	"net"
	"strings"
	"time"
)

func providerNetwork(base, address string) string {
	host, _, err := net.SplitHostPort(address)
	if err == nil {
		if ip := net.ParseIP(host); ip != nil {
			if ip.To4() != nil {
				return base + "4"
			}
			return base + "6"
		}
	}
	return base
}

func providerDialer(config Config, network string, timeout time.Duration) (*net.Dialer, error) {
	dialer := &net.Dialer{Timeout: timeout}
	if err := configureProviderDialer(dialer, network, config); err != nil {
		return nil, err
	}
	return dialer, nil
}

func dialProviderTCP(config Config, address string, timeout time.Duration) (net.Conn, error) {
	network := providerNetwork("tcp", address)
	dialer, err := providerDialer(config, network, timeout)
	if err != nil {
		return nil, err
	}
	return dialer.Dial(network, address)
}

func dialProviderUDP(config Config, address string, timeout time.Duration) (*net.UDPConn, error) {
	network := providerNetwork("udp", address)
	dialer, err := providerDialer(config, network, timeout)
	if err != nil {
		return nil, err
	}
	connection, err := dialer.Dial(network, address)
	if err != nil {
		return nil, err
	}
	udp, ok := connection.(*net.UDPConn)
	if !ok {
		connection.Close()
		return nil, fmt.Errorf("provider UDP dial returned %s", strings.TrimSpace(network))
	}
	return udp, nil
}
