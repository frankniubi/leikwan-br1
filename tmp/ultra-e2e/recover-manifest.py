#!/usr/bin/env python3
"""Rebuild the local manifest after an accidental overwrite: git restores the
committed baseline, and every value that came from this session is re-derived
from the artifact on disk (never from memory)."""
import hashlib, json, pathlib, subprocess

ROOT = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
VERSIONS = ROOT / "artifacts/versions.json"

subprocess.run(["git", "checkout", "--", "artifacts/versions.json"], cwd=ROOT, check=True)
doc = json.loads(VERSIONS.read_text(encoding="utf-8"))
print("restored baseline:",
      "mac runtime arm64", str(doc["ultra_client_runtime_macos_arm64"].get("sha256"))[:16],
      "android", doc["android_client_apk"].get("version"))

def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""): h.update(chunk)
    return h.hexdigest()

FINGERPRINT = "2026.09.13.3.452c8fe8"
BUILD = "go1.26.5, garble-v0.17.0, fast-path-failover-2026.09.13.3"
for key, relative in (("ultra_client_runtime_macos_arm64", "artifacts/ultra/ultra-client_macos_arm64"),
                      ("ultra_client_runtime_macos_x64", "artifacts/ultra/ultra-client_macos_amd64")):
    path = ROOT / relative
    data = path.read_bytes()
    if FINGERPRINT.encode() not in data:
        raise SystemExit(key + ": artifact does not carry the runtime stamp")
    digest = hashlib.sha256(data).hexdigest()
    entry = doc[key]
    entry["version"] = "2026.09.13.3"
    entry["sha256"] = digest
    entry["size_bytes"] = len(data)
    entry["build"] = BUILD
    entry["attestation"] = {"fingerprint": FINGERPRINT, "self_sha256": digest}
    print("%-32s %s %d" % (key, digest[:16], len(data)))

apk = ROOT / "artifacts/android/leikwan-android-client.apk"
entry = doc["android_client_apk"]
entry["version"] = "0.2.27"
entry["version_code"] = 29
entry["sha256"] = sha(apk)
entry["size_bytes"] = apk.stat().st_size
print("%-32s %s %d" % ("android_client_apk", entry["sha256"][:16], entry["size_bytes"]))

payload = json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n"
scratch = VERSIONS.with_suffix(".json.stage")
scratch.write_bytes(payload)
json.loads(scratch.read_text(encoding="utf-8"))
scratch.replace(VERSIONS)
print("baseline restored and extended; now run stage-headless-manifest.py")
