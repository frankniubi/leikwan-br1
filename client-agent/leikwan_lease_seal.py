#!/usr/bin/env python3
"""Ultra sealed lease v1, client side (docs/specs/2026-10-02-ultra-sealed-lease-v1.md).

Stdlib-only: the agent is also frozen with PyInstaller and cannot rely on the
``cryptography`` package.  This module provides exactly what the client needs:

* a persistent P-256 device key (generation, SEC1 uncompressed encoding),
* ECDH with point validation (Montgomery ladder over the Renes-Costello-Batina
  complete projective formulas, fixed 256 iterations, arithmetic swaps),
* HKDF-SHA256 and AES-256-GCM decryption (tag checked with compare_digest
  before any plaintext is produced),
* ``open_sealed_lease`` which merges the opened fields back into the lease.

Python integers are not constant time; the code avoids secret-dependent
branches and table indices where that is reasonably possible (the AES tables
are indexed by key-derived state, as in any table AES).  Nothing here logs.
"""

from __future__ import annotations

import base64
import binascii
import contextlib
import hashlib
import hmac
import json
import os
import pathlib
import re
import secrets
import tempfile

SEAL_VERSION = 1
SEAL_ALG = "ECDH-P256+HKDF-SHA256+A256GCM"
SEAL_INFO = b"leikwan-ultra-lease-v1"
SEAL_AAD_PREFIX = "leikwan-ultra-lease-v1|"
KEY_FILE_VERSION = 1
# Opened fields never replace the lease identity the AAD was bound to.
RESERVED_LEASE_KEYS = frozenset({"sealed", "lease_id", "forward_id"})


class LeaseSealError(ValueError):
    """A sealed lease could not be opened (callers map this to lease_unseal_failed)."""


# --------------------------------------------------------------------------- P-256

_P = 0xFFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFF
_N = 0xFFFFFFFF00000000FFFFFFFFFFFFFFFFBCE6FAADA7179E84F3B9CAC2FC632551
_B = 0x5AC635D8AA3A93E7B3EBBD55769886BC651D06B0CC53B0F63BCE3C3E27D2604B
_GX = 0x6B17D1F2E12C4247F8BCE6E563A440F277037D812DEB33A0F4A13945D898C296
_GY = 0x4FE342E2FE1A7F9B8EE7EB4A7C0F9E162BCE33576B315ECECBB6406837BF51F5


def _point_add(x1: int, y1: int, z1: int, x2: int, y2: int, z2: int) -> tuple[int, int, int]:
    """Complete addition for a = -3 (Renes-Costello-Batina 2015, Algorithm 4).

    Valid for every input pair including doubling and the point at infinity
    (0, 1, 0), so the ladder needs no exceptional-case branches.
    """
    p, b = _P, _B
    t0 = x1 * x2 % p
    t1 = y1 * y2 % p
    t2 = z1 * z2 % p
    t3 = (x1 + y1) * (x2 + y2) % p
    t4 = (t0 + t1) % p
    t3 = (t3 - t4) % p
    t4 = (y1 + z1) * (y2 + z2) % p
    x3 = (t1 + t2) % p
    t4 = (t4 - x3) % p
    x3 = (x1 + z1) * (x2 + z2) % p
    y3 = (t0 + t2) % p
    y3 = (x3 - y3) % p
    z3 = b * t2 % p
    x3 = (y3 - z3) % p
    z3 = (x3 + x3) % p
    x3 = (x3 + z3) % p
    z3 = (t1 - x3) % p
    x3 = (t1 + x3) % p
    y3 = b * y3 % p
    t1 = (t2 + t2) % p
    t2 = (t1 + t2) % p
    y3 = (y3 - t2) % p
    y3 = (y3 - t0) % p
    t1 = (y3 + y3) % p
    y3 = (t1 + y3) % p
    t1 = (t0 + t0) % p
    t0 = (t1 + t0) % p
    t0 = (t0 - t2) % p
    t1 = t4 * y3 % p
    t2 = t0 * y3 % p
    y3 = x3 * z3 % p
    y3 = (y3 + t2) % p
    x3 = t3 * x3 % p
    x3 = (x3 - t1) % p
    z3 = t4 * z3 % p
    t1 = t3 * t0 % p
    z3 = (z3 + t1) % p
    return x3, y3, z3


