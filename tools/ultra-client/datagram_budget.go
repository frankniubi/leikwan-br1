package main

import (
	"context"
	"errors"
	"sync"
	"time"
)

var errDatagramBudget = errors.New("datagram queue deadline exceeded")

const datagramQueueBytes = 16 << 20
const datagramQueueDeadline = 250 * time.Millisecond

// reserve bounds payload copies, not only channel slots. Callers must release
// exactly once on dequeue or failed admission. The channel itself is never closed.
type datagramBudget struct {
	mu      sync.Mutex
	used    int
	limit   int
	changed chan struct{}
}

func newDatagramBudget(limit int) *datagramBudget {
	return &datagramBudget{limit: limit, changed: make(chan struct{})}
}
func (b *datagramBudget) reserve(ctx context.Context, n int) (bool, error) {
	blocked := false
	if n > b.limit {
		return blocked, errDatagramBudget
	}
	for {
		b.mu.Lock()
		if e := ctx.Err(); e != nil {
			b.mu.Unlock()
			return blocked, e
		}
		if b.used+n <= b.limit {
			b.used += n
			b.mu.Unlock()
			return blocked, nil
		}
		wait := b.changed
		b.mu.Unlock()
		blocked = true
		select {
		case <-ctx.Done():
			return blocked, ctx.Err()
		case <-wait:
		}
	}
}
func (b *datagramBudget) release(n int) {
	b.mu.Lock()
	defer b.mu.Unlock()
	b.used -= n
	if b.used < 0 {
		panic("datagram budget release imbalance")
	}
	close(b.changed)
	b.changed = make(chan struct{})
}
func (b *datagramBudget) bytes() int { b.mu.Lock(); defer b.mu.Unlock(); return b.used }
