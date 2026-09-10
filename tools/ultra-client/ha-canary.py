#!/usr/bin/env python3
"""Bounded ordinary Ultra/SS-none TCP and UDP canary; no credentials needed.

Run on the client/slave with --host 127.0.0.1 --port 10001. Requests travel
through the existing runtime -> provider -> configured sidecar topology.
"""
import argparse
import json
import secrets
import socket
import struct
import time


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=10001)
    args = parser.parse_args()
    result = {}
    started = time.monotonic()
    try:
        with socket.create_connection((args.host, args.port), timeout=12) as conn:
            conn.settimeout(12)
            target = b'\x01' + socket.inet_aton('1.1.1.1') + struct.pack('!H', 80)
            conn.sendall(target + b'GET /cdn-cgi/trace HTTP/1.1\r\nHost: one.one.one.one\r\nConnection: close\r\n\r\n')
            # The provider may treat FIN as full-close. Half-close correctness
            # is covered by the controlled Go fixture, not assumed upstream.
            data = bytearray()
            while len(data) < 16384:
                chunk = conn.recv(4096)
                if not chunk:
                    break
                data.extend(chunk)
            result['tcp'] = {'ok': data.startswith(b'HTTP/1.'), 'bytes': len(data),
                             'seconds': round(time.monotonic() - started, 3)}
    except OSError as exc:
        result['tcp'] = {'ok': False, 'error': type(exc).__name__ + ': ' + str(exc)}
    started = time.monotonic()
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as conn:
            conn.settimeout(12)
            ident = secrets.randbelow(65536)
            dns = struct.pack('!6H', ident, 0x100, 1, 0, 0, 0) + b'\x07example\x03com\x00' + struct.pack('!HH', 1, 1)
            conn.sendto(b'\x00\x00\x00\x01' + socket.inet_aton('1.1.1.1') + struct.pack('!H', 53) + dns, (args.host, args.port))
            data, _ = conn.recvfrom(4096)
            valid = len(data) >= 22 and data[:4] == b'\x00\x00\x00\x01'
            valid = valid and struct.unpack('!H', data[10:12])[0] == ident and bool(data[12] & 0x80)
            result['udp'] = {'ok': valid, 'bytes': len(data), 'seconds': round(time.monotonic() - started, 3)}
    except OSError as exc:
        result['udp'] = {'ok': False, 'error': type(exc).__name__ + ': ' + str(exc)}
    print(json.dumps(result, sort_keys=True))
    return 0 if all(v['ok'] for v in result.values()) else 1


if __name__ == '__main__':
    raise SystemExit(main())
