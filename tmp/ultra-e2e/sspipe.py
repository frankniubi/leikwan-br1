#!/usr/bin/env python3
"""Local TCP port whose every connection is piped through the SS-none tunnel.

ipef3 (or anything else) can then measure the real throughput of the forwarded
path instead of the loopback hop.
"""
from __future__ import annotations

import argparse
import socket
import struct
import threading


def encode_address(host: str, port: int) -> bytes:
    return b"\x01" + socket.inet_aton(host) + struct.pack("!H", port)


def pipe(source: socket.socket, sink: socket.socket) -> None:
    try:
        while True:
            data = source.recv(262144)
            if not data:
                break
            sink.sendall(data)
    except OSError:
        pass
    finally:
        try:
            sink.shutdown(socket.SHUT_WR)
        except OSError:
            pass


def handle(client: socket.socket, local, target) -> None:
    try:
        client.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        remote = socket.create_connection(local, timeout=5.0)
        remote.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        remote.sendall(encode_address(*target))
        up = threading.Thread(target=pipe, args=(client, remote), daemon=True)
        down = threading.Thread(target=pipe, args=(remote, client), daemon=True)
        up.start()
        down.start()
        up.join()
        down.join()
        remote.close()
    except OSError:
        pass
    finally:
        try:
            client.close()
        except OSError:
            pass


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--listen-port", type=int, default=15201)
    ap.add_argument("--local-host", default="127.0.0.1")
    ap.add_argument("--local-port", type=int, default=10001)
    ap.add_argument("--target-host", default="127.0.0.1")
    ap.add_argument("--target-port", type=int, default=5201)
    args = ap.parse_args()

    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(("127.0.0.1", args.listen_port))
    server.listen(64)
    print("sspipe listening 127.0.0.1:%d -> tunnel %s:%d -> %s:%d" % (
        args.listen_port, args.local_host, args.local_port, args.target_host, args.target_port), flush=True)
    while True:
        client, _ = server.accept()
        threading.Thread(target=handle, args=(client, (args.local_host, args.local_port),
                                              (args.target_host, args.target_port)), daemon=True).start()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
