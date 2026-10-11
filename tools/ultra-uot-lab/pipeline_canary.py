#!/usr/bin/env python3
"""Continuous bidirectional echo test over japan_canary framing (lab only).

Send timing never waits for a reply. IDs and full payload comparison detect
loss, duplicates, cross-flow delivery, corruption, and late arrival separately.
"""
import argparse
import concurrent.futures
import hashlib
import json
import os
from pathlib import Path
import socket
import struct
import threading
import time

from japan_canary import (SESSION_ID_BYTES, open_carrier, read_frame, snapshot,
                          write_frame)

SIZES = (256, 1400, 4096, 16000)
HEADER = struct.Struct('!4sIQ')


def payload(flow, seq, size):
    header = HEADER.pack(b'UOT1', flow, seq)
    block = hashlib.sha256(header).digest()
    return header + (block * ((size + 31) // 32))[:size-len(header)]


def measure(args, index, start, end):
    result = {'flow': index, 'sent': 0, 'received': 0, 'duplicates': 0,
              'corrupt': 0, 'late_over_500ms': 0, 'errors': [], 'sizes': {s: [0, 0] for s in SIZES}}
    sent_at, received, delays = {}, set(), []
    lock = threading.Lock()
    if args.raw_udp_port:
        conn = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        conn.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 << 20)
        conn.connect(('127.0.0.1', args.raw_udp_port))
        conn.settimeout(8)
        read_packet = lambda: conn.recv(65535)
        send_packet = conn.send
    else:
        token = Path(args.token_file).read_bytes().strip()
        # One stable session ID per flow: a forced carrier drop reconnects
        # and re-announces the same ID, so the server reattaches the flow to
        # its original UDP socket instead of allocating a new source port.
        session_id = os.urandom(SESSION_ID_BYTES)
        carrier = {'conn': None}
        healthy = threading.Event()
        carrier_lock = threading.Lock()
        counters = {'reconnects': 0, 'reattaches': 0, 'carrier_errors': 0}

        def close_current():
            with carrier_lock:
                conn = carrier['conn']
                carrier['conn'] = None
                healthy.clear()
            if conn is not None:
                try:
                    conn.shutdown(socket.SHUT_RDWR)
                except OSError:
                    pass
                conn.close()

        def reconnect_until(deadline):
            while time.monotonic() < deadline:
                close_current()
                try:
                    conn, reattached = open_carrier(args.forward_port, args.port,
                                                    token, session_id)
                except (OSError, EOFError, ValueError):
                    counters['carrier_errors'] += 1
                    time.sleep(.25)
                    continue
                with carrier_lock:
                    carrier['conn'] = conn
                    healthy.set()
                counters['reconnects'] += 1
                if reattached:
                    counters['reattaches'] += 1
                return True
            return False

        def read_carrier_packet():
            while not stopping.is_set():
                with carrier_lock:
                    conn = carrier['conn']
                    alive = healthy.is_set()
                if conn is None or not alive:
                    time.sleep(.05)
                    continue
                try:
                    return read_frame(conn)
                except (EOFError, OSError, ValueError):
                    with carrier_lock:
                        if carrier['conn'] is conn:
                            healthy.clear()
                    continue
            raise EOFError('flow stopped')

        def send_carrier_packet(data):
            with carrier_lock:
                conn = carrier['conn']
                alive = healthy.is_set()
            if conn is None or not alive:
                if not reconnect_until(end):
                    raise OSError('carrier unavailable')
                with carrier_lock:
                    conn = carrier['conn']
            write_frame(conn, data)

        read_packet = read_carrier_packet
        send_packet = send_carrier_packet
    stopping = threading.Event()

    def reader():
        try:
            while not stopping.is_set():
                reply = read_packet()
                now = time.monotonic()
                if stopping.is_set():
                    break
                if len(reply) < HEADER.size:
                    result['corrupt'] += 1
                    continue
                magic, fid, seq = HEADER.unpack(reply[:HEADER.size])
                with lock:
                    timestamp = sent_at.get(seq)
                if magic != b'UOT1' or fid != index or timestamp is None or reply != payload(index, seq, len(reply)):
                    result['corrupt'] += 1
                    continue
                if seq in received:
                    result['duplicates'] += 1
                    continue
                size = SIZES[seq % len(SIZES)]
                if len(reply) != size:
                    result['corrupt'] += 1
                    continue
                received.add(seq)
                delay = 1000 * (now-timestamp)
                delays.append(delay)
                result['received'] += 1
                result['sizes'][size][1] += 1
                result['late_over_500ms'] += int(delay > 500)
        except (EOFError, OSError, ValueError) as exc:
            if not stopping.is_set():
                result['errors'].append(type(exc).__name__)

    thread = threading.Thread(target=reader, daemon=True)
    thread.start()
    next_send, seq = start, 0
    try:
        while time.monotonic() < end:
            time.sleep(max(0, next_send-time.monotonic()))
            now = time.monotonic()
            if now >= end:
                break
            size = SIZES[seq % len(SIZES)]
            data = payload(index, seq, size)
            # One second of 200pps per flow every 30 seconds, otherwise 60pps.
            rate = 200 if int(now-start) % 30 == 29 else args.pps
            try:
                send_packet(data)
            except (OSError, EOFError, ValueError) as exc:
                # Packet dropped while the carrier was down; counted, not sent.
                result['errors'].append('send_' + type(exc).__name__)
                seq += 1
                next_send = max(next_send + 1/rate, now)
                continue
            with lock:
                sent_at[seq] = time.monotonic()
            result['sent'] += 1
            result['sizes'][size][0] += 1
            seq += 1
            next_send = max(next_send + 1/rate, now)
        drain_until = time.monotonic()+3
        while result['received'] < result['sent'] and time.monotonic() < drain_until:
            time.sleep(.05)
    except (OSError, EOFError, ValueError) as exc:
        result['errors'].append(type(exc).__name__)
    finally:
        stopping.set()
        if args.raw_udp_port:
            conn.close()
        else:
            close_current()
        thread.join(2)
        if not args.raw_udp_port:
            result['carrier_reconnects'] = counters['reconnects']
            result['carrier_reattaches'] = counters['reattaches']
            result['carrier_errors'] = counters['carrier_errors']
    delays.sort()
    result['lost'] = result['sent']-result['received']
    result['loss_percent'] = 100*result['lost']/max(1, result['sent'])
    result['rtt_ms'] = {str(q): round(delays[int((len(delays)-1)*q)], 3) for q in (.5,.95,.99,1)} if delays else {}
    return result


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--token-file', required=True)
    p.add_argument('--port', type=int, default=18743)
    p.add_argument('--forward-port', type=int, default=10001)
    p.add_argument('--raw-udp-port', type=int, default=0)
    p.add_argument('--duration', type=int, default=180)
    p.add_argument('--flows', type=int, default=4)
    p.add_argument('--pps', type=int, default=60)
    p.add_argument('--out', required=True)
    p.add_argument('--status-file', default='/run/ultra/forward-170.status.json')
    args = p.parse_args()
    start = time.monotonic()+3
    end = start+args.duration
    began = time.time()
    samples = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.flows) as pool:
        jobs = [pool.submit(measure,args,i,start,end) for i in range(args.flows)]
        while not all(f.done() for f in jobs):
            d = snapshot(args.status_file)
            try:
                d['latest_tcp'] = json.loads(Path(args.status_file).read_text()).get('latest_tcp')
            except (OSError,ValueError):
                pass
            samples.append({'at': time.time(), 'status': d})
            Path(args.out+'.progress').write_text(json.dumps({'elapsed': round(time.time()-began), 'completed_flows': sum(f.done() for f in jobs)}))
            time.sleep(5)
        flows = [f.result() for f in jobs]
    result = {'started': began, 'ended': time.time(), 'requested_seconds': args.duration,
              'flows': flows, 'observations': samples, 'pps': args.pps,
              'script_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest()}
    Path(args.out).write_text(json.dumps(result,indent=2))
    print(json.dumps({'flows':flows}),flush=True)


if __name__ == '__main__':
    main()
