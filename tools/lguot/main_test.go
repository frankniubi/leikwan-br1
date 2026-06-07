package main

import (
	"bytes"
	"encoding/binary"
	"net"
	"testing"
)

// TestFrameBatchProducesLengthPrefixedWire verifies the writev framing helper produces the exact
// [uint16 len][payload] wire stream (byte-identical to the old per-datagram bufio path), so the
// optimization changes only HOW bytes are written (one writev per batch, no per-datagram copy),
// not the wire format — the server side (and old peers) stay compatible.
func TestFrameBatchProducesLengthPrefixedWire(t *testing.T) {
	payloads := [][]byte{[]byte("ab"), []byte("hello"), {}, []byte("xyz123")}
	hdrs := make([][]byte, len(payloads))
	for i := range hdrs {
		hdrs[i] = make([]byte, 2)
	}
	var iov net.Buffers
	frameBatch(&iov, hdrs, payloads)

	var got bytes.Buffer
	if _, err := iov.WriteTo(&got); err != nil {
		t.Fatalf("WriteTo: %v", err)
	}

	var want bytes.Buffer
	for _, p := range payloads {
		h := make([]byte, 2)
		binary.BigEndian.PutUint16(h, uint16(len(p)))
		want.Write(h)
		want.Write(p)
	}
	if !bytes.Equal(got.Bytes(), want.Bytes()) {
		t.Fatalf("frame mismatch:\n got=%x\nwant=%x", got.Bytes(), want.Bytes())
	}
}

// TestFrameBatchReusesBackingAllocationFree confirms a reused backing slice yields the same wire on
// a second batch (the hot loop reuses the backing array + hdrs across iterations, allocation-free).
func TestFrameBatchReusesBackingAllocationFree(t *testing.T) {
	hdrs := make([][]byte, 4)
	for i := range hdrs {
		hdrs[i] = make([]byte, 2)
	}
	backing := make(net.Buffers, 0, 8)

	for round := 0; round < 2; round++ {
		payloads := [][]byte{[]byte("one"), []byte("two")}
		iov := backing[:0]
		frameBatch(&iov, hdrs, payloads)
		var got bytes.Buffer
		if _, err := iov.WriteTo(&got); err != nil {
			t.Fatalf("round %d WriteTo: %v", round, err)
		}
		want := []byte{0, 3, 'o', 'n', 'e', 0, 3, 't', 'w', 'o'}
		if !bytes.Equal(got.Bytes(), want) {
			t.Fatalf("round %d mismatch: got=%x want=%x", round, got.Bytes(), want)
		}
	}
}
