package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
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

func TestNativeUDPTransientReadErrorsAreNonFatal(t *testing.T) {
	for _, err := range []error{
		syscall.Errno(10054), // WSAECONNRESET
		syscall.Errno(10061), // WSAECONNREFUSED
		syscall.Errno(4),     // EINTR
	} {
		if !isTransientNativeUDPReadError(err) {
			t.Errorf("isTransientNativeUDPReadError(%v) = false", err)
		}
	}
	if isTransientNativeUDPReadError(net.ErrClosed) {
		t.Fatal("closed UDP socket must remain fatal")
	}
}

func TestNativeSocks5ConnectRequestRoundTrip(t *testing.T) {
	request := []byte{5, 1, 0}
	request = append(request, []byte{5, 1, 0, 3, 11}...)
	request = append(request, []byte("example.com")...)
	request = append(request, 0x01, 0xbb)
	server, client := net.Pipe()
	defer server.Close()
	defer client.Close()
	go func() {
		_, _ = server.Write(request[:3])
		_, _ = io.ReadFull(server, make([]byte, 2))
		_, _ = server.Write(request[3:])
	}()

	parsed, err := readNativeSocks5Request(client)
	if err != nil {
		t.Fatalf("readNativeSocks5Request() error = %v", err)
	}
	if parsed.command != nativeSocks5CommandConnect || parsed.host != "example.com" || parsed.port != 443 {
		t.Fatalf("request = %+v", parsed)
	}
}

func TestNativeStreamCandidateDialRetriesSiblingAndReportsFailure(t *testing.T) {
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	policy.Protocol = nativeSocks5Protocol
	policy.Purpose = nativeSocks5Purpose
	policy.Native = true
	policy.Transport = "tcp+udp"
	policy.TargetDigest = strings.Repeat("b", 64)
	policy.Direction = "Hong Kong to Mainland China"
	policy.RouteScope = "global"
	policy.RoutePolicy = string(nativeRoutePolicyUnrestricted)
	first := testLease(policy, strings.Repeat("c", 64))
	first.Transport = policy.Transport
	first.CandidateKeyHash = strings.Repeat("d", 64)
	second := testLease(policy, strings.Repeat("e", 64))
	second.Transport = policy.Transport
	second.SelectionRef = "native-selection-reference-second"
	second.CandidateKeyHash = strings.Repeat("f", 64)
	store := NewPolicyStore(Config{ForwardID: policy.ForwardID, NativeSocks5: true, Target: "1.1.1.1:443", NativeRoutePolicy: nativeRoutePolicyUnrestricted})
	if err := store.ReplaceBatch(policy, first, []ProviderLease{first, second}); err != nil {
		t.Fatalf("replace batch: %v", err)
	}
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), policy.ForwardID, time.Millisecond)
	defer status.Close()
	snapshot, ok := store.Snapshot()
	if !ok {
		t.Fatal("missing native snapshot")
	}
	var attempts []string
	connection, selected, err := dialNativeStreamWithCandidates(
		context.Background(), store, snapshot, "example.com", 443, time.Second,
		func(lease ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
			attempts = append(attempts, lease.CandidateKeyHash)
			if lease.CandidateKeyHash == first.CandidateKeyHash {
				return nil, errors.New("provider timeout")
			}
			left, right := net.Pipe()
			_ = right.Close()
			return left, nil
		}, status)
	if err != nil {
		t.Fatalf("native candidate dial: %v", err)
	}
	defer connection.Close()
	defer selected.wipe()
	if !strings.EqualFold(selected.CandidateKeyHash, second.CandidateKeyHash) {
		t.Fatalf("selected candidate=%q, want sibling", selected.CandidateKeyHash)
	}
	if len(attempts) != 2 || attempts[0] != first.CandidateKeyHash || attempts[1] != second.CandidateKeyHash {
		t.Fatalf("candidate attempts=%v, want first then sibling", attempts)
	}
	status.mu.Lock()
	defer status.mu.Unlock()
	if len(status.status.CandidateFeedback) != 1 {
		t.Fatalf("candidate feedback=%d, want one failure", len(status.status.CandidateFeedback))
	}
	feedback := status.status.CandidateFeedback[0]
	if feedback.CandidateKeyHash != first.CandidateKeyHash || feedback.Protocol != "tcp" || feedback.Reason != "relay_connect_timeout" {
		t.Fatalf("candidate feedback=%+v", feedback)
	}
}

