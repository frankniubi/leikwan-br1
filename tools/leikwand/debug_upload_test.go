package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

const (
	debugTestA = "11223344-5566-4777-8888-99aabbccddee"
	debugTestB = "aabbccdd-5566-4777-8888-99aabbccddee"
)

func TestRedactDebugTextScrubsSecretsAndIPs(t *testing.T) {
	got := redactDebugText("Bearer secret-tok peer 203.0.113.42 and [2001:db8::1] ok")
	for _, leak := range []string{"secret-tok", "203.0.113.42", "2001:db8::1"} {
		if strings.Contains(got, leak) {
			t.Fatalf("redacted text still leaked %q: %s", leak, got)
		}
	}
	if !strings.Contains(got, "ok") {
		t.Fatalf("expected non-sensitive text preserved: %s", got)
	}
}

func debugTestMeta(uuid string) *DebugCollectionMeta {
	return &DebugCollectionMeta{UUID: uuid, Status: "active", SupportsUploadID: true}
}

func debugTestAgent(t *testing.T, handler http.HandlerFunc) *Agent {
	t.Helper()
	server := httptest.NewServer(handler)
	t.Cleanup(server.Close)
	cfg := defaultConfig()
	cfg.PanelURL, cfg.FallbackURL = server.URL, ""
	a := newTestAgent(t, cfg)
	a.panel = newPanelClient(a.cfg, SecretState{AgentToken: "tok"}, "dev-1", "router")
	return a
}

func debugObserve(t *testing.T, a *Agent, coll *DebugCollectionMeta) {
	t.Helper()
	if err := a.observeDebugCollection(coll, a.debugObservation()); err != nil {
		t.Fatal(err)
	}
}

func debugSnapshot(a *Agent) debugUploadState {
	a.debugMu.Lock()
	defer a.debugMu.Unlock()
	s := a.debugState
	if s.Pending != nil {
		p := *s.Pending
		s.Pending = &p
	}
	return s
}

// Move persisted deadlines forward in simulated time, without a sleep or a new
// upload identity. Production code always honors the persisted backoff.
func debugRetryReady(t *testing.T, a *Agent) {
	t.Helper()
	a.debugMu.Lock()
	defer a.debugMu.Unlock()
	next := a.debugState
	if next.Pending == nil {
		t.Fatal("no pending upload")
	}
	p := *next.Pending
	p.RetryAt = time.Now().Add(-time.Second)
	next.Pending = &p
	if err := a.saveDebugLocked(next); err != nil {
		t.Fatal(err)
	}
}

func debugSeedBundle(t *testing.T, a *Agent, uuid string, bundle []byte) {
	t.Helper()
	debugObserve(t, a, debugTestMeta(uuid))
	id, err := newDebugUploadID()
	if err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256(bundle)
	a.debugMu.Lock()
	defer a.debugMu.Unlock()
	next := a.debugState
	next.Pending = &debugUpload{Collection: uuid, UploadID: id, Bundle: bundle, Digest: hex.EncodeToString(sum[:]), ReferenceCode: uuid[:8]}
	if err := a.saveDebugLocked(next); err != nil {
		t.Fatal(err)
	}
}

