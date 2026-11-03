package main

import (
	"errors"
	"net"
	"syscall"
	"time"
)

var errSendQueueUnsupported = errors.New("send queue size unavailable")

// sendAckProgress measures write progress by what the peer acknowledged
// rather than by what the kernel accepted. After a peer stops reading, Linux
// and macOS still accept a few kilobytes on each fresh write, which used to
// re-arm the stall bound every time and stretched a 10 s bound to 20-30 s.
// Bytes leaving the unacknowledged send queue are the only real progress.
type sendAckProgress struct {
	raw          syscall.RawConn
	bound        time.Duration
	lastProgress time.Time
	queued       int
}

// newSendAckProgress returns nil when the platform cannot report the send
// queue; callers then keep counting accepted bytes as progress.
func newSendAckProgress(connection net.Conn) *sendAckProgress {
	raw := rawTCPConn(connection)
	if raw == nil {
		return nil
	}
	progress := &sendAckProgress{raw: raw, lastProgress: time.Now()}
	queued, err := progress.read()
	if err != nil {
		return nil
	}
	progress.queued = queued
	return progress
}

func (progress *sendAckProgress) read() (int, error) {
	var queued int
	var queueErr error
	if err := progress.raw.Control(func(fd uintptr) {
		queued, queueErr = sendQueuedBytes(fd)
	}); err != nil {
		return 0, err
	}
	return queued, queueErr
}

// deadline bounds the next attempt by the last acknowledged progress instead
// of by the attempt's start.
func (progress *sendAckProgress) deadline(next time.Time) time.Time {
	if progress.bound == 0 {
		progress.bound = time.Until(next)
	}
	return progress.lastProgress.Add(progress.bound)
}

// observe accounts an attempt that handed written bytes to the kernel. It
// reports false when the queue can no longer be read.
func (progress *sendAckProgress) observe(written int) bool {
	queued, err := progress.read()
	if err != nil {
		return false
	}
	if progress.queued+written-queued > 0 {
		progress.lastProgress = time.Now()
	}
	progress.queued = queued
	return true
}

func (progress *sendAckProgress) expired() bool {
	return time.Since(progress.lastProgress) >= progress.bound
}

// rawTCPConn unwraps the stream wrappers the relay path stacks on the provider
// socket and returns its raw descriptor, or nil.
func rawTCPConn(connection net.Conn) syscall.RawConn {
	for depth := 0; depth < 8 && connection != nil; depth++ {
		switch wrapped := connection.(type) {
		case *net.TCPConn:
			raw, err := wrapped.SyscallConn()
			if err != nil {
				return nil
			}
			return raw
		case *bufferedConn:
			connection = wrapped.Conn
		case *firstFlightConn:
			connection = wrapped.Conn
		case *tcpFirstReadNotifier:
			connection = wrapped.Conn
		case *tcpFirstFlightCapture:
			connection = wrapped.Conn
		default:
			return nil
		}
	}
	return nil
}
