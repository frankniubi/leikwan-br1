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
    remote_soak = "/tmp/udp-recovery-long-soak.py"
    sftp.put(str(SOAK), remote_soak)
    sftp.chmod(remote_soak, 0o700)
    sftp.close()

    remote_root = "/tmp/ultra-udp-recovery-long"
    script = f"""
set -eu
root={shlex.quote(remote_root)}
rm -rf "$root"
mkdir -p "$root"
date -u +%FT%TZ > "$root/start.txt"
systemctl show ultra-forward-170 -p MainPID -p NRestarts -p ExecMainStartTimestamp > "$root/unit.before.txt" || true
cat /run/ultra/forward-170.status.json > "$root/status.before.json" || true
python3 {shlex.quote(remote_soak)} --duration 300 --out-dir "$root/soak" --pps 5 --size 256 --size-big 256 --big-every 0 --burst-every 0 --burst-len 0 > "$root/run.log" 2>&1
date -u +%FT%TZ > "$root/end.txt"
cat /run/ultra/forward-170.status.json > "$root/status.after.json" || true
systemctl show ultra-forward-170 -p MainPID -p NRestarts -p ExecMainStartTimestamp > "$root/unit.after.txt" || true
journalctl -u ultra-forward-170 --since "$(cat "$root/start.txt")" --until "$(date -u -d '+3 seconds' +%FT%TZ)" --no-pager > "$root/journal.txt" 2>/dev/null || true
tail -n 30 "$root/run.log"
"""
    _, stdout, stderr = ssh.exec_command("bash -lc " + shlex.quote(script), timeout=390)
    for line in iter(stdout.readline, ""):
        print(line, end="", flush=True)
    err = stderr.read()
    code = stdout.channel.recv_exit_status()
    if err:
        print("--- remote stderr ---", file=sys.stderr)
        print(err, file=sys.stderr)

    local = ROOT / "tmp" / "udp-recovery-long-linux"
    local.mkdir(parents=True, exist_ok=True)
    sftp = ssh.open_sftp()
    try:
        for rel in ["start.txt", "end.txt", "unit.before.txt", "unit.after.txt", "status.before.json", "status.after.json", "run.log", "journal.txt"]:
            try:
                sftp.get(f"{remote_root}/{rel}", str(local / rel))
            except IOError:
                pass
        try:
            sftp.get(f"{remote_root}/soak/summary.json", str(local / "summary.json"))
        except IOError:
            pass
    finally:
        sftp.close()
        ssh.close()
    print(f"remote exit={code}; local evidence={local}")
    return code


if __name__ == "__main__":
    raise SystemExit(main())
