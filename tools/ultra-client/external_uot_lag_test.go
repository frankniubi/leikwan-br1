package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"testing"
	"time"
)

// ---------------------------------------------------------------------------
// Lag detector
// ---------------------------------------------------------------------------

func TestExternalUoTLagTrackerNeedsAStandingQueueForTheWholeInterval(t *testing.T) {
	bounds := externalUoTLagBounds{target: time.Second, interval: 3 * time.Second, grace: 10 * time.Second}
	start := time.Unix(1_000_000, 0)
	tracker := externalUoTLagTracker{bounds: bounds, attachedAt: start.Add(-time.Minute)}
	at := func(seconds float64) time.Time { return start.Add(time.Duration(seconds * float64(time.Second))) }
	if tracker.observe(at(0), 2*time.Second, 0, 0, 0) {
		t.Fatal("one slow datagram declared the carrier lagging")
	}
	if tracker.observe(at(2.9), 2*time.Second, 0, 0, 0) {
		t.Fatal("lagging declared before the interval elapsed")
	}
	// The queue drained once: the clock starts over.
	if tracker.observe(at(3.0), 10*time.Millisecond, 0, 0, 0) {
		t.Fatal("a drained queue declared the carrier lagging")
	}
	if tracker.observe(at(3.1), 1500*time.Millisecond, 0, 0, 0) || tracker.observe(at(6.0), 1500*time.Millisecond, 0, 0, 0) {
		t.Fatal("the drain did not reset the standing-queue clock")
	}
	if !tracker.observe(at(6.2), 1500*time.Millisecond, 0, 0, 0) {
		t.Fatal("a queue standing above target for the whole interval was not declared lagging")
	}
}

// A fresh carrier through the SHA->LAX relay can need ~7 s to reach the offered
// rate and queues on the way; that ramp must not be mistaken for lagging unless
// the queue already overflowed.
func TestExternalUoTLagTrackerGivesAFreshCarrierItsRamp(t *testing.T) {
	bounds := externalUoTLagBounds{target: time.Second, interval: 3 * time.Second, grace: 10 * time.Second}
	attached := time.Unix(2_000_000, 0)
	ramp := externalUoTLagTracker{bounds: bounds, attachedAt: attached, overflowsAtAtt: 7}
	for second := 1; second <= 9; second++ {
		// Even with the queue nearly full: nothing overflowed yet.
		if ramp.observe(attached.Add(time.Duration(second)*time.Second), 2500*time.Millisecond, 7, 3500, 4096) {
			t.Fatalf("carrier declared lagging %d s into its ramp", second)
		}
	}
	if !ramp.observe(attached.Add(10*time.Second), 2500*time.Millisecond, 7, 0, 0) {
		t.Fatal("a carrier still standing after the grace was kept")
	}
	overflowing := externalUoTLagTracker{bounds: bounds, attachedAt: attached, overflowsAtAtt: 7}
	overflowing.observe(attached.Add(time.Second), 2*time.Second, 7, 0, 0)
	if !overflowing.observe(attached.Add(4*time.Second), 2*time.Second, 9, 0, 0) {
		t.Fatal("a carrier whose queue already overflowed was kept for the rest of its grace")
	}
	if (&externalUoTLagTracker{}).observe(attached, time.Hour, 0, 0, 0) {
		t.Fatal("the zero bounds must disable the detector")
	}
}

// A fast association fills its queue before the interval runs out; once the
// queue is three quarters full behind a standing delay, waiting only turns the
// lag into drops.
func TestExternalUoTLagTrackerActsBeforeAFastQueueOverflows(t *testing.T) {
	bounds := externalUoTLagBounds{target: time.Second, interval: 3 * time.Second, grace: 10 * time.Second}
	now := time.Unix(3_000_000, 0)
	tracker := externalUoTLagTracker{bounds: bounds, attachedAt: now.Add(-time.Minute)}
	if tracker.observe(now, 1200*time.Millisecond, 0, 2000, 4096) {
		t.Fatal("a half-full queue skipped the interval")
	}
	if !tracker.observe(now.Add(500*time.Millisecond), 1500*time.Millisecond, 0, 3100, 4096) {
		t.Fatal("a standing queue three quarters full was kept until it overflowed")
	}
	burst := externalUoTLagTracker{bounds: bounds, attachedAt: now.Add(-time.Minute)}
	if burst.observe(now, 200*time.Millisecond, 0, 4000, 4096) {
		t.Fatal("a full queue that drains fast is a burst, not a lagging carrier")
	}
}

// slowCarrier accepts writes at a fixed byte rate, like a relay path that still
// moves data but less than the association offers.
type slowCarrier struct {
	recordingCarrier
	bytesPerSecond float64
}

