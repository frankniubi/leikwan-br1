#!/usr/bin/env python3
"""Local (repo) manifest + artifact update for mac app 1.0.32 with the signed 2026.09.13.3 runtime."""
import hashlib, json, pathlib, shutil, zipfile

ROOT = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
VERSIONS = ROOT / "artifacts/versions.json"
BUILD = ROOT / ".build/mac-1032"
APP_VERSION = "1.0.32"
FINGERPRINT = "2026.09.13.3.452c8fe8"

def sha(p): 
    h = hashlib.sha256()
    with open(p, "rb") as f:
        for c in iter(lambda: f.read(1 << 20), b""): h.update(c)
    return h.hexdigest()

# 1. stage signed runtimes into artifacts/ultra
runtime_map = {
    "ultra_client_runtime_macos_arm64": ("rt-arm64", "ultra-client_macos_arm64"),
    "ultra_client_runtime_macos_x64": ("rt-x64", "ultra-client_macos_amd64"),
}
staged = {}
for key, (src, name) in runtime_map.items():
    srcp = BUILD / "signed" / src
    data = srcp.read_bytes()
    if FINGERPRINT.encode() not in data:
        raise SystemExit(f"{key}: stamp missing")
    dest = ROOT / "artifacts/ultra" / name
    shutil.copyfile(srcp, dest)
    staged[key] = (dest, sha(dest), dest.stat().st_size)

# 2. stage update zips
zip_map = {
    "macos_client_update_arm64": ("arm64", "ultra_client_runtime_macos_arm64"),
    "macos_client_update_x64": ("x64", "ultra_client_runtime_macos_x64"),
}
for key, (arch, rt_key) in zip_map.items():
    srcp = BUILD / "out" / f"leikwan-bridge-update-{arch}.zip"
    dest = ROOT / "artifacts/macos" / f"leikwan-bridge-update-{arch}.zip"
    shutil.copyfile(srcp, dest)
    with zipfile.ZipFile(dest) as z:
        embedded = hashlib.sha256(z.read("LeiKwan Bridge.app/Contents/Resources/bin/ultra-client")).hexdigest()
    if embedded != staged[rt_key][1]:
        raise SystemExit(f"{key}: embedded runtime {embedded[:16]} != published runtime {staged[rt_key][1][:16]}")
    print(f"{key}: zip={sha(dest)[:16]} size={dest.stat().st_size} embedded_runtime={embedded[:16]} OK")

# 3. manifest
doc = json.loads(VERSIONS.read_text(encoding="utf-8"))
for key, (dest, digest, size) in staged.items():
    e = doc[key]
    print(f"{key}: {str(e.get('sha256'))[:16]} -> {digest[:16]}")
    e["version"] = "2026.09.13.3"
    e["sha256"] = digest
    e["size_bytes"] = size
    e.setdefault("build", "go1.26.5, garble-v0.17.0, fast-path-failover-2026.09.13.3")
    e["attestation"] = {"fingerprint": FINGERPRINT, "self_sha256": digest}
for key, (arch, rt_key) in zip_map.items():
    dest = ROOT / "artifacts/macos" / f"leikwan-bridge-update-{arch}.zip"
    e = doc[key]
    e["version"] = APP_VERSION
    e["sha256"] = sha(dest)
    e["size_bytes"] = dest.stat().st_size
    e.setdefault("runtime_sha256", {})["bin/ultra-client"] = staged[rt_key][1]
    print(f"{key}: version={APP_VERSION} sha={e['sha256'][:16]} size={e['size_bytes']} runtime={staged[rt_key][1][:16]}")
for key in ("macos_client_app_arm64", "macos_client_app_x64"):
    doc[key]["version"] = APP_VERSION
    print(f"{key}: version -> {APP_VERSION} (dmg hash pending)")

VERSIONS.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n")
print("local manifest updated")
