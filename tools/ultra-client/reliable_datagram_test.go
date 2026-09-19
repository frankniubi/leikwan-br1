package main

import (
	"context"
	"encoding/binary"
	"errors"
	"testing"
	"time"
)

func TestReliableReplaySelectiveACKAndDedup(t *testing.T) {
	tx, rx := newReliableDatagrams(), newReliableDatagrams()
	for i := byte(1); i <= 3; i++ {
		if e := tx.enqueue(context.Background(), []byte{i}); e != nil {
			t.Fatal(e)
		}
	}
	frames, e := tx.due(time.Now())
	if e != nil || len(frames) != 3 {
		t.Fatalf("%v %v", frames, e)
	}
	var delivered []byte
	deliver := func(p []byte) error { delivered = append(delivered, p...); return nil }
	if e := rx.receive(2, frames[1].body, deliver); e != nil {
		t.Fatal(e)
	}
	seq, b, _ := rx.ack()
	if seq != 0 || binary.BigEndian.Uint64(b) != 2 {
		t.Fatal("invalid SACK")
	}
	if e := tx.acknowledge(seq, binary.BigEndian.Uint64(b)); e != nil {
		t.Fatal(e)
	}
	tx.replay()
	frames, e = tx.due(time.Now())
	if e != nil || len(frames) != 3 {
		t.Fatal("replay missing")
	}
	for _, p := range frames {
		if e := rx.receive(p.seq, p.body, deliver); e != nil {
			t.Fatal(e)
		}
	}
	if e := rx.receive(1, []byte{1}, deliver); e != nil {
		t.Fatal(e)
	}
	if string(delivered) != string([]byte{1, 2, 3}) {
		t.Fatalf("delivery %v", delivered)
	}
	seq, b, _ = rx.ack()
	if e := tx.acknowledge(seq, binary.BigEndian.Uint64(b)); e != nil {
		t.Fatal(e)
	}
	if tx.snapshot().WindowPackets != 0 || rx.snapshot().Deduplicated != 2 {
		t.Fatal("ACK or dedup mismatch")
	}
}
func TestReliableFailedDeliveryNotAcknowledged(t *testing.T) {
	r := newReliableDatagrams()
	e := r.receive(1, []byte("a"), func([]byte) error { return errors.New("udp write failed") })
	if e == nil || r.snapshot().Delivered != 0 {
		t.Fatal("ACKed failed write")
	}
	if seq, _, _ := r.ack(); seq != 0 {
		t.Fatal("ACK advanced")
	}
}
func TestReliableRetryExhaustionExplicit(t *testing.T) {
	r := newReliableDatagrams()
	r.enqueue(context.Background(), []byte("a"))
	now := time.Now()
	for i := 0; i < 12; i++ {
		if _, e := r.due(now.Add(time.Duration(i) * time.Second)); e != nil {
			t.Fatal(e)
		}
	}
	if _, e := r.due(now.Add(12 * time.Second)); e == nil {
		t.Fatal("retry budget not enforced")
	} else {
		r.fail(e)
	}
	if r.snapshot().UnrecoverableCount != 1 || r.snapshot().Failed == "" {
		t.Fatal("failure not exposed")
	}
}
func TestReliableFutureACKAndByteDeadline(t *testing.T) {
	r := newReliableDatagrams()
	if e := r.acknowledge(1, 0); e == nil {
		t.Fatal("accepted unsent ACK")
	}
	if e := r.enqueue(context.Background(), make([]byte, datagramQueueBytes)); e != nil {
		t.Fatal(e)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Millisecond)
	defer cancel()
	if e := r.enqueue(ctx, []byte{1}); e == nil {
		t.Fatal("full window accepted")
	}
	s := r.snapshot()
	if s.QueueTimeout != 1 || s.QueueBlocked != 1 || s.UnrecoverableCount != 2 {
		t.Fatalf("%+v", s)
	}
}
