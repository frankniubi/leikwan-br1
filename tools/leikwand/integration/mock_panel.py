#!/usr/bin/env python3
"""Minimal stand-in for the Leikwan control panel.

It implements exactly the endpoints the OpenWrt agent uses, so the daemon and
the real ultra-client runtime can be exercised without touching production.
Behaviour is steered by small files in the state directory, which lets the
test harness change what the panel "decides" while the daemon keeps running.

State files (all optional):
  forward_count   number of ultra forwards to publish          (default 1)
  lease_mode      "two" | "invalid" | "empty" | "none"         (default two)
  action_stop     if present, heartbeat returns a stop action for that id
  listen_port     published listen port                        (default 10001)
  target          published forward target                     (default 127.0.0.1:9)
  reject_platform if present, 422 any platform except this value
  online_limit    "N" or "N/M": 429 client_online_limit_reached on every
                  endpoint (limit N, M active; M defaults to N)
  lease_sealing   "optional" (default) seals leases to the X-Leikwan-Lease-Key
                  device key when the header is present; "off" ignores it;
                  "require" refuses a lease request without the header
                  (docs/specs/2026-10-02-ultra-sealed-lease-v1.md)

Written state: last_lease_key_header (the header value, public key only) and
last_lease_sealed ("sealed N" or "clear N") after every lease answer.
"""

import base64
import hashlib
import json
import os
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

STATE = sys.argv[1] if len(sys.argv) > 1 else "/tmp/leikwan-it"
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 18080
os.makedirs(STATE, exist_ok=True)

HEX64 = "a" * 64
SEAL_INFO = b"leikwan-ultra-lease-v1"
SEAL_ALG = "ECDH-P256+HKDF-SHA256+A256GCM"
SEALED_FIELDS = ("endpoint", "credential", "external_uot")
PULLS = 0
HEARTBEATS = []
LOCK = threading.Lock()


def state(name, default=None):
    path = os.path.join(STATE, name)
    try:
        with open(path) as handle:
            return handle.read().strip()
    except OSError:
        return default


def sha256_hex(value: str) -> str:
    return hashlib.sha256(value.encode()).hexdigest()


def transport_mode() -> str:
    """Transport published for the forward and its policy.

    The fake relay implements the TCP provider handshake only, so the default
    is a TCP forward. A tcp+udp forward would additionally require the UDP
    datagram handshake before a candidate may be considered healthy — which is
    correct product behaviour, and exactly why the test does not pretend to
    cover it.
    """
    value = state("transport", "tcp")
    return value if value in ("tcp", "udp", "tcp+udp") else "tcp"


def policy(forward_id: int, target: str) -> dict:
    return {
        "protocol": "leikwan-ultra-policy-v2",
        "forward_id": forward_id,
        "policy_generation": HEX64,
        "session_ticket": "it-ticket-%d" % forward_id,
        "issued_at": "2020-01-01T00:00:00Z",
        "renew_after": "2020-01-01T00:00:00Z",
        # Comfortably inside the 5 minute + 30s TTL window the agent enforces.
        "expires_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(time.time() + 240)),
        "service_state": "active",
        "transport": transport_mode(),
        "rate_limit_mbps": 100,
        "quota_remaining_bytes": 1 << 30,
        "target_digest": sha256_hex(target),
    }


def lease_frame(forward_id: int, target: str, ip: str, port: int, index: int) -> dict:
    return {
        "protocol": "leikwan-ultra-policy-v2",
        "purpose": "ultra",
        "forward_id": forward_id,
        "session_ticket": "it-ticket-%d" % forward_id,
        "policy_generation": HEX64,
        "lease_id": "lease-%d-%d" % (forward_id, index),
        "expires_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(time.time() + 240)),
        "transport": transport_mode(),
        "rate_limit_mbps": 100,
        "quota_remaining_bytes": 1 << 30,
        "target_digest": sha256_hex(target),
        # The runtime requires a 16..128 character selection reference and one
        # of the four known tier names. Anything else is a lease_invalid
        # rejection, so the mock has to reproduce the real panel's shapes.
        "selection_ref": hashlib.sha256(("ref-%d" % index).encode()).hexdigest(),
        "selection_tier": "paid",
        "candidate_key_hash": hashlib.sha256(("cand-%d" % index).encode()).hexdigest(),
        "nonce_hash": hashlib.sha256(("nonce-%d" % index).encode()).hexdigest(),
        "endpoint": {"ip": ip, "port": port},
        "credential": {"user": "user-%d" % index, "password": "pass-%d" % index},
    }


