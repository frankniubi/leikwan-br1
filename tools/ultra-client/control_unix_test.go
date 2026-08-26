//go:build !windows

package main

import (
	"bytes"
	"net"
	"os"
	"path/filepath"
	"testing"
)

func TestControlServerRejectsPeerBeforeReadingLease(t *testing.T) {
	config := testPolicyConfig()
	parent := filepath.Join(t.TempDir(), "private")
	path := filepath.Join(parent, "forward-42.control.sock")
	store := NewPolicyStore(config)
	server := NewControlServer(path, config, store)
	server.peerAllowed = func(*net.UnixConn) bool { return false }
	if err := server.Listen(); err != nil {
		t.Fatal(err)
	}
	go func() { _ = server.Serve() }()

	connection, err := net.DialUnix("unix", nil, &net.UnixAddr{Name: path, Net: "unix"})
	if err != nil {
		t.Fatal(err)
	}
	response, err := readFrame(connection)
	_ = connection.Close()
	if err != nil || !bytes.Contains(response, []byte(`"error":"peer_rejected"`)) {
		t.Fatalf("response=%s err=%v", response, err)
	}
	if _, ok := store.Snapshot(); ok {
		t.Fatal("rejected peer updated the policy store")
	}
	info, err := os.Stat(parent)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o700 {
		t.Fatalf("parent mode=%#o", info.Mode().Perm())
	}
	server.Close()
	if _, err := os.Stat(path); !os.IsNotExist(err) {
		t.Fatalf("control socket remained after close: %v", err)
	}
}
