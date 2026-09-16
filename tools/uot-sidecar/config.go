package main

// Config loading mirrors sidecar-agent/leikwan_external_uot.py: the same JSON
// keys, the same defaults and the same tolerance for numeric strings, so an
// existing /etc/leikwan-external-uot.json can be handed to this binary as-is.

import (
	"encoding/json"
	"fmt"
	"os"
	"sort"
	"strconv"
	"strings"
)

type sidecarConfig struct {
	listenHost               string
	listenPort               int
	forwards                 map[int64]forwardBinding
	maxFrameBytes            int
	framePayloadMax          int
	queuePackets             int
	keepaliveSeconds         float64
	idleTimeoutSeconds       float64
	sessionGraceSeconds      float64
	sessionLimit             int
	udpConnectTimeoutSeconds float64
}

func (c sidecarConfig) validate() error {
	if c.maxFrameBytes < headerSize+minFramePayload {
		return fmt.Errorf("max_frame_bytes is too small")
	}
	if c.framePayloadMax < 1 || c.framePayloadMax > maxDatagram {
		return fmt.Errorf("frame_payload_max is invalid")
	}
	if c.maxFrameBytes < headerSize+c.framePayloadMax {
		return fmt.Errorf("max_frame_bytes must include frame_payload_max")
	}
	if c.queuePackets < 1 || c.sessionLimit < 1 {
		return fmt.Errorf("queue and session limits must be positive")
	}
	if c.keepaliveSeconds <= 0 || c.idleTimeoutSeconds <= 0 || c.sessionGraceSeconds <= 0 {
		return fmt.Errorf("timeouts must be positive")
	}
	return nil
}

func loadConfig(path string) (sidecarConfig, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return sidecarConfig{}, err
	}
	return parseConfig(raw)
}

func parseConfig(raw []byte) (sidecarConfig, error) {
	decoder := json.NewDecoder(strings.NewReader(string(raw)))
	decoder.UseNumber()
	var document map[string]any
	if err := decoder.Decode(&document); err != nil {
		return sidecarConfig{}, fmt.Errorf("config is not a JSON object: %w", err)
	}
	forwardsRaw, present := document["forwards"]
	if !present {
		forwardsRaw, present = document["forward_bindings"]
	}
	if !present {
		forwardsRaw = map[string]any{}
	}
	forwardsObject, ok := forwardsRaw.(map[string]any)
	if !ok {
		return sidecarConfig{}, fmt.Errorf("forwards must be an object")
	}
	forwards := make(map[int64]forwardBinding, len(forwardsObject))
	for rawID, rawBinding := range forwardsObject {
		forwardID, err := strconv.ParseInt(strings.TrimSpace(rawID), 10, 64)
		if err != nil {
			return sidecarConfig{}, fmt.Errorf("invalid forward id %q", rawID)
		}
		entry := map[string]any{}
		switch typed := rawBinding.(type) {
		case string:
			entry["secret"] = typed
		case map[string]any:
			entry = typed
		default:
			return sidecarConfig{}, fmt.Errorf("invalid binding for forward %d", forwardID)
		}
		targetsRaw, present := entry["targets"]
		if !present {
			targetsRaw, present = entry["target"]
		}
		if !present {
			targetsRaw = []any{}
		}
		targets, err := parseTargets(targetsRaw)
		if err != nil {
			return sidecarConfig{}, err
		}
		secret := asJSONString(entry["secret"])
		if secret == "" {
			secret = asJSONString(entry["hmac_secret"])
		}
		forwards[forwardID] = forwardBinding{
			forwardID:   forwardID,
			secret:      []byte(secret),
			targets:     targets,
			staticToken: strings.TrimSpace(asJSONString(entry["token"])),
		}
	}
	config := sidecarConfig{
		listenHost:               stringOrDefault(document, "listen_host", "0.0.0.0"),
		forwards:                 forwards,
		maxFrameBytes:            intOrDefault(document, "max_frame_bytes", defaultMaxFrameBytes),
		framePayloadMax:          intOrDefault(document, "frame_payload_max", maxDatagram),
		queuePackets:             intOrDefault(document, "queue_packets", defaultQueuePackets),
		keepaliveSeconds:         floatOrDefault(document, "keepalive_seconds", defaultKeepaliveSeconds),
		idleTimeoutSeconds:       floatOrDefault(document, "idle_timeout_seconds", defaultIdleTimeoutSecond),
		sessionGraceSeconds:      floatOrDefault(document, "session_grace_seconds", defaultSessionGraceSec),
		sessionLimit:             intOrDefault(document, "session_limit", defaultSessionLimit),
		udpConnectTimeoutSeconds: floatOrDefault(document, "udp_connect_timeout_seconds", defaultUDPConnectTimeout),
	}
	port, ok := falsyIntOrDefault(document["listen_port"], 0)
	if !ok {
		return sidecarConfig{}, fmt.Errorf("listen_port is invalid")
	}
	config.listenPort = port
	if err := config.validate(); err != nil {
		return sidecarConfig{}, err
	}
	return config, nil
}

