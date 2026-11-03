package main

// Coverage for per-forward metering and the token bucket speed limit: exact
// bucket timing under a fake clock (rate, one second burst, wait up to 250 ms
// then drop, in-place rate changes), and end-to-end counting in both
// directions through the raw and the reliable relay.

import (
	"context"
	"encoding/binary"
	"errors"
	"io"
	"strings"
	"testing"
	"time"
)

// fakeBucketClock drives a tokenBucket deterministically: sleeping advances
// the clock by exactly the requested delay and records it.
type fakeBucketClock struct {
	current time.Time
	sleeps  []time.Duration
}

func (c *fakeBucketClock) now() time.Time { return c.current }

func (c *fakeBucketClock) sleep(_ context.Context, d time.Duration) error {
	c.sleeps = append(c.sleeps, d)
	c.current = c.current.Add(d)
	return nil
}

func (c *fakeBucketClock) advance(d time.Duration) { c.current = c.current.Add(d) }

func (c *fakeBucketClock) takeSleeps() []time.Duration {
	sleeps := c.sleeps
	c.sleeps = nil
	return sleeps
}

func fakeClockBucket() (*tokenBucket, *fakeBucketClock) {
	clock := &fakeBucketClock{current: time.Date(2026, 10, 21, 0, 0, 0, 0, time.UTC)}
	bucket := newTokenBucket()
	bucket.now = clock.now
	bucket.sleep = clock.sleep
	return bucket, clock
}

func approximately(got time.Duration, want time.Duration) bool {
	diff := got - want
	if diff < 0 {
		diff = -diff
	}
	return diff <= time.Microsecond
}

func mustAdmit(t *testing.T, bucket *tokenBucket, clock *fakeBucketClock, n int, wantSleep time.Duration) {
	t.Helper()
	if err := bucket.wait(context.Background(), n); err != nil {
		t.Fatalf("wait(%d) = %v, want admitted", n, err)
	}
	sleeps := clock.takeSleeps()
	switch {
	case wantSleep == 0 && len(sleeps) != 0:
		t.Fatalf("wait(%d) slept %v, want no wait", n, sleeps)
	case wantSleep != 0 && (len(sleeps) != 1 || !approximately(sleeps[0], wantSleep)):
		t.Fatalf("wait(%d) slept %v, want %v", n, sleeps, wantSleep)
	}
}

func TestTokenBucketRateBurstAndDropAfterMaxWait(t *testing.T) {
	bucket, clock := fakeClockBucket()
	// 1 Mbps = 1,000,000 bit/s = 125,000 bytes/s, with a one second burst.
	bucket.setLimitMbps(1)

	// A fresh bucket starts full: one full second of burst passes untouched.
	mustAdmit(t, bucket, clock, 125000, 0)
	// Empty now: 12,500 bytes take 100 ms at 125,000 B/s.
	mustAdmit(t, bucket, clock, 12500, 100*time.Millisecond)
	mustAdmit(t, bucket, clock, 25000, 200*time.Millisecond)
	// 37,500 bytes would need 300 ms > 250 ms: dropped, nothing consumed, no wait.
	if err := bucket.wait(context.Background(), 37500); !errors.Is(err, errRateLimited) {
		t.Fatalf("wait beyond the 250 ms budget = %v, want errRateLimited", err)
	}
	if sleeps := clock.takeSleeps(); len(sleeps) != 0 {
		t.Fatalf("a dropped datagram waited %v", sleeps)
	}
	// Exactly 250 ms is still admitted, and the drop above consumed nothing.
	mustAdmit(t, bucket, clock, 31250, 250*time.Millisecond)

	// A long idle period refills to the burst and no further.
	clock.advance(10 * time.Second)
	mustAdmit(t, bucket, clock, 125000, 0)
	mustAdmit(t, bucket, clock, 125, time.Millisecond)
}

