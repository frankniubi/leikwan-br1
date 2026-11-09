package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"
)

func TestHAErrorClassNamesRelayRejections(t *testing.T) {
	for _, tc := range []struct {
		name string
		err  error
		want string
	}{
		{"credential_bare", errStreamAuthenticationRejected, "credential_rejected"},
		{"credential_in_handshake", fmt.Errorf("stream handshake: %w", markHandshakePhase(errStreamAuthenticationRejected)), "credential_rejected"},
		{"credential_after_fallback", fmt.Errorf("stream handshake failed after greeting fallback: initial=%v; fallback=%w", errors.New("x"), errStreamAuthenticationRejected), "credential_rejected"},
		{"credential_in_dial_failure", &candidateDialFailure{First: errStreamAuthenticationRejected, Terminal: errHACandidatesUnavailable}, "credential_rejected"},
		{"prelude", fmt.Errorf("stream handshake: %w", markHandshakePhase(errStreamPreludeRejected)), "prelude_rejected"},
		{"relay_unreachable", fmt.Errorf("%w: %w", errProviderDial, errors.New("no route")), "relay_unreachable"},
		// Established verdicts keep winning over the new sentinels.
		{"refused_dial_stays_refused", fmt.Errorf("%w: %w", errProviderDial, syscall.ECONNREFUSED), "refused"},
		{"dial_timeout_stays_timeout", fmt.Errorf("%w: %w", errProviderDial, &net.OpError{Op: "dial", Err: timeoutError{}}), "timeout"},
		{"command_rejected_unchanged", errStreamCommandRejected, "target_connect_rejected"},
		{"plain_error_unchanged", errors.New("boom"), "io_error"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if got := haErrorClass(tc.err); got != tc.want {
				t.Fatalf("haErrorClass(%v) = %q, want %q", tc.err, got, tc.want)
			}
		})
	}
}

func TestNativeTCPFailureReasonSeparatesRejectedCredential(t *testing.T) {
	if got := nativeTCPFailureReason(fmt.Errorf("stream handshake: %w", markHandshakePhase(errStreamAuthenticationRejected))); got != "credential_rejected" {
		t.Fatalf("rejected credential reported as %q", got)
	}
	for _, err := range []error{errors.New("provider timeout"), context.DeadlineExceeded, errStreamPreludeRejected, nil} {
		if got := nativeTCPFailureReason(err); got != "relay_connect_timeout" {
			t.Fatalf("%v reported as %q, want relay_connect_timeout", err, got)
		}
	}
}

