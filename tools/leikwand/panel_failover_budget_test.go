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

	// The primary keeps all but fallbackReserve; the fallback gets the
	// reserve. Scaled-down version of the 30s lease: 20s primary + 10s.
	ctx, cancel := context.WithTimeout(context.Background(), fallbackReserve+minAttemptBudget)
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

func TestAttemptContextReservesTimeForTheFallback(t *testing.T) {
	// The lease budget: the primary gets 20s, 10s stay for the fallback.
	lease, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	first, cancelFirst := attemptContext(lease, 2)
	defer cancelFirst()
	if got := time.Until(mustDeadline(t, first)); got > 21*time.Second || got < 19*time.Second {
		t.Fatalf("lease primary attempt gets %v, want about 20s", got)
	}

	// The pull budget: a healthy primary answering the 20s long poll must
	// not be cut off, so it keeps 30s of the 40s.
	pull, cancelPull := context.WithTimeout(context.Background(), 40*time.Second)
	defer cancelPull()
	pullFirst, cancelPullFirst := attemptContext(pull, 2)
	defer cancelPullFirst()
	if got := time.Until(mustDeadline(t, pullFirst)); got < 29*time.Second {
		t.Fatalf("pull primary attempt gets %v; a 20s long poll would be cut off", got)
	}

	last, cancelLast := attemptContext(lease, 1)
	defer cancelLast()
	if !mustDeadline(t, last).Equal(mustDeadline(t, lease)) {
		t.Fatal("the last attempt must get everything that is left")
	}

	// Too little to reserve anything: the caller's own deadline applies.
	short, cancelShort := context.WithTimeout(context.Background(), 6*time.Second)
	defer cancelShort()
	floor, cancelFloor := attemptContext(short, 2)
	defer cancelFloor()
	if !mustDeadline(t, floor).Equal(mustDeadline(t, short)) {
		t.Fatal("a budget below the floor must fall back to the caller deadline")
	}

	// No deadline: unbounded attempts stay unbounded (the http.Client
	// timeout still applies).
	free, cancelFree := attemptContext(context.Background(), 2)
	defer cancelFree()
	if _, ok := free.Deadline(); ok {
		t.Fatal("a caller without a deadline must not get one")
	}
}

// The pull long-polls for 20s. A primary that is healthy but holds the poll
// open must answer through it, not be failed over at the attempt boundary.
func TestPullLongPollOnAHealthyPrimaryIsNotFailedOver(t *testing.T) {
	var primaryHits, mirrorHits int32
	primary := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&primaryHits, 1)
		time.Sleep(1500 * time.Millisecond) // the long poll, scaled down
		w.WriteHeader(http.StatusNotModified)
	}))
	defer primary.Close()
	mirror := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&mirrorHits, 1)
		w.WriteHeader(http.StatusNotModified)
	}))
	defer mirror.Close()

	cfg := defaultConfig()
	cfg.PanelURL = primary.URL
	cfg.FallbackURL = mirror.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	// fallbackReserve + 2.5s: the primary attempt keeps 2.5s, longer than
	// the 1.5s poll, the way the 40s pull keeps 30s for a 20s poll.
	ctx, cancel := context.WithTimeout(context.Background(), fallbackReserve+2500*time.Millisecond)
	defer cancel()
	if _, err := client.Pull(ctx, "rev", 20); err != errNotModified {
		t.Fatalf("pull = %v, want not modified from the primary", err)
	}
	if atomic.LoadInt32(&mirrorHits) != 0 || client.baseURL() != strings.TrimRight(primary.URL, "/") {
		t.Fatalf("a healthy long poll was failed over (mirror hits=%d, active=%s)", mirrorHits, client.baseURL())
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
