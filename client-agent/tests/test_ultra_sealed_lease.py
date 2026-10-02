import copy
import importlib.util
import json
import logging
import os
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


SEAL = load_module("leikwan_lease_seal_under_test", "client-agent/leikwan_lease_seal.py")
AGENT = load_module("client_agent_sealed_lease", "client-agent/client_agent.py")

try:  # cross-checks only; the agent itself never imports cryptography
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import ec
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM
    from cryptography.hazmat.primitives.kdf.hkdf import HKDF

    HAVE_CRYPTOGRAPHY = True
except ImportError:  # pragma: no cover - dev machines have it
    HAVE_CRYPTOGRAPHY = False

# docs/specs/2026-10-02-ultra-sealed-lease-v1.md section 5.
VECTOR = {
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
            "ct": "PBnTWzpuLWhTPFhpX86NSK30Xe3CbjjHaQOs6PSBQ0uymvpO2hvnLDuWaaTqdrVWBWORXa7dQfFmzKxr4pN6xJ6Bi-IEiHBKOE8MgpV1REPUugS3rInpQhM66G9rY1ZrWmSTysuUlhoyRo_HeRgGT5uPETJfdiOThqzck9ixD8_ykEXHkO0VstbSUnc09hBqChXJX92Jmuf_tSb-9DOgvnfxd7A8_UDn4x52VVC6HuGQwOXG11gWfhUFSI62OMIJbdvKo3u6TmeV7GHL",
        },
    },
    "expected_plaintext": "{\"endpoint\":{\"ip\":\"203.0.113.7\",\"port\":40123},\"credential\":{\"user\":\"lg-user-1\",\"password\":\"lg-secret-1\"},\"external_uot\":{\"enabled\":true,\"sidecar_target\":\"198.51.100.9:7443\",\"token\":\"tok\"}}",
}
N = 0xFFFFFFFF00000000FFFFFFFFFFFFFFFFBCE6FAADA7179E84F3B9CAC2FC632551


def vector_key():
    return SEAL.LeaseDeviceKey(int(VECTOR["device_private_scalar_hex"], 16))


def flip_b64(value: str, index: int) -> str:
    raw = bytearray(SEAL.b64url_decode(value))
    raw[index] ^= 0x01
    return SEAL.b64url_encode(bytes(raw))


def ultra_forward(forward_id: int = 42) -> dict:
    return {
        "forward_id": forward_id,
        "backend_mode": "ultra",
        "target": "203.0.113.10:443",
        "transport": "tcp+udp",
        "ultra_access": {
            "protocol": "leikwan-ultra-policy-v2",
            "session_ticket": f"opaque-{forward_id}",
            "policy_generation": "a" * 64,
            "target_digest": "b" * 64,
            "transport": "tcp+udp",
        },
    }


