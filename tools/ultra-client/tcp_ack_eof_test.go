package main

import (
	"bytes"
	"errors"
	"io"
	"net"
	"testing"
)

func TestStreamAckThenEOFIsHandshakeFailure(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	go func() {
		defer server.Close()
		server.Write([]byte{5, 2, 1, 0})
	}()
	if _, err := readStreamAcks(client); !errors.Is(err, io.EOF) {
		t.Fatalf("accepted dead stream: %v", err)
	}
}

func TestStreamAckWithShortPayloadThenEOFPreservesPayload(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	client, err := net.Dial("tcp", listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	go func() {
		server, err := listener.Accept()
		if err != nil {
			return
		}
		defer server.Close()
		server.Write(append([]byte{5, 2, 1, 0}, []byte("ok")...))
	}()
	r, err := readStreamAcks(client)
	if err != nil {
		t.Fatal(err)
	}
	got, err := io.ReadAll(r)
	if err != nil || !bytes.Equal(got, []byte("ok")) {
		t.Fatalf("lost early payload: %q %v", got, err)
	}
}

func TestStreamPreludeThenEOFIsHandshakeFailure(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	client, err := net.Dial("tcp", listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	go func() {
		server, err := listener.Accept()
		if err != nil {
			return
		}
		defer server.Close()
		prelude := make([]byte, streamPreludeSize)
		prelude[8] = 1
		server.Write(prelude)
	}()
	if _, err := readStreamAcks(client); !errors.Is(err, io.EOF) {
		t.Fatalf("accepted dead metadata-prelude stream: %v", err)
	}
}

func TestStreamPreludeWithPayloadThenEOFPreservesPayload(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	client, err := net.Dial("tcp", listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	go func() {
		server, err := listener.Accept()
		if err != nil {
			return
		}
		defer server.Close()
		prelude := make([]byte, streamPreludeSize)
		prelude[8] = 1
		server.Write(append(prelude, []byte("ok")...))
	}()
	reader, err := readStreamAcks(client)
	if err != nil {
		t.Fatal(err)
	}
	got, err := io.ReadAll(reader)
	if err != nil || !bytes.Equal(got, []byte("ok")) {
		t.Fatalf("lost prelude stream's early payload: %q %v", got, err)
	}
}
