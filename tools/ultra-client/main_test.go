package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func TestBuildStreamHandshake(t *testing.T) {
	got, err := buildHandshake("line-user", "1234567890123456", 1, "1.1.1.1", 80)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.HasPrefix(got, []byte{5, 5, 0, 1, 2, 0x80, 0x83, 1, 9}) {
		t.Fatalf("unexpected prefix: %s", hex.EncodeToString(got))
	}
	if !bytes.Contains(got, []byte{5, 1}) {
		t.Fatalf("stream command missing: %s", hex.EncodeToString(got))
	}
}

func TestBuildDatagramHandshakeUsesReferenceFiveMethodGreeting(t *testing.T) {
	got, err := buildHandshake("line-user", "1234567890123456", 3, "1.1.1.1", 53)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.HasPrefix(got, []byte{5, 5, 0, 1, 2, 0x80, 0x83, 1, 9}) {
		t.Fatalf("unexpected datagram prefix: %s", hex.EncodeToString(got))
	}
	if !bytes.Contains(got, []byte{5, 3}) {
		t.Fatalf("datagram command missing: %s", hex.EncodeToString(got))
	}
}

func TestBuildHandshakeRejectsUnknownCommand(t *testing.T) {
	if _, err := buildHandshake("line-user", "1234567890123456", 2, "1.1.1.1", 80); err == nil {
		t.Fatal("unknown handshake command was accepted")
	}
}

type partialTestWriter struct {
	buf bytes.Buffer
	max int
}

func (w *partialTestWriter) Write(payload []byte) (int, error) {
	if len(payload) > w.max {
		payload = payload[:w.max]
	}
	return w.buf.Write(payload)
}

func TestWriteAllHandlesPartialWrites(t *testing.T) {
	w := &partialTestWriter{max: 2}
	want := []byte("complete handshake payload")
	if err := writeAll(w, want); err != nil {
		t.Fatalf("writeAll() error = %v", err)
	}
	if !bytes.Equal(w.buf.Bytes(), want) {
		t.Fatalf("writeAll() wrote %x, want %x", w.buf.Bytes(), want)
	}
}

type handshakeTimeoutTestError struct{}

func (handshakeTimeoutTestError) Error() string   { return "timeout" }
func (handshakeTimeoutTestError) Timeout() bool   { return true }
func (handshakeTimeoutTestError) Temporary() bool { return true }

func TestHandshakeFallbackDoesNotRetryProviderConnectTimeout(t *testing.T) {
	connectErr := fmt.Errorf("provider connect: %w", handshakeTimeoutTestError{})
	if isRetryableHandshakeError(connectErr) {
		t.Fatal("provider connect timeout was classified as a handshake failure")
	}
	handshakeErr := fmt.Errorf("stream handshake: %w", markHandshakePhase(handshakeTimeoutTestError{}))
	if !isRetryableHandshakeError(handshakeErr) {
		t.Fatal("handshake timeout was not classified as retryable")
	}
}

func TestDialStreamFallsBackToLegacyGreetingAfterEOF(t *testing.T) {
	listener, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.ParseIP("127.0.0.1")})
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer listener.Close()

	user, credential, host := "test-user", "test-credential", "example.com"
	current, err := buildHandshake(user, credential, 1, host, 443)
	if err != nil {
		t.Fatalf("build current handshake: %v", err)
	}
	legacy, err := buildHandshakeWithGreeting(user, credential, 1, host, 443, legacyHandshakeGreeting)
	if err != nil {
		t.Fatalf("build legacy handshake: %v", err)
	}

	handshakes := make(chan []byte, 2)
	serverErr := make(chan error, 1)
	go func() {
		for index, size := range []int{len(current), len(legacy)} {
			conn, acceptErr := listener.AcceptTCP()
			if acceptErr != nil {
				serverErr <- acceptErr
				return
			}
			func() {
				defer conn.Close()
				_ = conn.SetReadDeadline(time.Now().Add(time.Second))
				payload := make([]byte, size)
				if _, readErr := io.ReadFull(conn, payload); readErr != nil {
					serverErr <- readErr
					return
				}
				handshakes <- payload
				if index == 0 {
					return
				}
				if _, writeErr := conn.Write([]byte{5, 2, 1, 0, 4, 1, 1, 0, 1, 1, 0x12, 0x34, 0, 0}); writeErr != nil {
					serverErr <- writeErr
					return
				}
			}()
		}
		serverErr <- nil
	}()

	lease := ProviderLease{
		IP:       "127.0.0.1",
		Port:     listener.Addr().(*net.TCPAddr).Port,
		User:     []byte(user),
		Password: []byte(credential),
	}
	conn, err := dialStream(lease, host, 443, time.Second)
	if err != nil {
		t.Fatalf("dialStream() error = %v", err)
	}
	defer conn.Close()

	first := <-handshakes
	second := <-handshakes
	if !bytes.Equal(first, current) {
		t.Fatalf("first greeting = %x, want current %x", first, current)
	}
	if !bytes.Equal(second, legacy) {
		t.Fatalf("fallback greeting = %x, want legacy %x", second, legacy)
	}
	if err := <-serverErr; err != nil {
		t.Fatalf("fake server: %v", err)
	}
}

