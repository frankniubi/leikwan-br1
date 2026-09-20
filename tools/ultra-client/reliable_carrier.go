package main

import (
	"context"
	"encoding/binary"
	"errors"
	"net"
	"time"
)

type reliableWireFrame struct {
	kind byte
	seq  uint64
	body []byte
}

// One reader and one writer per carrier. Close and join the old reader before
// returning, so reconnect cannot steal frames through orphaned goroutines.
func runReliableTransport(ctx context.Context, conn net.Conn, r *reliableDatagrams,
	read func() (reliableWireFrame, error), write func(reliableWireFrame) error,
	deliver func([]byte) error, sendType, receiveType byte, observe func(reliableStats)) error {
	readerDone := make(chan struct{})
	readerErr := make(chan error, 1)
	r.replay()
	r.forceACK()
	go func() {
		defer close(readerDone)
		for {
			conn.SetReadDeadline(time.Now().Add(20 * time.Second))
			f, e := read()
			if e != nil {
				readerErr <- e
				return
			}
			switch f.kind {
			case receiveType:
				e = r.receive(f.seq, f.body, deliver)
			case reliableACKType:
				if len(f.body) != 8 {
					e = errors.New("invalid reliable selective ACK length")
				} else {
					e = r.acknowledge(f.seq, binary.BigEndian.Uint64(f.body))
				}
			case 6: // keepalive never consumes the data sequence space
			case 5:
				e = errors.New("peer closed reliable session")
			default:
				e = errors.New("unexpected reliable frame")
			}
			if e != nil {
				readerErr <- r.fail(e)
				return
			}
		}
	}()
	defer func() { conn.Close(); <-readerDone; observe(r.snapshot()) }()
	ticker := time.NewTicker(10 * time.Millisecond)
	defer ticker.Stop()
	lastKeepalive, lastReport := time.Now(), time.Now()
	send := func(f reliableWireFrame) error {
		conn.SetWriteDeadline(time.Now().Add(5 * time.Second))
		return write(f)
	}
	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case e := <-readerErr:
			return e
		case <-ticker.C:
		case <-r.wake:
		}
		if seq, b, ok := r.ack(); ok {
			if e := send(reliableWireFrame{kind: reliableACKType, seq: seq, body: b}); e != nil {
				return e
			}
		}
		frames, e := r.due(time.Now())
		if e != nil {
			return r.fail(e)
		}
		for _, p := range frames {
			if e := send(reliableWireFrame{kind: sendType, seq: p.seq, body: p.body}); e != nil {
				return e
			}
		}
		if time.Since(lastKeepalive) >= 5*time.Second {
			if e := send(reliableWireFrame{kind: 6}); e != nil {
				return e
			}
			lastKeepalive = time.Now()
		}
		if time.Since(lastReport) >= time.Second {
			observe(r.snapshot())
			lastReport = time.Now()
		}
	}
}