func TestUploadLogsNowAndPullTriggeredUpload(t *testing.T) {
	var mu sync.Mutex
	var started int
	var uploaded []DebugLogChunkRequest
	a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/client/debug/start":
			mu.Lock()
			started++
			mu.Unlock()
			writeJSON(t, w, DebugCollectionStartResponse{OK: true, ReferenceCode: "11223344", DebugCollection: debugTestMeta(debugTestA)})
		case "/api/client/debug/log":
			var body DebugLogChunkRequest
			if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
				t.Error(err)
				return
			}
			mu.Lock()
			uploaded = append(uploaded, body)
			mu.Unlock()
			writeJSON(t, w, map[string]any{"ok": true, "accepted": true})
		case "/api/client/configs/pull":
			writeJSON(t, w, PullResponse{OK: true, ConfigRevision: "rev-1", Client: ClientMeta{ID: 1, DebugCollection: debugTestMeta(debugTestB)}})
		default:
			t.Errorf("unexpected path %s", r.URL.Path)
		}
	})
	a.log.Error("lease", "relay 198.51.100.77 refused token=topsecret")
	res, err := a.uploadLogsNow(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if res["reference_code"] != "11223344" || !validDebugUUID(res["upload_id"].(string)) {
		t.Fatalf("bad result: %v", res)
	}
	if err := a.pullOnce(context.Background()); err != nil {
		t.Fatal(err)
	}
	a.submitDebugLogs(context.Background(), debugTestB)
	s := debugSnapshot(a)
	if s.Pending != nil || s.Requested != "" || !s.completed(debugTestA) || !s.completed(debugTestB) {
		t.Fatalf("bad completion state: %+v", s)
	}
	mu.Lock()
	defer mu.Unlock()
	if started != 1 || len(uploaded) != 2 || uploaded[0].UploadID == uploaded[1].UploadID {
		t.Fatalf("started=%d uploads=%+v", started, uploaded)
	}
	for _, body := range uploaded {
		raw, err := base64.StdEncoding.DecodeString(body.ChunkBase64)
		if err != nil {
			t.Fatal(err)
		}
		if strings.Contains(string(raw), "198.51.100.77") || strings.Contains(string(raw), "topsecret") {
			t.Fatalf("uploaded bundle leaked sensitive data: %s", raw)
		}
	}
}

func TestDebugGoneOnceAndInactiveClear(t *testing.T) {
	var mu sync.Mutex
	posts := 0
	coll := debugTestMeta(debugTestA)
	a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		if r.URL.Path == "/api/client/debug/log" {
			posts++
			w.WriteHeader(http.StatusGone)
			_, _ = io.WriteString(w, `{"ok":false,"error":"debug_collection_expired"}`)
			return
		}
		writeJSON(t, w, PullResponse{OK: true, Client: ClientMeta{DebugCollection: coll}})
	})
	for i := 0; i < 3; i++ {
		if err := a.pullOnce(context.Background()); err != nil {
			t.Fatal(err)
		}
		a.submitDebugLogs(context.Background(), debugTestA)
	}
	mu.Lock()
	if posts != 1 {
		t.Fatalf("410 repeated %d times", posts)
	}
	coll = debugTestMeta(debugTestB)
	mu.Unlock()
	if err := a.pullOnce(context.Background()); err != nil {
		t.Fatal(err)
	}
	mu.Lock()
	coll = &DebugCollectionMeta{UUID: debugTestB, Status: "closed", SupportsUploadID: true}
	mu.Unlock()
	if err := a.pullOnce(context.Background()); err != nil {
		t.Fatal(err)
	}
	if s := debugSnapshot(a); s.Requested != "" || s.Pending != nil || s.completed(debugTestB) {
		t.Fatalf("inactive must clear without claiming completion: %+v", s)
	}
	// Terminal receipts survive a process restart, even if pull still says active.
	store, err := newStore(a.cfg.DataDir)
	if err != nil {
		t.Fatal(err)
	}
	restarted := newAgent(a.cfg, store, SecretState{}, newLogger("error"))
	restarted.panel = a.panel
	debugObserve(t, restarted, debugTestMeta(debugTestA))
	if s := debugSnapshot(restarted); s.Requested != "" {
		t.Fatalf("restart requeued terminal collection: %+v", s)
	}
}

