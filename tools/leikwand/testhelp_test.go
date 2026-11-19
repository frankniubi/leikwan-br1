package main

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

// TestMain lets the test binary stand in for sing-box: a test that sets
// LEIKWAND_FAKE_SINGBOX=1 and points gateway_singbox_bin at os.Args[0] gets a
// "sing-box run" that idles until it is stopped, so ensureSingBox can be
// exercised for real without the binary.
func TestMain(m *testing.M) {
	if os.Getenv("LEIKWAND_FAKE_SINGBOX") == "1" && len(os.Args) > 1 {
		switch os.Args[1] {
		case "run":
			time.Sleep(2 * time.Minute)
			os.Exit(0)
		case "check":
			os.Exit(0)
		}
	}
	os.Exit(m.Run())
}

func writeTempFile(t *testing.T, name, content string) string {
	t.Helper()
	dir := t.TempDir()
	path := filepath.Join(dir, name)
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatalf("write %s: %v", path, err)
	}
	return path
}

func newTestAgent(t *testing.T, cfg Config) *Agent {
	t.Helper()
	// Always isolate: defaultConfig() carries production paths and reusing them
	// would let state leak between tests.
	cfg.DataDir = t.TempDir()
	cfg.RunDir = t.TempDir()
	store, err := newStore(cfg.DataDir)
	if err != nil {
		t.Fatalf("store: %v", err)
	}
	return newAgent(cfg, store, SecretState{}, newLogger("error"))
}
