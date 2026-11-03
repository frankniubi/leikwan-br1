package main

import (
	"context"
	"testing"
	"time"
)

func BenchmarkReliableEnqueueACK(b *testing.B) {
	r := newReliableDatagrams()
	payload := make([]byte, 1200)
	ctx := context.Background()
	b.ReportAllocs()
	b.SetBytes(int64(len(payload)))
	for i := 0; i < b.N; i++ {
		if e := r.enqueue(ctx, payload); e != nil {
			b.Fatal(e)
		}
		p, e := r.due(time.Now())
		if e != nil {
			b.Fatal(e)
		}
		if e := r.acknowledge(p[0].seq, 0); e != nil {
			b.Fatal(e)
		}
	}
}
func BenchmarkRawBudgetEnqueue(b *testing.B) {
	budget := newDatagramBudget(datagramQueueBytes)
	q := make(chan []byte, 4096)
	payload := make([]byte, 1200)
	ctx := context.Background()
	b.ReportAllocs()
	b.SetBytes(int64(len(payload)))
	for i := 0; i < b.N; i++ {
		if _, e := budget.reserve(ctx, len(payload)); e != nil {
			b.Fatal(e)
		}
		q <- append([]byte(nil), payload...)
		p := <-q
		budget.release(len(p))
	}
}