class LeaseSealPrimitiveTests(unittest.TestCase):
    def test_aes256_fips197_known_answer(self):
        self.assertEqual(
            "8ea2b7ca516745bfeafc49904b496089",
            SEAL.aes256_encrypt_block(bytes(range(32)), bytes.fromhex("00112233445566778899aabbccddeeff")).hex(),
        )

    def test_hkdf_sha256_rfc5869_case1(self):
        okm = SEAL.hkdf_sha256(b"\x0b" * 22, bytes(range(13)), bytes(range(0xF0, 0xFA)), 42)
        self.assertEqual(
            "3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865",
            okm.hex(),
        )

    def test_vector_public_key_and_kid(self):
        key = vector_key()
        self.assertEqual(VECTOR["device_public_b64url"], key.public_b64url)
        self.assertEqual(65, len(key.public_key))
        self.assertEqual(VECTOR["lease"]["sealed"]["kid"], key.kid)
        self.assertNotIn(VECTOR["device_private_scalar_hex"], repr(key))
        self.assertNotIn(str(int(VECTOR["device_private_scalar_hex"], 16)), repr(key))

    def test_vector_opens_to_exact_plaintext(self):
        key = vector_key()
        sealed = VECTOR["lease"]["sealed"]
        epk = SEAL.b64url_decode(sealed["epk"])
        aes_key = SEAL.hkdf_sha256(key.shared_secret(epk), epk + key.public_key, b"leikwan-ultra-lease-v1", 32)
        plain = SEAL.aes256_gcm_decrypt(
            aes_key,
            SEAL.b64url_decode(sealed["iv"]),
            SEAL.b64url_decode(sealed["ct"]),
            b"leikwan-ultra-lease-v1|" + VECTOR["lease"]["lease_id"].encode(),
        )
        self.assertEqual(VECTOR["expected_plaintext"].encode("utf-8"), plain)

    def test_vector_lease_is_merged_and_sealed_dropped(self):
        opened = SEAL.open_sealed_lease(copy.deepcopy(VECTOR["lease"]), vector_key())
        expected = json.loads(VECTOR["expected_plaintext"])
        self.assertNotIn("sealed", opened)
        self.assertEqual("ul2_test-vector-0001", opened["lease_id"])
        self.assertEqual(42, opened["forward_id"])
        for field in ("endpoint", "credential", "external_uot"):
            self.assertEqual(expected[field], opened[field])

    def test_unsealed_lease_is_returned_unchanged(self):
        lease = {"lease_id": "ul2_x", "endpoint": {"ip": "203.0.113.1", "port": 1}}
        self.assertIs(lease, SEAL.open_sealed_lease(lease, vector_key()))
        self.assertIs(lease, SEAL.open_sealed_lease(lease, None))

    def test_any_flipped_byte_fails(self):
        key = vector_key()
        sealed = VECTOR["lease"]["sealed"]
        cases = []
        for field, indexes in (("ct", (0, 50, -17, -1)), ("iv", (0, 11)), ("epk", (1, 40, 64))):
            for index in indexes:
                mutated = copy.deepcopy(VECTOR["lease"])
                mutated["sealed"][field] = flip_b64(sealed[field], index)
                cases.append(mutated)
        renamed = copy.deepcopy(VECTOR["lease"])
        renamed["lease_id"] = "ul2_test-vector-0002"
        cases.append(renamed)
        for lease in cases:
            with self.subTest(lease=lease):
                with self.assertRaises(SEAL.LeaseSealError):
                    SEAL.open_sealed_lease(lease, key)

    def test_header_fields_are_checked(self):
        key = vector_key()
        mutations = [
            ("v", 2), ("v", True), ("v", "1"),
            ("alg", "ECDH-P384+HKDF-SHA256+A256GCM"),
            ("kid", "0" * 32), ("kid", None),
            ("epk", "not base64!"), ("iv", "AAAA"), ("ct", ""), ("ct", None),
        ]
        for field, value in mutations:
            with self.subTest(field=field, value=value):
                lease = copy.deepcopy(VECTOR["lease"])
                lease["sealed"][field] = value
                with self.assertRaises(SEAL.LeaseSealError):
                    SEAL.open_sealed_lease(lease, key)
        for lease in ({**VECTOR["lease"], "sealed": "x"}, {k: v for k, v in VECTOR["lease"].items() if k != "lease_id"}):
            with self.assertRaises(SEAL.LeaseSealError):
                SEAL.open_sealed_lease(copy.deepcopy(lease), key)
        with self.assertRaises(SEAL.LeaseSealError):
            SEAL.open_sealed_lease(copy.deepcopy(VECTOR["lease"]), None)

    def test_off_curve_and_malformed_epk_rejected(self):
        key = vector_key()
        good = SEAL.b64url_decode(VECTOR["lease"]["sealed"]["epk"])
        p = 0xFFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFF
        x = int.from_bytes(good[1:33], "big")
        y = int.from_bytes(good[33:], "big")
        bad_points = [
            good[:1] + good[1:33] + ((y + 1) % p).to_bytes(32, "big"),  # off curve
            b"\x04" + b"\x00" * 64,  # (0, 0) is not on P-256
            b"\x00",  # SEC1 point at infinity
            b"\x02" + good[1:33],  # compressed form is not accepted
            good[:-1],
            b"\x04" + (x + p).to_bytes(33, "big")[1:] + good[33:],  # x >= p (wraps)
            b"\x04" + good[1:33] + (y + p).to_bytes(33, "big")[1:],  # y >= p
        ]
        for raw in bad_points:
            with self.subTest(raw=raw.hex()):
                with self.assertRaises(SEAL.LeaseSealError):
                    key.shared_secret(raw)
                lease = copy.deepcopy(VECTOR["lease"])
                lease["sealed"]["epk"] = SEAL.b64url_encode(raw)
                with self.assertRaises(SEAL.LeaseSealError):
                    SEAL.open_sealed_lease(lease, key)

    def test_open_is_fast_enough(self):
        key = vector_key()
        rounds = 10
        started = time.perf_counter()
        for _ in range(rounds):
            SEAL.open_sealed_lease(copy.deepcopy(VECTOR["lease"]), key)
        per_open = (time.perf_counter() - started) / rounds
        started = time.perf_counter()
        SEAL.LeaseDeviceKey(SEAL.generate_private_scalar())
        keygen = time.perf_counter() - started
        sys.stderr.write(f"\nsealed lease open: {per_open * 1000:.2f} ms/open, key generation: {keygen * 1000:.2f} ms\n")
        self.assertLess(per_open, 0.25)