func TestTokenBucketReservationsQueueWaiters(t *testing.T) {
	bucket, clock := fakeClockBucket()
	bucket.setLimitMbps(1)
	mustAdmit(t, bucket, clock, 125000, 0)
	// Two waiters arriving at the same instant: the second one waits behind
	// the first one's reservation instead of sharing the same refill. The
	// recorder does not advance the clock, as if both slept concurrently.
	bucket.sleep = func(_ context.Context, d time.Duration) error {
		clock.sleeps = append(clock.sleeps, d)
		return nil
	}
	if err := bucket.wait(context.Background(), 12500); err != nil {
		t.Fatal(err)
	}
	if err := bucket.wait(context.Background(), 12500); err != nil {
		t.Fatal(err)
	}
	sleeps := clock.takeSleeps()
	if len(sleeps) != 2 || !approximately(sleeps[0], 100*time.Millisecond) || !approximately(sleeps[1], 200*time.Millisecond) {
		t.Fatalf("queued waiters slept %v, want [100ms 200ms]", sleeps)
	}
	// A third arrival would have to wait 300 ms behind them: dropped.
	if err := bucket.wait(context.Background(), 12500); !errors.Is(err, errRateLimited) {
		t.Fatalf("third queued waiter = %v, want errRateLimited", err)
	}
}

func TestTokenBucketUnlimitedAndInPlaceRateChanges(t *testing.T) {
	bucket, clock := fakeClockBucket()
	// No limit configured: never waits, whatever the size.
	mustAdmit(t, bucket, clock, 10_000_000, 0)

	bucket.setLimitMbps(8) // 1,000,000 B/s
	mustAdmit(t, bucket, clock, 1_000_000, 0)
	// Raising the limit keeps the (empty) balance and only changes the rate:
	// 200,000 bytes at 2,000,000 B/s take 100 ms.
	bucket.setLimitMbps(16)
	if got := bucket.limitMbps(); got != 16 {
		t.Fatalf("limit after update = %v Mbps", got)
	}
	mustAdmit(t, bucket, clock, 200000, 100*time.Millisecond)
	// Lowering the limit clamps a full balance to the new, smaller burst.
	clock.advance(5 * time.Second)
	bucket.setLimitMbps(1)
	mustAdmit(t, bucket, clock, 125000, 0)
	if err := bucket.wait(context.Background(), 125000); !errors.Is(err, errRateLimited) {
		t.Fatalf("balance was not clamped to the new burst: %v", err)
	}
	// 0 removes the limit entirely.
	bucket.setLimitMbps(0)
	mustAdmit(t, bucket, clock, 10_000_000, 0)
	// Re-enabling starts from a full burst again.
	bucket.setLimitMbps(1)
	mustAdmit(t, bucket, clock, 125000, 0)
}

func TestTokenBucketCancelledWaitHandsTokensBack(t *testing.T) {
	bucket, clock := fakeClockBucket()
	bucket.setLimitMbps(1)
	mustAdmit(t, bucket, clock, 125000, 0)
	bucket.sleep = func(context.Context, time.Duration) error { return context.Canceled }
	if err := bucket.wait(context.Background(), 25000); !errors.Is(err, context.Canceled) {
		t.Fatalf("cancelled wait = %v", err)
	}
	// The cancelled reservation was returned, so the same request still
	// needs only 200 ms rather than 400 ms.
	bucket.sleep = clock.sleep
	mustAdmit(t, bucket, clock, 25000, 200*time.Millisecond)
}

func TestSleepContextHonoursCancellation(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	started := time.Now()
	if err := sleepContext(ctx, time.Hour); !errors.Is(err, context.Canceled) {
		t.Fatalf("sleepContext = %v", err)
	}
	if time.Since(started) > time.Second {
		t.Fatal("cancelled sleep did not return promptly")
	}
}

