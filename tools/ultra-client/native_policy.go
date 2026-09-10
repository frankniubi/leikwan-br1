package main

import (
	"errors"
	"strings"
)

const (
	nativeRoutePolicyRestricted   = "restricted"
	nativeRoutePolicyUnrestricted = "unrestricted"
	nativeDefaultPortMin          = 1000
)

var errNativePortRejected = errors.New("native_policy_port_rejected")

// NativeRoutePolicy is authenticated by the Panel lease and repeated in the
// relay config so both sing-box and the relay enforce the same boundary.
type NativeRoutePolicy string

func ParseNativeRoutePolicy(value string) (NativeRoutePolicy, error) {
	normalized := strings.ToLower(strings.TrimSpace(value))
	if normalized == "" {
		return NativeRoutePolicy(nativeRoutePolicyRestricted), nil
	}
	switch normalized {
	case nativeRoutePolicyRestricted, nativeRoutePolicyUnrestricted:
		return NativeRoutePolicy(normalized), nil
	default:
		return "", ErrPolicyInvalid
	}
}

func nativeRoutePolicyForConfig(config Config) NativeRoutePolicy {
	policy, err := ParseNativeRoutePolicy(config.NativeRoutePolicy)
	if err != nil {
		return NativeRoutePolicy(nativeRoutePolicyRestricted)
	}
	return policy
}

func (policy NativeRoutePolicy) AllowsPort(port int) bool {
	if port < 1 || port > 65535 {
		return false
	}
	if policy == NativeRoutePolicy(nativeRoutePolicyUnrestricted) {
		return true
	}
	return port >= nativeDefaultPortMin
}

func (policy NativeRoutePolicy) FilterProfile() string {
	if policy == NativeRoutePolicy(nativeRoutePolicyUnrestricted) {
		return "off"
	}
	return "opengfw_tcp"
}

func nativePortAllowed(config Config, port int) bool {
	policy := nativeRoutePolicyForConfig(config)
	if !policy.AllowsPort(port) {
		return false
	}
	if policy == NativeRoutePolicy(nativeRoutePolicyUnrestricted) {
		return true
	}
	minimum := config.NativePortMin
	if minimum < nativeDefaultPortMin || minimum > 65535 {
		minimum = nativeDefaultPortMin
	}
	return port >= minimum && port <= 65535
}
