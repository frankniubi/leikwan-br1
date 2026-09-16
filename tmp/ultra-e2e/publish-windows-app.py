#!/usr/bin/env python3
"""Publish the Windows runtime + desktop installer and keep the drift pin honest."""
import hashlib
import json
import os
import pathlib
import shutil
import time

ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
RUNTIME_NEW = pathlib.Path("/root/ultra-client_windows_amd64.exe.new")
SETUP_NEW = pathlib.Path("/root/leikwan-bridge-setup.exe.new")
RUNTIME_ART = ROOT / "artifacts/ultra/ultra-client_windows_amd64.exe"
SETUP_ART = ROOT / "artifacts/windows/leikwan-bridge-setup.exe"
VERSIONS = ROOT / "artifacts/versions.json"
APP_VERSION = "0.1.20.46"
RUNTIME_VERSION = "2026.09.13.3"
FINGERPRINT = "2026.09.13.3.452c8fe8"
BUILD = "go1.26.5, cgo-disabled, stripped, unobfuscated (Windows Defender false positive), fast-path-failover-2026.09.13.3"

runtime_data = RUNTIME_NEW.read_bytes()
setup_data = SETUP_NEW.read_bytes()
runtime_sha = hashlib.sha256(runtime_data).hexdigest()
setup_sha = hashlib.sha256(setup_data).hexdigest()
if runtime_data[:2] != b"MZ" or setup_data[:2] != b"MZ":
    raise SystemExit("staged artifacts are not PE images")

STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/windows-app-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)
for path, name in ((RUNTIME_ART, "ultra-client_windows_amd64.exe.prev"), (SETUP_ART, "leikwan-bridge-setup.exe.prev"), (VERSIONS, "versions.json.prev")):
    if path.exists():
        shutil.copy2(path, BACKUP / name)

shutil.copy2(RUNTIME_NEW, RUNTIME_ART)
shutil.copy2(SETUP_NEW, SETUP_ART)
os.chmod(RUNTIME_ART, 0o755)
os.chmod(SETUP_ART, 0o644)

doc = json.loads(VERSIONS.read_text(encoding="utf-8"))
runtime_entry = doc["ultra_client_runtime_windows"]
app_entry = doc["windows_client_app"]
print("runtime before:", runtime_entry.get("version"), str(runtime_entry.get("sha256"))[:16])
print("app before:", app_entry.get("version"), str(app_entry.get("sha256"))[:16], "pin", str(app_entry.get("runtime_sha256", {}).get("ultra_client_runtime_windows"))[:16])

runtime_entry["version"] = RUNTIME_VERSION
runtime_entry["sha256"] = runtime_sha
runtime_entry["size_bytes"] = len(runtime_data)
runtime_entry["build"] = BUILD
runtime_entry["obfuscator"] = "none"
runtime_entry["attestation"] = {"fingerprint": FINGERPRINT, "self_sha256": runtime_sha}

app_entry["version"] = APP_VERSION
app_entry["sha256"] = setup_sha
app_entry["size_bytes"] = len(setup_data)
app_entry.setdefault("runtime_sha256", {})["ultra_client_runtime_windows"] = runtime_sha

if app_entry["runtime_sha256"]["ultra_client_runtime_windows"] != runtime_entry["sha256"]:
    raise SystemExit("runtime drift pin does not match the published runtime")

tmp = VERSIONS.with_suffix(".json.new")
tmp.write_text(json.dumps(doc, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")
json.loads(tmp.read_text(encoding="utf-8"))
os.replace(tmp, VERSIONS)
print("runtime after :", runtime_entry["version"], runtime_sha[:16], runtime_entry["attestation"]["fingerprint"])
print("app after     :", app_entry["version"], setup_sha[:16], "pin", app_entry["runtime_sha256"]["ultra_client_runtime_windows"][:16])
print("backup:", BACKUP)
