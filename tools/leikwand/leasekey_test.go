package main

import (
	"bytes"
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/ecdh"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"runtime"
	"strings"
	"sync"
	"testing"
)

// Test vector from docs/specs/2026-10-02-ultra-sealed-lease-v1.md §5.
const (
	vectorScalarHex = "899847890f5f32c53126b22dc726d290a22e63fdc2bbc6dd2f063685199e5dfb"
	vectorPublic    = "BJB5i5gLTy4P7-eKXbTqzY_-YNVJKKEaugOx14nJAuEHrTi_gY9XreyCRnuXCE86Ecgpq1pTbXRGQ0BCPfIHhJs"
	vectorLease     = `{
		"lease_id": "ul2_test-vector-0001",
		"forward_id": 42,
		"sealed": {
			"v": 1,
			"alg": "ECDH-P256+HKDF-SHA256+A256GCM",
			"kid": "c188836f4cbb9983ef0ce2f209a32767",
			"epk": "BNcv3DbxPypDUaly9lSNYnpyfC2TFG6vjXq2CPkud-Y_eUPFQEtJv4ppTMWnLJ8FaohlckDI6vfBZQR1Ge8IdxU",
			"iv": "ABEiM0RVZneImaq7",
			"ct": "PBnTWzpuLWhTPFhpX86NSK30Xe3CbjjHaQOs6PSBQ0uymvpO2hvnLDuWaaTqdrVWBWORXa7dQfFmzKxr4pN6xJ6Bi-IEiHBKOE8MgpV1REPUugS3rInpQhM66G9rY1ZrWmSTysuUlhoyRo_HeRgGT5uPETJfdiOThqzck9ixD8_ykEXHkO0VstbSUnc09hBqChXJX92Jmuf_tSb-9DOgvnfxd7A8_UDn4x52VVC6HuGQwOXG11gWfhUFSI62OMIJbdvKo3u6TmeV7GHL"
		}
	}`
	vectorPlaintext = `{"endpoint":{"ip":"203.0.113.7","port":40123},"credential":{"user":"lg-user-1","password":"lg-secret-1"},"external_uot":{"enabled":true,"sidecar_target":"198.51.100.9:7443","token":"tok"}}`
)

func vectorKey(t *testing.T) *ecdh.PrivateKey {
	t.Helper()
	scalar, err := hex.DecodeString(vectorScalarHex)
	if err != nil {
		t.Fatal(err)
	}
	key, err := ecdh.P256().NewPrivateKey(scalar)
	if err != nil {
		t.Fatalf("vector key: %v", err)
	}
	return key
}

func vectorParts(t *testing.T) (map[string]json.RawMessage, json.RawMessage) {
	t.Helper()
	fields, sealed, ok, err := splitSealedLease(json.RawMessage(vectorLease))
	if err != nil || !ok {
		t.Fatalf("vector lease not recognised as sealed: ok=%v err=%v", ok, err)
	}
	return fields, sealed
}

func TestSealedLeaseVectorKeyMaterial(t *testing.T) {
	key := vectorKey(t)
	if got := leaseKeyHeaderValue(key.PublicKey()); got != vectorPublic {
		t.Fatalf("header value = %s, want %s", got, vectorPublic)
	}
	if len(key.PublicKey().Bytes()) != 65 || key.PublicKey().Bytes()[0] != 0x04 {
		t.Fatal("public key is not a 65-byte uncompressed point")
	}
	if got := leaseKeyID(key.PublicKey()); got != "c188836f4cbb9983ef0ce2f209a32767" {
		t.Fatalf("kid = %s", got)
	}
}

func TestSealedLeaseVectorOpensExactly(t *testing.T) {
	_, sealed := vectorParts(t)
	plain, err := openSealed(sealed, "ul2_test-vector-0001", vectorKey(t))
	if err != nil {
		t.Fatalf("open vector: %v", err)
	}
	if string(plain) != vectorPlaintext {
		t.Fatalf("plaintext mismatch:\n got %s\nwant %s", plain, vectorPlaintext)
	}
}