func TestNativeAssociationCandidatesRetrySiblingAndReportFailure(t *testing.T) {
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	policy.Protocol = nativeSocks5Protocol
	policy.Purpose = nativeSocks5Purpose
	policy.Native = true
	policy.Transport = "tcp+udp"
	policy.TargetDigest = strings.Repeat("b", 64)
	policy.Direction = "Hong Kong to Mainland China"
	policy.RouteScope = "global"
	policy.RoutePolicy = string(nativeRoutePolicyUnrestricted)
	first := testLease(policy, strings.Repeat("c", 64))
	first.Transport = policy.Transport
	first.CandidateKeyHash = strings.Repeat("d", 64)
	second := testLease(policy, strings.Repeat("e", 64))
	second.Transport = policy.Transport
	second.Port++
	second.SelectionRef = "native-selection-reference-second"
	second.CandidateKeyHash = strings.Repeat("f", 64)
	config := Config{ForwardID: policy.ForwardID, NativeSocks5: true, Target: "1.1.1.1:443", NativeRoutePolicy: nativeRoutePolicyUnrestricted, ConnectTimeoutSecs: 2}
	store := NewPolicyStore(config)
	if err := store.ReplaceBatch(policy, first, []ProviderLease{first, second}); err != nil {
		t.Fatalf("replace batch: %v", err)
	}
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), policy.ForwardID, time.Millisecond)
	defer status.Close()
	var attempts []string
	session, selected, err := armNativeAssociationCandidates(
		context.Background(), store, config, status,
		func(lease ProviderLease, _ time.Duration) (*DatagramSession, error) {
			attempts = append(attempts, lease.CandidateKeyHash)
			if lease.CandidateKeyHash == first.CandidateKeyHash {
				return nil, errors.New("provider timeout")
			}
			return &DatagramSession{}, nil
		})
	if err != nil {
		t.Fatalf("native UDP candidate arm: %v", err)
	}
	defer session.Close()
	defer selected.wipe()
	if selected.CandidateKeyHash != second.CandidateKeyHash {
		t.Fatalf("selected candidate=%q, want sibling", selected.CandidateKeyHash)
	}
	if len(attempts) != 2 || attempts[0] != first.CandidateKeyHash || attempts[1] != second.CandidateKeyHash {
		t.Fatalf("candidate attempts=%v, want first then sibling", attempts)
	}
	status.mu.Lock()
	defer status.mu.Unlock()
	if len(status.status.CandidateFeedback) != 1 {
		t.Fatalf("candidate feedback=%d, want one failure", len(status.status.CandidateFeedback))
	}
	feedback := status.status.CandidateFeedback[0]
	// A candidate that was never armed is a transport outcome, not a verdict on
	// the line: the same relay usually answers the next attempt.
	if feedback.CandidateKeyHash != first.CandidateKeyHash || feedback.Protocol != "udp" || feedback.Reason != "udp_arm_failed" {
		t.Fatalf("candidate feedback=%+v", feedback)
	}
}

func TestUDPArmFailureReasonSeparatesUnreachableFromRefused(t *testing.T) {
	unreachable := fmt.Errorf("%w: %w", errProviderDial, errors.New("dial tcp 1.2.3.4:5438: connect: connection refused"))
	if got := udpArmFailureReason(unreachable); got != "udp_endpoint_unreachable" {
		t.Fatalf("dial failure reason=%q, want udp_endpoint_unreachable", got)
	}
	refused := fmt.Errorf("datagram handshake failed after greeting fallback: %w", io.EOF)
	if got := udpArmFailureReason(refused); got != "udp_arm_failed" {
		t.Fatalf("refused session reason=%q, want udp_arm_failed", got)
	}
	// The established-then-broken path keeps the line-facing label.
	if got := udpRuntimeFailureReason(io.EOF); got != "udp_association_failed" {
		t.Fatalf("runtime failure reason=%q, want udp_association_failed", got)
	}
}

