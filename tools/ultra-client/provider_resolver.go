package main

import (
	"context"
	"errors"
	"fmt"
	"net"
	"strconv"
	"strings"
)

// providerResolver returns a resolver scoped to one runtime's egress policy.
// Strict direct mode never uses the process-wide resolver: every DNS query is
// sent through the same provider dial hook as relay TCP/UDP sockets.
func providerResolver(config Config) (*net.Resolver, error) {
	if !config.DirectEgressRequired {
		return net.DefaultResolver, nil
	}
	servers, err := normalizeEgressDNSServers(config.EgressDNSServers)
	if err != nil {
		return nil, err
	}
	return newFallbackResolver(config, servers), nil
}

func normalizeEgressDNSServers(values []string) ([]string, error) {
	if len(values) == 0 {
		return nil, errors.New("direct egress DNS servers unavailable")
	}
	result := make([]string, 0, len(values))
	seen := make(map[string]struct{}, len(values))
	for _, value := range values {
		value = strings.TrimSpace(value)
		host, port, err := net.SplitHostPort(value)
		if err != nil || net.ParseIP(host) == nil || port != "53" {
			return nil, fmt.Errorf("direct egress DNS server invalid: %q", value)
		}
		canonical := net.JoinHostPort(host, strconv.Itoa(53))
		if _, ok := seen[canonical]; ok {
			continue
		}
		seen[canonical] = struct{}{}
		result = append(result, canonical)
	}
	if len(result) == 0 {
		return nil, errors.New("direct egress DNS servers unavailable")
	}
	return result, nil
}

// lookupProviderIP resolves a hostname with the supplied runtime's resolver.
// Mobile hosts use this to resolve panel/control endpoints before connecting a
// literal address on the selected physical interface.
func lookupProviderIPs(ctx context.Context, config Config, host string) ([]net.IPAddr, error) {
	resolver, err := providerResolver(config)
	if err != nil {
		return nil, err
	}
	if strings.TrimSpace(host) == "" {
		return nil, errors.New("hostname unavailable")
	}
	return resolver.LookupIPAddr(ctx, host)
}