def _scalar_mult(k: int, x: int, y: int) -> tuple[int, int] | None:
    """Return k*(x, y) in affine coordinates, or None for the point at infinity."""
    r0 = (0, 1, 0)
    r1 = (x, y, 1)
    for i in range(255, -1, -1):
        mask = -((k >> i) & 1)
        # Arithmetic conditional swap (no secret-dependent branch).
        a0, a1, a2 = r0
        b0, b1, b2 = r1
        t = mask & (a0 ^ b0); a0 ^= t; b0 ^= t
        t = mask & (a1 ^ b1); a1 ^= t; b1 ^= t
        t = mask & (a2 ^ b2); a2 ^= t; b2 ^= t
        b0, b1, b2 = _point_add(a0, a1, a2, b0, b1, b2)
        a0, a1, a2 = _point_add(a0, a1, a2, a0, a1, a2)
        t = mask & (a0 ^ b0); a0 ^= t; b0 ^= t
        t = mask & (a1 ^ b1); a1 ^= t; b1 ^= t
        t = mask & (a2 ^ b2); a2 ^= t; b2 ^= t
        r0 = (a0, a1, a2)
        r1 = (b0, b1, b2)
    zx, zy, zz = r0
    if zz % _P == 0:
        return None
    inverse = pow(zz, _P - 2, _P)
    return zx * inverse % _P, zy * inverse % _P


def _on_curve(x: int, y: int) -> bool:
    return 0 <= x < _P and 0 <= y < _P and (y * y - (x * x * x - 3 * x + _B)) % _P == 0


def decode_public_key(raw: bytes) -> tuple[int, int]:
    """Validate a 65-byte uncompressed SEC1 point; P-256 has cofactor 1."""
    if not isinstance(raw, (bytes, bytearray)) or len(raw) != 65 or raw[0] != 0x04:
        raise LeaseSealError("public key must be a 65-byte uncompressed point")
    x = int.from_bytes(raw[1:33], "big")
    y = int.from_bytes(raw[33:], "big")
    if not _on_curve(x, y):
        raise LeaseSealError("public key is not on P-256")
    return x, y


def encode_public_key(x: int, y: int) -> bytes:
    return b"\x04" + x.to_bytes(32, "big") + y.to_bytes(32, "big")


def valid_private_scalar(d: object) -> bool:
    return isinstance(d, int) and not isinstance(d, bool) and 1 <= d < _N


def generate_private_scalar() -> int:
    return secrets.randbelow(_N - 1) + 1


def public_key_for(d: int) -> bytes:
    if not valid_private_scalar(d):
        raise LeaseSealError("invalid private scalar")
    point = _scalar_mult(d, _GX, _GY)
    if point is None:  # unreachable for 1 <= d < n
        raise LeaseSealError("invalid private scalar")
    return encode_public_key(*point)


def ecdh_shared_secret(d: int, peer_public: bytes) -> bytes:
    """32-byte big-endian x coordinate of d * peer_public."""
    if not valid_private_scalar(d):
        raise LeaseSealError("invalid private scalar")
    x, y = decode_public_key(peer_public)
    point = _scalar_mult(d, x, y)
    if point is None:
        raise LeaseSealError("ECDH produced the point at infinity")
    return point[0].to_bytes(32, "big")


def key_id(public_key: bytes) -> str:
    return hashlib.sha256(bytes(public_key)).hexdigest()[:32]


# --------------------------------------------------------------------------- HKDF

def hkdf_sha256(ikm: bytes, salt: bytes, info: bytes, length: int = 32) -> bytes:
    if not 0 < length <= 255 * 32:
        raise ValueError("invalid HKDF length")
    prk = hmac.new(salt or b"\x00" * 32, ikm, hashlib.sha256).digest()
    output = b""
    block = b""
    counter = 1
    while len(output) < length:
        block = hmac.new(prk, block + info + bytes([counter]), hashlib.sha256).digest()
        output += block
        counter += 1
    return output[:length]


# --------------------------------------------------------------------------- AES-256