func TestNativeAssociationFailureReportsOnceAndMarksCandidate(t *testing.T) {
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	lease := testLease(policy, strings.Repeat("b", 64))
	lease.CandidateKeyHash = strings.Repeat("c", 64)
	config := testPolicyConfig()
	store := NewPolicyStore(config)
	if err := store.Replace(policy, lease); err != nil {
		t.Fatalf("replace lease: %v", err)
	}
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), policy.ForwardID, time.Millisecond)
	defer status.Close()
	association := &nativeSocks5UDPAssociation{
		candidateKeyHash: lease.CandidateKeyHash,
		leaseHash:        strings.Repeat("d", 64),
		selectionRef:     lease.SelectionRef,
		policyGeneration: policy.PolicyGeneration,
	}
	runtime := &nativeSocks5Runtime{config: config, store: store, status: status}
	association.recordCandidateFailure(runtime, "udp", "udp_association_failed", errors.New("provider closed"))
	association.recordCandidateFailure(runtime, "udp", "udp_association_failed", errors.New("provider closed"))
	status.mu.Lock()
	defer status.mu.Unlock()
	if status.status.HAFailures != 1 || len(status.status.CandidateFeedback) != 1 {
		t.Fatalf("failure evidence=%+v", status.status)
	}
	if status.status.CandidateFeedback[0].CandidateKeyHash != lease.CandidateKeyHash {
		t.Fatalf("candidate feedback=%+v", status.status.CandidateFeedback[0])
	}
}

func TestNativeSocks5UdpDatagramPreservesDestination(t *testing.T) {
	payload := []byte("dns-payload")
	packet, err := makeNativeSocks5UDPDatagram("1.1.1.1", 53, payload)
	if err != nil {
		t.Fatalf("makeNativeSocks5UDPDatagram() error = %v", err)
	}
	host, port, got, err := parseNativeSocks5UDPDatagram(packet)
	if err != nil {
		t.Fatalf("parseNativeSocks5UDPDatagram() error = %v", err)
	}
	if host != "1.1.1.1" || port != 53 || !bytes.Equal(got, payload) {
		t.Fatalf("parsed datagram = %q:%d %q", host, port, got)
	}
}

func TestNativeSocks5ListenerIsLoopbackOnly(t *testing.T) {
	for _, host := range []string{"127.0.0.1", "127.42.0.9", "::1", "[::1]"} {
		if !nativeSocks5ListenHostAllowed(host) {
			t.Fatalf("expected loopback host to be allowed: %q", host)
		}
	}
	for _, host := range []string{"", "localhost", "0.0.0.0", "192.0.2.1", "::"} {
		if nativeSocks5ListenHostAllowed(host) {
			t.Fatalf("expected non-loopback host to be rejected: %q", host)
		}
	}
}

func TestNativeLeaseFrameDecodesOnlyInNativeRuntime(t *testing.T) {
	raw, err := json.Marshal(map[string]any{
		"protocol":              nativeSocks5Protocol,
		"purpose":               nativeSocks5Purpose,
		"forward_id":            174,
		"lease_id":              "native-lease-abcdefghijkl",
		"policy_generation":     "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		"expires_at":            time.Now().Add(2 * time.Minute).UTC().Format(time.RFC3339),
		"transport":             "tcp+udp",
		"rate_limit_mbps":       0,
		"quota_remaining_bytes": 1024,
		"target_digest":         "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
		"selection_ref":         "selection-reference-abcdefghijklmnop",
		"selection_tier":        "unknown",
		"endpoint":              map[string]any{"ip": "192.0.2.10", "port": 5438},
		"credential":            map[string]any{"user": "user", "password": "password"},
		"direction":             "Hong Kong to Mainland China",
		"route_scope":           "global",
	})
	if err != nil {
		t.Fatal(err)
	}
	config := Config{ForwardID: 174, NativeSocks5: true, Target: "1.1.1.1:443"}
	policy, lease, err := decodeLeaseFrame(raw, config)
	if err != nil {
		t.Fatalf("decode native lease: %v", err)
	}
	if !policy.Native || lease.Port != 5438 {
		t.Fatalf("native policy/lease not retained: native=%v port=%d", policy.Native, lease.Port)
	}
	lease.wipe()
}

