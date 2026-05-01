#!/usr/bin/env python3
from __future__ import annotations

import argparse
import contextlib
import select
import socket
import threading
import time


def parse_addr(value: str) -> tuple[str, int]:
    host, port = value.rsplit(":", 1)
    host = host.strip()
    if host.startswith("[") and host.endswith("]"):
        host = host[1:-1]
    return host, int(port)


class TokenBucket:
    def __init__(self, rate_bytes_per_sec: int) -> None:
        self.rate_bytes_per_sec = max(1, rate_bytes_per_sec)
        self.capacity = max(self.rate_bytes_per_sec, 65536)
        self.tokens = float(self.capacity)
        self.updated_at = time.monotonic()
        self.lock = threading.Lock()

    def consume(self, amount: int) -> None:
        if amount <= 0:
            return

        while True:
            with self.lock:
                now = time.monotonic()
                elapsed = now - self.updated_at
                if elapsed > 0:
                    self.tokens = min(self.capacity, self.tokens + elapsed * self.rate_bytes_per_sec)
                    self.updated_at = now
                if self.tokens >= amount:
                    self.tokens -= amount
                    return
                missing = amount - self.tokens
                wait_seconds = missing / self.rate_bytes_per_sec
            time.sleep(max(wait_seconds, 0.001))


def tcp_pipe(reader: socket.socket, writer: socket.socket, bucket: TokenBucket) -> None:
    try:
        while True:
            data = reader.recv(65536)
            if not data:
                break
            bucket.consume(len(data))
            writer.sendall(data)
    except Exception:
        pass
    finally:
        for sock in (reader, writer):
            with contextlib.suppress(Exception):
                sock.shutdown(socket.SHUT_RDWR)
            with contextlib.suppress(Exception):
                sock.close()


def run_tcp_mode(listen_addr: str, target_addr: str, mbps: float) -> None:
    listen_host, listen_port = parse_addr(listen_addr)
    target_host, target_port = parse_addr(target_addr)
    rate_bytes_per_sec = int(max(mbps, 0.1) * 1024 * 1024 / 8)
    ingress_bucket = TokenBucket(rate_bytes_per_sec)
    egress_bucket = TokenBucket(rate_bytes_per_sec)

    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind((listen_host, listen_port))
    server.listen(256)

    while True:
        client, _ = server.accept()
        try:
            upstream = socket.create_connection((target_host, target_port), timeout=3.0)
            upstream.settimeout(None)
            threading.Thread(target=tcp_pipe, args=(client, upstream, ingress_bucket), daemon=True).start()
            threading.Thread(target=tcp_pipe, args=(upstream, client, egress_bucket), daemon=True).start()
        except Exception:
            with contextlib.suppress(Exception):
                client.close()


def run_udp_mode(listen_addr: str, target_addr: str, mbps: float) -> None:
    listen_host, listen_port = parse_addr(listen_addr)
    target_host, target_port = parse_addr(target_addr)
    rate_bytes_per_sec = int(max(mbps, 0.1) * 1024 * 1024 / 8)
    ingress_bucket = TokenBucket(rate_bytes_per_sec)
    egress_bucket = TokenBucket(rate_bytes_per_sec)

    incoming = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    incoming.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    incoming.bind((listen_host, listen_port))
    incoming.setblocking(False)

    upstream = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    upstream.setblocking(False)

    last_client: tuple[str, int] | None = None
    last_client_at = 0.0

    while True:
        ready, _, _ = select.select([incoming, upstream], [], [], 1.0)
        now = time.time()
        for sock in ready:
            if sock is incoming:
                data, client = incoming.recvfrom(65535)
                ingress_bucket.consume(len(data))
                last_client = client
                last_client_at = now
                with contextlib.suppress(Exception):
                    upstream.sendto(data, (target_host, target_port))
            else:
                data, _ = upstream.recvfrom(65535)
                if last_client and (now - last_client_at) < 60:
                    egress_bucket.consume(len(data))
                    with contextlib.suppress(Exception):
                        incoming.sendto(data, last_client)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["tcp", "udp"])
    parser.add_argument("--listen", required=True)
    parser.add_argument("--target", required=True)
    parser.add_argument("--mbps", type=float, required=True)
    args = parser.parse_args()

    if args.mode == "tcp":
        run_tcp_mode(args.listen, args.target, args.mbps)
    else:
        run_udp_mode(args.listen, args.target, args.mbps)


if __name__ == "__main__":
    main()
