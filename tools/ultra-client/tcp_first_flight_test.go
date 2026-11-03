package main

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net"
	"path/filepath"
	"testing"
	"time"
)

type flightRecorder struct {
	net.Conn
	writes [][]byte
	times  []time.Time
	limit  int
	fail   error
}

func (r *flightRecorder) Write(p []byte) (int, error) {
	if r.fail != nil {
		return 0, r.fail
	}
	if r.limit > 0 && len(p) > r.limit {
		p = p[:r.limit]
	}
	r.writes = append(r.writes, append([]byte(nil), p...))
	r.times = append(r.times, time.Now())
	return len(p), nil
}

func TestFirstFlightExactBytesAndSingleDelay(t *testing.T) {
	r := &flightRecorder{}
	c := &firstFlightConn{Conn: r, ctx: context.Background(), prefix: 4, delay: 30 * time.Millisecond}
	payload := []byte{0, 255, 1, 128, 9, 10, 11, 12}
	if n, err := c.Write(payload); n != len(payload) || err != nil {
		t.Fatalf("write=%d %v", n, err)
	}
	if _, err := c.Write([]byte("next")); err != nil {
		t.Fatal(err)
	}
	if len(r.writes) != 3 || len(r.writes[0]) != 4 || !bytes.Equal(bytes.Join(r.writes, nil), append(payload, []byte("next")...)) {
		t.Fatalf("changed payload: %v", r.writes)
	}
	if r.times[1].Sub(r.times[0]) < 30*time.Millisecond {
		t.Fatal("first remainder was not delayed")
	}
	if c.notBefore.After(r.times[2]) {
		t.Fatal("subsequent write renewed delay")
	}
}

func TestFirstFlightShortReadDoesNotWaitForPrefix(t *testing.T) {
	r := &flightRecorder{limit: 1}
	c := &firstFlightConn{Conn: r, ctx: context.Background(), prefix: 4, delay: 30 * time.Millisecond}
	if n, err := c.Write([]byte("a")); n != 1 || err != nil || len(r.writes) != 1 {
		t.Fatalf("short first read: %d %v", n, err)
	}
	if n, err := c.Write([]byte("b")); n != 1 || err != nil {
		t.Fatalf("second write: %d %v", n, err)
	}
	if r.times[1].Sub(r.times[0]) < 30*time.Millisecond {
		t.Fatal("separate next write skipped first-flight delay")
	}
}

func TestFirstFlightCanceledRemainderIsNeverReplayed(t *testing.T) {
	r := &flightRecorder{}
	ctx, cancel := context.WithCancel(context.Background())
	c := &firstFlightConn{Conn: r, ctx: ctx, prefix: 4, delay: time.Second}
	time.AfterFunc(20*time.Millisecond, cancel)
	n, err := c.Write([]byte("12345678"))
	if n != 4 || !errors.Is(err, context.Canceled) || string(bytes.Join(r.writes, nil)) != "1234" {
		t.Fatalf("cancel=%d %v writes=%v", n, err, r.writes)
	}
}

func TestFirstFlightPartialPrefixAndWriteFailure(t *testing.T) {
	r := &flightRecorder{limit: 1}
	c := &firstFlightConn{Conn: r, ctx: context.Background(), prefix: 4, delay: time.Millisecond}
	if n, err := c.Write([]byte("abcd")); n != 4 || err != nil || string(bytes.Join(r.writes, nil)) != "abcd" {
		t.Fatalf("partial prefix=%d %v", n, err)
	}
	r.fail = io.ErrClosedPipe
	if _, err := c.Write([]byte("e")); !errors.Is(err, io.ErrClosedPipe) {
		t.Fatal(err)
	}
}

func TestFirstFlightRequiresExactTargetAndCandidate(t *testing.T) {
	now := time.Now()
	valid := TCPFirstFlightConfig{Target: "example.test:443", PolicyGeneration: "g", CandidateHashes: []string{"candidate"},
		ExpiresAt: now.Add(time.Minute).Format(time.RFC3339), PrefixBytes: 4, DelayMillis: 300}
	if !valid.matches(valid.Target, "candidate") {
		t.Fatal("valid scope rejected")
	}
	cases := []TCPFirstFlightConfig{{}, valid, valid, valid, valid, valid}
	cases[1].Target = "other:443"
	cases[2].CandidateHashes = []string{"other"}
	cases[3].PrefixBytes = 17
	cases[4].DelayMillis = 1001
	cases[5].DelayMillis = 0
	for i, config := range cases {
		if config.matches(valid.Target, "candidate") {
			t.Fatalf("invalid scope %d accepted", i)
		}
	}
	if valid.matches(valid.Target, "") {
		t.Fatal("stream without an authenticated candidate accepted")
	}
}

// Grants and policy generations rotate every few minutes while the runtime
// keeps its bootstrap config; the compatibility must survive that rotation.
func TestFirstFlightSurvivesGrantRenewalAndGenerationRotation(t *testing.T) {
	issued := TCPFirstFlightConfig{Target: "example.test:443", PolicyGeneration: "issued-generation",
		CandidateHashes: []string{"candidate"}, ExpiresAt: time.Now().Add(-time.Hour).Format(time.RFC3339),
		PrefixBytes: 4, DelayMillis: 300}
	if !issued.matches(issued.Target, "candidate") {
		t.Fatal("first-flight switched off after the issuing grant expired")
	}
}

func TestFirstFlightRelayPreservesServerFirstAndHalfClose(t *testing.T) {
	client, local := tcpPair(t)
	remote, provider := tcpPair(t)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer status.Close()
	limiter := NewPolicyLimiter()
	if err := limiter.Replace(20, 1024, time.Now().Add(time.Minute), "test"); err != nil {
		t.Fatal(err)
	}
	config := TCPFirstFlightConfig{Target: "example.test:443", PolicyGeneration: "g",
		CandidateHashes: []string{"candidate"}, ExpiresAt: time.Now().Add(time.Minute).Format(time.RFC3339),
		PrefixBytes: 4, DelayMillis: 30}
	done := make(chan struct{})
	go func() {
		relayTCP(context.Background(), local, remote, limiter, status, nil, relayIdentity{
			forward: 42, generation: "g", candidateKey: "candidate", target: config.Target, firstFlight: config,
		})
		close(done)
	}()
	if _, err := provider.Write([]byte("hello")); err != nil {
		t.Fatal(err)
	}
	greeting := make([]byte, 5)
	if _, err := io.ReadFull(client, greeting); err != nil || string(greeting) != "hello" {
		t.Fatalf("server-first blocked: %q %v", greeting, err)
	}
	client.Write([]byte("request"))
	client.CloseWrite()
	got, err := io.ReadAll(provider)
	if err != nil || string(xorOne(got)) != "request" {
		t.Fatalf("upstream=%x %v", got, err)
	}
	provider.Write([]byte("reply-after-eof"))
	provider.CloseWrite()
	got, err = io.ReadAll(client)
	if err != nil || string(got) != "reply-after-eof" {
		t.Fatalf("half close lost response: %q %v", got, err)
	}
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("relay leaked")
	}
}