func TestServeNativeSocks5ConnectRoundTrip(t *testing.T) {
	provider := newNativeStreamProvider(t)
	tcpListener, udpListener, listenPort := reserveNativeSocks5Listeners(t)
	config := Config{
		ForwardID: 174, NativeSocks5: true, ListenHost: "127.0.0.1", ListenPort: listenPort,
		Target: "1.1.1.1:443", Transport: "tcp+udp", ConnectTimeoutSecs: 2,
		NativeRoutePolicy: nativeRoutePolicyUnrestricted,
	}
	store := newNativeTestStore(t, config, provider.tcp.Addr().(*net.TCPAddr).Port)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	defer status.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() {
		serveErr <- serveNativeSocks5WithListeners(ctx, config, store, status, tcpListener, udpListener)
	}()
	waitNativeTCPPort(t, listenPort, serveErr)

	client, err := net.DialTimeout("tcp", net.JoinHostPort(config.ListenHost, fmt.Sprint(listenPort)), time.Second)
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	if _, err := client.Write([]byte{5, 1, 0}); err != nil {
		t.Fatal(err)
	}
	methodReply := make([]byte, 2)
	if _, err := io.ReadFull(client, methodReply); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(methodReply, []byte{5, 0}) {
		t.Fatalf("SOCKS method reply = %x", methodReply)
	}
	request := []byte{5, 1, 0, 3, 11}
	request = append(request, []byte("example.com")...)
	request = append(request, 0x01, 0xbb)
	if _, err := client.Write(request); err != nil {
		t.Fatal(err)
	}
	reply := make([]byte, 10)
	if _, err := io.ReadFull(client, reply); err != nil {
		t.Fatal(err)
	}
	if reply[0] != 5 || reply[1] != nativeSocks5ReplySucceeded {
		t.Fatalf("SOCKS connect reply = %x", reply)
	}
	payload := []byte("native-tcp-round-trip")
	if _, err := client.Write(payload); err != nil {
		t.Fatal(err)
	}
	if err := client.SetReadDeadline(time.Now().Add(2 * time.Second)); err != nil {
		t.Fatal(err)
	}
	got := make([]byte, len(payload))
	if _, err := io.ReadFull(client, got); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, payload) {
		t.Fatalf("native TCP payload = %q, want %q", got, payload)
	}

	cancel()
	select {
	case err := <-serveErr:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("native SOCKS runtime did not stop")
	}
}

