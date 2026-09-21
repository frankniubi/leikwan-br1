package main

// Coverage for the safe configuration reload: a valid reload admits a new
// forward id, a rejected reload keeps the previous snapshot serving untouched,
// an established session survives removal and rotation of its binding, and a
// reload racing concurrent HELLOs never produces a torn read (run with -race).

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// syncBuffer is a writer that can be read while the sidecar logger writes.
type syncBuffer struct {
	mu     sync.Mutex
	buffer bytes.Buffer
}

func (b *syncBuffer) Write(p []byte) (int, error) {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.buffer.Write(p)
}

func (b *syncBuffer) String() string {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.buffer.String()
}

// reloadBindingFixture is one forward of a generated configuration file.
type reloadBindingFixture struct {
	secret  string
	targets []string
}

// writeReloadConfig renders a complete sidecar configuration file. Listeners use
// port 0 so each fixture server picks a free port, and a reload never changes
// the listen address (which the reload refuses, see checkReloadable).
func writeReloadConfig(path string, forwards map[string]reloadBindingFixture) error {
	// Rendered by hand: the fixture fields are unexported, so json.Marshal
	// would silently emit empty bindings for them.
	forwardsDocument := map[string]any{}
	for forwardID, binding := range forwards {
		forwardsDocument[forwardID] = map[string]any{
			"secret":  binding.secret,
			"targets": binding.targets,
		}
	}
	document := map[string]any{
		"listen_host":                 "127.0.0.1",
		"listen_port":                 0,
		"forwards":                    forwardsDocument,
		"max_frame_bytes":             defaultMaxFrameBytes,
		"frame_payload_max":           maxDatagram,
		"queue_packets":               16,
		"keepalive_seconds":           0.05,
		"idle_timeout_seconds":        2,
		"session_grace_seconds":       2,
		"session_limit":               4096,
		"udp_connect_timeout_seconds": 2,
	}
	encoded, err := json.Marshal(document)
	if err != nil {
		return err
	}
	return os.WriteFile(path, encoded, 0o600)
}

func mustWriteReloadConfig(t *testing.T, path string, forwards map[string]reloadBindingFixture) {
	t.Helper()
	if err := writeReloadConfig(path, forwards); err != nil {
		t.Fatalf("config write failed: %v", err)
	}
}

type reloadHarness struct {
	server  *sidecarServer
	store   *configStore
	logger  *sidecarLogger
	address string
	logs    *syncBuffer
}

// startReloadHarness starts a sidecar whose configuration really lives in a file
// so the reload path (read, validate, publish) is exercised end to end.
func startReloadHarness(t *testing.T, path string) *reloadHarness {
	t.Helper()
	config, err := loadConfig(path)
	if err != nil {
		t.Fatalf("initial config rejected: %v", err)
	}
	logs := &syncBuffer{}
	logger := newSidecarLogger("INFO", logs)
	store := newConfigStore(path, &config)
	server := newSidecarServer(store, logger)
	if err := server.start(); err != nil {
		t.Fatalf("server start failed: %v", err)
	}
	t.Cleanup(server.close)
	return &reloadHarness{server: server, store: store, logger: logger, address: server.address(), logs: logs}
}

// reload performs exactly what a SIGHUP or a reload marker triggers.
func (h *reloadHarness) reload() {
	applyReload(h.store, h.logger)
}

// sessionTag builds a deterministic, distinct session id per test participant.
func sessionTag(tag byte) [sessionIDBytes]byte {
	session := [sessionIDBytes]byte{}
	for index := range session {
		session[index] = tag*31 + byte(index*7)
	}
	session[0] = tag
	return session
}

func helloPayloadBytes(secret string, forwardID int64, target string, framePayloadMax int) ([]byte, error) {
	token, err := makeHMACToken([]byte(secret), forwardID, target)
	if err != nil {
		return nil, err
	}
	return json.Marshal(map[string]any{
		"token":             token,
		"forward_id":        forwardID,
		"target":            target,
		"frame_payload_max": framePayloadMax,
		"last_sequence":     0,
	})
}