def _build_aes_tables() -> tuple[list[int], list[list[int]]]:
    def rotl8(value: int, shift: int) -> int:
        return ((value << shift) | (value >> (8 - shift))) & 0xFF

    sbox = [0] * 256
    p = q = 1
    while True:
        p = p ^ ((p << 1) & 0xFF) ^ (0x1B if p & 0x80 else 0)
        q ^= q << 1
        q ^= q << 2
        q ^= q << 4
        q &= 0xFF
        if q & 0x80:
            q ^= 0x09
        sbox[p] = q ^ rotl8(q, 1) ^ rotl8(q, 2) ^ rotl8(q, 3) ^ rotl8(q, 4) ^ 0x63
        if p == 1:
            break
    sbox[0] = 0x63
    te0 = []
    for s in sbox:
        s2 = ((s << 1) ^ (0x1B if s & 0x80 else 0)) & 0xFF
        te0.append((s2 << 24) | (s << 16) | (s << 8) | (s2 ^ s))
    tables = [te0]
    for shift in (8, 16, 24):
        tables.append([((v >> shift) | (v << (32 - shift))) & 0xFFFFFFFF for v in te0])
    return sbox, tables


_SBOX, (_TE0, _TE1, _TE2, _TE3) = _build_aes_tables()


def _aes256_expand_key(key: bytes) -> list[int]:
    if len(key) != 32:
        raise ValueError("AES-256 needs a 32-byte key")
    sbox = _SBOX
    words = [int.from_bytes(key[i : i + 4], "big") for i in range(0, 32, 4)]
    rcon = 1
    for i in range(8, 60):
        temp = words[i - 1]
        if i % 8 == 0:
            temp = ((temp << 8) & 0xFFFFFFFF) | (temp >> 24)
            temp = (sbox[temp >> 24] << 24) | (sbox[(temp >> 16) & 0xFF] << 16) | (sbox[(temp >> 8) & 0xFF] << 8) | sbox[temp & 0xFF]
            temp ^= rcon << 24
            rcon = ((rcon << 1) ^ (0x1B if rcon & 0x80 else 0)) & 0xFF
        elif i % 8 == 4:
            temp = (sbox[temp >> 24] << 24) | (sbox[(temp >> 16) & 0xFF] << 16) | (sbox[(temp >> 8) & 0xFF] << 8) | sbox[temp & 0xFF]
        words.append(words[i - 8] ^ temp)
    return words


def _aes_encrypt_block(round_keys: list[int], block: bytes) -> bytes:
    te0, te1, te2, te3, sbox = _TE0, _TE1, _TE2, _TE3, _SBOX
    s0 = int.from_bytes(block[0:4], "big") ^ round_keys[0]
    s1 = int.from_bytes(block[4:8], "big") ^ round_keys[1]
    s2 = int.from_bytes(block[8:12], "big") ^ round_keys[2]
    s3 = int.from_bytes(block[12:16], "big") ^ round_keys[3]
    for r in range(1, 14):
        k = 4 * r
        t0 = te0[s0 >> 24] ^ te1[(s1 >> 16) & 0xFF] ^ te2[(s2 >> 8) & 0xFF] ^ te3[s3 & 0xFF] ^ round_keys[k]
        t1 = te0[s1 >> 24] ^ te1[(s2 >> 16) & 0xFF] ^ te2[(s3 >> 8) & 0xFF] ^ te3[s0 & 0xFF] ^ round_keys[k + 1]
        t2 = te0[s2 >> 24] ^ te1[(s3 >> 16) & 0xFF] ^ te2[(s0 >> 8) & 0xFF] ^ te3[s1 & 0xFF] ^ round_keys[k + 2]
        t3 = te0[s3 >> 24] ^ te1[(s0 >> 16) & 0xFF] ^ te2[(s1 >> 8) & 0xFF] ^ te3[s2 & 0xFF] ^ round_keys[k + 3]
        s0, s1, s2, s3 = t0, t1, t2, t3
    out = []
    for i, (a, b, c, d) in enumerate(((s0, s1, s2, s3), (s1, s2, s3, s0), (s2, s3, s0, s1), (s3, s0, s1, s2))):
        word = (sbox[a >> 24] << 24) | (sbox[(b >> 16) & 0xFF] << 16) | (sbox[(c >> 8) & 0xFF] << 8) | sbox[d & 0xFF]
        out.append((word ^ round_keys[56 + i]).to_bytes(4, "big"))
    return b"".join(out)


def aes256_encrypt_block(key: bytes, block: bytes) -> bytes:
    """Single-block AES-256 (exposed for the FIPS-197 known-answer test)."""
    if len(block) != 16:
        raise ValueError("AES block must be 16 bytes")
    return _aes_encrypt_block(_aes256_expand_key(key), block)


# --------------------------------------------------------------------------- GCM

_GCM_R = 0xE1 << 120


def _gf128_mul(x: int, y: int) -> int:
    z = 0
    v = y
    for i in range(127, -1, -1):
        z ^= v & -((x >> i) & 1)
        v = (v >> 1) ^ (_GCM_R & -(v & 1))
    return z