func TestServeNativeSocks5ConnectPreservesHalfCloseResponse(t *testing.T) {
	providerListener, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatalf("listen provider: %v", err)
	}
	defer providerListener.Close()
	providerDone := make(chan error, 1)
	go func() {
		connection, acceptErr := providerListener.AcceptTCP()
		if acceptErr != nil {
			providerDone <- acceptErr
			return
		}
		defer connection.Close()
		reader := bufio.NewReader(connection)
		greeting := make([]byte, len(currentHandshakeGreeting))
		if _, err := io.ReadFull(reader, greeting); err != nil {
			providerDone <- err
			return
		}
		userLength, err := reader.ReadByte()
		if err != nil {
			providerDone <- err
			return
		}
		if _, err := io.CopyN(io.Discard, reader, int64(userLength)); err != nil {
			providerDone <- err
			return
		}
		passwordLength, err := reader.ReadByte()
		if err != nil {
			providerDone <- err
			return
		}
		if _, err := io.CopyN(io.Discard, reader, int64(passwordLength)); err != nil {
			providerDone <- err
			return
		}
		command := make([]byte, 3)
		if _, err := io.ReadFull(reader, command); err != nil {
			providerDone <- err
			return
		}
		if command[0] != 5 || command[1] != nativeSocks5CommandConnect {
			providerDone <- fmt.Errorf("unexpected command %x", command)
			return
		}
		addressType, err := reader.ReadByte()
		if err != nil {
			providerDone <- err
			return
		}
		if _, err := readNativeSocks5Address(reader, addressType); err != nil {
			providerDone <- err
			return
		}
		var port [2]byte
		if _, err := io.ReadFull(reader, port[:]); err != nil {
			providerDone <- err
			return
		}
		if _, err := connection.Write([]byte{5, 2, 1, 0}); err != nil {
			providerDone <- err
			return
		}
		buffer := make([]byte, 1024)
		for {
			_, readErr := reader.Read(buffer)
			if readErr != nil {
				if errors.Is(readErr, io.EOF) {
					_, writeErr := connection.Write([]byte("half-close-response"))
					providerDone <- writeErr
					return
				}
				providerDone <- readErr
				return
			}
		}
	}()

	tcpListener, udpListener, listenPort := reserveNativeSocks5Listeners(t)
	config := Config{
		ForwardID: 178, NativeSocks5: true, ListenHost: "127.0.0.1", ListenPort: listenPort,
		Target: "1.1.1.1:443", Transport: "tcp+udp", ConnectTimeoutSecs: 2,
		NativeRoutePolicy: nativeRoutePolicyUnrestricted,
	}
	store := newNativeTestStore(t, config, providerListener.Addr().(*net.TCPAddr).Port)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	defer status.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() {
		serveErr <- serveNativeSocks5WithListeners(ctx, config, store, status, tcpListener, udpListener)
	}()
	waitNativeTCPPort(t, listenPort, serveErr)

	connection, err := net.DialTimeout("tcp", net.JoinHostPort(config.ListenHost, fmt.Sprint(listenPort)), time.Second)
	if err != nil {
		t.Fatal(err)
	}
	defer connection.Close()
	if _, err := connection.Write([]byte{5, 1, 0}); err != nil {
		t.Fatal(err)
	}
	methodReply := make([]byte, 2)
	if _, err := io.ReadFull(connection, methodReply); err != nil {
		t.Fatal(err)
	}
	request := []byte{5, nativeSocks5CommandConnect, 0, 1, 1, 1, 1, 1, 1, 0xbb}
	if _, err := connection.Write(request); err != nil {
		t.Fatal(err)
	}
	reply := make([]byte, 10)
	if _, err := io.ReadFull(connection, reply); err != nil {
		t.Fatal(err)
	}
	if reply[0] != nativeSocks5Version || reply[1] != nativeSocks5ReplySucceeded {
		t.Fatalf("SOCKS connect reply = %x", reply)
	}
	if _, err := connection.Write([]byte("half-close-request")); err != nil {
		t.Fatal(err)
	}
	tcpConnection, ok := connection.(*net.TCPConn)
	if !ok {
		t.Fatalf("local connection type = %T, want *net.TCPConn", connection)
	}
	if err := tcpConnection.CloseWrite(); err != nil {
		t.Fatal(err)
	}
	if err := connection.SetReadDeadline(time.Now().Add(3 * time.Second)); err != nil {
		t.Fatal(err)
	}
	response := make([]byte, len("half-close-response"))
	if _, err := io.ReadFull(connection, response); err != nil {
		t.Fatalf("read delayed half-close response: %v", err)
	}
	if string(response) != "half-close-response" {
		t.Fatalf("half-close response = %q", response)
	}
	select {
	case err := <-providerDone:
		if err != nil {
			t.Fatalf("provider: %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("provider did not observe half-close")
	}

	cancel()
	select {
	case err := <-serveErr:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("native SOCKS runtime did not stop")
	}
}

func TestServeNativeSocks5UdpRoundTripPreservesDestination(t *testing.T) {
	provider := newTestUDPProvider(t)
	provider.echoOuterTarget.Store(true)
	tcpListener, udpListener, listenPort := reserveNativeSocks5Listeners(t)
	config := Config{
		ForwardID: 175, NativeSocks5: true, ListenHost: "127.0.0.1", ListenPort: listenPort,
		Target: "1.1.1.1:443", Transport: "tcp+udp", ConnectTimeoutSecs: 2,
		NativeRoutePolicy: nativeRoutePolicyUnrestricted,
	}
	store := newNativeTestStore(t, config, provider.tcp.Addr().(*net.TCPAddr).Port)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	defer status.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() {
		serveErr <- serveNativeSocks5WithListeners(ctx, config, store, status, tcpListener, udpListener)
	}()
	waitNativeTCPPort(t, listenPort, serveErr)

	control, err := net.DialTimeout("tcp", net.JoinHostPort(config.ListenHost, fmt.Sprint(listenPort)), time.Second)
	if err != nil {
		t.Fatal(err)
	}
	defer control.Close()
	if _, err := control.Write([]byte{5, 1, 0}); err != nil {
		t.Fatal(err)
	}
	methodReply := make([]byte, 2)
	if _, err := io.ReadFull(control, methodReply); err != nil {
		t.Fatal(err)
	}
	associateRequest := []byte{5, nativeSocks5CommandUDP, 0, 1, 0, 0, 0, 0, 0, 0}
	if _, err := control.Write(associateRequest); err != nil {
		t.Fatal(err)
	}
	associateReply := make([]byte, 10)
	if _, err := io.ReadFull(control, associateReply); err != nil {
		t.Fatal(err)
	}
	if associateReply[1] != nativeSocks5ReplySucceeded {
		t.Fatalf("UDP associate reply = %x", associateReply)
	}
	udpClient, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer udpClient.Close()
	packet, err := makeNativeSocks5UDPDatagram("1.1.1.1", 53, []byte("native-udp-round-trip"))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := udpClient.WriteToUDP(packet, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: listenPort}); err != nil {
		t.Fatal(err)
	}
	if err := udpClient.SetReadDeadline(time.Now().Add(4 * time.Second)); err != nil {
		t.Fatal(err)
	}
	response := make([]byte, 2048)
	count, _, err := udpClient.ReadFromUDP(response)
	if err != nil {
		t.Fatal(err)
	}
	host, port, payload, err := parseNativeSocks5UDPDatagram(response[:count])
	if err != nil {
		t.Fatal(err)
	}
	if host != "1.1.1.1" || port != 53 || !bytes.Equal(payload, []byte("native-udp-round-trip")) {
		t.Fatalf("native UDP response = %q:%d %q", host, port, payload)
	}

	cancel()
	select {
	case err := <-serveErr:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("native SOCKS runtime did not stop")
	}
}

