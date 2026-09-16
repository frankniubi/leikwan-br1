package main

import (
	"bytes"
	"net"
	"sync"
	"testing"
	"time"
)

// Regression suite for the 2026-09-16 reader race: probeEcho used to call
// SetReadDeadline/Read directly on a socket the runtime receive loop was
// already reading. The 300ms probe deadline woke the reader's pending Read and
// collapsed healthy associations into errUDPReceiveTimeout
// ("keepalive_timeout" in the canary). The fix gives the socket exactly one
// reader; probes register a waiter and the reader dispatches the matching
// reserved-address echo frame.

// startSilentRelaySession arms a session against a relay that never answers:
// exactly the canary conditions that used to manufacture the teardown.
func startSilentRelaySession(t *testing.T) *DatagramSession {
	t.Helper()
	server, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	go func() {
		buffer := make([]byte, 65535)
		for {
			if _, _, readErr := server.ReadFromUDP(buffer); readErr != nil {
				return
			}
		}
	}()
	client, err := net.DialUDP("udp4", nil, server.LocalAddr().(*net.UDPAddr))
	if err != nil {
		_ = server.Close()
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = client.Close(); _ = server.Close() })
	return &DatagramSession{remote: client, mode: 0x11, sid: []byte{1, 2, 3}}
}

// echoRelay answers keep-echo envelopes (target 2.0.0.0:0) with the
// reserved-address echo frame, optionally injecting business frames ahead of
// the echo to reproduce the interleavings the receive loop faces inside a
// probe window.
type echoRelay struct {
	mu       sync.Mutex
	probes   int
	inject   [][]byte
	injected bool
}

func startEchoRelaySession(t *testing.T, inject ...[]byte) (*echoRelay, *DatagramSession) {
	t.Helper()
	server, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	relay := &echoRelay{inject: inject}
	go func() {
		buffer := make([]byte, 65535)
		for {
			count, client, readErr := server.ReadFromUDP(buffer)
			if readErr != nil {
				return
			}
			if count <= datagramEnvelopeSize || !bytes.Equal(buffer[10:14], []byte{2, 0, 0, 0}) {
				continue
			}
			payload := append([]byte(nil), buffer[datagramEnvelopeSize:count]...)
			relay.mu.Lock()
			relay.probes++
			var pending [][]byte
			if !relay.injected {
				relay.injected = true
				pending = relay.inject
			}
			relay.mu.Unlock()
			for _, frame := range pending {
				_, _ = server.WriteToUDP(frame, client)
			}
			response := append([]byte{1, 2, 0, 0, 0, 0, 0}, payload...)
			_, _ = server.WriteToUDP(response, client)
		}
	}()
	client, err := net.DialUDP("udp4", nil, server.LocalAddr().(*net.UDPAddr))
	if err != nil {
		_ = server.Close()
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = client.Close(); _ = server.Close() })
	return relay, &DatagramSession{remote: client, mode: 0x11, sid: []byte{1, 2, 3}}
}

// businessDatagramFrame builds a game-looking response frame (9.9.9.9:53) that
// must never be consumed by a probe waiter.
func businessDatagramFrame(payload []byte) []byte {
	return append([]byte{1, 9, 9, 9, 9, 0, 53}, payload...)
}

// TestProbeLadderLeavesRuntimeReaderAlive is the fixed-behavior flip of the
// canary repro: an oversized datagram triggers the payload-ceiling ladder while
// the runtime reader owns the socket. Against a silent relay the ladder must
// finish its budget reporting unusable (0) while the reader stays alive with no
// manufactured deadline.
func TestProbeLadderLeavesRuntimeReaderAlive(t *testing.T) {
	session := startSilentRelaySession(t)
	failures := make(chan error, 4)
	session.onRuntimeFailure = func(err error) {
		select {
		case failures <- err:
		default:
		}
	}
	session.startRuntimeReader(30 * time.Second)
	defer session.Close()

	result := make(chan int, 1)
	go func() { result <- session.verifyPayloadCeiling() }()

	select {
	case err := <-failures:
		t.Fatalf("runtime reader failed during the probe ladder: %v", err)
	case ceiling := <-result:
		if ceiling != 0 {
			t.Fatalf("silent relay measured ceiling %d, want 0 (unusable)", ceiling)
		}
	case <-time.After(payloadCeilingBudget + 10*time.Second):
		t.Fatal("probe ladder did not terminate inside its budget")
	}

	select {
	case <-session.runtimeDone():
		t.Fatal("runtime reader died from a probe-manufactured deadline")
	default:
	}
	if err := session.runtimeError(); err != nil {
		t.Fatalf("runtime error = %v, want none", err)
	}
}

// TestProbeEchoWaitsForRuntimeReaderDispatch proves waiter mode: the reader
// consumes the echo for the probe and nothing leaks into the game channel.
func TestProbeEchoWaitsForRuntimeReaderDispatch(t *testing.T) {
	relay, session := startEchoRelaySession(t)
	session.startRuntimeReader(30 * time.Second)
	defer session.Close()

	if !session.probeEcho([]byte("probe-payload"), 2*time.Second) {
		t.Fatal("probe echo was not dispatched by the runtime reader")
	}
	relay.mu.Lock()
	probes := relay.probes
	relay.mu.Unlock()
	if probes == 0 {
		t.Fatal("relay never saw the probe envelope")
	}
	select {
	case packet := <-session.receiver.packets:
		t.Fatalf("probe echo leaked into the game channel: %q", packet)
	default:
	}
	select {
	case <-session.runtimeDone():
		t.Fatal("runtime reader stopped during the probe")
	default:
	}
}

