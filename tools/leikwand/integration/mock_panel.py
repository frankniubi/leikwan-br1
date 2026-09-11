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

    def do_GET(self):
        global PULLS
        if not self._platform_gate():
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
            self._json(200, {
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
        if not self._platform_gate():
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
        if mode == "empty":
            self._json(200, {"ok": True, "leases": []})
            return
        if mode == "invalid":
            # Five candidates exceeds the runtime's protocol limit of four, so
            # a correct agent must see the batch rejected instead of truncated.
            frames = [lease_frame(forward_id, target, "127.0.0.1", 1, i) for i in range(5)]
        else:
            frames = [lease_frame(forward_id, target, ip, port, i) for i, (ip, port) in enumerate(candidate_targets)]
        self._write_state("last_lease_request.json", json.dumps(body))
        self._json(200, {"ok": True, "leases": frames})

    def _handle_heartbeat(self, body):
        with LOCK:
            HEARTBEATS.append(body)
        self._write_state("last_heartbeat.json", json.dumps(body))
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
