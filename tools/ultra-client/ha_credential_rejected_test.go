package main

import (
	"context"
	"errors"
	"fmt"
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
