package main

import (
	"crypto/hmac"
	"encoding/base64"
	"encoding/hex"
	"strings"
	"testing"
)

func TestNormalizeTargetParity(t *testing.T) {
	cases := []struct {
		input string
		want  string
		fail  bool
	}{
		{"1.2.3.4:8000", "1.2.3.4:8000", false},
		{"  Example.COM:8443  ", "example.com:8443", false},
		{"[0:0:0:0:0:0:0:1]:9000", "::1:9000", false},
		{"0:0:0:0:0:0:0:1:9000", "::1:9000", false},
		{"127.0.0.1:0", "", true},
		{"127.0.0.1:65536", "", true},
		{"hostonly", "", true},
		{":8080", "", true},
		{"h ost:8080", "", true},
		{"1.2.3.4:port", "", true},
	}
	for _, testCase := range cases {
		got, err := normalizeTarget(testCase.input)
		if testCase.fail {
			if err == nil {
				t.Fatalf("normalizeTarget(%q) = %q, want error", testCase.input, got)
			}
			continue
		}
		if err != nil {
			t.Fatalf("normalizeTarget(%q) failed: %v", testCase.input, err)
		}
		if got != testCase.want {
			t.Fatalf("normalizeTarget(%q) = %q, want %q", testCase.input, got, testCase.want)
		}
	}
}

func testBinding(t *testing.T, secret string, targets ...string) forwardBinding {
	t.Helper()
	parsed, err := parseTargets(toAnySlice(targets))
	if err != nil {
		t.Fatalf("parseTargets failed: %v", err)
	}
	return forwardBinding{forwardID: 170, secret: []byte(secret), targets: parsed}
}

func toAnySlice(values []string) []any {
	items := make([]any, 0, len(values))
	for _, value := range values {
		items = append(items, value)
	}
	return items
}

func TestVerifyTokenForms(t *testing.T) {
	binding := testBinding(t, "secret-1", "127.0.0.1:9001")
	token, err := makeHMACToken([]byte("secret-1"), 170, "127.0.0.1:9001")
	if err != nil {
		t.Fatalf("makeHMACToken failed: %v", err)
	}
	hexPart := strings.TrimPrefix(token, "hmac-sha256:")
	raw, err := hex.DecodeString(hexPart)
	if err != nil {
		t.Fatalf("hex decode failed: %v", err)
	}
	accepted := []string{
		token,
		strings.ToUpper(hexPart),
		strings.ToUpper(token),
		"  " + token + "  ",
	}
	for _, candidate := range accepted {
		if !binding.verify(candidate, "127.0.0.1:9001") {
			t.Fatalf("token form %q was rejected", candidate)
		}
	}
	// The Python sidecar lower-cases the presented token before both the hex and
	// the base64 comparison, so a mixed-case base64 digest is rejected there
	// too: the relay must not be more permissive than the implementation it
	// replaces. Assert exactly that equivalence.
	for _, form := range []string{
		base64.URLEncoding.EncodeToString(raw),
		base64.RawURLEncoding.EncodeToString(raw),
	} {
		lowered := strings.ToLower(form)
		decoded, decodedOK := decodeBase64Loose(lowered)
		want := decodedOK && hmac.Equal(decoded, raw)
		if got := binding.verify(form, "127.0.0.1:9001"); got != want {
			t.Fatalf("base64 form %q: verify=%v, python-equivalent=%v", form, got, want)
		}
	}
	rejected := []string{"", "hmac-sha256:", hexPart + "00", strings.Repeat("a", 64)}
	for _, candidate := range rejected {
		if binding.verify(candidate, "127.0.0.1:9001") {
			t.Fatalf("token form %q was accepted", candidate)
		}
	}
	if binding.verify(token, "127.0.0.1:9002") {
		t.Fatal("a token minted for another target was accepted")
	}
}

func TestVerifyWildcardAndStaticToken(t *testing.T) {
	wildcard := testBinding(t, "secret-1", "*")
	token, err := makeHMACToken([]byte("secret-1"), 170, "10.0.0.9:53")
	if err != nil {
		t.Fatalf("makeHMACToken failed: %v", err)
	}
	if !wildcard.verify(token, "10.0.0.9:53") {
		t.Fatal("wildcard binding rejected a well-formed token")
	}
	if wildcard.verify("hmac-sha256:deadbeef", "10.0.0.9:53") {
		t.Fatal("wildcard binding accepted a bogus token")
	}
	static := forwardBinding{forwardID: 170, secret: []byte("secret-1"), targets: map[string]struct{}{}, staticToken: "literal-token"}
	if !static.verify("literal-token", "203.0.113.7:5353") {
		t.Fatal("static token binding rejected its configured token")
	}
	if static.verify("literal-token", "bad target") {
		t.Fatal("static token binding accepted a malformed target")
	}
}

