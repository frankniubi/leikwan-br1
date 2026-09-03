package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
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
	listenPort := freeNativeTCPPort(t)
	config := Config{
		ForwardID: 174, NativeSocks5: true, ListenHost: "127.0.0.1", ListenPort: listenPort,
		Target: "1.1.1.1:443", Transport: "tcp+udp", ConnectTimeoutSecs: 2,
		NativeRoutePolicy: nativeRoutePolicyUnrestricted,
	}
	store := newNativeTestStore(t, config, provider.tcp.Addr().(*net.TCPAddr).Port)
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	defer status.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() { serveErr <- serveNativeSocks5(ctx, config, store, status) }()
	waitNativeTCPPort(t, listenPort)

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

func TestServeNativeSocks5UdpRoundTripPreservesDestination(t *testing.T) {
	provider := newTestUDPProvider(t)
	provider.echoOuterTarget = true
	listenPort := freeNativeTCPPort(t)
	config := Config{
		ForwardID: 175, NativeSocks5: true, ListenHost: "127.0.0.1", ListenPort: listenPort,
		Target: "1.1.1.1:443", Transport: "tcp+udp", ConnectTimeoutSecs: 2,
		NativeRoutePolicy: nativeRoutePolicyUnrestricted,
	}
	store := newNativeTestStore(t, config, provider.tcp.Addr().(*net.TCPAddr).Port)
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	defer status.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() { serveErr <- serveNativeSocks5(ctx, config, store, status) }()
	waitNativeTCPPort(t, listenPort)

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

func freeNativeTCPPort(t *testing.T) int {
	t.Helper()
	listener, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := listener.Addr().(*net.TCPAddr).Port
	_ = listener.Close()
	return port
}

func waitNativeTCPPort(t *testing.T, port int) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		connection, err := net.DialTimeout("tcp", net.JoinHostPort("127.0.0.1", fmt.Sprint(port)), 100*time.Millisecond)
		if err == nil {
			_ = connection.Close()
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("native SOCKS TCP port %d did not become ready", port)
}