func TestSealedLeaseVectorMergesIntoFrame(t *testing.T) {
	payload := []byte(`{"ok":true,"lease_seal":{"v":1,"kid":"c188836f4cbb9983ef0ce2f209a32767"},"leases":[` + vectorLease + `]}`)
	response, failures, err := decodeLeaseResponse(payload, vectorKey(t))
	if err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(failures) != 0 || len(response.Leases) != 1 || response.Error != "" {
		t.Fatalf("failures=%v leases=%d error=%q", failures, len(response.Leases), response.Error)
	}
	lease := response.Leases[0]
	if lease.LeaseID != "ul2_test-vector-0001" || lease.ForwardID != 42 {
		t.Fatalf("identity lost: %+v", lease)
	}
	if lease.Endpoint.IP != "203.0.113.7" || lease.Endpoint.Port != 40123 {
		t.Fatalf("endpoint = %+v", lease.Endpoint)
	}
	if lease.Credential.User != "lg-user-1" || lease.Credential.Password != "lg-secret-1" {
		t.Fatalf("credential = %+v", lease.Credential)
	}
	encoded, _ := json.Marshal(lease)
	if strings.Contains(string(encoded), "sealed") {
		t.Fatalf("sealed survived into the runtime frame: %s", encoded)
	}
}

func flipB64(t *testing.T, value string, index int) string {
	t.Helper()
	raw, err := base64.RawURLEncoding.DecodeString(value)
	if err != nil {
		t.Fatal(err)
	}
	raw[index%len(raw)] ^= 0x01
	return base64.RawURLEncoding.EncodeToString(raw)
}

func TestSealedLeaseTamperFails(t *testing.T) {
	key := vectorKey(t)
	_, sealedRaw := vectorParts(t)
	var base sealedEnvelope
	if err := json.Unmarshal(sealedRaw, &base); err != nil {
		t.Fatal(err)
	}
	encode := func(env sealedEnvelope) json.RawMessage {
		raw, _ := json.Marshal(env)
		return raw
	}
	cases := map[string]func() (json.RawMessage, string){
		"ct_first": func() (json.RawMessage, string) {
			e := base
			e.CT = flipB64(t, e.CT, 0)
			return encode(e), "ul2_test-vector-0001"
		},
		"ct_tag": func() (json.RawMessage, string) {
			e := base
			e.CT = flipB64(t, e.CT, -1+len(mustB64(t, e.CT)))
			return encode(e), "ul2_test-vector-0001"
		},
		"iv": func() (json.RawMessage, string) {
			e := base
			e.IV = flipB64(t, e.IV, 3)
			return encode(e), "ul2_test-vector-0001"
		},
		"epk_x": func() (json.RawMessage, string) {
			e := base
			e.Epk = flipB64(t, e.Epk, 10)
			return encode(e), "ul2_test-vector-0001"
		},
		"epk_y": func() (json.RawMessage, string) {
			e := base
			e.Epk = flipB64(t, e.Epk, 64)
			return encode(e), "ul2_test-vector-0001"
		},
		"lease_id": func() (json.RawMessage, string) { return encode(base), "ul2_test-vector-0002" },
		"kid": func() (json.RawMessage, string) {
			e := base
			e.Kid = strings.Repeat("0", 32)
			return encode(e), "ul2_test-vector-0001"
		},
		"version": func() (json.RawMessage, string) { e := base; e.V = 2; return encode(e), "ul2_test-vector-0001" },
		"alg": func() (json.RawMessage, string) {
			e := base
			e.Alg = "ECDH-P256+HKDF-SHA256+A128GCM"
			return encode(e), "ul2_test-vector-0001"
		},
		"bad_b64": func() (json.RawMessage, string) {
			e := base
			e.CT = "!!" + e.CT
			return encode(e), "ul2_test-vector-0001"
		},
		"short_iv": func() (json.RawMessage, string) { e := base; e.IV = "AAAA"; return encode(e), "ul2_test-vector-0001" },
		"null":     func() (json.RawMessage, string) { return json.RawMessage("null"), "ul2_test-vector-0001" },
		"string":   func() (json.RawMessage, string) { return json.RawMessage(`"sealed"`), "ul2_test-vector-0001" },
	}
	for name, build := range cases {
		sealed, leaseID := build()
		if _, err := openSealed(sealed, leaseID, key); err == nil {
			t.Fatalf("%s: tampered envelope opened", name)
		}
	}
	if _, err := openSealed(sealedRaw, "ul2_test-vector-0001", nil); err == nil {
		t.Fatal("opened without a device key")
	}
	other, _ := ecdh.P256().GenerateKey(rand.Reader)
	if _, err := openSealed(sealedRaw, "ul2_test-vector-0001", other); err == nil {
		t.Fatal("opened with a foreign device key")
	}
}

func mustB64(t *testing.T, value string) []byte {
	t.Helper()
	raw, err := base64.RawURLEncoding.DecodeString(value)
	if err != nil {
		t.Fatal(err)
	}
	return raw
}

