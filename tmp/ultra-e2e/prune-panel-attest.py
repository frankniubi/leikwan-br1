#!/usr/bin/env python3
"""Remove attestation blocks on the panel that do not describe the served bytes."""
import hashlib
import json
import os
import pathlib
import shutil
import time

VERSIONS = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json")
STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/attestation-prune-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)
shutil.copy2(VERSIONS, BACKUP / "versions.json.prev")

doc = json.loads(VERSIONS.read_text(encoding="utf-8"))
removed = []
kept = []
for key, entry in doc.items():
    if not isinstance(entry, dict):
        continue
    attestation = entry.get("attestation")
    if not isinstance(attestation, dict):
        continue
    version = str(entry.get("version", ""))
    fingerprint = str(attestation.get("fingerprint", ""))
    sha = str(entry.get("sha256", ""))
    self_sha = str(attestation.get("self_sha256", ""))
    if sha and version and fingerprint.startswith(version + ".") and self_sha.lower() == sha.lower():
        kept.append("%s %s" % (key, fingerprint))
        continue
    if not sha:
        # Pointer/allowance entry (the retired runtime): keep it, it has no
        # artifact of its own to contradict.
        kept.append("%s %s (pointer)" % (key, fingerprint))
        continue
    removed.append("%s fingerprint=%s" % (key, fingerprint))
    del entry["attestation"]
tmp = VERSIONS.with_suffix(".json.new")
tmp.write_text(json.dumps(doc, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")
json.loads(tmp.read_text(encoding="utf-8"))
os.replace(tmp, VERSIONS)
print("kept:", kept)
print("removed:", removed)
print("backup:", BACKUP)
