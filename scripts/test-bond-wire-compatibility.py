#!/usr/bin/env python3
from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import os
import signal
import socket
import socketserver
import subprocess
import threading
import time
from pathlib import Path


class EchoHandler(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        while data := self.request.recv(256 * 1024):
            self.request.sendall(data)


class EchoServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


def free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def b64url(value: bytes) -> str:
    return base64.urlsafe_b64encode(value).decode("ascii").rstrip("=")


def session_token(secret: str, target_port: int) -> str:
    now = int(time.time())
    payload = json.dumps(
        {
            "target_host": "127.0.0.1",
            "target_port": target_port,
            "target_protocol": "tcp",
            "service_id": 1,
            "forward_id": 1,
            "issued_at": now,
            "expires_at": now + 300,
        },
        separators=(",", ":"),
    ).encode("utf-8")
    signature = hmac.new(secret.encode("utf-8"), payload, hashlib.sha256).digest()
    return f"{b64url(payload)}.{b64url(signature)}"


def stop_process(process: subprocess.Popen[bytes]) -> None:
    if process.poll() is not None:
        return
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        return
    try:
        process.wait(timeout=3)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait(timeout=3)


def connect_when_ready(port: int, processes: list[subprocess.Popen[bytes]]) -> socket.socket:
    deadline = time.monotonic() + 10
    last_error: OSError | None = None
    while time.monotonic() < deadline:
        for process in processes:
            if process.poll() is not None:
                stderr = process.stderr.read().decode("utf-8", "replace") if process.stderr else ""
                raise RuntimeError(f"bond process exited with {process.returncode}: {stderr}")
        try:
            return socket.create_connection(("127.0.0.1", port), timeout=1)
        except OSError as exc:
            last_error = exc
            time.sleep(0.05)
    raise RuntimeError(f"timed out waiting for bond client on {port}: {last_error}")


def run_case(
    label: str,
    client_binary: Path,
    server_binary: Path,
    echo_port: int,
    enable_server_warmup: bool,
) -> None:
    secret = "wire-compat-secret"
    server_port = free_port()
    client_port = free_port()
    server_command = [
        str(server_binary),
        "server",
        "--listen",
        f"127.0.0.1:{server_port}",
        "--token-secret",
        secret,
    ]
    if enable_server_warmup:
        server_command.extend(["--startup-warmup-bytes-per-leg", "1048576"])
    server = subprocess.Popen(
        server_command,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.PIPE,
        start_new_session=True,
    )
    client = subprocess.Popen(
        [
            str(client_binary),
            "client",
            "--protocol",
            "tcp",
            "--listen",
            f"127.0.0.1:{client_port}",
            "--legs",
            f"tcp://127.0.0.1:{server_port}?priority=1",
            "--session-token",
            session_token(secret, echo_port),
            "--preferred-initial-legs",
            "1",
            "--minimum-initial-legs",
            "1",
            "--initial-leg-wait-ms",
            "2000",
        ],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.PIPE,
        start_new_session=True,
    )
    try:
        stream = connect_when_ready(client_port, [server, client])
        stream.settimeout(20)
        payload = bytes(range(256)) * 8192
        stream.sendall(payload)
        received = bytearray()
        while len(received) < len(payload):
            chunk = stream.recv(min(256 * 1024, len(payload) - len(received)))
            if not chunk:
                break
            received.extend(chunk)
        stream.close()
        if received != payload:
            raise RuntimeError(f"{label}: echoed {len(received)} of {len(payload)} bytes")
        print(f"PASS {label}: {len(payload)} bytes echoed")
    finally:
        stop_process(client)
        stop_process(server)


def main() -> int:
    parser = argparse.ArgumentParser(description="Verify leikwan-bond rolling wire compatibility")
    parser.add_argument("--old-binary", type=Path, required=True)
    parser.add_argument("--new-binary", type=Path, required=True)
    args = parser.parse_args()
    for path in (args.old_binary, args.new_binary):
        if not path.is_file() or not os.access(path, os.X_OK):
            parser.error(f"binary is missing or not executable: {path}")

    with EchoServer(("127.0.0.1", 0), EchoHandler) as echo:
        thread = threading.Thread(target=echo.serve_forever, daemon=True)
        thread.start()
        echo_port = int(echo.server_address[1])
        run_case("old-client_to_new-warmup-server", args.old_binary, args.new_binary, echo_port, True)
        run_case("new-client_to_old-server", args.new_binary, args.old_binary, echo_port, False)
        run_case("new-client_to_new-warmup-server", args.new_binary, args.new_binary, echo_port, True)
        echo.shutdown()
        thread.join(timeout=3)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
