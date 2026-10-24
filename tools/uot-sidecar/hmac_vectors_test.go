package main

// Fixed HMAC token vectors (contract C3). testdata/hmac_vectors.json was
// generated from this implementation and cross-checked against the Python
// reference sidecar; the panel's PHP token issuer reuses the same file, so a
// change to normalizeTarget or makeHMACToken that alters any byte fails here.

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

type hmacVector struct {
	Secret    string `json:"secret"`
	ForwardID int64  `json:"forward_id"`
	Target    string `json:"target"`
	Token     string `json:"token"`
}

func TestHMACTokenVectors(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join("testdata", "hmac_vectors.json"))
	if err != nil {
		t.Fatalf("vectors missing: %v", err)
	}
	var vectors []hmacVector
	if err := json.Unmarshal(raw, &vectors); err != nil {
		t.Fatalf("vectors are not valid JSON: %v", err)
	}
	if len(vectors) < 4 {
		t.Fatalf("expected at least 4 vectors, got %d", len(vectors))
	}
	for _, vector := range vectors {
		token, err := makeHMACToken([]byte(vector.Secret), vector.ForwardID, vector.Target)
		if err != nil {
			t.Fatalf("forward=%d target=%q: %v", vector.ForwardID, vector.Target, err)
		}
		if token != vector.Token {
			t.Fatalf("forward=%d target=%q token = %s, want %s", vector.ForwardID, vector.Target, token, vector.Token)
		}
		// The sidecar must accept exactly this token for the raw target string.
		binding := forwardBinding{forwardID: vector.ForwardID, secret: []byte(vector.Secret)}
		if !binding.verify(vector.Token, vector.Target) {
			t.Fatalf("forward=%d target=%q: vector token was not accepted", vector.ForwardID, vector.Target)
		}
	}
}