func TestDebug429LostACKAndRestartReplayIdenticalBytes(t *testing.T) {
	var mu sync.Mutex
	var bodies [][]byte
	var decoded []DebugLogChunkRequest
	a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/client/debug/log" {
			t.Errorf("unexpected path %s", r.URL.Path)
			w.WriteHeader(500)
			return
		}
		raw, _ := io.ReadAll(r.Body)
		var body DebugLogChunkRequest
		if err := json.Unmarshal(raw, &body); err != nil {
			t.Error(err)
		}
		mu.Lock()
		bodies = append(bodies, raw)
		decoded = append(decoded, body)
		n := len(bodies)
		mu.Unlock()
		switch n {
		case 1: // Accepted sequence zero.
			writeJSON(t, w, map[string]any{"ok": true})
		case 2: // Rate limit sequence one; must not complete or rebuild.
			w.WriteHeader(http.StatusTooManyRequests)
			_, _ = io.WriteString(w, `{"error":"rate_limited"}`)
		case 3: // Sequence one is committed by the server, but ACK is lost.
			conn, _, err := w.(http.Hijacker).Hijack()
			if err != nil {
				t.Error(err)
				return
			}
			_ = conn.Close()
		default: // Identical duplicate after process restart.
			writeJSON(t, w, map[string]any{"ok": true, "duplicate": true})
		}
	})
	original := bytes.Repeat([]byte("immutable diagnostic bytes\n"), debugLogChunkBytes/len("immutable diagnostic bytes\n")+7)
	debugSeedBundle(t, a, debugTestA, original)
	a.submitDebugLogs(context.Background(), debugTestA)
	s := debugSnapshot(a)
	if s.Pending == nil || s.Pending.Sequence != 1 || s.Pending.Failures != 1 || !s.Pending.RetryAt.After(time.Now()) || s.completed(debugTestA) {
		t.Fatalf("429 did not retain cursor/backoff: %+v", s.Pending)
	}
	a.submitDebugLogs(context.Background(), debugTestA) // Backoff prevents a POST.
	mu.Lock()
	if len(bodies) != 2 {
		t.Fatal("backoff bypassed")
	}
	mu.Unlock()
	a.log.Error("test", "logs changed between attempts")
	debugRetryReady(t, a)
	a.submitDebugLogs(context.Background(), debugTestA)
	if s := debugSnapshot(a); s.Pending == nil || s.Pending.Sequence != 1 {
		t.Fatal("lost ACK advanced cursor")
	}
	store, err := newStore(a.cfg.DataDir)
	if err != nil {
		t.Fatal(err)
	}
	restarted := newAgent(a.cfg, store, SecretState{}, newLogger("error"))
	restarted.panel = a.panel
	debugObserve(t, restarted, debugTestMeta(debugTestA))
	debugRetryReady(t, restarted)
	restarted.log.Error("test", "different logs after restart")
	restarted.submitDebugLogs(context.Background(), debugTestA)
	if s := debugSnapshot(restarted); s.Pending != nil || !s.completed(debugTestA) {
		t.Fatal("restart did not finish")
	}
	mu.Lock()
	defer mu.Unlock()
	if len(bodies) != 4 || !bytes.Equal(bodies[1], bodies[2]) || !bytes.Equal(bodies[2], bodies[3]) {
		t.Fatalf("retried body changed across 429/lost ACK/restart: %d requests", len(bodies))
	}
	if decoded[0].Sequence != 0 || decoded[1].Sequence != 1 || decoded[0].UploadID != decoded[3].UploadID || !validDebugUUID(decoded[3].UploadID) {
		t.Fatalf("identity/cursor changed: %+v", decoded)
	}
}

func TestDebugManualPassiveSerialized(t *testing.T) {
	entered, release := make(chan struct{}), make(chan struct{})
	var mu sync.Mutex
	posts, starts := 0, 0
	a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/client/debug/log":
			mu.Lock()
			posts++
			n := posts
			mu.Unlock()
			if n == 1 {
				close(entered)
				<-release
			}
			w.WriteHeader(http.StatusTooManyRequests)
		case "/api/client/debug/start":
			mu.Lock()
			starts++
			mu.Unlock()
		default:
			t.Errorf("unexpected path %s", r.URL.Path)
		}
	})
	debugSeedBundle(t, a, debugTestA, []byte("stable\n"))
	passiveDone := make(chan struct{})
	go func() {
		defer close(passiveDone)
		a.submitDebugLogs(context.Background(), debugTestA)
	}()
	<-entered
	manualDone := make(chan error, 1)
	go func() {
		_, err := a.uploadLogsNow(context.Background())
		manualDone <- err
	}()
	// A second caller waiting at the gate must be cancellable without a POST.
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := a.uploadLogsNow(ctx); !errors.Is(err, context.Canceled) {
		t.Fatalf("cancelled waiter: %v", err)
	}
	close(release)
	<-passiveDone
	if err := <-manualDone; !errors.Is(err, errDebugDeferred) {
		t.Fatalf("manual did not reuse pending upload: %v", err)
	}
	mu.Lock()
	defer mu.Unlock()
	if posts != 1 || starts != 0 {
		t.Fatalf("manual/passive raced: posts=%d starts=%d", posts, starts)
	}
}

