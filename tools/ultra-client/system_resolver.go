package main

import (
	"context"
	"errors"
	"log"
	"net"
	"os"
	"runtime"
	"sync/atomic"
	"time"
)

// Android has no /etc/resolv.conf. A CGO_ENABLED=0 build therefore uses Go's
// pure resolver with its built-in default of 127.0.0.1:53 and [::1]:53, where
// nothing listens, and every hostname target fails with "connection refused".
// The host app excludes its own UID from the VPN, so these queries leave on
// the physical network.
var fallbackDNSServers = []string{"223.5.5.5:53", "119.29.29.29:53", "223.6.6.6:53", "1.1.1.1:53"}

const resolvConfPath = "/etc/resolv.conf"

func needsFallbackResolver(goos string, stat func(string) (os.FileInfo, error)) bool {
	if goos != "android" {
		return false
	}
	_, err := stat(resolvConfPath)
	return err != nil
}

func installFallbackResolverIfNeeded(config Config) {
	if !needsFallbackResolver(runtime.GOOS, os.Stat) {
		return
	}
	net.DefaultResolver = newFallbackResolver(config, fallbackDNSServers)
	log.Printf("system resolver unavailable; using fallback DNS servers=%d", len(fallbackDNSServers))
}

// newFallbackResolver ignores the nameserver address Go derives from its
// defaults and rotates through servers instead, so the resolver's own retry
// loop (two default nameservers, two attempts each) reaches every server.
func newFallbackResolver(config Config, servers []string) *net.Resolver {
	var next atomic.Uint32
	return &net.Resolver{
		PreferGo: true,
		Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
			if len(servers) == 0 {
				return nil, errors.New("no fallback DNS server")
			}
			server := servers[int(next.Add(1)-1)%len(servers)]
			family := providerNetwork(network, server)
			dialer, err := providerDialer(config, family, 0)
			if err != nil {
				return nil, err
			}
			return dialer.DialContext(ctx, family, server)
		},
	}
}

var (
	udpTargetResolveInitialDelay = time.Second
	udpTargetResolveMaxDelay     = 30 * time.Second
)

// resolveUDPTargetIPv4 keeps retrying until the target resolves or ctx ends.
// The UDP listener shares its runtime with the TCP listener, so returning a
// resolution error would tear both down and restart them every second.
func resolveUDPTargetIPv4(ctx context.Context, host string, resolve func(context.Context, string) (string, error)) (string, error) {
	delay := udpTargetResolveInitialDelay
	for {
		address, err := resolve(ctx, host)
		if err == nil {
			return address, nil
		}
		if ctx.Err() != nil {
			return "", ctx.Err()
		}
		log.Printf("udp target resolution failed, retrying in %s: %v", delay, err)
		timer := time.NewTimer(delay)
		select {
		case <-ctx.Done():
			timer.Stop()
			return "", ctx.Err()
		case <-timer.C:
		}
		delay *= 2
		if delay > udpTargetResolveMaxDelay {
			delay = udpTargetResolveMaxDelay
		}
	}
}
