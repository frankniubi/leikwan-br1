package main

import (
	"context"
	"sync"
)

// Auth ACKs alone do not prove forwarding. Revalidate every external-UoT
// renewal through HELLO, retaining only paths which actually reached the
// authenticated sidecar. Failed probes must never become fallback candidates.
func selectVerifiedExternalUoTCandidates(ctx context.Context, config Config, policy Policy,
	leases []ProviderLease, previous RuntimeSnapshot, havePrevious bool,
	onFailure probeFailureSink, probe leaseProbe) (ProviderLease, udpProbeResult, []ProviderLease, error) {
	if probe == nil {
		probe = probeExternalUoTLease
	}
	var mu sync.Mutex
	proven := make(map[string]udpProbeResult)
	wrapped := func(ctx context.Context, lease ProviderLease, c Config, p Policy) udpProbeResult {
		result := probe(ctx, lease, c, p)
		if result.Err == nil && result.TCPError == nil && result.TCPReceived > 0 {
			mu.Lock()
			proven[leaseSelectionKey(lease)] = result
			mu.Unlock()
		}
		return result
	}
	// The wire carrier is TCP, but the health evidence belongs to logical UDP.
	// A successful HELLO must not rehabilitate an unrelated user-TCP failure.
	var report probeFailureSink
	if onFailure != nil {
		report = func(lease ProviderLease, result udpProbeResult) {
			result.TCPSent, result.TCPReceived = 0, 0
			result.Sent, result.Received = 1, 0
			if result.Err == nil {
				result.Err = result.TCPError
			}
			onFailure(lease, result)
		}
	}
	selected, result, err := selectBestLeaseWithProbeReporting(
		ctx, config, effectiveProbePolicy(config, policy), leases, wrapped, report)
	if err != nil {
		return ProviderLease{}, result, nil, err
	}
	eligible := make([]ProviderLease, 0, len(proven))
	for _, lease := range leases {
		if measured, ok := proven[leaseSelectionKey(lease)]; ok {
			eligible = append(eligible, lease)
			// A new authorization generation need not change the physical
			// path. Keep a healthy path only when this NEW lease passed its
			// probe and the target/transport scope is unchanged. Reusing the
			// old credential, or trusting an earlier probe, is never allowed.
			if havePrevious && previous.Policy.TargetDigest == policy.TargetDigest &&
				previous.Policy.Transport == policy.Transport &&
				previous.Policy.RouteScope == policy.RouteScope &&
				previous.Policy.RoutePolicy == policy.RoutePolicy &&
				sameActiveCandidatePath(previous.Lease, lease) {
				selected, result = lease, measured
			}
		}
	}
	if len(eligible) == 0 {
		return ProviderLease{}, result, nil, errNoHealthyCandidate
	}
	return selected, result, eligible, nil
}
