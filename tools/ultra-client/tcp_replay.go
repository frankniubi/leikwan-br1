package main

import (
	"context"
	"errors"
	"fmt"
	"log"
	"net"
	"sync"
	"time"
)

// First-response replay for HA TCP.
//
// A stream that already sent its first upstream bytes may only be moved to
// another candidate when those bytes provably never reached the target.
// Silence is not that proof: the Ultra relay dialect confirms the target
// connection in its prelude, before any caller byte is written, and the LeiGod
// dialect opens the target on the first payload and may or may not send a
// confirmation frame. An EOF or reset with zero downstream bytes therefore
// cannot tell "the target never saw the request" from "the target processed a
// non-idempotent request and the answer was lost", and such a stream is never
// replayed (the 2026-09-20 investigation reached the same rule).
//
// The one unambiguous case is the relay's own "target connect failed" frame
// (errStreamCommandRejected, a LeiGod command=1 final with status 0) arriving
// before any downstream byte: the relay reports that it never opened the
// target, so nothing of the first flight was delivered anywhere. Only then is
// the buffered first flight (at most tcpReplayMaxBytes, and only while the
// application has not half-closed) sent once on the next healthy authorized
// candidate, excluding the refused path.
//
// First-flight compatibility (tcp_first_flight.go) promises that bytes it
// withheld on a stream are never written to that stream later. Replay keeps
// that promise: the refused relay connection is closed and receives nothing
// more; the flight goes to a new relay connection, where the first-flight
// rule is evaluated afresh for the new candidate and applied to the replayed
// flight as that stream's first write.
const tcpReplayMaxBytes = 64 * 1024

// tcpReplayDial reopens the stream's target on an authorized candidate other
// than excludedPathKey. The cleanup wipes the new stream's lease snapshot.
type tcpReplayDial func(ctx context.Context, excludedPathKey string) (net.Conn, relayIdentity, func(), error)

// tcpReplayEligible reports whether the downstream end of a stream proves that
// its target never received the first flight.
func tcpReplayEligible(readError error, readBytes, writtenBytes int64) bool {
	return readBytes == 0 && writtenBytes == 0 && errors.Is(readError, errStreamCommandRejected)
}

// tcpFirstFlightCapture keeps the bytes the upstream copy read from the local
// application, bounded by tcpReplayMaxBytes, until it is disarmed.
type tcpFirstFlightCapture struct {
	net.Conn
	mu       sync.Mutex
	buffer   []byte
	overflow bool
	disarmed bool
}

func (c *tcpFirstFlightCapture) Read(p []byte) (int, error) {
	n, err := c.Conn.Read(p)
	if n > 0 {
		c.record(p[:n])
	}
	return n, err
}

func (c *tcpFirstFlightCapture) record(p []byte) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.disarmed || c.overflow {
		return
	}
	if len(c.buffer)+len(p) > tcpReplayMaxBytes {
		c.overflow = true
		clearBytes(c.buffer)
		c.buffer = nil
		return
	}
	c.buffer = append(c.buffer, p...)
}

// disarm drops the captured bytes: the target answered, or the stream ended.
func (c *tcpFirstFlightCapture) disarm() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.disarmed = true
	clearBytes(c.buffer)
	c.buffer = nil
}

// take hands the captured flight to the caller and disarms the capture. It
// reports false when the flight was too large or the capture was disarmed.
func (c *tcpFirstFlightCapture) take() ([]byte, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.disarmed || c.overflow {
		return nil, false
	}
	payload := c.buffer
	c.buffer = nil
	c.disarmed = true
	return payload, true
}

// tcpFirstReadNotifier calls notify once, when the first downstream byte is
// read from the relay.
type tcpFirstReadNotifier struct {
	net.Conn
	once   sync.Once
	notify func()
}

func (c *tcpFirstReadNotifier) Read(p []byte) (int, error) {
	n, err := c.Conn.Read(p)
	if n > 0 {
		c.once.Do(c.notify)
	}
	return n, err
}

// copyOutboundWithPrelude writes a replayed first flight before the ordinary
// upstream copy. The prelude was charged to the limiter and to usage when it
// was first read from the application, so it is not charged again.
//
// deadline, when set, bounds each write to the relay by write progress (see
// writeAllProgress); the prelude is held to the same bound.
func copyOutboundWithPrelude(destination net.Conn, source net.Conn, limiter *PolicyLimiter, status *StatusWriter, prelude []byte, deadline progressWriteDeadline) (int64, error, string) {
	var total int64
	if len(prelude) > 0 {
		if err := writeXorProgress(destination, prelude, deadline); err != nil {
			return 0, err, "remote_write"
		}
		total = int64(len(prelude))
	}
	moved, err, stage := copyOutboundStagedWithDeadline(destination, source, limiter, status, deadline)
	return total + moved, err, stage
}