// attemptHello dials one carrier and returns the decoded HELLO_ACK. Unlike the
// helpers in server_test.go it never fails a test, so concurrent tests can call
// it from goroutines.
func attemptHello(address string, session [sessionIDBytes]byte, secret string, forwardID int64, target string) (map[string]any, error) {
	conn, err := net.DialTimeout("tcp", address, 3*time.Second)
	if err != nil {
		return nil, err
	}
	defer func() { _ = conn.Close() }()
	payload, err := helloPayloadBytes(secret, forwardID, target, 1200)
	if err != nil {
		return nil, err
	}
	_ = conn.SetWriteDeadline(time.Now().Add(3 * time.Second))
	if err := writeFrame(conn, frame{msgType: msgHello, session: session, payload: payload}, defaultMaxFrameBytes); err != nil {
		return nil, err
	}
	_ = conn.SetReadDeadline(time.Now().Add(3 * time.Second))
	incoming, err := readFrame(bufio.NewReader(conn), defaultMaxFrameBytes)
	if err != nil {
		return nil, err
	}
	decoded := map[string]any{}
	if err := json.Unmarshal(incoming.payload, &decoded); err != nil {
		return nil, err
	}
	return decoded, nil
}

func mustAcceptHello(t *testing.T, address string, session [sessionIDBytes]byte, secret string, forwardID int64, target string) *testCarrier {
	t.Helper()
	carrier := dialCarrier(t, address, session)
	carrier.send(t, msgHello, 0, helloPayloadFor(t, secret, forwardID, target, 1200))
	ack := decodeAck(t, carrier.mustRead(t, 3*time.Second).payload)
	if ack["ok"] != true {
		t.Fatalf("HELLO forward=%d was rejected: %v", forwardID, ack)
	}
	return carrier
}

func mustRejectHello(t *testing.T, address string, session [sessionIDBytes]byte, secret string, forwardID int64, target string, want string) {
	t.Helper()
	carrier := dialCarrier(t, address, session)
	carrier.send(t, msgHello, 0, helloPayloadFor(t, secret, forwardID, target, 1200))
	ack := decodeAck(t, carrier.mustRead(t, 3*time.Second).payload)
	if ack["ok"] != false || ack["error"] != want {
		t.Fatalf("HELLO forward=%d ack = %v, want error %q", forwardID, ack, want)
	}
}

// relayRoundTrip pushes one DATA frame through the carrier and returns the bytes
// the UDP echo target sent back.
func relayRoundTrip(t *testing.T, carrier *testCarrier, seq uint64, payload string) string {
	t.Helper()
	carrier.send(t, msgData, seq, []byte("E"+payload))
	return string(carrier.mustReadUDPData(t, 3*time.Second).payload)
}

func TestSidecarReloadAddsForwardForNewHello(t *testing.T) {
	echo := startEchoServer(t)
	path := filepath.Join(t.TempDir(), "leikwan-external-uot.json")
	mustWriteReloadConfig(t, path, map[string]reloadBindingFixture{
		"170": {secret: "secret-a", targets: []string{echo.address()}},
	})
	h := startReloadHarness(t, path)

	// Before the reload: 170 is authorised, 181 does not exist yet.
	carrier := mustAcceptHello(t, h.address, sessionTag(1), "secret-a", 170, echo.address())
	if got := relayRoundTrip(t, carrier, 1, "before"); got != "before" {
		t.Fatalf("round trip before the reload returned %q", got)
	}
	mustRejectHello(t, h.address, sessionTag(2), "secret-b", 181, echo.address(), "forward is not allowed")

	mustWriteReloadConfig(t, path, map[string]reloadBindingFixture{
		"170": {secret: "secret-a", targets: []string{echo.address()}},
		"181": {secret: "secret-b", targets: []string{echo.address()}},
	})
	h.reload()

	// The new id is usable by a new HELLO immediately, with no restart.
	added := mustAcceptHello(t, h.address, sessionTag(3), "secret-b", 181, echo.address())
	if got := relayRoundTrip(t, added, 1, "after"); got != "after" {
		t.Fatalf("round trip on the reloaded forward returned %q", got)
	}
	// The pre-existing id still works, and unknown ids are still refused.
	stillThere := mustAcceptHello(t, h.address, sessionTag(4), "secret-a", 170, echo.address())
	if got := relayRoundTrip(t, stillThere, 1, "still-there"); got != "still-there" {
		t.Fatalf("round trip on the pre-existing forward returned %q", got)
	}
	mustRejectHello(t, h.address, sessionTag(5), "secret-c", 999, echo.address(), "forward is not allowed")
	if logs := h.logs.String(); !strings.Contains(logs, "reloaded") || !strings.Contains(logs, "added=[181]") {
		t.Fatalf("reload was not logged with the added id: %s", logs)
	}
}

