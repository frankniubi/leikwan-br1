package main

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"crypto/ecdh"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"time"
)

// Ultra sealed lease v1 (docs/specs/2026-10-02-ultra-sealed-lease-v1.md).
//
// The Panel no longer hands the relay endpoint and credential to anyone who
// merely holds the agent token: when the lease request carries this device's
// P-256 public key, those fields come back encrypted to that key. The private
// half never leaves the router and is stored on its own, owner-only, outside
// UCI (world-readable), secret.json and every diagnostics payload.
const (
	leaseKeyHeader    = "X-Leikwan-Lease-Key"
	leaseSealVersion  = 1
	leaseSealAlg      = "ECDH-P256+HKDF-SHA256+A256GCM"
	leaseSealInfo     = "leikwan-ultra-lease-v1"
	leaseKeyFileName  = "lease_key.json"
	leaseKeyFileVer   = 1
	leaseKeyCurveName = "P-256"

	// codeLeaseUnsealFailed is the per-lease failure reported when a sealed
	// lease cannot be opened. The lease is dropped; nothing is substituted.
	codeLeaseUnsealFailed = "lease_unseal_failed"

	// Panel refusals that belong to this protocol.
	codeLeaseKeyInvalid        = "lease_key_invalid"
	codeLeaseSealingRequired   = "lease_sealing_required"
	codeLeaseDeviceKeyConflict = "lease_device_key_conflict"
)

func leaseKeyPath(dataDir string) string { return filepath.Join(dataDir, leaseKeyFileName) }

// leaseKeyFile is the on-disk form. Only the raw private scalar is stored; the
// public key and key id are derived from it on load.
type leaseKeyFile struct {
	Version       int    `json:"version"`
	Curve         string `json:"curve"`
	PrivateScalar string `json:"private_scalar"`
	CreatedAt     string `json:"created_at"`
}

// leaseKeyStore owns the device lease key: loaded once, generated only when
// the file is missing or unusable, and never rotated in reaction to a Panel
// refusal (a key conflict must not make two holders fight over the pin).
type leaseKeyStore struct {
	dataDir string
	log     *Logger

	mu        sync.Mutex
	key       *ecdh.PrivateKey
	persisted bool
}

func newLeaseKeyStore(dataDir string, log *Logger) *leaseKeyStore {
	return &leaseKeyStore{dataDir: dataDir, log: log}
}