// TestProbeEchoDoesNotStealBusinessDatagrams interleaves a business frame
// ahead of the echo reply: the probe must match only its echo, and the reader
// must still deliver the business frame downstream.
func TestProbeEchoDoesNotStealBusinessDatagrams(t *testing.T) {
	business := []byte("game-reply-before-echo")
	frame := businessDatagramFrame(business)
	_, session := startEchoRelaySession(t, frame)
	session.startRuntimeReader(30 * time.Second)
	defer session.Close()

	if !session.probeEcho([]byte("probe-payload"), 2*time.Second) {
		t.Fatal("probe echo was not dispatched")
	}
	select {
	case packet := <-session.receiver.packets:
		// The runtime reader queues the complete frame, header included.
		if !bytes.Equal(packet, frame) {
			t.Fatalf("business datagram corrupted: %q", packet)
		}
	default:
		t.Fatal("probe stole the business datagram from the runtime reader")
	}
}

// TestProbeEchoTimeoutKeepsRuntimeReaderHealthy proves a probe timeout stays a
// probe-local miss: the session keeps its reader and its own tolerance.
func TestProbeEchoTimeoutKeepsRuntimeReaderHealthy(t *testing.T) {
	session := startSilentRelaySession(t)
	failures := make(chan error, 4)
	session.onRuntimeFailure = func(err error) {
		select {
		case failures <- err:
		default:
		}
	}
	session.startRuntimeReader(30 * time.Second)
	defer session.Close()

	started := time.Now()
	if session.probeEcho([]byte("probe-payload"), 250*time.Millisecond) {
		t.Fatal("silent relay must not answer the probe")
	}
	if elapsed := time.Since(started); elapsed > 2*time.Second {
		t.Fatalf("probe took %v, want its own timeout", elapsed)
	}
	select {
	case err := <-failures:
		t.Fatalf("probe timeout escalated into a reader failure: %v", err)
	default:
	}
	if err := session.runtimeError(); err != nil {
		t.Fatalf("runtime error = %v, want none", err)
	}
	select {
	case <-session.runtimeDone():
		t.Fatal("runtime reader died from a probe timeout")
	default:
	}
}

// TestProbeEchoReturnsFalseWhenReaderDies keeps the error taxonomy: when the
// reader fails on its own deadline the association dies with
// errUDPReceiveTimeout, and an in-flight probe just returns false.
func TestProbeEchoReturnsFalseWhenReaderDies(t *testing.T) {
	session := startSilentRelaySession(t)
	failures := make(chan error, 4)
	session.onRuntimeFailure = func(err error) {
		select {
		case failures <- err:
		default:
		}
	}
	session.startRuntimeReader(150 * time.Millisecond)
	defer session.Close()

	started := time.Now()
	if session.probeEcho([]byte("probe-payload"), 10*time.Second) {
		t.Fatal("probe must fail once the reader owns a dead socket")
	}
	if elapsed := time.Since(started); elapsed > 5*time.Second {
		t.Fatalf("probe waited %v; its own timer must bound it", elapsed)
	}
	select {
	case err := <-failures:
		if err != errUDPReceiveTimeout {
			t.Fatalf("reader failure = %v, want errUDPReceiveTimeout", err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("reader never reported its own timeout")
	}
}

// TestProbeEchoWarmPathOwnsSocketDirectly covers the pre-reader callers
// (candidate arms, active forward checks, availability probes): no reader
// exists yet, so the probe still reads the socket directly and waiter mode
// stays off.
func TestProbeEchoWarmPathOwnsSocketDirectly(t *testing.T) {
	_, session := startEchoRelaySession(t)
	defer session.Close()

	if !session.probeEcho([]byte("warm-probe"), 2*time.Second) {
		t.Fatal("warm-path probe must read the socket directly before the reader starts")
	}
	if waiter := session.registerProbeWaiter([]byte("mode-check")); waiter != nil {
		session.unregisterProbeWaiter(waiter)
		t.Fatal("waiter mode must stay off before a reader owns the socket")
	}
}

// TestNativeReaderContractDispatchesProbeWaiters mirrors the native SOCKS5
// readUDPResponses loop: markSocketReaderOwned flips probeEcho into waiter mode
// without the ultra runtime reader, and a plain Read+dispatch loop feeds the
// waiter.
func TestNativeReaderContractDispatchesProbeWaiters(t *testing.T) {
	_, session := startEchoRelaySession(t)
	defer session.Close()

	if waiter := session.registerProbeWaiter([]byte("mode-check")); waiter != nil {
		session.unregisterProbeWaiter(waiter)
		t.Fatal("waiter mode must be off before the native reader is marked")
	}
	session.markSocketReaderOwned()
	done := make(chan struct{})
	go func() {
		defer close(done)
		buffer := make([]byte, 65535)
		for {
			count, err := session.remote.Read(buffer)
			if err != nil {
				return
			}
			if session.dispatchProbeEcho(buffer[:count]) {
				continue
			}
		}
	}()
	if !session.probeEcho([]byte("native-probe"), 2*time.Second) {
		t.Fatal("native reader contract did not dispatch the probe echo")
	}
	session.Close()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("native reader loop did not stop after Close")
	}
}
