package main

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"io"
	"net"
	"path/filepath"
	"sync/atomic"
	"testing"
	"time"
)

// legacyTargetRejected is the LeiGod command=1 final frame with status 0: the
// relay could not open the target, so it delivered nothing.
var legacyTargetRejected = []byte{4, 1, 0, 0, 1, 0, 0, 0, 0, 0}

type replayHarness struct {
	store   *PolicyStore
	first   ProviderLease
	second  ProviderLease
	status  *StatusWriter
	limiter *PolicyLimiter
	config  Config
	dials   atomic.Int32
}

func newReplayHarness(t *testing.T) *replayHarness {
	t.Helper()
	store, first, second := twoPathCandidateStore(t)
	t.Cleanup(func() { store.Revoke("") })
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	t.Cleanup(status.Close)
	limiter := NewPolicyLimiter()
	if err := limiter.Replace(0, 1<<20, time.Now().Add(time.Minute), "test"); err != nil {
		t.Fatal(err)
	}
	return &replayHarness{store: store, first: first, second: second, status: status, limiter: limiter,
		config: Config{ForwardID: 42, Target: "example.test:443", ConnectTimeoutSecs: 2}}
}

// relayConn wraps one end of a loopback pair the way a LeiGod dial returns it.
func relayConn(connection net.Conn) net.Conn {
	return &bufferedConn{Conn: connection, reader: bufio.NewReaderSize(connection, 64*1024)}
}

// dialer answers the replay dial for the second candidate only.
func (h *replayHarness) dialer(remote net.Conn) candidateStreamDial {
	return func(lease ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
		h.dials.Add(1)
		if lease.IP != h.second.IP {
			return nil, errors.New("replay dialled the refused candidate")
		}
		return remote, nil
	}
}

func (h *replayHarness) run(local, remote net.Conn, second net.Conn) chan struct{} {
	ident := tcpRelayIdentity(h.config, RuntimeSnapshot{Policy: Policy{PolicyGeneration: h.first.PolicyGeneration}, Lease: h.first})
	ident.replay = tcpReplayDialerFor(h.config, h.store, h.status, "example.test", 443, h.dialer(second))
	done := make(chan struct{})
	go func() {
		defer close(done)
		relayTCP(context.Background(), local, remote, h.limiter, h.status, h.store, ident)
	}()
	return done
}

func readXor(t *testing.T, connection net.Conn, size int) []byte {
	t.Helper()
	buffer := make([]byte, size)
	if _, err := io.ReadFull(connection, buffer); err != nil {
		t.Fatalf("provider read: %v", err)
	}
	return xorOne(buffer)
}

func waitRelay(t *testing.T, done chan struct{}) {
	t.Helper()
	select {
	case <-done:
	case <-time.After(3 * time.Second):
		t.Fatal("relay did not finish")
	}
}

// The refused relay never opened the target, so the first flight moves once to
// the next healthy candidate, byte for byte, and the stream completes.
func TestTCPReplaysFirstFlightAfterTargetConnectRejected(t *testing.T) {
	h := newReplayHarness(t)
	client, local := tcpPair(t)
	remoteA, providerA := tcpPair(t)
	remoteB, providerB := tcpPair(t)
	done := h.run(local, relayConn(remoteA), relayConn(remoteB))

	request := []byte("GET / HTTP/1.1\r\nHost: example.test\r\n\r\n")
	if _, err := client.Write(request); err != nil {
		t.Fatal(err)
	}
	if got := readXor(t, providerA, len(request)); !bytes.Equal(got, request) {
		t.Fatalf("refused relay saw %q", got)
	}
	if _, err := providerA.Write(legacyTargetRejected); err != nil {
		t.Fatal(err)
	}
	if got := readXor(t, providerB, len(request)); !bytes.Equal(got, request) {
		t.Fatalf("replayed flight = %q", got)
	}
	// A follow-up write still reaches the replacement stream.
	if _, err := client.Write([]byte("more")); err != nil {
		t.Fatal(err)
	}
	if got := readXor(t, providerB, 4); string(got) != "more" {
		t.Fatalf("follow-up = %q", got)
	}
	if _, err := providerB.Write([]byte("HTTP/1.1 200 OK\r\n\r\n")); err != nil {
		t.Fatal(err)
	}
	providerB.CloseWrite()
	response, err := io.ReadAll(io.LimitReader(client, 64))
	if err != nil || string(response) != "HTTP/1.1 200 OK\r\n\r\n" {
		t.Fatalf("response = %q, %v", response, err)
	}
	client.CloseWrite()
	waitRelay(t, done)
	if h.dials.Load() != 1 {
		t.Fatalf("replay dials = %d", h.dials.Load())
	}
	// The refused relay received nothing after its rejection.
	_ = providerA.SetReadDeadline(time.Now().Add(200 * time.Millisecond))
	if extra, _ := io.ReadAll(providerA); len(extra) != 0 {
		t.Fatalf("refused relay received %d more bytes", len(extra))
	}
}

// An EOF (or reset) with no downstream bytes cannot prove the target never
// processed the request, so it is never replayed.
func TestTCPDoesNotReplayUnconfirmedSilence(t *testing.T) {
	h := newReplayHarness(t)
	client, local := tcpPair(t)
	remoteA, providerA := tcpPair(t)
	remoteB, _ := tcpPair(t)
	done := h.run(local, relayConn(remoteA), relayConn(remoteB))

	request := []byte("POST /charge HTTP/1.1\r\n\r\n")
	if _, err := client.Write(request); err != nil {
		t.Fatal(err)
	}
	readXor(t, providerA, len(request))
	providerA.Close()
	if n, _ := client.Read(make([]byte, 16)); n != 0 {
		t.Fatalf("client got %d bytes", n)
	}
	// The relay half-closes toward the client and waits for it, as before.
	client.Close()
	waitRelay(t, done)
	if h.dials.Load() != 0 {
		t.Fatalf("silent stream was replayed (%d dials)", h.dials.Load())
	}
}

