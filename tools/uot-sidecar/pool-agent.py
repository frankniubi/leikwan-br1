#!/usr/bin/env python3
"""Standalone sidecar config/health agent. No client or sidecar binary changes."""
import argparse
import json
import os
from pathlib import Path
import socket
import struct
import subprocess
import tempfile
import time
import urllib.request
import urllib.parse
import uuid


def request(settings, suffix, body=None):
    base = settings['panel_url'].rstrip('/')
    if not base.startswith('https://'):
        raise ValueError('panel_url must use HTTPS')
    url = base + '/api/external-uot/pool/' + urllib.parse.quote(settings['node_id'], safe='') + '/' + suffix
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(url, data=data, headers={
        'Authorization': 'Bearer ' + settings['secret'], 'Content-Type': 'application/json'})
    # No redirects: never forward a pool credential to another origin.
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    with urllib.request.build_opener(NoRedirect).open(req, timeout=10) as response:
        return json.load(response)


def wire(kind, sid, payload=b''):
    header = struct.pack('!4sBBH16sQI', b'UOT2', 1, kind, 0, sid, 1, len(payload))
    return struct.pack('!I', len(header) + len(payload)) + header + payload


def read_frame(conn):
    def exact(n):
        data = b''
        while len(data) < n:
            part = conn.recv(n - len(data))
            if not part:
                raise OSError('UoT EOF')
            data += part
        return data
    size = struct.unpack('!I', exact(4))[0]
    if not 36 <= size <= 65543 + 36:
        raise ValueError('invalid UoT frame length')
    body = exact(size)
    magic, version, kind, _, sid, _, length = struct.unpack('!4sBBH16sQI', body[:36])
    if magic != b'UOT2' or version != 1 or length != size - 36:
        raise ValueError('invalid UoT frame')
    return kind, sid, body[36:]


def probe_forward(config, fid, binding, udp):
    """One authenticated UOT2 -> local UDP echo -> UOT2 exchange for one binding."""
    sid = uuid.uuid4().bytes
    host = config.get('listen_host', '0.0.0.0')
    host = '127.0.0.1' if host == '0.0.0.0' else '::1' if host == '::' else host
    with socket.create_connection((host, config['listen_port']), timeout=3) as conn:
        conn.settimeout(3)
        hello = {'forward_id': int(fid), 'token': binding['token'],
                 'target': '127.0.0.1:' + str(udp.getsockname()[1]), 'frame_payload_max': 1200}
        conn.sendall(wire(1, sid, json.dumps(hello).encode()))
        kind, received_sid, body = read_frame(conn)
        if kind != 2 or received_sid != sid or json.loads(body).get('ok') is not True:
            return False
        payload = os.urandom(32)
        conn.sendall(wire(3, sid, payload))
        received, addr = udp.recvfrom(65535)
        if received != payload:
            return False
        udp.sendto(received, addr)
        deadline = time.monotonic() + 4
        while time.monotonic() < deadline:
            kind, received_sid, body = read_frame(conn)
            if kind == 6:
                continue
            conn.sendall(wire(5, sid))
            return kind == 4 and received_sid == sid and body == payload
    return False


def probe(config):
    """Authenticated UOT2 -> local UDP echo -> UOT2. Not public-path proof.

    Every advertised binding is probed, not just the lowest forward id. A newly
    added forward used to be invisible to this health check: the node kept
    reporting healthy while the new binding was broken, so the pool kept routing
    that forward to a sidecar that would reject it with "forward is not allowed".
    """
    forwards = config.get('forwards', {})
    if not forwards:
        return False
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as udp:
        udp.bind(('127.0.0.1', 0))
        udp.settimeout(3)
        for fid, binding in forwards.items():
            try:
                if not probe_forward(config, fid, binding, udp):
                    return False
            except (OSError, ValueError, KeyError, TypeError):
                # A single unprobeable binding makes the node unhealthy rather
                # than silently reporting healthy on the strength of one peer.
                return False
    return True


def run_once(settings):
    document = request(settings, 'config')
    config, digest = document['config'], document['config_hash']
    path = Path(settings.get('config_path', '/etc/leikwan-external-uot.json'))
    binary = settings.get('binary_path', '/usr/local/bin/leikwan-external-uot-go')
    unit = settings.get('unit', 'leikwan-external-uot.service')
    previous = path.read_bytes() if path.exists() else None
    desired = (json.dumps(config, sort_keys=True, indent=2) + '\n').encode()
    # Withdraw before a config restart; no unvalidated config is admitted.
    if previous != desired:
        request(settings, 'heartbeat', {'healthy': False, 'config_hash': digest})
        path.parent.mkdir(parents=True, exist_ok=True)
        fd, temp = tempfile.mkstemp(prefix='.uot-pool-', dir=path.parent)
        try:
            with os.fdopen(fd, 'wb') as output:
                output.write(desired)
            subprocess.run([binary, '--check-config', '--config', temp], check=True,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
            if previous is not None:
                backup = path.with_suffix(path.suffix + '.pool-backup')
                backup.touch(mode=0o600, exist_ok=True)
                os.chmod(backup, 0o600)
                backup.write_bytes(previous)
            os.replace(temp, path)
            try:
                subprocess.run(['systemctl', 'restart', unit], check=True, timeout=20)
                if not probe(config):
                    raise RuntimeError('new UoT config failed local protocol probe')
            except Exception:
                if previous is not None:
                    path.write_bytes(previous)
                    subprocess.run(['systemctl', 'restart', unit], check=False, timeout=20)
                raise
        finally:
            if os.path.exists(temp):
                os.unlink(temp)
    healthy = subprocess.run(['systemctl', 'is-active', '--quiet', unit], timeout=5).returncode == 0
    try:
        healthy = healthy and probe(config)
    except (OSError, ValueError, KeyError):
        healthy = False
    request(settings, 'heartbeat', {'healthy': healthy, 'config_hash': digest})
    return healthy


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--settings', required=True, help='root-only JSON settings file')
    parser.add_argument('--once', action='store_true')
    args = parser.parse_args()
    while True:
        try:
            settings = json.loads(Path(args.settings).read_text())
            healthy = run_once(settings)
            print('uot-pool healthy=' + str(healthy).lower(), flush=True)
            if args.once:
                return 0 if healthy else 1
        except Exception as exc:
            # Exception text can contain URLs/config; log only the class.
            print('uot-pool failed=' + type(exc).__name__, flush=True)
            if args.once:
                return 1
        time.sleep(10)


if __name__ == '__main__':
    raise SystemExit(main())