func TestArmDatagramFallsBackToLegacyGreetingAfterEOF(t *testing.T) {
	tcpListener, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.ParseIP("127.0.0.1")})
	if err != nil {
		t.Fatalf("listen tcp: %v", err)
	}
	defer tcpListener.Close()
	udpConn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.ParseIP("127.0.0.1")})
	if err != nil {
		t.Fatalf("listen udp: %v", err)
	}
	defer udpConn.Close()

	user, credential := "test-user", "test-credential"
	port := tcpListener.Addr().(*net.TCPAddr).Port
	udpPort := udpConn.LocalAddr().(*net.UDPAddr).Port
	current, err := buildHandshake(user, credential, 3, "127.0.0.1", port)
	if err != nil {
		t.Fatalf("build current handshake: %v", err)
	}
	legacy, err := buildHandshakeWithGreeting(user, credential, 3, "127.0.0.1", port, legacyHandshakeGreeting)
	if err != nil {
		t.Fatalf("build legacy handshake: %v", err)
	}

	handshakes := make(chan []byte, 2)
	serverErr := make(chan error, 1)
	go func() {
		for index, size := range []int{len(current), len(legacy)} {
			conn, acceptErr := tcpListener.AcceptTCP()
			if acceptErr != nil {
				serverErr <- acceptErr
				return
			}
			func() {
				defer conn.Close()
				_ = conn.SetReadDeadline(time.Now().Add(time.Second))
				payload := make([]byte, size)
				if _, readErr := io.ReadFull(conn, payload); readErr != nil {
					serverErr <- readErr
					return
				}
				handshakes <- payload
				if index == 0 {
					return
				}
				encodedPort := uint16(udpPort) ^ 0x0101
				reply := []byte{4, 1, 1, 0, 1, 7, 0x10, 0x20, byte(encodedPort >> 8), byte(encodedPort)}
				if _, writeErr := conn.Write(reply); writeErr != nil {
					serverErr <- writeErr
				}
			}()
		}
		serverErr <- nil
	}()

	go func() {
		payload := make([]byte, 2048)
		_ = udpConn.SetReadDeadline(time.Now().Add(2 * time.Second))
		n, addr, readErr := udpConn.ReadFromUDP(payload)
		if readErr != nil {
			return
		}
		if n < 7 {
			return
		}
		response := append([]byte{0, 0, 0, 1, 2, 0, 0, 0, 0, 0}, []byte("ack")...)
		_, _ = udpConn.WriteToUDP(response, addr)
	}()

	lease := ProviderLease{
		IP:       "127.0.0.1",
		Port:     port,
		User:     []byte(user),
		Password: []byte(credential),
	}
	session, err := armDatagram(lease, time.Second)
	if err != nil {
		t.Fatalf("armDatagram() error = %v", err)
	}
	defer session.Close()

	first := <-handshakes
	second := <-handshakes
	if !bytes.Equal(first, current) {
		t.Fatalf("first greeting = %x, want current %x", first, current)
	}
	if !bytes.Equal(second, legacy) {
		t.Fatalf("fallback greeting = %x, want legacy %x", second, legacy)
	}
	if err := <-serverErr; err != nil {
		t.Fatalf("fake server: %v", err)
	}
}

func TestReadStreamAcksConsumesUltraPreludeAndPreservesPayload(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	prelude := []byte{0, 0, 0, 0, 0, 0, 0, 0, 1, 0x15, 0x4f, 0xd1, 0xd9, 0xfe}
	payload := []byte("HTTP/1.1 204 No Content\r\n\r\n")
	go func() { _, _ = server.Write(append(prelude, payload...)) }()
	reader, err := readStreamAcks(client)
	if err != nil {
		t.Fatal(err)
	}
	got := make([]byte, len(payload))
	_, err = io.ReadFull(reader, got)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, payload) {
		t.Fatalf("payload after prelude = %q, want %q", got, payload)
	}
}

func TestReadStreamAcksAcceptsLegacySocksAck(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	legacy := []byte{5, 2, 1, 0, 4, 1, 1, 0, 1, 1, 0x12, 0x34, 0, 0}
	payload := []byte("payload")
	go func() { _, _ = server.Write(append(legacy, payload...)) }()
	reader, err := readStreamAcks(client)
	if err != nil {
		t.Fatal(err)
	}
	got := make([]byte, len(payload))
	_, err = io.ReadFull(reader, got)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, payload) {
		t.Fatalf("payload after legacy ack = %q, want %q", got, payload)
	}
}

func TestReadStreamAcksAcceptsPlainFourByteAck(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	payload := []byte("address-first-payload")
	go func() { _, _ = server.Write(append([]byte{5, 2, 1, 0}, payload...)) }()
	reader, err := readStreamAcks(client)
	if err != nil {
		t.Fatal(err)
	}
	got := make([]byte, len(payload))
	if _, err := io.ReadFull(reader, got); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, payload) {
		t.Fatalf("payload after four-byte ack = %q, want %q", got, payload)
	}
}

func TestBufferedConnDropsDelayedTargetConnectPrelude(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	final := []byte{4, 1, 1, 0, 1, 1, 1, 1, 1, 1}
	payload := []byte("dns-response")
	go func() {
		_, _ = server.Write(append([]byte{5, 2, 1, 0}, final...))
		_, _ = server.Write(payload)
	}()
	reader, err := readStreamAcks(client)
	if err != nil {
		t.Fatal(err)
	}
	conn := &bufferedConn{Conn: client, reader: reader}
	got := make([]byte, len(payload))
	if _, err := io.ReadFull(conn, got); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, payload) {
		t.Fatalf("payload after delayed target prelude = %q, want %q", got, payload)
	}
}