// get returns the device key, loading or creating it on first use. A key
// that could not be written to disk is still used for this process and the
// write is retried on the next call, so a transient flash error neither
// disables sealing nor silently changes the key on every request.
func (s *leaseKeyStore) get() *ecdh.PrivateKey {
	if s == nil {
		return nil
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.key != nil {
		if !s.persisted {
			if err := writeLeaseKey(s.dataDir, s.key); err != nil {
				s.warn("lease key still not persisted: %v", err)
			} else {
				s.persisted = true
				s.info("lease key persisted (kid=%s)", leaseKeyID(s.key.PublicKey()))
			}
		}
		return s.key
	}

	key, err := readLeaseKey(s.dataDir)
	if err == nil {
		s.key = key
		s.persisted = true
		return s.key
	}
	if !errors.Is(err, os.ErrNotExist) {
		s.warn("stored lease key unusable (%v); generating a new one", err)
	}
	key, err = ecdh.P256().GenerateKey(rand.Reader)
	if err != nil {
		s.warn("could not generate lease key: %v", err)
		return nil
	}
	s.key = key
	if err := writeLeaseKey(s.dataDir, key); err != nil {
		s.warn("could not persist lease key (will retry): %v", err)
	} else {
		s.persisted = true
	}
	s.info("generated device lease key (kid=%s)", leaseKeyID(key.PublicKey()))
	return s.key
}

func (s *leaseKeyStore) warn(format string, args ...any) {
	if s.log != nil {
		s.log.Warn("lease", format, args...)
	}
}

func (s *leaseKeyStore) info(format string, args ...any) {
	if s.log != nil {
		s.log.Info("lease", format, args...)
	}
}

func readLeaseKey(dataDir string) (*ecdh.PrivateKey, error) {
	path := leaseKeyPath(dataDir)
	info, err := os.Lstat(path)
	if err != nil {
		return nil, err
	}
	if !info.Mode().IsRegular() {
		return nil, fmt.Errorf("%s is not a regular file", leaseKeyFileName)
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var stored leaseKeyFile
	if err := json.Unmarshal(raw, &stored); err != nil {
		return nil, fmt.Errorf("decode %s: %w", leaseKeyFileName, err)
	}
	if stored.Version != leaseKeyFileVer || stored.Curve != leaseKeyCurveName {
		return nil, fmt.Errorf("%s has unsupported version/curve", leaseKeyFileName)
	}
	scalar, err := hex.DecodeString(strings.TrimSpace(stored.PrivateScalar))
	if err != nil {
		return nil, fmt.Errorf("%s private key is not hex", leaseKeyFileName)
	}
	key, err := ecdh.P256().NewPrivateKey(scalar)
	if err != nil {
		return nil, fmt.Errorf("%s private key is invalid", leaseKeyFileName)
	}
	// Tighten a file whose mode was widened by hand or by a restore.
	if runtime.GOOS != "windows" && info.Mode().Perm()&0o077 != 0 {
		_ = os.Chmod(path, 0o600)
	}
	return key, nil
}

func writeLeaseKey(dataDir string, key *ecdh.PrivateKey) error {
	raw, err := json.MarshalIndent(leaseKeyFile{
		Version:       leaseKeyFileVer,
		Curve:         leaseKeyCurveName,
		PrivateScalar: hex.EncodeToString(key.Bytes()),
		CreatedAt:     formatRFC3339(time.Now()),
	}, "", "  ")
	if err != nil {
		return err
	}
	if err := ensurePrivateDir(dataDir); err != nil {
		return err
	}
	return writeFileAtomic(leaseKeyPath(dataDir), raw, 0o600)
}

// leaseKeyHeaderValue is base64url (no padding) of the 65-byte uncompressed
// SEC1 point, which is exactly what ecdh.PublicKey.Bytes returns for P-256.
func leaseKeyHeaderValue(pub *ecdh.PublicKey) string {
	return base64.RawURLEncoding.EncodeToString(pub.Bytes())
}

// leaseKeyID is the first 32 hex characters of sha256(raw public key).
func leaseKeyID(pub *ecdh.PublicKey) string {
	sum := sha256.Sum256(pub.Bytes())
	return hex.EncodeToString(sum[:])[:32]
}

// ---------------------------------------------------------------------------
// Opening a sealed lease response
// ---------------------------------------------------------------------------

type sealedEnvelope struct {
	V   int    `json:"v"`
	Alg string `json:"alg"`
	Kid string `json:"kid"`
	Epk string `json:"epk"`
	IV  string `json:"iv"`
	CT  string `json:"ct"`
}

// unsealError carries a short, non-sensitive reason for the log. The code an
// operator sees is always lease_unseal_failed.
type unsealError struct{ reason string }

func (e *unsealError) Error() string { return codeLeaseUnsealFailed + ": " + e.reason }

func unsealFail(reason string) error { return &unsealError{reason: reason} }

// decodeLeaseResponse turns the raw /api/client/ultra/lease body into the
// typed response. A lease without "sealed" is decoded exactly as before. A
// sealed lease is opened with key, its plaintext object is merged back into
// the lease and "sealed" is dropped; a lease that cannot be opened is removed
// from the batch and counted, never replaced by anything else.
func decodeLeaseResponse(payload []byte, key *ecdh.PrivateKey) (*LeaseResponse, []error, error) {
	var envelope struct {
		OK     bool              `json:"ok"`
		Error  string            `json:"error,omitempty"`
		Leases []json.RawMessage `json:"leases"`
	}
	if err := json.Unmarshal(payload, &envelope); err != nil {
		return nil, nil, err
	}
	out := &LeaseResponse{OK: envelope.OK, Error: envelope.Error}
	var failures []error
	for _, rawLease := range envelope.Leases {
		fields, sealedRaw, sealed, err := splitSealedLease(rawLease)
		if err != nil {
			return nil, nil, err
		}
		if !sealed {
			var frame LeaseFrame
			if err := json.Unmarshal(rawLease, &frame); err != nil {
				return nil, nil, err
			}
			out.Leases = append(out.Leases, frame)
			continue
		}
		frame, err := openSealedLease(fields, sealedRaw, key)
		if err != nil {
			failures = append(failures, err)
			continue
		}
		out.Leases = append(out.Leases, frame)
	}
	if len(failures) > 0 && len(out.Leases) == 0 {
		out.Error = codeLeaseUnsealFailed
	}
	return out, failures, nil
}

// splitSealedLease reports whether a lease carries a "sealed" member. The
// name is matched case-insensitively, as encoding/json would, so a variant
// spelling cannot slip a sealed lease through as a clear one.
func splitSealedLease(rawLease json.RawMessage) (map[string]json.RawMessage, json.RawMessage, bool, error) {
	trimmed := bytes.TrimSpace(rawLease)
	if len(trimmed) == 0 || trimmed[0] != '{' {
		return nil, nil, false, nil
	}
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(trimmed, &fields); err != nil {
		return nil, nil, false, err
	}
	for name, value := range fields {
		if strings.EqualFold(name, "sealed") {
			delete(fields, name)
			return fields, value, true, nil
		}
	}
	return fields, nil, false, nil
}

func openSealedLease(fields map[string]json.RawMessage, sealedRaw json.RawMessage, key *ecdh.PrivateKey) (LeaseFrame, error) {
	var frame LeaseFrame
	leaseID := ""
	if rawID, ok := fields["lease_id"]; ok {
		if err := json.Unmarshal(rawID, &leaseID); err != nil {
			return frame, unsealFail("lease_id")
		}
	}
	plaintext, err := openSealed(sealedRaw, leaseID, key)
	if err != nil {
		return frame, err
	}
	defer wipe(plaintext)

	trimmed := bytes.TrimSpace(plaintext)
	if len(trimmed) == 0 || trimmed[0] != '{' {
		return frame, unsealFail("plaintext_not_object")
	}
	var secret map[string]json.RawMessage
	if err := json.Unmarshal(trimmed, &secret); err != nil || secret == nil {
		return frame, unsealFail("plaintext_not_object")
	}
	for name, value := range secret {
		if strings.EqualFold(name, "sealed") {
			continue
		}
		// Drop any clear-text spelling variant so it cannot shadow the
		// authenticated value when the merged object is decoded.
		for existing := range fields {
			if strings.EqualFold(existing, name) {
				delete(fields, existing)
			}
		}
		fields[name] = value
	}
	merged, err := json.Marshal(fields)
	if err != nil {
		return frame, unsealFail("merge")
	}
	defer wipe(merged)
	if err := json.Unmarshal(merged, &frame); err != nil {
		return LeaseFrame{}, unsealFail("merged_lease_invalid")
	}
	return frame, nil
}

// openSealed implements §3 "Opening" of the spec and returns the plaintext.
func openSealed(sealedRaw json.RawMessage, leaseID string, key *ecdh.PrivateKey) ([]byte, error) {
	if key == nil {
		return nil, unsealFail("no_device_key")
	}
	var env sealedEnvelope
	trimmed := bytes.TrimSpace(sealedRaw)
	if len(trimmed) == 0 || trimmed[0] != '{' {
		return nil, unsealFail("envelope")
	}
	if err := json.Unmarshal(trimmed, &env); err != nil {
		return nil, unsealFail("envelope")
	}
	if env.V != leaseSealVersion {
		return nil, unsealFail("version")
	}
	if env.Alg != leaseSealAlg {
		return nil, unsealFail("alg")
	}
	devicePub := key.PublicKey()
	if env.Kid != leaseKeyID(devicePub) {
		return nil, unsealFail("kid_mismatch")
	}
	epk, err := decodeB64URL(env.Epk)
	if err != nil || len(epk) != 65 || epk[0] != 0x04 {
		return nil, unsealFail("epk")
	}
	ephemeral, err := ecdh.P256().NewPublicKey(epk)
	if err != nil {
		return nil, unsealFail("epk")
	}
	iv, err := decodeB64URL(env.IV)
	if err != nil || len(iv) != 12 {
		return nil, unsealFail("iv")
	}
	ct, err := decodeB64URL(env.CT)
	if err != nil || len(ct) < 16 {
		return nil, unsealFail("ct")
	}

	shared, err := key.ECDH(ephemeral)
	if err != nil {
		return nil, unsealFail("ecdh")
	}
	defer wipe(shared)
	salt := make([]byte, 0, 130)
	salt = append(salt, epk...)
	salt = append(salt, devicePub.Bytes()...)
	aesKey := hkdfSHA256(shared, salt, []byte(leaseSealInfo), 32)
	defer wipe(aesKey)

	block, err := aes.NewCipher(aesKey)
	if err != nil {
		return nil, unsealFail("cipher")
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return nil, unsealFail("cipher")
	}
	plaintext, err := gcm.Open(nil, iv, ct, []byte(leaseSealInfo+"|"+leaseID))
	if err != nil {
		return nil, unsealFail("authentication")
	}
	return plaintext, nil
}

// decodeB64URL accepts base64url with or without padding, and nothing else.
func decodeB64URL(value string) ([]byte, error) {
	return base64.RawURLEncoding.DecodeString(strings.TrimRight(strings.TrimSpace(value), "="))
}

// hkdfSHA256 is RFC 5869 HKDF (extract + expand). The module targets Go 1.21,
// which predates crypto/hkdf, and no new dependency is pulled in for it.
func hkdfSHA256(ikm, salt, info []byte, length int) []byte {
	if len(salt) == 0 {
		salt = make([]byte, sha256.Size)
	}
	extract := hmac.New(sha256.New, salt)
	extract.Write(ikm)
	prk := extract.Sum(nil)
	defer wipe(prk)

	out := make([]byte, 0, length+sha256.Size)
	var previous []byte
	for counter := byte(1); len(out) < length; counter++ {
		expand := hmac.New(sha256.New, prk)
		expand.Write(previous)
		expand.Write(info)
		expand.Write([]byte{counter})
		previous = expand.Sum(nil)
		out = append(out, previous...)
	}
	return out[:length]
}

// wipe is a best-effort scrub of short-lived secret buffers.
func wipe(buf []byte) {
	for i := range buf {
		buf[i] = 0
	}
}
