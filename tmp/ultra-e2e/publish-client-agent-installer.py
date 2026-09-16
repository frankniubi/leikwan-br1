#!/usr/bin/env python3
"""Publish the client-agent installer artifact and its manifest entry."""
import hashlib
import json
import os
import pathlib
import re
import shutil
import subprocess
import time

ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
SOURCE = pathlib.Path("/root/install-client-agent.sh.new")
REPO_SOURCE = ROOT / "install/install-client-agent.sh"
ARTIFACT = ROOT / "artifacts/install-client-agent.sh"
VERSIONS = ROOT / "artifacts/versions.json"
STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/client-agent-installer-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)

data = SOURCE.read_bytes()
match = re.search(rb'INSTALLER_VERSION="([^"]+)"', data)
if not match:
    raise SystemExit("INSTALLER_VERSION not found in the staged installer")
version = match.group(1).decode("ascii")
digest = hashlib.sha256(data).hexdigest()

doc = json.loads(VERSIONS.read_text(encoding="utf-8"))
entry = doc["client_agent_installer"]
before = {k: entry.get(k) for k in ("version", "sha256", "size_bytes")}
shutil.copy2(VERSIONS, BACKUP / "versions.json.prev")
if ARTIFACT.exists():
    shutil.copy2(ARTIFACT, BACKUP / "install-client-agent.sh.prev")
shutil.copy2(SOURCE, ARTIFACT)
shutil.copy2(SOURCE, REPO_SOURCE)
os.chmod(ARTIFACT, 0o644)
entry["version"] = version
entry["sha256"] = digest
entry["size"] = len(data)
entry["size_bytes"] = len(data)
tmp = VERSIONS.with_suffix(".json.new")
tmp.write_text(json.dumps(doc, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")
json.loads(tmp.read_text(encoding="utf-8"))
os.replace(tmp, VERSIONS)
print("published installer %s sha=%s size=%d" % (version, digest, len(data)))
print("before:", json.dumps(before, sort_keys=True))
print("after :", json.dumps({k: entry.get(k) for k in ("version", "sha256", "size_bytes")}, sort_keys=True))
lint = subprocess.run(["bash", "-n", str(ARTIFACT)], capture_output=True, text=True)
print("bash -n:", (lint.stderr or "ok").strip()[:200])
print("backup:", BACKUP)