def b64url_decode(value: str) -> bytes:
    value = value.strip().rstrip("=")
    return base64.urlsafe_b64decode(value + "=" * (-len(value) % 4))


def b64url(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode()


def seal_lease(lease: dict, device_public: bytes) -> dict:
    """Panel side of sealed lease v1, mirroring UltraLeaseSealer::sealLease."""
    from cryptography.hazmat.primitives import hashes
    from cryptography.hazmat.primitives.asymmetric import ec
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM
    from cryptography.hazmat.primitives.kdf.hkdf import HKDF
    from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

    device = ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), device_public)
    ephemeral = ec.generate_private_key(ec.SECP256R1())
    epk = ephemeral.public_key().public_bytes(Encoding.X962, PublicFormat.UncompressedPoint)
    shared = ephemeral.exchange(ec.ECDH(), device)
    key = HKDF(algorithm=hashes.SHA256(), length=32, salt=epk + device_public, info=SEAL_INFO).derive(shared)
    secret = {name: lease.pop(name) for name in SEALED_FIELDS if name in lease}
    plaintext = json.dumps(secret, separators=(",", ":")).encode()
    iv = os.urandom(12)
    ct = AESGCM(key).encrypt(iv, plaintext, b"leikwan-ultra-lease-v1|" + lease.get("lease_id", "").encode())
    lease["sealed"] = {
        "v": 1,
        "alg": SEAL_ALG,
        "kid": hashlib.sha256(device_public).hexdigest()[:32],
        "epk": b64url(epk),
        "iv": b64url(iv),
        "ct": b64url(ct),
    }
    return lease




# --- native outbound -------------------------------------------------------
# Enabled by the state file "native" = "1". The carrier mirrors what the
# Panel publishes for a native forward: a loopback target the server chose,
# an ordinary Ultra grant bound to that target, and the native_outbound
# capability. The lease handler checks the request exactly as
# ClientAgentController::resolveUltraLease does for purpose=native_outbound.
NATIVE_ID = 50
NATIVE_TARGET = "127.0.0.1:41000"
NATIVE_LEASE_PROTOCOL = "leikwan-ultra-native-socks5-v1"


def native_direction() -> str:
    return state("native_direction", "SHA -> HKG")


def native_scopes():
    return [s for s in state("native_scopes", "intl_only global").split() if s]


def native_unrestricted() -> bool:
    return state("native_unrestricted", "1") == "1"


def native_digest(direction: str, scope: str, policy_name: str, version: int) -> str:
    parts = ["native-outbound:v%d" % version, str(NATIVE_ID), direction.strip().lower(), "tcp+udp", scope]
    if version == 2:
        parts.append(policy_name)
    return sha256_hex("\n".join(parts))


def native_forward() -> dict:
    grant = policy(NATIVE_ID, NATIVE_TARGET)
    grant["transport"] = "tcp+udp"
    return {
        "forward_id": NATIVE_ID, "service_status": "active", "mode": "single", "client_mode": "single",
        "backend_mode": "ultra", "transport": "tcp+udp", "listen_port": 41000, "target": NATIVE_TARGET,
        "aggregation_enabled": False, "sidecar_required": False,
        "native_outbound": True, "native_direction": "sha_hkg",
        "ultra_access": grant,
    }


def native_capability() -> dict:
    return {
        "enabled": True, "protocol": "socks5", "lease_protocol": NATIVE_LEASE_PROTOCOL, "transport": "tcp+udp",
        "forward_ids": [NATIVE_ID], "default_forward_id": NATIVE_ID,
        "route_scopes": native_scopes(), "default_route_scope": "global",
        "unrestricted_allowed": native_unrestricted(),
        "forwards": [{
            "forward_id": NATIVE_ID, "direction": native_direction(),
            "scope_digest": native_digest(native_direction(), "global", "restricted", 1),
            "transport": "tcp+udp", "route_scopes": native_scopes(), "default_route_scope": "global",
            "unrestricted_allowed": native_unrestricted(),
        }],
    }