@unittest.skipUnless(HAVE_CRYPTOGRAPHY, "cryptography not installed (cross-check only)")
class LeaseSealCrossCheckTests(unittest.TestCase):
    def seal_with_cryptography(self, device_public: bytes, lease_id: str, plaintext: bytes, kid: str) -> dict:
        ephemeral = ec.generate_private_key(ec.SECP256R1())
        epk = ephemeral.public_key().public_bytes(serialization.Encoding.X962, serialization.PublicFormat.UncompressedPoint)
        peer = ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), device_public)
        shared = ephemeral.exchange(ec.ECDH(), peer)
        key = HKDF(algorithm=hashes.SHA256(), length=32, salt=epk + device_public, info=b"leikwan-ultra-lease-v1").derive(shared)
        iv = os.urandom(12)
        ct = AESGCM(key).encrypt(iv, plaintext, b"leikwan-ultra-lease-v1|" + lease_id.encode())
        return {
            "v": 1,
            "alg": "ECDH-P256+HKDF-SHA256+A256GCM",
            "kid": kid,
            "epk": SEAL.b64url_encode(epk),
            "iv": SEAL.b64url_encode(iv),
            "ct": SEAL.b64url_encode(ct),
        }

    def test_public_key_matches_cryptography_for_known_scalars(self):
        scalars = [1, 2, 3, N - 1, N - 2, (N - 1) // 2, int(VECTOR["device_private_scalar_hex"], 16)]
        scalars += [SEAL.generate_private_scalar() for _ in range(8)]
        for scalar in scalars:
            with self.subTest(scalar=hex(scalar)):
                reference = ec.derive_private_key(scalar, ec.SECP256R1()).public_key().public_bytes(
                    serialization.Encoding.X962, serialization.PublicFormat.UncompressedPoint
                )
                self.assertEqual(reference, SEAL.public_key_for(scalar))

    def test_generated_key_round_trips_ecdh_with_independent_implementation(self):
        for _ in range(4):
            ours = SEAL.LeaseDeviceKey(SEAL.generate_private_scalar())
            peer = ec.generate_private_key(ec.SECP256R1())
            peer_public = peer.public_key().public_bytes(serialization.Encoding.X962, serialization.PublicFormat.UncompressedPoint)
            reference = peer.exchange(ec.ECDH(), ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), ours.public_key))
            self.assertEqual(reference, ours.shared_secret(peer_public))

    def test_gcm_matches_cryptography_for_various_lengths(self):
        key = os.urandom(32)
        for length in (0, 1, 15, 16, 17, 31, 32, 33, 200):
            for aad in (b"", b"a", os.urandom(40)):
                iv = os.urandom(12)
                plaintext = os.urandom(length)
                sealed = AESGCM(key).encrypt(iv, plaintext, aad)
                self.assertEqual(plaintext, SEAL.aes256_gcm_decrypt(key, iv, sealed, aad))
                broken = bytearray(sealed)
                broken[-1] ^= 0x80
                with self.assertRaises(SEAL.LeaseSealError):
                    SEAL.aes256_gcm_decrypt(key, iv, bytes(broken), aad)

    def test_independently_sealed_lease_opens_and_bad_json_fails(self):
        device = SEAL.LeaseDeviceKey(SEAL.generate_private_scalar())
        body = {"endpoint": {"ip": "203.0.113.9", "port": 9}, "credential": {"user": "u", "password": "p"}}
        lease = {"lease_id": "ul2_cross", "forward_id": 7}
        lease["sealed"] = self.seal_with_cryptography(device.public_key, "ul2_cross", json.dumps(body).encode(), device.kid)
        opened = SEAL.open_sealed_lease(lease, device)
        self.assertEqual(body["endpoint"], opened["endpoint"])
        self.assertEqual(body["credential"], opened["credential"])
        self.assertNotIn("sealed", opened)

        for plaintext in (b"[1,2]", b"\"text\"", b"{not json", b"\xff\xfe"):
            with self.subTest(plaintext=plaintext):
                bad = {"lease_id": "ul2_cross", "forward_id": 7}
                bad["sealed"] = self.seal_with_cryptography(device.public_key, "ul2_cross", plaintext, device.kid)
                with self.assertRaises(SEAL.LeaseSealError):
                    SEAL.open_sealed_lease(bad, device)

        # Opened fields never rebind the lease identity.
        rebind = {"lease_id": "ul2_cross", "forward_id": 7}
        rebind["sealed"] = self.seal_with_cryptography(
            device.public_key, "ul2_cross", json.dumps({"lease_id": "other", "forward_id": 8, "endpoint": {}}).encode(), device.kid
        )
        opened = SEAL.open_sealed_lease(rebind, device)
        self.assertEqual(("ul2_cross", 7), (opened["lease_id"], opened["forward_id"]))