func TestBufferedConnPreservesNonPreludeFirstBytes(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	payload := []byte{4, 1, 9, 8, 7}
	go func() { _, _ = server.Write(payload) }()
	conn := &bufferedConn{Conn: client, reader: bufio.NewReader(client)}
	got := make([]byte, len(payload))
	if _, err := io.ReadFull(conn, got); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, payload) {
		t.Fatalf("non-prelude bytes changed = %x, want %x", got, payload)
	}
}

func TestOutboundStreamTransform(t *testing.T) {
	input := []byte("GET / HTTP/1.0\r\n\r\n")
	encoded := xorOne(input)
	if bytes.Equal(input, encoded) {
		t.Fatal("stream bytes were not transformed")
	}
	if !bytes.Equal(input, xorOne(encoded)) {
		t.Fatal("stream transform is not reversible")
	}
}

func TestDatagramEnvelopeRoundTrip(t *testing.T) {
	payload := []byte("payload")
	packet, err := makeDatagramEnvelope(6, []byte{0x10, 0x4b, 0x20}, "1.1.1.1", 53, payload)
	if err != nil {
		t.Fatal(err)
	}
	if len(packet) != 16+len(payload) {
		t.Fatalf("unexpected packet length: %d", len(packet))
	}
	down := append([]byte{0, 0, 0, 1, 1, 1, 1, 1, 0, 53}, payload...)
	decoded, ok := unwrapDatagram(down)
	if !ok || !bytes.Equal(decoded, payload) {
		t.Fatal("datagram response did not unwrap")
	}
	addressFirst := append([]byte{1, 1, 1, 1, 1, 0, 53}, payload...)
	decoded, ok = unwrapDatagram(addressFirst)
	if !ok || !bytes.Equal(decoded, payload) {
		t.Fatal("address-first datagram response did not unwrap")
	}
}

func TestDecodeLocalUDPRequestSupportsShadowsocksEnvelopeAndRawUDP(t *testing.T) {
	payload := []byte("dns-payload")
	request := append([]byte{0, 0, 0, 1, 1, 1, 1, 1, 0, 53}, payload...)
	decoded, socksUDP, valid := decodeLocalUDPRequest(request)
	if !valid || !socksUDP || !bytes.Equal(decoded, request[3:]) {
		t.Fatalf("decoded SOCKS UDP request = payload:%q socks:%v valid:%v", decoded, socksUDP, valid)
	}
	raw := []byte("raw-game-payload")
	decoded, socksUDP, valid = decodeLocalUDPRequest(raw)
	if !valid || socksUDP || !bytes.Equal(decoded, raw) {
		t.Fatalf("decoded raw UDP request = payload:%q socks:%v valid:%v", decoded, socksUDP, valid)
	}
	response, err := makeLocalUDPResponse("1.1.1.1", 53, payload)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(response[:10], request[:10]) || !bytes.Equal(response[10:], payload) {
		t.Fatalf("local SOCKS UDP response = %x, want header %x + payload", response, request[:10])
	}
}

func TestUnwrapNestedLocalUDPResponse(t *testing.T) {
	inner := append([]byte{1, 1, 1, 1, 1, 0, 53}, []byte("dns-response")...)
	outer := datagramResponseInfo{
		addressType: 1,
		address:     []byte{185, 220, 238, 143},
		port:        10001,
		payload:     inner,
	}
	gotInfo, gotPayload := unwrapNestedLocalUDPResponse(outer, outer.payload)
	if gotInfo.addressType != 1 || !bytes.Equal(gotInfo.address, []byte{1, 1, 1, 1}) || gotInfo.port != 53 {
		t.Fatalf("nested response info = %+v, want target address", gotInfo)
	}
	if !bytes.Equal(gotPayload, []byte("dns-response")) {
		t.Fatalf("nested response payload = %q", gotPayload)
	}
	opaque := []byte("raw-game-payload")
	opaqueInfo, opaquePayload := unwrapNestedLocalUDPResponse(outer, opaque)
	if opaqueInfo.addressType != outer.addressType || !bytes.Equal(opaquePayload, opaque) {
		t.Fatalf("opaque response changed: info=%+v payload=%q", opaqueInfo, opaquePayload)
	}
}

func TestDatagramSessionResponseAcceptsReservedReferenceFrame(t *testing.T) {
	sid := []byte{0x10, 0x20, 0x30}
	// The reference relay returns a standard SOCKS UDP frame and does not
	// echo the client-side SID. Keep the payload opaque here.
	packet := append([]byte{0, 0, 0, 1, 2, 0, 0, 0, 0, 0}, []byte("reference")...)
	if !validDatagramSessionResponse(packet, sid) {
		t.Fatal("reserved reference response was rejected")
	}
}

func TestDatagramKeepaliveDetectionRequiresReservedFrame(t *testing.T) {
	response := append([]byte{0, 0, 0, 1, 1, 1, 1, 1, 0, 53}, []byte(keepPayloadHex)...)
	if isDatagramKeepaliveResponse(response, response[10:]) {
		t.Fatal("keepalive payload alone was accepted as a protocol marker")
	}
	reserved := append([]byte{0, 0, 0, 1, 2, 0, 0, 0, 0, 0}, []byte("different opaque body")...)
	if !isDatagramKeepaliveResponse(reserved, reserved[10:]) {
		t.Fatal("reserved keepalive response was rejected")
	}
}

