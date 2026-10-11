package main

import (
	"encoding/json"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestLeaseAndStatusCarryOpaqueCandidateIdentity(t *testing.T) {
	now := time.Now().UTC()
	generation := strings.Repeat("a", 64)
	candidateHash := strings.Repeat("b", 64)
	target := "203.0.113.50:443"
	frame := map[string]any{
		"protocol":              policyProtocol,
		"purpose":               "ultra",
		"forward_id":            42,
		"session_ticket":        "ticket",
		"policy_generation":     generation,
		"lease_id":              "ul2_test",
		"expires_at":            now.Add(2 * time.Minute).Format(time.RFC3339),
		"transport":             "tcp",
		"rate_limit_mbps":       20,
		"quota_remaining_bytes": 1024,
		"target_digest":         targetDigest(target),
		"selection_ref":         "selection-reference-abcdefghijklmnop",
		"selection_tier":        selectionTierPaid,
		"nonce_hash":            strings.Repeat("c", 64),
		"candidate_key_hash":    candidateHash,
		"endpoint":              map[string]any{"ip": "203.0.113.20", "port": 5438},
		"credential":            map[string]any{"user": "user", "password": "password"},
	}
	raw, err := json.Marshal(frame)
	if err != nil {
		t.Fatal(err)
	}
	_, lease, err := decodeLeaseFrame(raw, Config{ForwardID: 42, Target: target})
	if err != nil {
		t.Fatalf("decode lease: %v", err)
	}
	if lease.CandidateKeyHash != candidateHash {
		t.Fatalf("candidate hash=%q, want %q", lease.CandidateKeyHash, candidateHash)
	}
	if lease.LeaseHash == "" || len(lease.LeaseHash) != 64 || !isLowerHex(lease.LeaseHash) {
		t.Fatalf("lease hash=%q, want opaque sha256 lease identity", lease.LeaseHash)
	}

	writer := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Second)
	writer.UpdateTCPWithTierAndCandidate(lease.SelectionRef, lease.SelectionTier, lease.CandidateKeyHash, 12*time.Millisecond)
	if writer.status.LatestTCP == nil || writer.status.LatestTCP.CandidateKeyHash != candidateHash {
		t.Fatalf("tcp status=%+v, want candidate hash", writer.status.LatestTCP)
	}
}
