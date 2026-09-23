package main

import (
	"context"
	"errors"
	"io"
	"log"
	"net"
	"runtime"
	"sync/atomic"
	"syscall"
	"time"
)

func haErrorClass(err error) string {
	switch {
	case err == nil:
		return "complete"
	case errors.Is(err, io.EOF):
		return "eof"
	case errors.Is(err, errUDPReceiveTimeout):
		return "keepalive_timeout"
	case errors.Is(err, errDatagramUnusable):
		// A measured payload-ceiling verdict is not an I/O failure; keep the
		// dedicated name visible in ha_events and logs.
		return udpPayloadCeilingUnusableReason
	case errors.Is(err, context.Canceled):
		return "canceled"
	case errors.Is(err, ErrPolicyExpired):
		return "policy_expired"
	case errors.Is(err, net.ErrClosed):
		return "closed"
	case isTCPReset(err):
		return "reset"
	case errors.Is(err, syscall.ECONNABORTED) ||
		(runtime.GOOS == "windows" && errors.Is(err, syscall.Errno(10053))):
		// WSAECONNABORTED: the local stack aborted the connection (for
		// example a retransmission timeout or the local peer's abortive close).
		return "aborted"
	case errors.Is(err, syscall.ECONNREFUSED):
		return "refused"
	case errors.Is(err, syscall.EPIPE):
		return "broken_pipe"
	case errors.Is(err, ErrQuotaExhausted):
		return "quota_exhausted"
	case errors.Is(err, errStreamCommandRejected):
		// The provider relay rejected the stream command. Classify it
		// explicitly so it is not indistinguishable from a generic io_error in
		// ha_events, and so it never counts as path-loss evidence.
		return "target_connect_rejected"
	}
	var n net.Error
	if errors.As(err, &n) && n.Timeout() {
		return "timeout"
	}
	return "io_error"
}

// isTCPReset recognises an explicit connection reset from either side. On
// Windows net.OpError wraps WSAECONNRESET (10054) rather than the POSIX-style
// syscall.ECONNRESET constant, so both are checked.
func isTCPReset(err error) bool {
	return errors.Is(err, syscall.ECONNRESET) ||
		(runtime.GOOS == "windows" && errors.Is(err, syscall.Errno(10054)))
}

func closeTCPWrite(c net.Conn) error {
	if b, ok := c.(*bufferedConn); ok {
		return closeTCPWrite(b.Conn)
	}
	if h, ok := c.(interface{ CloseWrite() error }); ok {
		return h.CloseWrite()
	}
	return errors.New("connection cannot half close")
}

// relayIdentity is the per-stream slice of the selected lease the health logic
// needs. The credentials stay in the lease: only the opaque identity, the
// path key (a hash of endpoint+user) and the generation travel with a stream.
type relayIdentity struct {
	forward      int
	generation   string
	selection    string
	pathKey      string
	candidateKey string
	leaseHash    string
	targetHash   string
	firstFlight  TCPFirstFlightConfig
	target       string
	// replay, when set, may reopen the stream once on another authorized
	// candidate after the relay explicitly refused to open the target. nil
	// disables replay (every stream after the first attempt, and tests).
	replay tcpReplayDial
}

// haDeadStreamMaxAge bounds how young a stream must be for its silence to count
// as evidence about the path instead of a normally short request.
const haDeadStreamMaxAge = 3 * time.Second

// haRelayOrderEpsilon tolerates the scheduling skew between the two copy
// goroutines that observe the ends of one stream. The ordering itself has not
// been seen to invert: across 200 silent-relay streams the remote end was
// observed first every time (65 exact ties, the rest a few microseconds to tens
// of milliseconds early). 25ms covers that skew and stays well below the ~60ms
// a healthy but still-open provider took to close after a local abort in the
// 2026-09-14 experiment, which is the gap this signal has to read as "the local
// side hung up first".
const haRelayOrderEpsilon = 25 * time.Millisecond

