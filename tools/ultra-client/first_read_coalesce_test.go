package main

import (
	"bufio"
	"bytes"
	"fmt"
	"io"
	"net"
	"testing"
	"time"
)

// readTestStreamHandshake consumes one cmd=1 stream handshake with an IPv4
// target: greeting, user, credential, 05 cmd checksum, address.
func readTestStreamHandshake(reader *bufio.Reader) error {
	prefix := make([]byte, 2)
	if _, err := io.ReadFull(reader, prefix); err != nil {
		return err
	}
	greeting := legacyHandshakeGreeting
	if prefix[1] == currentHandshakeGreeting[1] {
		greeting = currentHandshakeGreeting
	}
	if _, err := reader.Discard(len(greeting) - len(prefix)); err != nil {
		return err
	}
	for field := 0; field < 2; field++ {
		if _, err := readLenPrefixed(reader); err != nil {
			return err
		}
	}
	tail := make([]byte, 4)
	if _, err := io.ReadFull(reader, tail); err != nil {
		return err
	}
	if tail[3] != 1 {
		return fmt.Errorf("address type %d", tail[3])
	}
	_, err := reader.Discard(net.IPv4len + 2)
	return err
}

// startFirstResponseRelay accepts one stream, answers the handshake with ack,
// waits for the first flight and answers it with response in a single write.
func startFirstResponseRelay(t *testing.T, ack, response []byte) ProviderLease {
	t.Helper()
	listener, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = listener.Close() })
	go func() {
		conn, err := listener.AcceptTCP()
		if err != nil {
			return
		}
		defer conn.Close()
		_ = conn.SetDeadline(time.Now().Add(5 * time.Second))
		reader := bufio.NewReader(conn)
		if readTestStreamHandshake(reader) != nil {
			return
		}
		if _, err := conn.Write(ack); err != nil {
			return
		}
		flight := make([]byte, 64)
		if _, err := reader.Read(flight); err != nil {
			return
		}
		if _, err := conn.Write(response); err != nil {
			return
		}
		_, _ = io.Copy(io.Discard, reader)
	}()
	return ProviderLease{
		IP:       "127.0.0.1",
		Port:     listener.Addr().(*net.TCPAddr).Port,
		User:     []byte(gameSimUser),
		Password: []byte(gameSimCredential),
	}
}

// The legacy-final inspection reads the first downstream bytes one at a time.
// When they turn out to be application data, the first Read must still return
// everything that arrived with them: clients such as xray's SS2022 read the
// whole response header with a single Read and drop a stream that hands them a
// lone first byte (2026-11 report from client 44).
func TestBufferedConnFirstReadKeepsResponseTogether(t *testing.T) {
	response := make([]byte, 294)
	for index := range response {
		response[index] = byte(0x9c + 7*index)
	}
	cases := []struct {
		name string
		ack  []byte
		lead []byte
	}{
		{name: "auth ack only", ack: []byte{5, 2, 1, 0}, lead: []byte{0x9c}},
		{name: "immediate legacy final", ack: []byte{5, 2, 1, 0, 4, 1, 1, 0, 1, 1, 1, 1, 1, 1}, lead: []byte{0x9c}},
		{name: "payload starts 04", ack: []byte{5, 2, 1, 0}, lead: []byte{4, 2}},
		{name: "payload starts 04 01 but is no final", ack: []byte{5, 2, 1, 0}, lead: []byte{4, 1, 7, 7}},
		{name: "delayed legacy final then payload", ack: []byte{5, 2, 1, 0}, lead: nil},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			want := append(append([]byte(nil), tc.lead...), response[len(tc.lead):]...)
			written := want
			if tc.lead == nil {
				written = append([]byte{4, 1, 1, 0, 1, 1, 1, 1, 1, 1}, want...)
			}
			lease := startFirstResponseRelay(t, tc.ack, written)
			conn, err := dialStream(lease, "127.0.0.1", 5300, 2*time.Second)
			if err != nil {
				t.Fatal(err)
			}
			defer conn.Close()
			if _, err := conn.Write([]byte("first flight")); err != nil {
				t.Fatal(err)
			}
			_ = conn.SetReadDeadline(time.Now().Add(3 * time.Second))
			buffer := make([]byte, 32*1024)
			count, err := conn.Read(buffer)
			if err != nil || !bytes.Equal(buffer[:count], want) {
				t.Fatalf("first read = %d bytes (%v), want the whole %d-byte response", count, err, len(want))
			}
		})
	}
}
