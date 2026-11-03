#!/usr/bin/env python3
"""Opt-in lab only: fixed-target framed UDP over the existing Ultra TCP forward.

Server binds loopback, authenticates a private token, and can send only to the
configured UDP echo target. No provider credentials or production config edits.
Client reaches it using the existing Japanese SS-none test endpoint after Ultra.
This is a transport feasibility test, not a production UoT protocol.
"""
import argparse
import concurrent.futures
import hashlib
import hmac
import json
import os
from pathlib import Path
import socket
import struct
import threading
import time

MAX_DATAGRAM = 65507
SESSION_ID_BYTES = 16
SESSION_GRACE_SECONDS = 30


def receive(sock, size):
    data = bytearray()
    while len(data) < size:
        part = sock.recv(size - len(data))
        if not part:
            raise EOFError('stream closed')
        data.extend(part)
    return bytes(data)


def read_frame(sock):
    length = struct.unpack('!H', receive(sock, 2))[0]
    if length > MAX_DATAGRAM:
        raise ValueError('frame too large')
    return receive(sock, length)


def write_frame(sock, payload):
    if len(payload) > MAX_DATAGRAM:
        raise ValueError('frame too large')
    sock.sendall(struct.pack('!H', len(payload)) + payload)


class UoTSession:
    # One UDP socket shared across carrier reconnects of one client session.

    def __init__(self, session_id, target):
        self.id = session_id
        self.udp = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.udp.connect(target)
        self.udp.settimeout(.5)
        self.lock = threading.Lock()
        self.conn = None
        self.last_seen = time.monotonic()
        self.closed = False
        self.datagrams_down = 0
        self.pump = threading.Thread(target=self._pump, daemon=True)
        self.pump.start()

    def attach(self, conn):
        with self.lock:
            self.conn = conn
            self.last_seen = time.monotonic()

    def detach(self, conn):
        with self.lock:
            if self.conn is conn:
                self.conn = None

    def touch(self):
        with self.lock:
            self.last_seen = time.monotonic()

    def local_port(self):
        return self.udp.getsockname()[1]

    def send(self, data):
        self.udp.send(data)
        self.touch()

    def _pump(self):
        while not self.closed:
            try:
                response = self.udp.recv(MAX_DATAGRAM)
            except socket.timeout:
                continue
            except OSError:
                break
            with self.lock:
                conn = self.conn
            if conn is None:
                continue
            try:
                write_frame(conn, response)
                with self.lock:
                    self.datagrams_down += 1
            except OSError:
                self.detach(conn)

    def close(self):
        if self.closed:
            return
        self.closed = True
        self.udp.close()


class SessionRegistry:
    # Maps session IDs to live UDP sockets; sweep closes idle ones.

    def __init__(self, target, grace=SESSION_GRACE_SECONDS, limit=16):
        self.target = target
        self.grace = grace
        self.limit = limit
        self.lock = threading.Lock()
        self.sessions = {}
        self.stats = {'created': 0, 'reattached': 0, 'expired': 0, 'rejected_full': 0}

    def announce(self, conn, session_id):
        # Returns (session, reattached); (None, False) when registry is full.
        with self.lock:
            existing = self.sessions.get(session_id)
            if existing is not None and not existing.closed:
                existing.attach(conn)
                self.stats['reattached'] += 1
                return existing, True
            if len(self.sessions) >= self.limit:
                self.stats['rejected_full'] += 1
                return None, False
            session = UoTSession(session_id, self.target)
            self.sessions[session_id] = session
            self.stats['created'] += 1
        session.attach(conn)
        return session, False

    def sweep(self):
        now = time.monotonic()
        with self.lock:
            doomed = [sid for sid, s in self.sessions.items()
                      if s.closed or now - s.last_seen > self.grace]
            for sid in doomed:
                self.sessions.pop(sid).close()
                self.stats['expired'] += 1

    def close_all(self):
        with self.lock:
            sessions = list(self.sessions.values())
            self.sessions.clear()
        for s in sessions:
            s.close()

    def drop_carriers_once(self):
        # Forced lab carrier failure: close every attached TCP connection.
        # UDP sockets stay open, so re-announcing a session ID keeps the
        # same target-visible source port after the client reconnects.
        dropped = 0
        with self.lock:
            sessions = list(self.sessions.values())
        for s in sessions:
            with s.lock:
                conn = s.conn
            if conn is None:
                continue
            s.detach(conn)
            try:
                conn.shutdown(socket.SHUT_RDWR)
                dropped += 1
            except OSError:
                pass
        return dropped