func TestDebugCancelledUploadRemainsPending(t *testing.T) {
	entered := make(chan struct{})
	a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
		_, _ = io.Copy(io.Discard, r.Body)
		close(entered)
		<-r.Context().Done()
	})
	debugSeedBundle(t, a, debugTestA, []byte("cancelled bytes\n"))
	before := debugSnapshot(a).Pending
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { defer close(done); a.submitDebugLogs(ctx, debugTestA) }()
	<-entered
	cancel()
	select {
	case <-done:
	case <-time.After(3 * time.Second):
		t.Fatal("upload ignored cancellation")
	}
	s := debugSnapshot(a)
	if s.Pending == nil || s.Pending.UploadID != before.UploadID || s.Pending.Sequence != 0 || s.completed(debugTestA) || !bytes.Equal(s.Pending.Bundle, before.Bundle) {
		t.Fatalf("cancellation changed pending work: %+v", s)
	}
}

func TestDebugLateCompletionAndInactivePullCannotClearNewer(t *testing.T) {
	for _, status := range []int{http.StatusOK, http.StatusGone} {
		t.Run(itoa(status), func(t *testing.T) {
			entered, release := make(chan struct{}), make(chan struct{})
			a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
				close(entered)
				<-release
				w.WriteHeader(status)
			})
			debugSeedBundle(t, a, debugTestA, []byte("old collection"))
			oldEpoch := a.debugObservation()
			done := make(chan struct{})
			go func() { defer close(done); a.submitDebugLogs(context.Background(), debugTestA) }()
			<-entered
			debugObserve(t, a, debugTestMeta(debugTestB))
			if err := a.observeDebugCollection(nil, oldEpoch); err != nil {
				t.Fatal(err)
			}
			debugObserve(t, a, &DebugCollectionMeta{UUID: debugTestA, Status: "expired", SupportsUploadID: true})
			close(release)
			<-done
			if s := debugSnapshot(a); s.Requested != debugTestB || s.completed(debugTestA) || s.completed(debugTestB) {
				t.Fatalf("old completion/inactive metadata cleared newer request: %+v", s)
			}
		})
	}
}

func TestDebugErrorClassification(t *testing.T) {
	for _, tc := range []struct {
		status int
		code   string
		want   debugErrorAction
	}{
		{410, "debug_collection_expired", debugTerminal},
		{404, "debug_collection_not_found", debugTerminal},
		{404, "route_not_found", debugRetry},
		{409, "debug_chunk_replay", debugTerminal},
		{409, "other_conflict", debugRetry},
		{413, "debug_collection_quota_exceeded", debugTerminal},
		{422, "debug_chunk_invalid", debugTerminal},
		{408, "timeout", debugRetry}, {429, "rate_limited", debugRetry},
		{500, "internal", debugRetry}, {503, "unavailable", debugRetry},
		{401, "unauthorized", debugPause}, {403, "forbidden", debugPause},
	} {
		t.Run(itoa(tc.status)+tc.code, func(t *testing.T) {
			if got := classifyDebugError(&panelError{Status: tc.status, Code: tc.code}); got != tc.want {
				t.Fatalf("classification=%v want %v", got, tc.want)
			}
		})
	}
	if classifyDebugError(io.ErrUnexpectedEOF) != debugRetry {
		t.Fatal("network errors must retry")
	}
}

func TestDebugAuthenticationPausePersists(t *testing.T) {
	var mu sync.Mutex
	posts := 0
	a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		posts++
		mu.Unlock()
		w.WriteHeader(http.StatusForbidden)
	})
	debugSeedBundle(t, a, debugTestA, []byte("auth pause"))
	a.submitDebugLogs(context.Background(), debugTestA)
	debugRetryReady(t, a)
	a.submitDebugLogs(context.Background(), debugTestA)
	if s := debugSnapshot(a); s.Pending == nil || !s.Pending.AuthPaused || s.completed(debugTestA) {
		t.Fatalf("403 was not paused: %+v", s)
	}
	store, err := newStore(a.cfg.DataDir)
	if err != nil {
		t.Fatal(err)
	}
	restarted := newAgent(a.cfg, store, SecretState{}, newLogger("error"))
	restarted.panel = a.panel
	restarted.submitDebugLogs(context.Background(), debugTestA)
	mu.Lock()
	if posts != 1 {
		t.Fatalf("paused upload retried across restart: %d", posts)
	}
	mu.Unlock()
	debugObserve(t, restarted, debugTestMeta(debugTestA))
	restarted.submitDebugLogs(context.Background(), debugTestA)
	mu.Lock()
	defer mu.Unlock()
	if posts != 2 {
		t.Fatal("authenticated metadata did not release pause")
	}
}

