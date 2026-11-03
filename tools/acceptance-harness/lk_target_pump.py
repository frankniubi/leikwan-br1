#!/usr/bin/env python3
"""Private acceptance target: UDP rate-pump sink + TCP throughput responder.

Statistics are queryable on demand so a client-side loss number can be
attributed to a path segment instead of being taken on faith:

  * UDP: send the exact 6-byte datagram b"LKSTAT" to the UDP port; the reply is
    a JSON snapshot of the counters. Real payloads all begin with b"LKT1", so
    the query cannot collide with test traffic.
  * TCP: connect to the TCP port and write b"LKSTAT"; the JSON snapshot is
    written back and the connection is closed.

The periodic 20s print stays, but it is only emitted when a packet arrives, so
it is useless as a measurement window boundary. The query is not.
"""
import json
import socket
import socketserver
import sys
import threading
import time

UDP_PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 62101
TCP_PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 62102
MAGIC = b"LKT1"
STAT_QUERY = b"LKSTAT"
IDLE_TIMEOUT_SECONDS = 60
stats = {"udp_rx": 0, "udp_tx": 0, "udp_bytes": 0, "tcp_conns": 0, "tcp_bytes": 0,
         "stat_queries": 0, "started": time.time()}


def snapshot():
    snap = dict(stats)
    snap["now"] = time.time()
    return json.dumps(snap).encode()


def udp_pump():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    for opt, val in ((socket.SO_RCVBUF, 16 << 20), (socket.SO_SNDBUF, 16 << 20)):
        try:
            s.setsockopt(socket.SOL_SOCKET, opt, val)
        except OSError:
            pass
    s.bind(("0.0.0.0", UDP_PORT))
    print("udp pump listening", UDP_PORT, flush=True)
    last = time.time()
    while True:
        try:
            data, addr = s.recvfrom(65535)
        except OSError:
            continue
        if data == STAT_QUERY:
            stats["stat_queries"] += 1
            try:
                s.sendto(snapshot(), addr)
            except OSError:
                pass
            continue
        stats["udp_rx"] += 1
        stats["udp_bytes"] += len(data)
        if data.startswith(MAGIC):
            try:
                s.sendto(MAGIC + b"ACK", addr)
                stats["udp_tx"] += 1
            except OSError:
                pass
        if time.time() - last > 20:
            last = time.time()
            print("stats", stats, flush=True)


class TcpHandler(socketserver.BaseRequestHandler):
    def handle(self):
        stats["tcp_conns"] += 1
        # A peer that vanished without a FIN (a relay that dropped the stream,
        # a client that went away) must not pin a thread forever: 128 such
        # threads wedged the Frankfurt pump and falsified a line sweep.
        self.request.settimeout(IDLE_TIMEOUT_SECONDS)
        first = True
        try:
            while True:
                chunk = self.request.recv(1 << 16)
                if not chunk:
                    break
                stats["tcp_bytes"] += len(chunk)
                if first and chunk.startswith(STAT_QUERY):
                    stats["stat_queries"] += 1
                    self.request.sendall(snapshot())
                    break
                first = False
                if chunk.startswith(MAGIC):
                    self.request.sendall(MAGIC + b"ACK")
        except OSError:
            pass


class TcpServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True
    # Default request_queue_size is 5. At the acceptance connection rate the
    # accept queue overflowed and the kernel dropped connections before accept,
    # which the client sees as an empty reply. Counted as TcpExtListenDrops.
    request_queue_size = 1024


def main():
    threading.Thread(target=udp_pump, daemon=True).start()
    srv = TcpServer(("0.0.0.0", TCP_PORT), TcpHandler)
    print("tcp responder listening", TCP_PORT, flush=True)
    srv.serve_forever()


if __name__ == "__main__":
    main()