def server_connection(conn, token, registry):
    # v2 session-aware server: auth, session announce, then datagram relay.
    # Wire contract after the token frame: client sends exactly one 16-byte
    # frame as its session ID; server replies OKN (new session) or OKR
    # (reattached to the existing UDP socket). Later frames are datagrams.
    # Re-announcing the same ID after a reconnect keeps the same UDP source
    # port toward the target until the grace period expires.
    with conn:
        conn.settimeout(10)
        conn.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        if not hmac.compare_digest(read_frame(conn), token):
            return
        session_id = read_frame(conn)
        if len(session_id) != SESSION_ID_BYTES:
            write_frame(conn, b'ERRSID')
            return
        session, reattached = registry.announce(conn, session_id)
        if session is None:
            write_frame(conn, b'ERRFULL')
            return
        write_frame(conn, b'OKR' if reattached else b'OKN')
        print(json.dumps({'event': 'session', 'id': session_id.hex(),
                          'port': session.local_port(), 'reattached': reattached}), flush=True)
        conn.settimeout(None)
        try:
            while True:
                data = read_frame(conn)
                session.send(data)
        finally:
            session.detach(conn)
            try:
                conn.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass


def serve(args):
    token = Path(args.token_file).read_bytes().strip()
    if len(token) < 32:
        raise ValueError('private token required')
    # Session-aware experimental server: bounded registry with idle sweep.
    registry = SessionRegistry(('127.0.0.1', args.echo_port), grace=args.grace)
    stop_reaper = threading.Event()
    def reaper():
        while not stop_reaper.wait(1):
            registry.sweep()
    threading.Thread(target=reaper, daemon=True).start()
    if args.drop_first_after > 0:
        def forced_drop():
            if stop_reaper.wait(args.drop_first_after):
                return
            print(json.dumps({'event': 'forced_drop',
                              'dropped': registry.drop_carriers_once()}), flush=True)
        threading.Thread(target=forced_drop, daemon=True).start()
    def handle(conn):
        try:
            server_connection(conn, token, registry)
        except (OSError, EOFError, ValueError) as exc:
            print(json.dumps({'event': 'session_end', 'reason': type(exc).__name__}), flush=True)
        finally:
            conn.close()
    try:
        with socket.socket() as listener:
            listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            listener.bind(('127.0.0.1', args.port))
            listener.listen(16)
            print(json.dumps({'event': 'listening', 'port': args.port}), flush=True)
            while True:
                conn, _ = listener.accept()
                threading.Thread(target=handle, args=(conn,), daemon=True).start()
    finally:
        stop_reaper.set()
        registry.close_all()


def snapshot(path):
    try:
        d = json.loads(Path(path).read_text())
        return {k: d.get(k) for k in ('state', 'error', 'policy_generation', 'active_tcp', 'active_udp', 'runtime_attestation')}
    except (OSError, ValueError):
        return {'state': 'unavailable'}


def open_carrier(forward_port, port, token, session_id):
    # v2 client handshake: SS-none header, token, then a 16-byte session
    # announce. Returns (conn, reattached). Reuse one session_id across
    # carrier reconnects to keep the sidecar UDP source port toward the
    # target stable until the server grace period expires.
    if len(session_id) != SESSION_ID_BYTES:
        raise ValueError('session id must be %d bytes' % SESSION_ID_BYTES)
    conn = socket.create_connection(('127.0.0.1', forward_port), timeout=8)
    try:
        conn.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        conn.sendall(b'\x01' + socket.inet_aton('127.0.0.1') + struct.pack('!H', port))
        write_frame(conn, token)
        write_frame(conn, session_id)
        reply = read_frame(conn)
        if reply == b'OKN':
            return conn, False
        if reply == b'OKR':
            return conn, True
        raise ValueError('UoT handshake failed: ' + repr(reply))
    except Exception:
        conn.close()
        raise