// relayStreamOrder records when each direction of a relayed stream stopped, as
// observed by relayTCP. It is the only evidence that can tell a relay which gave
// up on the stream apart from a local client that hung up first.
type relayStreamOrder struct {
	upstreamEnded   time.Time // the local -> remote copy returned
	downstreamEnded time.Time // the remote -> local copy returned
	remoteReadError error     // excludes local-write and policy failures
	upstreamOrder   uint64    // monotonic observation order, independent of clock precision
	downstreamOrder uint64
}

// remoteStoppedFirst reports whether the remote -> local read ended no later
// than the local -> remote read did (within haRelayOrderEpsilon): the relay
// stopped the stream first and any local hang-up is a consequence of that. It
// is false when the local side ended the stream first, and false when the
// ordering was never measured.
func (o relayStreamOrder) remoteStoppedFirst() bool {
	// Strict observed ordering: a tie, or a local end even microseconds earlier,
	// is ambiguous rather than evidence against the path.
	if o.upstreamOrder != 0 && o.downstreamOrder != 0 {
		return o.downstreamOrder < o.upstreamOrder
	}
	if o.upstreamEnded.IsZero() || o.downstreamEnded.IsZero() {
		return false
	}
	return o.downstreamEnded.Before(o.upstreamEnded)
}

// noteRelayOutcome records one finished stream for telemetry. A successful
// stream clears prior path-failure state. A protocol-silent stream is not a
// relay-health verdict: encrypted destinations may accept a request without
// returning application payload, and ordinary user streams do not carry enough
// protocol context to distinguish that from a broken target. Explicit TCP/UDP
// probes own availability decisions; the remote/local end ordering is logged
// for diagnosis only.
func noteRelayOutcome(store *PolicyStore, status *StatusWriter, ident relayIdentity, started time.Time, upstreamBytes, downstreamBytes int64, order ...relayStreamOrder) {
	if store == nil || ident.pathKey == "" {
		return
	}
	lifetime := time.Since(started)
	if downstreamBytes > 0 {
		store.ClearPathFailure("tcp", ident.pathKey)
		return
	}
	if lifetime > haDeadStreamMaxAge || upstreamBytes == 0 {
		// Nothing was asked of the relay, or the stream lived long enough that
		// silence is not yet evidence.
		return
	}
	// A zero-byte application response is not protocol-independent evidence
	// that the relay is dead: encrypted destinations may legitimately remain
	// silent, and an ordinary EOF after a complete request is normal.
	// Only a strict remote-first ordering combined with an explicit TCP reset on
	// the remote read escalates to this forward's local path avoidance. That
	// never becomes global candidate reputation, and no business payload is
	// replayed.
	remoteFirst := len(order) > 0 && order[0].remoteStoppedFirst()
	remoteReset := remoteFirst && isTCPReset(order[0].remoteReadError)
	isolated := false
	if remoteReset {
		isolated = store.FailSilentPath("tcp", ident.pathKey, ident.generation)
	}
	if status != nil {
		remoteReadReason := "unknown"
		if len(order) > 0 {
			remoteReadReason = haErrorClass(order[0].remoteReadError)
		}
		// A single classified outcome replaces the legacy generic "no_response"
		// event: it carries the remote evidence the panel needs to tell an
		// ordinary EOF from a remote reset, and whether this forward avoided
		// the local path. Recording both would double-count the outcome.
		status.RecordTCPOutcome(ident, upstreamBytes, downstreamBytes, remoteReadReason, remoteFirst, isolated)
	}
	log.Printf(
		"tcp stream had no downstream bytes forward_id=%d selection_ref=%s remote_stopped_first=%t remote_read_reset=%t remote_read_reason=%s lifetime_ms=%d upstream_bytes=%d local_path_avoided=%t global_scored=false",
		ident.forward, ident.selection, remoteFirst, remoteReset, func() string {
			if len(order) == 0 {
				return "unknown"
			}
			return haErrorClass(order[0].remoteReadError)
		}(), lifetime.Milliseconds(), upstreamBytes, isolated)
}

