#!/usr/bin/env python3
"""Publish macOS client 1.0.32 + Android 0.2.27 and verify the served bytes."""
import hashlib, json, os, pathlib, shutil, time, urllib.request

ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
STAGE = pathlib.Path("/root/mac1032")
STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/mac-android-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)

TARGETS = [
    ("artifacts/ultra/ultra-client_macos_arm64", "ultra-client_macos_arm64.new", 0o755),
    ("artifacts/ultra/ultra-client_macos_amd64", "ultra-client_macos_amd64.new", 0o755),
    ("artifacts/macos/leikwan-bridge-update-arm64.zip", "leikwan-bridge-update-arm64.zip.new", 0o644),
    ("artifacts/macos/leikwan-bridge-update-x64.zip", "leikwan-bridge-update-x64.zip.new", 0o644),
    ("artifacts/macos/leikwan-bridge-arm64.dmg", "leikwan-bridge-arm64.dmg.new", 0o644),
    ("artifacts/macos/leikwan-bridge-x64.dmg", "leikwan-bridge-x64.dmg.new", 0o644),
    ("artifacts/android/leikwan-android-client.apk", "leikwan-android-client.apk.new", 0o644),
]

def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""): h.update(chunk)
    return h.hexdigest()

doc = json.loads((STAGE / "versions.json").read_text(encoding="utf-8"))
shutil.copy2(ROOT / "artifacts/versions.json", BACKUP / "versions.json.prev")
for relative, staged, mode in TARGETS:
    source = STAGE / staged
    target = ROOT / relative
    data = source.read_bytes()
    if target.exists():
        shutil.copy2(target, BACKUP / target.name)
    shutil.copy2(source, target)
    os.chmod(target, mode)
    print("staged %-48s %s %d" % (relative, sha(target)[:16], len(data)))

tmp = ROOT / "artifacts/versions.json.new"
tmp.write_text(json.dumps(doc, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")
json.loads(tmp.read_text(encoding="utf-8"))
shutil.copy2(ROOT / "artifacts/versions.json", BACKUP / "versions.json.new")
os.replace(tmp, ROOT / "artifacts/versions.json")

checks = [
    ("macos_client_app_arm64", "artifacts/macos/leikwan-bridge-arm64.dmg"),
    ("macos_client_app_x64", "artifacts/macos/leikwan-bridge-x64.dmg"),
    ("macos_client_update_arm64", "artifacts/macos/leikwan-bridge-update-arm64.zip"),
    ("macos_client_update_x64", "artifacts/macos/leikwan-bridge-update-x64.zip"),
    ("ultra_client_runtime_macos_arm64", "artifacts/ultra/ultra-client_macos_arm64"),
    ("ultra_client_runtime_macos_x64", "artifacts/ultra/ultra-client_macos_amd64"),
    ("android_client_apk", "artifacts/android/leikwan-android-client.apk"),
]
served_manifest = json.loads(urllib.request.urlopen(
    "https://v3.leikwanhost.com/artifacts/versions.json", timeout=60).read().decode("utf-8"))
failures = []
for key, relative in checks:
    entry = served_manifest[key]
    local = sha(ROOT / relative)
    url = "https://v3.leikwanhost.com/" + relative
    try:
        body = urllib.request.urlopen(url, timeout=300).read()
    except Exception as exc:
        failures.append("%s: fetch failed %s" % (key, exc))
        continue
    served = hashlib.sha256(body).hexdigest()
    ok = served == entry["sha256"] == local and len(body) == entry["size_bytes"]
    print("%-34s served=%s manifest=%s local=%s %s" % (
        key, served[:16], str(entry["sha256"])[:16], local[:16], "OK" if ok else "MISMATCH"))
    if not ok:
        failures.append(key)

runtime_entry = served_manifest["ultra_client_runtime_macos_arm64"]
print("macos runtime attestation:", json.dumps(runtime_entry.get("attestation")))
print("android:", served_manifest["android_client_apk"]["version"],
      served_manifest["android_client_apk"]["version_code"])
print("backup:", BACKUP)
if failures:
    raise SystemExit("FAILED: " + ", ".join(failures))
print("PUBLISH-OK")