type testUDPProvider struct {
	tcp           *net.TCPListener
	udp           *net.UDPConn
	firstAccepted chan struct{}
	acceptedOnce  sync.Once
	packets       chan []byte
}

func newTestUDPProvider(t *testing.T) *testUDPProvider {
	t.Helper()
	tcp, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	udp, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		_ = tcp.Close()
		t.Fatal(err)
	}
	provider := &testUDPProvider{
		tcp: tcp, udp: udp, firstAccepted: make(chan struct{}), packets: make(chan []byte, 128),
	}
	go provider.serveTCP()
	go provider.serveUDP()
	t.Cleanup(func() {
		_ = provider.tcp.Close()
		_ = provider.udp.Close()
	})
	return provider
}

func (provider *testUDPProvider) serveTCP() {
	for {
		connection, err := provider.tcp.AcceptTCP()
		if err != nil {
			return
		}
		provider.acceptedOnce.Do(func() { close(provider.firstAccepted) })
		go func() {
			defer connection.Close()
			_ = connection.SetReadDeadline(time.Now().Add(time.Second))
			_, _ = connection.Read(make([]byte, 512))
			encodedPort := uint16(provider.udp.LocalAddr().(*net.UDPAddr).Port) ^ 0x0101
			reply := []byte{4, 1, 1, 0, 1, 7, 0x10, 0x20, 0, 0}
			binary.BigEndian.PutUint16(reply[8:], encodedPort)
			_, _ = connection.Write(reply)
		}()
	}
}

func (provider *testUDPProvider) serveUDP() {
	buffer := make([]byte, 65535)
	for {
		count, client, err := provider.udp.ReadFromUDP(buffer)
		if err != nil {
			return
		}
		packet := append([]byte(nil), buffer[:count]...)
		select {
		case provider.packets <- packet:
		default:
		}
		if count < 16 {
			continue
		}
		if bytes.Equal(packet[10:14], net.IPv4(2, 0, 0, 0).To4()) && binary.BigEndian.Uint16(packet[14:16]) == 0 {
			response := append([]byte{0, 0, 0, 1, 2, 0, 0, 0, 0, 0}, []byte(keepPayloadHex)...)
			_, _ = provider.udp.WriteToUDP(response, client)
			continue
		}
		payload := append([]byte(nil), packet[16:]...)
		// A real `none` Shadowsocks target receives and returns the
		// address-first wire form. Decode that form in the fixture so the test
		// exercises the runtime's local SOCKS UDP unwrap/rewrap path instead of
		// echoing the destination header as application data.
		responseAddress := []byte{127, 0, 0, 1}
		responsePort := uint16(53)
		if info, valid := decodeDatagramAddressFirst(payload); valid {
			responseAddress = append([]byte(nil), info.address...)
			responsePort = info.port
			payload = append([]byte(nil), info.payload...)
		}
		response := append([]byte{0, 0, 0, 1}, responseAddress...)
		var port [2]byte
		binary.BigEndian.PutUint16(port[:], responsePort)
		response = append(response, port[:]...)
		response = append(response, payload...)
		_, _ = provider.udp.WriteToUDP(response, client)
	}
}

func testUDPStore(t *testing.T, config Config, provider *testUDPProvider) *PolicyStore {
	t.Helper()
	store := NewPolicyStore(config)
	now := time.Now()
	policy := Policy{
		Protocol: policyProtocol, ForwardID: config.ForwardID,
		PolicyGeneration: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		SessionTicket:    "ticket", ExpiresAt: now.Add(time.Minute), Transport: "udp",
		RateLimitMbps: 100, QuotaRemainingBytes: 1 << 20, TargetDigest: targetDigest(config.Target),
	}
	lease := ProviderLease{
		LeaseID: "ul2_test", PolicyGeneration: policy.PolicyGeneration, ExpiresAt: policy.ExpiresAt,
		Transport: "udp", SelectionRef: "selection-reference-abcdefghijklmnop",
		NonceHash: "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
		IP:        "127.0.0.1", Port: provider.tcp.Addr().(*net.TCPAddr).Port,
		User: []byte("user"), Password: []byte("1234567890123456"),
	}
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	return store
}

func isReservedKeepPacket(packet []byte) bool {
	return len(packet) >= 16 &&
		bytes.Equal(packet[10:14], net.IPv4(2, 0, 0, 0).To4()) &&
		binary.BigEndian.Uint16(packet[14:16]) == 0 &&
		bytes.Equal(packet[16:], []byte(keepPayloadHex))
}

func waitForProviderPacket(t *testing.T, packets <-chan []byte, timeout time.Duration, predicate func([]byte) bool) []byte {
	t.Helper()
	deadline := time.NewTimer(timeout)
	defer deadline.Stop()
	for {
		select {
		case packet := <-packets:
			if predicate(packet) {
				return packet
			}
		case <-deadline.C:
			t.Fatal("timed out waiting for provider datagram")
		}
	}
}

