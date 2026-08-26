package main

import (
	"bytes"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestLoadConfigFromStdinNeverCreatesAFile(t *testing.T) {
	directory := t.TempDir()
	raw := []byte(`{"forward_id":7,"listen_host":"127.0.0.1","listen_port":23007,"target":"198.51.100.9:443","control_socket":"/tmp/lk.sock"}`)

	config, err := loadConfig("", true, bytes.NewReader(raw))
	if err != nil {
		t.Fatal(err)
	}
	if config.Target != "198.51.100.9:443" {
		t.Fatalf("target=%q", config.Target)
	}
	entries, err := os.ReadDir(directory)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 0 {
		t.Fatalf("stdin config created files: %v", entries)
	}
}

func TestLoadConfigFromStdinRejectsMoreThan64KiB(t *testing.T) {
	_, err := loadConfig("", true, bytes.NewReader(make([]byte, maxConfigPayload+1)))
	if !errors.Is(err, ErrFrameTooLarge) {
		t.Fatalf("err=%v", err)
	}
}

func TestLoadConfigRequiresOneSourceAndOneJSONValue(t *testing.T) {
	valid := []byte(`{"forward_id":7,"listen_port":23007,"target":"198.51.100.9:443"}`)
	if _, err := loadConfig("config.json", true, bytes.NewReader(valid)); !errors.Is(err, ErrFrameInvalid) {
		t.Fatalf("mutually exclusive sources err=%v", err)
	}
	if _, err := loadConfig("", false, bytes.NewReader(valid)); !errors.Is(err, ErrFrameInvalid) {
		t.Fatalf("missing source err=%v", err)
	}
	if _, err := loadConfig("", true, bytes.NewReader(append(valid, []byte(` {}`)...))); !errors.Is(err, ErrFrameInvalid) {
		t.Fatalf("trailing value err=%v", err)
	}
}

func TestLoadConfigFromFileUsesTheSameBoundedDecoder(t *testing.T) {
	path := filepath.Join(t.TempDir(), "runtime.json")
	if err := os.WriteFile(path, []byte(`{"forward_id":8,"listen_port":23008,"target":"198.51.100.10:443"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	config, err := loadConfig(path, false, nil)
	if err != nil {
		t.Fatal(err)
	}
	if config.ForwardID != 8 {
		t.Fatalf("forward_id=%d", config.ForwardID)
	}

	canary := "provider-secret-canary"
	_, err = loadConfig("", true, strings.NewReader(`{"forward_id":8,"listen_port":23008,"target":"198.51.100.10:443","unknown":"`+canary+`"}`))
	if err == nil || strings.Contains(err.Error(), canary) {
		t.Fatalf("decoder leaked input value: %v", err)
	}
}
