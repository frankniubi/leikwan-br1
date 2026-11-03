package main

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net"
	"strings"
	"testing"
	"time"
)

// The loopback provider accepts command-1 but never answers sidecar HELLO.
// It cannot send traffic to any external target.
func fallbackUoTBoundaryFixture(t *testing.T, transport string) (Config, Policy, ProviderLease, <-chan string) {
	t.Helper()
	listener, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { listener.Close() })
	config := testPolicyConfig()
	config.Target = "198.51.100.20:60051"
	config.ConnectTimeoutSecs = 1
	config.ProbeTimeoutMillis = 100
	config.ExternalUoT = ExternalUoTConfig{
		Enabled: true, SidecarTarget: "192.0.2.40:7443", Token: "offline",
		FramePayloadMax: externalUoTMaxDatagramBytes,
	}
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	policy.Transport = transport
	lease := testLease(policy, strings.Repeat("b", 64))
	lease.IP = "127.0.0.1"
	lease.Port = listener.Addr().(*net.TCPAddr).Port
	ordinary, _ := buildHandshake(string(lease.User), string(lease.Password), 1, "198.51.100.20", 60051)
	sidecar, _ := buildHandshake(string(lease.User), string(lease.Password), 1, "192.0.2.40", 7443)
	if len(ordinary) != len(sidecar) {
		t.Fatal("fixture requires equal-size IPv4 handshakes")
	}
	observed := make(chan string, 1)
	go func() {
		conn, err := listener.AcceptTCP()
		if err != nil {
			observed <- "accept_error"
			return
		}
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(time.Second))
		request := make([]byte, len(ordinary))
		if _, err := io.ReadFull(conn, request); err != nil {
			observed <- "read_error"
			return
		}
		switch {
		case bytes.Equal(request, ordinary):
			observed <- "ordinary_target"
		case bytes.Equal(request, sidecar):
			observed <- "sidecar_target"
		default:
			observed <- "unexpected_command_or_target"
		}
		// Valid stream ACK, then close: no native UDP and no sidecar HELLO ACK.
		conn.Write([]byte{5, 2, 1, 0, 4, 1, 1, 0, 1, 1, 0x12, 0x34, 0, 0})
	}()
	return config, policy, lease, observed
}

func TestActiveFallbackExternalUoTDualKeepsOrdinaryTCP(t *testing.T) {
	config, policy, lease, observed := fallbackUoTBoundaryFixture(t, "tcp+udp")
	previous := RuntimeSnapshot{Policy: policy, Lease: lease}
	selected, result, err := retryActiveCandidateForward(
		context.Background(), config, policy, previous, []ProviderLease{lease}, nil)
	target := <-observed
	if err != nil || target != "ordinary_target" {
		t.Fatalf("usable ordinary TCP was gated by sidecar: target=%s err=%v", target, err)
	}
	if !result.usableFor(effectiveProbePolicy(config, policy).Transport) || result.Sent != 0 || result.Received != 0 {
		t.Fatalf("fallback fabricated/required native UDP evidence: %+v", result)
	}
	if selected.Transport != "tcp+udp" || policy.Transport != "tcp+udp" {
		t.Fatal("probe-only normalization changed actual lease/policy authorization")
	}
}

func TestActiveFallbackExternalUoTUDPOnlyStillRequiresHello(t *testing.T) {
	config, policy, lease, observed := fallbackUoTBoundaryFixture(t, "udp")
	_, err := directForwardCandidateTest(context.Background(), config, policy, lease)
	if target := <-observed; target != "sidecar_target" || err == nil {
		t.Fatalf("UDP-only bypassed sidecar verification: target=%s err=%v", target, err)
	}
}

func TestActiveFallbackExternalUoTCanceledContextRemainsFailClosed(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	config := testPolicyConfig()
	config.ExternalUoT.Enabled = true
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	result, err := directForwardCandidateTest(ctx, config, policy, testLease(policy, strings.Repeat("b", 64)))
	if !errors.Is(err, context.Canceled) || result.TCPSent != 0 || result.Sent != 0 {
		t.Fatalf("canceled verification continued: result=%+v err=%v", result, err)
	}
}