func TestResolveUDPKeepaliveInterval(t *testing.T) {
	tests := []struct {
		name   string
		config Config
		want   time.Duration
	}{
		{name: "udp invalid interval uses safe value", config: Config{Transport: "udp", UDPKeepaliveIntervalSecs: 1}, want: 5 * time.Second},
		{name: "tcp plus udp is fixed", config: Config{Transport: "tcp+udp", UDPKeepaliveIntervalSecs: 30}, want: 5 * time.Second},
		{name: "tcp disables udp ticker", config: Config{Transport: "tcp", UDPKeepaliveIntervalSecs: 5}, want: 0},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := resolveUDPKeepaliveInterval(test.config); got != test.want {
				t.Fatalf("resolveUDPKeepaliveInterval(%+v) = %s, want %s", test.config, got, test.want)
			}
		})
	}
}

func TestPersistentWarmWorkerRearmsAfterKeepaliveFailure(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	sessions := make(chan udpWarmSession, 1)
	var arms atomic.Int32
	var keepalives atomic.Int32
	factory := func() (udpWarmSession, error) {
		index := arms.Add(1)
		return udpWarmSession{
			session:      &DatagramSession{},
			selectionRef: fmt.Sprintf("warm-%d", index),
			started:      time.Now(),
		}, nil
	}
	keepalive := func(*DatagramSession) error {
		if keepalives.Add(1) == 1 {
			return errors.New("warm session expired")
		}
		return nil
	}
	go runPersistentWarmWorker(ctx, sessions, factory, keepalive, time.Millisecond, time.Millisecond)

	first := <-sessions
	if first.session == nil {
		t.Fatal("first warm session is missing")
	}
	deadline := time.Now().Add(time.Second)
	for arms.Load() < 2 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if arms.Load() < 2 {
		t.Fatalf("warm worker did not rearm after keepalive failure: arms=%d", arms.Load())
	}
	second := <-sessions
	if second.selectionRef == first.selectionRef {
		t.Fatalf("warm worker reused failed session: first=%q second=%q", first.selectionRef, second.selectionRef)
	}
}

func TestPersistentWarmWorkerRearmsAfterWarmSessionClaim(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	sessions := make(chan udpWarmSession, 1)
	var arms atomic.Int32
	factory := func() (udpWarmSession, error) {
		arms.Add(1)
		return udpWarmSession{session: &DatagramSession{}, started: time.Now()}, nil
	}
	go runPersistentWarmWorker(ctx, sessions, factory, func(*DatagramSession) error { return nil }, time.Millisecond, time.Millisecond)
	warm := <-sessions
	if warm.claimed == nil {
		t.Fatal("warm session has no claim signal")
	}
	close(warm.claimed)
	deadline := time.Now().Add(time.Second)
	for arms.Load() < 2 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if got := arms.Load(); got < 2 {
		t.Fatalf("warm worker did not rearm after claim: arms=%d", got)
	}
	select {
	case replacement := <-sessions:
		if replacement.session == nil {
			t.Fatal("replacement warm session is missing")
		}
	case <-time.After(time.Second):
		t.Fatal("replacement warm session was not published")
	}
}

func TestLeaseSelectionLogIsNeutral(t *testing.T) {
	var output bytes.Buffer
	previousWriter := log.Writer()
	previousFlags := log.Flags()
	log.SetOutput(&output)
	log.SetFlags(0)
	defer func() {
		log.SetOutput(previousWriter)
		log.SetFlags(previousFlags)
	}()

	logLeaseSelectionStart(42, 2)
	message := output.String()
	if strings.Contains(message, "Establishing Connection with the Server") {
		t.Fatalf("selection log exposed connection phrase: %q", message)
	}
	if !strings.Contains(message, "ultra lease selection started") {
		t.Fatalf("selection log lost neutral diagnostic text: %q", message)
	}
}

func TestServeUDPPrewarmsAssociationBeforeFirstClientPacket(t *testing.T) {
	provider := newTestUDPProvider(t)
	runtimeUDP, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer runtimeUDP.Close()
	listenPort := runtimeUDP.LocalAddr().(*net.UDPAddr).Port
	config := Config{
		ForwardID: 42, ListenHost: "127.0.0.1", ListenPort: listenPort,
		Target: "127.0.0.1:53", Transport: "udp", UDPPrewarm: true,
		UDPKeepaliveIntervalSecs: 5, UDPQueuePackets: 2, ConnectTimeoutSecs: 1,
	}
	store := testUDPStore(t, config, provider)
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() { serveErr <- serveUDPOnConn(ctx, config, store, status, runtimeUDP, "127.0.0.1", 53) }()

	select {
	case <-provider.firstAccepted:
	case <-time.After(2 * time.Second):
		t.Fatal("prewarm did not accept provider control connection before first client packet")
	}

	client, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	destination := &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: listenPort}
	want := []byte("first-client-payload")
	if _, err := client.WriteToUDP(want, destination); err != nil {
		t.Fatal(err)
	}
	if err := client.SetReadDeadline(time.Now().Add(2 * time.Second)); err != nil {
		t.Fatal(err)
	}
	response := make([]byte, 128)
	count, _, err := client.ReadFromUDP(response)
	if err != nil {
		t.Fatalf("prewarmed first client did not receive echo: %v", err)
	}
	if !bytes.Equal(response[:count], want) {
		t.Fatalf("prewarmed first client response = %q, want %q", response[:count], want)
	}

	cancel()
	select {
	case err := <-serveErr:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("UDP runtime did not stop")
	}
	status.Close()
	if status.status.UDPQueueDrops != 0 {
		t.Fatalf("UDP queue drops = %d, want 0 for prewarmed first association", status.status.UDPQueueDrops)
	}
}

