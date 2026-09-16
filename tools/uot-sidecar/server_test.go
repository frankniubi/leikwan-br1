package main

// Integration coverage for the relay itself: HELLO negotiation, echo relay in
// both directions, reconnect grace with socket reuse, replay suppression, and
// the rejection matrix. The equivalent Python implementation is asserted to
// produce identical bytes by tests/test_external_uot_sidecar_go.py.

import (
	"bufio"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"net"
	"strings"
	"sync"
	"testing"
	"time"
)

type echoServer struct {
	conn     *net.UDPConn
	mu       sync.Mutex
	peers    []*net.UDPAddr
	received [][]byte
}

func startEchoServer(t *testing.T) *echoServer {
	t.Helper()
	conn, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatalf("echo listen failed: %v", err)
	}
	server := &echoServer{conn: conn}
	go server.run()
	t.Cleanup(func() { _ = conn.Close() })
	return server
}

func (e *echoServer) address() string {
	return e.conn.LocalAddr().String()
}

func (e *echoServer) run() {
	buffer := make([]byte, maxDatagram)
	for {
		count, addr, err := e.conn.ReadFromUDP(buffer)
		if err != nil {
			return
		}
		e.mu.Lock()
		e.peers = append(e.peers, addr)
		e.received = append(e.received, append([]byte(nil), buffer[:count]...))
		e.mu.Unlock()
		payload := buffer[:count]
		switch {
		case count >= 3 && payload[0] == 'S':
			// "S" + uint16be length: reply with that many bytes to test the
			// target-to-carrier direction with controlled sizes.
			_, _ = e.conn.WriteToUDP(make([]byte, int(binary.BigEndian.Uint16(payload[1:3]))), addr)
		case count >= 1 && payload[0] == 'E':
			_, _ = e.conn.WriteToUDP(append([]byte(nil), payload[1:]...), addr)
		default:
			_, _ = e.conn.WriteToUDP(append([]byte(nil), payload...), addr)
		}
	}
}

func (e *echoServer) peerPorts() []string {
	e.mu.Lock()
	defer e.mu.Unlock()
	ports := make([]string, 0, len(e.peers))
	for _, addr := range e.peers {
		ports = append(ports, fmt.Sprintf("%d", addr.Port))
	}
	return ports
}

func (e *echoServer) lastPeerPort() string {
	ports := e.peerPorts()
	if len(ports) == 0 {
		return ""
	}
	return ports[len(ports)-1]
}

func (e *echoServer) receivedCount() int {
	e.mu.Lock()
	defer e.mu.Unlock()
	return len(e.received)
}

type testCarrier struct {
	conn    net.Conn
	reader  *bufio.Reader
	session [sessionIDBytes]byte
}

func dialCarrier(t *testing.T, address string, session [sessionIDBytes]byte) *testCarrier {
	t.Helper()
	conn, err := net.DialTimeout("tcp", address, 3*time.Second)
	if err != nil {
		t.Fatalf("carrier dial failed: %v", err)
	}
	carrier := &testCarrier{conn: conn, reader: bufio.NewReader(conn), session: session}
	t.Cleanup(func() { _ = conn.Close() })
	return carrier
}

func (c *testCarrier) send(t *testing.T, msgType byte, seq uint64, payload []byte) {
	t.Helper()
	_ = c.conn.SetWriteDeadline(time.Now().Add(3 * time.Second))
	if err := writeFrame(c.conn, frame{msgType: msgType, session: c.session, seq: seq, payload: payload}, defaultMaxFrameBytes); err != nil {
		t.Fatalf("carrier write failed: %v", err)
	}
}

func (c *testCarrier) read(t *testing.T, timeout time.Duration) (frame, error) {
	t.Helper()
	_ = c.conn.SetReadDeadline(time.Now().Add(timeout))
	return readFrame(c.reader, defaultMaxFrameBytes)
}

func (c *testCarrier) mustRead(t *testing.T, timeout time.Duration) frame {
	t.Helper()
	incoming, err := c.read(t, timeout)
	if err != nil {
		t.Fatalf("carrier read failed: %v", err)
	}
	return incoming
}

func fixtureServer(t *testing.T, echo *echoServer, mutate func(*sidecarConfig)) (*sidecarServer, string) {
	t.Helper()
	binding := forwardBinding{
		forwardID: 170,
		secret:    []byte("fixture-secret"),
		targets:   map[string]struct{}{echo.address(): {}},
	}
	config := sidecarConfig{
		listenHost:               "127.0.0.1",
		forwards:                 map[int64]forwardBinding{170: binding},
		maxFrameBytes:            defaultMaxFrameBytes,
		framePayloadMax:          maxDatagram,
		queuePackets:             16,
		keepaliveSeconds:         0.05,
		idleTimeoutSeconds:       2,
		sessionGraceSeconds:      0.35,
		sessionLimit:             4,
		udpConnectTimeoutSeconds: 2,
	}
	if mutate != nil {
		mutate(&config)
	}
	server := newSidecarServer(config, newSidecarLogger("ERROR", discardWriter{}))
	if err := server.start(); err != nil {
		t.Fatalf("server start failed: %v", err)
	}
	t.Cleanup(server.close)
	return server, server.address()
}

