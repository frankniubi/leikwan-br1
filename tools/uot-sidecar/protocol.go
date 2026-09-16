package main

// UOT2 wire protocol shared by the operator-owned external UoT sidecar and the
// Ultra runtime client. This Go implementation is a drop-in replacement for
// sidecar-agent/leikwan_external_uot.py: byte-identical framing, identical
// HMAC binding rules and identical HELLO_ACK payloads.
//
// Wire format (per frame):
//
//	uint32be frame_length (header + payload)
//	"UOT2" uint8 version uint8 type uint16 flags
//	uint128 session_id uint64 sequence uint32 payload_length
//	payload

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/netip"
	"strconv"
	"strings"
	"unicode"
)

const (
	protocolMagic   = "UOT2"
	protocolVersion = 1
	sessionIDBytes  = 16
	maxDatagram     = 65507

	// headerSize is magic[4] + version[1] + type[1] + flags[2] + session[16] + sequence[8] + payloadLength[4].
	headerSize = 36
	prefixSize = 4

	msgHello     = 1
	msgHelloAck  = 2
	msgData      = 3
	msgUDPData   = 4
	msgClose     = 5
	msgKeepalive = 6

	minFramePayload          = 256
	defaultFramePayload      = 1200
	defaultMaxFrameBytes     = headerSize + maxDatagram
	defaultQueuePackets      = 256
	defaultKeepaliveSeconds  = 10.0
	defaultIdleTimeoutSecond = 90.0
	defaultSessionGraceSec   = 30.0
	defaultSessionLimit      = 1024
	defaultUDPConnectTimeout = 5.0
)

// protocolError carries the exact rejection text the Python sidecar reports in
// its HELLO_ACK error field, so fleet probes and runbooks keep matching.
type protocolError struct{ reason string }

func (e protocolError) Error() string { return e.reason }

func protocolErrf(format string, args ...any) error {
	return protocolError{reason: fmt.Sprintf(format, args...)}
}

func knownMessageType(msgType byte) bool {
	switch msgType {
	case msgHello, msgHelloAck, msgData, msgUDPData, msgClose, msgKeepalive:
		return true
	default:
		return false
	}
}

type frame struct {
	msgType byte
	flags   uint16
	session [sessionIDBytes]byte
	seq     uint64
	payload []byte
}

func (f frame) body() ([]byte, error) {
	if !knownMessageType(f.msgType) {
		return nil, protocolError{reason: "unknown frame type"}
	}
	if len(f.payload) > 0xFFFFFFFF {
		return nil, protocolError{reason: "payload too large"}
	}
	body := make([]byte, headerSize+len(f.payload))
	copy(body[0:4], protocolMagic)
	body[4] = protocolVersion
	body[5] = f.msgType
	binary.BigEndian.PutUint16(body[6:8], f.flags)
	copy(body[8:24], f.session[:])
	binary.BigEndian.PutUint64(body[24:32], f.seq)
	binary.BigEndian.PutUint32(body[32:36], uint32(len(f.payload)))
	copy(body[headerSize:], f.payload)
	return body, nil
}

func (f frame) wire(maxFrameBytes int) ([]byte, error) {
	body, err := f.body()
	if err != nil {
		return nil, err
	}
	if len(body) > maxFrameBytes {
		return nil, protocolError{reason: "frame exceeds configured maximum"}
	}
	out := make([]byte, prefixSize+len(body))
	binary.BigEndian.PutUint32(out[0:4], uint32(len(body)))
	copy(out[prefixSize:], body)
	return out, nil
}

func parseFrameBody(body []byte) (frame, error) {
	if len(body) < headerSize {
		return frame{}, protocolError{reason: "truncated frame header"}
	}
	if string(body[0:4]) != protocolMagic || body[4] != protocolVersion {
		return frame{}, protocolError{reason: "invalid frame magic or version"}
	}
	msgType := body[5]
	flags := binary.BigEndian.Uint16(body[6:8])
	var session [sessionIDBytes]byte
	copy(session[:], body[8:24])
	seq := binary.BigEndian.Uint64(body[24:32])
	payloadLength := binary.BigEndian.Uint32(body[32:36])
	payload := body[headerSize:]
	if int(payloadLength) != len(payload) {
		return frame{}, protocolError{reason: "payload length mismatch"}
	}
	if !knownMessageType(msgType) {
		return frame{}, protocolError{reason: "unknown frame type"}
	}
	return frame{msgType: msgType, flags: flags, session: session, seq: seq, payload: payload}, nil
}

