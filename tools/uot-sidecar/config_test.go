package main

import (
	"os"
	"path/filepath"
	"testing"
)

const configFixture = `{
  "listen_host": "0.0.0.0",
  "listen_port": "7443",
  "forwards": {
    "170": {"targets": ["203.0.113.10:5201"], "secret": "fixture-secret"},
    "181": "shorthand-secret",
    "182": {"targets": "*", "token": "static-token", "hmac_secret": "ignored-secret"}
  },
  "max_frame_bytes": 65543,
  "frame_payload_max": 65507,
  "queue_packets": 512,
  "keepalive_seconds": 10,
  "idle_timeout_seconds": 90,
  "session_grace_seconds": 30,
  "session_limit": 128,
  "udp_connect_timeout_seconds": 5
}`

func TestParseConfigMatchesPythonShape(t *testing.T) {
	config, err := parseConfig([]byte(configFixture))
	if err != nil {
		t.Fatalf("parseConfig failed: %v", err)
	}
	if config.listenHost != "0.0.0.0" || config.listenPort != 7443 {
		t.Fatalf("unexpected listen address: %s:%d", config.listenHost, config.listenPort)
	}
	if config.maxFrameBytes != 65543 || config.framePayloadMax != maxDatagram || config.queuePackets != 512 {
		t.Fatalf("unexpected limits: %+v", config)
	}
	if config.keepaliveSeconds != 10 || config.idleTimeoutSeconds != 90 || config.sessionGraceSeconds != 30 || config.sessionLimit != 128 {
		t.Fatalf("unexpected timers: %+v", config)
	}
	if len(config.forwards) != 3 {
		t.Fatalf("expected 3 forwards, got %d", len(config.forwards))
	}
	binding := config.forwards[170]
	if string(binding.secret) != "fixture-secret" {
		t.Fatalf("forward 170 secret = %q", binding.secret)
	}
	if _, ok := binding.targets["203.0.113.10:5201"]; !ok {
		t.Fatalf("forward 170 targets = %v", binding.targets)
	}
	if string(config.forwards[181].secret) != "shorthand-secret" || len(config.forwards[181].targets) != 0 {
		t.Fatalf("shorthand binding parsed as %+v", config.forwards[181])
	}
	wildcard := config.forwards[182]
	if _, ok := wildcard.targets[wildcardTarget]; !ok || wildcard.staticToken != "static-token" {
		t.Fatalf("wildcard binding parsed as %+v", wildcard)
	}
	if _, ok := wildcard.targets["static-token"]; ok {
		t.Fatal("static token leaked into the target list")
	}
	if !wildcard.allowsTarget("198.51.100.20:5300") {
		t.Fatal("wildcard binding rejected a well-formed target")
	}
}

func TestParseConfigDefaults(t *testing.T) {
	config, err := parseConfig([]byte(`{"forwards": {"170": {"secret": "s"}}}`))
	if err != nil {
		t.Fatalf("parseConfig failed: %v", err)
	}
	if config.listenHost != "0.0.0.0" || config.listenPort != 0 {
		t.Fatalf("unexpected listen defaults: %+v", config)
	}
	if config.maxFrameBytes != defaultMaxFrameBytes || config.framePayloadMax != maxDatagram {
		t.Fatalf("unexpected frame defaults: %+v", config)
	}
	if config.queuePackets != defaultQueuePackets || config.sessionLimit != defaultSessionLimit {
		t.Fatalf("unexpected limit defaults: %+v", config)
	}
	if config.keepaliveSeconds != defaultKeepaliveSeconds || config.idleTimeoutSeconds != defaultIdleTimeoutSecond || config.sessionGraceSeconds != defaultSessionGraceSec {
		t.Fatalf("unexpected timer defaults: %+v", config)
	}
	if config.udpConnectTimeoutSeconds != defaultUDPConnectTimeout {
		t.Fatalf("unexpected udp connect timeout: %+v", config)
	}
}

func TestParseConfigRejectsInvalidPayloads(t *testing.T) {
	cases := []string{
		`{"forwards": []}`,
		`{"forwards": {"not-a-number": {"secret": "s"}}}`,
		`{"forwards": {"170": 12}}`,
		`{"forwards": {"170": {"targets": 12}}}`,
		`{"forwards": {"170": {"targets": ["nope"]}}}`,
		`{"forwards": {}, "max_frame_bytes": 64}`,
		`{"forwards": {}, "frame_payload_max": 70000}`,
	}
	for _, payload := range cases {
		if _, err := parseConfig([]byte(payload)); err == nil {
			t.Fatalf("config %s was accepted", payload)
		}
	}
}

func TestLoadConfigFromDisk(t *testing.T) {
	path := filepath.Join(t.TempDir(), "leikwan-external-uot.json")
	if err := os.WriteFile(path, []byte(configFixture), 0o600); err != nil {
		t.Fatalf("write fixture failed: %v", err)
	}
	config, err := loadConfig(path)
	if err != nil {
		t.Fatalf("loadConfig failed: %v", err)
	}
	if config.listenPort != 7443 || len(config.forwards) != 3 {
		t.Fatalf("unexpected config: %+v", config)
	}
}