func TestDebugUnsupportedCapability(t *testing.T) {
	posts := 0
	a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/client/debug/start" {
			writeJSON(t, w, map[string]any{"ok": true, "debug_collection": map[string]any{"uuid": debugTestA, "status": "active"}})
			return
		}
		posts++
	})
	if _, err := a.uploadLogsNow(context.Background()); !errors.Is(err, errDebugUnsupported) {
		t.Fatalf("manual unsupported result: %v", err)
	}
	debugObserve(t, a, &DebugCollectionMeta{UUID: debugTestA, Status: "active"})
	a.submitDebugLogs(context.Background(), debugTestA)
	if s := debugSnapshot(a); posts != 0 || s.Requested != "" || s.Pending != nil {
		t.Fatalf("legacy panel received upload: posts=%d state=%+v", posts, s)
	}
}

func TestDebugStateIOFailsClosed(t *testing.T) {
	for _, mode := range []string{"corrupt", "oversize", "write", "ack-write"} {
		t.Run(mode, func(t *testing.T) {
			var a *Agent
			posts := 0
			a = debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
				posts++
				if mode == "ack-write" {
					// Force rename failure after the server accepts the chunk.
					path := filepath.Join(a.store.dir, debugStateFile)
					if err := os.Remove(path); err != nil {
						t.Error(err)
					}
					if err := os.Mkdir(path, 0o700); err != nil {
						t.Error(err)
					}
				}
				writeJSON(t, w, map[string]any{"ok": true})
			})
			path := filepath.Join(a.store.dir, debugStateFile)
			switch mode {
			case "corrupt":
				if err := os.WriteFile(path, []byte("{"), 0o600); err != nil {
					t.Fatal(err)
				}
			case "oversize":
				if err := os.WriteFile(path, bytes.Repeat([]byte(" "), debugStateLimit+1), 0o600); err != nil {
					t.Fatal(err)
				}
			case "write":
				// Read succeeded as absent, but committing a request fails.
				a.debugMu.Lock()
				_ = a.loadDebugLocked()
				a.debugMu.Unlock()
				if err := os.Mkdir(path, 0o700); err != nil {
					t.Fatal(err)
				}
			case "ack-write":
				debugSeedBundle(t, a, debugTestA, []byte("persist cursor before sending next"))
			}
			if mode != "ack-write" {
				if err := a.observeDebugCollection(debugTestMeta(debugTestA), a.debugObservation()); err == nil {
					t.Fatal("IO error ignored")
				}
			}
			a.submitDebugLogs(context.Background(), debugTestA)
			a.submitDebugLogs(context.Background(), debugTestA)
			want := 0
			if mode == "ack-write" {
				want = 1
			}
			if posts != want || debugSnapshot(a).completed(debugTestA) {
				t.Fatalf("state failure did not stop uploads: posts=%d want %d", posts, want)
			}
		})
	}
}

