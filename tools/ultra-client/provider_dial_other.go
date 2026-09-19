//go:build !darwin && !windows

package main

import "net"

func configureProviderDialer(dialer *net.Dialer, network string, config Config) error {
	_ = dialer
	_ = network
	_ = config
	return nil
}