func helloPayloadFor(t *testing.T, secret string, forwardID int64, target string, framePayloadMax int) []byte {
	t.Helper()
	token, err := makeHMACToken([]byte(secret), forwardID, target)
	if err != nil {
		t.Fatalf("makeHMACToken failed: %v", err)
	}
	payload, marshalErr := json.Marshal(map[string]any{
		"token":             token,
		"forward_id":        forwardID,
		"target":            target,
		"frame_payload_max": framePayloadMax,
		"last_sequence":     0,
	})
	if marshalErr != nil {
		t.Fatalf("hello marshal failed: %v", marshalErr)
	}
	return payload
}

func randomSession(t *testing.T) [sessionIDBytes]byte {
	t.Helper()
	session := [sessionIDBytes]byte{}
	for index := range session {
		session[index] = byte(index*7 + 3)
	}
	return session
}

func decodeAck(t *testing.T, payload []byte) map[string]any {
	t.Helper()
	decoded := map[string]any{}
	if err := json.Unmarshal(payload, &decoded); err != nil {
		t.Fatalf("ack decode failed: %v (%s)", err, payload)
	}
	return decoded
}

func TestHelloEchoAndAckBytes(t *testing.T) {
	echo := startEchoServer(t)
	_, address := fixtureServer(t, echo, nil)
	session := randomSession(t)
	carrier := dialCarrier(t, address, session)
	carrier.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	ack := carrier.mustRead(t, 3*time.Second)
	if ack.msgType != msgHelloAck || ack.session != session {
		t.Fatalf("unexpected ack frame: %+v", ack)
	}
	if string(ack.payload) != `{"frame_payload_max":1200,"ok":true,"queue_packets":16,"session":"OKN"}` {
		t.Fatalf("ack payload = %s", ack.payload)
	}
	carrier.send(t, msgData, 1, []byte("ping-1"))
	carrier.send(t, msgData, 2, []byte("ping-2"))
	first := carrier.mustRead(t, 3*time.Second)
	second := carrier.mustRead(t, 3*time.Second)
	if first.msgType != msgUDPData || string(first.payload) != "ping-1" || first.seq >= second.seq {
		t.Fatalf("unexpected first datagram: %+v", first)
	}
	if string(second.payload) != "ping-2" {
		t.Fatalf("unexpected second datagram: %+v", second)
	}
	carrier.send(t, msgClose, 3, nil)
	if _, err := carrier.read(t, 300*time.Millisecond); err == nil {
		t.Fatal("carrier stayed open after CLOSE")
	}
}

func TestRejectionMatrix(t *testing.T) {
	echo := startEchoServer(t)
	_, address := fixtureServer(t, echo, nil)
	cases := []struct {
		name    string
		payload string
		want    string
	}{
		{"bogus token", `{"token":"hmac-sha256:00","target":"` + echo.address() + `","forward_id":170}`, "authentication or target binding failed"},
		{"unknown forward", `{"token":"x","target":"` + echo.address() + `","forward_id":999}`, "forward is not allowed"},
		{"foreign target", `{"token":"x","target":"198.51.100.7:9000","forward_id":170}`, "authentication or target binding failed"},
		{"malformed target", `{"token":"x","target":"nope","forward_id":170}`, "target must be host:port"},
		{"missing token", `{"target":"` + echo.address() + `","forward_id":170}`, "HELLO token and target are required"},
	}
	for index, testCase := range cases {
		session := randomSession(t)
		session[0] = byte(index)
		carrier := dialCarrier(t, address, session)
		carrier.send(t, msgHello, 0, []byte(testCase.payload))
		ack := carrier.mustRead(t, 3*time.Second)
		decoded := decodeAck(t, ack.payload)
		if decoded["ok"] != false || decoded["error"] != testCase.want {
			t.Fatalf("%s: ack = %s", testCase.name, ack.payload)
		}
		if decoded["frame_payload_max"] != float64(0) {
			t.Fatalf("%s: rejection advertised a payload limit: %s", testCase.name, ack.payload)
		}
	}
}

func TestFrameLevelRejections(t *testing.T) {
	echo := startEchoServer(t)
	_, address := fixtureServer(t, echo, nil)
	// A non-HELLO first frame is rejected before any session exists.
	first := dialCarrier(t, address, randomSession(t))
	first.send(t, msgData, 1, []byte("nope"))
	if decoded := decodeAck(t, first.mustRead(t, 3*time.Second).payload); decoded["error"] != "HELLO required" {
		t.Fatalf("first-frame ack = %v", decoded)
	}
	// After a healthy HELLO, the carrier direction may only carry DATA,
	// KEEPALIVE or CLOSE: a mirrored UDP_DATA frame is a protocol violation.
	carrier := dialCarrier(t, address, randomSession(t))
	carrier.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	_ = carrier.mustRead(t, 3*time.Second)
	carrier.send(t, msgUDPData, 1, []byte("nope"))
	ack := carrier.mustRead(t, 3*time.Second)
	if decoded := decodeAck(t, ack.payload); decoded["error"] != "unexpected frame type" {
		t.Fatalf("ack = %s", ack.payload)
	}
}