// dialReplayCandidate opens host:port on the first authorized, healthy TCP
// candidate whose path is not excludedPathKey. A dial failure happens before
// any byte is written, so it moves on to the next candidate as setup does.
func dialReplayCandidate(ctx context.Context, s *PolicyStore, excludedPathKey, host string, port int, total time.Duration, dial candidateStreamDial) (net.Conn, RuntimeSnapshot, error) {
	if s == nil || dial == nil || excludedPathKey == "" {
		return nil, RuntimeSnapshot{}, errHACandidatesUnavailable
	}
	candidates := s.CandidateSnapshots("tcp")
	defer wipeSnapshots(candidates)
	if total <= 0 {
		total = 5 * time.Second
	}
	deadline := time.Now().Add(total)
	var last error
	for _, ss := range candidates {
		if haPathKey(ss.Lease) == excludedPathKey {
			continue
		}
		if !s.acceptsInFlight(ss) {
			return nil, RuntimeSnapshot{}, ErrPolicyExpired
		}
		budget := time.Until(deadline)
		if budget > 3*time.Second {
			budget = 3 * time.Second
		}
		if expiry := time.Until(ss.Lease.ExpiresAt); expiry < budget {
			budget = expiry
		}
		if budget <= 0 {
			break
		}
		connection, err := dialCandidateAttempt(ctx, ss.Lease, host, port, budget, dial)
		if ctx.Err() != nil {
			if connection != nil {
				connection.Close()
			}
			return nil, RuntimeSnapshot{}, ctx.Err()
		}
		if err == nil {
			if !s.acceptsInFlight(ss) {
				connection.Close()
				return nil, RuntimeSnapshot{}, ErrPolicyExpired
			}
			return connection, RuntimeSnapshot{Policy: ss.Policy, Lease: cloneHALease(ss.Lease)}, nil
		}
		if connection != nil {
			connection.Close()
		}
		s.FailCandidate("tcp", ss.Lease)
		log.Printf("tcp replay candidate failed forward_id=%d selection_ref=%s reason=%s candidate_hash=%s", ss.Policy.ForwardID, ss.Lease.SelectionRef, haErrorClass(err), ss.Lease.CandidateKeyHash)
		last = err
	}
	if last != nil {
		return nil, RuntimeSnapshot{}, fmt.Errorf("%w: %v", errHACandidatesUnavailable, last)
	}
	return nil, RuntimeSnapshot{}, errHACandidatesUnavailable
}

// tcpRelayIdentity is the per-stream identity serveTCP hands to relayTCP.
func tcpRelayIdentity(config Config, snapshot RuntimeSnapshot) relayIdentity {
	return relayIdentity{
		forward:      config.ForwardID,
		generation:   snapshot.Policy.PolicyGeneration,
		selection:    snapshot.Lease.SelectionRef,
		pathKey:      haPathKey(snapshot.Lease),
		candidateKey: snapshot.Lease.CandidateKeyHash,
		leaseHash:    snapshot.Lease.LeaseHash,
		targetHash:   tcpTargetHash(config.Target),
		firstFlight:  config.TCPFirstFlight,
		target:       config.Target,
		writeStall:   resolveTCPWriteStall(config),
	}
}

// tcpReplayDialerFor builds the replay dialer serveTCP attaches to each
// stream.
func tcpReplayDialerFor(config Config, store *PolicyStore, status *StatusWriter, host string, port int, dial candidateStreamDial) tcpReplayDial {
	timeout := time.Duration(config.ConnectTimeoutSecs) * time.Second
	return func(ctx context.Context, excludedPathKey string) (net.Conn, relayIdentity, func(), error) {
		started := time.Now()
		remote, snapshot, err := dialReplayCandidate(ctx, store, excludedPathKey, host, port, timeout, dial)
		if err != nil {
			return nil, relayIdentity{}, nil, err
		}
		status.UpdateTCPWithTierAndCandidate(snapshot.Lease.SelectionRef, snapshot.Lease.SelectionTier, snapshot.Lease.CandidateKeyHash, time.Since(started), snapshot.Lease.LeaseHash)
		ident := tcpRelayIdentity(config, snapshot)
		return remote, ident, func() { snapshot.Lease.wipe() }, nil
	}
}