// A lease that cannot be opened fails on its own: it is dropped, nothing is
// substituted for it and the rest of the batch is still delivered.
func TestSealedLeaseFailureDropsOnlyThatLease(t *testing.T) {
	tampered := strings.Replace(vectorLease, `"ul2_test-vector-0001"`, `"ul2_test-vector-9999"`, 1)
	clearLease := `{"lease_id":"clear-1","forward_id":42,"endpoint":{"ip":"192.0.2.1","port":1},"credential":{"user":"u","password":"p"}}`

	response, failures, err := decodeLeaseResponse([]byte(`{"ok":true,"leases":[`+tampered+`]}`), vectorKey(t))
	if err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(response.Leases) != 0 || len(failures) != 1 || response.Error != codeLeaseUnsealFailed {
		t.Fatalf("leases=%d failures=%d error=%q", len(response.Leases), len(failures), response.Error)
	}
	if !strings.Contains(failures[0].Error(), codeLeaseUnsealFailed) {
		t.Fatalf("failure = %v", failures[0])
	}

	response, failures, err = decodeLeaseResponse([]byte(`{"ok":true,"leases":[`+tampered+`,`+vectorLease+`,`+clearLease+`]}`), vectorKey(t))
	if err != nil {
		t.Fatalf("decode mixed: %v", err)
	}
	if len(response.Leases) != 2 || len(failures) != 1 || response.Error != "" {
		t.Fatalf("mixed: leases=%d failures=%d error=%q", len(response.Leases), len(failures), response.Error)
	}
	if response.Leases[0].LeaseID != "ul2_test-vector-0001" || response.Leases[1].LeaseID != "clear-1" {
		t.Fatalf("wrong survivors: %+v", response.Leases)
	}

	// A sealed lease with no key at all also fails closed.
	response, failures, err = decodeLeaseResponse([]byte(`{"ok":true,"leases":[`+vectorLease+`]}`), nil)
	if err != nil || len(response.Leases) != 0 || len(failures) != 1 || response.Error != codeLeaseUnsealFailed {
		t.Fatalf("keyless: err=%v leases=%d failures=%d error=%q", err, len(response.Leases), len(failures), response.Error)
	}
}

// RFC 5869 test case 1 pins the hand-written HKDF to the standard.
func TestHKDFSHA256RFC5869(t *testing.T) {
	ikm, _ := hex.DecodeString("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b")
	salt, _ := hex.DecodeString("000102030405060708090a0b0c")
	info, _ := hex.DecodeString("f0f1f2f3f4f5f6f7f8f9")
	want := "3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865"
	if got := hex.EncodeToString(hkdfSHA256(ikm, salt, info, 42)); got != want {
		t.Fatalf("okm = %s", got)
	}
}

// sealForTest is the Panel side of the protocol, used to drive the client end
// to end with a key it generated itself.
func sealForTest(t *testing.T, devicePub []byte, leaseID string, plaintext []byte) map[string]any {
	t.Helper()
	pub, err := ecdh.P256().NewPublicKey(devicePub)
	if err != nil {
		t.Fatalf("panel parse device key: %v", err)
	}
	ephemeral, _ := ecdh.P256().GenerateKey(rand.Reader)
	shared, err := ephemeral.ECDH(pub)
	if err != nil {
		t.Fatal(err)
	}
	salt := append(append([]byte{}, ephemeral.PublicKey().Bytes()...), devicePub...)
	key := hkdfSHA256(shared, salt, []byte(leaseSealInfo), 32)
	block, _ := aes.NewCipher(key)
	gcm, _ := cipher.NewGCM(block)
	iv := make([]byte, 12)
	_, _ = rand.Read(iv)
	ct := gcm.Seal(nil, iv, plaintext, []byte(leaseSealInfo+"|"+leaseID))
	return map[string]any{
		"v":   1,
		"alg": leaseSealAlg,
		"kid": leaseKeyID(pub),
		"epk": base64.RawURLEncoding.EncodeToString(ephemeral.PublicKey().Bytes()),
		"iv":  base64.RawURLEncoding.EncodeToString(iv),
		"ct":  base64.RawURLEncoding.EncodeToString(ct),
	}
}

// sealingPanel answers /api/client/ultra/lease like a Panel in "optional"
// mode: sealed when the request carries a valid key, clear otherwise. It
// records every lease key header it saw.
type sealingPanel struct {
	mu      sync.Mutex
	headers []string
	refuse  string
}

