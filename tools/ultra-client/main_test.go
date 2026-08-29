package main

import (
	"bytes"
	"context"
	"encoding/hex"
	"errors"
	"io"
	"net"
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

func TestBuildDatagramHandshakeKeepsNativeGreeting(t *testing.T) {
	got, err := buildHandshake("line-user", "1234567890123456", 3, "1.1.1.1", 53)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.HasPrefix(got, []byte{5, 3, 2, 0x80, 0x83, 1, 9}) {
		t.Fatalf("unexpected datagram prefix: %s", hex.EncodeToString(got))
	}
	if !bytes.Contains(got, []byte{5, 3}) {
		t.Fatalf("datagram command missing: %s", hex.EncodeToString(got))
	}
}

func TestBuildHandshakeRejectsUnknownCommand(t *testing.T) {
	if _, err := buildHandshake("line-user", "1234567890123456", 2, "1.1.1.1", 80); err == nil {
		t.Fatal("unknown handshake command was accepted")
	}
}

func TestReadStreamAcksConsumesUltraPreludeAndPreservesPayload(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	prelude := []byte{0, 0, 0, 0, 0, 0, 0, 0, 1, 0x15, 0x4f, 0xd1, 0xd9, 0xfe}
	payload := []byte("HTTP/1.1 204 No Content\r\n\r\n")
	go func() { _, _ = server.Write(append(prelude, payload...)) }()
	reader, err := readStreamAcks(client)
	if err != nil {
		t.Fatal(err)
	}
	got := make([]byte, len(payload))
	_, err = io.ReadFull(reader, got)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, payload) {
		t.Fatalf("payload after prelude = %q, want %q", got, payload)
	}
}

func TestReadStreamAcksAcceptsLegacySocksAck(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	legacy := []byte{5, 2, 1, 0, 4, 1, 1, 0, 1, 1, 0x12, 0x34, 0, 0}
	payload := []byte("payload")
	go func() { _, _ = server.Write(append(legacy, payload...)) }()
	reader, err := readStreamAcks(client)
	if err != nil {
		t.Fatal(err)
	}
	got := make([]byte, len(payload))
	_, err = io.ReadFull(reader, got)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, payload) {
		t.Fatalf("payload after legacy ack = %q, want %q", got, payload)
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

func TestDialStreamSendsTargetAndLeavesXorStream(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	providerPort := listener.Addr().(*net.TCPAddr).Port
	serverErr := make(chan error, 1)
	go func() {
		conn, acceptErr := listener.Accept()
		if acceptErr != nil {
			serverErr <- acceptErr
			return
		}
		defer conn.Close()
		handshake := make([]byte, 20)
		if _, err := io.ReadFull(conn, handshake); err != nil {
			serverErr <- err
			return
		}
		wantTarget := []byte{5, 1, 0, 1, 1, 1, 1, 1, 1, 0xbb}
		if !bytes.Equal(handshake[len(handshake)-len(wantTarget):], wantTarget) {
			serverErr <- errors.New("private command did not carry the final target")
			return
		}
		ack := []byte{5, 2, 1, 0, 4, 1, 127, 0, 0, 1, 0x12, 0x34, 0, 0}
		if err := writeAll(conn, ack); err != nil {
			serverErr <- err
			return
		}
		payload := make([]byte, 5)
		if _, err := io.ReadFull(conn, payload); err != nil {
			serverErr <- err
			return
		}
		if !bytes.Equal(xorOne(payload), []byte("hello")) {
			serverErr <- errors.New("post-handshake payload was not XOR encoded")
			return
		}
		serverErr <- nil
	}()

	remote, err := dialStream(ProviderLease{
		IP:       "127.0.0.1",
		Port:     providerPort,
		User:     []byte("u"),
		Password: []byte("p"),
	}, "1.1.1.1", 443, time.Second)
	if err != nil {
		t.Fatal(err)
	}
	if err := writeXor(remote, []byte("hello")); err != nil {
		t.Fatal(err)
	}
	remote.Close()
	if err := <-serverErr; err != nil {
		t.Fatal(err)
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
