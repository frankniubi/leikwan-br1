#!/usr/bin/env python3
"""Experimental local UDP companion for the Japanese Ultra TCP pilot.

One TCP carrier per local UDP peer; bounded queues, no user-protocol parsing.
Reconnect retains the local listener but does NOT promise a stable remote UDP
source port yet. This helper is intentionally outside production packaging.
"""
import argparse
from collections import Counter
import json
from pathlib import Path
import queue
import socket
import struct
import threading
import time

from japan_canary import (MAX_DATAGRAM, SESSION_ID_BYTES, open_carrier,
                          read_frame, write_frame)


class Companion:
    def __init__(self, args):
        self.args = args
        self.token = Path(args.token_file).read_bytes().strip()
        if len(self.token) < 32:
            raise ValueError('private token required')
        self.udp = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.udp.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 << 20)
        self.udp.setsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF, 4 << 20)
        self.udp.bind(('127.0.0.1', args.listen_udp))
        self.lock = threading.Lock()
        self.sessions = {}
        self.stats = Counter()

    def count(self, key, amount=1):
        with self.lock:
            self.stats[key] += amount

    def session(self, peer, pending):
        last_packet = time.monotonic()
        session_id = struct.pack('!QQ', hash(peer) & ((1 << 64) - 1),
                                 int(time.time_ns()) & ((1 << 64) - 1))
        try:
            while time.monotonic()-last_packet < 30:
                conn, reader, stop = None, None, threading.Event()
                try:
                    conn, reattached = open_carrier(self.args.forward_port,
                                                    self.args.port, self.token,
                                                    session_id)
                    self.count('reattaches' if reattached else 'new_sessions')
                    def replies(stream=conn, stopped=stop):
                        try:
                            while not stopped.is_set():
                                data = read_frame(stream)
                                self.udp.sendto(data, peer)
                                self.count('datagrams_down')
                        except (EOFError, OSError, ValueError):
                            pass
                        finally:
                            stopped.set()
                    reader = threading.Thread(target=replies, daemon=True)
                    reader.start()
                    while not stop.is_set() and time.monotonic()-last_packet < 30:
                        try:
                            at, data = pending.get(timeout=.25)
                        except queue.Empty:
                            continue
                        last_packet = time.monotonic()
                        if last_packet-at > 1:
                            self.count('expired_drops')
                            continue
                        try:
                            write_frame(conn, data)
                            self.count('datagrams_up')
                        except (OSError, ValueError):
                            self.count('uncertain_send_drops')
                            raise
                except (OSError, EOFError, ValueError):
                    self.count('carrier_errors')
                finally:
                    stop.set()
                    if conn:
                        try:
                            conn.shutdown(socket.SHUT_RDWR)
                        except OSError:
                            pass
                        conn.close()
                    if reader:
                        reader.join(2)
                if time.monotonic()-last_packet < 30:
                    time.sleep(.25)
        finally:
            with self.lock:
                if self.sessions.get(peer) is pending:
                    self.stats['close_queue_drops'] += pending.qsize()
                    del self.sessions[peer]

    def publish(self):
        while True:
            with self.lock:
                snapshot = dict(self.stats, sessions=len(self.sessions), at=time.time())
            path = Path(self.args.stats_file)
            tmp = path.with_suffix('.tmp')
            tmp.write_text(json.dumps(snapshot))
            tmp.replace(path)
            time.sleep(1)

    def run(self):
        threading.Thread(target=self.publish, daemon=True).start()
        print(json.dumps({'event': 'listening', 'udp': self.args.listen_udp}), flush=True)
        while True:
            data, peer = self.udp.recvfrom(65535)
            if len(data) > MAX_DATAGRAM:
                self.count('oversize_drops')
                continue
            with self.lock:
                pending = self.sessions.get(peer)
                if pending is None:
                    if len(self.sessions) >= 16:
                        self.stats['session_limit_drops'] += 1
                        continue
                    pending = queue.Queue(maxsize=256)
                    self.sessions[peer] = pending
                    threading.Thread(target=self.session,args=(peer,pending),daemon=True).start()
                try:
                    pending.put_nowait((time.monotonic(),data))
                    self.stats['datagrams_in'] += 1
                except queue.Full:
                    self.stats['queue_drops'] += 1


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--token-file', required=True)
    p.add_argument('--listen-udp', type=int, default=30170)
    p.add_argument('--forward-port', type=int, default=10001)
    p.add_argument('--port', type=int, default=18743)
    p.add_argument('--stats-file', required=True)
    Companion(p.parse_args()).run()
