package main

// This file and its tests are byte-identical in tools/uot-sidecar. Each tool
// remains a standalone module so existing frozen build scripts keep working.
import (
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"sort"
	"sync"
	"time"
)

const reliableDatagramMode = "reliable_datagram_v1"
const reliableACKType byte = 7
const reliableWindowPackets = 4096

type reliablePacket struct {
	seq        uint64
	body       []byte
	born, sent time.Time
	attempts   int
	sacked     bool
}
type reliableStats struct {
	QueueBlocked         uint64 `json:"queue_blocked"`
	QueueTimeout         uint64 `json:"queue_timeout"`
	QueueDrop            uint64 `json:"queue_drop"`
	SequenceGap          uint64 `json:"sequence_gap"`
	RetransmitCount      uint64 `json:"retransmit_count"`
	ReconnectReplayCount uint64 `json:"reconnect_replay_count"`
	UnrecoverableCount   uint64 `json:"unrecoverable_frame_count"`
	Deduplicated         uint64 `json:"deduplicated"`
	WindowPackets        int    `json:"window_packets"`
	WindowBytes          int    `json:"window_bytes"`
	OldestUnackedMS      int64  `json:"oldest_unacked_ms"`
	Acknowledged         uint64 `json:"acknowledged_sequence"`
	Delivered            uint64 `json:"delivered_sequence"`
	Failed               string `json:"failure,omitempty"`
}
type reliableDatagrams struct {
	mu                   sync.Mutex
	pending              map[uint64]*reliablePacket
	next, acked, maxSent uint64
	bytes                int
	changed              chan struct{}
	wake                 chan struct{}
	failure              error
	stats                reliableStats
	recvMu               sync.Mutex
	delivered            uint64
	reorder              map[uint64][]byte
	recvBytes            int
	ackDirty             bool
}

func newReliableDatagrams() *reliableDatagrams {
	return &reliableDatagrams{pending: map[uint64]*reliablePacket{}, changed: make(chan struct{}), wake: make(chan struct{}, 1), reorder: map[uint64][]byte{}}
}
func (r *reliableDatagrams) signalLocked() {
	close(r.changed)
	r.changed = make(chan struct{})
	select {
	case r.wake <- struct{}{}:
	default:
	}
}
func (r *reliableDatagrams) fail(err error) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.failure == nil {
		r.failure = err
		r.stats.UnrecoverableCount += uint64(len(r.pending))
		r.stats.Failed = err.Error()
		r.signalLocked()
	}
	return r.failure
}
func (r *reliableDatagrams) enqueue(ctx context.Context, p []byte) error {
	ctx, cancel := context.WithTimeout(ctx, datagramQueueDeadline)
	defer cancel()
	blocked := false
	for {
		r.mu.Lock()
		if r.failure != nil {
			e := r.failure
			r.mu.Unlock()
			return e
		}
		if ctx.Err() != nil {
			if errors.Is(ctx.Err(), context.DeadlineExceeded) {
				r.stats.QueueTimeout++
				r.stats.UnrecoverableCount++
			}
			r.mu.Unlock()
			return r.fail(fmt.Errorf("reliable admission: %w", ctx.Err()))
		}
		if len(p) > datagramQueueBytes {
			r.mu.Unlock()
			return r.fail(errors.New("reliable frame too large"))
		}
		if len(r.pending) < reliableWindowPackets && r.bytes+len(p) <= datagramQueueBytes {
			if r.next == ^uint64(0) {
				r.mu.Unlock()
				return r.fail(errors.New("reliable sequence exhausted"))
			}
			r.next++
			r.pending[r.next] = &reliablePacket{seq: r.next, body: append([]byte(nil), p...), born: time.Now()}
			r.bytes += len(p)
			r.signalLocked()
			r.mu.Unlock()
			return nil
		}
		if !blocked {
			r.stats.QueueBlocked++
			blocked = true
		}
		wait := r.changed
		r.mu.Unlock()
		select {
		case <-ctx.Done():
		case <-wait:
		}
	}
}

// retained frames are released ONLY by cumulative ACK after delivery; selective
// ACK merely suppresses retransmission while the receiver retains an out-of-order frame.
func (r *reliableDatagrams) acknowledge(cumulative uint64, bits uint64) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	if cumulative > r.maxSent {
		return errors.New("reliable ACK exceeds transmitted sequence")
	}
	if cumulative < r.acked {
		return nil
	}
	r.acked = cumulative
	for seq, p := range r.pending {
		if seq <= cumulative {
			r.bytes -= len(p.body)
			delete(r.pending, seq)
		} else if seq-cumulative <= 64 {
			p.sacked = bits&(uint64(1)<<(seq-cumulative-1)) != 0
		}
	}
	r.signalLocked()
	return nil
}
func (r *reliableDatagrams) replay() {
	r.mu.Lock()
	defer r.mu.Unlock()
	for _, p := range r.pending {
		if p.attempts > 0 {
			r.stats.ReconnectReplayCount++
		}
		p.sent = time.Time{}
		p.sacked = false
	}
	r.signalLocked()
}