func readFrame(r io.Reader, maxFrameBytes int) (frame, error) {
	prefix := make([]byte, prefixSize)
	if _, err := io.ReadFull(r, prefix); err != nil {
		return frame{}, err
	}
	length := binary.BigEndian.Uint32(prefix)
	if int(length) < headerSize || int(length) > maxFrameBytes {
		return frame{}, protocolError{reason: "invalid frame length"}
	}
	body := make([]byte, length)
	if _, err := io.ReadFull(r, body); err != nil {
		return frame{}, err
	}
	return parseFrameBody(body)
}

func writeFrame(w io.Writer, f frame, maxFrameBytes int) error {
	wire, err := f.wire(maxFrameBytes)
	if err != nil {
		return err
	}
	_, err = w.Write(wire)
	return err
}

// normalizeTarget mirrors the Python binding normalizer: explicit host:port,
// no DNS resolution, lower-cased host, compressed IP form.
func normalizeTarget(value string) (string, error) {
	text := strings.TrimSpace(value)
	var host, portText string
	if strings.HasPrefix(text, "[") {
		end := strings.Index(text, "]")
		if end < 0 || end+1 >= len(text) || text[end+1] != ':' {
			return "", fmt.Errorf("target must be host:port")
		}
		host = text[1:end]
		portText = text[end+2:]
	} else {
		sep := strings.LastIndex(text, ":")
		if sep < 0 {
			return "", fmt.Errorf("target must be host:port")
		}
		host = text[:sep]
		portText = text[sep+1:]
	}
	host = strings.ToLower(strings.TrimSpace(host))
	if host == "" || strings.ContainsFunc(host, unicode.IsSpace) {
		return "", fmt.Errorf("target host is invalid")
	}
	port, err := strconv.Atoi(strings.TrimSpace(portText))
	if err != nil {
		return "", fmt.Errorf("target port is invalid")
	}
	if port < 1 || port > 65535 {
		return "", fmt.Errorf("target port is invalid")
	}
	if addr, parseErr := netip.ParseAddr(host); parseErr == nil {
		host = addr.String()
	}
	return host + ":" + strconv.Itoa(port), nil
}

func targetParts(value string) (string, string, error) {
	canonical, err := normalizeTarget(value)
	if err != nil {
		return "", "", err
	}
	sep := strings.LastIndex(canonical, ":")
	return canonical[:sep], canonical[sep+1:], nil
}

func bindingMessage(forwardID int64, target string) ([]byte, error) {
	normalized, err := normalizeTarget(target)
	if err != nil {
		return nil, err
	}
	return []byte(strconv.FormatInt(forwardID, 10) + "\n" + normalized), nil
}

// makeHMACToken returns the canonical per-forward token expected in HELLO.token.
func makeHMACToken(secret []byte, forwardID int64, target string) (string, error) {
	message, err := bindingMessage(forwardID, target)
	if err != nil {
		return "", err
	}
	mac := hmac.New(sha256.New, secret)
	_, _ = mac.Write(message)
	return "hmac-sha256:" + hex.EncodeToString(mac.Sum(nil)), nil
}

type forwardBinding struct {
	forwardID   int64
	secret      []byte
	targets     map[string]struct{}
	staticToken string
}

const wildcardTarget = "*"

// allowsTarget reports whether the binding may relay to this target. The token
// HMAC already binds forward_id+target, so an empty or wildcard list means
// allow-any (the target is still normalized, malformed targets stay rejected).
func (b forwardBinding) allowsTarget(target string) bool {
	if len(b.targets) == 0 {
		_, err := normalizeTarget(target)
		return err == nil
	}
	if _, wildcard := b.targets[wildcardTarget]; wildcard {
		_, err := normalizeTarget(target)
		return err == nil
	}
	normalized, err := normalizeTarget(target)
	if err != nil {
		return false
	}
	_, ok := b.targets[normalized]
	return ok
}

func (b forwardBinding) verify(token string, target string) bool {
	if !b.allowsTarget(target) {
		return false
	}
	value := strings.TrimSpace(token)
	if b.staticToken != "" && hmac.Equal([]byte(value), []byte(b.staticToken)) {
		return true
	}
	if len(b.secret) == 0 {
		return false
	}
	expected, err := makeHMACToken(b.secret, b.forwardID, target)
	if err != nil {
		return false
	}
	expectedHex := strings.TrimPrefix(expected, "hmac-sha256:")
	supplied := strings.TrimPrefix(strings.ToLower(value), "hmac-sha256:")
	if hmac.Equal([]byte(supplied), []byte(expectedHex)) {
		return true
	}
	expectedRaw, err := hex.DecodeString(expectedHex)
	if err != nil {
		return false
	}
	// Python accepts a raw urlsafe-base64 digest as well as the hex form. The
	// comparison still happens against the digest derived from the configured
	// secret, so only an equivalent encoding of the correct token passes.
	suppliedRaw, ok := decodeBase64Loose(supplied)
	return ok && hmac.Equal(suppliedRaw, expectedRaw)
}

