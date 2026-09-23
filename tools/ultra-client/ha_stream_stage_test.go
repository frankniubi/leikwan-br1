package main

import (
	"errors"
	"io"
	"net"
	"runtime"
	"syscall"
	"testing"
)

func TestHAErrorClassSeparatesWindowsAbortFromReset(t *testing.T) {
	wrap := func(err error) error { return &net.OpError{Op: "write", Net: "tcp", Err: err} }
	if got := haErrorClass(wrap(syscall.ECONNRESET)); got != "reset" {
		t.Fatalf("ECONNRESET class=%s", got)
	}
	if runtime.GOOS == "windows" {
		if got := haErrorClass(wrap(syscall.Errno(10054))); got != "reset" {
			t.Fatalf("WSAECONNRESET class=%s", got)
		}
		if got := haErrorClass(wrap(syscall.Errno(10053))); got != "aborted" {
			t.Fatalf("WSAECONNABORTED class=%s", got)
		}
	} else if got := haErrorClass(wrap(syscall.ECONNABORTED)); got != "aborted" {
		t.Fatalf("ECONNABORTED class=%s", got)
	}
}

type stageConn struct {
	net.Conn
	readErr  error
	writeErr error
	payload  []byte
}

func (c *stageConn) Read(p []byte) (int, error) {
	if len(c.payload) > 0 {
		n := copy(p, c.payload)
		c.payload = c.payload[n:]
		return n, nil
	}
	return 0, c.readErr
}

func (c *stageConn) Write(p []byte) (int, error) {
	if c.writeErr != nil {
		return 0, c.writeErr
	}
	return len(p), nil
}

func TestCopyOutboundStagedNamesTheFailingSide(t *testing.T) {
	limiter, status := observedInboundTestState(t, 100)
	if _, err, stage := copyOutboundStaged(&stageConn{}, &stageConn{readErr: io.EOF}, limiter, status); !errors.Is(err, io.EOF) || stage != "local_read" {
		t.Fatalf("local eof: %v %s", err, stage)
	}
	aborted := errors.New("relay write failed")
	if _, err, stage := copyOutboundStaged(&stageConn{writeErr: aborted}, &stageConn{payload: []byte("x"), readErr: io.EOF}, limiter, status); !errors.Is(err, aborted) || stage != "remote_write" {
		t.Fatalf("relay write: %v %s", err, stage)
	}
}

func TestInboundObservationStage(t *testing.T) {
	cases := map[string]inboundCopyObservation{
		"remote_read": {ReadError: io.EOF},
		"local_write": {ReadError: io.EOF, WriteError: errors.New("local")},
		"policy":      {WriteError: errors.New("local"), PolicyError: ErrQuotaExhausted},
	}
	for want, observation := range cases {
		if got := observation.Stage(); got != want {
			t.Fatalf("stage=%s want=%s", got, want)
		}
	}
}
