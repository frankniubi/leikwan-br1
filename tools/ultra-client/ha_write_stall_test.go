package main

import (
	"bytes"
	"context"
	"io"
	"log"
	"net"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// lockedLog captures the package logger for one test.
type lockedLog struct {
	mu  sync.Mutex
	buf bytes.Buffer
}

func (l *lockedLog) Write(p []byte) (int, error) {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.buf.Write(p)
}

func (l *lockedLog) String() string {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.buf.String()
}

func captureLog(t *testing.T) *lockedLog {
	t.Helper()
	captured := &lockedLog{}
	previousWriter, previousFlags := log.Writer(), log.Flags()
	log.SetOutput(captured)
	log.SetFlags(0)
	t.Cleanup(func() {
		log.SetOutput(previousWriter)
		log.SetFlags(previousFlags)
	})
	return captured
}

// stallPair returns a local client pair and a relay pair with no socket
// deadlines (the tests bound themselves) and small buffers on the relay leg,
// so a relay that stops reading blocks the runtime's writes quickly. The relay
// buffers are fixed before connect (see tcpPairWithBuffers): shrinking them on
// the connected sockets made Linux deliver even a reading relay's progress in
// ~200 ms bursts, which a short bound scored as a stall.
func stallPair(t *testing.T) (client, local, remote, provider *net.TCPConn) {
	t.Helper()
	client, local = tcpPair(t)
	remote, provider = tcpPairWithBuffers(t, 16<<10, 16<<10)
	for _, connection := range []*net.TCPConn{client, local} {
		_ = connection.SetDeadline(time.Time{})
	}
	return client, local, remote, provider
}

func stallIdentity(lease ProviderLease, bound time.Duration) relayIdentity {
	return relayIdentity{
		forward: 42, generation: lease.PolicyGeneration, selection: lease.SelectionRef,
		pathKey: haPathKey(lease), candidateKey: lease.CandidateKeyHash, leaseHash: lease.LeaseHash,
		writeStall: bound,
	}
}

func stallStatus(t *testing.T) *StatusWriter {
	t.Helper()
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	t.Cleanup(status.Close)
	return status
}

func stallLimiter(t *testing.T) *PolicyLimiter {
	t.Helper()
	limiter := NewPolicyLimiter()
	if err := limiter.Replace(0, 1<<34, time.Now().Add(time.Minute), "test"); err != nil {
		t.Fatal(err)
	}
	return limiter
}

func runRelay(store *PolicyStore, status *StatusWriter, limiter *PolicyLimiter, local, remote net.Conn, ident relayIdentity) chan struct{} {
	done := make(chan struct{})
	go func() {
		defer close(done)
		relayTCP(context.Background(), local, remote, limiter, status, store, ident)
	}()
	return done
}

// flood writes to the client socket until the relay stops accepting data or
// the socket is closed.
func flood(connection net.Conn) {
	go func() {
		chunk := bytes.Repeat([]byte("u"), 64<<10)
		for {
			if _, err := connection.Write(chunk); err != nil {
				return
			}
		}
	}()
}

func waitDone(t *testing.T, done chan struct{}, within time.Duration, what string) time.Duration {
	t.Helper()
	started := time.Now()
	select {
	case <-done:
		return time.Since(started)
	case <-time.After(within):
		t.Fatalf("%s: relay still running after %s", what, within)
		return 0
	}
}

func stallEvents(status *StatusWriter) []HAEvent {
	status.mu.Lock()
	defer status.mu.Unlock()
	var events []HAEvent
	for _, event := range status.status.HAEvents {
		if event.Reason == "remote_write_stall" {
			events = append(events, event)
		}
	}
	return events
}

func pathStreak(store *PolicyStore, key string) int {
	store.mu.RLock()
	defer store.mu.RUnlock()
	return store.pathFailures["tcp:"+key]
}

// A relay that accepts the stream and then stops reading used to pin the
// upstream copy in a blocked write for as long as the runtime lived, and every
// new stream went to the same candidate. The stalled write now ends the stream
// with a stage=remote_write reason=stall line, and two stalls in a row move new
// streams to the healthy sibling.
func TestRemoteWriteStallEndsStreamAndMovesNewStreams(t *testing.T) {
	logs := captureLog(t)
	store, first, second := twoPathCandidateStore(t)
	status := stallStatus(t)
	limiter := stallLimiter(t)
	bound := 300 * time.Millisecond

	for attempt := 1; attempt <= 2; attempt++ {
		client, local, remote, provider := stallPair(t)
		// The relay reads the first request and answers a few bytes, then never
		// reads again: the 0-67 bytes the matrix saw came back.
		done := runRelay(store, status, limiter, local, remote, stallIdentity(first, bound))
		flood(client)
		if _, err := io.ReadFull(provider, make([]byte, 1024)); err != nil {
			t.Fatal(err)
		}
		if _, err := provider.Write([]byte("HTTP/1.1 100 Continue\r\n")); err != nil {
			t.Fatal(err)
		}
		elapsed := waitDone(t, done, 5*time.Second, "stalled stream")
		if elapsed < bound/2 {
			t.Fatalf("stream ended after %s, before the %s stall bound could expire", elapsed, bound)
		}
		events := stallEvents(status)
		if len(events) != attempt {
			t.Fatalf("attempt %d: want %d remote_write_stall events, got %+v", attempt, attempt, status.status.HAEvents)
		}
		event := events[len(events)-1]
		if event.CandidateKeyHash != first.CandidateKeyHash || event.UpstreamBytes == nil || *event.UpstreamBytes == 0 ||
			event.RemoteReadBytes == nil || *event.RemoteReadBytes == 0 || event.LocalPathAvoided == nil {
			t.Fatalf("stall event lost its evidence: %+v", event)
		}
		if *event.LocalPathAvoided != (attempt == 2) {
			t.Fatalf("attempt %d: local_path_avoided=%t", attempt, *event.LocalPathAvoided)
		}
	}

	if !strings.Contains(logs.String(), "direction=upstream stage=remote_write reason=stall") {
		t.Fatalf("no stage=remote_write reason=stall line:\n%s", logs.String())
	}
	if _, isolated := isPathIsolated(store, "tcp", haPathKey(first)); !isolated {
		t.Fatal("two stalls in a row did not isolate the path")
	}
	candidates := store.CandidateSnapshots("tcp")
	defer wipeSnapshots(candidates)
	if len(candidates) != 1 || haPathKey(candidates[0].Lease) != haPathKey(second) {
		t.Fatalf("new streams do not move to the healthy sibling: %d candidates", len(candidates))
	}
	// The stall is local evidence, never global reputation.
	status.mu.Lock()
	feedback := len(status.status.CandidateFeedback)
	status.mu.Unlock()
	if feedback != 0 {
		t.Fatalf("a stall produced %d global candidate feedback entries", feedback)
	}
}

// fillUntilBlocked writes to the client socket until the runtime stops reading
// it, which happens once the runtime's own write to the relay is blocked.
func fillUntilBlocked(t *testing.T, client *net.TCPConn) {
	t.Helper()
	chunk := bytes.Repeat([]byte("u"), 64<<10)
	_ = client.SetWriteDeadline(time.Now().Add(300 * time.Millisecond))
	for {
		if _, err := client.Write(chunk); err != nil {
			if !isWriteTimeout(err) {
				t.Fatalf("client write failed before the relay blocked: %v", err)
			}
			return
		}
	}
}

// The local client closing while the upstream copy is blocked in a write to a
// relay that stopped reading used to leave the stream alive for 452-673 s,
// until the unit stopped (reason=canceled). The blocked write now ends it
// within a few stall bounds.
//
// "A few", not one: the Linux kernel accepts a little more data (8 KiB here)
// on each of the first fresh write() calls into a send buffer the relay has
// stopped draining, even though TCP_INFO shows nothing more acknowledged.
// writeAllProgress counts those bytes as progress and re-arms a whole bound,
// so a relay that stopped reading is declared stalled after up to three
// bounds on Linux (measured: 3 x 1.5 s with no byte acknowledged after the
// first 48 ms), against one on Windows.
const localCloseStallBound = 500 * time.Millisecond

// localCloseStallAllowance is how long the bounded stream may live: the
// 300 ms fill, three bounds, and scheduling slack.
const localCloseStallAllowance = 4*localCloseStallBound + time.Second

func TestLocalCloseDuringBlockedRemoteWriteEndsPromptly(t *testing.T) {
	t.Run("before", func(t *testing.T) {
		// Without the progress bound the same stream outlives its client: this
		// is the 2026-09-24 behaviour, and proves the scenario is reproduced.
		store, first, _ := twoPathCandidateStore(t)
		client, local, remote, _ := stallPair(t)
		ctx, cancel := context.WithCancel(context.Background())
		done := make(chan struct{})
		go func() {
			defer close(done)
			relayTCP(ctx, local, remote, stallLimiter(t), stallStatus(t), store, stallIdentity(first, -1))
		}()
		fillUntilBlocked(t, client)
		client.Close()
		select {
		case <-done:
			t.Fatal("the scenario did not reproduce: the unbounded stream ended by itself")
		case <-time.After(localCloseStallAllowance):
		}
		cancel()
		waitDone(t, done, 3*time.Second, "canceled stream")
	})
	t.Run("after", func(t *testing.T) {
		store, first, _ := twoPathCandidateStore(t)
		status := stallStatus(t)
		bound := localCloseStallBound
		client, local, remote, _ := stallPair(t)
		started := time.Now()
		done := runRelay(store, status, stallLimiter(t), local, remote, stallIdentity(first, bound))
		fillUntilBlocked(t, client)
		client.Close()
		waitDone(t, done, localCloseStallAllowance+3*time.Second, "stream whose client closed")
		if lived := time.Since(started); lived > localCloseStallAllowance {
			t.Fatalf("stream lived %s with a %s stall bound", lived, bound)
		}
		if len(stallEvents(status)) != 1 {
			t.Fatalf("blocked write after the client left was not a stall: %+v", status.status.HAEvents)
		}
	})
}

// A stream with nothing to send is idle, not stalled, however long it is quiet.
func TestIdleStreamIsNotAWriteStall(t *testing.T) {
	store, first, _ := twoPathCandidateStore(t)
	status := stallStatus(t)
	bound := 100 * time.Millisecond
	client, local, remote, provider := stallPair(t)
	done := runRelay(store, status, stallLimiter(t), local, remote, stallIdentity(first, bound))
	time.Sleep(6 * bound)
	if _, err := client.Write([]byte("ping")); err != nil {
		t.Fatal(err)
	}
	if got := readXor(t, provider, 4); string(got) != "ping" {
		t.Fatalf("relay got %q", got)
	}
	time.Sleep(6 * bound)
	if _, err := provider.Write([]byte("pong")); err != nil {
		t.Fatal(err)
	}
	reply := make([]byte, 4)
	if _, err := io.ReadFull(client, reply); err != nil || string(reply) != "pong" {
		t.Fatalf("idle stream broke: %q %v", reply, err)
	}
	client.Close()
	provider.Close()
	waitDone(t, done, 3*time.Second, "idle stream")
	if events := stallEvents(status); len(events) != 0 {
		t.Fatalf("an idle stream was scored as a stall: %+v", events)
	}
	if streak := pathStreak(store, haPathKey(first)); streak != 0 {
		t.Fatalf("an idle stream left a path strike (%d)", streak)
	}
}

// An upload the relay fully accepts but never answers is silence, not a stall:
// no write is ever blocked, and the half-closed stream still receives a late
// response.
func TestUnansweredUploadIsNotAWriteStall(t *testing.T) {
	store, first, _ := twoPathCandidateStore(t)
	status := stallStatus(t)
	bound := 100 * time.Millisecond
	client, local, remote, provider := stallPair(t)
	done := runRelay(store, status, stallLimiter(t), local, remote, stallIdentity(first, bound))
	upload := bytes.Repeat([]byte("x"), 256<<10)
	go func() {
		_, _ = client.Write(upload)
		_ = client.CloseWrite()
	}()
	got, err := io.ReadAll(provider)
	if err != nil || len(got) != len(upload) {
		t.Fatalf("relay received %d bytes: %v", len(got), err)
	}
	time.Sleep(6 * bound) // no reply for many stall bounds
	if _, err := provider.Write([]byte("late-response")); err != nil {
		t.Fatal(err)
	}
	provider.CloseWrite()
	reply, err := io.ReadAll(client)
	if err != nil || string(reply) != "late-response" {
		t.Fatalf("half-closed stream lost the late response: %q %v", reply, err)
	}
	waitDone(t, done, 3*time.Second, "unanswered upload")
	if events := stallEvents(status); len(events) != 0 {
		t.Fatalf("an unanswered upload was scored as a stall: %+v", events)
	}
	if streak := pathStreak(store, haPathKey(first)); streak != 0 {
		t.Fatalf("an unanswered upload left a path strike (%d)", streak)
	}
}

// A relay that reads slowly keeps making progress, so a large upload through it
// is never a stall even though single writes block for a while.
func TestSlowButMovingRelayIsNotAWriteStall(t *testing.T) {
	store, first, _ := twoPathCandidateStore(t)
	status := stallStatus(t)
	bound := 250 * time.Millisecond
	client, local, remote, provider := stallPair(t)
	done := runRelay(store, status, stallLimiter(t), local, remote, stallIdentity(first, bound))
	const total = 1 << 20
	go func() {
		_, _ = client.Write(bytes.Repeat([]byte("s"), total))
		_ = client.CloseWrite()
	}()
	received := 0
	buffer := make([]byte, 16<<10)
	for received < total {
		n, err := provider.Read(buffer)
		received += n
		if err != nil {
			t.Fatalf("relay read %d of %d: %v", received, total, err)
		}
		time.Sleep(10 * time.Millisecond) // ~1.6 MB/s: slow, but moving
	}
	provider.Close()
	waitDone(t, done, 3*time.Second, "slow relay")
	if events := stallEvents(status); len(events) != 0 {
		t.Fatalf("a slow but moving relay was scored as a stall: %+v", events)
	}
}

// Evidence against the only authorized path cannot move a stream anywhere, so
// a stall there is recorded but never isolates the forward's sole path.
func TestWriteStallOnSolePathDoesNotIsolateIt(t *testing.T) {
	store := NewPolicyStore(testPolicyConfig())
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	only := testLease(policy, strings.Repeat("b", 64))
	only.CandidateKeyHash = strings.Repeat("c", 64)
	if err := store.ReplaceCandidates(policy, only, []ProviderLease{only}); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { store.Revoke("") })
	status := stallStatus(t)
	for attempt := 0; attempt < 2; attempt++ {
		client, local, remote, _ := stallPair(t)
		done := runRelay(store, status, stallLimiter(t), local, remote, stallIdentity(only, 200*time.Millisecond))
		flood(client)
		waitDone(t, done, 5*time.Second, "sole-path stall")
	}
	if len(stallEvents(status)) != 2 {
		t.Fatalf("sole-path stalls were not recorded: %+v", status.status.HAEvents)
	}
	if _, isolated := isPathIsolated(store, "tcp", haPathKey(only)); isolated {
		t.Fatal("stalls isolated the only authorized path")
	}
}

