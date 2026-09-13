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

// noteRelayOutcome feeds one finished stream back into candidate health.
//
// A relay that accepts the connection and then returns not a single byte does
// that to every flow, but a zero-byte close is indistinguishable from a normal
// close if all we look at is the error: EOF is EOF. Without this signal the next
// dial walks straight back into the same dead path, which is how one dead
// candidate produced six minutes of one-second reconnect storms on 2026-09-13.
// Two such streams quarantine the path exactly like a failed dial does, and any
// stream that actually receives data clears the record.
func noteRelayOutcome(store *PolicyStore, status *StatusWriter, ident relayIdentity, started time.Time, upstreamBytes, downstreamBytes int64) {
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
	}
	done := make(chan result, 2)
	started := time.Now()
	var upstreamBytes, downstreamBytes int64
	go func() {
		moved, err := copyOutbound(remote, local, limiter, status)
		done <- result{"upstream", err, moved}
	}()
	go func() {
		moved, err := copyInbound(local, remote, limiter, status)
		done <- result{"downstream", err, moved}
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
			} else {
				downstreamBytes = r.bytes
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
	noteRelayOutcome(store, status, ident, started, upstreamBytes, downstreamBytes)
}
