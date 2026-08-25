package main

import (
	"errors"
	"testing"
	"time"
)

func testPolicyConfig() Config { return Config{ForwardID: 42, Target: "203.0.113.50:443"} }

func testPolicy(now time.Time, generation string) Policy {
	return Policy{
		Protocol: policyProtocol, ForwardID: 42, PolicyGeneration: generation, SessionTicket: "ticket",
		ExpiresAt: now.Add(2 * time.Minute), Transport: "tcp", RateLimitMbps: 20,
		QuotaRemainingBytes: 1024, TargetDigest: targetDigest("203.0.113.50:443"),
	}
}

func testLease(policy Policy, nonce string) ProviderLease {
	return ProviderLease{
		LeaseID: "ul2_test_" + nonce, PolicyGeneration: policy.PolicyGeneration,
		ExpiresAt: policy.ExpiresAt, Transport: policy.Transport, SelectionRef: "selection-reference-abcdefghijklmnop",
		NonceHash: nonce, IP: "203.0.113.20", Port: 5438, User: []byte("user"), Password: []byte("password"),
	}
}

func TestPolicyValidationAndRollback(t *testing.T) {
	now := time.Now()
	config := testPolicyConfig()
	policy := testPolicy(now, "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	if err := policy.Validate(config, now); err != nil {
		t.Fatal(err)
	}
	invalid := policy
	invalid.Protocol = "wrong"
	if !errors.Is(invalid.Validate(config, now), ErrPolicyInvalid) {
		t.Fatal("wrong protocol accepted")
	}
	invalid = policy
	invalid.TargetDigest = targetDigest("198.51.100.10:443")
	if !errors.Is(invalid.Validate(config, now), ErrPolicyInvalid) {
		t.Fatal("target digest mismatch accepted")
	}
	store := NewPolicyStore(config)
	if err := store.Replace(policy, testLease(policy, "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")); err != nil {
		t.Fatal(err)
	}
	next := testPolicy(now, "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc")
	if err := store.Replace(next, testLease(next, "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd")); err != nil {
		t.Fatal(err)
	}
	if err := store.Replace(policy, testLease(policy, "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee")); !errors.Is(err, ErrPolicyRollback) {
		t.Fatalf("generation rollback err=%v", err)
	}
}

func TestPolicyLimiterEnforcesQuotaAndRevocation(t *testing.T) {
	limiter := NewPolicyLimiter()
	generation := "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
	if err := limiter.Replace(0, 20, time.Now().Add(time.Minute), generation); err != nil {
		t.Fatal(err)
	}
	if err := limiter.Consume(20); err != nil {
		t.Fatal(err)
	}
	if !errors.Is(limiter.Consume(1), ErrQuotaExhausted) {
		t.Fatal("quota overrun accepted")
	}
	limiter.Revoke(generation)
	if !errors.Is(limiter.Consume(1), ErrPolicyStopped) {
		t.Fatal("revoked policy remained usable")
	}
}