func (c *slowCarrier) Write(p []byte) (int, error) {
	time.Sleep(time.Duration(float64(len(p)) / c.bytesPerSecond * float64(time.Second)))
	return c.recordingCarrier.Write(p)
}

// A carrier that moves data, just too little, ends with the lagging error and
// leaves everything it has not taken in the queue for the next carrier.
func TestExternalUoTWriterLeavesALaggingCarrierAndKeepsTheQueue(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	association := testUoTAssociation(ctx, 4096, nil)
	association.lag = externalUoTLagBounds{target: 150 * time.Millisecond, interval: 300 * time.Millisecond}
	carrier := &slowCarrier{bytesPerSecond: 100_000} // ~0.8 Mbit/s against ~2.5 offered
	errs := make(chan error, 1)
	done := make(chan struct{})
	go func() {
		defer close(done)
		association.externalUoTWriter(ctx, carrier, errs)
	}()
	offered := 0
	var lagErr error
	deadline := time.Now().Add(5 * time.Second)
	for lagErr == nil && time.Now().Before(deadline) {
		if !association.enqueue(datagram(offered)) {
			t.Fatalf("datagram %d dropped before the carrier was left", offered)
		}
		offered++
		time.Sleep(4 * time.Millisecond)
		select {
		case lagErr = <-errs:
		default:
		}
	}
	if !errors.Is(lagErr, errExternalUoTCarrierLagging) {
		t.Fatalf("lagging carrier ended with %v", lagErr)
	}
	<-done
	written := len(carrier.frames(t))
	queued := len(association.outbound)
	if written+queued != offered || len(association.takeRetry()) != 0 {
		t.Fatalf("offered %d, written %d, still queued %d: datagrams lost at the handover", offered, written, queued)
	}
}

// A replacement carrier inherits a queue that already waited through the
// previous carrier's drain and its own HELLO, and it first replays the resend
// window; the queue waits and overflows behind that replay. A carrier fast
// enough for the offered load is healthy; judging it by the age the datagrams
// brought with them condemned every replacement within a second or two and
// turned one slow relay into a failover loop.
func TestExternalUoTWriterDoesNotChargeAFreshCarrierForItsInheritedBacklog(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	association := testUoTAssociation(ctx, 256, nil)
	association.lag = externalUoTLagBounds{target: 300 * time.Millisecond, interval: 600 * time.Millisecond, grace: 10 * time.Second}
	stale := time.Now().Add(-5 * time.Second)
	for index := 0; index < 250; index++ {
		association.budget.tryReserve(1200)
		association.outbound <- externalUoTQueued{payload: datagram(index), queuedAt: stale}
	}
	// What the lagging carrier left unconfirmed: 2 MiB to replay first.
	association.preserveRetry(pendingRange(1, uint64(externalUoTResendBytes/1200), 1200))
	association.carrierAttachedAt = time.Now()
	carrier := &slowCarrier{bytesPerSecond: 1_500_000} // ~12 Mbit/s against ~4.8 offered
	errs := make(chan error, 1)
	done := make(chan struct{})
	go func() {
		defer close(done)
		association.externalUoTWriter(ctx, carrier, errs)
	}()
	deadline := time.Now().Add(4 * time.Second)
	for index := 250; time.Now().Before(deadline); index++ {
		association.enqueue(datagram(index))
		time.Sleep(2 * time.Millisecond)
		select {
		case err := <-errs:
			t.Fatalf("a fresh carrier working through its inherited backlog was left: %v", err)
		default:
		}
	}
	cancel()
	<-done
}

func pendingRange(first, last uint64, size int) []externalUoTPending {
	batch := make([]externalUoTPending, 0, last-first+1)
	for sequence := first; sequence <= last; sequence++ {
		batch = append(batch, externalUoTPending{payload: make([]byte, size), sequence: sequence})
	}
	return batch
}