def native_lease_refusal(item: dict):
    """Returns the Panel's error code for a bad native request, else None."""
    if state("native", "0") != "1":
        return "native_outbound_not_allowed"
    if int(item.get("forward_id", 0)) != NATIVE_ID:
        return "native_outbound_not_allowed"
    if not str(item.get("session_ticket", "")).strip():
        return "lease_scope_invalid"
    route_policy = str(item.get("route_policy", "restricted") or "restricted").lower()
    if route_policy not in ("restricted", "unrestricted"):
        return "native_route_policy_not_allowed"
    if route_policy == "unrestricted" and not native_unrestricted():
        return "native_route_policy_not_allowed"
    scope = str(item.get("route_scope", "global")).lower()
    if scope not in native_scopes():
        return "policy_scope_invalid"
    digest = str(item.get("target_digest", "")).lower()
    accepted = {native_digest(native_direction(), scope, route_policy, 2)}
    if route_policy == "restricted":
        accepted.add(native_digest(native_direction(), scope, route_policy, 1))
    if digest not in accepted:
        return "policy_scope_invalid"
    return None


def native_lease_frames(item: dict) -> list:
    scope = str(item.get("route_scope", "global")).lower()
    route_policy = str(item.get("route_policy", "restricted") or "restricted").lower()
    version = 1 if route_policy == "restricted" else 2
    ttl = int(state("native_ttl", "240"))
    host = state("relay_host", "127.0.0.1")
    port = int(state("relay_port", "19000"))
    with LOCK:
        global NATIVE_LEASES
        NATIVE_LEASES += 1
        serial = NATIVE_LEASES
    return [{
        "protocol": NATIVE_LEASE_PROTOCOL, "purpose": "native_outbound", "forward_id": NATIVE_ID,
        "lease_id": "native-lease-%d" % serial, "policy_generation": HEX64,
        "expires_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(time.time() + min(ttl, 240))),
        "transport": "tcp+udp", "rate_limit_mbps": 100, "quota_remaining_bytes": 1 << 30,
        "selection_ref": hashlib.sha256(("native-ref-%d" % serial).encode()).hexdigest(),
        "candidate_key_hash": hashlib.sha256(b"native-candidate").hexdigest(),
        "direction": native_direction(), "route_scope": scope, "route_policy": route_policy,
        "scope_digest": native_digest(native_direction(), scope, route_policy, version),
        "endpoint": {"ip": host, "port": port},
        "credential": {"user": "native-user", "password": "native-pass"},
        "direct_ip_cidrs": [host + "/32"],
    }]


