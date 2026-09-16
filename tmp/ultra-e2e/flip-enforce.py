#!/usr/bin/env python3
"""Enable attestation enforcement now that published fingerprints are matched by real clients."""
import json
import pathlib
import shutil
import subprocess
import time

ENV = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env")
STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/attestation-enforce-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)
shutil.copy2(ENV, BACKUP / ".env.prev")

lines = ENV.read_text(encoding="utf-8").splitlines()
changed = False
for index, line in enumerate(lines):
    if line.startswith("ULTRA_ATTESTATION_ENFORCEMENT="):
        if line.strip() != "ULTRA_ATTESTATION_ENFORCEMENT=enforce":
            lines[index] = "ULTRA_ATTESTATION_ENFORCEMENT=enforce"
            changed = True
if changed:
    ENV.write_text("\n".join(lines) + "\n", encoding="utf-8")
print("changed:", changed)
print([line for line in lines if line.startswith("ULTRA_ATTESTATION")])
print("backup:", BACKUP)

env = {}
for line in ENV.read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        env[key.strip()] = value.strip().strip('"').strip("'")
args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
        "-u", env.get("DB_USER", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]
sql = ("SELECT COUNT(*) refusals FROM ultra_lease_refusals;")
print(subprocess.run(args + ["-e", sql], capture_output=True, text=True).stdout)