func TestServeUDPUntanglesLocalShadowsocksEnvelope(t *testing.T) {
	provider := newTestUDPProvider(t)
	runtimeUDP, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer runtimeUDP.Close()
	listenPort := runtimeUDP.LocalAddr().(*net.UDPAddr).Port
	config := Config{
		ForwardID: 42, ListenHost: "127.0.0.1", ListenPort: listenPort,
		Target: "127.0.0.1:53", Transport: "udp", UDPPrewarm: true,
		UDPKeepaliveIntervalSecs: 5, UDPQueuePackets: 2, ConnectTimeoutSecs: 1,
	}
	store := testUDPStore(t, config, provider)
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() { serveErr <- serveUDPOnConn(ctx, config, store, status, runtimeUDP, "127.0.0.1", 53) }()
	select {
	case <-provider.firstAccepted:
	case <-time.After(2 * time.Second):
		t.Fatal("prewarm did not establish provider association")
	}

	client, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	destination := &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: listenPort}
	payload := []byte("dns-payload")
	request := append([]byte{0, 0, 0, 1, 1, 1, 1, 1, 0, 53}, payload...)
	if _, err := client.WriteToUDP(request, destination); err != nil {
		t.Fatal(err)
	}
	if err := client.SetReadDeadline(time.Now().Add(2 * time.Second)); err != nil {
		t.Fatal(err)
	}
	response := make([]byte, 128)
	count, _, err := client.ReadFromUDP(response)
	if err != nil {
		t.Fatalf("SOCKS UDP response missing: %v", err)
	}
	wantHeader := []byte{0, 0, 0, 1, 1, 1, 1, 1, 0, 53}
	if count != len(wantHeader)+len(payload) || !bytes.Equal(response[:len(wantHeader)], wantHeader) || !bytes.Equal(response[len(wantHeader):count], payload) {
		t.Fatalf("SOCKS UDP response = %x, want header %x + payload %q", response[:count], wantHeader, payload)
	}
	cancel()
	select {
	case err := <-serveErr:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("UDP runtime did not stop")
	}
	status.Close()
}

func TestServeUDPProtocolKeepaliveUsesReservedEnvelope(t *testing.T) {
	provider := newTestUDPProvider(t)
	runtimeUDP, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer runtimeUDP.Close()
	listenPort := runtimeUDP.LocalAddr().(*net.UDPAddr).Port
	config := Config{
		ForwardID: 42, ListenHost: "127.0.0.1", ListenPort: listenPort,
		Target: "127.0.0.1:53", Transport: "udp", UDPPrewarm: true,
		UDPKeepaliveIntervalSecs: 5, ConnectTimeoutSecs: 1,
	}
	store := testUDPStore(t, config, provider)
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() { serveErr <- serveUDPOnConn(ctx, config, store, status, runtimeUDP, "127.0.0.1", 53) }()

	select {
	case <-provider.firstAccepted:
	case <-time.After(2 * time.Second):
		t.Fatal("UDP setup did not accept provider control connection")
	}
	waitForProviderPacket(t, provider.packets, 2*time.Second, isReservedKeepPacket)

	client, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	destination := &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: listenPort}
	userPayload := []byte("user-before-keepalive")
	if _, err := client.WriteToUDP(userPayload, destination); err != nil {
		t.Fatal(err)
	}
	if err := client.SetReadDeadline(time.Now().Add(2 * time.Second)); err != nil {
		t.Fatal(err)
	}
	response := make([]byte, 128)
	count, _, err := client.ReadFromUDP(response)
	if err != nil {
		t.Fatalf("user payload did not receive echo: %v", err)
	}
	if !bytes.Equal(response[:count], userPayload) {
		t.Fatalf("user payload response = %q, want %q", response[:count], userPayload)
	}

	waitForProviderPacket(t, provider.packets, 7*time.Second, isReservedKeepPacket)
	if err := client.SetReadDeadline(time.Now().Add(250 * time.Millisecond)); err != nil {
		t.Fatal(err)
	}
	if count, _, err := client.ReadFromUDP(response); err == nil {
		t.Fatalf("keepalive response leaked to local client: %q", response[:count])
	}

	cancel()
	select {
	case err := <-serveErr:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("UDP runtime did not stop")
	}
	status.Close()
}

