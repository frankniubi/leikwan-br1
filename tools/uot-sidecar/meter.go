package main

// Per-forward UDP metering and speed limiting.
//
// Every forward id gets one forwardMeter for the life of the process. All
// sessions of that forward share it, so the counters are the forward's total
// and the speed limit caps the forward as a whole rather than each session.
// Sessions hold the *forwardMeter directly: the hot path is a handful of
// atomic adds and, only when a limit is configured, one short mutex section in
// the token bucket. The registry map is touched once per new session.
//
// Meters are never deleted. A forward removed from the configuration keeps its
// final counters until the process restarts, which is what the usage file
// contract (docs/plans/2026-10-21-external-uot-sidecar-enforcement.md, C4)
// requires so the panel can still collect the last bytes of a revoked forward.

import (
	"context"
	"errors"
	"sync"
	"sync/atomic"
	"time"
)

// rateLimitMaxWait is the longest a datagram waits for tokens. Anything that
// would wait longer is dropped and counted as rate_limited_drops.
const rateLimitMaxWait = 250 * time.Millisecond

// errRateLimited reports a datagram the limiter refused to wait for.
var errRateLimited = errors.New("rate limited")

// tokenBucket is a byte-denominated token bucket with a one second burst.
//
// A caller that has to wait reserves its tokens first (the balance may go
// negative) and then sleeps, so concurrent waiters queue up behind each other
// in arrival order instead of all waking at the same instant. now and sleep
// are injectable so tests can drive the bucket with a fake clock.
type tokenBucket struct {
	// limited is the lock-free fast path: an unlimited bucket never takes mu.
	limited atomic.Bool

	mu     sync.Mutex
	rate   float64 // bytes per second; 0 means unlimited
	burst  float64 // bytes; always one second worth of rate
	tokens float64
	last   time.Time

	now   func() time.Time
	sleep func(context.Context, time.Duration) error
}

func newTokenBucket() *tokenBucket {
	return &tokenBucket{now: time.Now, sleep: sleepContext}
}

// sleepContext waits for d or until ctx ends, whichever is first.
func sleepContext(ctx context.Context, d time.Duration) error {
	timer := time.NewTimer(d)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-timer.C:
		return nil
	}
}

// setLimitMbps changes the rate in place. 1 Mbps = 1,000,000 bit/s, so the
// bucket refills at mbps*1e6/8 bytes per second. 0 removes the limit. A bucket
// that becomes limited starts full; a bucket whose rate changes keeps its
// balance (settled at the old rate first, then clamped to the new burst).
func (b *tokenBucket) setLimitMbps(mbps float64) {
	rate := mbps * 1e6 / 8
	b.mu.Lock()
	defer b.mu.Unlock()
	if rate <= 0 {
		b.rate, b.burst, b.tokens = 0, 0, 0
		b.limited.Store(false)
		return
	}
	now := b.now()
	if b.rate > 0 {
		b.refillLocked(now)
		b.rate, b.burst = rate, rate
		if b.tokens > b.burst {
			b.tokens = b.burst
		}
	} else {
		b.rate, b.burst, b.tokens = rate, rate, rate
	}
	b.last = now
	b.limited.Store(true)
}

func (b *tokenBucket) limitMbps() float64 {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.rate * 8 / 1e6
}

func (b *tokenBucket) refillLocked(now time.Time) {
	if elapsed := now.Sub(b.last); elapsed > 0 {
		b.tokens += elapsed.Seconds() * b.rate
		if b.tokens > b.burst {
			b.tokens = b.burst
		}
	}
	b.last = now
}

// wait admits n bytes. It returns nil immediately when tokens are available,
// sleeps when the deficit can be covered within rateLimitMaxWait, and returns
// errRateLimited (consuming nothing) when it cannot. A context error means the
// session ended while waiting; the reservation is handed back.
func (b *tokenBucket) wait(ctx context.Context, n int) error {
	if !b.limited.Load() {
		return nil
	}
	need := float64(n)
	b.mu.Lock()
	if b.rate <= 0 {
		b.mu.Unlock()
		return nil
	}
	b.refillLocked(b.now())
	if b.tokens >= need {
		b.tokens -= need
		b.mu.Unlock()
		return nil
	}
	delay := time.Duration((need - b.tokens) / b.rate * float64(time.Second))
	if delay > rateLimitMaxWait {
		b.mu.Unlock()
		return errRateLimited
	}
	b.tokens -= need
	sleep := b.sleep
	b.mu.Unlock()
	if err := sleep(ctx, delay); err != nil {
		b.mu.Lock()
		b.tokens += need
		if b.tokens > b.burst {
			b.tokens = b.burst
		}
		b.mu.Unlock()
		return err
	}
	return nil
}

