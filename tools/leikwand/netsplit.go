package main

import (
	"strconv"
	"strings"
)

// splitHostPortDefault splits "host:port" into its parts, falling back to the
// supplied port when the input carries none. It handles bracketed IPv6
// literals, which net.SplitHostPort also accepts, without pulling the net
// package into the config generator's tests.
func splitHostPortDefault(value string, fallback int) (string, int) {
	value = strings.TrimSpace(value)
	if value == "" {
		return "", fallback
	}
	// Bracketed IPv6, optionally with a port: [::1] or [::1]:53
	if strings.HasPrefix(value, "[") {
		if end := strings.Index(value, "]"); end >= 0 {
			host := value[1:end]
			rest := strings.TrimPrefix(value[end+1:], ":")
			if rest == "" {
				return host, fallback
			}
			if parsed, err := strconv.Atoi(rest); err == nil && parsed > 0 && parsed <= 65535 {
				return host, parsed
			}
			return host, fallback
		}
	}
	// A bare IPv6 literal has more than one colon and no port.
	if strings.Count(value, ":") > 1 {
		return value, fallback
	}
	if idx := strings.LastIndex(value, ":"); idx >= 0 {
		host := value[:idx]
		portText := value[idx+1:]
		if parsed, err := strconv.Atoi(portText); err == nil && parsed > 0 && parsed <= 65535 {
			return host, parsed
		}
		return host, fallback
	}
	return value, fallback
}
