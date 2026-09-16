import hashlib
import json
import pathlib

root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
vpath = root / "artifacts" / "versions.json"
doc = json.loads(vpath.read_text(encoding="utf-8"))
FINGERPRINT = "2026.09.13.3.452c8fe8"
BUILD = "go1.26.5, garble-v0.17.0, fast-path-failover-2026.09.13.3"
keys = [
    "ultra_client_runtime_linux_arm64",
    "ultra_client_runtime_macos_x64",
    "ultra_client_runtime_macos_arm64",
    "ultra_client_runtime_android_arm64",
    "ultra_client_runtime_android_x86_64",
]
for key in keys:
    entry = doc[key]
    path = root / entry["path"].replace("/", "\\")
    data = path.read_bytes()
    if FINGERPRINT.encode("ascii") not in data:
        raise SystemExit("%s: local artifact does not carry the stamp" % key)
    sha = hashlib.sha256(data).hexdigest()
    before = (entry.get("version"), str(entry.get("sha256"))[:16])
    entry["version"] = "2026.09.13.3"
    entry["sha256"] = sha
    entry["size_bytes"] = len(data)
    entry["build"] = BUILD
    entry["attestation"] = {"fingerprint": FINGERPRINT, "self_sha256": sha}
    print("%-34s %s -> 2026.09.13.3 %s" % (key, before, sha[:16]))
vpath.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n")
print("local manifest updated")
