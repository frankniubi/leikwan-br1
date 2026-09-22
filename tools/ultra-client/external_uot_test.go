package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"testing"
	"time"
)

type probeXORReader struct{ io.Reader }

func (r probeXORReader) Read(p []byte) (int, error) {
	n, err := r.Reader.Read(p)
	for i := 0; i < n; i++ {
		p[i] ^= 1
	}
	return n, err
}

func TestExternalUoTVerificationClosesDisposableSession(t *testing.T) {
	listener, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	config := Config{ForwardID: 42, Target: "127.0.0.1:9999", ProbeTimeoutMillis: 1000}
	config.ExternalUoT = ExternalUoTConfig{
		Enabled: true, SidecarTarget: "127.0.0.1:9000",
		Token: "fixture-token", FramePayloadMax: externalUoTMaxDatagramBytes,
	}
	lease := ProviderLease{
		IP: "127.0.0.1", Port: listener.Addr().(*net.TCPAddr).Port,
		User: []byte("test-user"), Password: []byte("test-credential"),
	}
	handshake, err := buildHandshake(string(lease.User), string(lease.Password), 1, "127.0.0.1", 9000)
	if err != nil {
		t.Fatal(err)
	}
	done := make(chan error, 1)
	go func() {
		conn, e := listener.Accept()
		if e != nil {
			done <- e
			return
		}
		defer conn.Close()
		_ = conn.SetDeadline(time.Now().Add(3 * time.Second))
		if _, e = io.ReadFull(conn, make([]byte, len(handshake))); e != nil {
			done <- e
			return
		}
		if _, e = conn.Write([]byte{5, 2, 1, 0, 4, 1, 1, 0, 1, 1, 0x12, 0x34, 0, 0}); e != nil {
			done <- e
			return
		}
		reader := bufio.NewReader(probeXORReader{conn})
		hello, e := readExternalUoTFrame(reader)
		if e != nil {
			done <- e
			return
		}
		ack, _ := json.Marshal(externalUoTHelloAck{OK: true, Session: "OKN", FramePayloadMax: externalUoTMaxDatagramBytes})
		if e = writeExternalUoTFrame(conn, externalUoTFrame{
			msgType: externalUoTMsgHelloAck, session: hello.session, payload: ack,
		}); e != nil {
			done <- e
			return
		}
		closed, e := readExternalUoTFrame(reader)
		if e == nil && (closed.msgType != externalUoTClose || closed.session != hello.session) {
			e = fmt.Errorf("probe did not close its own session")
		}
		done <- e
	}()
	result := probeExternalUoTLease(context.Background(), lease, config, Policy{})
	if result.Err != nil || result.TCPReceived != 1 {
		t.Fatalf("probe failed: %+v", result)
	}
	if err := <-done; err != nil {
		t.Fatalf("probe leaked its disposable session: %v", err)
	}
}

func TestExternalUoTFrameRoundTripPreservesLargeDatagram(t *testing.T) {
	var session [16]byte
	for i := range session {
		session[i] = byte(i + 1)
	}
	payload := bytes.Repeat([]byte{0xa5}, 16000)
	encoded, err := externalUoTFrameBytes(externalUoTFrame{
		msgType: externalUoTData, flags: 3, session: session, sequence: 91, payload: payload,
	})
	if err != nil {
		t.Fatal(err)
	}
	var wire bytes.Buffer
	var prefix [4]byte
	binary.BigEndian.PutUint32(prefix[:], uint32(len(encoded)))
	wire.Write(prefix[:])
	wire.Write(encoded)
	frame, err := readExternalUoTFrame(bufio.NewReader(&wire))
	if err != nil {
		t.Fatal(err)
	}
	if frame.msgType != externalUoTData || frame.flags != 3 || frame.sequence != 91 || !bytes.Equal(frame.session[:], session[:]) || !bytes.Equal(frame.payload, payload) {
		t.Fatalf("frame changed during round trip: %#v", frame)
	}
}

