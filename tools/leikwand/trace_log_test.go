package main

import (
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
)

func TestTraceNewLinesSkipsAlreadyCapturedLines(t *testing.T) {
	lines := []string{"a", "b", "c", "d"}
	if got := traceNewLines(lines, ""); !reflect.DeepEqual(got, lines) {
		t.Fatalf("first capture = %v", got)
	}
	if got := traceNewLines(lines, "b"); !reflect.DeepEqual(got, []string{"c", "d"}) {
		t.Fatalf("after b = %v", got)
	}
	if got := traceNewLines(lines, "d"); len(got) != 0 {
		t.Fatalf("nothing new = %v", got)
	}
	got := traceNewLines(lines, "gone")
	if len(got) != 5 || !strings.Contains(got[0], "wrapped") {
		t.Fatalf("wrapped capture = %v", got)
	}
}

func TestAppendTraceRotatesAtBound(t *testing.T) {
	path := filepath.Join(t.TempDir(), traceFileName)
	chunk := strings.Repeat("x", traceFileBytes/2) + "\n"
	appendTrace(path, chunk)
	appendTrace(path, chunk)
	appendTrace(path, "tail\n")
	if _, err := os.Stat(path + ".1"); err != nil {
		t.Fatalf("expected rotated generation: %v", err)
	}
	raw, _ := os.ReadFile(path)
	if int64(len(raw)) > traceFileBytes {
		t.Fatalf("current generation %d bytes exceeds bound", len(raw))
	}
}

func TestDebugBundleCarriesTraceAndRuntimeStatus(t *testing.T) {
	runDir := t.TempDir()
	if err := os.MkdirAll(filepath.Join(runDir, "292"), 0o700); err != nil {
		t.Fatal(err)
	}
	status := `{"ha_failures":3,"ha_events":[{"reason":"timeout"}],"error_code":"tcp_connect_failed"}`
	if err := os.WriteFile(filepath.Join(runDir, "292", "status.json"), []byte(status), 0o600); err != nil {
		t.Fatal(err)
	}
	restore := runCommandFunc
	logd := "Thu Nov 12 00:25:01 2026 daemon.err leikwand[1]: tcp candidate failed forward_id=292 attempt=1 reason=timeout relay 198.51.100.7:443\n"
	runCommandFunc = func(timeout time.Duration, name string, args ...string) (string, error) {
		if name == "logread" {
			return logd, nil
		}
		return "", nil
	}
	defer func() { runCommandFunc = restore }()

	cfg := defaultConfig()
	cfg.PanelURL = "http://127.0.0.1:1" // diagnose() probes the panel; fail fast
	agent := newTestAgent(t, cfg)
	agent.cfg.RunDir = runDir
	agent.panel = newPanelClient(cfg, SecretState{}, "dev-1", "router")
	var state traceState
	agent.captureTrace(&state)
	bundle := string(agent.buildDebugLogBundle())
	for _, want := range []string{"=== runtime status 292 ===", `"ha_failures":3`, "=== trace", "tcp candidate failed forward_id=292"} {
		if !strings.Contains(bundle, want) {
			t.Fatalf("bundle missing %q:\n%s", want, bundle)
		}
	}
	if strings.Contains(bundle, "198.51.100.7") {
		t.Fatalf("bundle leaked relay IP:\n%s", bundle)
	}
}
