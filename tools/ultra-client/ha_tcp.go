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
	case errors.Is(err, errStreamCommandRejected):
		return "target_connect_rejected"
	case errors.Is(err, net.ErrClosed):
		return "closed"
	case isTCPReset(err):
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

func isTCPReset(err error) bool {
	// net.OpError wraps WSAECONNRESET (10054), not the POSIX-style
	// syscall.ECONNRESET constant, on Windows.
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
}

// haDeadStreamMaxAge bounds how young a stream must be for its silence to count
// as evidence about the path instead of a normally short request.
const haDeadStreamMaxAge = 3 * time.Second

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

// remoteStoppedFirst requires strict observed ordering. A tie or a local end
// even a few microseconds earlier is ambiguous, not evidence against the path.
func (o relayStreamOrder) remoteStoppedFirst() bool {
	if o.upstreamOrder != 0 && o.downstreamOrder != 0 {
		return o.downstreamOrder < o.upstreamOrder
	}
	if o.upstreamEnded.IsZero() || o.downstreamEnded.IsZero() {
		return false
	}
	return o.downstreamEnded.Before(o.upstreamEnded)
}

// noteRelayOutcome uses bytes read from the remote, not bytes delivered locally.
// Repeated remote-read resets can temporarily avoid this forward's TCP path.
// They are NOT global candidate-reputation evidence: the target itself can
// reject a request, and ordinary EOF is valid for protocol-silent applications.
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
		status.RecordTCPOutcome(ident, upstreamBytes, downstreamBytes, remoteReadReason, remoteFirst, isolated)
	}
	log.Printf(
		"tcp stream had no downstream bytes forward_id=%d selection_ref=%s remote_stopped_first=%t remote_read_reset=%t lifetime_ms=%d upstream_bytes=%d local_path_avoided=%t global_scored=false",
		ident.forward, ident.selection, remoteFirst, remoteReset, lifetime.Milliseconds(), upstreamBytes, isolated)
}

func relayTCP(ctx context.Context, local, remote net.Conn, limiter *PolicyLimiter, status *StatusWriter, store *PolicyStore, ident relayIdentity) {
	defer local.Close()
	defer remote.Close()
	type result struct {
		direction string
		err       error
		bytes     int64
		readBytes int64
		readError error
		order     uint64
		// ended is stamped by the copy goroutine itself, so the ordering of the
		// two ends is not distorted by how relayTCP happens to receive them.
		ended time.Time
	}
	done := make(chan result, 2)
	started := time.Now()
	var upstreamBytes, downstreamBytes int64
	var order relayStreamOrder
	var observedOrder atomic.Uint64
	go func() {
		moved, err := copyOutbound(remote, local, limiter, status)
		ordinal := observedOrder.Add(1)
		done <- result{direction: "upstream", err: err, bytes: moved, ended: time.Now(), order: ordinal}
	}()
	go func() {
		observed := copyInboundObserved(local, remote, limiter, status)
		ordinal := observedOrder.Add(1)
		done <- result{direction: "downstream", err: observed.Err(), bytes: observed.WrittenBytes,
			readBytes: observed.ReadBytes, readError: observed.ReadError, ended: time.Now(), order: ordinal}
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
				order.upstreamOrder = r.order
			} else {
				downstreamBytes = r.readBytes
				order.downstreamEnded = r.ended
				order.remoteReadError = r.readError
				order.downstreamOrder = r.order
			}
			log.Printf("tcp stream ended forward_id=%d direction=%s reason=%s generation=%s selection_ref=%s duration_ms=%d bytes=%d remote_read_bytes=%d remote_read_reason=%s", ident.forward, r.direction, haErrorClass(r.err), ident.generation, ident.selection, time.Since(started).Milliseconds(), r.bytes, r.readBytes, haErrorClass(r.readError))
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