// decodeBase64Loose mirrors Python's urlsafe decoder, which tolerates missing
// padding and discards characters outside the alphabet instead of failing.
func decodeBase64Loose(value string) ([]byte, bool) {
	cleaned := strings.Map(func(character rune) rune {
		switch {
		case character >= 'A' && character <= 'Z', character >= 'a' && character <= 'z',
			character >= '0' && character <= '9', character == '-', character == '_',
			character == '+', character == '/':
			return character
		default:
			return -1
		}
	}, value)
	if cleaned == "" {
		return nil, false
	}
	padded := cleaned + strings.Repeat("=", (4-len(cleaned)%4)%4)
	if decoded, err := base64.URLEncoding.DecodeString(padded); err == nil {
		return decoded, true
	}
	if decoded, err := base64.RawURLEncoding.DecodeString(cleaned); err == nil {
		return decoded, true
	}
	return nil, false
}

type helloPayload struct {
	token           string
	target          string
	forwardID       int64
	framePayloadMax int
}

func parseHelloPayload(f frame) (helloPayload, error) {
	if f.msgType != msgHello {
		return helloPayload{}, protocolError{reason: "HELLO required"}
	}
	raw, err := decodeJSONObject(f.payload)
	if err != nil {
		return helloPayload{}, err
	}
	token := strings.TrimSpace(asJSONString(raw["token"]))
	target := strings.TrimSpace(asJSONString(raw["target"]))
	if token == "" || target == "" {
		return helloPayload{}, protocolError{reason: "HELLO token and target are required"}
	}
	forwardID, ok := coerceInt(raw["forward_id"])
	if !ok {
		return helloPayload{}, protocolError{reason: "HELLO forward_id is required"}
	}
	desired := int64(defaultFramePayload)
	if value, present := raw["frame_payload_max"]; present {
		parsed, ok := coerceInt(value)
		if !ok {
			return helloPayload{}, protocolError{reason: "HELLO frame_payload_max is invalid"}
		}
		desired = parsed
	}
	if desired < minFramePayload || desired > maxDatagram {
		return helloPayload{}, protocolError{reason: "HELLO frame_payload_max is out of range"}
	}
	normalized, err := normalizeTarget(target)
	if err != nil {
		return helloPayload{}, err
	}
	return helloPayload{token: token, target: normalized, forwardID: forwardID, framePayloadMax: int(desired)}, nil
}

// decodeJSONObject mirrors the Python helper: strict JSON object, no trailing data.
func decodeJSONObject(payload []byte) (map[string]any, error) {
	if len(payload) == 0 {
		return nil, protocolError{reason: "JSON payload required"}
	}
	decoder := json.NewDecoder(strings.NewReader(string(payload)))
	decoder.UseNumber()
	var value any
	if err := decoder.Decode(&value); err != nil {
		return nil, protocolError{reason: "JSON payload required"}
	}
	var trailing any
	if err := decoder.Decode(&trailing); err != io.EOF {
		return nil, protocolError{reason: "JSON payload required"}
	}
	object, ok := value.(map[string]any)
	if !ok {
		return nil, protocolError{reason: "JSON object required"}
	}
	return object, nil
}

func asJSONString(value any) string {
	switch typed := value.(type) {
	case nil:
		return ""
	case string:
		return typed
	case json.Number:
		return typed.String()
	case bool:
		if typed {
			return "true"
		}
		return "false"
	default:
		return ""
	}
}

// coerceInt mirrors Python int(value): integral JSON numbers and decimal
// integer strings are accepted, floats truncate toward zero, everything else fails.
func coerceInt(value any) (int64, bool) {
	switch typed := value.(type) {
	case nil:
		return 0, false
	case json.Number:
		if parsed, err := strconv.ParseInt(typed.String(), 10, 64); err == nil {
			return parsed, true
		}
		floatValue, err := strconv.ParseFloat(typed.String(), 64)
		if err != nil {
			return 0, false
		}
		if floatValue > 9.2e18 || floatValue < -9.2e18 {
			return 0, false
		}
		return int64(floatValue), true
	case string:
		parsed, err := strconv.ParseInt(strings.TrimSpace(typed), 10, 64)
		if err != nil {
			return 0, false
		}
		return parsed, true
	default:
		return 0, false
	}
}

// encodeJSON mirrors json.dumps(value, separators=(",", ":"), sort_keys=True):
// map keys are emitted in sorted order and separators are compact.
func encodeJSON(value map[string]any) []byte {
	encoded, err := json.Marshal(value)
	if err != nil {
		return []byte("{}")
	}
	return encoded
}
