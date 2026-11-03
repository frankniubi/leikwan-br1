# Ultra sealed lease v1

**Date:** 2026-10-02 · **Status:** implemented on the panel (`master/src/Support/UltraLeaseSealer.php`)
**Why:** `docs/plans/2026-10-02-line-protection-security-remediation-plan.md` §A.7 — a client
`agent_token` alone must no longer be enough to `curl /api/client/ultra/lease` and read the
LeiGod relay `endpoint` + `credential` in clear.

## 1. Device key

* Each installed client owns one **P-256 (secp256r1 / prime256v1)** key pair used only for this.
* Generate it once, persist it, and reuse it for every lease request from that device
  (the same `device_id`). Do **not** regenerate per request, per restart or per update.
* The private key must not be stored in clear next to `agent_token`:
  * Android: Android Keystore (`PURPOSE_AGREE_KEY`, API 31+); below API 31 a software key whose
    PKCS#8 bytes are wrapped with a Keystore AES-GCM key.
  * Windows: DPAPI (`ProtectedData`, scope matching the account the agent runs as).
  * macOS / Linux / OpenWrt: a file with mode `0600` (owner-only) in the agent's private state
    directory (Keychain is optional on macOS).
* If the stored key is lost or unreadable, generate a new one. The panel accepts a new key for a
  device once the previous key has been idle for `ULTRA_LEASE_KEY_REPIN_SECONDS` (default 900 s).

## 2. Request

Send on every `POST /api/client/ultra/lease`:

```
X-Leikwan-Lease-Key: <base64url, no padding, of the 65-byte uncompressed SEC1 point 0x04||X||Y>
```

Body and every other header are unchanged.

## 3. Response

When the panel sealed the response it adds a top-level object

```json
"lease_seal": {"v": 1, "kid": "<first 32 hex chars of sha256(raw public key)>"}
```

and, in every entry of `leases[]`, the fields `endpoint`, `credential` and (when present)
`external_uot` are **removed** and replaced by

```json
"sealed": {
  "v": 1,
  "alg": "ECDH-P256+HKDF-SHA256+A256GCM",
  "kid": "<same as lease_seal.kid>",
  "epk": "<base64url 65-byte uncompressed ephemeral public key>",
  "iv":  "<base64url 12 bytes>",
  "ct":  "<base64url ciphertext || 16-byte GCM tag>"
}
```

### Opening

```
Z      = ECDH(device_private, epk)                      # 32-byte big-endian x coordinate
salt   = epk(65 bytes) || device_public(65 bytes)
key    = HKDF-SHA256(ikm = Z, salt = salt, info = "leikwan-ultra-lease-v1", L = 32)
aad    = UTF-8("leikwan-ultra-lease-v1|" + lease.lease_id)
plain  = AES-256-GCM-Decrypt(key, iv, ct[:-16], tag = ct[-16:], aad)
```

`plain` is a UTF-8 JSON object. Merge each of its keys into the lease object (they are exactly the
removed fields), then drop `sealed`. Everything downstream consumes the lease exactly as before.

### Client rules

* A lease **without** `sealed` is used as-is (panel in `off`/`optional` mode, or an older panel).
* A lease **with** `sealed` that fails to open (unknown `v`/`alg`, `kid` ≠ own key id, bad base64,
  GCM failure, non-object JSON) is treated as a failed lease for that forward
  (error `lease_unseal_failed`); never fall back to anything else and never crash the agent.
* Never log the opened plaintext, the credential or the private key.
* Panel refusals (HTTP 4xx JSON `error`):
  * `lease_key_invalid` (400) — the header was not a valid P-256 point: a client bug.
  * `lease_sealing_required` (409) — the panel runs `ULTRA_LEASE_SEALING=require` and the
    header was missing.
  * `lease_device_key_conflict` (409) — another key is active for this device id. Do **not**
    regenerate the key in response (that would fight the legitimate holder); report it and retry
    at the normal cadence.

## 4. Panel modes (`ULTRA_LEASE_SEALING`)

| value | behaviour |
|---|---|
| `off` | header ignored, leases in clear (rollback switch) |
| `optional` (default) | seal when a valid header is present, clear otherwise; key pin recorded, conflicts only logged |
| `require` | refuse a lease without the header; refuse a key that conflicts with the device's live pinned key |

Switch to `require` only after every shipped client sends the header.

## 5. Test vector

`device_private_scalar_hex` is the raw P-256 private scalar. Opening `lease.sealed` with it must
yield exactly `expected_plaintext`; flipping any byte of `ct`, `iv`, `epk` or `lease_id` must fail.

```json
{
  "device_private_scalar_hex": "899847890f5f32c53126b22dc726d290a22e63fdc2bbc6dd2f063685199e5dfb",
  "device_public_b64url": "BJB5i5gLTy4P7-eKXbTqzY_-YNVJKKEaugOx14nJAuEHrTi_gY9XreyCRnuXCE86Ecgpq1pTbXRGQ0BCPfIHhJs",
  "lease": {
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
  },
  "expected_plaintext": "{\"endpoint\":{\"ip\":\"203.0.113.7\",\"port\":40123},\"credential\":{\"user\":\"lg-user-1\",\"password\":\"lg-secret-1\"},\"external_uot\":{\"enabled\":true,\"sidecar_target\":\"198.51.100.9:7443\",\"token\":\"tok\"}}"
}
```