func TestServeNativeSocks5UdpKeepsAssociationAlive(t *testing.T) {
	provider := newTestUDPProvider(t)
	provider.echoOuterTarget.Store(true)
	tcpListener, udpListener, listenPort := reserveNativeSocks5Listeners(t)
	config := Config{
		ForwardID: 176, NativeSocks5: true, ListenHost: "127.0.0.1", ListenPort: listenPort,
		Target: "1.1.1.1:443", Transport: "tcp+udp", ConnectTimeoutSecs: 2,
		UDPKeepaliveIntervalSecs: 5, NativeRoutePolicy: nativeRoutePolicyUnrestricted,
	}
	store := newNativeTestStore(t, config, provider.tcp.Addr().(*net.TCPAddr).Port)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	defer status.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() {
		serveErr <- serveNativeSocks5WithListeners(ctx, config, store, status, tcpListener, udpListener)
	}()
	waitNativeTCPPort(t, listenPort, serveErr)

	control, err := net.DialTimeout("tcp", net.JoinHostPort(config.ListenHost, fmt.Sprint(listenPort)), time.Second)
	if err != nil {
		t.Fatal(err)
	}
	defer control.Close()
	if _, err := control.Write([]byte{5, 1, 0}); err != nil {
		t.Fatal(err)
	}
	methodReply := make([]byte, 2)
	if _, err := io.ReadFull(control, methodReply); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(methodReply, []byte{5, 0}) {
		t.Fatalf("SOCKS method reply = %x", methodReply)
	}
	associateRequest := []byte{5, nativeSocks5CommandUDP, 0, 1, 0, 0, 0, 0, 0, 0}
	if _, err := control.Write(associateRequest); err != nil {
		t.Fatal(err)
	}
	associateReply := make([]byte, 10)
	if _, err := io.ReadFull(control, associateReply); err != nil {
		t.Fatal(err)
	}
	if associateReply[1] != nativeSocks5ReplySucceeded {
		t.Fatalf("UDP associate reply = %x", associateReply)
	}
	udpClient, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer udpClient.Close()
	packet, err := makeNativeSocks5UDPDatagram("1.1.1.1", 53, []byte("native-udp-keepalive"))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := udpClient.WriteToUDP(packet, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: listenPort}); err != nil {
		t.Fatal(err)
	}
	if err := udpClient.SetReadDeadline(time.Now().Add(4 * time.Second)); err != nil {
		t.Fatal(err)
	}
	response := make([]byte, 2048)
	count, _, err := udpClient.ReadFromUDP(response)
	if err != nil {
		t.Fatal(err)
	}
	_, _, payload, err := parseNativeSocks5UDPDatagram(response[:count])
	if err != nil || !bytes.Equal(payload, []byte("native-udp-keepalive")) {
		t.Fatalf("native UDP response payload = %q, err=%v", payload, err)
	}

	// armDatagram may probe several candidate SIDs with reserved frames. Drain
	// those setup packets first, then require a later frame from the association
	// ticker rather than accidentally accepting another handshake probe.
	quietUntil := time.Now().Add(500 * time.Millisecond)
	for time.Now().Before(quietUntil) {
		select {
		case <-provider.packets:
			quietUntil = time.Now().Add(100 * time.Millisecond)
		default:
			time.Sleep(10 * time.Millisecond)
		}
	}
	waitForProviderPacket(t, provider.packets, 7*time.Second, isReservedKeepPacket)

	cancel()
	select {
	case err := <-serveErr:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("native SOCKS runtime did not stop")
	}
}

