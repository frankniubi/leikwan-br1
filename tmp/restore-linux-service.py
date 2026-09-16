#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re
import shlex
import sys

import paramiko

ROOT = pathlib.Path(__file__).resolve().parents[1]
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
    root = "/tmp/ultra-restore-20260916"
    script = f"""
set -eu
root={shlex.quote(root)}
rm -rf "$root"
mkdir -p "$root"
systemctl show ultra-forward-170 -p MainPID -p NRestarts -p ExecMainStartTimestamp > "$root/unit.before.txt" || true
cat /run/ultra/forward-170.status.json > "$root/status.before.json" || true
systemctl restart ultra-forward-170
for i in $(seq 1 18); do
  sleep 5
  cp /run/ultra/forward-170.status.json "$root/status.$i.json" 2>/dev/null || true
  state=$(python3 -c 'import json; print(json.load(open("/run/ultra/forward-170.status.json")).get("state", ""))' 2>/dev/null || true)
  policy=$(python3 -c 'import json; print(json.load(open("/run/ultra/forward-170.status.json")).get("policy_generation", ""))' 2>/dev/null || true)
  echo "poll=$i state=$state policy=${{policy:0:12}}"
  if [ "$state" = ready ] && [ -n "$policy" ]; then break; fi
done
systemctl show ultra-forward-170 -p MainPID -p NRestarts -p ExecMainStartTimestamp -p SubState > "$root/unit.after.txt" || true
cat /run/ultra/forward-170.status.json > "$root/status.after.json" || true
journalctl -u ultra-forward-170 --since '-2 min' --no-pager > "$root/journal.txt" 2>/dev/null || true
"""
    _, stdout, stderr = ssh.exec_command("bash -lc " + shlex.quote(script), timeout=150)
    for line in iter(stdout.readline, ""):
        print(line, end="", flush=True)
    err = stderr.read()
    code = stdout.channel.recv_exit_status()
    if err:
        print("--- remote stderr ---", file=sys.stderr)
        print(err, file=sys.stderr)
    local = ROOT / "tmp" / "ultra-restore-linux"
    local.mkdir(parents=True, exist_ok=True)
    sftp = ssh.open_sftp()
    try:
        for rel in ["unit.before.txt", "unit.after.txt", "status.before.json", "status.after.json", "journal.txt"]:
            try:
                sftp.get(f"{root}/{rel}", str(local / rel))
            except IOError:
                pass
        for index in range(1, 19):
            try:
                sftp.get(f"{root}/status.{index}.json", str(local / f"status.{index}.json"))
            except IOError:
                pass
    finally:
        sftp.close()
        ssh.close()
    print(f"remote exit={code}; local evidence={local}")
    return code


if __name__ == "__main__":
    raise SystemExit(main())
