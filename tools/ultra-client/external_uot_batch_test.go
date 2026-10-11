package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/binary"
	"errors"
	"net"
	"path/filepath"
	"sync"
	"testing"
	"time"
)

// recordingCarrier is a carrier socket that accepts every write, optionally
// charging a fixed cost per write call (a syscall / overlapped send round trip),
// and keeps what it received.
type recordingCarrier struct {
	net.Conn
	perWrite time.Duration
	mu       sync.Mutex
	writes   int
	data     bytes.Buffer
}

func (c *recordingCarrier) Write(p []byte) (int, error) {
	if c.perWrite > 0 {
		time.Sleep(c.perWrite)
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.writes++
	c.data.Write(p)
	return len(p), nil
}

func (c *recordingCarrier) SetWriteDeadline(time.Time) error { return nil }
func (c *recordingCarrier) Close() error                     { return nil }

func (c *recordingCarrier) snapshot() (int, []byte) {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.writes, append([]byte(nil), c.data.Bytes()...)
}

// frames decodes everything the carrier received.
func (c *recordingCarrier) frames(t *testing.T) []externalUoTFrame {
	t.Helper()
	_, wire := c.snapshot()
	reader := bufio.NewReader(bytes.NewReader(xorOne(wire)))
	var frames []externalUoTFrame
	for {
		if _, err := reader.Peek(1); err != nil {
			break
		}
		frame, err := readExternalUoTFrame(reader)
		if err != nil {
			t.Fatalf("carrier stream corrupt after %d frames: %v", len(frames), err)
		}
		frames = append(frames, frame)
	}
	return frames
}

func (c *recordingCarrier) waitFrames(t *testing.T, want int, within time.Duration) []externalUoTFrame {
	t.Helper()
	deadline := time.Now().Add(within)
	for {
		frames := c.frames(t)
		if len(frames) >= want {
			return frames
		}
		if time.Now().After(deadline) {
			t.Fatalf("carrier received %d of %d frames within %s", len(frames), want, within)
		}
		time.Sleep(5 * time.Millisecond)
	}
}

func testUoTAssociation(ctx context.Context, queue int, status *StatusWriter) *externalUoTAssociation {
	association := &externalUoTAssociation{
		ctx: ctx, cancel: func() {}, outbound: make(chan externalUoTQueued, queue),
		budget: newDatagramBudget(datagramQueueBytes), status: status,
	}
	for i := range association.session {
		association.session[i] = byte(i + 1)
	}
	return association
}

func datagram(index int) []byte {
	payload := bytes.Repeat([]byte{byte(index)}, 1200)
	binary.BigEndian.PutUint32(payload, uint32(index))
	return payload
}

func checkFrames(t *testing.T, frames []externalUoTFrame, count int, session [16]byte) {
	t.Helper()
	if len(frames) != count {
		t.Fatalf("got %d frames, want %d", len(frames), count)
	}
	for index, frame := range frames {
		if frame.msgType != externalUoTData || frame.session != session || frame.sequence != uint64(index+1) ||
			!bytes.Equal(frame.payload, datagram(index)) {
			t.Fatalf("frame %d out of order or corrupt: type=%d seq=%d", index, frame.msgType, frame.sequence)
		}
	}
}

// The prefix and the frame used to be two writes. One datagram is one write
// now, byte-identical on the wire.
func TestExternalUoTCarrierFrameIsOneWriteAndWireCompatible(t *testing.T) {
	frame := externalUoTFrame{msgType: externalUoTData, flags: 7, sequence: 9, payload: []byte("datagram")}
	frame.session[0] = 0xaa
	body, err := externalUoTFrameBytes(frame)
	if err != nil {
		t.Fatal(err)
	}
	var legacy []byte
	legacy = binary.BigEndian.AppendUint32(legacy, uint32(len(body)))
	legacy = append(legacy, body...)
	encoded, err := appendExternalUoTCarrierFrame([]byte("keep"), frame)
	if err != nil {
		t.Fatal(err)
	}
	if string(encoded[:4]) != "keep" || !bytes.Equal(encoded[4:], xorOne(legacy)) {
		t.Fatal("batched encoding differs from the two-write wire format")
	}
	carrier := &recordingCarrier{}
	if err := writeExternalUoTCarrierFrame(carrier, frame); err != nil {
		t.Fatal(err)
	}
	if writes, wire := carrier.snapshot(); writes != 1 || !bytes.Equal(wire, xorOne(legacy)) {
		t.Fatalf("one frame took %d writes", writes)
	}
}

// A backlog leaves in a few large writes, in order, with consecutive sequences;
// a lone datagram is still written at once, on its own.
func TestExternalUoTWriterCoalescesQueuedDatagrams(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	association := testUoTAssociation(ctx, 512, nil)
	for index := 0; index < 300; index++ {
		if !association.enqueue(datagram(index)) {
			t.Fatalf("admission %d refused", index)
		}
	}
	carrier := &recordingCarrier{}
	errs := make(chan error, 1)
	stopped := make(chan struct{})
	go func() {
		defer close(stopped)
		association.externalUoTWriter(ctx, carrier, errs)
	}()
	checkFrames(t, carrier.waitFrames(t, 300, 2*time.Second), 300, association.session)
	frameBytes := externalUoTFramePrefixSize + externalUoTHeaderSize + len(datagram(0))
	perBatch := (externalUoTBatchBytes + frameBytes - 1) / frameBytes // the frame that crosses the bound still goes
	if perBatch > externalUoTBatchFrames {
		perBatch = externalUoTBatchFrames
	}
	want := (300 + perBatch - 1) / perBatch
	if writes, _ := carrier.snapshot(); writes != want {
		t.Fatalf("300 queued datagrams took %d writes, want %d (batches of %d)", writes, want, perBatch)
	}
	if association.budget.bytes() != 0 {
		t.Fatalf("written datagrams still hold %d budget bytes", association.budget.bytes())
	}
	association.enqueue(datagram(300))
	frames := carrier.waitFrames(t, 301, time.Second)
	if writes, _ := carrier.snapshot(); writes != want+1 || frames[300].sequence != 301 {
		t.Fatalf("a lone datagram was not written on its own (writes=%d)", writes)
	}
	cancel()
	<-stopped
}

// A carrier write costs a fixed time per call. Writing each datagram as two
// small writes then caps the carrier far below 10 Mbit/s and a 256-packet queue
// overflows; batching keeps up with the same per-write cost. This is the
// Windows SHA->LAX failure (queue_blocked=1994) in miniature.
func TestExternalUoTWriterKeepsUpAt10MbpsWithPerWriteCost(t *testing.T) {
	const (
		datagrams = 1000
		burst     = 12 // every 10 ms: 1,200 datagrams/s of 1,200 B = 11.5 Mbit/s
		perWrite  = 2 * time.Millisecond
		queue     = 256
	)
	offer := func(association *externalUoTAssociation) (dropped int) {
		for index := 0; index < datagrams; index++ {
			if !association.enqueue(datagram(index)) {
				dropped++
			}
			if index%burst == burst-1 {
				time.Sleep(10 * time.Millisecond)
			}
		}
		return dropped
	}
	t.Run("two_writes_per_datagram", func(t *testing.T) {
		ctx, cancel := context.WithCancel(context.Background())
		defer cancel()
		association := testUoTAssociation(ctx, queue, nil)
		carrier := &recordingCarrier{perWrite: perWrite}
		go func() {
			for {
				select {
				case <-ctx.Done():
					return
				case queued := <-association.outbound:
					association.budget.release(len(queued.payload))
					_ = writeExternalUoTFrame(carrier, externalUoTFrame{msgType: externalUoTData, payload: queued.payload})
				}
			}
		}()
		if dropped := offer(association); dropped == 0 {
			t.Fatal("the unbatched model kept up; the test does not reproduce the backlog")
		}
	})
	t.Run("batched", func(t *testing.T) {
		ctx, cancel := context.WithCancel(context.Background())
		defer cancel()
		association := testUoTAssociation(ctx, queue, nil)
		carrier := &recordingCarrier{perWrite: perWrite}
		go association.externalUoTWriter(ctx, carrier, make(chan error, 1))
		if dropped := offer(association); dropped != 0 {
			t.Fatalf("batched carrier dropped %d of %d datagrams at 11.5 Mbit/s", dropped, datagrams)
		}
		checkFrames(t, carrier.waitFrames(t, datagrams, 3*time.Second), datagrams, association.session)
	})
}

// Admission runs on the one read loop that serves every association, so a full
// queue must refuse at once instead of stalling the loop (previously up to
// 250 ms per packet, during which every other association's datagrams piled up
// in the kernel and were dropped uncounted).
func TestExternalUoTFullQueueNeverStallsTheReadLoop(t *testing.T) {
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 170, time.Hour)
	defer status.Close()
	ctx := context.Background()
	stuck := testUoTAssociation(ctx, 1, status)
	healthy := testUoTAssociation(ctx, 64, status)
	if !stuck.enqueue(datagram(0)) {
		t.Fatal("initial admission")
	}
	started := time.Now()
	for index := 1; index <= 50; index++ {
		if stuck.enqueue(datagram(index)) {
			t.Fatal("admitted into a full queue")
		}
		if !healthy.enqueue(datagram(index)) {
			t.Fatal("a full neighbour blocked a healthy association")
		}
	}
	if elapsed := time.Since(started); elapsed > 200*time.Millisecond {
		t.Fatalf("100 admissions against a full queue took %s", elapsed)
	}
	if stuck.budget.bytes() != 1200 {
		t.Fatalf("refused admissions leaked budget: %d", stuck.budget.bytes())
	}
	status.mu.Lock()
	defer status.mu.Unlock()
	if status.status.UoTQueueTimeout != 50 || status.status.UoTQueueDrop != 50 || status.status.UoTQueueBlocked != 50 {
		t.Fatalf("refusals not counted: %+v", status.status)
	}
}