func TestTCPWriteStallConfigIsBounded(t *testing.T) {
	for _, tc := range []struct {
		seconds int
		want    time.Duration
	}{
		{0, 10 * time.Second}, {-1, -1}, {1, 3 * time.Second}, {20, 20 * time.Second}, {100000, 300 * time.Second},
	} {
		if got := resolveTCPWriteStall(Config{TCPWriteStallSeconds: tc.seconds}); got != tc.want {
			t.Fatalf("tcp_write_stall_seconds=%d resolved to %s, want %s", tc.seconds, got, tc.want)
		}
	}
	ident := tcpRelayIdentity(Config{TCPWriteStallSeconds: -1}, RuntimeSnapshot{})
	if remoteWriteStallTimeout(ident) != 0 {
		t.Fatal("a negative tcp_write_stall_seconds did not disable the timer")
	}
	if remoteWriteStallTimeout(tcpRelayIdentity(Config{}, RuntimeSnapshot{})) != remoteWriteProgressTimeout {
		t.Fatal("the default stream did not get the default stall bound")
	}
}

// writeAllProgress re-arms its deadline after every partial write: only an
// attempt that accepted nothing is a stall.
func TestWriteAllProgressCountsOnlyWritesWithoutProgress(t *testing.T) {
	partial := &scriptedConn{steps: []scriptedWrite{{n: 3, timeout: true}, {n: 2, timeout: true}, {n: 5}}}
	if err := writeAllProgress(partial, make([]byte, 10), func() time.Time { return time.Now().Add(time.Second) }); err != nil {
		t.Fatalf("progressing write failed: %v", err)
	}
	if partial.deadlines != 3 {
		t.Fatalf("deadline re-armed %d times, want 3", partial.deadlines)
	}
	stuck := &scriptedConn{steps: []scriptedWrite{{n: 4, timeout: true}, {n: 0, timeout: true}}}
	err := writeAllProgress(stuck, make([]byte, 10), func() time.Time { return time.Now().Add(time.Second) })
	if haErrorClass(err) != "stall" {
		t.Fatalf("write without progress classified %q (%v)", haErrorClass(err), err)
	}
}

type scriptedWrite struct {
	n       int
	timeout bool
}

type scriptedConn struct {
	net.Conn
	steps     []scriptedWrite
	deadlines int
}

func (c *scriptedConn) SetWriteDeadline(time.Time) error { c.deadlines++; return nil }

func (c *scriptedConn) Write(p []byte) (int, error) {
	step := c.steps[0]
	c.steps = c.steps[1:]
	n := min(step.n, len(p))
	if step.timeout {
		return n, &net.OpError{Op: "write", Net: "tcp", Err: timeoutError{}}
	}
	return n, nil
}

type timeoutError struct{}

func (timeoutError) Error() string   { return "i/o timeout" }
func (timeoutError) Timeout() bool   { return true }
func (timeoutError) Temporary() bool { return true }