func parseTargets(value any) (map[string]struct{}, error) {
	items := make([]any, 0, 4)
	switch typed := value.(type) {
	case string:
		items = append(items, typed)
	case []any:
		items = append(items, typed...)
	default:
		return nil, fmt.Errorf("forward targets must be a string or list")
	}
	targets := make(map[string]struct{}, len(items))
	for _, item := range items {
		text := asJSONString(item)
		if text == wildcardTarget {
			targets[wildcardTarget] = struct{}{}
			continue
		}
		normalized, err := normalizeTarget(text)
		if err != nil {
			return nil, err
		}
		targets[normalized] = struct{}{}
	}
	return targets, nil
}

func stringOrDefault(document map[string]any, key string, fallback string) string {
	value := document[key]
	if value == nil {
		return fallback
	}
	if text, ok := value.(string); ok {
		if strings.TrimSpace(text) == "" {
			return fallback
		}
		return text
	}
	return fallback
}

func intOrDefault(document map[string]any, key string, fallback int) int {
	value, ok := falsyIntOrDefault(document[key], fallback)
	if !ok {
		return fallback
	}
	return value
}

// falsyIntOrDefault mirrors `int(raw.get(key, default) or default)`: a missing,
// null, empty-string or zero value falls back to the default.
func falsyIntOrDefault(value any, fallback int) (int, bool) {
	if value == nil {
		return fallback, true
	}
	if text, ok := value.(string); ok && strings.TrimSpace(text) == "" {
		return fallback, true
	}
	parsed, ok := coerceInt(value)
	if !ok {
		return 0, false
	}
	if parsed == 0 {
		return fallback, true
	}
	return int(parsed), true
}

func floatOrDefault(document map[string]any, key string, fallback float64) float64 {
	value := document[key]
	if value == nil {
		return fallback
	}
	switch typed := value.(type) {
	case string:
		trimmed := strings.TrimSpace(typed)
		if trimmed == "" {
			return fallback
		}
		parsed, err := strconv.ParseFloat(trimmed, 64)
		if err != nil || parsed == 0 {
			return fallback
		}
		return parsed
	case json.Number:
		parsed, err := strconv.ParseFloat(typed.String(), 64)
		if err != nil || parsed == 0 {
			return fallback
		}
		return parsed
	default:
		return fallback
	}
}

// summarizeConfig renders a secret-free, sorted-key summary of the effective
// configuration. It exists so an operator (or the parity harness) can diff the
// Go sidecar's interpretation of a config file against the Python sidecar's
// without ever printing a secret or a live token.
func summarizeConfig(config sidecarConfig) []byte {
	forwards := make([]any, 0, len(config.forwards))
	ids := make([]int64, 0, len(config.forwards))
	for forwardID := range config.forwards {
		ids = append(ids, forwardID)
	}
	sort.Slice(ids, func(left int, right int) bool { return ids[left] < ids[right] })
	for _, forwardID := range ids {
		binding := config.forwards[forwardID]
		targets := make([]any, 0, len(binding.targets))
		for target := range binding.targets {
			targets = append(targets, target)
		}
		sort.Slice(targets, func(left int, right int) bool { return targets[left].(string) < targets[right].(string) })
		forwards = append(forwards, map[string]any{
			"forward_id":       forwardID,
			"targets":          targets,
			"has_secret":       len(binding.secret) > 0,
			"has_static_token": binding.staticToken != "",
		})
	}
	return encodeJSON(map[string]any{
		"listen_host":                 config.listenHost,
		"listen_port":                 config.listenPort,
		"forwards":                    forwards,
		"max_frame_bytes":             config.maxFrameBytes,
		"frame_payload_max":           config.framePayloadMax,
		"queue_packets":               config.queuePackets,
		"keepalive_seconds":           config.keepaliveSeconds,
		"idle_timeout_seconds":        config.idleTimeoutSeconds,
		"session_grace_seconds":       config.sessionGraceSeconds,
		"session_limit":               config.sessionLimit,
		"udp_connect_timeout_seconds": config.udpConnectTimeoutSeconds,
	})
}
