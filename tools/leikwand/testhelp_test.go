package main

import (
	"os"
	"path/filepath"
	"testing"
)

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