def flow(args, index, deadline):
    token = Path(args.token_file).read_bytes().strip()
    sizes = (256, 1400, 4096, 16000)
    result = {'flow': index, 'sent': 0, 'received': 0, 'corrupt': 0,
              'connections': 0, 'reattaches': 0, 'errors': [],
              'sizes': {s: [0, 0] for s in sizes}}
    rtts = []
    conn = None
    session_id = os.urandom(SESSION_ID_BYTES)
    sequence = 0
    next_send = time.monotonic()
    try:
        while time.monotonic() < deadline:
            try:
                if conn is None:
                    conn, reattached = open_carrier(args.forward_port, args.port, token, session_id)
                    result['connections'] += 1
                    if reattached:
                        result['reattaches'] += 1
                size = sizes[sequence % len(sizes)]
                payload = struct.pack('!IQ', index, sequence) + os.urandom(size - 12)
                result['sent'] += 1
                result['sizes'][size][0] += 1
                start = time.monotonic()
                write_frame(conn, payload)
                reply = read_frame(conn)
                rtts.append((time.monotonic() - start) * 1000)
                if reply == payload:
                    result['received'] += 1
                    result['sizes'][size][1] += 1
                else:
                    result['corrupt'] += 1
                sequence += 1
                next_send += 1 / args.pps
                time.sleep(max(0, next_send - time.monotonic()))
            except (OSError, EOFError, ValueError) as exc:
                result['errors'].append({'elapsed': round(args.duration - max(0, deadline-time.monotonic()), 2), 'type': type(exc).__name__})
                if conn:
                    conn.close()
                conn = None
                next_send = time.monotonic()
                time.sleep(.5)
    finally:
        if conn:
            conn.close()
    rtts.sort()
    result['rtt_ms'] = {str(q): round(rtts[min(len(rtts)-1, int((len(rtts)-1)*q))], 3) for q in (.5, .95, .99, 1)} if rtts else {}
    return result


def canary(args):
    started = time.time()
    deadline = time.monotonic() + args.duration
    observations = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.flows) as pool:
        futures = [pool.submit(flow, args, n, deadline) for n in range(args.flows)]
        while time.monotonic() < deadline:
            observations.append({'at': time.time(), 'status': snapshot(args.status_file)})
            Path(args.out + '.progress').write_text(json.dumps({'elapsed': round(time.time()-started), 'samples': len(observations)}))
            time.sleep(min(5, max(0, deadline-time.monotonic())))
        flows = [f.result() for f in futures]
    result = {'started': started, 'ended': time.time(), 'requested_seconds': args.duration,
              'scope': 'Linux -> Ultra forward 170 TCP -> LeiGod -> Japan SS-none -> loopback UoT -> UDP echo',
              'script_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
              'flows': flows, 'observations': observations}
    Path(args.out).write_text(json.dumps(result, indent=2))
    print(json.dumps({'flows': flows, 'duration': result['ended']-started}), flush=True)


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('mode', choices=['server', 'canary'])
    p.add_argument('--token-file', required=True)
    p.add_argument('--port', type=int, default=18743)
    p.add_argument('--echo-port', type=int, default=60000)
    p.add_argument('--drop-first-after', type=float, default=0,
                   help='server: once, close all carrier TCP connections after N seconds')
    p.add_argument('--forward-port', type=int, default=10001)
    p.add_argument('--duration', type=int, default=600)
    p.add_argument('--flows', type=int, default=4)
    p.add_argument('--pps', type=int, default=10)
    p.add_argument('--status-file', default='/run/ultra/forward-170.status.json')
    p.add_argument('--out', default='/tmp/japan-uot-result.json')
    args = p.parse_args()
    (serve if args.mode == 'server' else canary)(args)
