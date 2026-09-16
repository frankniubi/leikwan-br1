#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re
import shlex
import sys

import paramiko

ROOT = pathlib.Path(__file__).resolve().parents[1]
SOAK = ROOT / "tmp" / "ultra-e2e" / "soak.py"
HOST = "172.21.255.189"
SIZES = [1400, 1280, 1360, 1240, 1380, 1320, 1200, 1390, 1300]
DURATION = 30


def get_password() -> str:
    text = (ROOT / "leikwan-v3.txt").read_text(encoding="utf-8")
    match = re.search(r"root@172\.21\.255\.189[^\"\r\n]*\"([^\"]+)\"", text)
    if not match:
        raise RuntimeError("test host credential not found")
    return match.group(1)


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username="root", password=get_password(), timeout=25,
                banner_timeout=30, auth_timeout=30, look_for_keys=False, allow_agent=False)
    sftp = ssh.open_sftp()
    remote_soak = "/tmp/udp-controlled-round2-soak.py"
    sftp.put(str(SOAK), remote_soak)
    sftp.chmod(remote_soak, 0o700)
    sftp.close()

    sizes = " ".join(str(size) for size in SIZES)
    script = f"""
set -eu
root=/tmp/ultra-udp-controlled-round2
rm -rf "$root"
mkdir -p "$root"
date -u +%FT%TZ > "$root/start.txt"
systemctl show ultra-forward-170 -p MainPID -p NRestarts -p ExecMainStartTimestamp > "$root/unit.before.txt" || true
cat /run/ultra/forward-170.status.json > "$root/status.before.json" || true
for size in {sizes}; do
  out="$root/$size"
  mkdir -p "$out"
  date -u +%FT%TZ > "$out/start.txt"
  cat /run/ultra/forward-170.status.json > "$out/status.before.json" || true
  python3 {shlex.quote(remote_soak)} --duration {DURATION} --out-dir "$out" --pps 20 --size "$size" --size-big "$size" --big-every 0 --burst-every 0 --burst-len 0 > "$out/run.log" 2>&1
  date -u +%FT%TZ > "$out/end.txt"
  cat /run/ultra/forward-170.status.json > "$out/status.after.json" || true
  journalctl -u ultra-forward-170 --since "$(cat "$out/start.txt")" --until "$(date -u -d '+3 seconds' +%FT%TZ)" --no-pager > "$out/journal.txt" 2>/dev/null || true
  tail -n 6 "$out/run.log"
done
date -u +%FT%TZ > "$root/end.txt"
systemctl show ultra-forward-170 -p MainPID -p NRestarts -p ExecMainStartTimestamp > "$root/unit.after.txt" || true
cat /run/ultra/forward-170.status.json > "$root/status.after.json" || true
"""
    _, stdout, stderr = ssh.exec_command("bash -lc " + shlex.quote(script), timeout=900)
    for line in iter(stdout.readline, ""):
        print(line, end="", flush=True)
    err = stderr.read()
    code = stdout.channel.recv_exit_status()
    if err:
        print("--- remote stderr ---", file=sys.stderr)
        print(err, file=sys.stderr)

    local = ROOT / "tmp" / "udp-controlled-round2-linux"
    local.mkdir(parents=True, exist_ok=True)
    sftp = ssh.open_sftp()
    try:
        for rel in ["start.txt", "end.txt", "unit.before.txt", "unit.after.txt", "status.before.json", "status.after.json"]:
            try:
                sftp.get(f"/tmp/ultra-udp-controlled-round2/{rel}", str(local / rel))
            except IOError:
                pass
        for size in SIZES:
            dest = local / str(size)
            dest.mkdir(exist_ok=True)
            for name in ["start.txt", "end.txt", "run.log", "summary.json", "status.before.json", "status.after.json", "journal.txt"]:
                try:
                    sftp.get(f"/tmp/ultra-udp-controlled-round2/{size}/{name}", str(dest / name))
                except IOError:
                    pass
    finally:
        sftp.close()
        ssh.close()
    print(f"remote exit={code}; local evidence={local}")
    return code


if __name__ == "__main__":
    raise SystemExit(main())
