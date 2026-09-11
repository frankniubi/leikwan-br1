package main

import (
	"testing"
	"time"
)

func validPolicy(forward ForwardConfig, now time.Time) *UltraPolicy {
	return &UltraPolicy{
		Protocol:            "leikwan-ultra-policy-v2",
		ForwardID:           forward.ForwardID,
		PolicyGeneration:    "713bc338a375fae9a79078fbf811c74cdd969fcd3f98edbb93c82bb2517d3941",
		SessionTicket:       "ticket-abc",
		ExpiresAt:           formatRFC3339(now.Add(4 * time.Minute)),
		ServiceState:        "active",
		Transport:           "tcp+udp",
		RateLimitMbps:       100,
		QuotaRemainingBytes: 1 << 30,
		TargetDigest:        targetDigest("example.com:443"),
	}
}

func TestValidatePolicyAcceptsAWellFormedGrant(t *testing.T) {
	now := time.Now()
	forward := ForwardConfig{ForwardID: 7, Target: "example.com:443"}
	if failure := validatePolicy(validPolicy(forward, now), forward, now); failure != "" {
		t.Fatalf("valid grant rejected: %s", failure)
	}
}

// Each case here corresponds to a real way the Panel withdraws or narrows a
// grant. Serving traffic after any of them would be a billing and security
// defect, so every one must be rejected.
func TestValidatePolicyRejectsNarrowedGrants(t *testing.T) {
	now := time.Now()
	forward := ForwardConfig{ForwardID: 7, Target: "example.com:443"}
	cases := []struct {
		name   string
		mutate func(*UltraPolicy)
		want   string
	}{
		{"wrong protocol", func(p *UltraPolicy) { p.Protocol = "leikwan-ultra-policy-v1" }, "protocol"},
		{"other forward", func(p *UltraPolicy) { p.ForwardID = 8 }, "forward_id"},
		{"suspended", func(p *UltraPolicy) { p.ServiceState = "suspended" }, "service_state"},
		{"missing ticket", func(p *UltraPolicy) { p.SessionTicket = "" }, "session_ticket"},
		{"short generation", func(p *UltraPolicy) { p.PolicyGeneration = "abcd" }, "policy_generation"},
		{"uppercase digest", func(p *UltraPolicy) {
			p.TargetDigest = "2D92752E69614799EA8467C10D252C76F79C85845CF23D54406EF49AB463FF0C"
		}, "target_digest_format"},
		{"digest for another target", func(p *UltraPolicy) { p.TargetDigest = targetDigest("other.example:443") }, "target_digest_mismatch"},
		{"expired", func(p *UltraPolicy) { p.ExpiresAt = formatRFC3339(now.Add(-time.Second)) }, "expired"},
		{"implausible TTL", func(p *UltraPolicy) { p.ExpiresAt = formatRFC3339(now.Add(time.Hour)) }, "expiry_window"},
		{"quota exhausted", func(p *UltraPolicy) { p.QuotaRemainingBytes = 0 }, "quota"},
		{"rate limit out of range", func(p *UltraPolicy) { p.RateLimitMbps = 100001 }, "rate_limit"},
		{"bad transport", func(p *UltraPolicy) { p.Transport = "quic" }, "transport"},
		{"unparsable expiry", func(p *UltraPolicy) { p.ExpiresAt = "not-a-time" }, "expires_at"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			policy := validPolicy(forward, now)
			tc.mutate(policy)
			if got := validatePolicy(policy, forward, now); got != tc.want {
				t.Fatalf("validatePolicy = %q, want %q", got, tc.want)
			}
		})
	}
}

func TestAcceptForwardSelectsOnlyUltraForwards(t *testing.T) {
	base := ForwardConfig{ForwardID: 1, BackendMode: "ultra", ServiceStatus: "active", ClientMode: "single"}
	if !acceptForward(base) {
		t.Fatal("plain Ultra forward rejected")
	}
	race := base
	race.ClientMode = "race"
	if !acceptForward(race) {
		t.Fatal("race-mode Ultra forward must be accepted: candidates provide the HA")
	}

	rejected := map[string]ForwardConfig{
		"legacy backend":    {ForwardID: 1, BackendMode: "current_mode", ServiceStatus: "active"},
		"cf backend":        {ForwardID: 1, BackendMode: "cf_mode", ServiceStatus: "active"},
		"suspended service": {ForwardID: 1, BackendMode: "ultra", ServiceStatus: "suspended"},
		"sidecar required":  {ForwardID: 1, BackendMode: "ultra", ServiceStatus: "active", SidecarRequired: true},
		"aggregation":       {ForwardID: 1, BackendMode: "ultra", ServiceStatus: "active", Aggregation: true},
		"bond client mode":  {ForwardID: 1, BackendMode: "ultra", ServiceStatus: "active", ClientMode: "bond"},
		"mptcp client mode": {ForwardID: 1, BackendMode: "ultra", ServiceStatus: "active", ClientMode: "mptcp"},
		"no forward id":     {ForwardID: 0, BackendMode: "ultra", ServiceStatus: "active"},
	}
	for name, forward := range rejected {
		if acceptForward(forward) {
			t.Fatalf("%s must be rejected locally", name)
		}
	}
}

// A policy refresh must not restart the runtime: the whole point of the Ultra
// control socket is that new candidates are installed without dropping
// established sessions.
func TestContractSignatureIgnoresPolicyRotation(t *testing.T) {
	forward := ForwardConfig{ForwardID: 3, ListenPort: 10001, Target: "example.com:443", Transport: "tcp+udp", BackendMode: "ultra"}
	first := contractSignature(forward)

	rotated := forward
	rotated.UltraAccess = &UltraPolicy{PolicyGeneration: "aaaa", TargetDigest: "bbbb", SessionTicket: "cccc"}
	if contractSignature(rotated) != first {
		t.Fatal("policy rotation must not change the runtime contract")
	}

	moved := forward
	moved.ListenPort = 10002
	if contractSignature(moved) == first {
		t.Fatal("a listen-port change must force a restart")
	}

	retargeted := forward
	retargeted.Target = "other.example:443"
	if contractSignature(retargeted) == first {
		t.Fatal("a target change must force a restart")
	}
}
