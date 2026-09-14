package main

import (
	"context"
	"errors"
	"io"
	"log"
	"net"
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
	case errors.Is(err, syscall.ECONNRESET):
		return "reset"
	case errors.Is(err, syscall.ECONNREFUSED):
		return "refused"
	case errors.Is(err, syscall.EPIPE):
		return "broken_pipe"
	case errors.Is(err, ErrQuotaExhausted):
		return "quota_exhausted"
	}
	var n net.Error
	if errors.As(err, &n) && n.Timeout() {
		return "timeout"
	}
	return "io_error"
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
}

// remoteStoppedFirst reports whether the remote -> local read ended no later
// than the local -> remote read did (within haRelayOrderEpsilon): the relay
// stopped the stream first and any local hang-up is a consequence of that. It
// is false when the local side ended the stream first, and false when the
// ordering was never measured.
func (o relayStreamOrder) remoteStoppedFirst() bool {
	if o.upstreamEnded.IsZero() || o.downstreamEnded.IsZero() {
		return false
	}
	return !o.downstreamEnded.After(o.upstreamEnded.Add(haRelayOrderEpsilon))
}

// noteRelayOutcome feeds one finished stream back into candidate health.
//
// A relay that accepts the connection and then returns not a single byte does
// that to every flow, but a zero-byte close is indistinguishable from a normal
// close if all we look at is the error: EOF is EOF. Without this signal the next
// dial walks straight back into the same dead path, which is how one dead
// candidate produced six minutes of one-second reconnect storms on 2026-09-13.
// Two such streams quarantine the path exactly like a failed dial does, and any
// stream that actually receives data clears the record.
//
// Zero downstream bytes are only evidence when the RELAY ended the stream. A
// local client that sends a request and then hangs up produces the identical
// shape - upstream bytes, no downstream bytes, a short life - and on 2026-09-14
// that shape quarantined healthy paths. A strike therefore also requires the
// ordering relayTCP measured: the remote -> local read must have stopped no
// later than the local -> remote read, so the remote stopped first and the local
// hang-up was a consequence of it.
//
// Residual ambiguity, deliberately not papered over: a relay (or the provider
// behind it) that answers our half-close with its own FIN within
// haRelayOrderEpsilon of it is indistinguishable from one that hung up first,
// because by then our FIN is already on the wire and the two ends are ordered
// only by microseconds. Such a stream still counts as a strike, which is the
// safe direction for the silent-relay failure this signal exists for, and one
// strike alone never isolates a path (pathIsolationMinStrikes).
//
// order is optional so that a caller which cannot observe the ordering - the
// unit tests that feed this function directly - keeps the byte-count-only
// behaviour; relayTCP always supplies it.
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
	if len(order) > 0 && !order[0].remoteStoppedFirst() {
		// The local side gave up on the stream first, so the relay was never
		// asked to answer and its silence says nothing about the path.
		return
	}
	if !store.FailSilentPath("tcp", ident.pathKey, ident.generation) {
		return
	}
	if status != nil {
		status.RecordCandidateFailure(ident.candidateKey, "tcp", "tcp_no_response", ident.leaseHash)
		status.RecordHAEvent("tcp", "no_response", ident.generation)
	}
	log.Printf(
		"tcp candidate quarantined forward_id=%d selection_ref=%s reason=no_downstream_bytes lifetime_ms=%d upstream_bytes=%d",
		ident.forward, ident.selection, lifetime.Milliseconds(), upstreamBytes)
}

func relayTCP(ctx context.Context, local, remote net.Conn, limiter *PolicyLimiter, status *StatusWriter, store *PolicyStore, ident relayIdentity) {
	defer local.Close()
	defer remote.Close()
	type result struct {
		direction string
		err       error
		bytes     int64
		// ended is stamped by the copy goroutine itself, so the ordering of the
		// two ends is not distorted by how relayTCP happens to receive them.
		ended time.Time
	}
	done := make(chan result, 2)
	started := time.Now()
	var upstreamBytes, downstreamBytes int64
	var order relayStreamOrder
	go func() {
		moved, err := copyOutbound(remote, local, limiter, status)
		done <- result{direction: "upstream", err: err, bytes: moved, ended: time.Now()}
	}()
	go func() {
		moved, err := copyInbound(local, remote, limiter, status)
		done <- result{direction: "downstream", err: err, bytes: moved, ended: time.Now()}
	}()
	received := 0
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
			return
		case r := <-done:
			received++
			if r.direction == "upstream" {
				upstreamBytes = r.bytes
				order.upstreamEnded = r.ended
			} else {
				downstreamBytes = r.bytes
				order.downstreamEnded = r.ended
			}
			log.Printf("tcp stream ended forward_id=%d direction=%s reason=%s generation=%s selection_ref=%s duration_ms=%d bytes=%d", ident.forward, r.direction, haErrorClass(r.err), ident.generation, ident.selection, time.Since(started).Milliseconds(), r.bytes)
			status.RecordHAEvent("tcp", haErrorClass(r.err), ident.generation)
			if r.err == nil || errors.Is(r.err, io.EOF) {
				target := local
				if r.direction == "upstream" {
					target = remote
				}
				if closeTCPWrite(target) == nil {
					continue
				}
			}
			local.Close()
			remote.Close()
		}
	}
	noteRelayOutcome(store, status, ident, started, upstreamBytes, downstreamBytes, order)
}
