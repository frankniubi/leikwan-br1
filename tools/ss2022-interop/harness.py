#!/usr/bin/env python3
"""SS2022 key-normalization interop harness.

Starts the server config chosen by --server (sing-box with an over-long key, or Xray with a
raw 32-char ASCII key), rewrites the app-built client config's inbound into a loopback mixed
inbound (route/outbounds untouched -- the shadowsocks outbound must be exactly as the app
generates it, i.e. the normalizer's output), then dials a TCP connection through it to a local
marker and asserts the marker body comes back.

Stdlib only. Exit 0 = the app config connected to the server; 1 = anything else.
"""

from __future__ import annotations

import argparse
import copy
import json
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path

MARKER_HOST = "127.0.0.1"
MARKER_PORT = 18444
INBOUND_HOST = "127.0.0.1"
INBOUND_PORT = 18390
SERVER_PORTS = {"singbox": 18388, "xray": 18389}
CASE_TIMEOUT = 15.0


class HarnessError(Exception):
    pass


def log(msg: str) -> None:
    print(f"[ss2022-interop] {msg}", flush=True)


class MarkerHandler(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        try:
            data = self.request.recv(65536)
        except OSError:
            return
        body = f"MARKER-SS2022-OK len={len(data)}\n"
        try:
            self.request.sendall(f"HTTP/1.1 200 OK\r\nContent-Length: {len(body)}\r\nConnection: close\r\n\r\n{body}".encode())
        except OSError:
            pass


class ThreadingTCP(socketserver.ThreadingMixIn, socketserver.TCPServer):
    allow_reuse_address = True


def rewrite_client_config(config: dict) -> tuple[dict, list[str]]:
    """TUN/other inbound -> loopback mixed with the same tag; nothing else changes."""
    out = copy.deepcopy(config)
    notes: list[str] = []
    inbounds = out.get("inbounds") or []
    if not inbounds:
        raise HarnessError("client config has no inbounds")
    first = inbounds[0]
    tag = first.get("tag", "tun-in")
    inbounds[0] = {
        "type": "mixed",
        "tag": tag,
        "listen": INBOUND_HOST,
        "listen_port": INBOUND_PORT,
    }
    notes.append(f"inbound {tag}: {first.get('type')} -> mixed {INBOUND_HOST}:{INBOUND_PORT}")
    return out, notes


def wait_for_port(host: str, port: int, proc: subprocess.Popen, deadline_s: float) -> None:
    deadline = time.monotonic() + deadline_s
    while time.monotonic() < deadline:
        if proc.poll() is not None:
            raise HarnessError(f"server exited early (status {proc.returncode})")
        try:
            with socket.create_connection((host, port), timeout=0.5):
                return
        except OSError:
            time.sleep(0.2)
    raise HarnessError(f"server port {host}:{port} not accepting after {deadline_s}s")


def dial_through_inbound(server_port: int) -> str:
    """SOCKS5 CONNECT to the marker through the client inbound; returns the marker body."""
    deadline = time.monotonic() + CASE_TIMEOUT

    def remaining() -> float:
        return max(0.5, deadline - time.monotonic())

    conn = socket.create_connection((INBOUND_HOST, INBOUND_PORT), timeout=remaining())
    try:
        conn.settimeout(remaining())
        conn.sendall(b"\x05\x01\x00")
        reply = conn.recv(2)
        if reply != b"\x05\x00":
            raise HarnessError(f"inbound refused no-auth SOCKS5: {reply!r}")
        host, port = MARKER_HOST, MARKER_PORT
        req = b"\x05\x01\x00\x01" + socket.inet_aton(host) + port.to_bytes(2, "big")
        conn.sendall(req)
        ver, rep, _rsv, atyp = conn.recv(4)
        if rep != 0:
            raise HarnessError(f"SOCKS5 CONNECT failed with reply code {rep}")
        conn.recv({1: 4, 4: 16}.get(atyp, 0) or conn.recv(1)[0])
        conn.recv(2)
        conn.sendall(b"GET /interop HTTP/1.1\r\nHost: marker\r\nConnection: close\r\n\r\n")
        data = b""
        while True:
            try:
                chunk = conn.recv(4096)
            except socket.timeout:
                break
            if not chunk:
                break
            data += chunk
        return data.decode("utf-8", "replace")
    finally:
        conn.close()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n", 1)[0])
    parser.add_argument("--server", required=True, choices=sorted(SERVER_PORTS))
    parser.add_argument("--config", required=True, type=Path, help="app-built client config JSON")
    parser.add_argument("--out", required=True, type=Path, help="output directory")
    parser.add_argument("--singbox", type=Path, help="sing-box binary (required with --server singbox)")
    parser.add_argument("--xray", type=Path, help="xray binary (required with --server xray)")
    args = parser.parse_args()

    out = args.out.resolve()
    out.mkdir(parents=True, exist_ok=True)

    if args.server == "singbox":
        if not args.singbox:
            raise HarnessError("--singbox is required with --server singbox")
        binary, server_config = args.singbox.resolve(), Path(__file__).parent / "server-singbox-overlong.json"
        cmd = [str(binary), "run", "-c", str(server_config)]
    else:
        if not args.xray:
            raise HarnessError("--xray is required with --server xray")
        binary, server_config = args.xray.resolve(), Path(__file__).parent / "server-xray-rawkey.json"
        cmd = [str(binary), "run", "-c", str(server_config)]

    config = json.loads(args.config.read_text(encoding="utf-8"))
    rewritten, notes = rewrite_client_config(config)
    client_path = out / "client.rewritten.json"
    client_path.write_text(json.dumps(rewritten, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    for note in notes:
        log(note)

    ss_outbounds = [o for o in rewritten.get("outbounds") or [] if o.get("type") == "shadowsocks"]
    if not ss_outbounds:
        raise HarnessError("client config has no shadowsocks outbound")
    log(f"client shadowsocks password (normalized): {ss_outbounds[0].get('password')}")
    log(f"client shadowsocks server: {ss_outbounds[0].get('server')}:{ss_outbounds[0].get('server_port')}")

    workdir = Path(tempfile.mkdtemp(prefix="ss2022-server-", dir=str(out)))
    log_file = open(out / "server.log", "w", encoding="utf-8")
    server_proc = subprocess.Popen(cmd, stdout=log_file, stderr=subprocess.STDOUT, cwd=str(workdir))
    client_proc = None
    client_log = open(out / "client.log", "w", encoding="utf-8")
    marker = None
    try:
        wait_for_port("127.0.0.1", SERVER_PORTS[args.server], server_proc, 20)
        log(f"server running ({args.server}, pid {server_proc.pid})")

        marker = ThreadingTCP((MARKER_HOST, MARKER_PORT), MarkerHandler)
        threading.Thread(target=marker.serve_forever, daemon=True).start()

        # Point the client's shadowsocks outbound at the local server port if it isn't already.
        # The app builds the outbound against the node's real server:port; for CI we rewrite ONLY
        # server/server_port (never the password/method) so the normalized key is exercised
        # against the local server.
        adjusted = copy.deepcopy(rewritten)
        for o in adjusted.get("outbounds") or []:
            if o.get("type") == "shadowsocks":
                o["server"] = "127.0.0.1"
                o["server_port"] = SERVER_PORTS[args.server]
        adjusted_path = out / "client.adjusted.json"
        adjusted_path.write_text(json.dumps(adjusted, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

        # Run the client. The app always runs the sing-box core, so the client side always
        # runs the app-built config through sing-box regardless of the server flavour.
        if not args.singbox:
            raise HarnessError("--singbox is required to run the client config")
        client_cmd = [str(args.singbox.resolve()), "run", "-c", str(adjusted_path), "-D", str(workdir)]
        client_proc = subprocess.Popen(client_cmd, stdout=client_log, stderr=subprocess.STDOUT, cwd=str(workdir))
        wait_for_port(INBOUND_HOST, INBOUND_PORT, client_proc, 20)
        log(f"client running (pid {client_proc.pid}), inbound {INBOUND_HOST}:{INBOUND_PORT}")

        body = dial_through_inbound(SERVER_PORTS[args.server])
        log(f"marker body: {body!r}")
        ok = "MARKER-SS2022-OK" in body
        summary = {
            "server": args.server,
            "config": str(args.config),
            "normalized_password": ss_outbounds[0].get("password"),
            "marker_body": body,
            "passed": ok,
        }
        (out / "results.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        step = __import__("os").environ.get("GITHUB_STEP_SUMMARY")
        if step:
            with open(step, "a", encoding="utf-8") as handle:
                handle.write(f"### SS2022 interop ({args.server}): {'PASS' if ok else 'FAIL'}\n\n")
                handle.write(f"- normalized password: `{ss_outbounds[0].get('password')}`\n")
                handle.write(f"- marker: `{body.strip() or 'no bytes'}`\n")
        log(f"{'PASS' if ok else 'FAIL'}")
        return 0 if ok else 1
    except HarnessError as exc:
        log(f"ERROR: {exc}")
        return 1
    finally:
        for proc in (client_proc, server_proc):
            if proc is not None and proc.poll() is None:
                proc.terminate()
                try:
                    proc.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    proc.wait()
        if marker is not None:
            marker.shutdown()
            marker.server_close()
        log_file.close()
        client_log.close()


if __name__ == "__main__":
    try:
        sys.exit(main())
    except HarnessError as exc:
        log(f"ERROR: {exc}")
        sys.exit(1)