func relayTCP(ctx context.Context, local, remote net.Conn, limiter *PolicyLimiter, status *StatusWriter, store *PolicyStore, ident relayIdentity) {
	defer local.Close()
	var cleanups []func()
	defer func() {
		for _, cleanup := range cleanups {
			cleanup()
		}
	}()
	var prelude []byte
	for {
		next := relayTCPAttempt(ctx, local, remote, limiter, status, store, ident, prelude)
		clearBytes(prelude)
		if next.remote == nil {
			return
		}
		if next.cleanup != nil {
			cleanups = append(cleanups, next.cleanup)
		}
		log.Printf("tcp first flight replayed forward_id=%d reason=target_connect_rejected from_candidate_hash=%s to_candidate_hash=%s bytes=%d", ident.forward, ident.candidateKey, next.ident.candidateKey, len(next.payload))
		status.RecordHAEvent("tcp", "target_connect_rejected_replayed", next.ident.generation)
		remote, ident, prelude = next.remote, next.ident, next.payload
		// Replay at most once per local connection.
		ident.replay = nil
	}
}

// tcpReplayResult is a stream to continue on: a fresh relay connection whose
// target has not yet seen any byte of this local connection, and the first
// flight to send on it. A zero value ends the local connection.
type tcpReplayResult struct {
	remote  net.Conn
	ident   relayIdentity
	cleanup func()
	payload []byte
}

