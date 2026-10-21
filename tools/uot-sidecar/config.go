package main

// Config loading mirrors sidecar-agent/leikwan_external_uot.py: the same JSON
// keys, the same defaults and the same tolerance for numeric strings, so an
// existing /etc/leikwan-external-uot.json can be handed to this binary as-is.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"math"
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
	// allowPrivateHostnameTargets lets a hostname target resolve to a
	// non-global address (loopback, RFC 1918, link-local, ...). It is off by
	// default; IP-literal targets are never affected by it.
	allowPrivateHostnameTargets bool
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

// loadSnapshot reads and fully validates a configuration file and returns the
// immutable snapshot a configStore publishes. Every load allocates fresh maps,
// so a published snapshot never shares mutable state with an older one.
func loadSnapshot(path string) (*sidecarConfig, error) {
	config, err := loadConfig(path)
	if err != nil {
		return nil, err
	}
	return &config, nil
}

func parseConfig(raw []byte) (sidecarConfig, error) {
	decoder := json.NewDecoder(strings.NewReader(string(raw)))
	decoder.UseNumber()
	var document map[string]any
	if err := decoder.Decode(&document); err != nil {
		return sidecarConfig{}, fmt.Errorf("config is not a JSON object: %w", err)
	}
	if err := rejectDuplicateForwardIDs(raw); err != nil {
		return sidecarConfig{}, err
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
		rateLimit, err := parseRateLimitMbps(entry["rate_limit_mbps"])
		if err != nil {
			return sidecarConfig{}, fmt.Errorf("forward %d: %w", forwardID, err)
		}
		forwards[forwardID] = forwardBinding{
			forwardID:     forwardID,
			secret:        []byte(secret),
			targets:       targets,
			staticToken:   strings.TrimSpace(asJSONString(entry["token"])),
			rateLimitMbps: rateLimit,
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
	allowPrivate, err := boolOrDefault(document, "allow_private_hostname_targets", false)
	if err != nil {
		return sidecarConfig{}, err
	}
	config.allowPrivateHostnameTargets = allowPrivate
	if err := config.validate(); err != nil {
		return sidecarConfig{}, err
	}
	return config, nil
}

// maxRateLimitMbps bounds rate_limit_mbps. The panel and the standalone merge
// script apply the same ceiling, so a larger value can only be a corrupt file.
const maxRateLimitMbps = 100000

// parseRateLimitMbps reads the optional per-forward sidecar speed limit. A
// missing, null or empty value means unlimited (0). The value is in Mbps where
// 1 Mbps = 1,000,000 bit/s, matching tools/ultra-client/policy.go. Anything
// that is not a finite number in [0, maxRateLimitMbps] rejects the whole file,
// so a reload never silently drops a limit the panel meant to enforce.
func parseRateLimitMbps(value any) (float64, error) {
	var text string
	switch typed := value.(type) {
	case nil:
		return 0, nil
	case json.Number:
		text = typed.String()
	case string:
		text = strings.TrimSpace(typed)
		if text == "" {
			return 0, nil
		}
	default:
		return 0, fmt.Errorf("rate_limit_mbps must be a number")
	}
	parsed, err := strconv.ParseFloat(text, 64)
	if err != nil || math.IsNaN(parsed) || math.IsInf(parsed, 0) {
		return 0, fmt.Errorf("rate_limit_mbps must be a number")
	}
	if parsed < 0 || parsed > maxRateLimitMbps {
		return 0, fmt.Errorf("rate_limit_mbps must be between 0 and %d", maxRateLimitMbps)
	}
	return parsed, nil
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

// rejectDuplicateForwardIDs fails a document that declares one forward id
// twice. encoding/json silently keeps the last duplicate key, so without this
// scan a hand-edited file with two entries for one forward would load with half
// of the operator's intent applied. The Python sidecar is equally permissive;
// rejecting the file outright is the safe behaviour for a reload.
func rejectDuplicateForwardIDs(raw []byte) error {
	keys, err := forwardIDsInOrder(raw)
	if err != nil {
		// The main decoder reports malformed documents with a better message;
		// this scan only adds duplicate detection on top of it.
		return nil
	}
	seen := make(map[string]struct{}, len(keys))
	for _, key := range keys {
		if _, duplicate := seen[key]; duplicate {
			return fmt.Errorf("duplicate forward id %q", key)
		}
		seen[key] = struct{}{}
	}
	return nil
}

// forwardIDsInOrder returns the keys of the forwards object in file order,
// duplicates included.
func forwardIDsInOrder(raw []byte) ([]string, error) {
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	token, err := decoder.Token()
	if err != nil {
		return nil, err
	}
	if delim, ok := token.(json.Delim); !ok || delim != '{' {
		return nil, fmt.Errorf("config is not a JSON object")
	}
	ids := []string{}
	for decoder.More() {
		keyToken, err := decoder.Token()
		if err != nil {
			return nil, err
		}
		key, ok := keyToken.(string)
		if !ok {
			return nil, fmt.Errorf("config key is invalid")
		}
		if key == "forwards" || key == "forward_bindings" {
			keys, err := objectKeysInOrder(decoder)
			if err != nil {
				return nil, err
			}
			ids = append(ids, keys...)
			continue
		}
		if err := skipJSONValue(decoder); err != nil {
			return nil, err
		}
	}
	return ids, nil
}

// objectKeysInOrder consumes the object at the decoder's cursor and returns its
// keys in file order.
func objectKeysInOrder(decoder *json.Decoder) ([]string, error) {
	token, err := decoder.Token()
	if err != nil {
		return nil, err
	}
	if delim, ok := token.(json.Delim); !ok || delim != '{' {
		return nil, fmt.Errorf("forwards must be an object")
	}
	keys := []string{}
	for decoder.More() {
		keyToken, err := decoder.Token()
		if err != nil {
			return nil, err
		}
		key, ok := keyToken.(string)
		if !ok {
			return nil, fmt.Errorf("forward id is invalid")
		}
		keys = append(keys, strings.TrimSpace(key))
		if err := skipJSONValue(decoder); err != nil {
			return nil, err
		}
	}
	if _, err := decoder.Token(); err != nil { // consume the closing brace
		return nil, err
	}
	return keys, nil
}

// skipJSONValue consumes one complete JSON value (scalar, object or array).
func skipJSONValue(decoder *json.Decoder) error {
	token, err := decoder.Token()
	if err != nil {
		return err
	}
	if _, isDelim := token.(json.Delim); !isDelim {
		return nil
	}
	depth := 1
	for depth > 0 {
		token, err := decoder.Token()
		if err != nil {
			return err
		}
		if delim, ok := token.(json.Delim); ok {
			switch delim {
			case '{', '[':
				depth++
			default:
				depth--
			}
		}
	}
	return nil
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

// boolOrDefault reads an optional boolean switch. A missing or null value is
// the default; anything that is not clearly true or false rejects the file, so
// a typo can never silently flip a security switch.
func boolOrDefault(document map[string]any, key string, fallback bool) (bool, error) {
	switch typed := document[key].(type) {
	case nil:
		return fallback, nil
	case bool:
		return typed, nil
	case json.Number:
		switch typed.String() {
		case "0":
			return false, nil
		case "1":
			return true, nil
		}
	case string:
		switch strings.ToLower(strings.TrimSpace(typed)) {
		case "":
			return fallback, nil
		case "true", "1", "yes", "on":
			return true, nil
		case "false", "0", "no", "off":
			return false, nil
		}
	}
	return false, fmt.Errorf("%s must be a boolean", key)
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
			"rate_limit_mbps":  binding.rateLimitMbps,
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