func TestServeNativeSocks5UdpIsolatesConcurrentLocalSockets(t *testing.T) {
	provider := newTestUDPProvider(t)
	provider.echoOuterTarget.Store(true)
	tcpListener, udpListener, listenPort := reserveNativeSocks5Listeners(t)
	config := Config{
		ForwardID: 177, NativeSocks5: true, ListenHost: "127.0.0.1", ListenPort: listenPort,
		Target: "1.1.1.1:443", Transport: "tcp+udp", ConnectTimeoutSecs: 2,
		NativeRoutePolicy: nativeRoutePolicyUnrestricted,
	}
	store := newNativeTestStore(t, config, provider.tcp.Addr().(*net.TCPAddr).Port)
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	defer status.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() {
		serveErr <- serveNativeSocks5WithListeners(ctx, config, store, status, tcpListener, udpListener)
	}()
	waitNativeTCPPort(t, listenPort, serveErr)

	openControl := func() net.Conn {
		testControl, err := net.DialTimeout("tcp", net.JoinHostPort(config.ListenHost, fmt.Sprint(listenPort)), time.Second)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := testControl.Write([]byte{5, 1, 0}); err != nil {
			t.Fatal(err)
		}
		methodReply := make([]byte, 2)
		if _, err := io.ReadFull(testControl, methodReply); err != nil {
			t.Fatal(err)
		}
		if !bytes.Equal(methodReply, []byte{5, 0}) {
			t.Fatalf("SOCKS method reply = %x", methodReply)
		}
		associateRequest := []byte{5, nativeSocks5CommandUDP, 0, 1, 0, 0, 0, 0, 0, 0}
		if _, err := testControl.Write(associateRequest); err != nil {
			t.Fatal(err)
		}
		associateReply := make([]byte, 10)
		if _, err := io.ReadFull(testControl, associateReply); err != nil {
			t.Fatal(err)
		}
		if associateReply[1] != nativeSocks5ReplySucceeded {
			t.Fatalf("UDP associate reply = %x", associateReply)
		}
		return testControl
	}
	controlA := openControl()
	defer controlA.Close()
	controlB := openControl()
	defer controlB.Close()

	clientA, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer clientA.Close()
	clientB, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer clientB.Close()
	destination := &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: listenPort}
	packetA, err := makeNativeSocks5UDPDatagram("1.1.1.1", 53, []byte("socket-a"))
	if err != nil {
		t.Fatal(err)
	}
	packetB, err := makeNativeSocks5UDPDatagram("1.1.1.1", 53, []byte("socket-b"))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := clientA.WriteToUDP(packetA, destination); err != nil {
		t.Fatal(err)
	}
	if _, err := clientB.WriteToUDP(packetB, destination); err != nil {
		t.Fatal(err)
	}
	readPayload := func(client *net.UDPConn, want []byte) {
		if err := client.SetReadDeadline(time.Now().Add(5 * time.Second)); err != nil {
			t.Fatal(err)
		}
		response := make([]byte, 2048)
		count, _, err := client.ReadFromUDP(response)
		if err != nil {
			t.Fatalf("UDP response for %q: %v", want, err)
		}
		_, _, payload, err := parseNativeSocks5UDPDatagram(response[:count])
		if err != nil {
			t.Fatal(err)
		}
		if !bytes.Equal(payload, want) {
			t.Fatalf("UDP response payload = %q, want %q", payload, want)
		}
	}
	readPayload(clientA, []byte("socket-a"))
	readPayload(clientB, []byte("socket-b"))
	if err := controlA.Close(); err != nil {
		t.Fatal(err)
	}
	packetB2, err := makeNativeSocks5UDPDatagram("1.1.1.1", 53, []byte("socket-b-after-a-close"))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := clientB.WriteToUDP(packetB2, destination); err != nil {
		t.Fatal(err)
	}
	readPayload(clientB, []byte("socket-b-after-a-close"))

	cancel()
	select {
	case err := <-serveErr:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("native SOCKS runtime did not stop")
	}
}

type nativeStreamProvider struct {
	tcp *net.TCPListener
}

func newNativeStreamProvider(t *testing.T) *nativeStreamProvider {
	t.Helper()
	listener, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	provider := &nativeStreamProvider{tcp: listener}
	go provider.serve()
	t.Cleanup(func() { _ = listener.Close() })
	return provider
}

func (provider *nativeStreamProvider) serve() {
	for {
		connection, err := provider.tcp.AcceptTCP()
		if err != nil {
			return
		}
		go provider.handle(connection)
	}
}