// Through the real handshake: a relay that answers 05 02 05 02 must reach the
// Panel as credential_rejected, in both the strike-relevant candidate feedback
// and the HA event, while the sibling that accepts the credential is used.
func TestNativeStreamDialReportsRejectedCredential(t *testing.T) {
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	policy.Protocol = nativeSocks5Protocol
	policy.Purpose = nativeSocks5Purpose
	policy.Native = true
	policy.Transport = "tcp+udp"
	policy.TargetDigest = strings.Repeat("b", 64)
	policy.Direction = "SHA -> TYO"
	policy.RouteScope = "global"
	policy.RoutePolicy = string(nativeRoutePolicyUnrestricted)
	refusing := testLease(policy, strings.Repeat("c", 64))
	refusing.Transport = policy.Transport
	refusing.CandidateKeyHash = strings.Repeat("d", 64)
	refusing.LeaseHash = strings.Repeat("9", 64)
	refusing.User = []byte("user-0123456789x")
	refusing.Password = []byte("pass-0123456789x")
	refusing.IP, refusing.Port = fakeStreamRelay(t, []byte{5, 2, 5, 2}, 200*time.Millisecond)
	working := testLease(policy, strings.Repeat("e", 64))
	working.Transport = policy.Transport
	working.SelectionRef = "native-selection-reference-second"
	working.CandidateKeyHash = strings.Repeat("f", 64)
	working.User = []byte("user-0123456789y")
	working.Password = []byte("pass-0123456789y")
	working.IP, working.Port = fakeStreamRelay(t, []byte{5, 2, 1, 0, 4, 1, 1, 0, 1, 1, 1, 1, 1, 1}, 0)
	store := NewPolicyStore(Config{ForwardID: policy.ForwardID, NativeSocks5: true, Target: "1.1.1.1:443", NativeRoutePolicy: nativeRoutePolicyUnrestricted})
	if err := store.ReplaceBatch(policy, refusing, []ProviderLease{refusing, working}); err != nil {
		t.Fatalf("replace batch: %v", err)
	}
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), policy.ForwardID, time.Millisecond)
	defer status.Close()
	snapshot, ok := store.Snapshot()
	if !ok {
		t.Fatal("missing native snapshot")
	}
	connection, selected, err := dialNativeStreamWithCandidates(
		context.Background(), store, snapshot, "1.1.1.1", 80, 3*time.Second,
		func(lease ProviderLease, host string, port int, timeout time.Duration) (net.Conn, error) {
			return dialNativeStreamConfig(Config{}, lease, host, port, timeout)
		}, status)
	if err != nil {
		t.Fatalf("native candidate dial: %v", err)
	}
	defer connection.Close()
	defer selected.wipe()
	if selected.CandidateKeyHash != working.CandidateKeyHash {
		t.Fatalf("selected candidate %q, want the relay that accepted the credential", selected.CandidateKeyHash)
	}
	status.mu.Lock()
	defer status.mu.Unlock()
	if len(status.status.CandidateFeedback) != 1 {
		t.Fatalf("candidate feedback=%+v, want exactly the rejected candidate", status.status.CandidateFeedback)
	}
	feedback := status.status.CandidateFeedback[0]
	if feedback.CandidateKeyHash != refusing.CandidateKeyHash || feedback.Protocol != "tcp" || feedback.Reason != "credential_rejected" {
		t.Fatalf("candidate feedback=%+v, want credential_rejected for the refusing relay", feedback)
	}
	if len(status.status.HAEvents) != 1 || status.status.HAEvents[0].Reason != "credential_rejected" {
		t.Fatalf("ha events=%+v, want one credential_rejected", status.status.HAEvents)
	}
}

// The case seen in the field on 2026-11-09: the relay refuses the credential on
// the first greeting and the fallback greeting runs into the deadline. Before,
// the refusal survived only as log text ("initial=%v"), so the selection probe
// reported it as probe_tcp_failed and the Panel never learned that the line's
// material held a retired relay.
func TestStreamCredentialRejectedSeesTheInitialGreeting(t *testing.T) {
	refused := fmt.Errorf("stream handshake: %w", markHandshakePhase(errStreamAuthenticationRejected))
	fallback := &greetingFallbackError{initial: refused, fallback: context.DeadlineExceeded}
	probe := wrapProbeFailure(errProbeTCPFailed, fallback)
	for _, tc := range []struct {
		name string
		err  error
		want bool
	}{
		{"nil", nil, false},
		{"bare", errStreamAuthenticationRejected, true},
		{"initial_greeting", fallback, true},
		{"initial_greeting_inside_probe", probe, true},
		{"fallback_greeting", &greetingFallbackError{initial: io.EOF, fallback: refused}, true},
		{"other_initial", &greetingFallbackError{initial: io.EOF, fallback: context.DeadlineExceeded}, false},
		{"probe_timeout", wrapProbeFailure(errProbeTCPFailed, context.DeadlineExceeded), false},
		{"probe_kind_only", errProbeTCPFailed, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if got := streamCredentialRejected(tc.err); got != tc.want {
				t.Fatalf("streamCredentialRejected(%v) = %v, want %v", tc.err, got, tc.want)
			}
		})
	}
	if got := haErrorClass(probe); got != "credential_rejected" {
		t.Fatalf("haErrorClass(probe) = %q, want credential_rejected", got)
	}
	if got := nativeTCPFailureReason(fallback); got != "credential_rejected" {
		t.Fatalf("nativeTCPFailureReason(fallback) = %q, want credential_rejected (it used to be a relay_connect_timeout strike)", got)
	}
}

