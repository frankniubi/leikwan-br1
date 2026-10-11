#!/usr/bin/env python3
"""Pull an authenticated pool contract, hot-reload, then attest real UOT2 health.

Network/panel failures retain the last known configuration. They never restart
the data plane. The panel independently expires heartbeats after its TTL.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import socket
import struct
import subprocess
import tempfile
import time
import urllib.request


def atomic_write(path, data):
    path = Path(path)
    fd, temporary = tempfile.mkstemp(prefix=path.name + ".", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def exact(sock, count):
    result = bytearray()
    while len(result) < count:
        part = sock.recv(count - len(result))
        if not part:
            raise EOFError("carrier closed")
        result.extend(part)
    return bytes(result)


def frame(kind, session, payload=b""):
    body = struct.pack("!4sBBH16sQI", b"UOT2", 1, kind, 0, session, 1, len(payload)) + payload
    return struct.pack("!I", len(body)) + body


def receive(sock):
    size = struct.unpack("!I", exact(sock, 4))[0]
    if size < 36 or size > 1048576:
        raise ValueError("invalid frame length")
    body = exact(sock, size)
    magic, version, kind, _, session, _, length = struct.unpack("!4sBBH16sQI", body[:36])
    if magic != b"UOT2" or version != 1 or length != len(body) - 36:
        raise ValueError("invalid frame")
    return kind, session, body[36:]


def validate_config(config):
    if config.get("listen_host") != "0.0.0.0" or not 1 <= config.get("listen_port", 0) <= 65535:
        raise ValueError("invalid listener")
    bindings = config.get("forwards")
    if not isinstance(bindings, dict) or not bindings:
        raise ValueError("empty pool contract")
    for forward, binding in bindings.items():
        if not str(forward).isdigit() or not isinstance(binding, dict) or not binding.get("token"):
            raise ValueError("invalid forward binding")


def probe(config):
    """Check every binding admission and one actual DNS UDP round trip."""
    checked_dns = False
    for forward, binding in config["forwards"].items():
        session = os.urandom(16)
        with socket.create_connection(("127.0.0.1", config["listen_port"]), 2) as sock:
            sock.settimeout(2)
            hello = {"token": binding["token"], "forward_id": int(forward),
                     "target": "1.1.1.1:53", "frame_payload_max": 1200}
            sock.sendall(frame(1, session, json.dumps(hello).encode()))
            kind, received_session, body = receive(sock)
            if kind != 2 or received_session != session or json.loads(body).get("ok") is not True:
                raise ValueError("binding admission failed")
            if not checked_dns:
                query_id = os.urandom(2)
                query = query_id + struct.pack("!HHHHH", 0x100, 1, 0, 0, 0)
                query += b"\x03one\x03one\x03one\x00" + struct.pack("!HH", 1, 1)
                sock.sendall(frame(3, session, query))
                kind, received_session, answer = receive(sock)
                if kind != 4 or received_session != session or len(answer) < 12 or answer[:2] != query_id or not answer[2] & 0x80 or answer[3] & 15:
                    raise ValueError("UDP DNS health failed")
                checked_dns = True
            sock.sendall(frame(5, session))


def request(settings, action, payload=None):
    url = settings["panel"].rstrip("/") + "/api/external-uot/pool/" + settings["id"] + "/" + action
    data = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(url, data=data, headers={
        "Authorization": "Bearer " + settings["secret"], "Content-Type": "application/json",
        "User-Agent": "Leikwan-External-UoT/1.0"})
    with urllib.request.urlopen(req, timeout=8) as response:
        return json.load(response)


def synchronize(settings):
    response = request(settings, "config")
    config, digest = response["config"], response["config_hash"]
    validate_config(config)
    encoded = json.dumps(config, separators=(",", ":"), ensure_ascii=False).encode()
    if hashlib.sha256(encoded).hexdigest() != digest:
        raise ValueError("contract digest mismatch")
    destination = Path(settings.get("config_path", "/etc/leikwan-external-uot.json"))
    old = destination.read_bytes() if destination.exists() else None
    if old is None or json.loads(old) != config:
        if old is not None:
            atomic_write(str(destination) + ".last-good", old)
        atomic_write(destination, encoded)
        subprocess.run(["systemctl", "kill", "--kill-whom=main", "--signal=HUP",
                        "leikwan-external-uot.service"], check=True, timeout=5)
        time.sleep(0.2)
    healthy = False
    try:
        probe(config)
        healthy = True
    finally:
        request(settings, "heartbeat", {"healthy": healthy, "config_hash": digest})
    return digest, len(config["forwards"])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--settings", default="/etc/leikwan-uot-pool-sync.json")
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    settings = json.loads(Path(args.settings).read_text())
    failures = 0
    previous = None
    while True:
        started = time.monotonic()
        try:
            result = synchronize(settings)
            if failures or result != previous:
                print("pool healthy: bindings=%d config=%s" % (result[1], result[0]), flush=True)
            failures, previous = 0, result
        except Exception as error:
            failures += 1
            # Never log bearer tokens, response bodies or untrusted URL content.
            if failures == 1 or failures % 6 == 0:
                print("pool sync failed: %s consecutive=%d" % (type(error).__name__, failures), flush=True)
            if args.once:
                raise SystemExit(1)
        if args.once:
            return
        time.sleep(max(1, 10 - (time.monotonic() - started)))


if __name__ == "__main__":
    main()