func (provider *nativeStreamProvider) handle(connection *net.TCPConn) {
	defer connection.Close()
	reader := bufio.NewReader(connection)
	greeting := make([]byte, len(currentHandshakeGreeting))
	if _, err := io.ReadFull(reader, greeting); err != nil {
		return
	}
	userLength, err := reader.ReadByte()
	if err != nil {
		return
	}
	if _, err := io.CopyN(io.Discard, reader, int64(userLength)); err != nil {
		return
	}
	passwordLength, err := reader.ReadByte()
	if err != nil {
		return
	}
	if _, err := io.CopyN(io.Discard, reader, int64(passwordLength)); err != nil {
		return
	}
	command := make([]byte, 3)
	if _, err := io.ReadFull(reader, command); err != nil {
		return
	}
	if command[0] != 5 || command[1] != 1 {
		return
	}
	if _, err := readNativeSocks5Address(reader, mustReadByte(reader)); err != nil {
		return
	}
	if _, err := io.CopyN(io.Discard, reader, 2); err != nil {
		return
	}
	if _, err := connection.Write([]byte{5, 2, 1, 0}); err != nil {
		return
	}
	buffer := make([]byte, 32*1024)
	for {
		count, err := reader.Read(buffer)
		if count > 0 {
			decoded := xorOne(buffer[:count])
			writeErr := writeAll(connection, decoded)
			clearBytes(decoded)
			if writeErr != nil {
				return
			}
		}
		if err != nil {
			return
		}
	}
}

func mustReadByte(reader *bufio.Reader) byte {
	value, _ := reader.ReadByte()
	return value
}

func newNativeTestStore(t *testing.T, config Config, providerPort int) *PolicyStore {
	t.Helper()
	store := NewPolicyStore(config)
	now := time.Now()
	policy := Policy{
		Protocol: nativeSocks5Protocol, Purpose: nativeSocks5Purpose, Native: true,
		ForwardID: config.ForwardID, PolicyGeneration: strings.Repeat("a", 64),
		ExpiresAt: now.Add(2 * time.Minute), Transport: "tcp+udp", RateLimitMbps: 100,
		QuotaRemainingBytes: 1 << 20, TargetDigest: strings.Repeat("b", 64),
		Direction: "Hong Kong to Mainland China", RouteScope: "global",
		RoutePolicy: string(nativeRoutePolicyForConfig(config)),
	}
	lease := ProviderLease{
		LeaseID: "native-test-lease-abcdefghijkl", PolicyGeneration: policy.PolicyGeneration,
		ExpiresAt: policy.ExpiresAt, Transport: policy.Transport,
		SelectionRef: "native-selection-reference", SelectionTier: selectionTierPaid,
		NonceHash: strings.Repeat("c", 64), IP: "127.0.0.1", Port: providerPort,
		User: []byte("native-user"), Password: []byte("1234567890123456"),
	}
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	return store
}

func reserveNativeSocks5Listeners(t *testing.T) (net.Listener, *net.UDPConn, int) {
	t.Helper()
	for range 16 {
		tcpListener, err := net.Listen("tcp4", "127.0.0.1:0")
		if err != nil {
			t.Fatal(err)
		}
		port := tcpListener.Addr().(*net.TCPAddr).Port
		udpListener, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: port})
		if err == nil {
			t.Cleanup(func() {
				_ = tcpListener.Close()
				_ = udpListener.Close()
			})
			return tcpListener, udpListener, port
		}
		_ = tcpListener.Close()
	}
	t.Fatal("could not reserve matching native SOCKS TCP and UDP ports")
	return nil, nil, 0
}

// Reserve both protocols before the runtime starts; freeing an ephemeral TCP
// port before it binds lets another process steal the port and makes this
// startup test flaky.
func waitNativeTCPPort(t *testing.T, port int, serveErr <-chan error) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	address := net.JoinHostPort("127.0.0.1", fmt.Sprint(port))
	for time.Now().Before(deadline) {
		select {
		case err := <-serveErr:
			if err == nil {
				t.Fatal("native SOCKS runtime exited before becoming ready")
			}
			t.Fatalf("native SOCKS runtime failed before becoming ready: %v", err)
		default:
		}
		connection, err := net.DialTimeout("tcp", address, 100*time.Millisecond)
		if err == nil {
			if err := completeSocksGreeting(connection); err == nil {
				_ = connection.Close()
				return
			}
			_ = connection.Close()
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("native SOCKS TCP port %d did not become ready", port)
}

// completeSocksGreeting performs the method negotiation a SOCKS5 server must
// answer with 05 00. Anything else on the port is not the server under test.
func completeSocksGreeting(connection net.Conn) error {
	if err := connection.SetDeadline(time.Now().Add(250 * time.Millisecond)); err != nil {
		return err
	}
	if _, err := connection.Write([]byte{5, 1, 0}); err != nil {
		return err
	}
	reply := make([]byte, 2)
	if _, err := io.ReadFull(connection, reply); err != nil {
		return err
	}
	if !bytes.Equal(reply, []byte{5, 0}) {
		return fmt.Errorf("unexpected SOCKS method reply %x", reply)
	}
	return nil
}
