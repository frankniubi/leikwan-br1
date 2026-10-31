package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"net"
	"strings"
	"time"
)

// Native outbound leases.
//
// A native lease is minted against the carrier forward's ordinary Ultra grant
// (session ticket + policy generation) plus a scope digest that binds it to a
// direction, route scope and route policy. The request and the checks on the
// answer are a transcription of the desktop client's
// ResolveNativeOutboundLeasesAsync / ParseNativeOutboundLease, so the Panel
// sees one wire shape from every platform.

// nativeExpectedDigest is the digest the Panel will accept and echo back as
// scope_digest. Restricted leases keep the v1 digest the desktop sends (the
// Panel accepts v1 and v2 there); unrestricted only exists in v2.
func nativeExpectedDigest(forwardID int, direction, scope, policy string) string {
	if normalizeRoutePolicy(policy) == "unrestricted" {
		return nativeScopeDigestV2(forwardID, direction, "tcp+udp", scope, "unrestricted")
	}
	return nativeScopeDigestV1(forwardID, direction, "tcp+udp", scope)
}

func newLeaseNonce() (string, error) {
	var raw [16]byte
	if _, err := rand.Read(raw[:]); err != nil {
		return "", err
	}
	return hex.EncodeToString(raw[:]), nil
}

func buildNativeLeaseRequest(deviceID string, plan carrierPlan, policy *UltraPolicy, maxCandidates int) (LeaseRequest, error) {
	if policy == nil {
		return LeaseRequest{}, errors.New("native carrier has no Ultra grant")
	}
	nonce, err := newLeaseNonce()
	if err != nil {
		return LeaseRequest{}, err
	}
	return LeaseRequest{
		DeviceID: deviceID,
		Forwards: []LeaseRequestForward{{
			Purpose:          nativeLeasePurpose,
			ForwardID:        plan.Carrier.ForwardID,
			SessionTicket:    policy.SessionTicket,
			PolicyGeneration: strings.ToLower(strings.TrimSpace(policy.PolicyGeneration)),
			Nonce:            nonce,
			TargetDigest:     nativeExpectedDigest(plan.Carrier.ForwardID, plan.Native.Direction, plan.Scope, plan.Policy),
			Transport:        "tcp+udp",
			RouteScope:       plan.Scope,
			RoutePolicy:      plan.Policy,
			SelectionMode:    "auto",
			MaxCandidates:    clampInt(maxCandidates, 1, 4),
		}},
	}, nil
}

// validateNativeLeases checks every lease of a native batch and returns the
// frames ultra-client expects on its control socket. One bad lease rejects
// the whole batch, exactly like the desktop: a partial batch would hand the
// runtime a candidate set the Panel did not intend.
func validateNativeLeases(leases []LeaseFrame, plan carrierPlan, policy *UltraPolicy, now time.Time) ([]LeaseFrame, error) {
	if len(leases) == 0 || len(leases) > 4 {
		return nil, fmt.Errorf("native lease batch size %d", len(leases))
	}
	policyExpiry, err := parseRFC3339(policy.ExpiresAt)
	if err != nil {
		return nil, errors.New("native grant expiry invalid")
	}
	expected := nativeExpectedDigest(plan.Carrier.ForwardID, plan.Native.Direction, plan.Scope, plan.Policy)
	frames := make([]LeaseFrame, 0, len(leases))
	for _, lease := range leases {
		reason := ""
		switch {
		case lease.Protocol != nativeLeaseProtocol:
			reason = "protocol"
		case lease.Purpose != nativeLeasePurpose:
			reason = "purpose"
		case lease.ForwardID != plan.Carrier.ForwardID:
			reason = "forward_id"
		case lease.PolicyGeneration != strings.ToLower(strings.TrimSpace(policy.PolicyGeneration)):
			reason = "policy_generation"
		case !strings.EqualFold(lease.Transport, "tcp+udp"):
			reason = "transport"
		case lease.Direction != plan.Native.Direction:
			reason = "direction"
		case lease.RouteScope != plan.Scope:
			reason = "route_scope"
		case normalizeRoutePolicy(lease.RoutePolicy) != plan.Policy:
			reason = "route_policy"
		case !strings.EqualFold(strings.TrimSpace(lease.ScopeDigest), expected):
			reason = "scope_digest"
		}
		if reason == "" {
			expiresAt, parseErr := parseRFC3339(lease.ExpiresAt)
			switch {
			case parseErr != nil || !expiresAt.After(now):
				reason = "expires_at"
			case expiresAt.After(policyExpiry.Add(time.Second)):
				reason = "expires_after_grant"
			case expiresAt.After(now.Add(5*time.Minute + 30*time.Second)):
				reason = "expiry_window"
			}
		}
		if reason == "" {
			switch {
			case net.ParseIP(strings.TrimSpace(lease.Endpoint.IP)) == nil || lease.Endpoint.Port < 1 || lease.Endpoint.Port > 65535:
				reason = "endpoint"
			case len(lease.Credential.User) == 0 || len(lease.Credential.User) > 255 ||
				len(lease.Credential.Password) == 0 || len(lease.Credential.Password) > 255:
				reason = "credential"
			case lease.CandidateKeyHash != "" && !isHex64(strings.ToLower(strings.TrimSpace(lease.CandidateKeyHash))):
				reason = "candidate_key_hash"
			}
		}
		if reason != "" {
			return nil, fmt.Errorf("native lease rejected locally: %s", reason)
		}
		frames = append(frames, nativeRuntimeFrame(lease))
	}
	return frames, nil
}

// nativeRuntimeFrame is the control-socket shape: ultra-client reads the
// scope binding from target_digest, and a native frame has no session ticket.
func nativeRuntimeFrame(lease LeaseFrame) LeaseFrame {
	frame := lease
	frame.TargetDigest = strings.ToLower(strings.TrimSpace(lease.ScopeDigest))
	frame.ScopeDigest = ""
	frame.SessionTicket = ""
	frame.CandidateKeyHash = strings.ToLower(strings.TrimSpace(lease.CandidateKeyHash))
	frame.DirectIPCidrs = nil
	return frame
}

// requestNativeLeases asks the Panel for a native batch and validates it.
func (a *Agent) requestNativeLeases(ctx context.Context, plan carrierPlan) ([]LeaseFrame, []string, error) {
	policy := plan.Carrier.UltraAccess
	request, err := buildNativeLeaseRequest(a.deviceID, plan, policy, a.cfg.MaxCandidates)
	if err != nil {
		return nil, nil, err
	}
	leaseCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	response, err := a.panel.Lease(leaseCtx, request)
	cancel()
	if err != nil {
		return nil, nil, fmt.Errorf("native lease request: %w%s", err, leaseRefusalHint(err))
	}
	a.noteUnsealFailures("gateway", plan.Carrier.ForwardID, response)
	if !response.OK || len(response.Leases) == 0 {
		return nil, nil, fmt.Errorf("native lease refused: %s", firstNonEmpty(response.Error, "no_leases"))
	}
	frames, err := validateNativeLeases(response.Leases, plan, policy, time.Now())
	if err != nil {
		return nil, nil, err
	}
	return frames, nil, nil
}
