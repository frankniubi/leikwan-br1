package main

import (
	"encoding/json"
	"net"
	"strings"
	"time"
)

type leaseFrame struct {
	Protocol            string `json:"protocol"`
	ForwardID           int    `json:"forward_id"`
	SessionTicket       string `json:"session_ticket"`
	PolicyGeneration    string `json:"policy_generation"`
	LeaseID             string `json:"lease_id"`
	ExpiresAt           string `json:"expires_at"`
	Transport           string `json:"transport"`
	RateLimitMbps       int64  `json:"rate_limit_mbps"`
	QuotaRemainingBytes int64  `json:"quota_remaining_bytes"`
	TargetDigest        string `json:"target_digest"`
	SelectionRef        string `json:"selection_ref"`
	SelectionTier       string `json:"selection_tier"`
	NonceHash           string `json:"nonce_hash"`
	Endpoint            struct {
		IP   string `json:"ip"`
		Port int    `json:"port"`
	} `json:"endpoint"`
	Credential struct {
		User     string `json:"user"`
		Password string `json:"password"`
	} `json:"credential"`
}

func (f leaseFrame) decode(config Config, now time.Time) (Policy, ProviderLease, error) {
	expiresAt, err := time.Parse(time.RFC3339, strings.TrimSpace(f.ExpiresAt))
	if err != nil {
		return Policy{}, ProviderLease{}, ErrLeaseInvalid
	}
	selectionTier, tierErr := normalizeSelectionTier(f.SelectionTier)
	if tierErr != nil {
		return Policy{}, ProviderLease{}, ErrLeaseInvalid
	}
	policy := Policy{
		Protocol: f.Protocol, ForwardID: f.ForwardID, SessionTicket: f.SessionTicket,
		PolicyGeneration: f.PolicyGeneration, ExpiresAt: expiresAt,
		Transport: f.Transport, RateLimitMbps: f.RateLimitMbps,
		QuotaRemainingBytes: f.QuotaRemainingBytes, TargetDigest: f.TargetDigest,
	}
	lease := ProviderLease{
		LeaseID: f.LeaseID, PolicyGeneration: f.PolicyGeneration, ExpiresAt: expiresAt,
		Transport: f.Transport, SelectionRef: f.SelectionRef, SelectionTier: selectionTier, NonceHash: f.NonceHash,
		IP: net.ParseIP(strings.TrimSpace(f.Endpoint.IP)).String(), Port: f.Endpoint.Port,
		User: []byte(f.Credential.User), Password: []byte(f.Credential.Password),
	}
	if lease.IP == "<nil>" {
		lease.IP = ""
	}
	if err := policy.Validate(config, now); err != nil {
		lease.wipe()
		return Policy{}, ProviderLease{}, err
	}
	if err := lease.Validate(policy, now); err != nil {
		lease.wipe()
		return Policy{}, ProviderLease{}, err
	}
	return policy, lease, nil
}

func decodeLeaseFrame(raw []byte, config Config) (Policy, ProviderLease, error) {
	var frame leaseFrame
	if err := json.Unmarshal(raw, &frame); err != nil {
		return Policy{}, ProviderLease{}, ErrFrameInvalid
	}
	return frame.decode(config, time.Now())
}

func decodeLeaseBatchFrame(raw []byte, config Config) (Policy, []ProviderLease, error) {
	var wrapper struct {
		Leases []json.RawMessage `json:"leases"`
	}
	if err := json.Unmarshal(raw, &wrapper); err != nil {
		return Policy{}, nil, ErrFrameInvalid
	}
	if wrapper.Leases == nil {
		policy, lease, err := decodeLeaseFrame(raw, config)
		if err != nil {
			return Policy{}, nil, err
		}
		return policy, []ProviderLease{lease}, nil
	}
	if len(wrapper.Leases) < 1 || len(wrapper.Leases) > 4 {
		return Policy{}, nil, ErrFrameInvalid
	}
	var policy Policy
	leases := make([]ProviderLease, 0, len(wrapper.Leases))
	for index, encoded := range wrapper.Leases {
		candidatePolicy, lease, err := decodeLeaseFrame(encoded, config)
		if err != nil {
			for candidateIndex := range leases {
				leases[candidateIndex].wipe()
			}
			return Policy{}, nil, err
		}
		if index == 0 {
			policy = candidatePolicy
		} else if candidatePolicy.Protocol != policy.Protocol ||
			candidatePolicy.ForwardID != policy.ForwardID ||
			candidatePolicy.PolicyGeneration != policy.PolicyGeneration ||
			candidatePolicy.SessionTicket != policy.SessionTicket ||
			!candidatePolicy.ExpiresAt.Equal(policy.ExpiresAt) ||
			candidatePolicy.Transport != policy.Transport ||
			candidatePolicy.RateLimitMbps != policy.RateLimitMbps ||
			candidatePolicy.QuotaRemainingBytes != policy.QuotaRemainingBytes ||
			candidatePolicy.TargetDigest != policy.TargetDigest {
			lease.wipe()
			for candidateIndex := range leases {
				leases[candidateIndex].wipe()
			}
			return Policy{}, nil, ErrPolicyInvalid
		}
		leases = append(leases, lease)
	}
	return policy, leases, nil
}