func (p *sealingPanel) handler(t *testing.T) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		header := r.Header.Get(leaseKeyHeader)
		p.mu.Lock()
		p.headers = append(p.headers, header)
		refuse := p.refuse
		p.mu.Unlock()
		if refuse != "" {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusConflict)
			_, _ = w.Write([]byte(`{"ok":false,"error":"` + refuse + `"}`))
			return
		}
		secret := map[string]any{
			"endpoint":   map[string]any{"ip": "203.0.113.50", "port": 31000},
			"credential": map[string]any{"user": "relay-user", "password": "relay-pass"},
		}
		lease := map[string]any{"protocol": "leikwan-ultra-policy-v2", "forward_id": 9, "lease_id": "ul2_e2e-1"}
		envelope := map[string]any{"ok": true}
		if header != "" {
			devicePub, err := base64.RawURLEncoding.DecodeString(header)
			if err != nil || len(devicePub) != 65 {
				w.WriteHeader(http.StatusBadRequest)
				_, _ = w.Write([]byte(`{"ok":false,"error":"lease_key_invalid"}`))
				return
			}
			plain, _ := json.Marshal(secret)
			lease["sealed"] = sealForTest(t, devicePub, "ul2_e2e-1", plain)
			envelope["lease_seal"] = map[string]any{"v": 1, "kid": lease["sealed"].(map[string]any)["kid"]}
		} else {
			for k, v := range secret {
				lease[k] = v
			}
		}
		envelope["leases"] = []any{lease}
		writeJSON(t, w, envelope)
	}
}

func (p *sealingPanel) seen() []string {
	p.mu.Lock()
	defer p.mu.Unlock()
	return append([]string(nil), p.headers...)
}

func newSealingClient(t *testing.T, url, dataDir string) *PanelClient {
	t.Helper()
	cfg := defaultConfig()
	cfg.PanelURL = url
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")
	client.leaseKeys = newLeaseKeyStore(dataDir, newLogger("error"))
	return client
}

func TestLeaseSendsKeyHeaderAndOpensSealedLease(t *testing.T) {
	panel := &sealingPanel{}
	server := httptest.NewServer(panel.handler(t))
	defer server.Close()
	dataDir := t.TempDir()
	client := newSealingClient(t, server.URL, dataDir)

	for i := 0; i < 2; i++ {
		response, err := client.Lease(context.Background(), LeaseRequest{DeviceID: "dev-1"})
		if err != nil {
			t.Fatalf("lease %d: %v", i, err)
		}
		if len(response.Leases) != 1 || len(response.UnsealFailures) != 0 {
			t.Fatalf("lease %d: leases=%d failures=%v", i, len(response.Leases), response.UnsealFailures)
		}
		lease := response.Leases[0]
		if lease.Endpoint.IP != "203.0.113.50" || lease.Endpoint.Port != 31000 || lease.Credential.Password != "relay-pass" {
			t.Fatalf("lease %d not opened: %+v", i, lease)
		}
	}
	seen := panel.seen()
	if len(seen) != 2 || seen[0] == "" || seen[0] != seen[1] {
		t.Fatalf("headers = %q, want the same key twice", seen)
	}
	if strings.ContainsAny(seen[0], "=+/") {
		t.Fatalf("header is not unpadded base64url: %s", seen[0])
	}
	raw := mustB64(t, seen[0])
	if len(raw) != 65 || raw[0] != 0x04 {
		t.Fatalf("header decodes to %d bytes, first=%#x", len(raw), raw[0])
	}

	// A fresh process on the same data directory reuses the persisted key.
	again := newSealingClient(t, server.URL, dataDir)
	if _, err := again.Lease(context.Background(), LeaseRequest{DeviceID: "dev-1"}); err != nil {
		t.Fatalf("lease after restart: %v", err)
	}
	if seen := panel.seen(); seen[2] != seen[0] {
		t.Fatal("device key changed across a restart")
	}
}

func TestLeaseWithoutKeyStoreStaysUnsealed(t *testing.T) {
	panel := &sealingPanel{}
	server := httptest.NewServer(panel.handler(t))
	defer server.Close()
	cfg := defaultConfig()
	cfg.PanelURL = server.URL
	client := newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")

	response, err := client.Lease(context.Background(), LeaseRequest{DeviceID: "dev-1"})
	if err != nil {
		t.Fatalf("lease: %v", err)
	}
	if seen := panel.seen(); len(seen) != 1 || seen[0] != "" {
		t.Fatalf("unexpected key header: %q", seen)
	}
	if len(response.Leases) != 1 || response.Leases[0].Credential.User != "relay-user" {
		t.Fatalf("clear lease not decoded: %+v", response.Leases)
	}
}

