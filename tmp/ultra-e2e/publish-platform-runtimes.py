#!/usr/bin/env python3
"""Publish the rebuilt non-Windows runtimes and refresh their attestation blocks."""
import hashlib
import json
import os
import pathlib
import shutil
import time

ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
VERSIONS = ROOT / "artifacts/versions.json"
VERSION = "2026.09.13.3"
FINGERPRINT = "2026.09.13.3.452c8fe8"
BUILD = "go1.26.5, garble-v0.17.0, fast-path-failover-2026.09.13.3"

PLATFORMS = [
    ("ultra_client_runtime_linux_arm64", "ultra-client_linux_arm64", "/root/ultra-client_linux_arm64.new"),
    ("ultra_client_runtime_macos_x64", "ultra-client_macos_amd64", "/root/ultra-client_macos_amd64.new"),
    ("ultra_client_runtime_macos_arm64", "ultra-client_macos_arm64", "/root/ultra-client_macos_arm64.new"),
    ("ultra_client_runtime_android_arm64", "ultra-client_android_arm64", "/root/ultra-client_android_arm64.new"),
    ("ultra_client_runtime_android_x86_64", "ultra-client_android_x86_64", "/root/ultra-client_android_x86_64.new"),
]

STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/platform-runtimes-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)
shutil.copy2(VERSIONS, BACKUP / "versions.json.prev")

doc = json.loads(VERSIONS.read_text(encoding="utf-8"))
summary = []
for key, artifact_name, staged in PLATFORMS:
    source = pathlib.Path(staged)
    if not source.is_file():
        raise SystemExit("staged artifact missing: " + staged)
    data = source.read_bytes()
    if FINGERPRINT.encode("ascii") not in data:
        raise SystemExit("%s: staged binary does not carry the %s stamp" % (key, FINGERPRINT))
    sha = hashlib.sha256(data).hexdigest()
    entry = doc.get(key)
    if not isinstance(entry, dict):
        raise SystemExit("manifest entry missing: " + key)
    target = ROOT / entry.get("path", "artifacts/ultra/" + artifact_name)
    if target.exists():
        shutil.copy2(target, BACKUP / (artifact_name + ".prev"))
    shutil.copy2(source, target)
    os.chmod(target, 0o755)
    entry["version"] = VERSION
    entry["sha256"] = sha
    entry["size_bytes"] = len(data)
    entry["build"] = BUILD
    entry["attestation"] = {"fingerprint": FINGERPRINT, "self_sha256": sha}
    summary.append("%-34s %s %s" % (key, VERSION, sha[:16]))

tmp = VERSIONS.with_suffix(".json.new")
tmp.write_text(json.dumps(doc, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")
json.loads(tmp.read_text(encoding="utf-8"))
os.replace(tmp, VERSIONS)
print("published:")
for line in summary:
    print("  " + line)
print("backup:", BACKUP)
