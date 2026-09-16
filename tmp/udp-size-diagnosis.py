#!/usr/bin/env python3
"""Run a bounded UDP payload-size sweep through the existing Linux forward."""
from __future__ import annotations

import json
import pathlib
import re
import shlex
import sys
import time

import paramiko


ROOT = pathlib.Path(__file__).resolve().parents[1]
SOAK = ROOT / "tmp" / "ultra-e2e" / "soak.py"
HOST = "172.21.255.189"
USER = "root"
SIZES = [256, 512, 768, 972, 973, 1000, 1200, 1400, 1472, 1500, 2000, 4096, 8192, 12000]


def password() -> str:
    text = (ROOT / "leikwan-v3.txt").read_text(encoding="utf-8")
    match = re.search(r"root@172\.21\.255\.189[^\"\r\n]*\"([^\"]+)\"", text)
    if not match:
        raise RuntimeError("test-host credential not found")
    return match.group(1)


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=password(), timeout=25,
                banner_timeout=30, auth_timeout=30, look_for_keys=False, allow_agent=False)
    remote_soak = "/tmp/udp-size-diagnosis-soak.py"
    sftp = ssh.open_sftp()
    try:
        sftp.put(str(SOAK), remote_soak)
        sftp.chmod(remote_soak, 0o700)
    finally:
        sftp.close()

    sizes = " ".join(str(size) for size in SIZES)
    script = f"""
set -eu
rm -rf /tmp/ultra-udp-diagnosis
mkdir -p /tmp/ultra-udp-diagnosis
printf 'host='; hostname
printf 'runtime='; sha256sum /usr/local/bin/ultra-client 2>/dev/null || true
printf 'status='; cat /run/ultra/forward-170.status.json 2>/dev/null || true
for size in {sizes}; do
  out=/tmp/ultra-udp-diagnosis/$size
  mkdir -p "$out"
  echo "=== size=$size ==="
  python3 {shlex.quote(remote_soak)} --duration 20 --out-dir "$out" --pps 30 --size "$size" --size-big "$size" --big-every 0 --burst-every 0 --burst-len 0 >"$out/run.log" 2>&1
  tail -n 8 "$out/run.log"
done
echo '=== summaries ==='
python3 - <<'PY'
import json
from pathlib import Path
for path in sorted(Path('/tmp/ultra-udp-diagnosis').glob('*/summary.json'), key=lambda p: int(p.parent.name)):
    doc=json.loads(path.read_text())
    udp={{x['name']: x for x in doc['udp']}}
    tunnel=udp['tunnel-udp']; direct=udp['direct-udp']
    print(json.dumps({{
        'size': int(path.parent.name),
        'tunnel': {{'sent': tunnel['sent'], 'recv': tunnel['recv'], 'loss_pct': tunnel['loss_pct'], 'send_errors': tunnel['send_errors'], 'rtt_ms': tunnel['rtt_ms']}},
        'direct': {{'sent': direct['sent'], 'recv': direct['recv'], 'loss_pct': direct['loss_pct'], 'send_errors': direct['send_errors'], 'rtt_ms': direct['rtt_ms']}},
    }}, sort_keys=True))
PY
"""
    stdin, stdout, stderr = ssh.exec_command("bash -lc " + shlex.quote(script), timeout=900)
    for line in iter(stdout.readline, ""):
        print(line, end="", flush=True)
    error = stderr.read()
    code = stdout.channel.recv_exit_status()
    if error:
        print("--- remote stderr ---", file=sys.stderr)
        print(error, file=sys.stderr)
    print(f"remote exit={code}")

    local_out = ROOT / "tmp" / "udp-size-diagnosis-linux"
    local_out.mkdir(parents=True, exist_ok=True)
    sftp = ssh.open_sftp()
    try:
        for size in SIZES:
            remote = f"/tmp/ultra-udp-diagnosis/{size}/summary.json"
            try:
                sftp.get(remote, str(local_out / f"{size}.summary.json"))
            except IOError:
                pass
    finally:
        sftp.close()
    ssh.close()
    print(f"local summaries: {local_out}")
    return code


if __name__ == "__main__":
    raise SystemExit(main())
