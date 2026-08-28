package main

import (
	"bytes"
	"context"
	"encoding/hex"
	"testing"
	"time"
)

func TestBuildStreamHandshake(t *testing.T) {
	got, err := buildHandshake("line-user", "1234567890123456", 1, "1.1.1.1", 80)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.HasPrefix(got, []byte{5, 3, 2, 0x80, 0x83, 1, 9}) {
		t.Fatalf("unexpected prefix: %s", hex.EncodeToString(got))
	}
	if !bytes.Contains(got, []byte{5, 1}) {
		t.Fatalf("stream command missing: %s", hex.EncodeToString(got))
	}
}

func TestOutboundStreamTransform(t *testing.T) {
	input := []byte("GET / HTTP/1.0\r\n\r\n")
	encoded := xorOne(input)
	if bytes.Equal(input, encoded) {
		t.Fatal("stream bytes were not transformed")
	}
	if !bytes.Equal(input, xorOne(encoded)) {
		t.Fatal("stream transform is not reversible")
	}
}

func TestDatagramEnvelopeRoundTrip(t *testing.T) {
	payload := []byte("payload")
	packet, err := makeDatagramEnvelope(6, []byte{0x10, 0x4b, 0x20}, "1.1.1.1", 53, payload)
	if err != nil {
		t.Fatal(err)
	}
	if len(packet) != 16+len(payload) {
		t.Fatalf("unexpected packet length: %d", len(packet))
	}
	down := append([]byte{0, 0, 0, 1, 1, 1, 1, 1, 0, 53}, payload...)
	decoded, ok := unwrapDatagram(down)
	if !ok || !bytes.Equal(decoded, payload) {
		t.Fatal("datagram response did not unwrap")
	}
}

func TestRuntimePolicyRequiresValidLeaseBeforeDataPlane(t *testing.T) {
	config := Config{ForwardID: 42, Target: "1.1.1.1:443"}
	store := NewPolicyStore(config)
	policy := Policy{
		Protocol: policyProtocol, ForwardID: 42, PolicyGeneration: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		SessionTicket: "ticket", ExpiresAt: time.Now().Add(time.Minute), Transport: "tcp",
		RateLimitMbps: 10, QuotaRemainingBytes: 1024, TargetDigest: targetDigest(config.Target),
	}
	lease := ProviderLease{
		LeaseID: "ul2_test", PolicyGeneration: policy.PolicyGeneration, ExpiresAt: policy.ExpiresAt,
		Transport: "tcp", SelectionRef: "selection-reference-abcdefghijklmnop", NonceHash: "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
		IP: "203.0.113.20", Port: 5438, User: []byte("user"), Password: []byte("password"),
	}
	if _, ok := store.Snapshot(); ok {
		t.Fatal("runtime must not have a data-plane snapshot before lease")
	}
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	snapshot, ok := store.Snapshot()
	if !ok {
		t.Fatal("valid policy and lease were not accepted")
	}
	snapshot.Lease.wipe()
	store.Revoke(policy.PolicyGeneration)
	if _, ok := store.Snapshot(); ok {
		t.Fatal("revoked lease remained usable")
	}
}

func TestRuntimePolicyGenerationRotationKeepsListenersAlive(t *testing.T) {
	config := Config{ForwardID: 42, Target: "1.1.1.1:443"}
	store := NewPolicyStore(config)
	now := time.Now()
	first := Policy{
		Protocol: policyProtocol, ForwardID: 42, PolicyGeneration: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		SessionTicket: "ticket-a", ExpiresAt: now.Add(time.Minute), Transport: "tcp",
		RateLimitMbps: 10, QuotaRemainingBytes: 1024, TargetDigest: targetDigest(config.Target),
	}
	if err := store.Replace(first, ProviderLease{
		LeaseID: "ul2_first", PolicyGeneration: first.PolicyGeneration, ExpiresAt: first.ExpiresAt,
		Transport: "tcp", SelectionRef: "selection-reference-abcdefghijklmnop",
		NonceHash: "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
		IP:        "203.0.113.20", Port: 5438, User: []byte("user"), Password: []byte("password"),
	}); err != nil {
		t.Fatal(err)
	}

	runtimeContext, cancelRuntime := context.WithCancel(context.Background())
	defer cancelRuntime()
	go watchRuntimePolicy(runtimeContext, cancelRuntime, store)
	next := first
	next.PolicyGeneration = "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
	next.SessionTicket = "ticket-b"
	if err := store.Replace(next, ProviderLease{
		LeaseID: "ul2_next", PolicyGeneration: next.PolicyGeneration, ExpiresAt: next.ExpiresAt,
		Transport: "tcp", SelectionRef: "selection-reference-abcdefghijklmnop",
		NonceHash: "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd",
		IP:        "203.0.113.20", Port: 5438, User: []byte("user"), Password: []byte("password"),
	}); err != nil {
		t.Fatal(err)
	}

	select {
	case <-runtimeContext.Done():
		t.Fatal("policy generation rotation canceled active listeners")
	case <-time.After(100 * time.Millisecond):
	}
	store.Revoke(next.PolicyGeneration)
	select {
	case <-runtimeContext.Done():
	case <-time.After(time.Second):
		t.Fatal("policy revocation did not cancel active listeners")
	}
}