// The new error types must not move any existing verdict: they unwrap to the
// same error the fmt.Errorf forms did, and print the same text.
func TestGreetingFallbackAndProbeErrorsKeepTheirVerdicts(t *testing.T) {
	refused := fmt.Errorf("stream handshake: %w", markHandshakePhase(errStreamAuthenticationRejected))
	fallback := &greetingFallbackError{initial: refused, fallback: context.DeadlineExceeded}
	old := fmt.Errorf("stream handshake failed after greeting fallback: initial=%v; fallback=%w", refused, context.DeadlineExceeded)
	if fallback.Error() != old.Error() {
		t.Fatalf("fallback text changed:\n got %q\nwant %q", fallback.Error(), old.Error())
	}
	for _, target := range []error{context.DeadlineExceeded, errStreamAuthenticationRejected, errStreamCommandRejected, io.EOF} {
		if errors.Is(fallback, target) != errors.Is(old, target) {
			t.Fatalf("errors.Is(fallback, %v) changed", target)
		}
	}
	var timeout net.Error
	if errors.As(fallback, &timeout) != errors.As(old, &timeout) {
		t.Fatal("net.Error classification of the fallback error changed")
	}
	probe := wrapProbeFailure(errProbeTCPFailed, fallback)
	oldProbe := fmt.Errorf("%w: %v", errProbeTCPFailed, fallback)
	if probe.Error() != oldProbe.Error() {
		t.Fatalf("probe text changed:\n got %q\nwant %q", probe.Error(), oldProbe.Error())
	}
	for _, target := range []error{errProbeTCPFailed, errProbeUDPFailed, context.DeadlineExceeded, errStreamAuthenticationRejected} {
		if errors.Is(probe, target) != errors.Is(oldProbe, target) {
			t.Fatalf("errors.Is(probe, %v) changed", target)
		}
	}
	if wrapProbeFailure(errProbeUDPFailed, nil) != errProbeUDPFailed {
		t.Fatal("a probe failure without a cause must stay the bare kind")
	}
}

// Through the real handshake and the real feedback sink: a relay that refuses
// the first greeting and stays silent on the fallback must reach the Panel as
// tcp credential_rejected, not probe_tcp_failed.
func TestProbeOfARelayThatRefusesTheCredentialReportsIt(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { listener.Close() })
	go func() {
		for attempt := 0; ; attempt++ {
			conn, err := listener.Accept()
			if err != nil {
				return
			}
			go func(conn net.Conn, first bool) {
				defer conn.Close()
				buffer := make([]byte, 512)
				if _, err := conn.Read(buffer); err != nil {
					return
				}
				if first {
					_, _ = conn.Write([]byte{5, 2, 5, 2})
					time.Sleep(100 * time.Millisecond)
					return
				}
				_, _ = io.Copy(io.Discard, conn) // fallback greeting: never answered
			}(conn, attempt == 0)
		}
	}()
	address := listener.Addr().(*net.TCPAddr)
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	lease := testLease(policy, strings.Repeat("c", 64))
	lease.User = []byte("user-0123456789x")
	lease.Password = []byte("pass-0123456789x")
	lease.IP, lease.Port = address.IP.String(), address.Port
	lease.CandidateKeyHash = strings.Repeat("d", 64)
	lease.LeaseHash = strings.Repeat("9", 64)

	connection, dialErr := dialStreamConfig(Config{}, lease, "1.1.1.1", 80, 700*time.Millisecond)
	if dialErr == nil {
		connection.Close()
		t.Fatal("the dial must fail")
	}
	if !strings.Contains(dialErr.Error(), "initial=stream handshake: stream authentication rejected") {
		t.Fatalf("dial error = %q, want the refused first greeting", dialErr)
	}
	if !streamCredentialRejected(dialErr) {
		t.Fatalf("streamCredentialRejected(%q) = false", dialErr)
	}

	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), policy.ForwardID, time.Millisecond)
	defer status.Close()
	status.RecordProbeFailure(lease, udpProbeResult{TCPSent: 1, TCPError: wrapProbeFailure(errProbeTCPFailed, dialErr)})
	status.mu.Lock()
	defer status.mu.Unlock()
	if len(status.status.CandidateFeedback) != 1 {
		t.Fatalf("candidate feedback = %+v, want one entry", status.status.CandidateFeedback)
	}
	feedback := status.status.CandidateFeedback[0]
	if feedback.Protocol != "tcp" || feedback.Reason != "credential_rejected" || feedback.CandidateKeyHash != lease.CandidateKeyHash {
		t.Fatalf("candidate feedback = %+v, want tcp credential_rejected for the refusing relay", feedback)
	}
}