func TestMeterRegistrySharesMeterAndAppliesLimits(t *testing.T) {
	limits := map[int64]float64{170: 100}
	registry := newMeterRegistry(func(forwardID int64) float64 { return limits[forwardID] })
	first := registry.forward(170)
	if registry.forward(170) != first {
		t.Fatal("two sessions of one forward got different meters")
	}
	if first.toTarget.limitMbps() != 100 || first.fromTarget.limitMbps() != 100 {
		t.Fatal("a new meter did not pick up the configured limit")
	}
	other := registry.forward(181)
	if other.toTarget.limited.Load() {
		t.Fatal("a forward without a limit was limited")
	}
	registry.applyLimits(&sidecarConfig{forwards: map[int64]forwardBinding{
		170: {forwardID: 170, rateLimitMbps: 0},
		181: {forwardID: 181, rateLimitMbps: 50},
	}})
	if first.toTarget.limited.Load() || other.fromTarget.limitMbps() != 50 {
		t.Fatal("applyLimits did not update the existing buckets in place")
	}
	first.countToTarget(10)
	first.countFromTarget(7)
	first.countRateLimitedDrop()
	usage := registry.usage()
	if usage[170] != (forwardUsage{ToTargetBytes: 10, FromTargetBytes: 7, ToTargetPackets: 1, FromTargetPackets: 1, RateLimitedDrops: 1}) {
		t.Fatalf("usage[170] = %+v", usage[170])
	}
	if usage[181] != (forwardUsage{}) {
		t.Fatalf("usage[181] = %+v", usage[181])
	}
}