// due is single-writer per attached carrier. Attempts are charged before writing
// because an error can occur after a peer has received the complete frame.
func (r *reliableDatagrams) due(now time.Time) ([]reliablePacket, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.failure != nil {
		return nil, r.failure
	}
	keys := make([]uint64, 0, len(r.pending))
	for seq, p := range r.pending {
		if now.Sub(p.born) > 30*time.Second {
			return nil, errors.New("reliable ACK age budget exhausted")
		}
		if !p.sacked && (p.sent.IsZero() || now.Sub(p.sent) >= time.Second) {
			keys = append(keys, seq)
		}
	}
	sort.Slice(keys, func(i, j int) bool { return keys[i] < keys[j] })
	if len(keys) > 64 {
		keys = keys[:64]
	}
	out := make([]reliablePacket, 0, len(keys))
	for _, seq := range keys {
		p := r.pending[seq]
		if p.attempts >= 12 {
			return nil, errors.New("reliable retry budget exhausted")
		}
		if p.attempts > 0 {
			r.stats.RetransmitCount++
		}
		p.attempts++
		p.sent = now
		if seq > r.maxSent {
			r.maxSent = seq
		}
		out = append(out, *p)
	}
	return out, nil
}

// Delivery and sequence advancement are atomic relative to every reader,
// including a previous carrier still unwinding after a reattach.
func (r *reliableDatagrams) receive(seq uint64, p []byte, deliver func([]byte) error) error {
	r.recvMu.Lock()
	defer r.recvMu.Unlock()
	r.mu.Lock()
	failure := r.failure
	r.mu.Unlock()
	if failure != nil {
		return failure
	}
	if seq == 0 {
		return errors.New("reliable zero data sequence")
	}
	if seq <= r.delivered {
		r.mu.Lock()
		r.stats.Deduplicated++
		r.mu.Unlock()
		r.ackDirty = true
		select {
		case r.wake <- struct{}{}:
		default:
		}
		return nil
	}
	if seq-r.delivered > reliableWindowPackets {
		return errors.New("reliable receive window exceeded")
	}
	if _, exists := r.reorder[seq]; !exists {
		if r.recvBytes+len(p) > datagramQueueBytes {
			return errors.New("reliable receive byte budget exceeded")
		}
		r.reorder[seq] = append([]byte(nil), p...)
		r.recvBytes += len(p)
		if seq > r.delivered+1 {
			r.mu.Lock()
			r.stats.SequenceGap++
			r.mu.Unlock()
		}
	}
	for {
		next := r.delivered + 1
		b, ok := r.reorder[next]
		if !ok {
			break
		}
		if e := deliver(b); e != nil {
			return fmt.Errorf("reliable delivery: %w", e)
		}
		delete(r.reorder, next)
		r.recvBytes -= len(b)
		r.delivered = next
	}
	r.ackDirty = true
	select {
	case r.wake <- struct{}{}:
	default:
	}
	return nil
}
func (r *reliableDatagrams) ack() (uint64, []byte, bool) {
	r.recvMu.Lock()
	defer r.recvMu.Unlock()
	if !r.ackDirty {
		return 0, nil, false
	}
	r.ackDirty = false
	bits := uint64(0)
	for seq := range r.reorder {
		if seq-r.delivered <= 64 {
			bits |= 1 << (seq - r.delivered - 1)
		}
	}
	b := make([]byte, 8)
	binary.BigEndian.PutUint64(b, bits)
	return r.delivered, b, true
}
func (r *reliableDatagrams) forceACK() {
	r.recvMu.Lock()
	r.ackDirty = true
	r.recvMu.Unlock()
	select {
	case r.wake <- struct{}{}:
	default:
	}
}
func (r *reliableDatagrams) snapshot() reliableStats {
	r.mu.Lock()
	s := r.stats
	s.WindowPackets = len(r.pending)
	s.WindowBytes = r.bytes
	s.Acknowledged = r.acked
	for _, p := range r.pending {
		age := time.Since(p.born).Milliseconds()
		if age > s.OldestUnackedMS {
			s.OldestUnackedMS = age
		}
	}
	r.mu.Unlock()
	r.recvMu.Lock()
	s.Delivered = r.delivered
	r.recvMu.Unlock()
	return s
}