// relayTCPAttempt relays one provider stream. prelude, when not empty, is the
// first flight of the local connection that a previous relay refused before it
// opened the target: it is written first, ahead of anything read from local,
// and was already charged to the policy limiter when it was first read.
func relayTCPAttempt(ctx context.Context, local, remote net.Conn, limiter *PolicyLimiter, status *StatusWriter, store *PolicyStore, ident relayIdentity, prelude []byte) (replay tcpReplayResult) {
	flightCtx, cancel := context.WithCancel(ctx)
	defer cancel()
	defer func() {
		if replay.remote == nil {
			local.Close()
		}
	}()
	defer remote.Close()
	done := make(chan relayCopyResult, 2)
	started := time.Now()
	var upstreamBytes, downstreamBytes int64
	var order relayStreamOrder
	var observedOrder atomic.Uint64
	outbound := remote
	if ident.firstFlight.matches(ident.target, ident.candidateKey) {
		outbound = &firstFlightConn{
			Conn: remote, ctx: flightCtx, prefix: ident.firstFlight.PrefixBytes,
			delay: time.Duration(ident.firstFlight.DelayMillis) * time.Millisecond,
		}
		log.Printf("tcp first-flight compatibility forward_id=%d prefix_bytes=%d delay_ms=%d candidate_hash=%s target_hash=%s",
			ident.forward, ident.firstFlight.PrefixBytes, ident.firstFlight.DelayMillis, ident.candidateKey, ident.targetHash)
	}
	// Keep the first flight only while a replay could still be safe (see
	// tcpReplayEligible): the first downstream byte proves the target answered
	// and drops it.
	var capture *tcpFirstFlightCapture
	upstreamSource, downstreamSource := local, remote
	if ident.replay != nil && store != nil && ident.pathKey != "" {
		capture = &tcpFirstFlightCapture{Conn: local}
		upstreamSource = capture
		downstreamSource = &tcpFirstReadNotifier{Conn: remote, notify: capture.disarm}
		defer capture.disarm()
	}
	go func() {
		moved, err, stage := copyOutboundWithPrelude(outbound, upstreamSource, limiter, status, prelude)
		ordinal := observedOrder.Add(1)
		done <- relayCopyResult{direction: "upstream", stage: stage, err: err, bytes: moved, ended: time.Now(), order: ordinal}
	}()
	go func() {
		observed := copyInboundObserved(local, downstreamSource, limiter, status)
		ordinal := observedOrder.Add(1)
		done <- relayCopyResult{direction: "downstream", stage: observed.Stage(), err: observed.Err(), bytes: observed.WrittenBytes,
			readBytes: observed.ReadBytes, readError: observed.ReadError, ended: time.Now(), order: ordinal}
	}()
	record := func(r relayCopyResult, event bool) {
		if r.direction == "upstream" {
			upstreamBytes = r.bytes
			order.upstreamEnded = r.ended
			order.upstreamOrder = r.order
		} else {
			downstreamBytes = r.bytes
			order.downstreamEnded = r.ended
			order.downstreamOrder = r.order
			order.remoteReadError = r.readError
		}
		log.Printf("tcp stream ended forward_id=%d direction=%s stage=%s reason=%s generation=%s selection_ref=%s duration_ms=%d bytes=%d candidate_hash=%s lease_hash=%s relay_hash=%s target_hash=%s", ident.forward, r.direction, r.stage, haErrorClass(r.err), ident.generation, ident.selection, time.Since(started).Milliseconds(), r.bytes, ident.candidateKey, ident.leaseHash, ident.pathKey, ident.targetHash)
		if event {
			status.RecordHAEvent("tcp", haErrorClass(r.err), ident.generation)
		}
	}
	received := 0
	upstreamDone := false
	for received < 2 {
		select {
		case <-ctx.Done():
			local.Close()
			remote.Close()
			for received < 2 {
				<-done
				received++
			}
			status.RecordHAEvent("tcp", haErrorClass(ctx.Err()), ident.generation)
			log.Printf("tcp stream closed forward_id=%d direction=runtime reason=canceled generation=%s selection_ref=%s duration_ms=%d", ident.forward, ident.generation, ident.selection, time.Since(started).Milliseconds())
			return tcpReplayResult{}
		case r := <-done:
			received++
			record(r, true)
			if r.direction == "upstream" {
				upstreamDone = true
			}
			if r.direction == "downstream" && !upstreamDone && capture != nil && ctx.Err() == nil &&
				tcpReplayEligible(r.readError, r.readBytes, r.bytes) {
				// Stop the upstream copy without closing the local socket: the
				// read deadline unblocks a pending local read (the capture has
				// already kept whatever it returned) and closing the refused
				// relay fails a pending write.
				if local.SetReadDeadline(time.Now()) == nil {
					cancel()
					remote.Close()
					up := <-done
					received++
					upstreamDone = true
					// This end was forced here; it is not a stream failure.
					record(up, false)
					_ = local.SetReadDeadline(time.Time{})
					payload, kept := capture.take()
					// A local half-close or a policy stop means this is not a
					// plain unanswered first flight any more.
					localEnded := up.stage == "local_read" && (up.err == nil || errors.Is(up.err, io.EOF))
					if kept && !localEnded && up.stage != "policy" && ctx.Err() == nil {
						next, nextIdent, cleanup, err := ident.replay(ctx, ident.pathKey)
						if err == nil {
							noteRelayOutcome(store, status, ident, started, upstreamBytes, downstreamBytes, order)
							return tcpReplayResult{remote: next, ident: nextIdent, cleanup: cleanup, payload: payload}
						}
						log.Printf("tcp first flight replay unavailable forward_id=%d candidate_hash=%s reason=%s", ident.forward, ident.candidateKey, haErrorClass(err))
					}
					clearBytes(payload)
					local.Close()
					continue
				}
			}
			if r.err == nil || errors.Is(r.err, io.EOF) {
				target := local
				if r.direction == "upstream" {
					target = remote
				}
				if closeTCPWrite(target) == nil {
					continue
				}
			}
			cancel()
			local.Close()
			remote.Close()
		}
	}
	noteRelayOutcome(store, status, ident, started, upstreamBytes, downstreamBytes, order)
	return tcpReplayResult{}
}

type relayCopyResult struct {
	direction string
	stage     string
	err       error
	bytes     int64
	readBytes int64
	readError error
	order     uint64
	// ended is stamped by the copy goroutine itself, so the ordering of the
	// two ends is not distorted by how relayTCP happens to receive them.
	ended time.Time
}