func TestSidecarReloadRejectsBadJSONAndKeepsServing(t *testing.T) {
	echo := startEchoServer(t)
	path := filepath.Join(t.TempDir(), "leikwan-external-uot.json")
	mustWriteReloadConfig(t, path, map[string]reloadBindingFixture{
		"170": {secret: "secret-a", targets: []string{echo.address()}},
	})
	h := startReloadHarness(t, path)
	carrier := mustAcceptHello(t, h.address, sessionTag(1), "secret-a", 170, echo.address())
	if got := relayRoundTrip(t, carrier, 1, "before"); got != "before" {
		t.Fatalf("round trip before the corrupt reload returned %q", got)
	}
	before := h.store.snapshot()

	corrupt := `{"forwards": {"170": {"secret": "secret-a"}`
	if err := os.WriteFile(path, []byte(corrupt), 0o600); err != nil {
		t.Fatalf("corrupt write failed: %v", err)
	}
	h.reload()

	if after := h.store.snapshot(); after != before {
		t.Fatal("a rejected reload published a new snapshot")
	}
	logs := h.logs.String()
	if !strings.Contains(logs, "reload rejected") || !strings.Contains(logs, "previous_snapshot=still-serving") {
		t.Fatalf("rejection was not logged with its reason: %s", logs)
	}
	if !strings.Contains(logs, "not a JSON object") {
		t.Fatalf("rejection log does not state the parse failure: %s", logs)
	}
	// The previous snapshot keeps serving: the established session keeps
	// relaying, a brand new HELLO for 170 still succeeds, and nothing from the
	// rejected document leaked into the live configuration.
	if got := relayRoundTrip(t, carrier, 2, "after"); got != "after" {
		t.Fatalf("established session broke after a rejected reload: %q", got)
	}
	mustAcceptHello(t, h.address, sessionTag(2), "secret-a", 170, echo.address())
	mustRejectHello(t, h.address, sessionTag(3), "secret-c", 181, echo.address(), "forward is not allowed")
}

func TestSidecarReloadRejectionsLeaveSnapshotUntouched(t *testing.T) {
	echo := startEchoServer(t)
	path := filepath.Join(t.TempDir(), "leikwan-external-uot.json")
	mustWriteReloadConfig(t, path, map[string]reloadBindingFixture{
		"170": {secret: "secret-a", targets: []string{echo.address()}},
	})
	h := startReloadHarness(t, path)
	before := h.store.snapshot()

	cases := []struct {
		name     string
		document string
		want     string
	}{
		{
			name:     "bad json",
			document: `{"forwards": {`,
			want:     "not a JSON object",
		},
		{
			name:     "duplicate forward id",
			document: `{"listen_host":"127.0.0.1","listen_port":0,"forwards":{"170":{"secret":"a"},"170":{"secret":"b"}}}`,
			want:     `duplicate forward id "170"`,
		},
		{
			name:     "forwards is not an object",
			document: `{"listen_host":"127.0.0.1","listen_port":0,"forwards":[]}`,
			want:     "forwards must be an object",
		},
		{
			name:     "malformed target",
			document: `{"listen_host":"127.0.0.1","listen_port":0,"forwards":{"170":{"secret":"a","targets":["nope"]}}}`,
			want:     "target must be host:port",
		},
		{
			name:     "invalid forward id",
			document: `{"listen_host":"127.0.0.1","listen_port":0,"forwards":{"seventeen":{"secret":"a"}}}`,
			want:     "invalid forward id",
		},
		{
			name:     "frame limit too small",
			document: `{"listen_host":"127.0.0.1","listen_port":0,"forwards":{},"max_frame_bytes":64}`,
			want:     "max_frame_bytes is too small",
		},
		{
			name:     "listen address change",
			document: `{"listen_host":"127.0.0.1","listen_port":1234,"forwards":{}}`,
			want:     "needs a restart",
		},
	}
	for _, testCase := range cases {
		if err := os.WriteFile(path, []byte(testCase.document), 0o600); err != nil {
			t.Fatalf("%s: write failed: %v", testCase.name, err)
		}
		if _, err := h.store.reload(); err == nil || !strings.Contains(err.Error(), testCase.want) {
			t.Fatalf("%s: reload error = %v, want it to mention %q", testCase.name, err, testCase.want)
		}
		if h.store.snapshot() != before {
			t.Fatalf("%s: rejected reload changed the published snapshot", testCase.name)
		}
	}

	// An unreadable (here: missing) file is rejected the same way.
	if err := os.Remove(path); err != nil {
		t.Fatalf("remove failed: %v", err)
	}
	if _, err := h.store.reload(); err == nil {
		t.Fatal("a missing configuration file was accepted")
	}
	if h.store.snapshot() != before {
		t.Fatal("a missing configuration file changed the published snapshot")
	}
	mustAcceptHello(t, h.address, sessionTag(1), "secret-a", 170, echo.address())
}