func TestExternalUoTCarrierWriterUsesUltraXORTransform(t *testing.T) {
	left, right := net.Pipe()
	defer left.Close()
	defer right.Close()
	frame := externalUoTFrame{msgType: externalUoTData, payload: []byte("carrier-frame")}
	writeDone := make(chan error, 1)
	go func() { writeDone <- writeExternalUoTCarrierFrame(left, frame) }()
	var encodedPrefix [4]byte
	if _, err := io.ReadFull(right, encodedPrefix[:]); err != nil {
		t.Fatal(err)
	}
	prefix := xorOne(encodedPrefix[:])
	length := binary.BigEndian.Uint32(prefix)
	encodedBody := make([]byte, length)
	if _, err := io.ReadFull(right, encodedBody); err != nil {
		t.Fatal(err)
	}
	decoded := xorOne(encodedBody)
	if _, err := readExternalUoTFrame(bufio.NewReader(bytes.NewReader(append(prefix[:], decoded...)))); err != nil {
		t.Fatalf("XOR carrier frame did not decode: %v", err)
	}
	if err := <-writeDone; err != nil {
		t.Fatal(err)
	}
}

func TestExternalUoTTunerBacksOffKeepaliveOnReconnectAndRecovers(t *testing.T) {
	tuner := newExternalUoTTuner(ExternalUoTConfig{FramePayloadMax: externalUoTMaxDatagramBytes, KeepaliveSeconds: 10})
	initial, _, _, _ := tuner.snapshot()
	tuner.observeReconnect()
	backoff, _, reconnects, _ := tuner.snapshot()
	if reconnects != 1 || backoff <= initial {
		t.Fatalf("reconnect did not back off keepalive: initial=%s current=%s reconnects=%d", initial, backoff, reconnects)
	}
	for range 32 {
		tuner.observeRTT(20 * time.Millisecond)
	}
	recovered, _, _, _ := tuner.snapshot()
	if recovered >= backoff {
		t.Fatalf("stable samples did not recover keepalive: backoff=%s recovered=%s", backoff, recovered)
	}
}

func TestExternalUoTProbeUsesSidecarTargetAndHello(t *testing.T) {
	server, client := net.Pipe()
	defer server.Close()
	defer client.Close()
	go func() {
		reader := bufio.NewReader(server)
		frame, err := readExternalUoTFrame(reader)
		if err != nil {
			return
		}
		var hello externalUoTHello
		if json.Unmarshal(frame.payload, &hello) != nil || hello.Target != "203.0.113.10:443" {
			return
		}
		ack, _ := json.Marshal(externalUoTHelloAck{OK: true, Session: "OKN"})
		_ = writeExternalUoTFrame(server, externalUoTFrame{msgType: externalUoTMsgHelloAck, payload: ack})
	}()
	_ = client.SetDeadline(time.Now().Add(time.Second))
	hello, _ := json.Marshal(externalUoTHello{Token: "t", ForwardID: 42, Target: "203.0.113.10:443", FramePayloadMax: externalUoTMaxDatagramBytes})
	if err := writeExternalUoTFrame(client, externalUoTFrame{msgType: externalUoTMsgHello, payload: hello}); err != nil {
		t.Fatal(err)
	}
	frame, err := readExternalUoTFrame(bufio.NewReader(client))
	if err != nil || frame.msgType != externalUoTMsgHelloAck {
		t.Fatalf("hello ack failed: frame=%#v err=%v", frame, err)
	}
}

func TestExternalUoTDisabledByDefault(t *testing.T) {
	if externalUoTEnabled(Config{}) {
		t.Fatal("external UoT unexpectedly enabled by zero config")
	}
	config, err := (ExternalUoTConfig{}).normalized()
	if err != nil || config.Enabled {
		t.Fatalf("zero config normalized unexpectedly: %#v %v", config, err)
	}
}
