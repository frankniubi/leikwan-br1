package main

import (
	"bytes"
	"encoding/json"
	"strings"
	"testing"
)

// ultra-client loads its configuration with json.Decoder.DisallowUnknownFields,
// so a single misspelled key makes the runtime exit with
// "runtime configuration unavailable" and the forward never comes up.
//
// This list is copied from tools/ultra-client/main.go (the Config struct). The
// test is deliberately an independent transcription rather than a reflection
// over RuntimeConfig: reflecting would only prove the struct equals itself.
var runtimeAcceptedKeys = map[string]bool{
	"forward_id":                     true,
	"listen_host":                    true,
	"listen_port":                    true,
	"target":                         true,
	"native_socks5":                  true,
	"native_route_policy":            true,
	"native_port_min":                true,
	"native_filter_profile":          true,
	"probe_target":                   true,
	"control_socket":                 true,
	"policy_generation":              true,
	"target_digest":                  true,
	"transport":                      true,
	"max_tcp_connections":            true,
	"udp_prewarm":                    true,
	"udp_keepalive_interval_seconds": true,
	"udp_queue_packets":              true,
	"udp_max_associations":           true,
	"udp_setup_workers":              true,
	"connect_timeout_seconds":        true,
	"location_cache_path":            true,
	"location_lock_path":             true,
	"status_path":                    true,
	"auto_select":                    true,
	"probe_duration_seconds":         true,
	"probe_timeout_ms":               true,
	"probe_workers":                  true,
	"probe_samples":                  true,
	"probe_interval_ms":              true,
	"zone_aliases":                   true,
}

func emittedKeys(t *testing.T, value any) map[string]bool {
	t.Helper()
	raw, err := json.Marshal(value)
	if err != nil {
		t.Fatalf("marshal runtime config: %v", err)
	}
	var generic map[string]any
	if err := json.Unmarshal(raw, &generic); err != nil {
		t.Fatalf("unmarshal runtime config: %v", err)
	}
	keys := map[string]bool{}
	for key := range generic {
		keys[key] = true
	}
	return keys
}

func assertKnownKeys(t *testing.T, label string, cfg RuntimeConfig) {
	t.Helper()
	for key := range emittedKeys(t, cfg) {
		if !runtimeAcceptedKeys[key] {
			t.Fatalf("%s emits %q, which ultra-client rejects (DisallowUnknownFields)", label, key)
		}
	}
}

func TestForwardRuntimeConfigEmitsOnlyAcceptedKeys(t *testing.T) {
	cfg := defaultConfig()
	forward := ForwardConfig{ForwardID: 3, ListenPort: 10001, Target: "example.com:443", Transport: "tcp+udp", BackendMode: "ultra"}
	policy := &UltraPolicy{PolicyGeneration: "gen", TargetDigest: "digest"}
	assertKnownKeys(t, "forward runtime config", buildRuntimeConfig(cfg, forward, pathsForForward("/run", 3), policy))
}

func TestNativeRuntimeConfigEmitsOnlyAcceptedKeys(t *testing.T) {
	cfg := defaultConfig()
	runtime := buildNativeRuntimeConfig(cfg, 3, gatewayRelayPaths("/run"), strings.Repeat("a", 64), strings.Repeat("b", 64), "restricted")
	assertKnownKeys(t, "native runtime config", runtime)
	// ultra-client exits with "runtime configuration invalid" on an empty
	// target, native mode included.
	if strings.TrimSpace(runtime.Target) == "" || !runtime.NativeSocks5 || runtime.ListenHost != "127.0.0.1" {
		t.Fatalf("native relay config unusable: target=%q native=%v host=%s", runtime.Target, runtime.NativeSocks5, runtime.ListenHost)
	}
	if runtime.ListenPort != gatewaySocksPort || runtime.NativeFilterProfile != "opengfw_tcp" {
		t.Fatalf("listen=%d filter=%s", runtime.ListenPort, runtime.NativeFilterProfile)
	}
	if unrestricted := buildNativeRuntimeConfig(cfg, 3, gatewayRelayPaths("/run"), "", "", "unrestricted"); unrestricted.NativeFilterProfile != "off" || unrestricted.NativeRoutePolicy != "unrestricted" {
		t.Fatalf("unrestricted relay = %s/%s", unrestricted.NativeRoutePolicy, unrestricted.NativeFilterProfile)
	}
}

// The default probe contract has to reach the runtime under the exact keys the
// desktop client uses, otherwise candidate ranking silently falls back to the
// runtime's own defaults.
func TestForwardRuntimeConfigCarriesTheProbeContract(t *testing.T) {
	cfg := defaultConfig()
	forward := ForwardConfig{ForwardID: 3, ListenPort: 10001, Target: "example.com:443", Transport: "tcp"}
	runtime := buildRuntimeConfig(cfg, forward, pathsForForward("/run", 3), nil)

	if runtime.ProbeTarget != defaultProbeTarget {
		t.Fatalf("probe target = %q", runtime.ProbeTarget)
	}
	if runtime.ProbeDurationSecs != defaultProbeDurationSecs ||
		runtime.ProbeTimeoutMillis != defaultProbeTimeoutMillis ||
		runtime.ProbeWorkers != defaultProbeWorkers ||
		runtime.ProbeSamples != defaultProbeSamples ||
		runtime.ProbeIntervalMillis != defaultProbeIntervalMillis {
		t.Fatalf("probe contract drifted: %+v", runtime)
	}
	if !runtime.AutoSelect {
		t.Fatal("auto_select must be on: candidate ranking is the runtime's job")
	}
	if runtime.ControlSocket == "" || runtime.StatusPath == "" {
		t.Fatal("control socket and status path are required for supervision")
	}
	// A TCP-only forward must not enable the UDP prewarm path.
	if runtime.UDPPrewarm {
		t.Fatal("udp_prewarm must stay off for a tcp-only forward")
	}
	udp := buildRuntimeConfig(cfg, ForwardConfig{ForwardID: 3, ListenPort: 10001, Target: "example.com:443", Transport: "tcp+udp"}, pathsForForward("/run", 3), nil)
	if !udp.UDPPrewarm {
		t.Fatal("udp_prewarm must be on when the transport includes udp")
	}
}

// A strict decode of our own output must succeed: this is the same decoder
// mode the runtime uses, so it catches a duplicate or malformed tag.
func TestRuntimeConfigDecodesStrictly(t *testing.T) {
	cfg := defaultConfig()
	forward := ForwardConfig{ForwardID: 3, ListenPort: 10001, Target: "example.com:443", Transport: "tcp+udp"}
	raw, err := json.Marshal(buildRuntimeConfig(cfg, forward, pathsForForward("/run", 3), nil))
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	var decoded RuntimeConfig
	if err := decoder.Decode(&decoded); err != nil {
		t.Fatalf("strict decode of our own config failed: %v", err)
	}
}
