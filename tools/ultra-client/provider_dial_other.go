//go:build !darwin && !windows && !android

package main

import "net"

func configureProviderDialer(dialer *net.Dialer, network string, config Config) (bool, error) {
	_ = dialer
	_ = network
	if config.DirectEgressRequired {
		return false, errDirectEgressUnavailable
	}
	return false, nil
}
