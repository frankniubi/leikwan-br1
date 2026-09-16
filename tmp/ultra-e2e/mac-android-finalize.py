#!/usr/bin/env python3
"""Finish the local manifest for the macOS 1.0.32 DMGs and the Android 0.2.27 APK."""
import hashlib, json, pathlib, shutil

ROOT = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
VERSIONS = ROOT / "artifacts/versions.json"
BUILD = ROOT / ".build/mac-1032/out"

def sha(p):
    h = hashlib.sha256()
    with open(p, "rb") as f:
        for c in iter(lambda: f.read(1 << 20), b""): h.update(c)
    return h.hexdigest()

doc = json.loads(VERSIONS.read_text(encoding="utf-8"))

for arch, key in (("arm64", "macos_client_app_arm64"), ("x64", "macos_client_app_x64")):
    src = BUILD / f"leikwan-bridge-{arch}.dmg"
    dest = ROOT / "artifacts/macos" / f"leikwan-bridge-{arch}.dmg"
    shutil.copyfile(src, dest)
    digest = sha(dest)
    e = doc[key]
    e["version"] = "1.0.32"
    e["sha256"] = digest
    e["size_bytes"] = dest.stat().st_size
    print(f"{key}: 1.0.32 {digest[:16]} {e['size_bytes']}")

apk = ROOT / "artifacts/android/leikwan-android-client.apk"
entry = doc["android_client_apk"]
entry["version"] = "0.2.27"
entry["version_code"] = 29
entry["sha256"] = sha(apk)
entry["size_bytes"] = apk.stat().st_size
print(f"android_client_apk: 0.2.27 code=29 {entry['sha256'][:16]} {entry['size_bytes']}")

# consistency gates before the manifest is written
assert doc["macos_client_update_arm64"]["runtime_sha256"]["bin/ultra-client"] == doc["ultra_client_runtime_macos_arm64"]["attestation"]["self_sha256"] == doc["ultra_client_runtime_macos_arm64"]["sha256"]
assert doc["macos_client_update_x64"]["runtime_sha256"]["bin/ultra-client"] == doc["ultra_client_runtime_macos_x64"]["attestation"]["self_sha256"] == doc["ultra_client_runtime_macos_x64"]["sha256"]
for key in ("macos_client_app_arm64", "macos_client_app_x64", "macos_client_update_arm64", "macos_client_update_x64"):
    path = ROOT / doc[key]["path"]
    assert sha(path) == doc[key]["sha256"], key
    assert path.stat().st_size == doc[key]["size_bytes"], key
assert sha(apk) == entry["sha256"]

VERSIONS.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n")
print("local manifest complete")
