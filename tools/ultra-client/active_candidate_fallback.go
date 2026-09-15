package main

import (
	"context"
	"fmt"
	"strings"
	"time"
)

// activeCandidateForwardTest is injectable so the selection fallback can be
// tested without opening provider sockets. The production implementation below
// performs the same transport checks the runtime uses for a real forward.
type activeCandidateForwardTest func(context.Context, Config, Policy, ProviderLease) (udpProbeResult, error)

// sameActiveCandidatePath deliberately ignores rotating lease identifiers. A
// refresh may mint a new selection reference and nonce for the same endpoint,
// while the endpoint plus logical user still identifies the path the runtime
// was using. Empty users are rejected because a relay can carry multiple lines.
func sameActiveCandidatePath(left, right ProviderLease) bool {
	if !strings.EqualFold(strings.TrimSpace(left.IP), strings.TrimSpace(right.IP)) || left.Port != right.Port {
		return false
	}
	if leftRef, rightRef := strings.TrimSpace(left.SelectionRef), strings.TrimSpace(right.SelectionRef); leftRef != "" && leftRef == rightRef {
		return true
	}
	leftUser, rightUser := strings.TrimSpace(string(left.User)), strings.TrimSpace(string(right.User))
	return leftUser != "" && rightUser != "" && leftUser == rightUser
}

// retryActiveCandidateForward runs one real forwarding check against the
// candidate corresponding to the path that was already carrying traffic. It is
// only a recovery from a false-negative probe batch; it never authorizes a path
// outside the newly delivered lease set.
func retryActiveCandidateForward(
	ctx context.Context,
	config Config,
	policy Policy,
	previous RuntimeSnapshot,
	leases []ProviderLease,
	test activeCandidateForwardTest,
) (ProviderLease, udpProbeResult, error) {
	if test == nil {
		test = directForwardCandidateTest
	}
	if previous.Policy.TargetDigest != policy.TargetDigest ||
		!strings.EqualFold(previous.Policy.Transport, policy.Transport) ||
		!strings.EqualFold(previous.Policy.RouteScope, policy.RouteScope) ||
		!strings.EqualFold(previous.Policy.RoutePolicy, policy.RoutePolicy) {
		return ProviderLease{}, udpProbeResult{}, errNoHealthyCandidate
	}
	var lastErr error
	matching := make([]int, 0, 2)
	for index := range leases {
		candidate := leases[index]
		if !sameActiveCandidatePath(previous.Lease, candidate) {
			continue
		}
		if strings.TrimSpace(previous.Lease.SelectionRef) != "" &&
			strings.TrimSpace(previous.Lease.SelectionRef) == strings.TrimSpace(candidate.SelectionRef) {
			matching = []int{index}
			break
		}
		matching = append(matching, index)
	}
	if len(matching) != 1 {
		// An endpoint can carry more than one logical line. Without an exact
		// selection reference, an ambiguous match is not safe to rescue.
		return ProviderLease{}, udpProbeResult{}, errNoHealthyCandidate
	}
	for _, index := range matching {
		candidate := leases[index]
		result, err := test(ctx, config, policy, candidate)
		if err != nil {
			lastErr = err
			continue
		}
		result.Index = index
		result.SelectionTier = candidate.SelectionTier
		return candidate, result, nil
	}
	if lastErr != nil {
		return ProviderLease{}, udpProbeResult{}, fmt.Errorf("%w: active candidate forwarding test: %v", errNoHealthyCandidate, lastErr)
	}
	return ProviderLease{}, udpProbeResult{}, errNoHealthyCandidate
}

func directForwardCandidateTest(ctx context.Context, config Config, policy Policy, lease ProviderLease) (udpProbeResult, error) {
	result := udpProbeResult{SelectionTier: lease.SelectionTier}
	if err := ctx.Err(); err != nil {
		return result, err
	}
	transport := strings.ToLower(strings.TrimSpace(policy.Transport))
	if transport != "tcp" && transport != "udp" && transport != "tcp+udp" {
		return result, fmt.Errorf("unsupported transport %q", policy.Transport)
	}

	if transport == "tcp" || transport == "tcp+udp" {
		targetHost, targetPort, err := parseTarget(config.Target)
		if err != nil {
			result.TCPError = wrapProbeFailure(errProbeTCPFailed, err)
			return result, result.TCPError
		}
		timeout := activeForwardTimeout(ctx, configuredConnectTimeout(config))
		if timeout <= 0 {
			result.TCPError = wrapProbeFailure(errProbeTCPFailed, context.DeadlineExceeded)
			return result, result.TCPError
		}
		started := time.Now()
		connection, err := dialStream(lease, targetHost, targetPort, timeout)
		result.TCPSent = 1
		if err != nil {
			result.TCPError = wrapProbeFailure(errProbeTCPFailed, err)
			return result, result.TCPError
		}
		_ = connection.Close()
		result.TCPReceived = 1
		result.TCPRTT = time.Since(started)
	}

	if transport == "udp" || transport == "tcp+udp" {
		if err := ctx.Err(); err != nil {
			result.Err = wrapProbeFailure(errProbeUDPFailed, err)
			return result, result.Err
		}
		timeout := activeForwardTimeout(ctx, probeAssociationTimeout(config))
		if timeout <= 0 {
			result.Err = wrapProbeFailure(errProbeUDPFailed, context.DeadlineExceeded)
			return result, result.Err
		}
		started := time.Now()
		session, err := armDatagram(lease, timeout)
		if err != nil {
			result.Err = wrapProbeFailure(errProbeUDPFailed, err)
			return result, result.Err
		}
		payload := []byte("leikw-an-active-forward-check")
		ok := session.probeEcho(payload, activeForwardTimeout(ctx, probeTimeout(config)))
		session.Close()
		result.Sent = 1
		if !ok {
			result.Err = wrapProbeFailure(errProbeUDPFailed, errProbeUnavailable)
			return result, result.Err
		}
		result.Received = 1
		result.MedianRTT = time.Since(started)
		result.P95RTT = result.MedianRTT
	}
	return result, nil
}

func configuredConnectTimeout(config Config) time.Duration {
	seconds := config.ConnectTimeoutSecs
	if seconds < 1 || seconds > 30 {
		seconds = 15
	}
	return time.Duration(seconds) * time.Second
}

// Keep enough of the control deadline in reserve for the one-shot recovery
// check after the normal probe batch has exhausted its candidates.
func activeCandidateFallbackBudget(config Config) time.Duration {
	return configuredConnectTimeout(config) + probeAssociationTimeout(config) + probeTimeout(config) + time.Second
}

func activeForwardTimeout(ctx context.Context, requested time.Duration) time.Duration {
	if requested <= 0 {
		return 0
	}
	if deadline, ok := ctx.Deadline(); ok {
		remaining := time.Until(deadline)
		if remaining < requested {
			return remaining
		}
	}
	return requested
}