func TestServeUDPDoesNotBlockNewClientWhileAnotherAssociationArms(t *testing.T) {
	providerTCP, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer providerTCP.Close()
	providerUDP, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer providerUDP.Close()

	firstAccepted := make(chan struct{})
	releaseFirst := make(chan struct{})
	var releaseOnce sync.Once
	defer releaseOnce.Do(func() { close(releaseFirst) })
	var accepted atomic.Int32
	go func() {
		for {
			connection, acceptErr := providerTCP.AcceptTCP()
			if acceptErr != nil {
				return
			}
			sequence := accepted.Add(1)
			go func() {
				defer connection.Close()
				_ = connection.SetReadDeadline(time.Now().Add(time.Second))
				handshake := make([]byte, 512)
				if _, readErr := connection.Read(handshake); readErr != nil {
					return
				}
				if sequence == 1 {
					close(firstAccepted)
					<-releaseFirst
				}
				encodedPort := uint16(providerUDP.LocalAddr().(*net.UDPAddr).Port) ^ 0x0101
				reply := []byte{4, 1, 1, 0, 1, 7, 0x10, 0x20, 0, 0}
				binary.BigEndian.PutUint16(reply[8:], encodedPort)
				_, _ = connection.Write(reply)
			}()
		}
	}()
	go func() {
		buffer := make([]byte, 64*1024)
		for {
			count, client, readErr := providerUDP.ReadFromUDP(buffer)
			if readErr != nil {
				return
			}
			if count < 16 {
				continue
			}
			if bytes.Equal(buffer[10:14], net.IPv4(2, 0, 0, 0).To4()) && binary.BigEndian.Uint16(buffer[14:16]) == 0 {
				response := append([]byte{0, 0, 0, 1, 2, 0, 0, 0, 0, 0}, []byte(keepPayloadHex)...)
				_, _ = providerUDP.WriteToUDP(response, client)
				continue
			}
			payload := append([]byte(nil), buffer[16:count]...)
			response := append([]byte{0, 0, 0, 1, 127, 0, 0, 1, 0, 53}, payload...)
			_, _ = providerUDP.WriteToUDP(response, client)
		}
	}()

	runtimeUDP, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	listenPort := runtimeUDP.LocalAddr().(*net.UDPAddr).Port
	config := Config{
		ForwardID:          42,
		ListenHost:         "127.0.0.1",
		ListenPort:         listenPort,
		Target:             "127.0.0.1:53",
		Transport:          "udp",
		UDPPrewarm:         false,
		UDPQueuePackets:    2,
		ConnectTimeoutSecs: 1,
	}
	store := NewPolicyStore(config)
	now := time.Now()
	policy := Policy{
		Protocol: policyProtocol, ForwardID: 42, PolicyGeneration: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		SessionTicket: "ticket", ExpiresAt: now.Add(time.Minute), Transport: "udp",
		RateLimitMbps: 100, QuotaRemainingBytes: 1 << 20, TargetDigest: targetDigest(config.Target),
	}
	lease := ProviderLease{
		LeaseID: "ul2_test", PolicyGeneration: policy.PolicyGeneration, ExpiresAt: policy.ExpiresAt,
		Transport: "udp", SelectionRef: "selection-reference-abcdefghijklmnop",
		NonceHash: "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
		IP:        "127.0.0.1", Port: providerTCP.Addr().(*net.TCPAddr).Port,
		User: []byte("user"), Password: []byte("1234567890123456"),
	}
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Millisecond)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	serveErr := make(chan error, 1)
	go func() { serveErr <- serveUDPOnConn(ctx, config, store, status, runtimeUDP, "127.0.0.1", 53) }()

	destination := &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: listenPort}
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

	if _, err := clientA.WriteToUDP([]byte("client-a"), destination); err != nil {
		t.Fatal(err)
	}
	select {
	case <-firstAccepted:
	case <-time.After(time.Second):
		t.Fatal("first UDP association did not start")
	}
	pendingA := [][]byte{[]byte("client-a-2"), []byte("client-a-3")}
	for _, payload := range pendingA {
		if _, err := clientA.WriteToUDP(payload, destination); err != nil {
			t.Fatal(err)
		}
	}
	want := []byte("client-b")
	if _, err := clientB.WriteToUDP(want, destination); err != nil {
		t.Fatal(err)
	}
	_ = clientB.SetReadDeadline(time.Now().Add(750 * time.Millisecond))
	response := make([]byte, 128)
	count, _, err := clientB.ReadFromUDP(response)
	if err != nil {
		t.Fatalf("second UDP client was blocked by the first association: %v", err)
	}
	if !bytes.Equal(response[:count], want) {
		t.Fatalf("second UDP client response = %q, want %q", response[:count], want)
	}
	releaseOnce.Do(func() { close(releaseFirst) })
	_ = clientA.SetReadDeadline(time.Now().Add(time.Second))
	for _, wantA := range [][]byte{[]byte("client-a"), pendingA[0]} {
		count, _, err := clientA.ReadFromUDP(response)
		if err != nil {
			t.Fatalf("pending UDP packet %q was not delivered: %v", wantA, err)
		}
		if !bytes.Equal(response[:count], wantA) {
			t.Fatalf("pending UDP response = %q, want %q", response[:count], wantA)
		}
	}
	_ = clientA.SetReadDeadline(time.Now().Add(100 * time.Millisecond))
	if count, _, err := clientA.ReadFromUDP(response); err == nil {
		t.Fatalf("queue overflow packet unexpectedly returned: %q", response[:count])
	}

	const burstClients = 128
	burst := make([]*net.UDPConn, 0, burstClients)
	for index := 0; index < burstClients; index++ {
		client, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
		if err != nil {
			t.Fatal(err)
		}
		burst = append(burst, client)
		payload := []byte(fmt.Sprintf("burst-%03d", index))
		if _, err := client.WriteToUDP(payload, destination); err != nil {
			t.Fatal(err)
		}
	}
	burstDeadline := time.Now().Add(3 * time.Second)
	for index, client := range burst {
		_ = client.SetReadDeadline(burstDeadline)
		count, _, err := client.ReadFromUDP(response)
		if err != nil {
			t.Fatalf("burst client %d did not receive a response: %v", index, err)
		}
		wantBurst := []byte(fmt.Sprintf("burst-%03d", index))
		if !bytes.Equal(response[:count], wantBurst) {
			t.Fatalf("burst client %d response = %q, want %q", index, response[:count], wantBurst)
		}
		_ = client.Close()
	}
	cancel()
	select {
	case err := <-serveErr:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("UDP runtime did not stop")
	}
	status.Close()
	if status.status.UDPQueueDrops != 1 {
		t.Fatalf("UDP queue drops = %d, want 1", status.status.UDPQueueDrops)
	}
	if status.status.UDPActiveAssociations != 0 || status.status.UDPPendingAssociations != 0 {
		t.Fatalf("UDP associations after stop = active:%d pending:%d",
			status.status.UDPActiveAssociations, status.status.UDPPendingAssociations)
	}
}