func TestSidecarReloadRemovalKeepsEstablishedSession(t *testing.T) {
	echo := startEchoServer(t)
	path := filepath.Join(t.TempDir(), "leikwan-external-uot.json")
	mustWriteReloadConfig(t, path, map[string]reloadBindingFixture{
		"170": {secret: "secret-a", targets: []string{echo.address()}},
		"181": {secret: "secret-b", targets: []string{echo.address()}},
	})
	h := startReloadHarness(t, path)
	session := sessionTag(7)
	carrier := mustAcceptHello(t, h.address, session, "secret-a", 170, echo.address())
	if got := relayRoundTrip(t, carrier, 1, "before"); got != "before" {
		t.Fatalf("round trip before the removal returned %q", got)
	}

	// Remove 170 and add 182, so the reload really publishes a different set.
	mustWriteReloadConfig(t, path, map[string]reloadBindingFixture{
		"181": {secret: "secret-b", targets: []string{echo.address()}},
		"182": {secret: "secret-c", targets: []string{echo.address()}},
	})
	h.reload()
	mustAcceptHello(t, h.address, sessionTag(8), "secret-c", 182, echo.address())

	// 1. The established session keeps relaying on its existing carrier.
	if got := relayRoundTrip(t, carrier, 2, "after-removal"); got != "after-removal" {
		t.Fatalf("established session broke when its binding was removed: %q", got)
	}
	// 2. Removal stops NEW HELLOs from using the id.
	mustRejectHello(t, h.address, sessionTag(9), "secret-a", 170, echo.address(), "forward is not allowed")
	// 3. A carrier reattach of the SAME established session is still authorised
	// by the binding negotiated when it was opened.
	reattached := dialCarrier(t, h.address, session)
	reattached.send(t, msgHello, 0, helloPayloadFor(t, "secret-a", 170, echo.address(), 1200))
	ack := decodeAck(t, reattached.mustRead(t, 3*time.Second).payload)
	if ack["ok"] != true || ack["session"] != "OKR" {
		t.Fatalf("established session could not reattach after its binding was removed: %v", ack)
	}
	if got := relayRoundTrip(t, reattached, 3, "after-reattach"); got != "after-reattach" {
		t.Fatalf("reattached session did not relay: %q", got)
	}
}

func TestSidecarReloadTokenRotationKeepsEstablishedSession(t *testing.T) {
	echo := startEchoServer(t)
	path := filepath.Join(t.TempDir(), "leikwan-external-uot.json")
	mustWriteReloadConfig(t, path, map[string]reloadBindingFixture{
		"170": {secret: "secret-a", targets: []string{echo.address()}},
	})
	h := startReloadHarness(t, path)
	session := sessionTag(4)
	carrier := mustAcceptHello(t, h.address, session, "secret-a", 170, echo.address())
	if got := relayRoundTrip(t, carrier, 1, "before"); got != "before" {
		t.Fatalf("round trip before the rotation returned %q", got)
	}

	mustWriteReloadConfig(t, path, map[string]reloadBindingFixture{
		"170": {secret: "secret-rotated", targets: []string{echo.address()}},
	})
	h.reload()
	if logs := h.logs.String(); !strings.Contains(logs, "credentials_changed=[170]") {
		t.Fatalf("rotation was not reported: %s", logs)
	}

	// The in-flight session is unaffected by the rotation.
	if got := relayRoundTrip(t, carrier, 2, "after"); got != "after" {
		t.Fatalf("established session broke when the token rotated: %q", got)
	}
	// The retired token can no longer create a session.
	mustRejectHello(t, h.address, sessionTag(5), "secret-a", 170, echo.address(), "authentication or target binding failed")
	// The rotated token works for a new session.
	mustAcceptHello(t, h.address, sessionTag(6), "secret-rotated", 170, echo.address())
	// The established session may still reattach with the token it negotiated.
	reattached := dialCarrier(t, h.address, session)
	reattached.send(t, msgHello, 0, helloPayloadFor(t, "secret-a", 170, echo.address(), 1200))
	if ack := decodeAck(t, reattached.mustRead(t, 3*time.Second).payload); ack["ok"] != true {
		t.Fatalf("established session could not reattach after the token rotated: %v", ack)
	}
	if got := relayRoundTrip(t, reattached, 3, "rotated-reattach"); got != "rotated-reattach" {
		t.Fatalf("reattached session did not relay: %q", got)
	}
}