def _ghash(h: int, aad: bytes, ciphertext: bytes) -> int:
    y = 0
    for data in (aad, ciphertext):
        for offset in range(0, len(data), 16):
            chunk = data[offset : offset + 16]
            y = _gf128_mul(y ^ int.from_bytes(chunk.ljust(16, b"\x00"), "big"), h)
    lengths = ((len(aad) * 8) << 64) | (len(ciphertext) * 8)
    return _gf128_mul(y ^ lengths, h)


def aes256_gcm_decrypt(key: bytes, iv: bytes, ciphertext_and_tag: bytes, aad: bytes) -> bytes:
    """AES-256-GCM with a 96-bit IV and 128-bit tag; raises LeaseSealError on any failure."""
    if len(key) != 32 or len(iv) != 12 or len(ciphertext_and_tag) < 16:
        raise LeaseSealError("invalid GCM parameters")
    ciphertext, tag = bytes(ciphertext_and_tag[:-16]), bytes(ciphertext_and_tag[-16:])
    if len(ciphertext) > 65536:
        raise LeaseSealError("sealed payload too large")
    round_keys = _aes256_expand_key(key)
    h = int.from_bytes(_aes_encrypt_block(round_keys, b"\x00" * 16), "big")
    j0 = bytes(iv) + b"\x00\x00\x00\x01"
    s = _ghash(h, bytes(aad), ciphertext)
    expected = (int.from_bytes(_aes_encrypt_block(round_keys, j0), "big") ^ s).to_bytes(16, "big")
    if not hmac.compare_digest(expected, tag):
        raise LeaseSealError("GCM authentication failed")
    out = bytearray()
    counter = 2
    prefix = bytes(iv)
    for offset in range(0, len(ciphertext), 16):
        stream = _aes_encrypt_block(round_keys, prefix + counter.to_bytes(4, "big"))
        chunk = ciphertext[offset : offset + 16]
        out += bytes(a ^ b for a, b in zip(chunk, stream))
        counter = (counter + 1) & 0xFFFFFFFF
    return bytes(out)


# --------------------------------------------------------------------------- encoding

_B64URL = re.compile(r"[A-Za-z0-9_-]*")


def b64url_encode(raw: bytes) -> str:
    return base64.urlsafe_b64encode(bytes(raw)).rstrip(b"=").decode("ascii")


def b64url_decode(value: object) -> bytes:
    if not isinstance(value, str):
        raise LeaseSealError("expected a base64url string")
    text = value.rstrip("=")
    if len(value) - len(text) > 2 or _B64URL.fullmatch(text) is None or len(text) % 4 == 1:
        raise LeaseSealError("invalid base64url")
    try:
        return base64.urlsafe_b64decode(text + "=" * (-len(text) % 4))
    except (binascii.Error, ValueError) as exc:
        raise LeaseSealError("invalid base64url") from exc


# --------------------------------------------------------------------------- device key

class LeaseDeviceKey:
    """One device's P-256 lease key. ``repr`` never shows the private scalar."""

    __slots__ = ("_d", "public_key", "public_b64url", "kid", "persisted")

    def __init__(self, private_scalar: int, *, persisted: bool = True):
        if not valid_private_scalar(private_scalar):
            raise LeaseSealError("invalid private scalar")
        self._d = private_scalar
        self.public_key = public_key_for(private_scalar)
        self.public_b64url = b64url_encode(self.public_key)
        self.kid = key_id(self.public_key)
        self.persisted = persisted

    def __repr__(self) -> str:
        return f"LeaseDeviceKey(kid={self.kid!r}, persisted={self.persisted!r})"

    def shared_secret(self, peer_public: bytes) -> bytes:
        return ecdh_shared_secret(self._d, peer_public)

    def open_sealed(self, lease_id: object, sealed: object) -> dict:
        """Open a ``sealed`` object; returns the plaintext JSON object."""
        if not isinstance(lease_id, str) or lease_id == "":
            raise LeaseSealError("lease_id missing")
        if not isinstance(sealed, dict):
            raise LeaseSealError("sealed is not an object")
        version = sealed.get("v")
        if isinstance(version, bool) or version != SEAL_VERSION:
            raise LeaseSealError("unsupported sealed version")
        if sealed.get("alg") != SEAL_ALG:
            raise LeaseSealError("unsupported sealed algorithm")
        kid = sealed.get("kid")
        if not isinstance(kid, str) or not hmac.compare_digest(kid.encode("utf-8", "replace"), self.kid.encode("ascii")):
            raise LeaseSealError("sealed for a different device key")
        epk = b64url_decode(sealed.get("epk"))
        iv = b64url_decode(sealed.get("iv"))
        ct = b64url_decode(sealed.get("ct"))
        shared = self.shared_secret(epk)
        key = hkdf_sha256(shared, epk + self.public_key, SEAL_INFO, 32)
        aad = (SEAL_AAD_PREFIX + lease_id).encode("utf-8")
        plain = aes256_gcm_decrypt(key, iv, ct, aad)
        try:
            opened = json.loads(plain.decode("utf-8"))
        except (UnicodeDecodeError, ValueError) as exc:
            raise LeaseSealError("sealed plaintext is not JSON") from exc
        if not isinstance(opened, dict):
            raise LeaseSealError("sealed plaintext is not an object")
        return opened


