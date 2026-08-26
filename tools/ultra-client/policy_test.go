package main

import (
	"errors"
	"strings"
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

func TestProviderSecretsAreClearedOnSnapshotReplacementAndRevoke(t *testing.T) {
	now := time.Now()
	store := NewPolicyStore(testPolicyConfig())
	firstPolicy := testPolicy(now, "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	if err := store.Replace(firstPolicy, testLease(firstPolicy, "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")); err != nil {
		t.Fatal(err)
	}

	snapshot, ok := store.Snapshot()
	if !ok {
		t.Fatal("snapshot missing")
	}
	snapshotUser := snapshot.Lease.User
	snapshotPassword := snapshot.Lease.Password
	snapshot.Lease.wipe()
	assertZeroed(t, snapshotUser, "snapshot user")
	assertZeroed(t, snapshotPassword, "snapshot password")
	if snapshot.Lease.IP != "" {
		t.Fatal("snapshot provider IP reference was retained")
	}

	oldLease := store.lease
	oldUser := oldLease.User
	oldPassword := oldLease.Password
	nextPolicy := testPolicy(now, "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc")
	if err := store.Replace(nextPolicy, testLease(nextPolicy, "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd")); err != nil {
		t.Fatal(err)
	}
	assertZeroed(t, oldUser, "replaced user")
	assertZeroed(t, oldPassword, "replaced password")
	if oldLease.IP != "" {
		t.Fatal("replaced provider IP reference was retained")
	}

	currentLease := store.lease
	currentUser := currentLease.User
	currentPassword := currentLease.Password
	store.Revoke(nextPolicy.PolicyGeneration)
	assertZeroed(t, currentUser, "revoked user")
	assertZeroed(t, currentPassword, "revoked password")
	if currentLease.IP != "" {
		t.Fatal("revoked provider IP reference was retained")
	}
}

func assertZeroed(t *testing.T, value []byte, label string) {
	t.Helper()
	for _, item := range value {
		if item != 0 {
			t.Fatalf("%s was not cleared", label)
		}
	}
}

func TestExpiredProviderSecretsAreCleared(t *testing.T) {
	now := time.Now()
	policy := testPolicy(now, "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	policy.ExpiresAt = now.Add(100 * time.Millisecond)
	lease := testLease(policy, "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")
	store := NewPolicyStore(testPolicyConfig())
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	stored := store.lease
	storedUser := stored.User
	storedPassword := stored.Password
	time.Sleep(150 * time.Millisecond)
	store.ClearExpired()
	assertZeroed(t, storedUser, "expired user")
	assertZeroed(t, storedPassword, "expired password")
	if stored.IP != "" || store.HasValid() {
		t.Fatal("expired provider material remained available")
	}
}

func TestLeaseErrorsDoNotEchoProviderCanaries(t *testing.T) {
	endpointCanary := "203.0.113.249"
	credentialCanary := "provider-password-canary"
	raw := []byte(`{"protocol":"wrong","forward_id":42,"session_ticket":"ticket","policy_generation":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","lease_id":"ul2_test","expires_at":"` + time.Now().Add(time.Minute).UTC().Format(time.RFC3339) + `","transport":"tcp","rate_limit_mbps":20,"quota_remaining_bytes":1024,"target_digest":"` + targetDigest(testPolicyConfig().Target) + `","selection_ref":"selection-reference-abcdefghijklmnop","nonce_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","endpoint":{"ip":"` + endpointCanary + `","port":5438},"credential":{"user":"user","password":"` + credentialCanary + `"}}`)
	_, lease, err := decodeLeaseFrame(raw, testPolicyConfig())
	lease.wipe()
	if err == nil {
		t.Fatal("invalid lease was accepted")
	}
	output := err.Error() + controlErrorName(err)
	if strings.Contains(output, endpointCanary) || strings.Contains(output, credentialCanary) {
		t.Fatalf("provider material leaked in error output: %q", output)
	}
}