func TestSidecarReloadRacingHelloHasNoTornRead(t *testing.T) {
	echoA := startEchoServer(t)
	echoB := startEchoServer(t)
	path := filepath.Join(t.TempDir(), "leikwan-external-uot.json")
	configA := map[string]reloadBindingFixture{"170": {secret: "secret-a", targets: []string{echoA.address()}}}
	configB := map[string]reloadBindingFixture{"170": {secret: "secret-b", targets: []string{echoB.address()}}}
	mustWriteReloadConfig(t, path, configA)
	h := startReloadHarness(t, path)

	reloadDone := make(chan struct{})
	go func() {
		defer close(reloadDone)
		for round := 0; round < 60; round++ {
			candidate := configA
			if round%2 == 0 {
				candidate = configB
			}
			if err := writeReloadConfig(path, candidate); err != nil {
				t.Errorf("reload write failed: %v", err)
				return
			}
			if _, err := h.store.reload(); err != nil {
				t.Errorf("reload failed: %v", err)
				return
			}
		}
	}()

	// A reader that keeps loading the snapshot the way a request does.
	readerStop := make(chan struct{})
	readerDone := make(chan struct{})
	go func() {
		defer close(readerDone)
		for {
			select {
			case <-readerStop:
				return
			default:
			}
			snapshot := h.store.snapshot()
			_ = len(snapshot.forwards)
			_ = snapshot.maxFrameBytes
			if binding, present := snapshot.forwards[170]; present {
				_ = binding.allowsTarget(echoA.address())
			}
		}
	}()

	const clients = 6
	const iterations = 8
	problems := make(chan string, clients*iterations)
	var wg sync.WaitGroup
	for client := 0; client < clients; client++ {
		wg.Add(1)
		go func(client int) {
			defer wg.Done()
			for iteration := 0; iteration < iterations; iteration++ {
				session := sessionTag(byte(client*iterations + iteration + 1))
				authorised := ""
				// At every instant the published snapshot is exactly configA or
				// exactly configB, so some attempt must be accepted. A torn read
				// (half of one snapshot, half of the other) could never
				// authorise either, which is what this loop detects.
				for round := 0; round < 30 && authorised == ""; round++ {
					pairs := [][2]string{{"secret-a", echoA.address()}, {"secret-b", echoB.address()}}
					for _, pair := range pairs {
						ack, err := attemptHello(h.address, session, pair[0], 170, pair[1])
						if err != nil {
							problems <- fmt.Sprintf("client=%d iteration=%d: %v", client, iteration, err)
							break
						}
						if ack["ok"] == true {
							authorised = pair[1]
							break
						}
					}
				}
				if authorised == "" {
					problems <- fmt.Sprintf("client=%d iteration=%d: torn read, neither snapshot authorised a HELLO", client, iteration)
				}
			}
		}(client)
	}
	wg.Wait()
	close(readerStop)
	<-readerDone
	<-reloadDone
	close(problems)
	for problem := range problems {
		t.Error(problem)
	}
}

func TestRunReloadMarkerLoopTriggersOncePerMarker(t *testing.T) {
	marker := filepath.Join(t.TempDir(), "leikwan-external-uot.json.reload")
	stop := make(chan struct{})
	defer close(stop)
	var triggers int32
	logs := &syncBuffer{}
	logger := newSidecarLogger("INFO", logs)
	go runReloadMarkerLoop(stop, marker, 5*time.Millisecond, logger, func() { atomic.AddInt32(&triggers, 1) })

	// Nothing happens until a marker exists.
	time.Sleep(40 * time.Millisecond)
	if got := atomic.LoadInt32(&triggers); got != 0 {
		t.Fatalf("reload triggered without a marker: %d", got)
	}
	if err := os.WriteFile(marker, []byte("reload"), 0o600); err != nil {
		t.Fatalf("marker write failed: %v", err)
	}
	waitForTriggers(t, &triggers, 1)
	if _, err := os.Stat(marker); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("consumed marker still exists: %v", err)
	}
	// A consumed marker does not fire again, a fresh one does.
	time.Sleep(40 * time.Millisecond)
	if got := atomic.LoadInt32(&triggers); got != 1 {
		t.Fatalf("stale marker re-triggered a reload: %d", got)
	}
	if err := os.WriteFile(marker, []byte("reload"), 0o600); err != nil {
		t.Fatalf("marker rewrite failed: %v", err)
	}
	waitForTriggers(t, &triggers, 2)
	if !strings.Contains(logs.String(), "reload marker observed") {
		t.Fatalf("marker trigger was not logged: %s", logs.String())
	}
}

func waitForTriggers(t *testing.T, counter *int32, want int32) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if atomic.LoadInt32(counter) >= want {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatalf("reload trigger count = %d, want %d", atomic.LoadInt32(counter), want)
}