def open_sealed_lease(lease: dict, device_key: LeaseDeviceKey | None) -> dict:
    """Return the lease with ``sealed`` opened and merged; unsealed leases are returned unchanged."""
    if not isinstance(lease, dict) or "sealed" not in lease:
        return lease
    if device_key is None:
        raise LeaseSealError("sealed lease but no device key")
    opened = device_key.open_sealed(lease.get("lease_id"), lease.get("sealed"))
    merged = {key: value for key, value in lease.items() if key != "sealed"}
    for key, value in opened.items():
        if key not in RESERVED_LEASE_KEYS:
            merged[key] = value
    return merged


def _parse_key_file(text: str) -> int | None:
    try:
        payload = json.loads(text)
    except ValueError:
        return None
    if not isinstance(payload, dict) or payload.get("v") != KEY_FILE_VERSION or payload.get("alg") != "P-256":
        return None
    raw = payload.get("d")
    if not isinstance(raw, str) or re.fullmatch(r"[0-9a-f]{64}", raw) is None:
        return None
    scalar = int(raw, 16)
    return scalar if valid_private_scalar(scalar) else None


def _write_key_file(path: pathlib.Path, scalar: int, *, replace: bool) -> int:
    """Persist atomically with mode 0600; returns the scalar that is on disk."""
    body = json.dumps({"v": KEY_FILE_VERSION, "alg": "P-256", "d": format(scalar, "064x")}) + "\n"
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=str(path.parent))
    try:
        if hasattr(os, "fchmod"):
            with contextlib.suppress(OSError):
                os.fchmod(fd, 0o600)  # mkstemp already uses 0600; keep it explicit
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
        if not replace:
            try:
                # Never clobber a key another process created concurrently.
                os.link(temporary_name, path)
                return scalar
            except FileExistsError:
                winner = _parse_key_file(path.read_text(encoding="utf-8"))
                if winner is not None:
                    return winner
            except OSError:
                pass
        os.replace(temporary_name, path)
        return scalar
    finally:
        if os.path.exists(temporary_name):
            with contextlib.suppress(OSError):
                os.unlink(temporary_name)


def load_or_create_device_key(path: pathlib.Path | str) -> tuple[LeaseDeviceKey, str]:
    """Load the persisted key, creating it once.

    Returns ``(key, event)`` where event is "loaded", "created", "replaced"
    (missing/corrupt file regenerated), "unreadable" or "unpersisted" (the
    last two return a process-lifetime key without touching the file).
    """
    path = pathlib.Path(path)
    try:
        text = path.read_text(encoding="utf-8")
    except FileNotFoundError:
        text = None
    except (OSError, UnicodeDecodeError) as exc:
        if isinstance(exc, UnicodeDecodeError):
            text = ""
        else:
            # Could be transient (EIO, EACCES): never overwrite what we cannot read.
            return LeaseDeviceKey(generate_private_scalar(), persisted=False), "unreadable"
    if text is not None:
        scalar = _parse_key_file(text)
        if scalar is not None:
            with contextlib.suppress(OSError):
                if (path.stat().st_mode & 0o077) != 0:
                    os.chmod(path, 0o600)
            return LeaseDeviceKey(scalar), "loaded"
    candidate = generate_private_scalar()
    try:
        stored = _write_key_file(path, candidate, replace=text is not None)
    except OSError:
        return LeaseDeviceKey(candidate, persisted=False), "unpersisted"
    return LeaseDeviceKey(stored), "created" if text is None else "replaced"