class LeaseKeyPersistenceTests(unittest.TestCase):
    def test_key_is_created_once_with_owner_only_mode_and_reused(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "ultra-lease-key"
            first, event = SEAL.load_or_create_device_key(path)
            self.assertEqual("created", event)
            self.assertTrue(first.persisted)
            if os.name == "posix":
                self.assertEqual(0o600, path.stat().st_mode & 0o777)
            second, event = SEAL.load_or_create_device_key(path)
            self.assertEqual("loaded", event)
            self.assertEqual(first.public_key, second.public_key)
            self.assertNotIn(first.public_b64url, path.read_text(encoding="utf-8"))

    def test_corrupt_key_is_regenerated(self):
        for content in ("", "garbage", json.dumps({"v": 1, "alg": "P-256", "d": "0" * 64}), json.dumps({"v": 1, "alg": "P-256", "d": format(N, "064x")})):
            with self.subTest(content=content), tempfile.TemporaryDirectory() as tmp:
                path = Path(tmp) / "ultra-lease-key"
                path.write_text(content, encoding="utf-8")
                key, event = SEAL.load_or_create_device_key(path)
                self.assertEqual("replaced", event)
                again, event = SEAL.load_or_create_device_key(path)
                self.assertEqual("loaded", event)
                self.assertEqual(key.kid, again.kid)

    def test_unwritable_location_yields_process_key(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "ultra-lease-key"
            with mock.patch.object(SEAL, "_write_key_file", side_effect=PermissionError("read-only")):
                key, event = SEAL.load_or_create_device_key(path)
            self.assertEqual("unpersisted", event)
            self.assertFalse(key.persisted)
            self.assertEqual(65, len(key.public_key))
            self.assertFalse(path.exists())

    def test_unreadable_key_is_never_overwritten(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "ultra-lease-key"
            original, _ = SEAL.load_or_create_device_key(path)
            before = path.read_bytes()
            with mock.patch.object(Path, "read_text", side_effect=PermissionError("denied")):
                key, event = SEAL.load_or_create_device_key(path)
            self.assertEqual("unreadable", event)
            self.assertFalse(key.persisted)
            self.assertEqual(before, path.read_bytes())
            self.assertEqual(original.kid, SEAL.load_or_create_device_key(path)[0].kid)

    def test_agent_caches_one_key_per_state_dir(self):
        with tempfile.TemporaryDirectory() as tmp:
            agent = AGENT.ClientAgent.__new__(AGENT.ClientAgent)
            agent.state_dir = Path(tmp)
            key = agent.lease_device_key()
            self.assertIsNotNone(key)
            self.assertTrue((Path(tmp) / AGENT.LEASE_KEY_FILENAME).is_file())
            self.assertIs(key, agent.lease_device_key())
            fresh = AGENT.ClientAgent.__new__(AGENT.ClientAgent)
            fresh.state_dir = Path(tmp)
            self.assertEqual(key.kid, fresh.lease_device_key().kid)


class ResolveSealedLeaseTests(unittest.TestCase):
    def test_header_is_sent_and_sealed_lease_is_opened(self):
        key = vector_key()
        lease = copy.deepcopy(VECTOR["lease"])
        lease.update({"policy_generation": "a" * 64, "expires_at": "2030-01-01T00:30:00Z"})
        with mock.patch.object(AGENT, "http_json", return_value={"ok": True, "lease_seal": {"v": 1, "kid": key.kid}, "leases": [lease]}) as request:
            resolved = AGENT.resolve_ultra_leases("https://panel.example.test", "token", "device-a", [ultra_forward()], lease_key=key)

        headers = request.call_args.kwargs["extra_headers"]
        self.assertEqual(VECTOR["device_public_b64url"], headers["X-Leikwan-Lease-Key"])
        self.assertEqual("device-a", headers["x-device-id"])
        opened = resolved[0]["_ultra_lease"]
        self.assertNotIn("sealed", opened)
        self.assertEqual({"ip": "203.0.113.7", "port": 40123}, opened["endpoint"])
        self.assertEqual("lg-secret-1", opened["credential"]["password"])
        self.assertTrue(opened["external_uot"]["enabled"])
        self.assertNotIn("_ultra_lease_resolution_failed", resolved[0])

    def test_unsealed_lease_still_works_with_and_without_key(self):
        clear = {
            "forward_id": 42,
            "lease_id": "ul2_clear",
            "policy_generation": "a" * 64,
            "endpoint": {"ip": "203.0.113.20", "port": 5438},
            "credential": {"user": "line-user", "password": "line-password"},
        }
        for lease_key in (None, vector_key()):
            with self.subTest(lease_key=lease_key):
                with mock.patch.object(AGENT, "http_json", return_value={"ok": True, "leases": [dict(clear)]}) as request:
                    resolved = AGENT.resolve_ultra_leases("https://panel.example.test", "token", "device-a", [ultra_forward()], lease_key=lease_key)
                self.assertEqual(clear, resolved[0]["_ultra_lease"])
                headers = request.call_args.kwargs["extra_headers"]
                self.assertEqual(lease_key is not None, "X-Leikwan-Lease-Key" in headers)

    def test_unseal_failure_fails_that_forward_without_fallback_or_secret_logging(self):
        key = vector_key()
        tampered = copy.deepcopy(VECTOR["lease"])
        tampered["sealed"]["ct"] = flip_b64(tampered["sealed"]["ct"], 3)
        tampered["endpoint"] = {"ip": "198.51.100.66", "port": 1}  # must never be used
        other = {"forward_id": 43, "lease_id": "ul2_43", "endpoint": {"ip": "203.0.113.43", "port": 43}, "credential": {"user": "u", "password": "p"}}
        with mock.patch.object(AGENT, "http_json", return_value={"ok": True, "leases": [tampered, other]}), \
                self.assertLogs(level=logging.WARNING) as logs:
            resolved = AGENT.resolve_ultra_leases(
                "https://panel.example.test", "token", "device-a", [ultra_forward(42), ultra_forward(43)], lease_key=key
            )
        self.assertNotIn("_ultra_lease", resolved[0])
        self.assertTrue(resolved[0]["_ultra_lease_resolution_failed"])
        self.assertEqual("ul2_43", resolved[1]["_ultra_lease"]["lease_id"])
        text = "\n".join(logs.output)
        self.assertIn("lease_unseal_failed", text)
        for secret in ("lg-secret-1", "lg-user-1", VECTOR["device_private_scalar_hex"], tampered["sealed"]["ct"]):
            self.assertNotIn(secret, text)

    def test_sealed_lease_without_device_key_fails_closed(self):
        with mock.patch.object(AGENT, "http_json", return_value={"ok": True, "leases": [copy.deepcopy(VECTOR["lease"])]}):
            resolved = AGENT.resolve_ultra_leases("https://panel.example.test", "token", "device-a", [ultra_forward()])
        self.assertNotIn("_ultra_lease", resolved[0])
        self.assertTrue(resolved[0]["_ultra_lease_resolution_failed"])

    def test_key_refusals_fail_the_batch_and_never_regenerate_the_key(self):
        for status, code in ((400, "lease_key_invalid"), (409, "lease_sealing_required"), (409, "lease_device_key_conflict")):
            with self.subTest(code=code), tempfile.TemporaryDirectory() as tmp:
                agent = AGENT.ClientAgent.__new__(AGENT.ClientAgent)
                agent.state_dir = Path(tmp)
                key = agent.lease_device_key()
                before = (Path(tmp) / AGENT.LEASE_KEY_FILENAME).read_bytes()
                error = RuntimeError(f'http {status} https://panel.example.test/api/client/ultra/lease: {{"ok":false,"error":"{code}"}}')
                with mock.patch.object(AGENT, "http_json", side_effect=error) as request, \
                        self.assertLogs(level=logging.WARNING) as logs:
                    # A business refusal fails the batch like any other lease error.
                    resolved = AGENT.resolve_ultra_leases(
                        "https://panel.example.test", "token", "device-a", [ultra_forward()],
                        fallback_endpoints=["https://mirror.example.test"], lease_key=key,
                    )
                self.assertNotIn("_ultra_lease", resolved[0])
                self.assertTrue(resolved[0]["_ultra_lease_resolution_failed"])
                self.assertEqual(1, request.call_count)  # no replay through a mirror
                self.assertIn(code, "\n".join(logs.output))
                self.assertEqual(before, (Path(tmp) / AGENT.LEASE_KEY_FILENAME).read_bytes())
                self.assertIs(key, agent.lease_device_key())

    def test_refusal_of_one_batch_keeps_other_batches(self):
        key = vector_key()
        forwards = [ultra_forward(fid) for fid in range(42, 47)]
        with mock.patch.object(
            AGENT,
            "http_json",
            side_effect=[
                {"ok": True, "leases": [{"forward_id": fid, "lease_id": f"ul2-{fid}"} for fid in range(42, 46)]},
                RuntimeError('http 409 https://panel.example.test/api/client/ultra/lease: {"error":"lease_device_key_conflict"}'),
            ],
        ):
            resolved = AGENT.resolve_ultra_leases("https://panel.example.test", "token", "device-a", forwards, lease_key=key)
        self.assertEqual("ul2-42", resolved[0]["_ultra_lease"]["lease_id"])
        self.assertTrue(resolved[-1]["_ultra_lease_resolution_failed"])


if __name__ == "__main__":
    unittest.main()
