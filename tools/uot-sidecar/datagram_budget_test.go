package main

import (
	"context"
	"errors"
	"testing"
	"time"
)

func TestDatagramBudgetDeadlineCancellationAndRelease(t *testing.T) {
	b := newDatagramBudget(8)
	if _, e := b.reserve(context.Background(), 8); e != nil {
		t.Fatal(e)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Millisecond)
	blocked, e := b.reserve(ctx, 1)
	cancel()
	if !blocked || !errors.Is(e, context.DeadlineExceeded) || b.bytes() != 8 {
		t.Fatalf("blocked=%v err=%v bytes=%d", blocked, e, b.bytes())
	}
	ctx, cancel = context.WithCancel(context.Background())
	done := make(chan error)
	go func() { _, e := b.reserve(ctx, 1); done <- e }()
	cancel()
	select {
	case e := <-done:
		if !errors.Is(e, context.Canceled) {
			t.Fatal(e)
		}
	case <-time.After(time.Second):
		t.Fatal("shutdown blocked")
	}
	b.release(8)
	if _, e := b.reserve(context.Background(), 8); e != nil {
		t.Fatal(e)
	}
	b.release(8)
	if b.bytes() != 0 {
		t.Fatal("leaked reservation")
	}
}
func TestDatagramBudgetRejectsOversize(t *testing.T) {
	b := newDatagramBudget(4)
	if _, e := b.reserve(context.Background(), 5); !errors.Is(e, errDatagramBudget) {
		t.Fatal(e)
	}
	if b.bytes() != 0 {
		t.Fatal("oversize charged")
	}
}