// What a carrier accepted is kept, bounded, and resent ahead of the batch the
// failed carrier never wrote, in sequence order.
func TestExternalUoTResendWindowIsBoundedAndResentInOrder(t *testing.T) {
	// Writer tests elsewhere in the package leave windows behind; measure
	// against what is already held.
	base := externalUoTResendHeld.Load()
	association := testUoTAssociation(context.Background(), 16, nil)
	now := time.Now()
	perDatagram := 1200
	total := externalUoTResendBytes/perDatagram + 500
	association.confirmWritten(pendingRange(1, uint64(total), perDatagram), now)
	if association.unconfirmedBytes > externalUoTResendBytes {
		t.Fatalf("resend window holds %d bytes, bound %d", association.unconfirmedBytes, externalUoTResendBytes)
	}
	oldestKept := association.unconfirmed[0].sequence
	if oldestKept <= 1 || association.unconfirmed[len(association.unconfirmed)-1].sequence != uint64(total) {
		t.Fatalf("window must keep the newest datagrams: %d..%d", oldestKept, association.unconfirmed[len(association.unconfirmed)-1].sequence)
	}
	association.preserveRetry(pendingRange(uint64(total)+1, uint64(total)+3, perDatagram))
	association.requeueUnconfirmed(now.Add(time.Second))
	resend := association.takeRetry()
	for index, item := range resend {
		if item.sequence != oldestKept+uint64(index) {
			t.Fatalf("resend out of order at %d: %d", index, item.sequence)
		}
	}
	if last := resend[len(resend)-1].sequence; last != uint64(total)+3 {
		t.Fatalf("the unwritten retry batch was not kept after the window: last=%d", last)
	}
	if association.unconfirmedBytes != 0 || len(association.unconfirmed) != 0 {
		t.Fatal("the window must be handed over once, not resent on every carrier")
	}

	association.discardUnconfirmed()
	if held := externalUoTResendHeld.Load() - base; held != 0 {
		t.Fatalf("runtime-wide resend budget leaked %d bytes", held)
	}

	// Many associations share one runtime-wide budget.
	crowd := make([]*externalUoTAssociation, externalUoTResendTotalBytes/externalUoTResendBytes+4)
	for index := range crowd {
		crowd[index] = testUoTAssociation(context.Background(), 16, nil)
		crowd[index].confirmWritten(pendingRange(1, uint64(externalUoTResendBytes/perDatagram), perDatagram), now)
	}
	if held := externalUoTResendHeld.Load(); held > externalUoTResendTotalBytes && held > base {
		t.Fatalf("associations together hold %d bytes, runtime bound %d", held, externalUoTResendTotalBytes)
	}
	for _, member := range crowd {
		member.discardUnconfirmed()
	}

	stale := testUoTAssociation(context.Background(), 16, nil)
	stale.confirmWritten(pendingRange(1, 10, perDatagram), now)
	stale.requeueUnconfirmed(now.Add(externalUoTResendAge + time.Second))
	if len(stale.takeRetry()) != 0 {
		t.Fatal("datagrams older than the resend age were resent")
	}
	if held := externalUoTResendHeld.Load() - base; held != 0 {
		t.Fatalf("runtime-wide resend budget leaked %d bytes", held)
	}
}

// A rotated session restarts its sequence numbers; a retry or resend numbered
// in the old space would make the new sidecar session suppress every datagram.
func TestExternalUoTSessionRotationDropsOldSequenceSpace(t *testing.T) {
	association := testUoTAssociation(context.Background(), 16, nil)
	association.sequence = 5000
	association.confirmWritten(pendingRange(4990, 4995, 100), time.Now())
	association.preserveRetry(pendingRange(4996, 5000, 100))
	previous := association.session
	if !association.rotateSession() {
		t.Fatal("rotation failed")
	}
	if association.session == previous || association.sequence != 0 {
		t.Fatal("rotation kept the old session or sequence")
	}
	association.requeueUnconfirmed(time.Now())
	if kept := association.takeRetry(); len(kept) != 0 {
		t.Fatalf("rotation kept %d datagrams numbered for the old session", len(kept))
	}
}

func TestExternalUoTLagBoundsBackOffAfterConsecutiveLaggingCarriers(t *testing.T) {
	base := externalUoTDefaultLagBounds
	if got := base.forStreak(0); got != base {
		t.Fatalf("no streak changed the bounds: %+v", got)
	}
	second := base.forStreak(1)
	if second.interval != 2*base.interval || second.grace != 2*base.grace || second.target != base.target {
		t.Fatalf("first backoff: %+v", second)
	}
	if capped := base.forStreak(10); capped.interval != 8*base.interval || capped.grace != 8*base.grace {
		t.Fatalf("backoff not capped at x8: %+v", capped)
	}
}

// ---------------------------------------------------------------------------
// Local SHA->LAX model: two provider relays in front of one sidecar
// ---------------------------------------------------------------------------

// uotLagRelay models a provider relay: it terminates the carrier TCP
// connection close to the client (as the real relay does, 19-20 ms from the
// Windows test host) and forwards it over its own per-connection path to the
// sidecar, adding delay each way and shaping the upstream rate of every
// connection it accepts independently. Its forwarding buffer is bounded, so a
// slow path pushes back on the carrier exactly as the relay's receive window
// does. A client half-close is forwarded after the buffered bytes.
type uotLagRelay struct {
	t        testing.TB
	listener *net.TCPListener
	sidecar  string
	oneWay   time.Duration
	// buffer bounds what the relay holds between reading the carrier and
	// forwarding it; receiveBuffer (0 = kernel default) fixes its socket
	// receive buffer. Together with the client's own socket they are the
	// backlog a lagging carrier hides below the association queue.
	buffer        int
	receiveBuffer int
	// rate returns the upstream byte rate of the index-th accepted connection
	// (0-based) at the given age; 0 means unshaped.
	rate     func(index int, age time.Duration) float64
	accepted atomic.Int64
}

