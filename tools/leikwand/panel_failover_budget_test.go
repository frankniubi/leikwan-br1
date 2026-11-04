package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

// blackholedPanel accepts the connection and never answers, like a CDN edge
// the GFW lets connect but stalls. close releases the handlers.
func blackholedPanel(t *testing.T, hits *int32) (*httptest.Server, func()) {
	t.Helper()
	block := make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(hits, 1)
		select {
		case <-block:
		case <-r.Context().Done():
		}
	}))
	return server, func() { close(block); server.Close() }
}

// The field failure (forward 354, 2026-11-04): v3 accepted connections and
// never answered, the nbwly fallback worked. Pull (40s) failed over and
// diagnostics passed, but every lease (30s) spent its whole budget on v3 and
// reached the fallback with a dead context: "context deadline exceeded"
// against v3, forever. Each attempt must get its own share of the budget so
// the fallback is actually tried.
func TestLeaseFailsOverWithinItsBudgetWhenThePrimaryBlackholes(t *testing.T) {
	var primaryHits, mirrorHits int32
	primary, release := blackholedPanel(t, &primaryHits)
	defer release()
	mirror := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&mirrorHits, 1)
		writeJSON(t, w, map[string]any{"ok": true, "leases": []any{}})
	}))
	defer mirror.Close()

	cfg := defaultConfig()
	cfg.PanelURL = primary.URL
	cfg.FallbackURL = mirror.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	// 2 x minAttemptBudget: the primary gets its share and the fallback the
	// rest, exactly as the 30s lease budget splits across the two endpoints.
	ctx, cancel := context.WithTimeout(context.Background(), 2*minAttemptBudget+time.Second)
	defer cancel()
	start := time.Now()
	response, err := client.Lease(ctx, LeaseRequest{DeviceID: "dev-1"})
	if err != nil {
		t.Fatalf("lease did not fail over within its budget: %v (after %v)", err, time.Since(start))
	}
	if !response.OK {
		t.Fatal("lease response not ok")
	}
	if atomic.LoadInt32(&primaryHits) != 1 || atomic.LoadInt32(&mirrorHits) != 1 {
		t.Fatalf("hits primary=%d mirror=%d, want 1/1", primaryHits, mirrorHits)
	}
	if client.baseURL() != strings.TrimRight(mirror.URL, "/") {
		t.Fatalf("active endpoint = %s, want the mirror", client.baseURL())
	}

	// Once on the mirror the next lease goes straight there.
	ctx2, cancel2 := context.WithTimeout(context.Background(), 2*minAttemptBudget)
	defer cancel2()
	if _, err := client.Lease(ctx2, LeaseRequest{DeviceID: "dev-1"}); err != nil {
		t.Fatalf("second lease: %v", err)
	}
	if atomic.LoadInt32(&primaryHits) != 1 {
		t.Fatalf("second lease went back to the blackholed primary (hits=%d)", primaryHits)
	}
}

// A spent budget must not fire another attempt: it would fail at once and its
// error would name an endpoint that was never contacted.
func TestPanelStopsWhenTheCallerBudgetIsSpent(t *testing.T) {
	var primaryHits, mirrorHits int32
	primary, release := blackholedPanel(t, &primaryHits)
	defer release()
	mirror, releaseMirror := blackholedPanel(t, &mirrorHits)
	defer releaseMirror()

	cfg := defaultConfig()
	cfg.PanelURL = primary.URL
	cfg.FallbackURL = mirror.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	_, err := client.Lease(ctx, LeaseRequest{DeviceID: "dev-1"})
	if err == nil {
		t.Fatal("lease succeeded against two blackholed endpoints")
	}
	if !strings.Contains(err.Error(), primary.URL) {
		t.Fatalf("error should name the endpoint that was tried (%s): %v", primary.URL, err)
	}
	if atomic.LoadInt32(&mirrorHits) != 0 {
		t.Fatalf("an attempt was sent with a spent budget (mirror hits=%d)", mirrorHits)
	}
}

// A caller that gives up (a cancelled long poll, a spent budget) says nothing
// about the endpoint. Rotating on it would push every other request off a
// working endpoint onto a worse one — the toggling that kept the field router
// paying the blackhole penalty on alternate requests.
func TestCallerCancellationDoesNotRotateTheEndpoint(t *testing.T) {
	var slowHits, otherHits int32
	slow, release := blackholedPanel(t, &slowHits)
	defer release()
	other := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&otherHits, 1)
		writeJSON(t, w, map[string]any{"ok": true})
	}))
	defer other.Close()

	cfg := defaultConfig()
	cfg.PanelURL = slow.URL // a working endpoint that is merely slow to answer
	cfg.FallbackURL = other.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	ctx, cancel := context.WithCancel(context.Background())
	go func() { time.Sleep(200 * time.Millisecond); cancel() }()
	if _, err := client.Pull(ctx, "", 20); err == nil {
		t.Fatal("cancelled pull reported success")
	}
	if client.baseURL() != strings.TrimRight(slow.URL, "/") {
		t.Fatalf("a caller cancellation rotated the endpoint to %s", client.baseURL())
	}
	if atomic.LoadInt32(&otherHits) != 0 {
		t.Fatalf("the fallback was contacted after the caller gave up (%d)", otherHits)
	}
}

func TestAttemptContextSplitsTheRemainingBudget(t *testing.T) {
	parent, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	first, cancelFirst := attemptContext(parent, 2)
	defer cancelFirst()
	deadline, ok := first.Deadline()
	if !ok {
		t.Fatal("attempt has no deadline")
	}
	if got := time.Until(deadline); got > 16*time.Second || got < 14*time.Second {
		t.Fatalf("first of two attempts gets %v, want about 15s", got)
	}

	last, cancelLast := attemptContext(parent, 1)
	defer cancelLast()
	if d, _ := last.Deadline(); !d.Equal(mustDeadline(t, parent)) {
		t.Fatal("the last attempt must get everything that is left")
	}

	// A share below the floor is not imposed: the caller's deadline already
	// is the tighter bound, and a slow but healthy Panel keeps its chance.
	short, cancelShort := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancelShort()
	floor, cancelFloor := attemptContext(short, 2)
	defer cancelFloor()
	if d, _ := floor.Deadline(); !d.Equal(mustDeadline(t, short)) {
		t.Fatalf("share below %v must fall back to the caller deadline", minAttemptBudget)
	}

	// No deadline: unbounded attempts stay unbounded (the http.Client
	// timeout still applies).
	free, cancelFree := attemptContext(context.Background(), 2)
	defer cancelFree()
	if _, ok := free.Deadline(); ok {
		t.Fatal("a caller without a deadline must not get one")
	}
}

func mustDeadline(t *testing.T, ctx context.Context) time.Time {
	t.Helper()
	d, ok := ctx.Deadline()
	if !ok {
		t.Fatal("context has no deadline")
	}
	return d
}