// forwardUsage is one forward's entry in the usage file. Field order is the
// order the contract documents.
type forwardUsage struct {
	ToTargetBytes     uint64 `json:"to_target_bytes"`
	FromTargetBytes   uint64 `json:"from_target_bytes"`
	ToTargetPackets   uint64 `json:"to_target_packets"`
	FromTargetPackets uint64 `json:"from_target_packets"`
	RateLimitedDrops  uint64 `json:"rate_limited_drops"`
}

// forwardMeter is the shared accounting and limiting state of one forward.
type forwardMeter struct {
	toTargetBytes     atomic.Uint64
	toTargetPackets   atomic.Uint64
	fromTargetBytes   atomic.Uint64
	fromTargetPackets atomic.Uint64
	rateLimitedDrops  atomic.Uint64

	// toTarget limits client -> target, fromTarget limits target -> client.
	toTarget   *tokenBucket
	fromTarget *tokenBucket
}

func newForwardMeter() *forwardMeter {
	return &forwardMeter{toTarget: newTokenBucket(), fromTarget: newTokenBucket()}
}

func (m *forwardMeter) setLimitMbps(mbps float64) {
	m.toTarget.setLimitMbps(mbps)
	m.fromTarget.setLimitMbps(mbps)
}

// countToTarget records one datagram written to the target socket.
func (m *forwardMeter) countToTarget(payloadBytes int) {
	m.toTargetBytes.Add(uint64(payloadBytes))
	m.toTargetPackets.Add(1)
}

// countFromTarget records one target datagram accepted for delivery.
func (m *forwardMeter) countFromTarget(payloadBytes int) {
	m.fromTargetBytes.Add(uint64(payloadBytes))
	m.fromTargetPackets.Add(1)
}

func (m *forwardMeter) countRateLimitedDrop() {
	m.rateLimitedDrops.Add(1)
}

func (m *forwardMeter) usage() forwardUsage {
	return forwardUsage{
		ToTargetBytes:     m.toTargetBytes.Load(),
		FromTargetBytes:   m.fromTargetBytes.Load(),
		ToTargetPackets:   m.toTargetPackets.Load(),
		FromTargetPackets: m.fromTargetPackets.Load(),
		RateLimitedDrops:  m.rateLimitedDrops.Load(),
	}
}

// meterRegistry owns every forwardMeter of the process.
type meterRegistry struct {
	mu     sync.Mutex
	meters map[int64]*forwardMeter
	// limitFor returns the forward's rate limit in the CURRENT configuration
	// snapshot. It is called with mu held, which is what makes a meter created
	// concurrently with a reload end up with the reloaded limit: either the
	// creation reads the new snapshot, or applyLimits runs after it and sees the
	// new meter.
	limitFor func(forwardID int64) float64
}

func newMeterRegistry(limitFor func(forwardID int64) float64) *meterRegistry {
	return &meterRegistry{meters: make(map[int64]*forwardMeter), limitFor: limitFor}
}

// forward returns the shared meter of a forward, creating it on first use.
func (r *meterRegistry) forward(forwardID int64) *forwardMeter {
	r.mu.Lock()
	defer r.mu.Unlock()
	if meter, present := r.meters[forwardID]; present {
		return meter
	}
	meter := newForwardMeter()
	if r.limitFor != nil {
		meter.setLimitMbps(r.limitFor(forwardID))
	}
	r.meters[forwardID] = meter
	return meter
}

// applyLimits updates every existing meter's buckets in place from a snapshot.
// A forward absent from the snapshot becomes unlimited; its sessions are being
// revoked anyway and a later re-add sets the limit again.
func (r *meterRegistry) applyLimits(snapshot *sidecarConfig) {
	r.mu.Lock()
	defer r.mu.Unlock()
	for forwardID, meter := range r.meters {
		meter.setLimitMbps(snapshot.forwards[forwardID].rateLimitMbps)
	}
}

// usage returns a point-in-time copy of every forward's counters.
func (r *meterRegistry) usage() map[int64]forwardUsage {
	r.mu.Lock()
	meters := make(map[int64]*forwardMeter, len(r.meters))
	for forwardID, meter := range r.meters {
		meters[forwardID] = meter
	}
	r.mu.Unlock()
	out := make(map[int64]forwardUsage, len(meters))
	for forwardID, meter := range meters {
		out[forwardID] = meter.usage()
	}
	return out
}