NATIVE_LEASES = 0


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):  # keep the harness output readable
        pass

    def _json(self, status: int, payload: dict):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _platform_gate(self) -> bool:
        reject = state("reject_platform")
        if not reject:
            return True
        if self.headers.get("X-Device-Platform") == reject:
            return True
        self._json(422, {"ok": False, "error": "location_platform_invalid"})
        return False

    def _online_limit_gate(self) -> bool:
        raw = state("online_limit")
        if not raw:
            return True
        limit, _, active = raw.partition("/")
        limit = int(limit or 0)
        active = int(active or limit)
        counts = {"online_device_limit": limit, "active_online_devices": active}
        self._json(429, dict({"ok": False, "error": "client_online_limit_reached",
                              "protocol_version": 1, "details": dict(counts)}, **counts))
        return False

    def do_GET(self):
        global PULLS
        if not self._platform_gate() or not self._online_limit_gate():
            return
        if self.path.startswith("/api/client/configs/pull"):
            with LOCK:
                PULLS += 1
            target = state("target", "127.0.0.1:9")
            listen_port = int(state("listen_port", "10001"))
            count = int(state("forward_count", "1"))
            forwards = []
            for index in range(count):
                forward_id = 3 + index
                forwards.append({
                    "forward_id": forward_id,
                    "service_status": "active",
                    "mode": "single",
                    "client_mode": "race",
                    "backend_mode": "ultra",
                    "transport": transport_mode(),
                    "listen_port": listen_port + index,
                    "target": target,
                    "aggregation_enabled": False,
                    "sidecar_required": False,
                    "ultra_access": policy(forward_id, target),
                })
            native_on = state("native", "0") == "1"
            if native_on:
                forwards.append(native_forward())
            self._write_state("pulls", str(PULLS))
            self._json(200, {
                "native_outbound": native_capability() if native_on else None,
                "ok": True,
                "config_revision": "rev-%d" % PULLS,
                "forward_revision": "frev-1",
                "force_apply": False,
                "next_poll_after_ms": 1000,
                "client": {
                    "id": 42,
                    "name": "Integration Router",
                    "display_host": "router.local",
                    "online_device_limit": 5,
                    "active_online_devices": 1,
                    "desired_ultra_runtime_version": "",
                    "desired_ultra_runtime_sha256": "",
                },
                "forwards": forwards,
            })
            return
        self._json(404, {"ok": False, "error": "not_found"})

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b"{}"
        try:
            body = json.loads(raw or b"{}")
        except ValueError:
            body = {}
        if not self._platform_gate() or not self._online_limit_gate():
            return

        if self.path.startswith("/api/client/ultra/lease"):
            self._handle_lease(body)
            return
        if self.path.startswith("/api/client/heartbeat"):
            self._handle_heartbeat(body)
            return
        if self.path.startswith("/api/client/diagnostics/result"):
            self._write_state("diagnostics.json", json.dumps(body)[:200000])
            self._json(200, {"ok": True})
            return
        self._json(404, {"ok": False, "error": "not_found"})

    def _handle_lease(self, body):
        mode = state("lease_mode", "two")
        forwards = body.get("forwards") or []
        forward_id = int(forwards[0].get("forward_id", 3)) if forwards else 3
        target = state("target", "127.0.0.1:9")
        # Candidate 1 is deliberately blackholed on port 1 so the runtime's
        # probe fails fast; candidate 2 is a local listener.
        candidate_targets = [("127.0.0.1", 1), ("127.0.0.1", int(state("relay_port", "19000")))]
        if mode == "none":
            self._json(409, {"ok": False, "error": "no_approved_candidate"})
            return
        if state("lease_sealing") == "require" and not (self.headers.get("X-Leikwan-Lease-Key") or "").strip():
            self._json(409, {"ok": False, "error": "lease_sealing_required"})
            return
        if mode == "empty":
            self._json(200, {"ok": True, "leases": []})
            return
        if forwards and str(forwards[0].get("purpose", "")).lower() == "native_outbound":
            with open(os.path.join(STATE, "native_lease_requests.jsonl"), "a") as handle:
                handle.write(json.dumps(forwards[0]) + "\n")
            refusal = native_lease_refusal(forwards[0])
            if refusal:
                self._write_state("last_native_refusal", refusal)
                self._json(409 if refusal != "native_outbound_not_allowed" else 403,
                           {"ok": False, "error": refusal, "failures": {}})
                return
            frames = native_lease_frames(forwards[0])
        elif mode == "invalid":
            # Five candidates exceeds the runtime's protocol limit of four, so
            # a correct agent must see the batch rejected instead of truncated.
            frames = [lease_frame(forward_id, target, "127.0.0.1", 1, i) for i in range(5)]
        else:
            frames = [lease_frame(forward_id, target, ip, port, i) for i, (ip, port) in enumerate(candidate_targets)]
        self._write_state("last_lease_request.json", json.dumps(body))
        response = {"ok": True, "leases": frames}
        sealing = state("lease_sealing", "optional")
        header = (self.headers.get("X-Leikwan-Lease-Key") or "").strip()
        self._write_state("last_lease_key_header", header)
        if sealing != "off" and header:
            try:
                device_public = b64url_decode(header)
                if len(device_public) != 65 or device_public[0] != 4:
                    raise ValueError("not an uncompressed point")
                response["leases"] = [seal_lease(frame, device_public) for frame in frames]
                response["lease_seal"] = {"v": 1, "kid": hashlib.sha256(device_public).hexdigest()[:32]}
            except ImportError:
                self._write_state("last_lease_sealed", "unavailable %d" % len(frames))
                self._json(200, response)
                return
            except ValueError:
                self._json(400, {"ok": False, "error": "lease_key_invalid"})
                return
            self._write_state("last_lease_sealed", "sealed %d" % len(frames))
        else:
            self._write_state("last_lease_sealed", "clear %d" % len(frames))
        self._json(200, response)

    def _handle_heartbeat(self, body):
        with LOCK:
            HEARTBEATS.append(body)
        self._write_state("last_heartbeat.json", json.dumps(body))
        if body.get("ultra_usage"):
            with open(os.path.join(STATE, "usage.jsonl"), "a") as handle:
                for item in body["ultra_usage"]:
                    handle.write(json.dumps(item) + "\n")
        actions = []
        stop_id = state("action_stop")
        if stop_id:
            actions.append({
                "forward_id": int(stop_id),
                "policy_generation": HEX64,
                "action": "stop",
                "reason": "integration_test",
            })
        acknowledged = bool(body.get("ultra_usage"))
        self._json(200, {"ok": True, "ultra_usage_acknowledged": acknowledged, "ultra_actions": actions})

    def _write_state(self, name, content):
        with open(os.path.join(STATE, name), "w") as handle:
            handle.write(content)


if __name__ == "__main__":
    server = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    with open(os.path.join(STATE, "ready"), "w") as handle:
        handle.write(str(os.getpid()))
    server.serve_forever()