func newUoTLagRelay(t testing.TB, sidecar string, oneWay time.Duration, buffer, receiveBuffer int, rate func(int, time.Duration) float64) *uotLagRelay {
	t.Helper()
	// The receive buffer is fixed on the listener, before any handshake (see
	// tcpPairWithBuffers): set on the accepted socket, macOS kept the window it
	// had already advertised, so a "slow" relay still acknowledged and quietly
	// held hundreds of KiB the client can never see.
	var listenConfig net.ListenConfig
	if receiveBuffer > 0 {
		listenConfig.Control = func(_, _ string, raw syscall.RawConn) error {
			var optionErr error
			if err := raw.Control(func(fd uintptr) { optionErr = setRawSocketBuffers(fd, receiveBuffer, 0) }); err != nil {
				return err
			}
			return optionErr
		}
	}
	bound, err := listenConfig.Listen(context.Background(), "tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	listener := bound.(*net.TCPListener)
	if buffer <= 0 {
		buffer = 256 << 10
	}
	relay := &uotLagRelay{t: t, listener: listener, sidecar: sidecar, oneWay: oneWay, buffer: buffer, receiveBuffer: receiveBuffer, rate: rate}
	t.Cleanup(func() { listener.Close() })
	go relay.acceptLoop()
	return relay
}

func (relay *uotLagRelay) port() int { return relay.listener.Addr().(*net.TCPAddr).Port }

func (relay *uotLagRelay) acceptLoop() {
	for {
		client, err := relay.listener.AcceptTCP()
		if err != nil {
			return
		}
		index := int(relay.accepted.Add(1) - 1)
		go relay.serve(client, index)
	}
}

func readLeigodStreamHandshake(reader *bufio.Reader) error {
	prefix := make([]byte, 2)
	if _, err := io.ReadFull(reader, prefix); err != nil {
		return err
	}
	switch {
	case bytes.Equal(prefix, []byte{5, 3}):
		if _, err := io.ReadFull(reader, make([]byte, 4)); err != nil {
			return err
		}
	case bytes.Equal(prefix, []byte{5, 5}):
		if _, err := io.ReadFull(reader, make([]byte, 6)); err != nil {
			return err
		}
	default:
		return errors.New("unknown greeting")
	}
	for field := 0; field < 2; field++ {
		if _, err := readLenPrefixed(reader); err != nil {
			return err
		}
	}
	tail := make([]byte, 3)
	if _, err := io.ReadFull(reader, tail); err != nil || tail[0] != 5 || tail[1] != 1 {
		return errors.New("not a command-1 stream")
	}
	addressType, err := reader.ReadByte()
	if err != nil || addressType != 1 {
		return errors.New("unexpected address type")
	}
	_, err = io.ReadFull(reader, make([]byte, 6))
	return err
}

type uotLagChunk struct {
	data []byte
	at   time.Time
}

func (relay *uotLagRelay) serve(client *net.TCPConn, index int) {
	defer client.Close()
	reader := bufio.NewReaderSize(client, 64<<10)
	_ = client.SetReadDeadline(time.Now().Add(5 * time.Second))
	if err := readLeigodStreamHandshake(reader); err != nil {
		return
	}
	_ = client.SetReadDeadline(time.Time{})
	if _, err := client.Write([]byte{5, 2, 1, 0, 4, 1, 1, 0, 1, 1, 1, 1, 1, 1}); err != nil {
		return
	}
	upstreamConn, err := net.Dial("tcp4", relay.sidecar)
	if err != nil {
		return
	}
	upstream := upstreamConn.(*net.TCPConn)
	defer upstream.Close()
	accepted := time.Now()

	// client -> sidecar: bounded buffer, per-connection shaping, fixed delay.
	var held atomic.Int64
	chunks := make(chan uotLagChunk, 4096)
	go func() {
		defer close(chunks)
		buffer := make([]byte, 32<<10)
		for {
			count, readErr := reader.Read(buffer)
			if count > 0 {
				for held.Load()+int64(count) > int64(relay.buffer) {
					time.Sleep(time.Millisecond)
				}
				held.Add(int64(count))
				chunks <- uotLagChunk{data: xorOne(buffer[:count]), at: time.Now()}
			}
			if readErr != nil {
				return
			}
		}
	}()
	go func() {
		var sent float64
		var shapedSince time.Time
		for chunk := range chunks {
			if wait := time.Until(chunk.at.Add(relay.oneWay)); wait > 0 {
				time.Sleep(wait)
			}
			for offset := 0; offset < len(chunk.data); {
				piece := len(chunk.data) - offset
				if piece > 1448 {
					piece = 1448
				}
				if rate := relay.rate(index, time.Since(accepted)); rate > 0 {
					if shapedSince.IsZero() {
						shapedSince, sent = time.Now(), 0
					}
					sent += float64(piece)
					if due := shapedSince.Add(time.Duration(sent / rate * float64(time.Second))); time.Until(due) > 0 {
						time.Sleep(time.Until(due))
					}
				} else {
					shapedSince = time.Time{}
				}
				if _, writeErr := upstream.Write(chunk.data[offset : offset+piece]); writeErr != nil {
					return
				}
				offset += piece
			}
			held.Add(-int64(len(chunk.data)))
		}
		_ = upstream.CloseWrite()
	}()

	// sidecar -> client: fixed delay; the sidecar closing ends the stream.
	downstream := make(chan uotLagChunk, 4096)
	go func() {
		defer close(downstream)
		buffer := make([]byte, 32<<10)
		for {
			count, readErr := upstream.Read(buffer)
			if count > 0 {
				downstream <- uotLagChunk{data: append([]byte(nil), buffer[:count]...), at: time.Now()}
			}
			if readErr != nil {
				return
			}
		}
	}()
	for chunk := range downstream {
		if wait := time.Until(chunk.at.Add(relay.oneWay)); wait > 0 {
			time.Sleep(wait)
		}
		if _, err := client.Write(chunk.data); err != nil {
			return
		}
	}
}

// uotLagSidecar speaks the sidecar side of UOT2 with the semantics that make a
// carrier handover lossy: one carrier per session, a HELLO on a new carrier
// closes the old one, and client sequence numbers at or below the last
// delivered one are dropped as replays. The target it forwards to answers every
// datagram with a 7-byte ACK, as the acceptance pump does.
type uotLagSidecar struct {
	listener  *net.TCPListener
	mu        sync.Mutex
	sessions  map[[16]byte]*uotLagSidecarSession
	delivered atomic.Int64
	replays   atomic.Int64
	attaches  atomic.Int64
}

type uotLagSidecarSession struct {
	mu      sync.Mutex
	carrier net.Conn
	lastSeq uint64
	seq     uint64
}

func newUoTLagSidecar(t testing.TB) *uotLagSidecar {
	t.Helper()
	listener, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	sidecar := &uotLagSidecar{listener: listener, sessions: map[[16]byte]*uotLagSidecarSession{}}
	t.Cleanup(func() { listener.Close() })
	go func() {
		for {
			carrier, err := listener.AcceptTCP()
			if err != nil {
				return
			}
			go sidecar.serve(carrier)
		}
	}()
	return sidecar
}

func (sidecar *uotLagSidecar) address() string { return sidecar.listener.Addr().String() }

func writeUoTLagFrame(connection net.Conn, frame externalUoTFrame) error {
	return writeExternalUoTFrame(connection, frame)
}

func (sidecar *uotLagSidecar) serve(carrier *net.TCPConn) {
	defer carrier.Close()
	reader := bufio.NewReaderSize(carrier, 128<<10)
	hello, err := readExternalUoTFrame(reader)
	if err != nil || hello.msgType != externalUoTMsgHello {
		return
	}
	sidecar.mu.Lock()
	session := sidecar.sessions[hello.session]
	marker := "OKR"
	if session == nil {
		session = &uotLagSidecarSession{}
		sidecar.sessions[hello.session] = session
		marker = "OKN"
	}
	sidecar.mu.Unlock()
	session.mu.Lock()
	previous := session.carrier
	session.carrier = carrier
	session.mu.Unlock()
	if previous != nil {
		_ = previous.Close()
	}
	sidecar.attaches.Add(1)
	ack, _ := json.Marshal(map[string]any{"ok": true, "session": marker, "frame_payload_max": externalUoTMaxDatagramBytes, "queue_packets": 4096})
	session.mu.Lock()
	err = writeUoTLagFrame(carrier, externalUoTFrame{msgType: externalUoTMsgHelloAck, session: hello.session, payload: ack})
	session.mu.Unlock()
	if err != nil {
		return
	}
	for {
		frame, err := readExternalUoTFrame(reader)
		if err != nil {
			return
		}
		if frame.msgType != externalUoTData {
			continue
		}
		session.mu.Lock()
		if frame.sequence <= session.lastSeq {
			session.mu.Unlock()
			sidecar.replays.Add(1)
			continue
		}
		session.lastSeq = frame.sequence
		sidecar.delivered.Add(1)
		session.seq++
		current := session.carrier
		reply := externalUoTFrame{msgType: externalUoTUDPData, session: hello.session, sequence: session.seq, payload: []byte("LKT1ACK")}
		if current != nil {
			_ = writeUoTLagFrame(current, reply)
		}
		session.mu.Unlock()
	}
}

type uotLagScenario struct {
	name      string
	senders   int
	mbps      float64 // aggregate offered rate, 1200-byte datagrams
	duration  time.Duration
	drain     time.Duration
	oneWay    time.Duration
	queue     int
	lag       externalUoTLagBounds
	collapse  time.Duration // age at which the first carrier through relay A slows down
	collapsed float64       // its rate afterwards, bytes/s (0: never collapses)
	// relayBuffer / relayReceiveBuffer size what each relay hides (0: the
	// 256 KiB forwarding buffer and the kernel's receive buffer).
	relayBuffer        int
	relayReceiveBuffer int
	// rampFor/rampRate make every carrier through relay A start slow, like a
	// fresh carrier through line 694 that needs seconds to reach the rate.
	rampFor  time.Duration
	rampRate float64
}

type uotLagResult struct {
	sent, acked        int64
	queueDrops         uint64
	lagFailovers       uint64
	delivered, replays int64
	relayA, relayB     int64
	maxLatency         time.Duration
}

func (result uotLagResult) lossPct() float64 {
	if result.sent == 0 {
		return 0
	}
	return 100 * float64(result.sent-result.acked) / float64(result.sent)
}

func (result uotLagResult) String() string {
	return fmt.Sprintf("sent=%d acked=%d loss=%.3f%% queue_drops=%d lag_failovers=%d max_latency=%s sidecar_delivered=%d replays=%d relayA_conns=%d relayB_conns=%d",
		result.sent, result.acked, result.lossPct(), result.queueDrops, result.lagFailovers, result.maxLatency.Round(time.Millisecond),
		result.delivered, result.replays, result.relayA, result.relayB)
}

// runUoTLagScenario drives the production serveExternalUoT through relay A
// (primary; its first carrier collapses) with relay B as the healthy sibling.
func runUoTLagScenario(t *testing.T, scenario uotLagScenario) uotLagResult {
	t.Helper()
	previous := externalUoTDefaultLagBounds
	externalUoTDefaultLagBounds = scenario.lag
	defer func() { externalUoTDefaultLagBounds = previous }()

	sidecar := newUoTLagSidecar(t)
	relayA := newUoTLagRelay(t, sidecar.address(), scenario.oneWay, scenario.relayBuffer, scenario.relayReceiveBuffer, func(index int, age time.Duration) float64 {
		if age < scenario.rampFor {
			return scenario.rampRate
		}
		if index == 0 && scenario.collapsed > 0 && age >= scenario.collapse {
			return scenario.collapsed
		}
		return 0
	})
	relayB := newUoTLagRelay(t, sidecar.address(), scenario.oneWay, scenario.relayBuffer, scenario.relayReceiveBuffer, func(int, time.Duration) float64 { return 0 })

	config := testPolicyConfig()
	config.ListenHost = "127.0.0.1"
	config.ConnectTimeoutSecs = 3
	config.ExternalUoT = ExternalUoTConfig{
		Enabled: true, SidecarTarget: sidecar.address(), Token: "lag-bench",
		QueuePackets: scenario.queue, FramePayloadMax: externalUoTMaxDatagramBytes,
	}
	now := time.Now()
	policy := testPolicy(now, strings.Repeat("c", 64))
	policy.Transport = "udp"
	policy.RateLimitMbps = 10000
	policy.QuotaRemainingBytes = 1 << 31
	policy.ExpiresAt = now.Add(4 * time.Minute)
	leaseA := testLease(policy, strings.Repeat("d", 64))
	leaseA.IP, leaseA.Port = "127.0.0.1", relayA.port()
	leaseA.User, leaseA.Password = []byte(gameSimUser), []byte(gameSimCredential)
	leaseA.CandidateKeyHash = strings.Repeat("a1", 32)
	leaseB := testLease(policy, strings.Repeat("e", 64))
	leaseB.IP, leaseB.Port = "127.0.0.1", relayB.port()
	leaseB.User, leaseB.Password = []byte(gameSimUser), []byte(gameSimCredential)
	leaseB.CandidateKeyHash = strings.Repeat("b2", 32)
	store := NewPolicyStore(config)
	if err := store.ReplaceBatch(policy, leaseA, []ProviderLease{leaseA, leaseB}); err != nil {
		t.Fatal(err)
	}
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), config.ForwardID, time.Hour)
	defer status.Close()

	listening := make(chan *net.UDPConn, 1)
	previousHook := testHookLocalUDPListening
	testHookLocalUDPListening = func(conn *net.UDPConn) { listening <- conn }
	defer func() { testHookLocalUDPListening = previousHook }()
	ctx, cancel := context.WithCancel(context.Background())
	served := make(chan error, 1)
	go func() { served <- serveExternalUoT(ctx, config, store, status) }()
	var local *net.UDPConn
	select {
	case local = <-listening:
	case err := <-served:
		t.Fatalf("serveExternalUoT: %v", err)
	case <-time.After(5 * time.Second):
		t.Fatal("UoT listener never came up")
	}
	defer func() {
		cancel()
		<-served
	}()

	perSender := scenario.mbps * 1e6 / 8 / 1200 / float64(scenario.senders)
	interval := time.Duration(float64(time.Second) / perSender)
	var sent, acked atomic.Int64
	var maxLatency atomic.Int64
	var wait sync.WaitGroup
	start := time.Now()
	stopAt := start.Add(scenario.duration)
	for sender := 0; sender < scenario.senders; sender++ {
		conn, err := net.DialUDP("udp4", nil, local.LocalAddr().(*net.UDPAddr))
		if err != nil {
			t.Fatal(err)
		}
		defer conn.Close()
		_ = conn.SetReadBuffer(4 << 20)
		var mu sync.Mutex
		var sendTimes []time.Time
		var got int
		go func() {
			buffer := make([]byte, 2048)
			for {
				_ = conn.SetReadDeadline(time.Now().Add(scenario.duration + scenario.drain + 5*time.Second))
				count, err := conn.Read(buffer)
				if err != nil {
					return
				}
				if count < 4 || string(buffer[:4]) != "LKT1" {
					continue
				}
				acked.Add(1)
				mu.Lock()
				if got < len(sendTimes) {
					if latency := time.Since(sendTimes[got]); int64(latency) > maxLatency.Load() {
						maxLatency.Store(int64(latency))
					}
				}
				got++
				mu.Unlock()
			}
		}()
		wait.Add(1)
		go func() {
			defer wait.Done()
			body := make([]byte, 1200)
			copy(body, "LKT1")
			next := time.Now()
			for index := uint32(0); time.Now().Before(stopAt); index++ {
				if wait := time.Until(next); wait > 0 {
					time.Sleep(wait)
				}
				binary.BigEndian.PutUint32(body[4:8], index)
				mu.Lock()
				sendTimes = append(sendTimes, time.Now())
				mu.Unlock()
				if _, err := conn.Write(body); err == nil {
					sent.Add(1)
				}
				next = next.Add(interval)
				if behind := time.Since(next); behind > 50*time.Millisecond {
					next = time.Now()
				}
			}
		}()
	}
	wait.Wait()
	time.Sleep(scenario.drain)
	status.mu.Lock()
	snapshot := status.status
	status.mu.Unlock()
	return uotLagResult{
		sent: sent.Load(), acked: acked.Load(), queueDrops: snapshot.UoTQueueDrop, lagFailovers: snapshot.UoTCarrierLagFailovers,
		delivered: sidecar.delivered.Load(), replays: sidecar.replays.Load(),
		relayA: relayA.accepted.Load(), relayB: relayB.accepted.Load(), maxLatency: time.Duration(maxLatency.Load()),
	}
}