// A carrier whose relay stopped reading ends with a stall instead of pinning
// the writer (and the keepalive, which shares its lock) forever, and the batch
// it could not write is kept for the replacement carrier.
func TestExternalUoTCarrierWriteStallEndsCarrierAndKeepsBatch(t *testing.T) {
	// Buffers fixed before connect: a receive buffer shrunk on the connected
	// socket lets the stopped relay keep accepting retransmitted bytes, so the
	// stall comes late and the keepalive afterwards can still slip through.
	left, _ := tcpPairWithBuffers(t, 16<<10, 16<<10)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	association := testUoTAssociation(ctx, 4096, nil)
	association.writeStall = 200 * time.Millisecond
	go func() {
		for index := 0; ctx.Err() == nil; index++ {
			association.enqueue(datagram(index % 200))
			if index%64 == 0 {
				time.Sleep(time.Millisecond)
			}
		}
	}()
	errs := make(chan error, 1)
	started := time.Now()
	go association.externalUoTWriter(ctx, left, errs)
	select {
	case err := <-errs:
		if !errors.Is(err, errRemoteWriteStall) || haErrorClass(err) != "stall" {
			t.Fatalf("carrier ended with %v, want a write stall", err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("stalled carrier writer never returned")
	}
	if elapsed := time.Since(started); elapsed < association.writeStall {
		t.Fatalf("stall declared after %s, before the bound", elapsed)
	}
	if kept := association.takeRetry(); len(kept) == 0 {
		t.Fatal("the unwritten batch was lost instead of kept for the next carrier")
	}
	// A keepalive on the stalled carrier must not block for longer than the
	// bound either. The first few may still be accepted: a socket that
	// refused the large batch can keep a little room for a tiny write (BSD
	// sockets, for one, refuse a large write once free space is below the send
	// low-water mark but take a small one that fits). That room is finite, so
	// keepalives end in a bounded stall instead of blocking with the lock held.
	keepaliveDeadline := time.Now().Add(3 * time.Second)
	for accepted := 0; ; accepted++ {
		began := time.Now()
		err := association.writeCarrierFrame(left, externalUoTFrame{msgType: externalUoTKeepalive})
		if took := time.Since(began); took > association.writeStall+time.Second {
			t.Fatalf("a keepalive on the stalled carrier blocked for %s with a %s bound", took, association.writeStall)
		}
		if errors.Is(err, errRemoteWriteStall) {
			break
		}
		if err != nil {
			t.Fatalf("a keepalive on the stalled carrier returned %v, want a bounded stall", err)
		}
		if time.Now().After(keepaliveDeadline) {
			t.Fatalf("the stalled carrier accepted %d keepalives without a stall", accepted+1)
		}
	}
}
