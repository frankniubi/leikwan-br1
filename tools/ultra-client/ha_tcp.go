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

func relayTCP(ctx context.Context, local, remote net.Conn, limiter *PolicyLimiter, status *StatusWriter, forward int, generation, selection string) {
	defer local.Close()
	defer remote.Close()
	type result struct {
		direction string
		err       error
	}
	done := make(chan result, 2)
	started := time.Now()
	go func() { done <- result{"upstream", copyOutbound(remote, local, limiter, status)} }()
	go func() { done <- result{"downstream", copyInbound(local, remote, limiter, status)} }()
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
			status.RecordHAEvent("tcp", haErrorClass(ctx.Err()), generation)
			log.Printf("tcp stream closed forward_id=%d direction=runtime reason=canceled generation=%s selection_ref=%s duration_ms=%d", forward, generation, selection, time.Since(started).Milliseconds())
			return
		case r := <-done:
			received++
			log.Printf("tcp stream ended forward_id=%d direction=%s reason=%s generation=%s selection_ref=%s duration_ms=%d", forward, r.direction, haErrorClass(r.err), generation, selection, time.Since(started).Milliseconds())
			status.RecordHAEvent("tcp", haErrorClass(r.err), generation)
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
}