// The mechanism of the 2026-09-24 combo-55 loss, at loopback scale: one
// association's carrier keeps moving data but slower than the association
// offers. Without the lag detector the association stays on it, its queue
// fills and overflows; with it, the association leaves the carrier, the path
// is isolated, and the sibling relay carries the queue and the rest of the run.
func TestExternalUoTLaggingCarrierFailsOverWithoutLoss(t *testing.T) {
	// Small relay buffers keep what the lagging carrier hides below the queue
	// (and so the time to notice it) comparable on every platform's loopback.
	scenario := uotLagScenario{
		senders: 2, mbps: 5, duration: 6 * time.Second, drain: 1500 * time.Millisecond,
		oneWay: 20 * time.Millisecond, queue: 512, relayBuffer: 32 << 10, relayReceiveBuffer: 32 << 10,
		collapse: 500 * time.Millisecond, collapsed: 110_000, // ~0.9 Mbit/s against 2.5 offered
	}
	before := scenario
	before.name, before.lag = "detector_off", externalUoTLagBounds{}
	baseline := runUoTLagScenario(t, before)
	t.Logf("before: %s", baseline)
	if baseline.lossPct() < 5 {
		t.Fatalf("the collapsed carrier did not reproduce the loss without the detector: %s", baseline)
	}
	after := scenario
	after.name = "detector_on"
	after.lag = externalUoTLagBounds{target: 250 * time.Millisecond, interval: 500 * time.Millisecond, grace: time.Second, drain: 500 * time.Millisecond}
	fixed := runUoTLagScenario(t, after)
	t.Logf("after:  %s", fixed)
	if fixed.lagFailovers == 0 || fixed.relayB == 0 {
		t.Fatalf("the lagging carrier was not left for the sibling relay: %s", fixed)
	}
	if fixed.queueDrops != 0 || fixed.lossPct() > 0.5 {
		t.Fatalf("failover still lost datagrams: %s", fixed)
	}
	if fixed.replays == 0 {
		t.Fatalf("the drain bound expired, so the next carrier should have resent the window: %s", fixed)
	}

	// A relay that forwards the half-close lets the lagging carrier finish
	// inside the drain bound; the sidecar then has everything and the next
	// carrier does not spend its ramp resending it.
	drained := after
	drained.name = "drain_completes"
	drained.duration = 8 * time.Second
	// A loopback receiver on macOS advertises a 290-515 KiB window whatever its
	// SO_RCVBUF, so the collapsed relay acknowledges and holds ~600 KiB the
	// client cannot see: the lag shows ~4 s later than on Linux and the drain
	// takes ~3.5 s. Leave time to count what that late handover still delivers;
	// this scenario is about losing nothing, not about latency.
	drained.drain = 6 * time.Second
	drained.queue = externalUoTDefaultQueuePackets
	drained.collapsed = 160_000 // 1.3 Mbit/s: its backlog drains well inside the bound
	drained.lag.drain = 8 * time.Second
	complete := runUoTLagScenario(t, drained)
	t.Logf("drain:  %s", complete)
	if complete.lagFailovers == 0 || complete.queueDrops != 0 || complete.lossPct() > 0.5 {
		t.Fatalf("drained failover lost datagrams: %s", complete)
	}
	if complete.replays != 0 {
		t.Fatalf("a carrier that drained completely still had its window resent: %s", complete)
	}
}

