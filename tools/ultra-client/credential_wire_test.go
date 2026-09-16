package main

import (
	"bytes"
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func TestLeaseCredentialEscapesReachHandshakeAsRawBytes(t *testing.T) {
	config := Config{ForwardID: 42, Target: "203.0.113.50:443"}
	generation := strings.Repeat("a", 64)
	user := `user"\segment`
	password := `pass"\segment`
	frame := map[string]any{
		"protocol":              policyProtocol,
		"forward_id":            42,
		"session_ticket":        "ticket",
		"policy_generation":     generation,
		"lease_id":              "ul2_credential_escape",
		"expires_at":            time.Now().Add(2 * time.Minute).UTC().Format(time.RFC3339),
		"transport":             "tcp+udp",
		"rate_limit_mbps":       20,
		"quota_remaining_bytes": 1024,
		"target_digest":         targetDigest(config.Target),
		"selection_ref":         "selection-reference-abcdefghijklmnop",
		"selection_tier":        selectionTierPaid,
		"nonce_hash":            strings.Repeat("b", 64),
		"endpoint":              map[string]any{"ip": "203.0.113.20", "port": 5438},
		"credential":            map[string]any{"user": user, "password": password},
	}
	raw, err := json.Marshal(frame)
	if err != nil {
		t.Fatal(err)
	}
	_, lease, err := decodeLeaseFrame(raw, config)
	if err != nil {
		t.Fatalf("decode lease: %v", err)
	}
	defer lease.wipe()

	if !bytes.Equal(lease.User, []byte(user)) {
		t.Fatalf("decoded user bytes changed: %q", lease.User)
	}
	if !bytes.Equal(lease.Password, []byte(password)) {
		t.Fatalf("decoded password bytes changed: %q", lease.Password)
	}

	handshake, err := buildHandshakeWithGreeting(string(lease.User), string(lease.Password), 3, lease.IP, lease.Port, currentHandshakeGreeting)
	if err != nil {
		t.Fatalf("build handshake: %v", err)
	}
	offset := len(currentHandshakeGreeting)
	userLength := int(handshake[offset])
	offset++
	gotUser := handshake[offset : offset+userLength]
	offset += userLength
	credentialLength := int(handshake[offset])
	offset++
	gotPassword := handshake[offset : offset+credentialLength]
	if !bytes.Equal(gotUser, []byte(user)) || !bytes.Equal(gotPassword, []byte(password)) {
		t.Fatalf("handshake credential bytes changed: user=%q password=%q", gotUser, gotPassword)
	}
}