func TestResolveUDPLimits(t *testing.T) {
	tests := []struct {
		name         string
		config       Config
		queue        int
		associations int
		workers      int
	}{
		{name: "defaults", queue: 64, associations: 1024, workers: 64},
		{
			name: "configured",
			config: Config{
				UDPQueuePackets:    8,
				UDPMaxAssociations: 256,
				UDPSetupWorkers:    32,
			},
			queue: 8, associations: 256, workers: 32,
		},
		{
			name: "workers capped by associations",
			config: Config{
				UDPMaxAssociations: 16,
				UDPSetupWorkers:    64,
			},
			queue: 64, associations: 16, workers: 16,
		},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			limits := resolveUDPLimits(test.config)
			if limits.queuePackets != test.queue || limits.maxAssociations != test.associations || limits.setupWorkers != test.workers {
				t.Fatalf("limits = %+v, want queue=%d associations=%d workers=%d", limits, test.queue, test.associations, test.workers)
			}
		})
	}
}

func TestRuntimePolicyRequiresValidLeaseBeforeDataPlane(t *testing.T) {
	config := Config{ForwardID: 42, Target: "1.1.1.1:443"}
	store := NewPolicyStore(config)
	policy := Policy{
		Protocol: policyProtocol, ForwardID: 42, PolicyGeneration: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		SessionTicket: "ticket", ExpiresAt: time.Now().Add(time.Minute), Transport: "tcp",
		RateLimitMbps: 10, QuotaRemainingBytes: 1024, TargetDigest: targetDigest(config.Target),
	}
	lease := ProviderLease{
		LeaseID: "ul2_test", PolicyGeneration: policy.PolicyGeneration, ExpiresAt: policy.ExpiresAt,
		Transport: "tcp", SelectionRef: "selection-reference-abcdefghijklmnop", NonceHash: "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
		IP: "203.0.113.20", Port: 5438, User: []byte("user"), Password: []byte("password"),
	}
	if _, ok := store.Snapshot(); ok {
		t.Fatal("runtime must not have a data-plane snapshot before lease")
	}
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	snapshot, ok := store.Snapshot()
	if !ok {
		t.Fatal("valid policy and lease were not accepted")
	}
	snapshot.Lease.wipe()
	store.Revoke(policy.PolicyGeneration)
	if _, ok := store.Snapshot(); ok {
		t.Fatal("revoked lease remained usable")
	}
}

func TestRuntimePolicyGenerationRotationKeepsListenersAlive(t *testing.T) {
	config := Config{ForwardID: 42, Target: "1.1.1.1:443"}
	store := NewPolicyStore(config)
	now := time.Now()
	first := Policy{
		Protocol: policyProtocol, ForwardID: 42, PolicyGeneration: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		SessionTicket: "ticket-a", ExpiresAt: now.Add(time.Minute), Transport: "tcp",
		RateLimitMbps: 10, QuotaRemainingBytes: 1024, TargetDigest: targetDigest(config.Target),
	}
	if err := store.Replace(first, ProviderLease{
		LeaseID: "ul2_first", PolicyGeneration: first.PolicyGeneration, ExpiresAt: first.ExpiresAt,
		Transport: "tcp", SelectionRef: "selection-reference-abcdefghijklmnop",
		NonceHash: "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
		IP:        "203.0.113.20", Port: 5438, User: []byte("user"), Password: []byte("password"),
	}); err != nil {
		t.Fatal(err)
	}

	runtimeContext, cancelRuntime := context.WithCancel(context.Background())
	defer cancelRuntime()
	go watchRuntimePolicy(runtimeContext, cancelRuntime, store)
	next := first
	next.PolicyGeneration = "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
	next.SessionTicket = "ticket-b"
	if err := store.Replace(next, ProviderLease{
		LeaseID: "ul2_next", PolicyGeneration: next.PolicyGeneration, ExpiresAt: next.ExpiresAt,
		Transport: "tcp", SelectionRef: "selection-reference-abcdefghijklmnop",
		NonceHash: "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd",
		IP:        "203.0.113.20", Port: 5438, User: []byte("user"), Password: []byte("password"),
	}); err != nil {
		t.Fatal(err)
	}

	select {
	case <-runtimeContext.Done():
		t.Fatal("policy generation rotation canceled active listeners")
	case <-time.After(100 * time.Millisecond):
	}
	store.Revoke(next.PolicyGeneration)
	select {
	case <-runtimeContext.Done():
	case <-time.After(time.Second):
		t.Fatal("policy revocation did not cancel active listeners")
	}
}
