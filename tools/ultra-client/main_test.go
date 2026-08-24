package main

import (
	"bytes"
	"encoding/hex"
	"testing"
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

func TestAccessValidationRejectsExpiredAndUnboundedEntries(t *testing.T) {
	access := Access{Token: "token", ExpiresAt: "2000-01-01T00:00:00Z", SealedMaterial: "um1.invalid"}
	if err := access.validate(); err == nil {
		t.Fatal("expired access must be rejected")
	}
	access = Access{Token: "token", ExpiresAt: "2099-01-01T00:00:00Z", SealedMaterial: "um1.invalid"}
	if err := access.validate(); err == nil {
		t.Fatal("invalid sealed material must be rejected")
	}
}
