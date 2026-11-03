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


class UdpClientPolicy:
    """Pick which client address(es) get replies from the shared upstream socket.

    Replies carry no client identity, so a bare "last sender wins" lets a single
    stray datagram from a new source port (a latency probe, a second app socket)
    steal the return path until the real client sends again. A new port is held
    as a secondary instead: for a short window replies go to both ports, and it
    only becomes primary after sustained traffic or once the primary goes quiet
    (a genuine roam).
    """

    CLIENT_IDLE_SECONDS = 60.0
    SECONDARY_WINDOW_SECONDS = 1.0
    PROMOTE_PACKETS = 3
    PRIMARY_SILENT_SECONDS = 2.0

    def __init__(self) -> None:
        self.primary: tuple[str, int] | None = None
        self.primary_at = 0.0
        self.secondary: tuple[str, int] | None = None
        self.secondary_at = 0.0
        self.secondary_packets = 0

    def client_packet(self, client: tuple[str, int], now: float) -> None:
        if self.primary is None or client == self.primary:
            self.primary = client
            self.primary_at = now
            return
        if client == self.secondary:
            self.secondary_packets += 1
        else:
            self.secondary = client
            self.secondary_packets = 1
        self.secondary_at = now
        if self.secondary_packets >= self.PROMOTE_PACKETS or now - self.primary_at > self.PRIMARY_SILENT_SECONDS:
            self.primary = client
            self.primary_at = now
            self.secondary = None
            self.secondary_packets = 0

    def reply_targets(self, now: float) -> list[tuple[str, int]]:
        targets: list[tuple[str, int]] = []
        if self.primary is not None and now - self.primary_at < self.CLIENT_IDLE_SECONDS:
            targets.append(self.primary)
        if self.secondary is not None and now - self.secondary_at < self.SECONDARY_WINDOW_SECONDS:
            targets.append(self.secondary)
        return targets


def serve_udp(
    incoming: socket.socket,
    upstream: socket.socket,
    target: tuple[str, int],
    mbps: float,
    stop: threading.Event | None = None,
) -> None:
    rate_bytes_per_sec = int(max(mbps, 0.1) * 1024 * 1024 / 8)
    ingress_bucket = TokenBucket(rate_bytes_per_sec)
    egress_bucket = TokenBucket(rate_bytes_per_sec)
    policy = UdpClientPolicy()

    while stop is None or not stop.is_set():
        ready, _, _ = select.select([incoming, upstream], [], [], 1.0)
        for sock in ready:
            now = time.monotonic()
            if sock is incoming:
                data, client = incoming.recvfrom(65535)
                ingress_bucket.consume(len(data))
                policy.client_packet(client, now)
                with contextlib.suppress(Exception):
                    upstream.sendto(data, target)
            else:
                data, _ = upstream.recvfrom(65535)
                targets = policy.reply_targets(now)
                if targets:
                    egress_bucket.consume(len(data))
                    for client in targets:
                        with contextlib.suppress(Exception):
                            incoming.sendto(data, client)


def run_udp_mode(listen_addr: str, target_addr: str, mbps: float) -> None:
    listen_host, listen_port = parse_addr(listen_addr)
    target_host, target_port = parse_addr(target_addr)

    incoming = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    incoming.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    incoming.bind((listen_host, listen_port))
    incoming.setblocking(False)

    upstream = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    upstream.setblocking(False)

    serve_udp(incoming, upstream, (target_host, target_port), mbps)


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