func TestReattachReusesSocketAndSuppressesReplay(t *testing.T) {
	echo := startEchoServer(t)
	_, address := fixtureServer(t, echo, nil)
	session := randomSession(t)
	first := dialCarrier(t, address, session)
	first.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	if decoded := decodeAck(t, first.mustRead(t, 3*time.Second).payload); decoded["session"] != "OKN" {
		t.Fatalf("first ack = %v", decoded)
	}
	first.send(t, msgData, 1, []byte("Eone"))
	if payload := first.mustRead(t, 3*time.Second).payload; string(payload) != "one" {
		t.Fatalf("echo payload = %q", payload)
	}
	portBefore := echo.lastPeerPort()
	second := dialCarrier(t, address, session)
	second.send(t, msgHello, 1, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	if decoded := decodeAck(t, second.mustRead(t, 3*time.Second).payload); decoded["session"] != "OKR" {
		t.Fatalf("reattach ack = %v", decoded)
	}
	second.send(t, msgData, 1, []byte("Ereplay"))
	second.send(t, msgData, 2, []byte("Efresh"))
	payload := second.mustRead(t, 3*time.Second).payload
	if string(payload) != "fresh" {
		t.Fatalf("expected the replay to be suppressed, got %q", payload)
	}
	if portAfter := echo.lastPeerPort(); portAfter != portBefore {
		t.Fatalf("UDP source port changed across reattach: %s -> %s", portBefore, portAfter)
	}
}

func TestSessionGraceExpiryClosesSession(t *testing.T) {
	echo := startEchoServer(t)
	server, address := fixtureServer(t, echo, nil)
	session := randomSession(t)
	carrier := dialCarrier(t, address, session)
	carrier.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	_ = carrier.mustRead(t, 3*time.Second)
	_ = carrier.conn.Close()
	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		server.registry.mu.Lock()
		remaining := len(server.registry.sessions)
		server.registry.mu.Unlock()
		if remaining == 0 {
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatal("session was not reaped after the grace period")
}

func TestOversizeDatagramIsDropped(t *testing.T) {
	echo := startEchoServer(t)
	server, address := fixtureServer(t, echo, func(config *sidecarConfig) { config.framePayloadMax = 300 })
	session := randomSession(t)
	carrier := dialCarrier(t, address, session)
	carrier.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 300))
	ack := decodeAck(t, carrier.mustRead(t, 3*time.Second).payload)
	if ack["frame_payload_max"] != float64(300) {
		t.Fatalf("negotiated limit = %v", ack["frame_payload_max"])
	}
	// "S" + uint16be 2000 asks the echo for a 2000-byte reply, which exceeds
	// the negotiated 300-byte limit and must be dropped in the target->carrier
	// direction instead of being framed.
	carrier.send(t, msgData, 1, []byte{'S', 0x07, 0xd0})
	// Keepalives keep flowing, so assert on datagrams specifically.
	deadline := time.Now().Add(500 * time.Millisecond)
	for time.Now().Before(deadline) {
		incoming, err := carrier.read(t, time.Until(deadline))
		if err != nil {
			break
		}
		if incoming.msgType == msgUDPData {
			t.Fatalf("oversize datagram was forwarded to the carrier: %d bytes", len(incoming.payload))
		}
	}
	server.registry.mu.Lock()
	active := server.registry.sessions[session]
	server.registry.mu.Unlock()
	if active == nil {
		t.Fatal("session disappeared")
	}
	if stats := active.snapshotStats(); stats.oversizeDrop == 0 {
		t.Fatalf("oversize drop was not counted: %+v", stats)
	}
}

func TestKeepaliveIsEmittedWhileIdle(t *testing.T) {
	echo := startEchoServer(t)
	_, address := fixtureServer(t, echo, nil)
	carrier := dialCarrier(t, address, randomSession(t))
	carrier.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	_ = carrier.mustRead(t, 3*time.Second)
	keepalive := carrier.mustRead(t, 3*time.Second)
	if keepalive.msgType != msgKeepalive || keepalive.seq == 0 {
		t.Fatalf("unexpected keepalive frame: %+v", keepalive)
	}
	if strings.TrimSpace(string(keepalive.payload)) != "" {
		t.Fatalf("keepalive carried a payload: %q", keepalive.payload)
	}
}

func TestSessionLimitRejectsNewSession(t *testing.T) {
	echo := startEchoServer(t)
	_, address := fixtureServer(t, echo, func(config *sidecarConfig) { config.sessionLimit = 1 })
	first := dialCarrier(t, address, randomSession(t))
	first.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	_ = first.mustRead(t, 3*time.Second)
	second := dialCarrier(t, address, [sessionIDBytes]byte{9, 9, 9})
	second.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	ack := decodeAck(t, second.mustRead(t, 3*time.Second).payload)
	if ack["error"] != "session limit reached" {
		t.Fatalf("ack = %v", ack)
	}
}
