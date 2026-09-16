#!/usr/bin/env python3
"""Publish the client-agent release: mirror artifact + manifest entry."""
import hashlib
import json
import os
import pathlib
import shutil
import subprocess
import time

ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
VERSIONS = ROOT / "artifacts/versions.json"
ARTIFACT = ROOT / "artifacts/client-agent.py"
SOURCE = pathlib.Path("/root/client_agent.py.new")  # staged by the caller
REPO_SOURCE = ROOT / "client-agent/client_agent.py"
STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/client-agent-release-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)

if not SOURCE.is_file():
    raise SystemExit("agent source missing on the panel: %s" % SOURCE)
data = SOURCE.read_bytes()
digest = hashlib.sha256(data).hexdigest()

version = None
for line in data.decode("utf-8").splitlines():
    if line.startswith("AGENT_VERSION"):
        version = line.split("=", 1)[1].strip().strip('"')
        break
if not version:
    raise SystemExit("AGENT_VERSION not found in the panel's agent source")

doc = json.loads(VERSIONS.read_text(encoding="utf-8"))
entry = doc["client_agent"]
before = {k: entry.get(k) for k in ("version", "sha256", "size_bytes")}
if before["sha256"] == digest and before["version"] == version:
    print("manifest already points at this agent build")
else:
    shutil.copy2(VERSIONS, BACKUP / "versions.json.prev")
    if ARTIFACT.exists():
        shutil.copy2(ARTIFACT, BACKUP / "client-agent.py.prev")
    shutil.copy2(SOURCE, ARTIFACT)
    # Keep the panel's repo copy in step: the next agent release reads it.
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
    print("published agent %s sha=%s size=%d" % (version, digest, len(data)))
print("manifest before:", json.dumps(before, sort_keys=True))
print("manifest after :", json.dumps({k: entry.get(k) for k in ("version", "sha256", "size_bytes")}, sort_keys=True))
lint = subprocess.run(["php", "-l", str(ARTIFACT)], capture_output=True, text=True)
print((lint.stdout or lint.stderr).strip())
print("backup:", BACKUP)