// TestExternalUoTLagBenchmark is the full-scale local model of the SHA->LAX
// acceptance run: four senders at 10 Mbit/s aggregate, 1200-byte datagrams,
// 160 ms relay RTT, the production queue (4096) and the production lag bounds,
// with one carrier collapsing to 1.5 Mbit/s ten seconds in, as observed on
// line 694. Opt in with ULTRA_UOT_LAG_BENCH=<seconds> (60 or more is
// representative):
//
//	ULTRA_UOT_LAG_BENCH=90 go test -run TestExternalUoTLagBenchmark -v -count=1
func TestExternalUoTLagBenchmark(t *testing.T) {
	seconds, _ := strconv.Atoi(os.Getenv("ULTRA_UOT_LAG_BENCH"))
	if seconds <= 0 {
		t.Skip("set ULTRA_UOT_LAG_BENCH=<seconds> to run the full-scale lag benchmark")
	}
	scenario := uotLagScenario{
		senders: 4, mbps: 10, duration: time.Duration(seconds) * time.Second, drain: 2 * time.Second,
		oneWay: 80 * time.Millisecond, queue: externalUoTDefaultQueuePackets,
		collapse: 10 * time.Second, collapsed: 1.5e6 / 8,
	}
	before := scenario
	before.name, before.lag = "detector_off", externalUoTLagBounds{}
	baseline := runUoTLagScenario(t, before)
	t.Logf("before: %s", baseline)
	after := scenario
	after.name, after.lag = "detector_on", externalUoTDefaultLagBounds
	fixed := runUoTLagScenario(t, after)
	t.Logf("after:  %s", fixed)
	if fixed.lossPct() > 0.5 || math.IsNaN(fixed.lossPct()) {
		t.Fatalf("loss above the 0.5%% acceptance bound: %s", fixed)
	}
	// Controls: healthy carriers, and carriers that ramp slowly for 7 s (up to
	// ~2.8 s of queueing, as measured on line 694), must never be left.
	healthy := after
	healthy.name, healthy.collapsed = "healthy", 0
	control := runUoTLagScenario(t, healthy)
	t.Logf("healthy: %s", control)
	ramping := healthy
	ramping.name, ramping.rampFor, ramping.rampRate = "slow_ramp", 7*time.Second, 1.2e6/8
	ramp := runUoTLagScenario(t, ramping)
	t.Logf("ramp:    %s", ramp)
	if control.lagFailovers != 0 || control.lossPct() > 0.1 || ramp.lagFailovers != 0 || ramp.lossPct() > 0.5 {
		t.Fatalf("healthy or ramping carriers were disturbed: healthy=%s ramp=%s", control, ramp)
	}
}
