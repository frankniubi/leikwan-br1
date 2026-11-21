//go:build !windows

package main

import (
	"context"
	"net"
	"testing"
	"time"
)

func TestControlCloseCancelsSelectionContext(t *testing.T) {
	config := testPolicyConfig()
	_, path := shortControlSocketPath(t)
	server := NewControlServer(path, config, NewPolicyStore(config))
	if err := server.Listen(); err != nil {
		t.Fatal(err)
	}
	go func() { _ = server.Serve() }()
	probe, cancel := context.WithTimeout(server.ctx, time.Minute)
	defer cancel()
	server.Close()
	select {
	case <-probe.Done():
		if probe.Err() != context.Canceled {
			t.Fatalf("probe: %v", probe.Err())
		}
	case <-time.After(time.Second):
		t.Fatal("selection context outlived control shutdown")
	}
	server.Close() // idempotent
}

func TestControlCloseUnblocksAdmittedPartialFrame(t *testing.T) {
	config := testPolicyConfig()
	_, path := shortControlSocketPath(t)
	server := NewControlServer(path, config, NewPolicyStore(config))
	if err := server.Listen(); err != nil {
		t.Fatal(err)
	}
	admitted := make(chan struct{})
	server.peerAllowed = func(*net.UnixConn) bool { close(admitted); return true }
	go func() { _ = server.Serve() }()
	defer server.Close()
	connection, err := net.DialUnix("unix", nil, &net.UnixAddr{Name: path, Net: "unix"})
	if err != nil {
		t.Fatal(err)
	}
	defer connection.Close()
	if _, err := connection.Write([]byte{0, 0}); err != nil {
		t.Fatal(err)
	}
	select {
	case <-admitted:
	case <-time.After(time.Second):
		t.Fatal("handler not admitted")
	}
	server.Close()
	_ = connection.SetReadDeadline(time.Now().Add(time.Second))
	var one [1]byte
	_, err = connection.Read(one[:])
	if err == nil {
		t.Fatal("partial frame survived shutdown")
	}
	if timeout, ok := err.(net.Error); ok && timeout.Timeout() {
		t.Fatal("handler did not close on cancellation")
	}
}