func waitForUsage(t *testing.T, server *sidecarServer, forwardID int64, want forwardUsage) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	var got forwardUsage
	for time.Now().Before(deadline) {
		got = server.meters.usage()[forwardID]
		if got == want {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatalf("usage[%d] = %+v, want %+v", forwardID, got, want)
}

func sizedReplyRequest(size int) []byte {
	request := []byte{'S', 0, 0}
	binary.BigEndian.PutUint16(request[1:], uint16(size))
	return request
}

func TestMeterCountsRawRelayBothDirectionsAcrossSessions(t *testing.T) {
	echo := startEchoServer(t)
	server, address := fixtureServer(t, echo, nil)
	first := dialCarrier(t, address, sessionTag(21))
	first.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	if ack := decodeAck(t, first.mustRead(t, 3*time.Second).payload); ack["ok"] != true {
		t.Fatal(ack)
	}
	first.send(t, msgData, 1, []byte("Eping"))
	if got := string(first.mustReadUDPData(t, 3*time.Second).payload); got != "ping" {
		t.Fatalf("echo = %q", got)
	}
	first.send(t, msgData, 2, sizedReplyRequest(100))
	if got := first.mustReadUDPData(t, 3*time.Second).payload; len(got) != 100 {
		t.Fatalf("sized reply = %d bytes", len(got))
	}
	// Payload bytes only: 5 + 3 bytes out, 4 + 100 bytes back.
	waitForUsage(t, server, 170, forwardUsage{ToTargetBytes: 8, ToTargetPackets: 2, FromTargetBytes: 104, FromTargetPackets: 2})

	// A second session of the same forward adds to the same counters.
	second := dialCarrier(t, address, sessionTag(22))
	second.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	if ack := decodeAck(t, second.mustRead(t, 3*time.Second).payload); ack["ok"] != true {
		t.Fatal(ack)
	}
	second.send(t, msgData, 1, []byte("Exy"))
	if got := string(second.mustReadUDPData(t, 3*time.Second).payload); got != "xy" {
		t.Fatalf("echo = %q", got)
	}
	waitForUsage(t, server, 170, forwardUsage{ToTargetBytes: 11, ToTargetPackets: 3, FromTargetBytes: 106, FromTargetPackets: 3})
}

func TestMeterCountsReliableRelayBothDirections(t *testing.T) {
	echo := startEchoServer(t)
	server, address := fixtureServer(t, echo, func(c *sidecarConfig) { c.sessionGraceSeconds = 10 })
	carrier := dialCarrier(t, address, randomSession(t))
	carrier.send(t, msgHello, 0, reliableHelloFor(t, echo.address()))
	if ack := decodeAck(t, carrier.mustRead(t, time.Second).payload); ack["delivery_mode"] != reliableDatagramMode {
		t.Fatal(ack)
	}
	carrier.send(t, msgData, 1, []byte("Eone"))
	carrier.send(t, msgData, 1, []byte("Eone")) // a replay is deduplicated and not counted again
	carrier.send(t, msgData, 2, []byte("Etwo!"))
	seen := map[uint64]string{}
	for i := 0; i < 12 && len(seen) < 2; i++ {
		f := carrier.mustRead(t, time.Second)
		if f.msgType == msgUDPData {
			seen[f.seq] = string(f.payload)
		}
	}
	if seen[1] != "one" || seen[2] != "two!" {
		t.Fatalf("reliable replies = %v", seen)
	}
	waitForUsage(t, server, 170, forwardUsage{ToTargetBytes: 9, ToTargetPackets: 2, FromTargetBytes: 7, FromTargetPackets: 2})
}

func TestRateLimitDropsClientToTargetDatagramsAndCountsThem(t *testing.T) {
	echo := startEchoServer(t)
	server, address := fixtureServer(t, echo, func(c *sidecarConfig) {
		binding := c.forwards[170]
		binding.rateLimitMbps = 0.01 // 1,250 bytes/s, 1,250 byte burst
		c.forwards[170] = binding
	})
	meter := server.meters.forward(170)
	if meter.toTarget.limitMbps() != 0.01 {
		t.Fatalf("meter limit = %v", meter.toTarget.limitMbps())
	}
	carrier := dialCarrier(t, address, sessionTag(23))
	carrier.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	if ack := decodeAck(t, carrier.mustRead(t, 3*time.Second).payload); ack["ok"] != true {
		t.Fatal(ack)
	}
	payload := append([]byte("E"), []byte(strings.Repeat("a", 999))...)
	carrier.send(t, msgData, 1, payload)
	if got := carrier.mustReadUDPData(t, 3*time.Second).payload; len(got) != 999 {
		t.Fatalf("first datagram echoed %d bytes", len(got))
	}
	// The burst is spent; the next 1,000 bytes would need ~750 ms: dropped
	// without blocking the carrier for longer than the 250 ms budget.
	carrier.send(t, msgData, 2, payload)
	waitForUsage(t, server, 170, forwardUsage{ToTargetBytes: 1000, ToTargetPackets: 1, FromTargetBytes: 999, FromTargetPackets: 1, RateLimitedDrops: 1})
	if echo.receivedCount() != 1 {
		t.Fatalf("a rate-limited datagram reached the target: %d", echo.receivedCount())
	}
}

func TestRateLimitDropsTargetToClientDatagramsWithoutFailingSession(t *testing.T) {
	config := &sidecarConfig{queuePackets: 4, framePayloadMax: maxDatagram,
		forwards: map[int64]forwardBinding{170: {forwardID: 170, rateLimitMbps: 0.01}}}
	server := newSidecarServer(newConfigStore("", config), newSidecarLogger("ERROR", io.Discard))
	for _, reliable := range []bool{false, true} {
		session := newUoTSession(config, server, sessionTag(24), config.forwards[170], "127.0.0.1:1", maxDatagram)
		if reliable {
			session.reliable = newReliableDatagrams()
		}
		bucket, _ := fakeClockBucket()
		bucket.setLimitMbps(0.01)
		session.meter = newForwardMeter()
		session.meter.fromTarget = bucket
		if !session.enqueueUDP(make([]byte, 1000)) {
			t.Fatalf("reliable=%t: first datagram within the burst was refused", reliable)
		}
		// The policy drop reports "handled" so a reliable reader keeps going.
		if !session.enqueueUDP(make([]byte, 1000)) {
			t.Fatalf("reliable=%t: a rate-limited drop was reported as an admission failure", reliable)
		}
		if session.isClosed() || session.ctx.Err() != nil {
			t.Fatalf("reliable=%t: a rate-limited drop closed the session", reliable)
		}
		got := session.meter.usage()
		if got != (forwardUsage{FromTargetBytes: 1000, FromTargetPackets: 1, RateLimitedDrops: 1}) {
			t.Fatalf("reliable=%t: usage = %+v", reliable, got)
		}
		if !reliable && len(session.outbound) != 1 {
			t.Fatalf("raw queue holds %d frames, want 1", len(session.outbound))
		}
		session.close()
	}
}
