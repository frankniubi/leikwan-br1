package main

import (
	"bytes"
	"encoding/binary"
	"errors"
	"io"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestControlFrameBoundsAndPartialReads(t *testing.T) {
	var buffer bytes.Buffer
	if err := writeFrame(&buffer, []byte("ok")); err != nil {
		t.Fatal(err)
	}
	got, err := readFrame(bytes.NewReader(buffer.Bytes()))
	if err != nil || string(got) != "ok" {
		t.Fatalf("frame=%q err=%v", got, err)
	}
	var oversized [4]byte
	binary.BigEndian.PutUint32(oversized[:], maxControlPayload+1)
	if _, err := readFrame(bytes.NewReader(oversized[:])); !errors.Is(err, ErrFrameTooLarge) {
		t.Fatalf("oversized read err=%v", err)
	}
	var partial bytes.Buffer
	var partialHeader [4]byte
	binary.BigEndian.PutUint32(partialHeader[:], 8)
	partial.Write(partialHeader[:])
	partial.WriteString("no")
	if _, err := readFrame(&partial); !errors.Is(err, io.ErrUnexpectedEOF) {
		t.Fatalf("partial read err=%v", err)
	}
	if err := writeFrame(&buffer, make([]byte, maxControlPayload+1)); !errors.Is(err, ErrFrameTooLarge) {
		t.Fatalf("oversized write err=%v", err)
	}
}

func shortControlSocketPath(t *testing.T) (string, string) {
	t.Helper()
	parent, err := os.MkdirTemp("", "lku-")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.RemoveAll(parent) })
	return parent, filepath.Join(parent, "c.sock")
}

func TestControlServerAcceptsLeaseAndRejectsReplay(t *testing.T) {
	if os.Getenv("CI") == "windows" {
		t.Skip("Unix control socket is exercised on Linux")
	}
	config := testPolicyConfig()
	_, path := shortControlSocketPath(t)
	store := NewPolicyStore(config)
	server := NewControlServer(path, config, store)
	if err := server.Listen(); err != nil {
		t.Skipf("unix socket unavailable: %v", err)
	}
	go func() { _ = server.Serve() }()
	defer server.Close()
	policy := testPolicy(time.Now(), "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	lease := testLease(policy, "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")
	expiresAt := policy.ExpiresAt.UTC().Format(time.RFC3339)
	payload := []byte(`{"protocol":"leikwan-ultra-policy-v2","forward_id":42,"session_ticket":"ticket","policy_generation":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","lease_id":"ul2_test","expires_at":"` + expiresAt + `","transport":"tcp","rate_limit_mbps":20,"quota_remaining_bytes":1024,"target_digest":"` + targetDigest(config.Target) + `","selection_ref":"selection-reference-abcdefghijklmnop","nonce_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","endpoint":{"ip":"203.0.113.20","port":5438},"credential":{"user":"user","password":"password"}}`)
	connection, err := net.DialUnix("unix", nil, &net.UnixAddr{Name: path, Net: "unix"})
	if err != nil {
		t.Skipf("unix connection unavailable: %v", err)
	}
	if err := writeFrame(connection, payload); err != nil {
		t.Fatal(err)
	}
	response, err := readFrame(connection)
	_ = connection.Close()
	if err != nil || !bytes.Contains(response, []byte(`"ok":true`)) {
		t.Fatalf("response=%s err=%v", response, err)
	}
	_ = lease
	second, err := net.DialUnix("unix", nil, &net.UnixAddr{Name: path, Net: "unix"})
	if err != nil {
		t.Fatal(err)
	}
	if err := writeFrame(second, payload); err != nil {
		t.Fatal(err)
	}
	replay, err := readFrame(second)
	_ = second.Close()
	if err != nil || !bytes.Contains(replay, []byte("lease_replayed")) {
		t.Fatalf("replay=%s err=%v", replay, err)
	}
}