// A first flight larger than the replay bound, or one the application already
// half-closed, is not replayed either.
func TestTCPReplayBoundsAndHalfClose(t *testing.T) {
	t.Run("oversized", func(t *testing.T) {
		h := newReplayHarness(t)
		client, local := tcpPair(t)
		remoteA, providerA := tcpPair(t)
		remoteB, _ := tcpPair(t)
		done := h.run(local, relayConn(remoteA), relayConn(remoteB))
		request := bytes.Repeat([]byte("x"), tcpReplayMaxBytes+1024)
		go client.Write(request)
		readXor(t, providerA, len(request))
		providerA.Write(legacyTargetRejected)
		if n, _ := client.Read(make([]byte, 16)); n != 0 {
			t.Fatalf("client got %d bytes", n)
		}
		waitRelay(t, done)
		if h.dials.Load() != 0 {
			t.Fatalf("oversized flight replayed (%d dials)", h.dials.Load())
		}
	})
	t.Run("half_closed", func(t *testing.T) {
		h := newReplayHarness(t)
		client, local := tcpPair(t)
		remoteA, providerA := tcpPair(t)
		remoteB, _ := tcpPair(t)
		done := h.run(local, relayConn(remoteA), relayConn(remoteB))
		request := []byte("request")
		client.Write(request)
		client.CloseWrite()
		readXor(t, providerA, len(request))
		if _, err := providerA.Read(make([]byte, 1)); err != io.EOF {
			t.Fatalf("half-close not forwarded: %v", err)
		}
		providerA.Write(legacyTargetRejected)
		if n, _ := client.Read(make([]byte, 16)); n != 0 {
			t.Fatalf("client got %d bytes", n)
		}
		waitRelay(t, done)
		if h.dials.Load() != 0 {
			t.Fatalf("half-closed flight replayed (%d dials)", h.dials.Load())
		}
	})
}

// Replay happens once: a second refusal ends the local connection.
func TestTCPReplayHappensAtMostOnce(t *testing.T) {
	h := newReplayHarness(t)
	client, local := tcpPair(t)
	remoteA, providerA := tcpPair(t)
	remoteB, providerB := tcpPair(t)
	done := h.run(local, relayConn(remoteA), relayConn(remoteB))
	request := []byte("hello")
	client.Write(request)
	readXor(t, providerA, len(request))
	providerA.Write(legacyTargetRejected)
	readXor(t, providerB, len(request))
	providerB.Write(legacyTargetRejected)
	if n, _ := client.Read(make([]byte, 16)); n != 0 {
		t.Fatalf("client got %d bytes", n)
	}
	waitRelay(t, done)
	if h.dials.Load() != 1 {
		t.Fatalf("replay dials = %d", h.dials.Load())
	}
}

// First-flight compatibility withholds the remainder of the first write on a
// stream and must never write it to that stream later. On a refusal during
// the withheld delay, the refused stream gets nothing more; the new candidate
// gets the whole flight, reshaped by its own first-flight rule.
func TestTCPReplayKeepsFirstFlightNoReplayGuarantee(t *testing.T) {
	h := newReplayHarness(t)
	h.config.TCPFirstFlight = TCPFirstFlightConfig{
		Target: h.config.Target, CandidateHashes: []string{h.first.CandidateKeyHash, h.second.CandidateKeyHash},
		PrefixBytes: 4, DelayMillis: 300,
	}
	client, local := tcpPair(t)
	remoteA, providerA := tcpPair(t)
	remoteB, providerB := tcpPair(t)
	done := h.run(local, relayConn(remoteA), relayConn(remoteB))

	request := []byte("ClientHello-bytes")
	client.Write(request)
	if got := readXor(t, providerA, 4); string(got) != "Clie" {
		t.Fatalf("refused relay prefix = %q", got)
	}
	providerA.Write(legacyTargetRejected)

	started := time.Now()
	if got := readXor(t, providerB, 4); string(got) != "Clie" {
		t.Fatalf("replacement prefix = %q", got)
	}
	if got := readXor(t, providerB, len(request)-4); string(got) != string(request[4:]) {
		t.Fatalf("replacement remainder = %q", got)
	}
	if time.Since(started) < 250*time.Millisecond {
		t.Fatal("first-flight delay not applied to the replayed flight")
	}
	_ = providerA.SetReadDeadline(time.Now().Add(400 * time.Millisecond))
	if extra, _ := io.ReadAll(providerA); len(extra) != 0 {
		t.Fatalf("withheld remainder reached the refused stream (%d bytes)", len(extra))
	}
	providerB.Write([]byte("ServerHello"))
	providerB.CloseWrite()
	if response, _ := io.ReadAll(io.LimitReader(client, 32)); string(response) != "ServerHello" {
		t.Fatalf("response = %q", response)
	}
	client.CloseWrite()
	waitRelay(t, done)
}

func TestTCPReplayEligibility(t *testing.T) {
	if !tcpReplayEligible(errStreamCommandRejected, 0, 0) {
		t.Fatal("explicit target refusal not eligible")
	}
	for _, tc := range []struct {
		err          error
		read, writen int64
	}{
		{io.EOF, 0, 0},
		{errors.New("reset"), 0, 0},
		{nil, 0, 0},
		{errStreamCommandRejected, 1, 0},
		{errStreamCommandRejected, 0, 1},
	} {
		if tcpReplayEligible(tc.err, tc.read, tc.writen) {
			t.Fatalf("ineligible outcome accepted: %+v", tc)
		}
	}
}