// A clear lease from an off/optional or older Panel is used as-is even when
// this device sent its key.
func TestClearLeaseStillWorksWithKey(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get(leaseKeyHeader) == "" {
			t.Error("lease key header missing")
		}
		writeJSON(t, w, map[string]any{"ok": true, "leases": []any{map[string]any{
			"protocol": "leikwan-ultra-policy-v2", "forward_id": 3, "lease_id": "clear",
			"endpoint":   map[string]any{"ip": "198.51.100.1", "port": 4000},
			"credential": map[string]any{"user": "u2", "password": "p2"},
		}}})
	}))
	defer server.Close()
	client := newSealingClient(t, server.URL, t.TempDir())
	response, err := client.Lease(context.Background(), LeaseRequest{DeviceID: "dev-1"})
	if err != nil {
		t.Fatalf("lease: %v", err)
	}
	if len(response.Leases) != 1 || response.Leases[0].Endpoint.Port != 4000 || response.Leases[0].Credential.User != "u2" {
		t.Fatalf("clear lease = %+v", response.Leases)
	}
}

// Sealing refusals are ordinary lease errors and a key conflict never makes
// the device rotate its key.
func TestLeaseKeyRefusalsKeepKey(t *testing.T) {
	panel := &sealingPanel{}
	server := httptest.NewServer(panel.handler(t))
	defer server.Close()
	client := newSealingClient(t, server.URL, t.TempDir())

	for _, code := range []string{codeLeaseDeviceKeyConflict, codeLeaseSealingRequired, codeLeaseKeyInvalid, codeLeaseDeviceKeyConflict} {
		panel.mu.Lock()
		panel.refuse = code
		panel.mu.Unlock()
		_, err := client.Lease(context.Background(), LeaseRequest{DeviceID: "dev-1"})
		var pe *panelError
		if !asPanelError(err, &pe) || pe.Code != code {
			t.Fatalf("error = %v, want %s", err, code)
		}
		if hint := leaseRefusalHint(err); hint == "" {
			t.Fatalf("no operator hint for %s", code)
		}
	}
	seen := panel.seen()
	for _, header := range seen[1:] {
		if header != seen[0] {
			t.Fatal("device key changed after a panel refusal")
		}
	}
}

func TestLeaseKeyFilePersistence(t *testing.T) {
	dataDir := t.TempDir()
	store := newLeaseKeyStore(dataDir, nil)
	key := store.get()
	if key == nil {
		t.Fatal("no key generated")
	}
	if store.get() != key {
		t.Fatal("key not cached")
	}
	info, err := os.Stat(leaseKeyPath(dataDir))
	if err != nil {
		t.Fatalf("key file: %v", err)
	}
	if runtime.GOOS != "windows" && info.Mode().Perm() != 0o600 {
		t.Fatalf("key file mode = %v, want 0600", info.Mode().Perm())
	}
	raw, _ := os.ReadFile(leaseKeyPath(dataDir))
	if !bytes.Contains(raw, []byte(hex.EncodeToString(key.Bytes()))) {
		t.Fatal("stored scalar does not match the key in use")
	}

	reloaded := newLeaseKeyStore(dataDir, nil).get()
	if reloaded == nil || !bytes.Equal(reloaded.Bytes(), key.Bytes()) {
		t.Fatal("persisted key not reused")
	}

	// The key is kept out of secret.json, which sign-out deletes.
	if err := saveSecrets(dataDir, SecretState{AgentToken: "t"}); err != nil {
		t.Fatal(err)
	}
	secretRaw, _ := os.ReadFile(secretPath(dataDir))
	if bytes.Contains(secretRaw, []byte(hex.EncodeToString(key.Bytes()))) {
		t.Fatal("lease key leaked into secret.json")
	}
	_ = clearSecrets(dataDir)
	if again := newLeaseKeyStore(dataDir, nil).get(); !bytes.Equal(again.Bytes(), key.Bytes()) {
		t.Fatal("sign-out must not rotate the device lease key")
	}

	// A corrupt file is replaced by a fresh, valid key.
	if err := os.WriteFile(leaseKeyPath(dataDir), []byte("{not json"), 0o600); err != nil {
		t.Fatal(err)
	}
	fresh := newLeaseKeyStore(dataDir, nil).get()
	if fresh == nil || bytes.Equal(fresh.Bytes(), key.Bytes()) {
		t.Fatal("corrupt key file was not regenerated")
	}
	if stored, err := readLeaseKey(dataDir); err != nil || !bytes.Equal(stored.Bytes(), fresh.Bytes()) {
		t.Fatalf("regenerated key not persisted: %v", err)
	}
}

func TestAgentAttachesLeaseKeyStore(t *testing.T) {
	agent := newTestAgent(t, defaultConfig())
	if agent.panel.leaseKeys == nil || agent.panel.leaseKeys.dataDir != agent.cfg.DataDir {
		t.Fatal("agent panel client has no lease key store bound to its data directory")
	}
}