func TestDebugBindingChangeDiscardsOldPayloadBeforePOST(t *testing.T) {
	for _, change := range []string{"token", "client", "device", "panel", "config-panel", "restart-account"} {
		t.Run(change, func(t *testing.T) {
			posts := 0
			a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
				posts++
				writeJSON(t, w, map[string]any{"ok": true})
			})
			debugSeedBundle(t, a, debugTestA, []byte("private old-account diagnostic"))
			raw, err := os.ReadFile(filepath.Join(a.store.dir, debugStateFile))
			if err != nil {
				t.Fatal(err)
			}
			var disk map[string]json.RawMessage
			if err := json.Unmarshal(raw, &disk); err != nil {
				t.Fatal(err)
			}
			if strings.Contains(string(raw), `"token"`) || bytes.Contains(raw, []byte(a.panel.endpointLabel())) || len(debugSnapshot(a).Binding) != 64 {
				t.Fatal("binding persisted raw token or panel identity")
			}
			switch change {
			case "token":
				a.panel.setToken("new-account-secret", 0)
			case "client":
				a.panel.setToken("tok", 2)
			case "device":
				a.panel.mu.Lock()
				a.panel.deviceID = "new-device"
				a.panel.mu.Unlock()
			case "panel":
				a.panel.mu.Lock()
				a.panel.endpoints = append(a.panel.endpoints, "http://different-panel.invalid")
				a.panel.mu.Unlock()
			case "config-panel":
				a.mu.Lock()
				a.cfg.PanelURL = "http://different-panel.invalid"
				a.mu.Unlock()
			case "restart-account":
				store, err := newStore(a.cfg.DataDir)
				if err != nil {
					t.Fatal(err)
				}
				a = newAgent(a.cfg, store, SecretState{AgentToken: "new-account-secret", ClientID: 2}, newLogger("error"))
			}
			a.submitDebugLogs(context.Background(), debugTestA)
			if s := debugSnapshot(a); posts != 0 || s.Requested != "" || s.Pending != nil || s.completed(debugTestA) {
				t.Fatalf("old payload survived identity change: posts=%d state=%+v", posts, s)
			}
		})
	}
}

func TestDebugEnrollmentDuringUploadPinsOldIdentityAndStopsNextChunk(t *testing.T) {
	entered, release := make(chan struct{}), make(chan struct{})
	var mu sync.Mutex
	var tokens []string
	a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		tokens = append(tokens, r.Header.Get("Authorization"))
		mu.Unlock()
		close(entered)
		<-release
		writeJSON(t, w, map[string]any{"ok": true})
	})
	debugSeedBundle(t, a, debugTestA, bytes.Repeat([]byte("x"), debugLogChunkBytes+1))
	done := make(chan struct{})
	go func() { defer close(done); a.submitDebugLogs(context.Background(), debugTestA) }()
	<-entered
	a.panel.setToken("new-account-secret", 2)
	close(release)
	<-done
	mu.Lock()
	defer mu.Unlock()
	if len(tokens) != 1 || tokens[0] != "Bearer tok" || debugSnapshot(a).Pending != nil {
		t.Fatalf("identity switched under in-flight upload: %v", tokens)
	}
}

func TestDebugStateBudgetHoldsMaximumBundleAndRejectsExcess(t *testing.T) {
	a := newTestAgent(t, defaultConfig())
	debugSeedBundle(t, a, debugTestA, bytes.Repeat([]byte("x"), debugLogByteLimit))
	raw, err := os.ReadFile(filepath.Join(a.store.dir, debugStateFile))
	if err != nil {
		t.Fatal(err)
	}
	if len(raw) > debugStateLimit || len(raw) < debugLogByteLimit*4/3 {
		t.Fatalf("base64 state size=%d limit=%d", len(raw), debugStateLimit)
	}
	var state debugUploadState
	if err := json.Unmarshal(raw, &state); err != nil {
		t.Fatal(err)
	}
	if err := state.validate(); err != nil {
		t.Fatal(err)
	}
	state.Pending.Bundle = append(state.Pending.Bundle, 'x')
	if err := state.validate(); err == nil {
		t.Fatal("oversize bundle accepted")
	}
}

func TestDebugHeartbeatDoesNotWaitForUpload(t *testing.T) {
	entered, release := make(chan struct{}), make(chan struct{})
	a := debugTestAgent(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/client/debug/log" {
			close(entered)
			<-release
		}
		writeJSON(t, w, map[string]any{"ok": true})
	})
	debugSeedBundle(t, a, debugTestA, []byte("background upload"))
	done := make(chan struct{})
	go func() { defer close(done); a.submitDebugLogs(context.Background(), debugTestA) }()
	<-entered
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	err := a.sendHeartbeat(ctx)
	close(release)
	<-done
	if err != nil {
		t.Fatalf("heartbeat blocked by upload: %v", err)
	}
}