func TestFrameRoundTrip(t *testing.T) {
	original := frame{msgType: msgData, session: [sessionIDBytes]byte{1, 2, 3}, seq: 42, payload: []byte("ping")}
	wire, err := original.wire(defaultMaxFrameBytes)
	if err != nil {
		t.Fatalf("wire failed: %v", err)
	}
	if len(wire) != prefixSize+headerSize+4 {
		t.Fatalf("unexpected wire length %d", len(wire))
	}
	parsed, err := readFrame(strings.NewReader(string(wire)), defaultMaxFrameBytes)
	if err != nil {
		t.Fatalf("readFrame failed: %v", err)
	}
	if parsed.msgType != original.msgType || parsed.seq != original.seq || string(parsed.payload) != "ping" {
		t.Fatalf("round trip mismatch: %+v", parsed)
	}
	if _, err := readFrame(strings.NewReader(string(wire[:3])), defaultMaxFrameBytes); err == nil {
		t.Fatal("truncated frame was accepted")
	}
	overlong := frame{msgType: msgData, payload: make([]byte, maxDatagram)}
	if _, err := overlong.wire(headerSize + minFramePayload); err == nil {
		t.Fatal("oversize frame was accepted")
	}
}

func TestParseHelloPayloadDefaultsAndErrors(t *testing.T) {
	valid := frame{msgType: msgHello, payload: []byte(`{"token":"t","target":"127.0.0.1:9001","forward_id":170}`)}
	parsed, err := parseHelloPayload(valid)
	if err != nil {
		t.Fatalf("parseHelloPayload failed: %v", err)
	}
	if parsed.framePayloadMax != defaultFramePayload || parsed.forwardID != 170 {
		t.Fatalf("unexpected default parse: %+v", parsed)
	}
	numericStrings := frame{msgType: msgHello, payload: []byte(`{"token":"t","target":"127.0.0.1:9001","forward_id":"170","frame_payload_max":"1200"}`)}
	if _, err := parseHelloPayload(numericStrings); err != nil {
		t.Fatalf("numeric strings were rejected: %v", err)
	}
	failures := []struct {
		name    string
		payload string
		want    string
	}{
		{"missing token", `{"target":"127.0.0.1:9001","forward_id":170}`, "HELLO token and target are required"},
		{"missing forward", `{"token":"t","target":"127.0.0.1:9001"}`, "HELLO forward_id is required"},
		{"bad max", `{"token":"t","target":"127.0.0.1:9001","forward_id":170,"frame_payload_max":"x"}`, "HELLO frame_payload_max is invalid"},
		{"small max", `{"token":"t","target":"127.0.0.1:9001","forward_id":170,"frame_payload_max":10}`, "HELLO frame_payload_max is out of range"},
		{"malformed target", `{"token":"t","target":"nope","forward_id":170}`, "target must be host:port"},
		{"not json", `token`, "JSON payload required"},
		{"not object", `[]`, "JSON object required"},
	}
	for _, testCase := range failures {
		candidate := frame{msgType: msgHello, payload: []byte(testCase.payload)}
		_, err := parseHelloPayload(candidate)
		if err == nil || err.Error() != testCase.want {
			t.Fatalf("%s: got %v, want %q", testCase.name, err, testCase.want)
		}
	}
	if _, err := parseHelloPayload(frame{msgType: msgData}); err == nil || err.Error() != "HELLO required" {
		t.Fatalf("non-HELLO frame: got %v", err)
	}
}

func TestAckEncodingMatchesPython(t *testing.T) {
	config := sidecarConfig{queuePackets: 256}
	server := newSidecarServer(newConfigStore("", &config), newSidecarLogger("ERROR", discardWriter{}))
	snapshot := server.config.snapshot()
	session := &uotSession{framePayloadMax: 1200}
	accepted := server.acceptAck(snapshot, [sessionIDBytes]byte{}, session, false)
	if string(accepted.payload) != `{"frame_payload_max":1200,"ok":true,"queue_packets":256,"session":"OKN"}` {
		t.Fatalf("accept ack payload = %s", accepted.payload)
	}
	reattached := server.acceptAck(snapshot, [sessionIDBytes]byte{}, session, true)
	if string(reattached.payload) != `{"frame_payload_max":1200,"ok":true,"queue_packets":256,"session":"OKR"}` {
		t.Fatalf("reattach ack payload = %s", reattached.payload)
	}
	rejection := server.rejectionAck(snapshot, [sessionIDBytes]byte{}, "authentication or target binding failed")
	if string(rejection.payload) != `{"error":"authentication or target binding failed","frame_payload_max":0,"ok":false,"queue_packets":256}` {
		t.Fatalf("rejection ack payload = %s", rejection.payload)
	}
}

type discardWriter struct{}

func (discardWriter) Write(p []byte) (int, error) { return len(p), nil }
